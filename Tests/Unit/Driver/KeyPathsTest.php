<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Driver;

use Koh\FalS3\Driver\KeyPaths;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class KeyPathsTest extends UnitTestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function parentProvider(): iterable
    {
        yield 'root file' => ['x.jpg', ''];
        yield 'nested file' => ['a/b/c.jpg', 'a/b/'];
        yield 'folder marker' => ['a/b/', 'a/'];
        yield 'top folder marker' => ['a/', ''];
        yield 'root' => ['', ''];
    }

    #[Test]
    #[DataProvider('parentProvider')]
    public function parentIsTheContainingPrefix(string $key, string $expected): void
    {
        self::assertSame($expected, KeyPaths::parent($key));
    }

    #[Test]
    public function ancestorsRunFromThePrefixToTheRoot(): void
    {
        self::assertSame(['a/b/', 'a/', ''], KeyPaths::ancestors('a/b/'));
        self::assertSame([''], KeyPaths::ancestors(''));
    }

    #[Test]
    public function nameIsTheLastSegment(): void
    {
        self::assertSame('c.jpg', KeyPaths::name('a/b/c.jpg'));
        self::assertSame('b', KeyPaths::name('a/b/'));
        self::assertSame('x', KeyPaths::name('x'));
    }

    #[Test]
    public function folderKeysEndWithASlash(): void
    {
        self::assertTrue(KeyPaths::isFolderKey('a/'));
        self::assertFalse(KeyPaths::isFolderKey('a'));
        self::assertFalse(KeyPaths::isFolderKey(''));
    }
}
