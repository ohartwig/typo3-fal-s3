<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Storage;

use Koh\FalS3\Driver\S3Driver;
use Koh\FalS3\Tests\Functional\Support\S3TestBucket;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Resource\StorageRepository;
use TYPO3\CMS\Core\Utility\CommandUtility;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The driver inside the FAL: a sys_file_storage record using it, the public
 * URL TYPO3 derives, image processing writing variants back to the bucket,
 * and the fallback storage's processing folder redirected into storage 1.
 */
final class StorageIntegrationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    protected array $configurationToUseInTestInstance = [
        'EXTENSIONS' => ['koh_fal_s3' => ['fallbackProcessingFolder' => '1:/_processed_/_fallback_/']],
    ];

    private ?S3TestBucket $bucket = null;

    protected function setUp(): void
    {
        if (S3TestBucket::endpoint() === null) {
            self::markTestSkipped('KOH_FAL_S3_TEST_ENDPOINT is not set; no S3 server to test against.');
        }
        parent::setUp();
        $this->bucket = S3TestBucket::create();
        $fields = [];
        foreach ($this->bucket->driverConfiguration() as $key => $value) {
            $fields[$key] = ['vDEF' => is_scalar($value) ? (string)$value : ''];
        }
        $xml = GeneralUtility::makeInstance(FlexFormTools::class)->flexArray2Xml(['data' => ['sDEF' => ['lDEF' => $fields]]]);
        GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_storage')->insert('sys_file_storage', [
            'uid' => 1,
            'pid' => 0,
            'name' => 'S3 media',
            'driver' => S3Driver::SHORT_NAME,
            'configuration' => $xml,
            'is_browsable' => 1,
            'is_public' => 1,
            'is_writable' => 1,
            'is_online' => 1,
            'is_default' => 1,
        ]);
    }

    protected function tearDown(): void
    {
        $this->bucket?->destroy();
        parent::tearDown();
    }

    #[Test]
    public function aStorageOnTheDriverAddsFilesAndBuildsPublicUrls(): void
    {
        $storage = $this->storage(1);
        self::assertTrue($storage->isOnline());
        self::assertSame(S3Driver::SHORT_NAME, $storage->getDriverType());

        $local = GeneralUtility::tempnam('storage-', '.txt');
        file_put_contents($local, 'through the storage');
        $file = $storage->addFile($local, $storage->getRootLevelFolder(), 'via-storage.txt');

        self::assertInstanceOf(File::class, $file);
        self::assertSame('/via-storage.txt', $file->getIdentifier());
        self::assertSame('/fileadmin/via-storage.txt', $file->getPublicUrl());
        self::assertSame(sha1('through the storage'), $file->getSha1());
        self::assertSame(19, $file->getSize());
        self::assertSame('text/plain', $file->getMimeType());
        self::assertContains('via-storage.txt', $this->bucket()->keys());

        $folder = $storage->createFolder('reports', $storage->getRootLevelFolder());
        $moved = $file->moveTo($folder);
        self::assertSame('/reports/via-storage.txt', $moved->getIdentifier());
        $keys = $this->bucket()->keys();
        self::assertContains('reports/', $keys);
        self::assertContains('reports/via-storage.txt', $keys);
        self::assertNotContains('via-storage.txt', $keys);
    }

    #[Test]
    public function processedVariantsLandInTheBucket(): void
    {
        if (CommandUtility::checkCommand('magick') === false && CommandUtility::checkCommand('convert') === false && CommandUtility::checkCommand('gm') === false) {
            self::markTestSkipped('No ImageMagick/GraphicsMagick binary in this environment.');
        }
        $storage = $this->storage(1);
        $local = GeneralUtility::tempnam('storage-', '.png');
        $image = imagecreatetruecolor(40, 30);
        self::assertNotFalse($image);
        imagepng($image, $local);
        $file = $storage->addFile($local, $storage->getRootLevelFolder(), 'photo.png');
        self::assertInstanceOf(File::class, $file);

        $processed = $file->process(ProcessedFile::CONTEXT_IMAGECROPSCALEMASK, ['width' => '20']);
        self::assertTrue($processed->isProcessed());
        self::assertStringStartsWith('/_processed_/', $processed->getIdentifier());
        self::assertStringStartsWith('/fileadmin/_processed_/', (string)$processed->getPublicUrl());
        self::assertContains(ltrim($processed->getIdentifier(), '/'), $this->bucket()->keys());
    }

    #[Test]
    public function fallbackStorageProcessesIntoStorageOne(): void
    {
        $processingFolder = $this->storage(0)->getProcessingFolder();
        self::assertSame(1, $processingFolder->getStorage()->getUid());
        self::assertSame('/_processed_/_fallback_/', $processingFolder->getIdentifier());
        self::assertContains('_processed_/_fallback_/', $this->bucket()->keys(), 'the processing folder marker was created in the bucket');
    }

    private function bucket(): S3TestBucket
    {
        self::assertNotNull($this->bucket);
        return $this->bucket;
    }

    private function storage(int $uid): ResourceStorage
    {
        $storage = GeneralUtility::makeInstance(StorageRepository::class)->findByUid($uid);
        self::assertNotNull($storage);
        return $storage;
    }
}
