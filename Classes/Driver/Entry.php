<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

/**
 * One line of a listing: a file or an emulated folder.
 */
final readonly class Entry
{
    public const TYPE_FILE = 'file';
    public const TYPE_DIR = 'dir';

    /**
     * @param non-empty-string $identifier
     */
    public function __construct(
        public string $type,
        public string $identifier,
        public string $name,
        public string $key,
        public int $size,
        public int $mtime,
        public ?string $etag,
        public ?string $storageClass,
    ) {}

    public function isFile(): bool
    {
        return $this->type === self::TYPE_FILE;
    }
}
