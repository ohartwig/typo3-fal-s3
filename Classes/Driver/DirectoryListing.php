<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Driver;

use Koh\FalS3\Cache\MetadataCache;
use Koh\FalS3\S3\ObjectMetadata;
use Koh\FalS3\S3\ObjectStore;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;

/**
 * Turns ListObjectsV2 into FAL listings with LocalDriver's exact filter, sort
 * and paging semantics, and seeds the object cache from what the listing
 * already told us so the indexer's per-file getFileInfoByIdentifier() costs
 * nothing.
 */
final class DirectoryListing
{
    public function __construct(
        private readonly ObjectStore $store,
        private readonly MetadataCache $cache,
        private readonly IdentifierMapper $mapper,
    ) {}

    /**
     * @return array{files: list<Entry>, folders: list<Entry>}
     */
    public function listFolder(string $folderIdentifier, bool $recursive): array
    {
        $prefix = $this->mapper->folderPrefix($folderIdentifier);
        $cached = $this->cache->listing($prefix, $recursive);
        if ($cached !== null) {
            return $cached;
        }

        $raw = $this->store->list($prefix, $recursive);
        $files = [];
        $folders = [];
        $folderKeys = [];
        $markerMtimes = [];
        $seed = [];

        foreach ($raw['objects'] as $object) {
            $key = $object['key'];
            if ($key === $prefix || $this->mapper->isExcluded($key)) {
                continue;
            }
            if (KeyPaths::isFolderKey($key)) {
                $folderKeys[$key] = true;
                $markerMtimes[$key] = $object['mtime'];
                continue;
            }
            if ($recursive) {
                // Every intermediate prefix between the listed folder and the key is a folder.
                $parent = KeyPaths::parent($key);
                while ($parent !== $prefix && strlen($parent) > strlen($prefix)) {
                    $folderKeys[$parent] = true;
                    $parent = KeyPaths::parent($parent);
                }
            }
            $files[] = new Entry(
                Entry::TYPE_FILE,
                $this->mapper->identifierFromKey($key),
                KeyPaths::name($key),
                $key,
                $object['size'],
                $object['mtime'],
                $object['etag'],
                $object['storageClass'],
            );
            $seed[$key] = new ObjectMetadata(true, $object['size'], $object['mtime'], $object['etag'], null, $object['storageClass'], false);
        }
        foreach ($raw['prefixes'] as $commonPrefix) {
            if (!$this->mapper->isExcluded($commonPrefix)) {
                $folderKeys[$commonPrefix] = true;
            }
        }
        foreach (array_keys($folderKeys) as $folderKey) {
            if ($this->mapper->isExcluded($folderKey)) {
                continue;
            }
            $folders[] = new Entry(
                Entry::TYPE_DIR,
                $this->mapper->identifierFromKey($folderKey),
                KeyPaths::name($folderKey),
                $folderKey,
                0,
                $markerMtimes[$folderKey] ?? 0,
                null,
                null,
            );
        }

        $listing = ['files' => $files, 'folders' => $folders];
        $this->cache->rememberObjects($seed);
        $this->cache->rememberListing($prefix, $recursive, $listing);
        return $listing;
    }

    /**
     * @param list<Entry> $entries
     * @param array<int, callable|array{0: class-string|object, 1: string}> $filters
     * @return list<non-empty-string>
     */
    public function apply(array $entries, array $filters, string $sort, bool $sortRev, int $start, int $numberOfItems, DriverInterface $driver): array
    {
        $result = [];
        foreach ($this->sort($entries, $sort, $sortRev) as $entry) {
            if (!$this->passesFilters($entry, $filters, $driver)) {
                continue;
            }
            if ($start > 0) {
                $start--;
                continue;
            }
            $result[] = $entry->identifier;
            if ($numberOfItems > 0 && count($result) >= $numberOfItems) {
                break;
            }
        }
        return $result;
    }

    /**
     * @param list<Entry> $entries
     * @param array<int, callable|array{0: class-string|object, 1: string}> $filters
     */
    /**
     * @param list<Entry> $entries
     * @param array<int, callable|array{0: class-string|object, 1: string}> $filters
     * @return int<0, max>
     */
    public function count(array $entries, array $filters, DriverInterface $driver): int
    {
        $passing = [];
        foreach ($entries as $entry) {
            if ($this->passesFilters($entry, $filters, $driver)) {
                $passing[] = $entry;
            }
        }
        return count($passing);
    }

    /**
     * LocalDriver::sortDirectoryEntries(): a sort key per entry with a
     * collision counter, strnatcasecmp, reversed by multiplier.
     *
     * @param list<Entry> $entries
     * @return list<Entry>
     */
    private function sort(array $entries, string $sort, bool $sortRev): array
    {
        if (count($entries) < 2) {
            return $entries;
        }
        $keyed = [];
        foreach ($entries as $entry) {
            $sortingKey = match ($sort) {
                'size' => ($entry->isFile() ? (string)$entry->size : '0') . 's',
                'rw' => 'R' . ($this->mapper->isExcluded($entry->identifier) ? '' : 'W'),
                'fileext' => pathinfo($entry->name, PATHINFO_EXTENSION),
                'tstamp' => $entry->mtime . 't',
                'crdate' => $entry->mtime . 'c',
                default => $entry->name,
            };
            $i = 0;
            while (isset($keyed[$sortingKey . $i])) {
                $i++;
            }
            $keyed[$sortingKey . $i] = $entry;
        }
        $multiplier = $sortRev ? -1 : 1;
        uksort($keyed, static fn(string $a, string $b): int => strnatcasecmp($a, $b) * $multiplier);
        return array_values($keyed);
    }

    /**
     * @param array<int, callable|array{0: class-string|object, 1: string}> $filters
     */
    private function passesFilters(Entry $entry, array $filters, DriverInterface $driver): bool
    {
        if ($filters === []) {
            return true;
        }
        $parentIdentifier = '/' . KeyPaths::parent(ltrim($entry->identifier, '/'));
        foreach ($filters as $filter) {
            if (!is_callable($filter)) {
                continue;
            }
            $result = $filter($entry->name, $entry->identifier, $parentIdentifier, [], $driver);
            if ($result === -1) {
                return false;
            }
            if ($result === false) {
                $name = is_array($filter) ? (is_object($filter[0]) ? $filter[0]::class : $filter[0]) . '::' . $filter[1] : 'closure';
                throw new \RuntimeException('Could not apply file/folder name filter ' . $name, 1476046425);
            }
        }
        return true;
    }
}
