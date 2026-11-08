<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Imaging;

use Koh\FalS3\Driver\S3Driver;
use TYPO3\CMS\Core\Page\AssetCollector;
use TYPO3\CMS\Core\Resource\ProcessedFile;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService as CoreImageService;

/**
 * Extbase's ImageService without a download for every image variant that
 * lives in an S3 storage.
 *
 * After processing, the core service registers each variant with the
 * AssetCollector through ImageResource::createFromProcessedFile(). That call
 * asks for a local copy of the file (getForLocalProcessing()), only to store
 * its path as legacy information nobody reads. On a local storage the path is
 * free; on S3 it is a full GetObject of every variant, in every uncached
 * render of every page that shows the image.
 *
 * For variants in an S3 storage this registers the same media entry from the
 * values TYPO3 already has (width and height from sys_file_processedfile, the
 * public URL from the driver) and gives the public URL as the path. Other
 * storages keep the core behaviour.
 */
readonly class ImageService extends CoreImageService
{
    protected function setCompatibilityValues(ProcessedFile $processedImage): void
    {
        if ($processedImage->getStorage()->getDriverType() !== S3Driver::SHORT_NAME) {
            parent::setCompatibilityValues($processedImage);
            return;
        }
        $publicUrl = $processedImage->getPublicUrl();
        if ($publicUrl === null) {
            return;
        }
        $originalFile = $processedImage->getOriginalFile();
        GeneralUtility::makeInstance(AssetCollector::class)->addMedia($publicUrl, [
            0 => self::pixels($processedImage->getProperty('width')),
            1 => self::pixels($processedImage->getProperty('height')),
            2 => $processedImage->getExtension(),
            3 => $publicUrl,
            'origFile' => $publicUrl,
            'origFile_mtime' => $originalFile->getModificationTime(),
        ]);
    }

    private static function pixels(mixed $value): int
    {
        return is_numeric($value) ? (int)$value : 0;
    }
}
