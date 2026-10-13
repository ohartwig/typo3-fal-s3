<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\S3;

/**
 * Cache-Control is written as object metadata so any delivery path that
 * forwards S3 headers (a presigned URL, a proxy that passes them through)
 * serves processed variants as immutable and originals with a shorter TTL.
 */
final readonly class CacheControlPolicy
{
    public const PROCESSED_PREFIX = '_processed_/';

    public function __construct(
        private string $default,
        private string $processed,
    ) {}

    /**
     * @param string $relativeKey the key without the storage's keyPrefix
     */
    public function for(string $relativeKey): string
    {
        if (str_ends_with($relativeKey, '/')) {
            return 'no-store';
        }
        return str_starts_with($relativeKey, self::PROCESSED_PREFIX) ? $this->processed : $this->default;
    }

    public static function isProcessed(string $relativeKey): bool
    {
        return str_starts_with($relativeKey, self::PROCESSED_PREFIX);
    }
}
