<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Configuration;

/**
 * Everything the driver needs to know about one storage, resolved once in
 * processConfiguration() from environment, TYPO3_CONF_VARS and the FlexForm.
 * No credentials: those come from the AWS default chain (web identity in the
 * cluster, static keys only for a local MinIO).
 */
final readonly class DriverConfiguration
{
    /**
     * @param list<string> $excludedPrefixes
     */
    public function __construct(
        public string $bucket,
        public string $region,
        public ?string $endpoint,
        public bool $pathStyle,
        public bool $dualstack,
        public string $keyPrefix,
        public string $publicBaseUri,
        public array $excludedPrefixes,
        public string $cacheControlDefault,
        public string $cacheControlProcessed,
        public bool $conditionalWrites,
        public string $streamMode,
        public int $presignLifetime,
        public bool $malwareGate,
        public int $maxFolderOperationObjects,
        public int $negativeCacheLifetime,
        public int $cacheLifetime,
        public bool $caseSensitive,
    ) {}
}
