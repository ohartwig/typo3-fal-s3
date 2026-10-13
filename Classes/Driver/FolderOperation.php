<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

use Koh\FalS3\Cache\MetadataCache;
use Koh\FalS3\S3\CacheControlPolicy;
use Koh\FalS3\S3\ContentTypeResolver;
use Koh\FalS3\S3\ObjectStore;
use Koh\FalS3\S3\UploadOptions;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFolderException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;

/**
 * S3 has no rename. A folder move is: list every key under the source,
 * copy each to the destination, and only when every copy succeeded delete
 * the sources in batches. A failure mid-way leaves the source intact and
 * the partial destination for manual cleanup, named in the exception.
 */
final class FolderOperation
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly MetadataCache $cache,
        private readonly IdentifierMapper $mapper,
        private readonly CacheControlPolicy $policy,
        private readonly ContentTypeResolver $types,
        private readonly int $maxObjects,
        private readonly LoggerInterface $logger,
    ) {}

    /**
     * @param non-empty-string $sourceFolderIdentifier
     * @param non-empty-string $targetFolderIdentifier
     * @return array<non-empty-string, non-empty-string> old identifier => new identifier, the folder itself first
     */
    public function copyTree(string $sourceFolderIdentifier, string $targetFolderIdentifier, bool $deleteSource): array
    {
        $sourcePrefix = $this->mapper->folderPrefix($sourceFolderIdentifier);
        $targetPrefix = $this->mapper->folderPrefix($targetFolderIdentifier);
        if ($targetPrefix === $sourcePrefix || str_starts_with($targetPrefix, $sourcePrefix)) {
            throw new FileOperationErrorException(
                sprintf('Cannot copy folder "%s" into itself ("%s").', $sourceFolderIdentifier, $targetFolderIdentifier),
                1760400060,
            );
        }
        if ($this->store->prefixHasAnything($targetPrefix)) {
            throw new ExistingTargetFolderException(
                sprintf('The target folder "%s" already exists.', $targetFolderIdentifier),
                1760400061,
            );
        }

        $objects = $this->collect($sourcePrefix);
        $map = [$sourceFolderIdentifier => $targetFolderIdentifier];
        $copied = 0;
        $sourceHadMarker = false;
        try {
            foreach ($objects as $object) {
                $sourceKey = $object['key'];
                $targetKey = $targetPrefix . substr($sourceKey, strlen($sourcePrefix));
                if (KeyPaths::isFolderKey($sourceKey)) {
                    if ($sourceKey === $sourcePrefix) {
                        $sourceHadMarker = true;
                    }
                    $this->store->putString($targetKey, '', new UploadOptions(ContentTypeResolver::DIRECTORY, 'no-store'));
                } else {
                    $relativeTarget = $this->mapper->relativeKey($targetKey);
                    $metadata = $this->store->copy(
                        $sourceKey,
                        $targetKey,
                        $object['size'],
                        new UploadOptions($this->types->forFile($targetKey), $this->policy->for($relativeTarget)),
                    );
                    $this->cache->rememberObject($targetKey, $metadata);
                }
                $copied++;
                if ($sourceKey !== $sourcePrefix) {
                    $map[$this->mapper->identifierFromKey($sourceKey)] = $this->mapper->identifierFromKey($targetKey);
                }
            }
            if (!$sourceHadMarker) {
                // The source was only implied by its children; give the target an explicit marker
                // so it exists even when it is empty, and so renames of empty folders survive.
                $this->store->putString($targetPrefix, '', new UploadOptions(ContentTypeResolver::DIRECTORY, 'no-store'));
            }
        } catch (\Throwable $e) {
            $this->logger->error('Folder copy failed part-way; the source is intact, the partial target needs cleanup', [
                'source' => $sourcePrefix,
                'target' => $targetPrefix,
                'copied' => $copied,
                'total' => count($objects),
                'error' => $e->getMessage(),
            ]);
            $this->cache->invalidateFolderTree($targetPrefix);
            throw new FileOperationErrorException(
                sprintf('Copying folder "%s" to "%s" failed after %d of %d objects: %s', $sourceFolderIdentifier, $targetFolderIdentifier, $copied, count($objects), $e->getMessage()),
                1760400062,
                $e,
            );
        }

        if ($deleteSource) {
            $this->store->deleteMany(array_map(static fn(array $object): string => $object['key'], $objects));
            foreach ($objects as $object) {
                $this->cache->forgetObject($object['key']);
            }
            $this->cache->invalidateFolderTree($sourcePrefix);
            $this->cache->rememberFolderExists($sourcePrefix, false);
        }
        $this->cache->invalidateFolderTree($targetPrefix);
        $this->cache->rememberFolderExists($targetPrefix, true);
        return $map;
    }

    public function deleteTree(string $folderIdentifier): void
    {
        $prefix = $this->mapper->folderPrefix($folderIdentifier);
        $objects = $this->collect($prefix);
        $keys = array_map(static fn(array $object): string => $object['key'], $objects);
        if ($keys !== []) {
            $this->store->deleteMany($keys);
        }
        foreach ($keys as $key) {
            $this->cache->forgetObject($key);
        }
        $this->cache->invalidateFolderTree($prefix);
        $this->cache->rememberFolderExists($prefix, false);
    }

    /**
     * Every object under the prefix including folder markers, excluded
     * prefixes left out so they are neither copied nor deleted.
     *
     * @return list<array{key: string, size: int, mtime: int, etag: ?string, storageClass: ?string}>
     */
    private function collect(string $prefix): array
    {
        $objects = array_values(array_filter(
            $this->store->list($prefix, true)['objects'],
            fn(array $object): bool => !$this->mapper->isExcluded($object['key']),
        ));
        if (count($objects) > $this->maxObjects) {
            throw new FileOperationErrorException(
                sprintf('Folder "%s" holds %d objects, more than the %d this storage moves or deletes in one operation (maxFolderOperationObjects).', $prefix, count($objects), $this->maxObjects),
                1760400063,
            );
        }
        return $objects;
    }
}
