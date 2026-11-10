<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Cache;

use Koh\FalS3\Driver\Entry;
use Koh\FalS3\Driver\KeyPaths;
use Koh\FalS3\Metrics\RequestCounter;
use Koh\FalS3\Runtime\EndOfRequest;
use Koh\FalS3\Runtime\Finishable;
use Koh\FalS3\S3\ObjectMetadata;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * Two layers: a request-local memo (always) and the shared `koh_fal_s3_meta`
 * cache. Every replica writes through the shared layer, so invalidation is
 * cache-based, never in-process. The memo is dropped wholesale on any write;
 * within one request a re-read then costs a cache lookup, not an S3 call.
 *
 * Entries and tags:
 *
 *   obj_<key>            HEAD result            tag folder_<parent>
 *   list_<prefix>_0      non-recursive listing  tag folder_<prefix>
 *   list_<prefix>_1      recursive listing      tag rlist_<prefix>
 *   fex_<prefix>         folder exists?         tags rlist_<prefix>, folder_<parent(prefix)>
 *   hash_<algo>_<key+etag>  content hash        no tag, no expiry (etag-scoped)
 *   scan_<key+etag>      GuardDuty tag value    no tag, short expiry for non-final states
 *
 * invalidateWrite(key): drop obj_<key>, flush folder_<parent(key)>, flush
 * rlist_<A> for every ancestor A of parent(key) including the root.
 *
 * The memo ends with the HTTP request (see EndOfRequest), also in a worker
 * server that keeps this object across requests: a memo that outlived its
 * request would miss other replicas' invalidations and grow without bound.
 *
 * Every lookup counts as hit or miss on the RequestCounter, and the time spent
 * in the shared cache is added to its cache_ms.
 */
final class MetadataCache implements Finishable
{
    /** @var array<string, mixed> */
    private array $memo = [];

    public function __construct(
        private readonly FrontendInterface $cache,
        private readonly string $bucket,
        private readonly int $lifetime,
        private readonly int $negativeLifetime,
        private readonly ?RequestCounter $counter = null,
    ) {
        EndOfRequest::register($this);
    }

    public function object(string $key): ?ObjectMetadata
    {
        $value = $this->read(CacheKeys::object($this->bucket, $key));
        return $value instanceof ObjectMetadata ? $value : null;
    }

    public function rememberObject(string $key, ObjectMetadata $metadata): void
    {
        $this->write(
            CacheKeys::object($this->bucket, $key),
            $metadata,
            [CacheKeys::folderTag($this->bucket, KeyPaths::parent($key))],
            $metadata->exists ? $this->lifetime : $this->negativeLifetime,
        );
    }

    /**
     * @param array<string, ObjectMetadata> $byKey
     */
    public function rememberObjects(array $byKey): void
    {
        foreach ($byKey as $key => $metadata) {
            $this->rememberObject($key, $metadata);
        }
    }

    public function forgetObject(string $key): void
    {
        $id = CacheKeys::object($this->bucket, $key);
        unset($this->memo[$id]);
        $started = $this->counter?->startTimer();
        $this->cache->remove($id);
        $this->counter?->cacheDone($started);
    }

    /**
     * @return array{files: list<Entry>, folders: list<Entry>}|null
     */
    public function listing(string $prefix, bool $recursive): ?array
    {
        $value = $this->read(CacheKeys::listing($this->bucket, $prefix, $recursive));
        if (!is_array($value) || !isset($value['files'], $value['folders']) || !is_array($value['files']) || !is_array($value['folders'])) {
            return null;
        }
        /** @var array{files: list<Entry>, folders: list<Entry>} $value */
        return $value;
    }

    /**
     * @param array{files: list<Entry>, folders: list<Entry>} $listing
     */
    public function rememberListing(string $prefix, bool $recursive, array $listing): void
    {
        $tag = $recursive
            ? CacheKeys::recursiveTag($this->bucket, $prefix)
            : CacheKeys::folderTag($this->bucket, $prefix);
        $this->write(CacheKeys::listing($this->bucket, $prefix, $recursive), $listing, [$tag], $this->lifetime);
    }

    public function folderExists(string $prefix): ?bool
    {
        $value = $this->read(CacheKeys::folderExists($this->bucket, $prefix));
        return is_bool($value) ? $value : null;
    }

    public function rememberFolderExists(string $prefix, bool $exists): void
    {
        $this->write(
            CacheKeys::folderExists($this->bucket, $prefix),
            $exists,
            [
                CacheKeys::recursiveTag($this->bucket, $prefix),
                CacheKeys::folderTag($this->bucket, KeyPaths::parent($prefix)),
            ],
            $exists ? $this->lifetime : $this->negativeLifetime,
        );
    }

    public function hash(string $key, string $etag, string $algorithm): ?string
    {
        if ($etag === '') {
            return null;
        }
        $value = $this->read(CacheKeys::hash($this->bucket, $key, $etag, $algorithm));
        return is_string($value) ? $value : null;
    }

    public function rememberHash(string $key, string $etag, string $algorithm, string $hash): void
    {
        if ($etag === '') {
            return;
        }
        $this->write(CacheKeys::hash($this->bucket, $key, $etag, $algorithm), $hash, [], 0);
    }

    public function scanStatus(string $key, string $etag): ?string
    {
        $value = $this->read(CacheKeys::scan($this->bucket, $key, $etag));
        return is_string($value) ? $value : null;
    }

    public function rememberScanStatus(string $key, string $etag, string $status, int $lifetime): void
    {
        $this->write(CacheKeys::scan($this->bucket, $key, $etag), $status, [], $lifetime);
    }

    /**
     * A file or marker at $key was created, replaced, copied to, or deleted.
     */
    public function invalidateWrite(string $key): void
    {
        $this->memo = [];
        $started = $this->counter?->startTimer();
        $this->cache->remove(CacheKeys::object($this->bucket, $key));
        $parent = KeyPaths::parent($key);
        $tags = [CacheKeys::folderTag($this->bucket, $parent)];
        foreach (KeyPaths::ancestors($parent) as $ancestor) {
            $tags[] = CacheKeys::recursiveTag($this->bucket, $ancestor);
        }
        $this->cache->flushByTags(array_values(array_unique($tags)));
        $this->counter?->cacheDone($started);
    }

    /**
     * Everything under $prefix changed (folder copied, moved or deleted).
     * Objects inside are forgotten individually by the caller, which knows the keys.
     */
    public function invalidateFolderTree(string $prefix): void
    {
        $this->memo = [];
        $tags = [
            CacheKeys::folderTag($this->bucket, $prefix),
            CacheKeys::folderTag($this->bucket, KeyPaths::parent($prefix)),
        ];
        foreach (KeyPaths::ancestors($prefix) as $ancestor) {
            $tags[] = CacheKeys::recursiveTag($this->bucket, $ancestor);
        }
        $started = $this->counter?->startTimer();
        $this->cache->remove(CacheKeys::folderExists($this->bucket, $prefix));
        $this->cache->flushByTags(array_values(array_unique($tags)));
        $this->counter?->cacheDone($started);
    }

    public function forgetMemo(): void
    {
        $this->memo = [];
    }

    public function finish(): void
    {
        $this->forgetMemo();
    }

    private function read(string $id): mixed
    {
        if (array_key_exists($id, $this->memo)) {
            $this->counter?->cacheHit(true);
            return $this->memo[$id];
        }
        $started = $this->counter?->startTimer();
        $value = $this->cache->get($id);
        $this->counter?->cacheDone($started);
        if ($value === false) {
            $this->counter?->cacheMiss();
            return null;
        }
        $this->counter?->cacheHit();
        return $this->memo[$id] = $value;
    }

    /**
     * @param list<string> $tags
     */
    private function write(string $id, mixed $value, array $tags, int $lifetime): void
    {
        $this->memo[$id] = $value;
        $started = $this->counter?->startTimer();
        $this->cache->set($id, $value, $tags, $lifetime === 0 ? null : $lifetime);
        $this->counter?->cacheDone($started);
    }
}
