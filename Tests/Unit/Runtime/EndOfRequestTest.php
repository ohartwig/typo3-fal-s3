<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Runtime;

use Koh\FalS3\Driver\TemporaryFiles;
use Koh\FalS3\Metrics\RequestCounter;
use Koh\FalS3\Runtime\EndOfRequest;
use Koh\FalS3\S3\ObjectMetadata;
use Koh\FalS3\S3\ObjectStore;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A worker server keeps one PHP process across many requests. Per-request
 * state has to finish when the request's objects go away, and nothing may keep
 * those objects alive until the process ends.
 */
final class EndOfRequestTest extends UnitTestCase
{
    #[Test]
    public function counterLogsWhenItsRequestEndsAndIsNotKeptAlive(): void
    {
        $logger = $this->recordingLogger();
        $before = EndOfRequest::pendingCount();

        $counter = new RequestCounter($logger, 'bucket');
        $counter->request('GetObject', 'a.jpg');
        self::assertSame($before + 1, EndOfRequest::pendingCount());
        self::assertSame([], $logger->records, 'nothing is logged while the request runs');

        unset($counter);

        self::assertCount(1, $logger->records, 'the line is written when the request objects go away');
        self::assertSame($before, EndOfRequest::pendingCount(), 'the registry holds its objects weakly');
    }

    #[Test]
    public function finishAllFlushesLiveObjectsOnce(): void
    {
        $logger = $this->recordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        $counter->request('HeadObject', 'a.jpg');

        EndOfRequest::finishAll();
        self::assertCount(1, $logger->records, 'the shutdown fallback flushes objects that are still alive');

        unset($counter);
        self::assertCount(1, $logger->records, 'and the destructor does not log the same counts again');
    }

    #[Test]
    public function aFlushStartsTheNextLineFromZero(): void
    {
        $logger = $this->recordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        for ($i = 0; $i <= RequestCounter::KEY_LIMIT; $i++) {
            $counter->request('GetObject', 'k' . $i);
        }
        $counter->cacheHit();
        $counter->cacheMiss();

        $counter->finish();
        $counter->request('HeadObject', 'x');

        self::assertSame([
            'bucket' => 'bucket',
            'requests' => ['HeadObject' => 1],
            'total' => 1,
            'cache_hits' => 0,
            'cache_misses' => 0,
            'keys' => ['HeadObject' => ['x' => 1]],
            'keys_dropped' => 0,
        ], $counter->snapshot());
    }

    #[Test]
    public function aCounterWithoutRequestsLogsNothing(): void
    {
        $logger = $this->recordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        $counter->cacheHit();
        unset($counter);

        self::assertSame([], $logger->records);
    }

    #[Test]
    public function readKeysAreListedAndCapped(): void
    {
        $counter = new RequestCounter(new NullLogger(), 'bucket');
        $counter->request('GetObject', 'a.jpg');
        $counter->request('GetObject', 'a.jpg');
        $counter->request('HeadObject', 'b.jpg');
        $counter->request('PutObject', 'c.jpg');
        for ($i = 0; $i < RequestCounter::KEY_LIMIT + 3; $i++) {
            $counter->request('HeadObject', 'many/' . $i . '.jpg');
        }

        $snapshot = $counter->snapshot();
        self::assertSame(['a.jpg' => 2], $snapshot['keys']['GetObject']);
        self::assertCount(RequestCounter::KEY_LIMIT, $snapshot['keys']['HeadObject']);
        self::assertSame(1, $snapshot['keys']['HeadObject']['b.jpg']);
        self::assertSame(4, $snapshot['keys_dropped']);
        self::assertArrayNotHasKey('PutObject', $snapshot['keys'], 'only read operations are keyed');
        self::assertSame(2 + 1 + 1 + RequestCounter::KEY_LIMIT + 3, $snapshot['total']);
    }

    #[Test]
    public function temporaryFilesAreRemovedWhenTheirRequestEnds(): void
    {
        $before = EndOfRequest::pendingCount();
        $files = new TemporaryFiles($this->storeWritingTo());
        $shared = $files->materialise('a.jpg', 'etag', 'jpg', false);
        $copy = $files->materialise('a.jpg', 'etag', 'jpg', true);
        self::assertFileExists($shared);
        self::assertFileExists($copy);
        self::assertSame($before + 1, EndOfRequest::pendingCount(), 'registered once for the shutdown fallback');

        EndOfRequest::finishAll();
        self::assertFileDoesNotExist($shared, 'the shutdown fallback removes the files of live objects');

        $again = $files->materialise('a.jpg', 'etag', 'jpg', false);
        unset($files);

        self::assertFileDoesNotExist($again);
        self::assertFileDoesNotExist($copy);
    }

    private function recordingLogger(): RecordingLogger
    {
        return new RecordingLogger();
    }

    private function storeWritingTo(): ObjectStore
    {
        return new class () extends ObjectStore {
            public function __construct() {}

            public function downloadTo(string $key, string $localPath): ObjectMetadata
            {
                file_put_contents($localPath, 'body of ' . $key);
                return ObjectMetadata::missing();
            }
        };
    }
}
