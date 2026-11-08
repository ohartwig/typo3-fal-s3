<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Imaging;

use Koh\FalS3\Imaging\ImageService;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Extbase\Service\ImageService as CoreImageService;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * Whoever asks the container for Extbase's ImageService (Fluid's image view
 * helpers, site packages) gets the one that does not download S3 variants.
 */
final class ImageServiceRegistrationTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['extbase', 'fluid'];

    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    #[Test]
    public function theContainerHandsOutTheS3AwareImageService(): void
    {
        self::assertInstanceOf(ImageService::class, $this->get(CoreImageService::class));
        self::assertInstanceOf(ImageService::class, GeneralUtility::makeInstance(CoreImageService::class));
    }
}
