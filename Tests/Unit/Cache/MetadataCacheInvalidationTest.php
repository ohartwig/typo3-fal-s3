<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Cache;

use Koh\FalS3\Cache\MetadataCache;
use Koh\FalS3\Driver\Entry;
use Koh\FalS3\S3\ObjectMetadata;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The invalidation matrix: which cached facts a write at a key must drop,
 * and which it must leave alone (they are what makes the cache worth having).
 */
final class MetadataCacheInvalidationTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private MetadataCache $cache;
    private VariableFrontend $frontend;

    protected function setUp(): void
    {
        parent::setUp();
        $backend = new TransientMemoryBackend();
        $this->frontend = new VariableFrontend('koh_fal_s3_meta', $backend);
        $backend->setCache($this->frontend);
        $this->cache = new MetadataCache($this->frontend, 'bucket', 3600, 60);
    }

    #[Test]
    public function objectMetadataRoundTrips(): void
    {
        $metadata = new ObjectMetadata(true, 10, 20, '"e"', 'image/png', 'STANDARD', false);
        $this->cache->rememberObject('a/b.png', $metadata);
        self::assertEquals($metadata, $this->cache->object('a/b.png'));
        self::assertNull($this->cache->object('a/other.png'));
    }

    #[Test]
    public function writeDropsTheObjectTheParentListingAndRecursiveListingsAbove(): void
    {
        $this->seed();
        $this->cache->invalidateWrite('a/b/new.txt');

        self::assertNull($this->cache->object('a/b/new.txt'), 'the written key');
        self::assertNull($this->cache->object('a/b/sibling.txt'), 'siblings share the parent tag and are refilled by the next listing');
        self::assertNull($this->cache->listing('a/b/', false), 'parent listing');
        self::assertNull($this->cache->listing('a/b/', true), 'recursive listing of the parent');
        self::assertNull($this->cache->listing('a/', true), 'recursive listing of the grandparent');
        self::assertNull($this->cache->listing('', true), 'recursive listing of the root');
        self::assertNull($this->cache->folderExists('a/b/'), 'the parent may have come into existence');

        self::assertNotNull($this->cache->listing('a/', false), 'non-recursive listing of the grandparent is unaffected');
        self::assertNotNull($this->cache->listing('a/c/', false), 'an unrelated folder keeps its listing');
        self::assertNotNull($this->cache->listing('a/c/', true), 'and its recursive listing');
        self::assertNotNull($this->cache->object('a/c/other.txt'), 'objects elsewhere stay');
        self::assertNotNull($this->cache->folderExists('a/c/'));
        self::assertSame('deadbeef', $this->cache->hash('a/b/sibling.txt', 'etag1', 'sha1'), 'hashes are etag-scoped and never invalidated');
    }

    #[Test]
    public function folderTreeInvalidationDropsEverythingAboutTheFolderAndItsParent(): void
    {
        $this->seed();
        $this->cache->invalidateFolderTree('a/b/');

        self::assertNull($this->cache->listing('a/b/', false));
        self::assertNull($this->cache->listing('a/b/', true));
        self::assertNull($this->cache->listing('a/', false), 'the parent lists the folder');
        self::assertNull($this->cache->listing('a/', true));
        self::assertNull($this->cache->folderExists('a/b/'));
        self::assertNull($this->cache->object('a/b/sibling.txt'), 'direct children carry the folder tag');
        self::assertNotNull($this->cache->listing('a/c/', false), 'an unrelated sibling folder is untouched');
    }

    #[Test]
    public function missingObjectsAreCachedNegativelyAndCanBeForgotten(): void
    {
        $this->cache->rememberObject('gone.txt', ObjectMetadata::missing());
        self::assertFalse($this->cache->object('gone.txt')?->exists);
        $this->cache->forgetObject('gone.txt');
        self::assertNull($this->cache->object('gone.txt'));
    }

    #[Test]
    public function memoIsDroppedOnWriteSoTheSharedLayerWins(): void
    {
        $this->cache->rememberObject('x.txt', new ObjectMetadata(true, 1, 1, '"a"', null, null, false));
        // Another replica changed the shared layer behind our back.
        $this->frontend->remove('obj_' . sha1('bucket|x.txt'));
        self::assertNotNull($this->cache->object('x.txt'), 'memo still answers within the request');
        $this->cache->invalidateWrite('other.txt');
        self::assertNull($this->cache->object('x.txt'), 'after any write the memo is gone and the shared layer is consulted');
    }

    private function seed(): void
    {
        $meta = new ObjectMetadata(true, 1, 1, '"etag1"', null, null, false);
        $this->cache->rememberObject('a/b/new.txt', $meta);
        $this->cache->rememberObject('a/b/sibling.txt', $meta);
        $this->cache->rememberObject('a/c/other.txt', $meta);
        $empty = ['files' => [new Entry(Entry::TYPE_FILE, '/x', 'x', 'x', 0, 0, null, null)], 'folders' => []];
        foreach (['a/b/', 'a/', 'a/c/', ''] as $prefix) {
            $this->cache->rememberListing($prefix, false, $empty);
            $this->cache->rememberListing($prefix, true, $empty);
            $this->cache->rememberFolderExists($prefix, true);
        }
        $this->cache->rememberHash('a/b/sibling.txt', 'etag1', 'sha1', 'deadbeef');
    }
}
