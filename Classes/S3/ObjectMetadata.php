<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\S3;

/**
 * What one HEAD (or a listing entry, or a write response) told us about an object.
 */
final readonly class ObjectMetadata
{
    public function __construct(
        public bool $exists,
        public int $size,
        public int $mtime,
        public ?string $etag,
        public ?string $contentType,
        public ?string $storageClass,
        public bool $archived,
    ) {}

    public static function missing(): self
    {
        return new self(false, 0, 0, null, null, null, false);
    }

    /**
     * The ETag of a single-part PUT is the MD5 of the body. A multipart ETag
     * carries a "-<parts>" suffix and is not.
     */
    public function md5FromEtag(): ?string
    {
        if ($this->etag === null) {
            return null;
        }
        $etag = trim($this->etag, '"');
        if ($etag === '' || str_contains($etag, '-') || !preg_match('/^[0-9a-f]{32}$/i', $etag)) {
            return null;
        }
        return strtolower($etag);
    }

    public function etagKey(): string
    {
        return trim((string)$this->etag, '"');
    }
}
