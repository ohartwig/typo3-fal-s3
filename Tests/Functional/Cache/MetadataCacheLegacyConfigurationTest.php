<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Cache;

use Koh\FalS3\Cache\MetadataCacheFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * An installation that configures the cache the pre-1.2 way, under
 * cacheConfigurations (settings.php or TYPO3__SYS__caching__cacheConfigurations__…),
 * keeps its backend; the CacheManager does not get to build it.
 */
final class MetadataCacheLegacyConfigurationTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    protected array $configurationToUseInTestInstance = [
        'SYS' => [
            'caching' => [
                'cacheConfigurations' => [
                    'koh_fal_s3_meta' => [
                        'backend' => TransientMemoryBackend::class,
                        'groups' => ['system'],
                    ],
                ],
            ],
        ],
    ];

    #[Test]
    public function theLegacyEntryIsHonouredOutsideTheCacheManager(): void
    {
        /** @var array{SYS: array{caching: array{cacheConfigurations: array<string, mixed>}}} $confVars */
        $confVars = $GLOBALS['TYPO3_CONF_VARS'];
        self::assertArrayNotHasKey('koh_fal_s3_meta', $confVars['SYS']['caching']['cacheConfigurations']);
        self::assertArrayHasKey('koh_fal_s3_meta', $confVars['SYS']['caching']);
        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);
        self::assertFalse($cacheManager->hasCache('koh_fal_s3_meta'));

        $factory = $this->get(MetadataCacheFactory::class);
        self::assertInstanceOf(MetadataCacheFactory::class, $factory);
        self::assertInstanceOf(TransientMemoryBackend::class, $factory->frontend()->getBackend());
        self::assertSame('', $factory->tableDefinitions());
    }
}
