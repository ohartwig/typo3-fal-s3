<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Metrics;

use Koh\FalS3\Cache\MetadataCache;
use Koh\FalS3\Metrics\RequestContext;
use Koh\FalS3\Metrics\RequestCounter;
use Koh\FalS3\S3\ObjectMetadata;
use Koh\FalS3\Tests\Unit\Runtime\RecordingLogger;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\LogLevel;
use Psr\Log\NullLogger;
use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Core\Log\Logger;
use TYPO3\CMS\Core\Log\Writer\NullWriter;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * The metrics line has to separate S3 round trips from metadata-cache
 * lookups and say how long each took, and it must cost nothing when no one
 * reads it.
 */
final class RequestCounterTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    protected function tearDown(): void
    {
        RequestContext::reset();
        parent::tearDown();
    }

    #[Test]
    public function aRenderServedFromTheCacheAloneStillWritesItsLine(): void
    {
        $logger = new RecordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        $counter->cacheHit();
        $counter->cacheHit(true);

        $counter->finish();

        self::assertSame([RequestCounter::MESSAGE], $logger->records);
        $line = $logger->contexts[0];
        self::assertSame(0, $line['total']);
        self::assertSame(2, $line['cache_hits']);
        self::assertSame(1, $line['memo_hits']);
    }

    #[Test]
    public function s3TimeIsSummedPerOperation(): void
    {
        $counter = new RequestCounter(new RecordingLogger(), 'bucket');
        $counter->request('HeadObject', 'a.jpg');
        $counter->requestDone('HeadObject', hrtime(true) - 5_000_000);
        $counter->request('HeadObject', 'b.jpg');
        $counter->requestDone('HeadObject', hrtime(true) - 3_000_000);
        $counter->request('GetObject', 'c.jpg');
        $counter->requestDone('GetObject', hrtime(true) - 2_000_000);

        $snapshot = $counter->snapshot();
        self::assertGreaterThanOrEqual(8.0, $snapshot['s3_ms_by_operation']['HeadObject']);
        self::assertLessThan(50.0, $snapshot['s3_ms_by_operation']['HeadObject']);
        self::assertGreaterThanOrEqual(2.0, $snapshot['s3_ms_by_operation']['GetObject']);
        self::assertEqualsWithDelta(
            $snapshot['s3_ms_by_operation']['HeadObject'] + $snapshot['s3_ms_by_operation']['GetObject'],
            $snapshot['s3_ms'],
            0.002,
        );
    }

    #[Test]
    public function cacheTimeIsSummed(): void
    {
        $counter = new RequestCounter(new RecordingLogger(), 'bucket');
        $counter->cacheDone(hrtime(true) - 1_500_000);
        $counter->cacheDone(hrtime(true) - 500_000);

        self::assertGreaterThanOrEqual(2.0, $counter->snapshot()['cache_ms']);
    }

    #[Test]
    public function theLineNamesTheHttpRequest(): void
    {
        $logger = new RecordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        RequestContext::enter('example.org', '/blog/post');
        $counter->request('HeadObject', 'a.jpg');

        $counter->finish();

        self::assertSame('example.org', $logger->contexts[0]['host']);
        self::assertSame('/blog/post', $logger->contexts[0]['path']);
    }

    #[Test]
    public function withoutAnInfoWriterNothingIsTimedOrLogged(): void
    {
        $logger = new Logger('Koh.FalS3.Metrics');
        $logger->addWriter(LogLevel::WARNING, new NullWriter());
        self::assertFalse(RequestCounter::writesInfo($logger));
        self::assertFalse(RequestCounter::writesInfo(new Logger('Koh.FalS3.Metrics')));
        self::assertFalse(RequestCounter::writesInfo(new NullLogger()));

        $counter = new RequestCounter(new NullLogger(), 'bucket');
        self::assertFalse($counter->isEnabled());
        self::assertNull($counter->request('HeadObject', 'a.jpg'));
        self::assertNull($counter->startTimer());
        self::assertSame(1, $counter->snapshot()['total'], 'requests are still counted for diagnostics');
    }

    #[Test]
    public function anInfoWriterSwitchesMetricsOn(): void
    {
        $logger = new Logger('Koh.FalS3.Metrics');
        $logger->addWriter(LogLevel::INFO, new NullWriter());
        self::assertTrue(RequestCounter::writesInfo($logger));

        $debug = new Logger('Koh.FalS3.Metrics');
        $debug->addWriter(LogLevel::DEBUG, new NullWriter());
        self::assertTrue(RequestCounter::writesInfo($debug));

        self::assertTrue(RequestCounter::writesInfo(new RecordingLogger()), 'other loggers are assumed to write');
    }

    #[Test]
    public function metadataCacheReportsMemoHitsAndDropsTheMemoWithTheRequest(): void
    {
        $backend = new TransientMemoryBackend();
        $frontend = new VariableFrontend('koh_fal_s3_meta', $backend);
        $backend->setCache($frontend);
        $counter = new RequestCounter(new RecordingLogger(), 'bucket');
        $cache = new MetadataCache($frontend, 'bucket', 3600, 60, $counter);

        $cache->rememberObject('a.jpg', new ObjectMetadata(true, 1, 2, '"e"', 'image/jpeg', null, false));
        $cache->object('a.jpg');
        $cache->object('missing.jpg');
        self::assertSame(1, $counter->snapshot()['cache_hits']);
        self::assertSame(1, $counter->snapshot()['memo_hits'], 'the value written in this request is in the memo');
        self::assertSame(1, $counter->snapshot()['cache_misses']);

        $cache->finish();
        $counter->finish();
        $cache->object('a.jpg');

        self::assertSame(1, $counter->snapshot()['cache_hits']);
        self::assertSame(0, $counter->snapshot()['memo_hits'], 'the next request asks the shared cache again');
    }
}
