<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

use Koh\FalS3\S3\ObjectStore;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Local copies for getFileForLocalProcessing(). LocalImageProcessor asks for
 * the original of every variant it renders, so one download per
 * (key, etag) per request is shared between read-only callers; a writable
 * caller gets its own copy because it may hand the path to addFile() with
 * removeOriginal. Everything is unlinked at shutdown.
 */
final class TemporaryFiles
{
    /** @var array<string, non-empty-string> */
    private array $shared = [];
    /** @var list<string> */
    private array $created = [];
    private bool $registered = false;

    public function __construct(
        private readonly ObjectStore $store,
    ) {}

    /**
     * @return non-empty-string
     */
    public function materialise(string $key, ?string $etag, string $extension, bool $writable): string
    {
        $shareKey = $key . '|' . ($etag ?? '');
        $sharedPath = $this->shared[$shareKey] ?? null;
        if ($sharedPath === null || !is_file($sharedPath)) {
            $sharedPath = $this->newPath($extension);
            $this->store->downloadTo($key, $sharedPath);
            $this->shared[$shareKey] = $sharedPath;
        }
        if (!$writable) {
            return $sharedPath;
        }
        $copy = $this->newPath($extension);
        if (!copy($sharedPath, $copy)) {
            throw new \RuntimeException(sprintf('Cannot copy temporary file "%s".', $sharedPath), 1760400050);
        }
        return $copy;
    }

    public function cleanup(): void
    {
        foreach ($this->created as $path) {
            if (is_file($path)) {
                @unlink($path);
            }
        }
        $this->created = [];
        $this->shared = [];
    }

    /**
     * @return non-empty-string
     */
    private function newPath(string $extension): string
    {
        $path = GeneralUtility::tempnam('fal-s3-', $extension === '' ? '' : '.' . $extension);
        $this->created[] = $path;
        if (!$this->registered) {
            $this->registered = true;
            register_shutdown_function($this->cleanup(...));
        }
        return $path;
    }
}
