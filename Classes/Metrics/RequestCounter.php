<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Metrics;

use Psr\Log\LoggerInterface;

/**
 * Counts S3 requests per operation and cache hits/misses for one request
 * lifecycle, and writes a single info line at shutdown. That line is what the
 * cost comparison with the FUSE mount is made from, so it is deliberately
 * machine-readable and emitted only when at least one request went out.
 */
final class RequestCounter
{
    /** @var array<string, int> */
    private array $requests = [];
    private int $cacheHits = 0;
    private int $cacheMisses = 0;
    private bool $registered = false;

    public function __construct(
        private readonly LoggerInterface $logger,
        private readonly string $bucket,
    ) {}

    public function request(string $operation): void
    {
        $this->requests[$operation] = ($this->requests[$operation] ?? 0) + 1;
        $this->registerShutdown();
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
     * @return array{bucket: string, requests: array<string, int>, total: int, cache_hits: int, cache_misses: int}
     */
    public function snapshot(): array
    {
        return [
            'bucket' => $this->bucket,
            'requests' => $this->requests,
            'total' => array_sum($this->requests),
            'cache_hits' => $this->cacheHits,
            'cache_misses' => $this->cacheMisses,
        ];
    }

    public function flush(): void
    {
        if ($this->requests === []) {
            return;
        }
        $this->logger->info('S3 requests for this process', $this->snapshot());
        $this->requests = [];
        $this->cacheHits = 0;
        $this->cacheMisses = 0;
    }

    private function registerShutdown(): void
    {
        if ($this->registered) {
            return;
        }
        $this->registered = true;
        register_shutdown_function($this->flush(...));
    }
}
