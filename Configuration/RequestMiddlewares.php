<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Koh\FalS3\Runtime\FinishRequestMiddleware;

// Outermost in both stacks, so the metrics line covers the whole request and
// per-request state ends once per HTTP request, also in a worker server. The
// "before" targets are the first core middlewares of each stack; if one is
// renamed, the ordering service drops the reference and the middleware still
// runs, only no longer guaranteed first.
return [
    'frontend' => [
        'koh/fal-s3/finish-request' => [
            'target' => FinishRequestMiddleware::class,
            'before' => [
                'typo3/cms-frontend/timetracker',
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
    'backend' => [
        'koh/fal-s3/finish-request' => [
            'target' => FinishRequestMiddleware::class,
            'before' => [
                'typo3/cms-core/normalized-params-attribute',
            ],
        ],
    ],
];
