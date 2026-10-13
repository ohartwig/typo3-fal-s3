<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Driver;

use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;
use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;
use TYPO3\CMS\Core\Resource\Exception\FolderDoesNotExistException;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The DriverInterface contract as TYPO3 relies on it, run against the S3
 * driver and against LocalDriver alike: where the two disagree, one of them
 * is wrong, and it is usually not the one the core ships.
 */
abstract class DriverContractTestCase extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'koh_fal_s3_meta' => [
                        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
                        'backend' => \TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend::class,
                    ],
                ],
            ],
        ],
    ];

    protected DriverInterface $driver;

    abstract protected function createDriver(): DriverInterface;

    abstract protected function expectedPublicUrl(string $identifier): string;

    protected function setUp(): void
    {
        parent::setUp();
        $this->driver = $this->createDriver();
    }

    #[Test]
    public function rootAndDefaultFolder(): void
    {
        self::assertSame('/', $this->driver->getRootLevelFolder());
        self::assertTrue($this->driver->folderExists('/'));
        self::assertSame('/user_upload/', $this->driver->getDefaultFolder());
        self::assertTrue($this->driver->folderExists('/user_upload/'));
        self::assertTrue($this->driver->folderExistsInFolder('user_upload', '/'));
        self::assertFalse($this->driver->folderExists('/nope/'));
        self::assertFalse($this->driver->folderExistsInFolder('nope', '/'));
    }

    #[Test]
    public function createsFoldersFlatAndRecursively(): void
    {
        self::assertSame('/docs/', $this->driver->createFolder('docs', '/'));
        self::assertSame('/docs/a/b/', $this->driver->createFolder('a/b', '/docs/', true));
        self::assertTrue($this->driver->folderExists('/docs/a/'));
        self::assertTrue($this->driver->folderExists('/docs/a/b/'));
        self::assertTrue($this->driver->isFolderEmpty('/docs/a/b/'));
        self::assertFalse($this->driver->isFolderEmpty('/docs/'));

        $info = $this->driver->getFolderInfoByIdentifier('/docs/a/');
        self::assertSame('/docs/a/', $info['identifier']);
        self::assertSame('a', $info['name']);
        self::assertSame(1, $info['storage']);
        self::assertSame('/docs/a/', $this->driver->getFolderInFolder('a', '/docs/'));
    }

    #[Test]
    public function missingFolderInfoThrows(): void
    {
        $this->expectException(FolderDoesNotExistException::class);
        $this->driver->getFolderInfoByIdentifier('/missing/');
    }

    #[Test]
    public function addsAFileAndReadsItBack(): void
    {
        $local = $this->localFile('hello world', 'txt');
        $identifier = $this->driver->addFile($local, '/', 'greeting.txt');

        self::assertSame('/greeting.txt', $identifier);
        self::assertFileDoesNotExist($local, 'removeOriginal defaults to true');
        self::assertTrue($this->driver->fileExists('/greeting.txt'));
        self::assertTrue($this->driver->fileExistsInFolder('greeting.txt', '/'));
        self::assertFalse($this->driver->fileExists('/nothing.txt'));
        self::assertSame('hello world', $this->driver->getFileContents('/greeting.txt'));
        self::assertSame(sha1('hello world'), $this->driver->hash('/greeting.txt', 'sha1'));
        self::assertSame(md5('hello world'), $this->driver->hash('/greeting.txt', 'md5'));
        self::assertSame(hash('sha256', 'hello world'), $this->driver->hash('/greeting.txt', 'sha256'));

        $info = $this->driver->getFileInfoByIdentifier('/greeting.txt');
        self::assertSame(11, $info['size']);
        self::assertSame('greeting.txt', $info['name']);
        self::assertSame('txt', $info['extension']);
        self::assertSame('text/plain', $info['mimetype']);
        self::assertSame('/greeting.txt', $info['identifier']);
        self::assertSame(sha1('/greeting.txt'), $info['identifier_hash']);
        self::assertSame(sha1('/'), $info['folder_hash']);
        self::assertSame(1, $info['storage']);
        self::assertGreaterThan(0, $info['mtime']);

        $partial = $this->driver->getFileInfoByIdentifier('/greeting.txt', ['size', 'name']);
        self::assertSame(['size' => 11, 'name' => 'greeting.txt'], $partial);
    }

    #[Test]
    public function addFileCanKeepTheOriginal(): void
    {
        $local = $this->localFile('keep', 'txt');
        $this->driver->addFile($local, '/', 'kept.txt', false);
        self::assertFileExists($local);
    }

    #[Test]
    public function missingFileInfoThrowsLikeLocalDriver(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionCode(1314516809);
        $this->driver->getFileInfoByIdentifier('/missing.txt');
    }

    #[Test]
    public function createsEmptyFilesAndSetsContents(): void
    {
        self::assertSame('/notes.md', $this->driver->createFile('notes.md', '/'));
        self::assertSame('', $this->driver->getFileContents('/notes.md'));
        self::assertSame(5, $this->driver->setFileContents('/notes.md', '# hey'));
        self::assertSame('# hey', $this->driver->getFileContents('/notes.md'));
        self::assertSame(5, $this->driver->getFileInfoByIdentifier('/notes.md')['size']);
    }

    #[Test]
    public function renamesMovesCopiesAndDeletesFiles(): void
    {
        $this->driver->createFolder('target', '/');
        $this->driver->addFile($this->localFile('one', 'txt'), '/', 'one.txt');

        self::assertSame('/uno.txt', $this->driver->renameFile('/one.txt', 'uno.txt'));
        self::assertFalse($this->driver->fileExists('/one.txt'));
        self::assertSame('one', $this->driver->getFileContents('/uno.txt'));

        self::assertSame('/target/copy.txt', $this->driver->copyFileWithinStorage('/uno.txt', '/target/', 'copy.txt'));
        self::assertTrue($this->driver->fileExists('/uno.txt'));
        self::assertSame('one', $this->driver->getFileContents('/target/copy.txt'));

        self::assertSame('/target/moved.txt', $this->driver->moveFileWithinStorage('/uno.txt', '/target/', 'moved.txt'));
        self::assertFalse($this->driver->fileExists('/uno.txt'));
        self::assertSame('one', $this->driver->getFileContents('/target/moved.txt'));

        self::assertTrue($this->driver->deleteFile('/target/moved.txt'));
        self::assertFalse($this->driver->fileExists('/target/moved.txt'));
        self::assertTrue($this->driver->fileExists('/target/copy.txt'));
    }

    #[Test]
    public function renamingOntoAnExistingFileIsRefused(): void
    {
        $this->driver->addFile($this->localFile('a', 'txt'), '/', 'a.txt');
        $this->driver->addFile($this->localFile('b', 'txt'), '/', 'b.txt');
        $this->expectException(ExistingTargetFileNameException::class);
        $this->driver->renameFile('/a.txt', 'b.txt');
    }

    #[Test]
    public function replaceFileSwapsTheContent(): void
    {
        $this->driver->addFile($this->localFile('v1', 'txt'), '/', 'r.txt');
        $hashBefore = $this->driver->hash('/r.txt', 'sha1');
        self::assertTrue($this->driver->replaceFile('/r.txt', $this->localFile('version two', 'txt')));
        self::assertSame('version two', $this->driver->getFileContents('/r.txt'));
        self::assertNotSame($hashBefore, $this->driver->hash('/r.txt', 'sha1'));
        self::assertSame(11, $this->driver->getFileInfoByIdentifier('/r.txt')['size']);
    }

    #[Test]
    public function listsFilesAndFoldersWithSortingPagingAndFilters(): void
    {
        $this->driver->createFolder('zeta', '/');
        $this->driver->createFolder('alpha', '/');
        $this->driver->addFile($this->localFile('xx', 'txt'), '/', 'b.txt');
        $this->driver->addFile($this->localFile('x', 'txt'), '/', 'a.txt');
        $this->driver->addFile($this->localFile('xxx', 'jpg'), '/', 'c.jpg');
        $this->driver->addFile($this->localFile('deep', 'txt'), '/alpha/', 'deep.txt');

        self::assertSame(['/a.txt', '/b.txt', '/c.jpg'], self::values($this->driver->getFilesInFolder('/')));
        self::assertSame(['/c.jpg', '/b.txt', '/a.txt'], self::values($this->driver->getFilesInFolder('/', 0, 0, false, [], 'name', true)));
        self::assertSame(['/a.txt', '/b.txt', '/c.jpg'], self::values($this->driver->getFilesInFolder('/', 0, 0, false, [], 'size')));
        self::assertSame(['/b.txt'], self::values($this->driver->getFilesInFolder('/', 1, 1)));
        self::assertSame(3, $this->driver->countFilesInFolder('/'));
        self::assertSame(4, $this->driver->countFilesInFolder('/', true));
        self::assertSame(['/a.txt', '/b.txt', '/c.jpg', '/alpha/deep.txt'], self::values($this->driver->getFilesInFolder('/', 0, 0, true)));

        self::assertSame(['/alpha/', '/zeta/'], self::values($this->driver->getFoldersInFolder('/')));
        self::assertSame(2, $this->driver->countFoldersInFolder('/'));
        self::assertSame([], self::values($this->driver->getFoldersInFolder('/alpha/')));

        $onlyTxt = static fn(string $name): int|bool => str_ends_with($name, '.txt') ? true : -1;
        self::assertSame(['/a.txt', '/b.txt'], self::values($this->driver->getFilesInFolder('/', 0, 0, false, [$onlyTxt])));
        self::assertSame(2, $this->driver->countFilesInFolder('/', false, [$onlyTxt]));
        self::assertSame('/a.txt', $this->driver->getFileInFolder('a.txt', '/'));
    }

    #[Test]
    public function renamesMovesCopiesAndDeletesFolders(): void
    {
        $this->driver->createFolder('src', '/');
        $this->driver->createFolder('sub', '/src/');
        $this->driver->createFolder('empty', '/src/');
        $this->driver->addFile($this->localFile('1', 'txt'), '/src/', 'one.txt');
        $this->driver->addFile($this->localFile('2', 'txt'), '/src/sub/', 'two.txt');
        $this->driver->createFolder('elsewhere', '/');

        $map = $this->driver->renameFolder('/src/', 'renamed');
        self::assertSame('/renamed/', $map['/src/']);
        self::assertSame('/renamed/one.txt', $map['/src/one.txt']);
        self::assertSame('/renamed/sub/two.txt', $map['/src/sub/two.txt']);
        self::assertSame('/renamed/sub/', $map['/src/sub/']);
        self::assertFalse($this->driver->folderExists('/src/'));
        self::assertTrue($this->driver->folderExists('/renamed/empty/'));
        self::assertSame('2', $this->driver->getFileContents('/renamed/sub/two.txt'));

        $map = $this->driver->moveFolderWithinStorage('/renamed/', '/elsewhere/', 'moved');
        self::assertSame('/elsewhere/moved/', $map['/renamed/']);
        self::assertSame('/elsewhere/moved/sub/two.txt', $map['/renamed/sub/two.txt']);
        self::assertFalse($this->driver->folderExists('/renamed/'));
        self::assertTrue($this->driver->fileExists('/elsewhere/moved/one.txt'));

        self::assertTrue($this->driver->copyFolderWithinStorage('/elsewhere/moved/', '/', 'copied'));
        self::assertTrue($this->driver->fileExists('/copied/sub/two.txt'));
        self::assertTrue($this->driver->fileExists('/elsewhere/moved/sub/two.txt'));
        self::assertTrue($this->driver->folderExists('/copied/empty/'));

        try {
            $this->driver->deleteFolder('/copied/');
            self::fail('deleting a non-empty folder without recursion must fail');
        } catch (FileOperationErrorException) {
        }
        self::assertTrue($this->driver->deleteFolder('/copied/', true));
        self::assertFalse($this->driver->folderExists('/copied/'));
        self::assertFalse($this->driver->fileExists('/copied/sub/two.txt'));
        self::assertTrue($this->driver->deleteFolder('/elsewhere/moved/empty/'));
        self::assertFalse($this->driver->folderExists('/elsewhere/moved/empty/'));
    }

    #[Test]
    public function localProcessingHandsOutARealFile(): void
    {
        $this->driver->addFile($this->localFile('process me', 'txt'), '/', 'p.txt');

        $readOnly = $this->driver->getFileForLocalProcessing('/p.txt', false);
        self::assertFileExists($readOnly);
        self::assertSame('process me', (string)file_get_contents($readOnly));

        $writable = $this->driver->getFileForLocalProcessing('/p.txt', true);
        self::assertFileExists($writable);
        self::assertNotSame($readOnly, $writable, 'a writable copy is a copy');
        file_put_contents($writable, 'processed');
        self::assertSame('process me', $this->driver->getFileContents('/p.txt'), 'editing the copy does not touch the storage');
        $this->driver->replaceFile('/p.txt', $writable);
        self::assertSame('processed', $this->driver->getFileContents('/p.txt'));
    }

    #[Test]
    public function publicUrlsAndContainment(): void
    {
        $this->driver->createFolder('ä folder', '/');
        $this->driver->addFile($this->localFile('x', 'txt'), '/ä_folder/', 'my file.txt');
        self::assertSame($this->expectedPublicUrl('/%C3%A4_folder/my%20file.txt'), $this->driver->getPublicUrl('/ä_folder/my file.txt'));

        self::assertTrue($this->driver->isWithin('/', '/a/b.txt'));
        self::assertTrue($this->driver->isWithin('/a/', '/a/b.txt'));
        self::assertTrue($this->driver->isWithin('/a/', '/a/'));
        self::assertFalse($this->driver->isWithin('/a/', '/ab/c.txt'));
        self::assertFalse($this->driver->isWithin('/a/b/', '/a/c.txt'));
    }

    #[Test]
    public function permissionsAndRoles(): void
    {
        $this->driver->getDefaultFolder();
        self::assertSame(['r' => true, 'w' => true], $this->driver->getPermissions('/user_upload/'));
        if (method_exists($this->driver, 'getRole')) {
            self::assertSame('userupload', $this->driver->getRole('/user_upload/'));
            self::assertSame('recycler', $this->driver->getRole('/_recycler_/'));
            self::assertSame('default', $this->driver->getRole('/images/'));
        }
    }

    #[Test]
    public function sanitizesFileNames(): void
    {
        self::assertSame('a_b.txt', $this->driver->sanitizeFileName('a b.txt'));
        self::assertSame('x.txt', $this->driver->sanitizeFileName('x.txt...'));
        self::assertSame('__', $this->driver->sanitizeFileName('<>'));
    }

    #[Test]
    public function unknownHashAlgorithmIsRejected(): void
    {
        $this->driver->addFile($this->localFile('h', 'txt'), '/', 'h.txt');
        $this->expectException(\Throwable::class);
        $this->driver->hash('/h.txt', 'no-such-algorithm');
    }

    /**
     * LocalDriver returns identifier => identifier maps, the interface says list; TYPO3 itself
     * only ever reads the values.
     *
     * @param array<mixed> $items
     * @return list<mixed>
     */
    protected static function values(array $items): array
    {
        return array_values($items);
    }

    /**
     * @return non-empty-string
     */
    protected function localFile(string $content, string $extension): string
    {
        $path = GeneralUtility::tempnam('contract-', '.' . $extension);
        file_put_contents($path, $content);
        return $path;
    }
}
