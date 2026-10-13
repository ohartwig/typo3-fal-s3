<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

use Koh\FalS3\Exception\ExcludedPrefixException;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;

/**
 * FAL identifier <-> S3 key. Identifiers arrive canonicalised by the driver
 * ("/user_upload/x.jpg", folders with a trailing slash, root "/"); keys are
 * the identifier without the leading slash, behind an optional keyPrefix.
 *
 * Also the one place that knows which prefixes are not ours.
 */
final class IdentifierMapper
{
    /** @var list<string> */
    private readonly array $excludedPrefixes;

    /**
     * @param list<string> $excludedPrefixes
     */
    public function __construct(
        private readonly string $keyPrefix,
        array $excludedPrefixes,
    ) {
        $this->excludedPrefixes = array_values(array_filter(
            array_map(static fn(string $prefix): string => ltrim(trim($prefix), '/'), $excludedPrefixes),
            static fn(string $prefix): bool => $prefix !== '',
        ));
    }

    public function keyPrefix(): string
    {
        return $this->keyPrefix;
    }

    public function fileKey(string $identifier): string
    {
        $this->assertSafe($identifier);
        if ($identifier === '/' || str_ends_with($identifier, '/')) {
            throw new InvalidPathException(sprintf('"%s" is a folder identifier, a file identifier was expected.', $identifier), 1760400040);
        }
        return $this->keyPrefix . ltrim($identifier, '/');
    }

    public function folderPrefix(string $folderIdentifier): string
    {
        $this->assertSafe($folderIdentifier);
        $relative = trim($folderIdentifier, '/');
        return $relative === '' ? $this->keyPrefix : $this->keyPrefix . $relative . '/';
    }

    /**
     * Folder keys keep their trailing slash so the result is a folder identifier.
     *
     * @return non-empty-string
     */
    public function identifierFromKey(string $key): string
    {
        return '/' . $this->relativeKey($key);
    }

    public function relativeKey(string $key): string
    {
        if ($this->keyPrefix !== '') {
            if (!str_starts_with($key, $this->keyPrefix)) {
                throw new InvalidPathException(sprintf('Key "%s" lies outside the configured prefix "%s".', $key, $this->keyPrefix), 1760400041);
            }
            return substr($key, strlen($this->keyPrefix));
        }
        return $key;
    }

    /**
     * @param string $identifierOrKey a canonical identifier (leading slash) or a key
     */
    public function isExcluded(string $identifierOrKey): bool
    {
        $relative = str_starts_with($identifierOrKey, '/')
            ? ltrim($identifierOrKey, '/')
            : ($this->keyPrefix !== '' && str_starts_with($identifierOrKey, $this->keyPrefix)
                ? substr($identifierOrKey, strlen($this->keyPrefix))
                : $identifierOrKey);
        foreach ($this->excludedPrefixes as $excluded) {
            if (str_ends_with($excluded, '/')) {
                if (str_starts_with($relative, $excluded) || rtrim($relative, '/') === rtrim($excluded, '/')) {
                    return true;
                }
            } elseif ($relative === $excluded || rtrim($relative, '/') === $excluded || str_starts_with($relative, $excluded . '/')) {
                return true;
            }
        }
        return false;
    }

    public function assertNotExcluded(string $identifier): void
    {
        if ($this->isExcluded($identifier)) {
            throw new ExcludedPrefixException($identifier);
        }
    }

    private function assertSafe(string $identifier): void
    {
        if ($identifier === '' || !str_starts_with($identifier, '/')) {
            throw new InvalidPathException(sprintf('Identifier "%s" must start with a slash.', $identifier), 1760400042);
        }
        if (str_contains($identifier, '\\') || preg_match('/[\x00-\x1F\x7F]/', $identifier) === 1) {
            throw new InvalidPathException(sprintf('Identifier "%s" contains characters that are not allowed.', $identifier), 1760400043);
        }
        foreach (explode('/', trim($identifier, '/')) as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidPathException(sprintf('Identifier "%s" contains a relative segment.', $identifier), 1760400044);
            }
            if ($segment === '' && $identifier !== '/') {
                throw new InvalidPathException(sprintf('Identifier "%s" contains an empty segment.', $identifier), 1760400045);
            }
        }
    }
}
