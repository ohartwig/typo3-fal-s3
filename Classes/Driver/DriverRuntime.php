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
use Koh\FalS3\S3\ContentTypeResolver;
use Koh\FalS3\S3\ObjectStore;
use Koh\FalS3\Security\MalwareScanGate;
use Psr\Log\LoggerInterface;

/**
 * Everything the driver delegates to, built once per storage in initialize().
 */
final readonly class DriverRuntime
{
    public function __construct(
        public DriverConfiguration $configuration,
        public IdentifierMapper $mapper,
        public ObjectStore $store,
        public MetadataCache $cache,
        public DirectoryListing $listing,
        public TemporaryFiles $temporaryFiles,
        public FolderOperation $folders,
        public CacheControlPolicy $cacheControl,
        public ContentTypeResolver $contentTypes,
        public MalwareScanGate $malwareGate,
        public RequestCounter $counter,
        public LoggerInterface $logger,
    ) {}
}
