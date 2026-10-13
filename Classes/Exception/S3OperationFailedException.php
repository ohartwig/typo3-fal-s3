<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Exception;

use TYPO3\CMS\Core\Resource\Exception\FileOperationErrorException;

/**
 * An S3 request failed for a reason that is not "the object is not there".
 *
 * Carries the operation, the key and the HTTP status so the log line and the
 * flash message say which call failed, not just that one did.
 */
class S3OperationFailedException extends FileOperationErrorException
{
    public function __construct(
        string $message,
        int $code,
        public readonly string $operation,
        public readonly string $key,
        public readonly ?int $statusCode = null,
        public readonly ?string $requestId = null,
        ?\Throwable $previous = null,
    ) {
        parent::__construct($message, $code, $previous);
    }
}
