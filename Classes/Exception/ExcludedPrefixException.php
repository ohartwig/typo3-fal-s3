<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Exception;

use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;

/**
 * The identifier points into a prefix the storage is configured to hide:
 * other consumers share the bucket (typo3temp assets, a POS exchange folder,
 * the GuardDuty probe object) and FAL must neither list nor write there.
 */
final class ExcludedPrefixException extends InvalidPathException
{
    public function __construct(string $identifier)
    {
        parent::__construct(
            sprintf('Path "%s" is excluded from this storage; another system owns that prefix of the bucket.', $identifier),
            1760400003,
        );
    }
}
