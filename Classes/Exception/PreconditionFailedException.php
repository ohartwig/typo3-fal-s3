<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Exception;

/**
 * A conditional write (If-None-Match: *) found the object already present.
 * Internal to the driver: the write paths decide whether that is a lost race
 * on a processed file (fine) or a real collision (an exception for the user).
 */
final class PreconditionFailedException extends S3OperationFailedException
{
    public function __construct(string $operation, string $key, ?\Throwable $previous = null)
    {
        parent::__construct(
            sprintf('Object "%s" already exists (precondition failed on %s).', $key, $operation),
            1760400002,
            $operation,
            $key,
            412,
            null,
            $previous,
        );
    }
}
