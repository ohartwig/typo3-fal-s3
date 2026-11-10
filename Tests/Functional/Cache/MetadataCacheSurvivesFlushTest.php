<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Cache;

use Koh\FalS3\Cache\MetadataCacheFactory;
use Koh\FalS3\Command\MetadataFlushCommand;
use PHPUnit\Framework\Attributes\Test;
use Psr\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Event\CacheFlushEvent;
use TYPO3\CMS\Core\Console\CommandRegistry;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The metadata cache describes S3 objects, not code: a deployment's
 * `cache:flush` must leave it warm, the dedicated command must empty it.
 * Runs on the default database backend, whose tables only exist because
 * MetadataCacheSchemaListener declares them.
 */
final class MetadataCacheSurvivesFlushTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    #[Test]
    public function noCacheManagerFlushReachesTheMetadataCache(): void
    {
        $this->factory()->frontend()->set('obj_survivor', ['exists' => true], ['folder_x']);
        $cacheManager = $this->get(CacheManager::class);
        self::assertInstanceOf(CacheManager::class, $cacheManager);
        self::assertFalse($cacheManager->hasCache(MetadataCacheFactory::IDENTIFIER));

        // What `cache:flush` (any group) dispatches after flushing the core cache.
        $eventDispatcher = $this->get(EventDispatcherInterface::class);
        self::assertInstanceOf(EventDispatcherInterface::class, $eventDispatcher);
        $eventDispatcher->dispatch(new CacheFlushEvent($cacheManager->getCacheGroups()));
        // What "Flush all caches" in the backend calls.
        $cacheManager->flushCaches();
        $cacheManager->flushCachesInGroup('system');
        $cacheManager->flushCachesByTags(['folder_x']);

        self::assertSame(['exists' => true], $this->freshFrontendValue('obj_survivor'), 'entry still in the shared backend');
    }

    #[Test]
    public function theFlushCommandEmptiesIt(): void
    {
        $this->factory()->frontend()->set('obj_doomed', ['exists' => true]);
        $registry = $this->get(CommandRegistry::class);
        self::assertInstanceOf(CommandRegistry::class, $registry);
        self::assertTrue($registry->has('koh_fal_s3:metadata:flush'));

        $command = $this->get(MetadataFlushCommand::class);
        self::assertInstanceOf(MetadataFlushCommand::class, $command);
        $tester = new CommandTester($command);
        $tester->execute([]);

        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        self::assertStringContainsString('flushed', $tester->getDisplay());
        self::assertFalse($this->freshFrontendValue('obj_doomed'));
    }

    private function factory(): MetadataCacheFactory
    {
        $factory = $this->get(MetadataCacheFactory::class);
        self::assertInstanceOf(MetadataCacheFactory::class, $factory);
        return $factory;
    }

    /**
     * Read through a second, independent frontend: another process's view.
     */
    private function freshFrontendValue(string $id): mixed
    {
        return $this->factory()->create()->get($id);
    }
}
