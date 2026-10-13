<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

use Koh\FalS3\Cache\MetadataCache;
use Koh\FalS3\Configuration\DriverConfiguration;
use Koh\FalS3\Metrics\RequestCounter;
use Koh\FalS3\S3\CacheControlPolicy;
use Koh\FalS3\S3\ClientFactory;
use Koh\FalS3\S3\ContentTypeResolver;
use Koh\FalS3\S3\ObjectStore;
use Koh\FalS3\Security\MalwareScanGate;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\CacheManager;
use TYPO3\CMS\Core\Cache\Exception\NoSuchCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Log\LogManager;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * TYPO3 instantiates drivers with makeInstance($class, $configuration), so
 * nothing can be injected into the driver itself. This factory is the one
 * object the driver fetches from the container (public service, so
 * makeInstance returns the shared instance and the S3 client memo survives
 * across storages and calls).
 */
class DriverRuntimeFactory
{
    public const CACHE_IDENTIFIER = 'koh_fal_s3_meta';

    private readonly ClientFactory $clientFactory;

    public function __construct(?ClientFactory $clientFactory = null)
    {
        $this->clientFactory = $clientFactory ?? new ClientFactory();
    }

    public function create(DriverConfiguration $configuration, int $storageUid): DriverRuntime
    {
        $logger = $this->logger();
        $counter = new RequestCounter($this->metricsLogger(), $configuration->bucket);
        $store = new ObjectStore($this->clientFactory->create($configuration, $logger), $configuration->bucket, $counter, $logger);
        $cache = new MetadataCache($this->cacheFrontend($logger), $configuration->bucket, $configuration->cacheLifetime, $configuration->negativeCacheLifetime, $counter);
        $mapper = new IdentifierMapper($configuration->keyPrefix, $configuration->excludedPrefixes);
        $policy = new CacheControlPolicy($configuration->cacheControlDefault, $configuration->cacheControlProcessed);
        $types = new ContentTypeResolver();

        return new DriverRuntime(
            configuration: $configuration,
            mapper: $mapper,
            store: $store,
            cache: $cache,
            listing: new DirectoryListing($store, $cache, $mapper),
            temporaryFiles: new TemporaryFiles($store),
            folders: new FolderOperation($store, $cache, $mapper, $policy, $types, $configuration->maxFolderOperationObjects, $logger),
            cacheControl: $policy,
            contentTypes: $types,
            malwareGate: new MalwareScanGate($store, $cache, $configuration->malwareGate),
            counter: $counter,
            logger: $logger,
        );
    }

    protected function cacheFrontend(LoggerInterface $logger): FrontendInterface
    {
        try {
            return GeneralUtility::makeInstance(CacheManager::class)->getCache(self::CACHE_IDENTIFIER);
        } catch (NoSuchCacheException) {
            $logger->warning('Cache "koh_fal_s3_meta" is not configured; falling back to a per-request memory cache, so every request asks S3 again.');
            $backend = new TransientMemoryBackend();
            $frontend = new VariableFrontend(self::CACHE_IDENTIFIER, $backend);
            $backend->setCache($frontend);
            return $frontend;
        }
    }

    protected function logger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(LogManager::class)->getLogger(S3Driver::class);
    }

    protected function metricsLogger(): LoggerInterface
    {
        return GeneralUtility::makeInstance(LogManager::class)->getLogger('Koh.FalS3.Metrics');
    }
}
