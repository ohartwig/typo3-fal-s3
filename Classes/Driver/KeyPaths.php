<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

/**
 * Pure string arithmetic on S3 keys: a "folder" is a key prefix ending in "/",
 * the root is the empty prefix.
 */
final class KeyPaths
{
    /**
     * The prefix of the folder that contains the key ('' for the root). For a
     * folder marker ("a/b/") this is the parent folder ("a/").
     */
    public static function parent(string $key): string
    {
        $trimmed = rtrim($key, '/');
        $slash = strrpos($trimmed, '/');
        return $slash === false ? '' : substr($trimmed, 0, $slash + 1);
    }

    /**
     * The prefix itself and every folder above it, root ('') last.
     *
     * @return list<string>
     */
    public static function ancestors(string $prefix): array
    {
        $ancestors = [];
        $current = $prefix;
        while (true) {
            $ancestors[] = $current;
            if ($current === '') {
                break;
            }
            $current = self::parent($current);
        }
        return $ancestors;
    }

    public static function name(string $key): string
    {
        $trimmed = rtrim($key, '/');
        $slash = strrpos($trimmed, '/');
        return $slash === false ? $trimmed : substr($trimmed, $slash + 1);
    }

    public static function isFolderKey(string $key): bool
    {
        return $key !== '' && str_ends_with($key, '/');
    }
}
