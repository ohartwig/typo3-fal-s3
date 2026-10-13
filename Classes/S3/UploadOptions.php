<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\S3;

final readonly class UploadOptions
{
    public function __construct(
        public string $contentType,
        public string $cacheControl,
        public ?string $ifNoneMatch = null,
    ) {}
}
