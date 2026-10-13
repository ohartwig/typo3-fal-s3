<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Exception;

use TYPO3\CMS\Core\Resource\Exception\InsufficientFileReadPermissionsException;

/**
 * GuardDuty Malware Protection tagged the object THREATS_FOUND; the driver
 * refuses to hand it out.
 */
final class ThreatsFoundException extends InsufficientFileReadPermissionsException
{
    public function __construct(string $identifier)
    {
        parent::__construct(
            sprintf('File "%s" was flagged by malware scanning and is not delivered.', $identifier),
            1760400004,
        );
    }
}
