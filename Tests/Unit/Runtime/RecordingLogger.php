<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Runtime;

use Psr\Log\AbstractLogger;

final class RecordingLogger extends AbstractLogger
{
    /** @var list<string> */
    public array $records = [];
    /** @var list<array<mixed>> */
    public array $contexts = [];

    public function log($level, \Stringable|string $message, array $context = []): void
    {
        $this->records[] = (string)$message;
        $this->contexts[] = $context;
    }
}
