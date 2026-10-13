<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Driver;

use Koh\FalS3\Driver\S3Driver;
use Koh\FalS3\Tests\Functional\Support\S3TestBucket;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * What the metadata cache buys: request counts per operation, and
 * invalidation that reaches a second driver instance through the shared
 * (database) backend, which is the replica situation.
 */
final class S3DriverCachingTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    private ?S3TestBucket $bucket = null;

    protected function setUp(): void
    {
        if (S3TestBucket::endpoint() === null) {
            self::markTestSkipped('KOH_FAL_S3_TEST_ENDPOINT is not set; no S3 server to test against.');
        }
        parent::setUp();
        $this->bucket = S3TestBucket::create();
    }

    protected function tearDown(): void
    {
        $this->bucket?->destroy();
        parent::tearDown();
    }

    #[Test]
    public function aListingAnswersEveryFileInfoWithoutFurtherRequests(): void
    {
        $driver = $this->driver();
        foreach (['a', 'b', 'c'] as $name) {
            $driver->addFile($this->localFile($name), '/', $name . '.txt');
        }
        $reader = $this->driver();
        $files = $reader->getFilesInFolder('/');
        self::assertCount(3, $files);
        foreach ($files as $identifier) {
            if ($identifier === '') {
                self::fail('empty identifier in listing');
            }
            $reader->getFileInfoByIdentifier($identifier);
            $reader->fileExists($identifier);
        }
        $requests = $reader->getRequestCounter()->snapshot()['requests'];
        self::assertSame(1, $requests['ListObjectsV2'] ?? 0);
        self::assertArrayNotHasKey('HeadObject', $requests, 'the listing seeded every object');

        $reader->getFilesInFolder('/');
        self::assertSame(1, $reader->getRequestCounter()->snapshot()['requests']['ListObjectsV2'], 'second listing is a cache hit');
    }

    #[Test]
    public function aWriteOnOneInstanceInvalidatesTheOther(): void
    {
        $writer = $this->driver();
        $reader = $this->driver();
        $writer->createFolder('docs', '/');
        self::assertSame([], $reader->getFilesInFolder('/docs/'));
        self::assertFalse($reader->fileExists('/docs/new.txt'));

        // A second request on the same replica: empty request memo, shared cache still warm.
        $warm = $this->driver();
        self::assertSame([], $warm->getFilesInFolder('/docs/'));
        self::assertFalse($warm->fileExists('/docs/new.txt'));
        self::assertSame([], $warm->getRequestCounter()->snapshot()['requests'], 'answered from the shared cache alone');

        $writer->addFile($this->localFile('new'), '/docs/', 'new.txt');

        $later = $this->driver();
        self::assertSame(['/docs/new.txt'], $later->getFilesInFolder('/docs/'), 'listing refetched after another instance wrote');
        self::assertTrue($later->fileExists('/docs/new.txt'), 'negative object cache dropped');

        $writer->deleteFile('/docs/new.txt');
        $afterDelete = $this->driver();
        self::assertFalse($afterDelete->fileExists('/docs/new.txt'));
        self::assertSame([], $afterDelete->getFilesInFolder('/docs/'));
    }

    #[Test]
    public function writesAreFollowedByNoHeadBecauseMetadataIsWrittenThrough(): void
    {
        $driver = $this->driver();
        $driver->addFile($this->localFile('x'), '/', 'x.txt');
        $driver->getFileInfoByIdentifier('/x.txt');
        $driver->hash('/x.txt', 'md5');
        $requests = $driver->getRequestCounter()->snapshot()['requests'];
        self::assertSame(1, $requests['PutObject'] ?? 0);
        self::assertArrayNotHasKey('HeadObject', $requests);
        self::assertArrayNotHasKey('GetObject', $requests, 'md5 comes from the ETag');
    }

    private function driver(): S3Driver
    {
        self::assertNotNull($this->bucket);
        $driver = new S3Driver($this->bucket->driverConfiguration());
        $driver->setStorageUid(1);
        $driver->mergeConfigurationCapabilities(new Capabilities(15));
        $driver->processConfiguration();
        $driver->initialize();
        return $driver;
    }

    /**
     * @return non-empty-string
     */
    private function localFile(string $content): string
    {
        $path = GeneralUtility::tempnam('caching-', '.txt');
        file_put_contents($path, $content);
        return $path;
    }
}
