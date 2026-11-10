<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Runtime;

use Koh\FalS3\Metrics\RequestContext;
use Koh\FalS3\Metrics\RequestCounter;
use Koh\FalS3\Runtime\FinishRequestMiddleware;
use PHPUnit\Framework\Attributes\Test;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\RequestHandlerInterface;
use TYPO3\CMS\Core\Http\Response;
use TYPO3\CMS\Core\Http\ServerRequest;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

/**
 * A worker server with a persistent bootstrap keeps the driver objects, and
 * with them the counters, across requests. The line still has to be one per
 * HTTP request.
 */
final class FinishRequestMiddlewareTest extends UnitTestCase
{
    protected function tearDown(): void
    {
        RequestContext::reset();
        parent::tearDown();
    }

    #[Test]
    public function aSurvivingCounterWritesOneLinePerRequest(): void
    {
        $logger = new RecordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        $middleware = new FinishRequestMiddleware();

        $middleware->process(new ServerRequest('https://example.org/one?page=2'), $this->handler(static function () use ($counter): void {
            $counter->request('HeadObject', 'a.jpg');
            $counter->request('HeadObject', 'b.jpg');
        }));
        $middleware->process(new ServerRequest('https://example.org/two'), $this->handler(static function () use ($counter): void {
            $counter->cacheHit();
        }));

        self::assertCount(2, $logger->contexts);
        self::assertSame(['example.org', '/one', 2], [$logger->contexts[0]['host'], $logger->contexts[0]['path'], $logger->contexts[0]['total']]);
        self::assertSame(['example.org', '/two', 0, 1], [$logger->contexts[1]['host'], $logger->contexts[1]['path'], $logger->contexts[1]['total'], $logger->contexts[1]['cache_hits']]);
        self::assertSame(['host' => '', 'path' => ''], RequestContext::current(), 'nothing leaks into the time between requests');
    }

    #[Test]
    public function aFailingRequestStillWritesItsLine(): void
    {
        $logger = new RecordingLogger();
        $counter = new RequestCounter($logger, 'bucket');

        try {
            (new FinishRequestMiddleware())->process(new ServerRequest('https://example.org/broken'), $this->handler(static function () use ($counter): void {
                $counter->request('GetObject', 'a.jpg');
                throw new \RuntimeException('render failed');
            }));
            self::fail('the exception passes through');
        } catch (\RuntimeException) {
        }

        self::assertCount(1, $logger->contexts);
        self::assertSame('/broken', $logger->contexts[0]['path']);
    }

    #[Test]
    public function aSubRequestBelongsToTheOuterRequest(): void
    {
        $logger = new RecordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        $middleware = new FinishRequestMiddleware();

        $middleware->process(new ServerRequest('https://example.org/missing'), $this->handler(function () use ($counter, $middleware): void {
            $counter->request('HeadObject', 'a.jpg');
            $middleware->process(new ServerRequest('https://example.org/404'), $this->handler(static function () use ($counter): void {
                $counter->request('HeadObject', 'b.jpg');
            }));
            self::assertSame('/missing', RequestContext::current()['path'], 'the sub-request leaves the outer context in place');
        }));

        self::assertCount(1, $logger->contexts);
        self::assertSame(['/missing', 2], [$logger->contexts[0]['path'], $logger->contexts[0]['total']]);
    }

    #[Test]
    public function workBeforeTheRequestIsNotAttributedToIt(): void
    {
        $logger = new RecordingLogger();
        $counter = new RequestCounter($logger, 'bucket');
        $counter->request('ListObjectsV2');

        (new FinishRequestMiddleware())->process(new ServerRequest('https://example.org/page'), $this->handler(static function () use ($counter): void {
            $counter->request('HeadObject', 'a.jpg');
        }));

        self::assertCount(2, $logger->contexts);
        self::assertSame(['', ['ListObjectsV2' => 1]], [$logger->contexts[0]['path'], $logger->contexts[0]['requests']]);
        self::assertSame(['/page', ['HeadObject' => 1]], [$logger->contexts[1]['path'], $logger->contexts[1]['requests']]);
    }

    /**
     * @param \Closure(): void $work
     */
    private function handler(\Closure $work): RequestHandlerInterface
    {
        return new class ($work) implements RequestHandlerInterface {
            /**
             * @param \Closure(): void $work
             */
            public function __construct(private readonly \Closure $work) {}

            public function handle(ServerRequestInterface $request): ResponseInterface
            {
                ($this->work)();
                return new Response();
            }
        };
    }
}
