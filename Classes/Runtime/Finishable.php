<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Runtime;

/**
 * Per-request state that must be closed once: from the destructor, or from
 * the shutdown fallback in EndOfRequest. finish() must be idempotent.
 */
interface Finishable
{
    public function finish(): void;
}
