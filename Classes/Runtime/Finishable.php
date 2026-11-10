<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Runtime;

/**
 * Per-request state that must be closed: from the destructor, from
 * FinishRequestMiddleware at the end of each HTTP request, or from the
 * shutdown fallback in EndOfRequest. finish() must be idempotent and leave
 * the object usable for the next request, which in a worker server with a
 * persistent bootstrap is served by the same object.
 */
interface Finishable
{
    public function finish(): void;
}
