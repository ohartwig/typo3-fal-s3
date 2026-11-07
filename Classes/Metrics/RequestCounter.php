<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Metrics;

use Koh\FalS3\Runtime\EndOfRequest;
use Koh\FalS3\Runtime\Finishable;
use Psr\Log\LoggerInterface;

/**
 * Counts S3 requests per operation and cache hits/misses for one request
 * lifecycle, and writes a single info line when that lifecycle ends (see
 * EndOfRequest). That line is what the cost comparison with the FUSE mount is
 * made from, so it is deliberately machine-readable and emitted only when at
 * least one request went out.
 *
 * For the read operations a page render can trigger (GetObject, HeadObject)
 * the line also names the objects, so a slow render can be traced to the
 * files it fetched. At most KEY_LIMIT objects per operation are listed;
 * `keys_dropped` counts the requests for objects beyond that.
 */
final class RequestCounter implements Finishable
{
    public const KEY_LIMIT = 25;
    private const KEYED_OPERATIONS = ['GetObject', 'HeadObject'];

    /** @var array<string, int> */
    private array $requests = [];
    /** @var array<string, array<string, int>> */
    private array $keys = [];
    private int $keysDropped = 0;
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private bool $registered = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $bucket,
    ) {}

    public function request(string $operation, ?string $key = null): void
    {
        $this->requests[$operation] = ($this->requests[$operation] ?? 0) + 1;
        if ($key !== null && in_array($operation, self::KEYED_OPERATIONS, true)) {
            $this->countKey($operation, $key);
        }
        $this->register();
    }

    public function cacheHit(): void
    {
        $this->cacheHits++;
    }

    public function cacheMiss(): void
    {
        $this->cacheMisses++;
    }

    /**
     * @return array{bucket: string, requests: array<string, int>, total: int, cache_hits: int, cache_misses: int, keys: array<string, array<string, int>>, keys_dropped: int}
     */
    public function snapshot(): array
    {
        return [
            'bucket' => $this->bucket,
            'requests' => $this->requests,
            'total' => array_sum($this->requests),
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
            'keys' => $this->keys,
            'keys_dropped' => $this->keysDropped,
        ];
    }

    public function flush(): void
    {
        if ($this->requests === []) {
            return;
        }
        $this->logger->info('S3 requests for this request', $this->snapshot());
        $this->requests = [];
        $this->keys = [];
        $this->keysDropped = 0;
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
    }

    public function finish(): void
    {
        $this->flush();
    }

    public function __destruct()
    {
        $this->flush();
    }

    private function countKey(string $operation, string $key): void
    {
        if (isset($this->keys[$operation][$key])) {
            $this->keys[$operation][$key]++;
            return;
        }
        if (count($this->keys[$operation] ?? []) >= self::KEY_LIMIT) {
            $this->keysDropped++;
            return;
        }
        $this->keys[$operation][$key] = 1;
    }

    private function register(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        EndOfRequest::register($this);
    }
}
