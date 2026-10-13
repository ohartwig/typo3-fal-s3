<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\S3;

use TYPO3\CMS\Core\Resource\MimeTypeDetector;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\PathUtility;

/**
 * Content-Type for a PUT: the extension decides (TYPO3's own table), finfo on
 * the local file is the fallback, octet-stream the last resort.
 */
final class ContentTypeResolver
{
    public const DIRECTORY = 'application/x-directory';
    public const FALLBACK = 'application/octet-stream';

    private ?MimeTypeDetector $detector = null;

    public function forFile(string $fileNameOrIdentifier, ?string $localPath = null): string
    {
        $extension = strtolower(PathUtility::pathinfo($fileNameOrIdentifier, PATHINFO_EXTENSION));
        if ($extension !== '') {
            $types = $this->detector()->getMimeTypesForFileExtension($extension);
            if ($types !== []) {
                return $types[0];
            }
        }
        if ($localPath !== null && is_file($localPath) && function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo !== false) {
                $detected = finfo_file($finfo, $localPath);
                finfo_close($finfo);
                if (is_string($detected) && $detected !== '') {
                    return $detected;
                }
            }
        }
        return self::FALLBACK;
    }

    private function detector(): MimeTypeDetector
    {
        return $this->detector ??= GeneralUtility::makeInstance(MimeTypeDetector::class);
    }
}
