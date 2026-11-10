<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Metrics;

/**
 * The HTTP request the metrics line belongs to, set by
 * FinishRequestMiddleware. Static because the counters are created by the
 * driver, which TYPO3 builds without the request, and may outlive it in a
 * worker server.
 *
 * Requests nest: TYPO3 can render an error page through an internal
 * sub-request that passes the same middleware stack. Only the outermost
 * request sets the context and ends it.
 */
final class RequestContext
{
    private static int $depth = 0;
    private static string $host = '';
    private static string $path = '';

    public static function inRequest(): bool
    {
        return self::$depth > 0;
    }

    /**
     * Only the outermost request sets host and path.
     */
    public static function enter(string $host, string $path): void
    {
        if (self::$depth++ > 0) {
            return;
        }
        self::$host = $host;
        self::$path = $path;
    }

    /**
     * Host and path stay until reset(), so the outermost request can still
     * write its line after leaving.
     */
    public static function leave(): void
    {
        if (self::$depth > 0) {
            self::$depth--;
        }
    }

    public static function reset(): void
    {
        self::$depth = 0;
        self::$host = '';
        self::$path = '';
    }

    /**
     * @return array{host: string, path: string}
     */
    public static function current(): array
    {
        return ['host' => self::$host, 'path' => self::$path];
    }
}
