<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Runtime;

use Koh\FalS3\Runtime\FinishRequestMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Http\MiddlewareStackResolver;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The metrics line covers a whole request only if the middleware wraps every
 * other one. The dispatcher runs the last entry of a resolved stack first.
 */
final class FinishRequestMiddlewareStackTest extends FunctionalTestCase
{
    protected array $coreExtensionsToLoad = ['frontend', 'backend'];
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    /**
     * @return iterable<string, array{string}>
     */
    public static function stacks(): iterable
    {
        yield 'frontend' => ['frontend'];
        yield 'backend' => ['backend'];
    }

    #[Test]
    #[DataProvider('stacks')]
    public function runsOutermost(string $stack): void
    {
        $resolver = $this->get(MiddlewareStackResolver::class);
        self::assertInstanceOf(MiddlewareStackResolver::class, $resolver);
        $middlewares = $resolver->resolve($stack)->getArrayCopy();

        self::assertSame('koh/fal-s3/finish-request', array_key_last($middlewares));
        self::assertSame(FinishRequestMiddleware::class, $middlewares['koh/fal-s3/finish-request']);
        self::assertTrue($this->getContainer()->has(FinishRequestMiddleware::class), 'the dispatcher fetches it from the container');
    }
}
