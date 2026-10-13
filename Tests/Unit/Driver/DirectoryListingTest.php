<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Driver;

use Koh\FalS3\Cache\MetadataCache;
use Koh\FalS3\Driver\DirectoryListing;
use Koh\FalS3\Driver\Entry;
use Koh\FalS3\Driver\IdentifierMapper;
use Koh\FalS3\S3\ObjectStore;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class DirectoryListingTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private DirectoryListing $listing;
    private ObjectStore&\PHPUnit\Framework\MockObject\MockObject $store;
    private MetadataCache $cache;
    private DriverInterface $driver;

    protected function setUp(): void
    {
        parent::setUp();
        $backend = new TransientMemoryBackend();
        $frontend = new VariableFrontend('koh_fal_s3_meta', $backend);
        $backend->setCache($frontend);
        $this->cache = new MetadataCache($frontend, 'bucket', 3600, 60);
        $this->store = $this->createMock(ObjectStore::class);
        $mapper = new IdentifierMapper('', ['_typo3temp-assets/']);
        $this->listing = new DirectoryListing($this->store, $this->cache, $mapper);
        $this->driver = $this->createMock(DriverInterface::class);
    }

    #[Test]
    public function nonRecursiveListingSplitsFilesAndFoldersAndHidesMarkersAndExclusions(): void
    {
        $this->store->expects(self::once())->method('list')->with('', false)->willReturn([
            'objects' => [
                ['key' => 'b.txt', 'size' => 3, 'mtime' => 100, 'etag' => '"x"', 'storageClass' => 'STANDARD'],
                ['key' => 'a.txt', 'size' => 5, 'mtime' => 200, 'etag' => '"y"', 'storageClass' => 'STANDARD'],
                ['key' => 'malware-probe', 'size' => 0, 'mtime' => 1, 'etag' => null, 'storageClass' => null],
            ],
            'prefixes' => ['user_upload/', '_typo3temp-assets/', '_processed_/'],
        ]);

        $result = $this->listing->listFolder('/', false);

        self::assertSame(['/b.txt', '/a.txt', '/malware-probe'], array_map(static fn(Entry $e): string => $e->identifier, $result['files']));
        self::assertSame(['/user_upload/', '/_processed_/'], array_map(static fn(Entry $e): string => $e->identifier, $result['folders']));
        // The listing seeded the object cache: no HEAD needed for these afterwards.
        self::assertSame(5, $this->cache->object('a.txt')?->size);
        // Second call is served from cache (store mock allows exactly one list()).
        $this->listing->listFolder('/', false);
    }

    #[Test]
    public function recursiveListingDerivesIntermediateFolders(): void
    {
        $this->store->expects(self::once())->method('list')->with('a/', true)->willReturn([
            'objects' => [
                ['key' => 'a/', 'size' => 0, 'mtime' => 1, 'etag' => null, 'storageClass' => null],
                ['key' => 'a/x.txt', 'size' => 1, 'mtime' => 1, 'etag' => null, 'storageClass' => null],
                ['key' => 'a/b/c/deep.txt', 'size' => 1, 'mtime' => 1, 'etag' => null, 'storageClass' => null],
                ['key' => 'a/empty/', 'size' => 0, 'mtime' => 42, 'etag' => null, 'storageClass' => null],
            ],
            'prefixes' => [],
        ]);

        $result = $this->listing->listFolder('/a/', true);

        self::assertSame(['/a/x.txt', '/a/b/c/deep.txt'], array_map(static fn(Entry $e): string => $e->identifier, $result['files']));
        $folders = array_map(static fn(Entry $e): string => $e->identifier, $result['folders']);
        sort($folders);
        self::assertSame(['/a/b/', '/a/b/c/', '/a/empty/'], $folders);
        $empty = array_values(array_filter($result['folders'], static fn(Entry $e): bool => $e->identifier === '/a/empty/'))[0];
        self::assertSame(42, $empty->mtime, 'marker mtime is kept for the folder');
    }

    #[Test]
    public function sortsLikeLocalDriverAndPaginates(): void
    {
        $entries = [
            $this->file('/img10.jpg', 300, 30),
            $this->file('/img2.jpg', 100, 10),
            $this->file('/doc.pdf', 200, 20),
        ];

        self::assertSame(['/doc.pdf', '/img2.jpg', '/img10.jpg'], $this->listing->apply($entries, [], '', false, 0, 0, $this->driver), 'natural, case-insensitive name order');
        self::assertSame(['/img10.jpg', '/img2.jpg', '/doc.pdf'], $this->listing->apply($entries, [], '', true, 0, 0, $this->driver));
        self::assertSame(['/img2.jpg', '/doc.pdf', '/img10.jpg'], $this->listing->apply($entries, [], 'size', false, 0, 0, $this->driver));
        self::assertSame(['/img2.jpg', '/doc.pdf', '/img10.jpg'], $this->listing->apply($entries, [], 'tstamp', false, 0, 0, $this->driver));
        self::assertSame(['/img10.jpg', '/img2.jpg', '/doc.pdf'], $this->listing->apply($entries, [], 'fileext', false, 0, 0, $this->driver));
        self::assertSame(['/img2.jpg'], $this->listing->apply($entries, [], '', false, 1, 1, $this->driver), 'start=1, one item');
        self::assertSame(['/img2.jpg', '/img10.jpg'], $this->listing->apply($entries, [], '', false, 1, 0, $this->driver), 'start=1, unlimited');
    }

    #[Test]
    public function sortKeyCollisionsKeepEveryEntry(): void
    {
        $entries = [$this->file('/a.txt', 1, 1), $this->file('/b.txt', 1, 1), $this->file('/c.txt', 1, 1)];
        self::assertCount(3, $this->listing->apply($entries, [], 'size', false, 0, 0, $this->driver));
    }

    #[Test]
    public function filtersFollowTheCoreContract(): void
    {
        $entries = [$this->file('/keep.txt', 1, 1), $this->file('/.hidden', 1, 1)];
        $hideDotFiles = static fn(string $name): int|bool => str_starts_with($name, '.') ? -1 : true;
        self::assertSame(['/keep.txt'], $this->listing->apply($entries, [$hideDotFiles], '', false, 0, 0, $this->driver));
        self::assertSame(1, $this->listing->count($entries, [$hideDotFiles], $this->driver));

        $broken = static fn(): bool => false;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionCode(1476046425);
        $this->listing->apply($entries, [$broken], '', false, 0, 0, $this->driver);
    }

    #[Test]
    public function filtersReceiveTheParentIdentifier(): void
    {
        $seen = [];
        $spy = static function (string $name, string $identifier, string $parent) use (&$seen): bool {
            $seen[] = [$name, $identifier, $parent];
            return true;
        };
        $entries = [
            $this->file('/a/b/c.txt', 1, 1),
            new Entry(Entry::TYPE_DIR, '/a/b/', 'b', 'a/b/', 0, 0, null, null),
            $this->file('/root.txt', 1, 1),
        ];
        $this->listing->apply($entries, [$spy], '', false, 0, 0, $this->driver);
        self::assertContains(['c.txt', '/a/b/c.txt', '/a/b/'], $seen);
        self::assertContains(['b', '/a/b/', '/a/'], $seen);
        self::assertContains(['root.txt', '/root.txt', '/'], $seen);
    }

    /**
     * @param non-empty-string $identifier
     */
    private function file(string $identifier, int $size, int $mtime): Entry
    {
        return new Entry(Entry::TYPE_FILE, $identifier, basename($identifier), ltrim($identifier, '/'), $size, $mtime, null, null);
    }
}
