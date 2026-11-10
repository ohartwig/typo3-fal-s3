<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Cache;

use Koh\FalS3\Cache\MetadataCacheFactory;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Cache\Backend\NullBackend;
use TYPO3\CMS\Core\Cache\Backend\RedisBackend;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Exception\InvalidBackendException;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * Where the configuration comes from, and that frontend and backend are put
 * together the way the CacheManager would.
 */
final class MetadataCacheFactoryTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    #[Test]
    public function withoutConfigurationTheDatabaseBackendIsTheDefault(): void
    {
        $caching = MetadataCacheFactory::relocate([]);
        self::assertSame(MetadataCacheFactory::DEFAULT_CONFIGURATION, $caching[MetadataCacheFactory::IDENTIFIER]);
        self::assertSame(Typo3DatabaseBackend::class, MetadataCacheFactory::DEFAULT_CONFIGURATION['backend']);
    }

    #[Test]
    public function aLegacyEntryIsMovedOutOfTheCacheManagerConfiguration(): void
    {
        // What TYPO3__SYS__caching__cacheConfigurations__koh_fal_s3_meta__backend and
        // __options__hostname produce: a partial entry, nothing else.
        $caching = MetadataCacheFactory::relocate([
            'cacheConfigurations' => [
                'pages' => ['backend' => NullBackend::class],
                'koh_fal_s3_meta' => [
                    'backend' => RedisBackend::class,
                    'options' => ['hostname' => 'valkey'],
                    'groups' => ['system'],
                ],
            ],
        ]);

        self::assertIsArray($caching['cacheConfigurations']);
        self::assertSame(['pages'], array_keys($caching['cacheConfigurations']), 'the CacheManager no longer knows the cache');
        self::assertSame([
            'frontend' => VariableFrontend::class,
            'backend' => RedisBackend::class,
            'options' => ['defaultLifetime' => 86400, 'hostname' => 'valkey'],
        ], $caching['koh_fal_s3_meta']);
    }

    #[Test]
    public function theNewKeyWinsOverTheLegacyEntryFieldByField(): void
    {
        $caching = MetadataCacheFactory::relocate([
            'cacheConfigurations' => [
                'koh_fal_s3_meta' => ['backend' => RedisBackend::class, 'options' => ['hostname' => 'old', 'database' => 7]],
            ],
            'koh_fal_s3_meta' => ['options' => ['hostname' => 'new']],
        ]);

        self::assertSame([
            'frontend' => VariableFrontend::class,
            'backend' => RedisBackend::class,
            'options' => ['defaultLifetime' => 86400, 'hostname' => 'new', 'database' => 7],
        ], $caching['koh_fal_s3_meta']);
    }

    #[Test]
    public function relocatingTwiceChangesNothing(): void
    {
        $once = MetadataCacheFactory::relocate(['cacheConfigurations' => ['koh_fal_s3_meta' => ['backend' => TransientMemoryBackend::class]]]);
        self::assertSame($once, MetadataCacheFactory::relocate($once));
    }

    #[Test]
    public function buildsFrontendAndBackendInTheCacheManagersOrder(): void
    {
        $frontend = MetadataCacheFactory::build([
            'frontend' => VariableFrontend::class,
            'backend' => '\\' . RecordingBackend::class,
            'options' => ['compression' => '1', 'hostname' => 'valkey'],
        ]);

        self::assertInstanceOf(VariableFrontend::class, $frontend);
        self::assertSame('koh_fal_s3_meta', $frontend->getIdentifier());
        $backend = $frontend->getBackend();
        self::assertInstanceOf(RecordingBackend::class, $backend);
        self::assertSame(['setCompression', 'setHostname', 'initializeObject', 'setCache:koh_fal_s3_meta'], $backend->calls);
        self::assertTrue($backend->compression, 'legacy non-bool compression is normalised as the CacheManager does');
        self::assertSame('valkey', $backend->hostname);

        $frontend->set('obj_x', ['size' => 1]);
        self::assertSame(['size' => 1], $frontend->get('obj_x'));
    }

    #[Test]
    public function aClassThatIsNoBackendIsRefused(): void
    {
        $this->expectException(InvalidBackendException::class);
        MetadataCacheFactory::build(['backend' => VariableFrontend::class]);
    }

    #[Test]
    public function anUnknownFrontendIsRefused(): void
    {
        $this->expectException(InvalidCacheException::class);
        MetadataCacheFactory::build(['frontend' => 'Koh\\FalS3\\DoesNotExist', 'backend' => TransientMemoryBackend::class]);
    }

    #[Test]
    public function theFactoryKeepsOneFrontendPerProcess(): void
    {
        $factory = new class extends MetadataCacheFactory {
            protected function configuration(): array
            {
                return ['backend' => TransientMemoryBackend::class];
            }
        };
        self::assertSame($factory->frontend(), $factory->frontend());
        self::assertNotSame($factory->frontend(), $factory->create());
        self::assertSame('', $factory->tableDefinitions(), 'only the database backend has tables');
        self::assertSame(TransientMemoryBackend::class, $factory->backendClassName());
    }
}
