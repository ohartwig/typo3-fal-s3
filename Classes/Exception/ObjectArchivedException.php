<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Exception;

/**
 * The object sits in an archive tier (Intelligent-Tiering ARCHIVE_ACCESS or
 * DEEP_ARCHIVE_ACCESS, or Glacier) and has to be restored before S3 serves it.
 */
final class ObjectArchivedException extends S3OperationFailedException
{
    public function __construct(string $key, public readonly ?string $storageClass, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf(
                'Object "%s" is archived (%s); run "vendor/bin/typo3 koh_fal_s3:restore" for it or remove the archive tiers from the bucket\'s Intelligent-Tiering configuration.',
                $key,
                $storageClass ?? 'unknown storage class'
            ),
            1760400001,
            'GetObject',
            $key,
            403,
            null,
            $previous,
        );
    }
}
