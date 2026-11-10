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
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Log\Logger;

/**
 * Counts and times S3 requests per operation and metadata-cache lookups for
 * one HTTP request, and writes a single info line when that request ends.
 * That line is what the cost comparison with the FUSE mount and the
 * attribution of render time are made from, so it is deliberately
 * machine-readable.
 *
 * The request ends when FinishRequestMiddleware returns (frontend and
 * backend, also in a worker server that keeps the driver objects across
 * requests); outside the middleware (CLI) when the counter is destructed or
 * the process shuts down (see EndOfRequest). A line is written when anything
 * was counted: an S3 request, a cache hit or a cache miss.
 *
 * Message: "S3 requests for this request". Context fields:
 *
 *   bucket              bucket name
 *   host, path          the HTTP request, path without query string; empty
 *                       outside an HTTP request (CLI)
 *   requests            S3 requests per operation
 *   total               sum of requests
 *   s3_ms               wall-clock milliseconds spent in S3 calls, from
 *                       sending the request until the response is resolved;
 *                       for downloads to a local file including the body
 *   s3_ms_by_operation  the same per operation
 *   cache_hits          metadata lookups answered without S3, memo included
 *   memo_hits           the part of cache_hits answered from the in-process
 *                       memo, i.e. without asking the shared cache
 *   cache_misses        lookups that found nothing (the caller then asks S3)
 *   cache_ms            wall-clock milliseconds spent in the shared metadata
 *                       cache (get, set, remove, flushByTags); memo hits are
 *                       not timed
 *   keys                for GetObject and HeadObject the object keys, at most
 *                       KEY_LIMIT per operation
 *   keys_dropped        requests for objects beyond that limit
 *
 * host, path, s3_ms, s3_ms_by_operation, memo_hits and cache_ms were added
 * after 1.0.16, as was the line for requests without any S3 call; the
 * message and the other fields are unchanged.
 *
 * Nothing is timed or logged when the logger has no writer for INFO, which is
 * the default: the counter then costs a few array increments per lookup.
 */
final class RequestCounter implements Finishable
{
    public const MESSAGE = 'S3 requests for this request';
    public const KEY_LIMIT = 25;
    private const KEYED_OPERATIONS = ['GetObject', 'HeadObject'];

    /** @var array<string, int> */
    private array $requests = [];
    /** @var array<string, int> nanoseconds per operation */
    private array $s3Nanoseconds = [];
    /** @var array<string, array<string, int>> */
    private array $keys = [];
    private int $keysDropped = 0;
    private int $cacheHits = 0;
    private int $memoHits = 0;
    private int $cacheMisses = 0;
    private int $cacheNanoseconds = 0;
    private readonly bool $enabled;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $bucket,
        ?bool $enabled = null,
    ) {
        $this->enabled = $enabled ?? self::writesInfo($logger);
        // Registered up front, not on the first S3 request: a render served
        // entirely from the metadata cache has to produce its line too.
        EndOfRequest::register($this);
    }

    /**
     * Counts an S3 request. Returns the start time to hand to requestDone(),
     * or null when metrics are off.
     */
    public function request(string $operation, ?string $key = null): ?int
    {
        $this->requests[$operation] = ($this->requests[$operation] ?? 0) + 1;
        if ($key !== null && in_array($operation, self::KEYED_OPERATIONS, true)) {
            $this->countKey($operation, $key);
        }
        return $this->enabled ? (int)hrtime(true) : null;
    }

    /**
     * Adds the time since $started to the operation. Also used for work that
     * belongs to an operation counted earlier, such as reading a body.
     */
    public function requestDone(string $operation, ?int $started): void
    {
        if ($started === null) {
            return;
        }
        $this->s3Nanoseconds[$operation] = ($this->s3Nanoseconds[$operation] ?? 0) + ((int)hrtime(true) - $started);
    }

    /**
     * Start time for cacheDone() or requestDone(), or null when metrics are off.
     */
    public function startTimer(): ?int
    {
        return $this->enabled ? (int)hrtime(true) : null;
    }

    public function cacheDone(?int $started): void
    {
        if ($started !== null) {
            $this->cacheNanoseconds += (int)hrtime(true) - $started;
        }
    }

    public function cacheHit(bool $fromMemo = false): void
    {
        $this->cacheHits++;
        if ($fromMemo) {
            $this->memoHits++;
        }
    }

    public function cacheMiss(): void
    {
        $this->cacheMisses++;
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return array{bucket: string, host: string, path: string, requests: array<string, int>, total: int, s3_ms: float, s3_ms_by_operation: array<string, float>, cache_hits: int, memo_hits: int, cache_misses: int, cache_ms: float, keys: array<string, array<string, int>>, keys_dropped: int}
     */
    public function snapshot(): array
    {
        $context = RequestContext::current();
        return [
            'bucket' => $this->bucket,
            'host' => $context['host'],
            'path' => $context['path'],
            'requests' => $this->requests,
            'total' => array_sum($this->requests),
            's3_ms' => self::milliseconds(array_sum($this->s3Nanoseconds)),
            's3_ms_by_operation' => array_map(self::milliseconds(...), $this->s3Nanoseconds),
            'cache_hits' => $this->cacheHits,
            'memo_hits' => $this->memoHits,
            'cache_misses' => $this->cacheMisses,
            'cache_ms' => self::milliseconds($this->cacheNanoseconds),
            'keys' => $this->keys,
            'keys_dropped' => $this->keysDropped,
        ];
    }

    /**
     * Writes the line if anything was counted and starts the next request
     * from zero. Idempotent.
     */
    public function finish(): void
    {
        if ($this->enabled && ($this->requests !== [] || $this->cacheHits > 0 || $this->cacheMisses > 0)) {
            $this->logger->info(self::MESSAGE, $this->snapshot());
        }
        $this->requests = [];
        $this->s3Nanoseconds = [];
        $this->keys = [];
        $this->keysDropped = 0;
        $this->cacheHits = 0;
        $this->memoHits = 0;
        $this->cacheMisses = 0;
        $this->cacheNanoseconds = 0;
    }

    public function __destruct()
    {
        $this->finish();
    }

    /**
     * TYPO3's Logger adds a writer to every level at or above the configured
     * minimum, so an empty INFO list means an info record would be dropped.
     * Other PSR-3 loggers cannot tell; they are assumed to write.
     */
    public static function writesInfo(LoggerInterface $logger): bool
    {
        if ($logger instanceof NullLogger) {
            return false;
        }
        if ($logger instanceof Logger) {
            return ($logger->getWriters()[LogLevel::INFO] ?? []) !== [];
        }
        return true;
    }

    private static function milliseconds(int $nanoseconds): float
    {
        return round($nanoseconds / 1_000_000, 3);
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
}
