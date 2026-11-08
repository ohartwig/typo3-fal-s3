<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Imaging;

use Koh\FalS3\Driver\S3Driver;
use Koh\FalS3\Imaging\ImageService;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\MockObject\MockObject;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Resource\File;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Resource\ResourceFactory;
use TYPO3\CMS\Core\Resource\ResourceStorage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Registering a processed image with the AssetCollector must not download the
 * variant from S3: the entry is built from what TYPO3 already knows.
 */
final class ImageServiceTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    private AssetCollector $assets;

    protected function setUp(): void
    {
        parent::setUp();
        $this->assets = new AssetCollector();
        GeneralUtility::setSingletonInstance(AssetCollector::class, $this->assets);
    }

    #[Test]
    public function aVariantInAnS3StorageIsRegisteredWithoutADownload(): void
    {
        $variant = $this->variant(S3Driver::SHORT_NAME, 'https://media.example.org/_processed_/e/9/csm_a_1.avif');
        $variant->expects(self::never())->method('getForLocalProcessing');

        $this->applyTo($variant);

        self::assertSame([
            'https://media.example.org/_processed_/e/9/csm_a_1.avif' => [
                0 => 640,
                1 => 480,
                2 => 'avif',
                3 => 'https://media.example.org/_processed_/e/9/csm_a_1.avif',
                'origFile' => 'https://media.example.org/_processed_/e/9/csm_a_1.avif',
                'origFile_mtime' => 1700000000,
            ],
        ], $this->assets->getMedia());
    }

    #[Test]
    public function dimensionsFromTheDatabaseRowBecomeIntegers(): void
    {
        $variant = $this->variant(S3Driver::SHORT_NAME, 'https://media.example.org/a.webp', '320', null);
        $variant->expects(self::never())->method('getForLocalProcessing');

        $this->applyTo($variant);

        self::assertSame([
            'https://media.example.org/a.webp' => [
                0 => 320,
                1 => 0,
                2 => 'avif',
                3 => 'https://media.example.org/a.webp',
                'origFile' => 'https://media.example.org/a.webp',
                'origFile_mtime' => 1700000000,
            ],
        ], $this->assets->getMedia());
    }

    #[Test]
    public function aVariantWithoutPublicUrlIsNotRegistered(): void
    {
        $variant = $this->variant(S3Driver::SHORT_NAME, null);
        $variant->expects(self::never())->method('getForLocalProcessing');

        $this->applyTo($variant);

        self::assertSame([], $this->assets->getMedia());
    }

    #[Test]
    public function otherStoragesKeepTheCoreBehaviour(): void
    {
        $variant = $this->variant('Local', '/fileadmin/_processed_/e/9/csm_a_1.avif');
        $variant->expects(self::once())->method('getForLocalProcessing')->with(false)
            ->willReturn('/var/www/public/fileadmin/_processed_/e/9/csm_a_1.avif');

        $this->applyTo($variant);

        self::assertSame([
            '/fileadmin/_processed_/e/9/csm_a_1.avif' => [
                0 => 640,
                1 => 480,
                2 => 'avif',
                3 => '/var/www/public/fileadmin/_processed_/e/9/csm_a_1.avif',
                'origFile' => '/fileadmin/_processed_/e/9/csm_a_1.avif',
                'origFile_mtime' => 1700000000,
            ],
        ], $this->assets->getMedia());
    }

    private function applyTo(ProcessedFile $variant): void
    {
        $original = self::createStub(File::class);
        $original->method('process')->willReturn($variant);

        $service = new ImageService(self::createStub(ResourceFactory::class));
        self::assertSame($variant, $service->applyProcessingInstructions($original, ['width' => '640']));
    }

    private function variant(string $driverType, ?string $publicUrl, mixed $width = 640, mixed $height = 480): ProcessedFile&MockObject
    {
        $storage = self::createStub(ResourceStorage::class);
        $storage->method('getDriverType')->willReturn($driverType);

        $original = self::createStub(File::class);
        $original->method('getModificationTime')->willReturn(1700000000);

        $variant = $this->createMock(ProcessedFile::class);
        $variant->method('getStorage')->willReturn($storage);
        $variant->method('getPublicUrl')->willReturn($publicUrl);
        $variant->method('getOriginalFile')->willReturn($original);
        $variant->method('getExtension')->willReturn('avif');
        $variant->method('getProperty')->willReturnMap([['width', $width], ['height', $height]]);
        return $variant;
    }
}
