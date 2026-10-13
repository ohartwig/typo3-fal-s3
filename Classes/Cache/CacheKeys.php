<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Cache;

/**
 * Every cache identifier and tag is minted here so the invalidation rules in
 * MetadataCache and the readers agree on the spelling. Identifiers are hashed:
 * keys contain characters the caching framework does not accept.
 */
final class CacheKeys
{
    public static function object(string $bucket, string $key): string
    {
        return 'obj_' . sha1($bucket . '|' . $key);
    }

    public static function listing(string $bucket, string $prefix, bool $recursive): string
    {
        return 'list_' . sha1($bucket . '|' . $prefix) . ($recursive ? '_1' : '_0');
    }

    public static function folderExists(string $bucket, string $prefix): string
    {
        return 'fex_' . sha1($bucket . '|' . $prefix);
    }

    public static function hash(string $bucket, string $key, string $etag, string $algorithm): string
    {
        $algorithm = (string)preg_replace('/[^a-z0-9]/', '', strtolower($algorithm));
        return 'hash_' . $algorithm . '_' . sha1($bucket . '|' . $key . '|' . $etag);
    }

    public static function scan(string $bucket, string $key, string $etag): string
    {
        return 'scan_' . sha1($bucket . '|' . $key . '|' . $etag);
    }

    public static function folderTag(string $bucket, string $prefix): string
    {
        return 'folder_' . sha1($bucket . '|' . $prefix);
    }

    public static function recursiveTag(string $bucket, string $prefix): string
    {
        return 'rlist_' . sha1($bucket . '|' . $prefix);
    }
}
