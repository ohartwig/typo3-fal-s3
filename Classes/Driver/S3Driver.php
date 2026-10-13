<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

use Koh\FalS3\Configuration\ConfigurationResolver;
use Koh\FalS3\Configuration\DriverConfiguration;
use Koh\FalS3\Exception\ObjectArchivedException;
use Koh\FalS3\Exception\PreconditionFailedException;
use Koh\FalS3\Exception\ThreatsFoundException;
use Koh\FalS3\Http\S3ObjectStream;
use Koh\FalS3\Metrics\RequestCounter;
use Koh\FalS3\S3\CacheControlPolicy;
use Koh\FalS3\S3\ContentTypeResolver;
use Koh\FalS3\S3\ObjectMetadata;
use Koh\FalS3\S3\UploadOptions;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Core\Charset\CharsetConverter;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\AbstractHierarchicalFilesystemDriver;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Driver\StreamableDriverInterface;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\CMS\Core\Resource\Exception\InvalidFileNameException;
use TYPO3\CMS\Core\Resource\FolderInterface;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * FAL driver for an S3 bucket.
 *
 * Identifiers map 1:1 to keys (bucket root = storage root, optional
 * keyPrefix), folders are key prefixes with an optional zero-byte marker,
 * public URLs are the identifier under publicBaseUri — so a storage that used
 * to be a LocalDriver on a mounted bucket keeps every identifier, hash and
 * URL when it switches to this driver.
 *
 * This class canonicalises identifiers and decides semantics; it makes no
 * S3 call and holds no cache logic itself (see DriverRuntime).
 */
class S3Driver extends AbstractHierarchicalFilesystemDriver implements StreamableDriverInterface
{
    public const SHORT_NAME = 'KohS3';

    /** @var array<non-empty-string, FolderInterface::ROLE_*> */
    private const FOLDER_ROLES = [
        '_recycler_' => FolderInterface::ROLE_RECYCLER,
        '_temp_' => FolderInterface::ROLE_TEMPORARY,
        'user_upload' => FolderInterface::ROLE_USERUPLOAD,
    ];

    /**
     * Content-Types an S3 server or a FUSE mount sets when nobody told it better;
     * the extension is the more reliable source then.
     *
     * @var list<string>
     */
    private const GENERIC_CONTENT_TYPES = ['', 'application/octet-stream', 'binary/octet-stream', 'application/x-www-form-urlencoded'];

    /** @var list<non-empty-string> */
    private const FILE_INFO_PROPERTIES = [
        'size', 'atime', 'mtime', 'ctime', 'mimetype', 'name', 'extension',
        'identifier', 'identifier_hash', 'storage', 'folder_hash',
    ];

    private ?DriverConfiguration $driverConfiguration = null;
    private ?DriverRuntime $runtime = null;

    /**
     * @param array<string, mixed> $configuration
     */
    public function __construct(array $configuration = [])
    {
        parent::__construct($configuration);
        $this->capabilities = new Capabilities(
            Capabilities::CAPABILITY_BROWSABLE
            | Capabilities::CAPABILITY_PUBLIC
            | Capabilities::CAPABILITY_WRITABLE
            | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS
        );
    }

    public function mergeConfigurationCapabilities(Capabilities $capabilities): Capabilities
    {
        $this->capabilities->and($capabilities);
        return $this->capabilities;
    }

    /**
     * Only configuration errors may surface here: TYPO3 takes the storage
     * offline on an exception from this method, and a network hiccup must not.
     */
    public function processConfiguration(): void
    {
        /** @var array<string, mixed> $configuration */
        $configuration = $this->configuration;
        $this->driverConfiguration = GeneralUtility::makeInstance(ConfigurationResolver::class)
            ->resolve($configuration, $this->storageUid ?? 0);
    }

    public function initialize(): void
    {
        if ($this->driverConfiguration === null) {
            $this->processConfiguration();
        }
        /** @var DriverConfiguration $configuration */
        $configuration = $this->driverConfiguration;
        $this->runtime = GeneralUtility::makeInstance(DriverRuntimeFactory::class)->create($configuration, $this->storageUid ?? 0);
    }

    public function isCaseSensitiveFileSystem(): bool
    {
        if ($this->driverConfiguration !== null) {
            return $this->driverConfiguration->caseSensitive;
        }
        return parent::isCaseSensitiveFileSystem();
    }

    /**
     * Same rules as LocalDriver, so a file name that was valid on the mount is
     * valid here and vice versa.
     */
    public function sanitizeFileName(string $fileName): string
    {
        $normalized = \Normalizer::normalize($fileName);
        if (is_string($normalized) && $normalized !== '') {
            $fileName = $normalized;
        }
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $utf8Filesystem = is_array($confVars) && is_array($confVars['SYS'] ?? null) && (bool)($confVars['SYS']['UTF8filesystem'] ?? false);
        if ($utf8Filesystem) {
            $cleanFileName = (string)preg_replace('/[' . LocalDriver::UNSAFE_FILENAME_CHARACTER_EXPRESSION . ']/u', '_', trim($fileName));
            if (!$this->isCaseSensitiveFileSystem()) {
                $cleanFileName = mb_strtolower($cleanFileName, 'utf-8');
            }
        } else {
            $fileName = GeneralUtility::makeInstance(CharsetConverter::class)->utf8_char_mapping($fileName);
            $cleanFileName = (string)preg_replace('/[' . LocalDriver::UNSAFE_FILENAME_CHARACTER_EXPRESSION . '\\xC0-\\xFF]/', '_', trim($fileName));
            if (!$this->isCaseSensitiveFileSystem()) {
                $cleanFileName = strtolower($cleanFileName);
            }
        }
        $cleanFileName = rtrim($cleanFileName, '.');
        if ($cleanFileName === '' || str_contains($cleanFileName, '/')) {
            throw new InvalidFileNameException('File name ' . $fileName . ' is invalid.', 1320288991);
        }
        return $cleanFileName;
    }

    public function getRootLevelFolder(): string
    {
        return '/';
    }

    public function getDefaultFolder(): string
    {
        $identifier = '/user_upload/';
        if (!$this->folderExists($identifier)) {
            $identifier = $this->createFolder('user_upload');
        }
        return $identifier;
    }

    public function getPublicUrl(string $identifier): ?string
    {
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        $runtime = $this->runtime();
        if ($runtime->mapper->isExcluded($identifier)) {
            return null;
        }
        if ($runtime->malwareGate->isEnabled()) {
            $key = $runtime->mapper->fileKey($identifier);
            if ($runtime->malwareGate->isBlocked($key, $this->metadata($key)->etag)) {
                return null;
            }
        }
        $segments = array_map(rawurlencode(...), explode('/', ltrim($identifier, '/')));
        return $runtime->configuration->publicBaseUri . implode('/', $segments);
    }

    /*******************
     * FOLDERS
     *******************/

    public function createFolder(string $newFolderName, string $parentFolderIdentifier = '', bool $recursive = false): string
    {
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier === '' ? '/' : $parentFolderIdentifier);
        $newFolderName = trim($newFolderName, '/');
        $parts = $recursive ? GeneralUtility::trimExplode('/', $newFolderName, true) : [$newFolderName];
        $runtime = $this->runtime();

        $current = $parentFolderIdentifier;
        foreach ($parts as $part) {
            if ($part === '') {
                throw new InvalidFileNameException('Folder name must not be empty.', 1760400082);
            }
            $part = $this->sanitizeFileName($part);
            $current = $this->canonicalizeAndCheckFolderIdentifier($current . $part . '/');
            $runtime->mapper->assertNotExcluded($current);
            $prefix = $runtime->mapper->folderPrefix($current);
            if ($this->folderExists($current)) {
                continue;
            }
            try {
                $runtime->store->putString($prefix, '', new UploadOptions(ContentTypeResolver::DIRECTORY, 'no-store', '*'));
            } catch (PreconditionFailedException) {
                // Another replica created the marker meanwhile; the folder exists, which is what we wanted.
            }
            $runtime->cache->invalidateWrite($prefix);
            $runtime->cache->rememberFolderExists($prefix, true);
        }
        return $current;
    }

    public function renameFolder(string $folderIdentifier, string $newName): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $trimmed = rtrim($folderIdentifier, '/');
        if ($trimmed === '') {
            throw new FileOperationErrorException('The root folder cannot be renamed.', 1760400083);
        }
        $newName = $this->sanitizeFileName($newName);
        $parent = $this->getParentFolderIdentifierOfIdentifier($trimmed);
        $newFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parent . $newName . '/');
        if ($newFolderIdentifier === $folderIdentifier) {
            return [$folderIdentifier => $folderIdentifier];
        }
        $this->runtime()->mapper->assertNotExcluded($newFolderIdentifier);
        if ($this->folderExists($newFolderIdentifier)) {
            throw new ExistingTargetFolderException('The target folder "' . $newFolderIdentifier . '" already exists.', 1760400080);
        }
        return $this->runtime()->folders->copyTree($folderIdentifier, $newFolderIdentifier, true);
    }

    public function deleteFolder(string $folderIdentifier, bool $deleteRecursively = false): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            throw new FileOperationErrorException('The root folder cannot be deleted.', 1760400081);
        }
        $this->runtime()->mapper->assertNotExcluded($folderIdentifier);
        if (!$deleteRecursively && !$this->isFolderEmpty($folderIdentifier)) {
            throw new FileOperationErrorException('Deleting folder "' . $folderIdentifier . '" failed: it is not empty.', 1330119451);
        }
        $this->runtime()->folders->deleteTree($folderIdentifier);
        return true;
    }

    public function folderExists(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if ($folderIdentifier === '/') {
            return true;
        }
        $runtime = $this->runtime();
        if ($runtime->mapper->isExcluded($folderIdentifier)) {
            return false;
        }
        $prefix = $runtime->mapper->folderPrefix($folderIdentifier);
        $cached = $runtime->cache->folderExists($prefix);
        if ($cached !== null) {
            return $cached;
        }
        $exists = $runtime->store->prefixHasAnything($prefix);
        $runtime->cache->rememberFolderExists($prefix, $exists);
        return $exists;
    }

    public function isFolderEmpty(string $folderIdentifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $runtime = $this->runtime();
        return $runtime->store->isPrefixEmpty($runtime->mapper->folderPrefix($folderIdentifier));
    }

    public function folderExistsInFolder(string $folderName, string $folderIdentifier): bool
    {
        return $this->folderExists($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . $folderName . '/');
    }

    public function getFolderInFolder(string $folderName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier . '/' . $folderName);
    }

    public function getFolderInfoByIdentifier(string $folderIdentifier): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        if (!$this->folderExists($folderIdentifier)) {
            throw new FolderDoesNotExistException('Folder "' . $folderIdentifier . '" does not exist.', 1314516810);
        }
        $mtime = 0;
        if ($folderIdentifier !== '/') {
            // Only what the cache already knows about the marker; a HEAD per folder row would be a request per row.
            $marker = $this->runtime()->cache->object($this->runtime()->mapper->folderPrefix($folderIdentifier));
            $mtime = $marker?->exists ? $marker->mtime : 0;
        }
        return [
            'identifier' => $folderIdentifier,
            'name' => $folderIdentifier === '/' ? '' : PathUtility::basename(rtrim($folderIdentifier, '/')),
            'mtime' => $mtime,
            'ctime' => $mtime,
            'storage' => $this->storageUid ?? 0,
        ];
    }

    public function getFoldersInFolder(string $folderIdentifier, int $start = 0, int $numberOfItems = 0, bool $recursive = false, array $folderNameFilterCallbacks = [], string $sort = '', bool $sortRev = false): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $runtime = $this->runtime();
        $entries = $runtime->listing->listFolder($folderIdentifier, $recursive)['folders'];
        return $runtime->listing->apply($entries, $folderNameFilterCallbacks, $sort, $sortRev, $start, $numberOfItems, $this);
    }

    public function countFoldersInFolder(string $folderIdentifier, bool $recursive = false, array $folderNameFilterCallbacks = []): int
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $runtime = $this->runtime();
        return $runtime->listing->count($runtime->listing->listFolder($folderIdentifier, $recursive)['folders'], $folderNameFilterCallbacks, $this);
    }

    public function moveFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): array
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $newFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier . '/' . $newFolderName);
        $this->runtime()->mapper->assertNotExcluded($newFolderIdentifier);
        return $this->runtime()->folders->copyTree($sourceFolderIdentifier, $newFolderIdentifier, true);
    }

    public function copyFolderWithinStorage(string $sourceFolderIdentifier, string $targetFolderIdentifier, string $newFolderName): bool
    {
        $sourceFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($sourceFolderIdentifier);
        $newFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier . '/' . $newFolderName);
        $this->runtime()->mapper->assertNotExcluded($newFolderIdentifier);
        $this->runtime()->folders->copyTree($sourceFolderIdentifier, $newFolderIdentifier, false);
        return true;
    }

    public function getRole(string $folderIdentifier): string
    {
        $name = PathUtility::basename(rtrim($folderIdentifier, '/'));
        return self::FOLDER_ROLES[$name] ?? FolderInterface::ROLE_DEFAULT;
    }

    /*******************
     * FILES
     *******************/

    public function fileExists(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        if ($fileIdentifier === '/' || str_ends_with($fileIdentifier, '/')) {
            return false;
        }
        $runtime = $this->runtime();
        if ($runtime->mapper->isExcluded($fileIdentifier)) {
            return false;
        }
        return $this->metadata($runtime->mapper->fileKey($fileIdentifier))->exists;
    }

    public function fileExistsInFolder(string $fileName, string $folderIdentifier): bool
    {
        return $this->fileExists($this->canonicalizeAndCheckFolderIdentifier($folderIdentifier) . $fileName);
    }

    public function getFileInFolder(string $fileName, string $folderIdentifier): string
    {
        return $this->canonicalizeAndCheckFileIdentifier($folderIdentifier . '/' . $fileName);
    }

    public function getFileInfoByIdentifier(string $fileIdentifier, array $propertiesToExtract = []): array
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $runtime = $this->runtime();
        $key = $runtime->mapper->fileKey($fileIdentifier);
        $metadata = $runtime->mapper->isExcluded($fileIdentifier) ? ObjectMetadata::missing() : $this->metadata($key);
        if (!$metadata->exists) {
            // LocalDriver throws this exact exception; callers in the core catch it.
            throw new \InvalidArgumentException('File ' . $fileIdentifier . ' does not exist.', 1314516809);
        }
        $properties = $propertiesToExtract === [] ? self::FILE_INFO_PROPERTIES : $propertiesToExtract;
        $info = [];
        foreach ($properties as $property) {
            $info[$property] = match ($property) {
                'size' => $metadata->size,
                'atime', 'mtime', 'ctime' => $metadata->mtime,
                'name' => PathUtility::basename($fileIdentifier),
                'extension' => PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION),
                'mimetype' => $metadata->contentType !== null && !in_array(strtolower($metadata->contentType), self::GENERIC_CONTENT_TYPES, true)
                    ? $metadata->contentType
                    : $runtime->contentTypes->forFile($fileIdentifier),
                'identifier' => $fileIdentifier,
                'storage' => $this->storageUid ?? 0,
                'identifier_hash' => $this->hashIdentifier($fileIdentifier),
                'folder_hash' => $this->hashIdentifier($this->getParentFolderIdentifierOfIdentifier($fileIdentifier)),
                default => throw new \InvalidArgumentException(sprintf('The information "%s" is not available.', $property), 1476047422),
            };
        }
        return $info;
    }

    public function getFilesInFolder(string $folderIdentifier, int $start = 0, int $numberOfItems = 0, bool $recursive = false, array $filenameFilterCallbacks = [], string $sort = '', bool $sortRev = false): array
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $runtime = $this->runtime();
        $entries = $runtime->listing->listFolder($folderIdentifier, $recursive)['files'];
        return $runtime->listing->apply($entries, $filenameFilterCallbacks, $sort, $sortRev, $start, $numberOfItems, $this);
    }

    public function countFilesInFolder(string $folderIdentifier, bool $recursive = false, array $filenameFilterCallbacks = []): int
    {
        $folderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($folderIdentifier);
        $runtime = $this->runtime();
        return $runtime->listing->count($runtime->listing->listFolder($folderIdentifier, $recursive)['files'], $filenameFilterCallbacks, $this);
    }

    public function addFile(string $localFilePath, string $targetFolderIdentifier, string $newFileName = '', bool $removeOriginal = true): string
    {
        $localFilePath = $this->canonicalizeAndCheckFilePath($localFilePath);
        $candidateName = $newFileName !== '' ? $newFileName : PathUtility::basename($localFilePath);
        if ($candidateName === '') {
            throw new InvalidFileNameException('File name must not be empty.', 1760400084);
        }
        $newFileName = $this->sanitizeFileName($candidateName);
        $newFileIdentifier = $this->canonicalizeAndCheckFolderIdentifier($targetFolderIdentifier) . $newFileName;
        $runtime = $this->runtime();
        $runtime->mapper->assertNotExcluded($newFileIdentifier);
        $key = $runtime->mapper->fileKey($newFileIdentifier);
        $relativeKey = $runtime->mapper->relativeKey($key);

        $options = new UploadOptions(
            $runtime->contentTypes->forFile($newFileName, $localFilePath),
            $runtime->cacheControl->for($relativeKey),
            $runtime->configuration->conditionalWrites ? '*' : null,
        );
        try {
            $metadata = $runtime->store->upload($key, $localFilePath, $options);
        } catch (PreconditionFailedException $e) {
            if (!CacheControlPolicy::isProcessed($relativeKey)) {
                throw new ExistingTargetFileNameException('The target file "' . $newFileIdentifier . '" already exists.', 1320291063, $e);
            }
            // Two replicas rendered the same variant; the one that lost the race is as good as the one that won.
            $runtime->logger->info('Processed file already present, keeping the existing object', ['key' => $key]);
            $metadata = $runtime->store->head($key);
        }
        $runtime->cache->invalidateWrite($key);
        $runtime->cache->rememberObject($key, $metadata);
        if ($removeOriginal) {
            @unlink($localFilePath);
        }
        return $newFileIdentifier;
    }

    public function createFile(string $fileName, string $parentFolderIdentifier): string
    {
        $fileName = ltrim($fileName, '/');
        if ($fileName === '') {
            throw new InvalidFileNameException('File name must not be empty.', 1760400085);
        }
        $fileName = $this->sanitizeFileName($fileName);
        $parentFolderIdentifier = $this->canonicalizeAndCheckFolderIdentifier($parentFolderIdentifier);
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($parentFolderIdentifier . $fileName);
        $runtime = $this->runtime();
        $runtime->mapper->assertNotExcluded($fileIdentifier);
        $key = $runtime->mapper->fileKey($fileIdentifier);
        try {
            $metadata = $runtime->store->putString($key, '', new UploadOptions(
                $runtime->contentTypes->forFile($fileName),
                $runtime->cacheControl->for($runtime->mapper->relativeKey($key)),
                $runtime->configuration->conditionalWrites ? '*' : null,
            ));
        } catch (PreconditionFailedException $e) {
            throw new ExistingTargetFileNameException('The target file "' . $fileIdentifier . '" already exists.', 1320291063, $e);
        }
        $runtime->cache->invalidateWrite($key);
        $runtime->cache->rememberObject($key, $metadata);
        return $fileIdentifier;
    }

    public function copyFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $fileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newIdentifier = $this->canonicalizeAndCheckFileIdentifier($targetFolderIdentifier . '/' . $fileName);
        $this->copyObject($fileIdentifier, $newIdentifier);
        return $newIdentifier;
    }

    public function renameFile(string $fileIdentifier, string $newName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $newName = $this->sanitizeFileName($newName);
        $newIdentifier = $this->canonicalizeAndCheckFileIdentifier(
            rtrim(PathUtility::dirname($fileIdentifier), '/') . '/' . $newName
        );
        if ($newIdentifier === $fileIdentifier) {
            return $fileIdentifier;
        }
        if ($this->fileExists($newIdentifier)) {
            throw new ExistingTargetFileNameException('The target file "' . $newIdentifier . '" already exists.', 1320291063);
        }
        $this->copyObject($fileIdentifier, $newIdentifier);
        $this->deleteObject($fileIdentifier, true);
        return $newIdentifier;
    }

    public function moveFileWithinStorage(string $fileIdentifier, string $targetFolderIdentifier, string $newFileName): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $targetIdentifier = $this->canonicalizeAndCheckFileIdentifier($targetFolderIdentifier . '/' . $newFileName);
        if ($targetIdentifier === $fileIdentifier) {
            return $fileIdentifier;
        }
        $this->copyObject($fileIdentifier, $targetIdentifier);
        $this->deleteObject($fileIdentifier, true);
        return $targetIdentifier;
    }

    public function replaceFile(string $fileIdentifier, string $localFilePath): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $runtime = $this->runtime();
        $runtime->mapper->assertNotExcluded($fileIdentifier);
        $key = $runtime->mapper->fileKey($fileIdentifier);

        $current = $this->metadata($key);
        $localMd5 = md5_file($localFilePath);
        if ($current->exists && $localMd5 !== false && $current->md5FromEtag() === $localMd5) {
            // Identical content: no new object version, no cache churn.
            @unlink($localFilePath);
            return true;
        }
        $metadata = $runtime->store->upload($key, $localFilePath, new UploadOptions(
            $runtime->contentTypes->forFile($fileIdentifier, $localFilePath),
            $runtime->cacheControl->for($runtime->mapper->relativeKey($key)),
        ));
        $runtime->cache->invalidateWrite($key);
        $runtime->cache->rememberObject($key, $metadata);
        @unlink($localFilePath);
        return true;
    }

    public function deleteFile(string $fileIdentifier): bool
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $this->runtime()->mapper->assertNotExcluded($fileIdentifier);
        $this->deleteObject($fileIdentifier);
        return true;
    }

    public function hash(string $fileIdentifier, string $hashAlgorithm): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $runtime = $this->runtime();
        $key = $runtime->mapper->fileKey($fileIdentifier);
        $metadata = $this->metadata($key);
        if (!$metadata->exists) {
            throw new \InvalidArgumentException('File ' . $fileIdentifier . ' does not exist.', 1314516809);
        }
        $etag = $metadata->etagKey();
        $cached = $runtime->cache->hash($key, $etag, $hashAlgorithm);
        if ($cached !== null) {
            return $cached;
        }
        if (strtolower($hashAlgorithm) === 'md5' && ($md5 = $metadata->md5FromEtag()) !== null) {
            $runtime->cache->rememberHash($key, $etag, $hashAlgorithm, $md5);
            return $md5;
        }
        try {
            $context = hash_init($hashAlgorithm);
        } catch (\ValueError $e) {
            throw new \InvalidArgumentException('Hash algorithm "' . $hashAlgorithm . '" is not supported.', 1760400090, $e);
        }
        $output = $runtime->store->get($key);
        foreach ($output->getBody()->getChunks() as $chunk) {
            hash_update($context, $chunk);
        }
        $hash = hash_final($context);
        $runtime->cache->rememberHash($key, $etag, $hashAlgorithm, $hash);
        return $hash;
    }

    public function getFileContents(string $fileIdentifier): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $runtime = $this->runtime();
        if ($runtime->mapper->isExcluded($fileIdentifier)) {
            return '';
        }
        try {
            return $runtime->store->get($runtime->mapper->fileKey($fileIdentifier))->getBody()->getContentAsString();
        } catch (FileDoesNotExistException) {
            return '';
        }
    }

    public function setFileContents(string $fileIdentifier, string $contents): int
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $runtime = $this->runtime();
        $runtime->mapper->assertNotExcluded($fileIdentifier);
        $key = $runtime->mapper->fileKey($fileIdentifier);
        $current = $this->metadata($key);
        if ($current->exists && $current->md5FromEtag() === md5($contents)) {
            return strlen($contents);
        }
        $metadata = $runtime->store->putString($key, $contents, new UploadOptions(
            $runtime->contentTypes->forFile($fileIdentifier),
            $runtime->cacheControl->for($runtime->mapper->relativeKey($key)),
        ));
        $runtime->cache->invalidateWrite($key);
        $runtime->cache->rememberObject($key, $metadata);
        return strlen($contents);
    }

    public function getFileForLocalProcessing(string $fileIdentifier, bool $writable = true): string
    {
        $fileIdentifier = $this->canonicalizeAndCheckFileIdentifier($fileIdentifier);
        $runtime = $this->runtime();
        $key = $runtime->mapper->fileKey($fileIdentifier);
        $metadata = $this->metadata($key);
        if (!$metadata->exists) {
            throw new \InvalidArgumentException('File ' . $fileIdentifier . ' does not exist.', 1314516809);
        }
        if ($metadata->archived) {
            throw new ObjectArchivedException($key, $metadata->storageClass);
        }
        if ($runtime->malwareGate->isBlocked($key, $metadata->etag)) {
            throw new ThreatsFoundException($fileIdentifier);
        }
        return $runtime->temporaryFiles->materialise(
            $key,
            $metadata->etag,
            PathUtility::pathinfo($fileIdentifier, PATHINFO_EXTENSION),
            $writable,
        );
    }

    public function getPermissions(string $identifier): array
    {
        return ['r' => true, 'w' => !$this->runtime()->mapper->isExcluded($identifier)];
    }

    public function dumpFileContents(string $identifier): void
    {
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        $runtime = $this->runtime();
        $output = $runtime->store->get($runtime->mapper->fileKey($identifier));
        foreach ($output->getBody()->getChunks() as $chunk) {
            echo $chunk;
        }
    }

    public function isWithin(string $folderIdentifier, string $identifier): bool
    {
        $folderIdentifier = $this->canonicalizeAndCheckFileIdentifier($folderIdentifier);
        $entryIdentifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        if ($folderIdentifier === $entryIdentifier) {
            return true;
        }
        if ($folderIdentifier !== '/') {
            $folderIdentifier .= '/';
        }
        return str_starts_with($entryIdentifier, $folderIdentifier);
    }

    /**
     * @param array{as_download?: bool, filename_overwrite?: ?string, mimetype_overwrite?: ?string} $properties
     */
    public function streamFile(string $identifier, array $properties): ResponseInterface
    {
        $identifier = $this->canonicalizeAndCheckFileIdentifier($identifier);
        $runtime = $this->runtime();
        if ($runtime->mapper->isExcluded($identifier)) {
            return new Response('php://temp', 404, ['Content-Type' => 'text/plain'], 'Not Found');
        }
        $key = $runtime->mapper->fileKey($identifier);
        $metadata = $this->metadata($key);
        if (!$metadata->exists) {
            return new Response('php://temp', 404, ['Content-Type' => 'text/plain'], 'Not Found');
        }
        if ($runtime->malwareGate->isBlocked($key, $metadata->etag)) {
            return new Response('php://temp', 403, ['Content-Type' => 'text/plain'], 'Forbidden');
        }

        $downloadName = (string)($properties['filename_overwrite'] ?? PathUtility::basename($identifier));
        $mimeType = (string)($properties['mimetype_overwrite'] ?? ($metadata->contentType ?: $runtime->contentTypes->forFile($identifier)));
        $disposition = ($properties['as_download'] ?? false) ? 'attachment' : 'inline';
        $contentDisposition = sprintf(
            "%s; filename=\"%s\"; filename*=UTF-8''%s",
            $disposition,
            str_replace(['"', "\r", "\n"], '', $downloadName),
            rawurlencode($downloadName),
        );

        if ($runtime->configuration->streamMode === ConfigurationResolver::STREAM_MODE_REDIRECT) {
            $expires = (new \DateTimeImmutable())->modify('+' . $runtime->configuration->presignLifetime . ' seconds');
            $url = $runtime->store->presign($key, $expires, $contentDisposition, $mimeType);
            return new Response('php://temp', 307, ['Location' => $url, 'Cache-Control' => 'private, no-store']);
        }

        try {
            $output = $runtime->store->get($key);
        } catch (ObjectArchivedException $e) {
            $body = new \TYPO3\CMS\Core\Http\Stream('php://temp', 'rw');
            $body->write($e->getMessage());
            return new Response($body, 503, ['Content-Type' => 'text/plain; charset=utf-8', 'Retry-After' => '3600', 'Cache-Control' => 'no-store']);
        }
        $size = $output->getContentLength() ?? $metadata->size;
        $headers = [
            'Content-Disposition' => $contentDisposition,
            'Content-Type' => $mimeType,
            'Content-Length' => (string)$size,
            'Last-Modified' => gmdate('D, d M Y H:i:s', $output->getLastModified()?->getTimestamp() ?? $metadata->mtime) . ' GMT',
            'Cache-Control' => $output->getCacheControl() ?: $runtime->cacheControl->for($runtime->mapper->relativeKey($key)),
        ];
        if ($output->getEtag() !== null) {
            $headers['ETag'] = $output->getEtag();
        }
        return new Response(new S3ObjectStream($output, $size), 200, $headers);
    }

    /**
     * Request and cache counters of this storage, for tests and diagnostics.
     *
     * @internal
     */
    public function getRequestCounter(): RequestCounter
    {
        return $this->runtime()->counter;
    }

    /*******************
     * INTERNALS
     *******************/

    private function runtime(): DriverRuntime
    {
        if ($this->runtime === null) {
            $this->initialize();
        }
        /** @var DriverRuntime $runtime */
        $runtime = $this->runtime;
        return $runtime;
    }

    /**
     * Metadata for a key: request memo, shared cache, then one HEAD.
     */
    private function metadata(string $key): ObjectMetadata
    {
        $runtime = $this->runtime();
        $cached = $runtime->cache->object($key);
        if ($cached !== null) {
            return $cached;
        }
        $metadata = $runtime->store->head($key);
        $runtime->cache->rememberObject($key, $metadata);
        return $metadata;
    }

    private function copyObject(string $sourceIdentifier, string $targetIdentifier): void
    {
        $runtime = $this->runtime();
        $runtime->mapper->assertNotExcluded($targetIdentifier);
        if ($runtime->mapper->isExcluded($sourceIdentifier)) {
            throw new FileDoesNotExistException('File ' . $sourceIdentifier . ' does not exist.', 1760400091);
        }
        $sourceKey = $runtime->mapper->fileKey($sourceIdentifier);
        $targetKey = $runtime->mapper->fileKey($targetIdentifier);
        $source = $this->metadata($sourceKey);
        if (!$source->exists) {
            throw new FileDoesNotExistException('File ' . $sourceIdentifier . ' does not exist.', 1760400092);
        }
        $metadata = $runtime->store->copy($sourceKey, $targetKey, $source->size, new UploadOptions(
            $runtime->contentTypes->forFile($targetIdentifier),
            $runtime->cacheControl->for($runtime->mapper->relativeKey($targetKey)),
        ));
        $runtime->cache->invalidateWrite($targetKey);
        $runtime->cache->rememberObject($targetKey, $metadata);
    }

    /**
     * @param bool $afterSuccessfulCopy the copy half of a move already succeeded: an orphaned
     *                                  source object is cheaper than a file TYPO3 believes to be missing,
     *                                  so the failure is logged and the new identifier still returned
     */
    private function deleteObject(string $identifier, bool $afterSuccessfulCopy = false): void
    {
        $runtime = $this->runtime();
        $key = $runtime->mapper->fileKey($identifier);
        try {
            $runtime->store->delete($key);
        } catch (FileOperationErrorException $e) {
            if (!$afterSuccessfulCopy) {
                throw $e;
            }
            $runtime->logger->error('Deleting the source object after a copy failed; an orphan remains', ['key' => $key, 'error' => $e->getMessage()]);
            $runtime->cache->invalidateWrite($key);
            return;
        }
        $runtime->cache->invalidateWrite($key);
        $runtime->cache->rememberObject($key, ObjectMetadata::missing());
    }
}
