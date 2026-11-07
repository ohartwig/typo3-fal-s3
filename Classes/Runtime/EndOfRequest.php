<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Runtime;

/**
 * Runs per-request clean-up when the objects of a request go away, not when
 * the PHP process ends.
 *
 * A worker server (FrankenPHP, RoadRunner) serves hundreds of requests in one
 * process. register_shutdown_function() per object would hold every request's
 * driver runtime, S3 client included, until the worker exits, and run all of
 * their clean-up in one burst at that moment. Objects that register here
 * finish from their destructor instead: once the worker drops the request's
 * container, they finish with it.
 *
 * One process-wide shutdown hook remains as a fallback for objects still alive
 * when the process ends; after a fatal error PHP does not call destructors.
 * The registry holds its objects weakly, so it keeps nothing alive.
 */
final class EndOfRequest
{
    /** @var \WeakMap<Finishable, true>|null */
    private static ?\WeakMap $pending = null;

    public static function register(Finishable $object): void
    {
        if (self::$pending === null) {
            self::$pending = new \WeakMap();
            register_shutdown_function(self::finishAll(...));
        }
        self::$pending[$object] = true;
    }

    public static function finishAll(): void
    {
        if (self::$pending === null) {
            return;
        }
        foreach (self::$pending as $object => $_) {
            $object->finish();
        }
    }

    /**
     * Number of registered objects still alive, for tests.
     */
    public static function pendingCount(): int
    {
        return self::$pending === null ? 0 : count(self::$pending);
    }
}
