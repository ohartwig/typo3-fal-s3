<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Driver;

use Koh\FalS3\Driver\IdentifierMapper;
use Koh\FalS3\Exception\ExcludedPrefixException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Exception\InvalidPathException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class IdentifierMapperTest extends UnitTestCase
{
    #[Test]
    public function mapsIdentifiersToKeysWithoutPrefix(): void
    {
        $mapper = new IdentifierMapper('', []);
        self::assertSame('user_upload/x.jpg', $mapper->fileKey('/user_upload/x.jpg'));
        self::assertSame('', $mapper->folderPrefix('/'));
        self::assertSame('user_upload/', $mapper->folderPrefix('/user_upload/'));
        self::assertSame('/user_upload/x.jpg', $mapper->identifierFromKey('user_upload/x.jpg'));
        self::assertSame('/user_upload/', $mapper->identifierFromKey('user_upload/'));
    }

    #[Test]
    public function mapsIdentifiersToKeysWithPrefix(): void
    {
        $mapper = new IdentifierMapper('tenant/', []);
        self::assertSame('tenant/user_upload/x.jpg', $mapper->fileKey('/user_upload/x.jpg'));
        self::assertSame('tenant/', $mapper->folderPrefix('/'));
        self::assertSame('tenant/a/b/', $mapper->folderPrefix('/a/b/'));
        self::assertSame('/a/b/', $mapper->identifierFromKey('tenant/a/b/'));
        self::assertSame('a/b.txt', $mapper->relativeKey('tenant/a/b.txt'));
    }

    #[Test]
    public function refusesKeysOutsideThePrefix(): void
    {
        $mapper = new IdentifierMapper('tenant/', []);
        $this->expectException(InvalidPathException::class);
        $mapper->identifierFromKey('other/x.jpg');
    }

    /**
     * @return iterable<string, array{string}>
     */
    public static function unsafeIdentifierProvider(): iterable
    {
        yield 'no leading slash' => ['user_upload/x.jpg'];
        yield 'empty' => [''];
        yield 'backslash' => ['/a\\b.jpg'];
        yield 'control character' => ["/a\x01b.jpg"];
        yield 'dot segment' => ['/a/./b.jpg'];
        yield 'dot-dot segment' => ['/a/../b.jpg'];
        yield 'empty segment' => ['/a//b.jpg'];
    }

    #[Test]
    #[DataProvider('unsafeIdentifierProvider')]
    public function refusesUnsafeIdentifiers(string $identifier): void
    {
        $mapper = new IdentifierMapper('', []);
        $this->expectException(InvalidPathException::class);
        $mapper->fileKey($identifier);
    }

    #[Test]
    public function aFolderIdentifierIsNotAFileKey(): void
    {
        $mapper = new IdentifierMapper('', []);
        $this->expectException(InvalidPathException::class);
        $mapper->fileKey('/user_upload/');
    }

    /**
     * @return iterable<string, array{string, bool}>
     */
    public static function exclusionProvider(): iterable
    {
        yield 'folder prefix, file inside' => ['/_typo3temp-assets/x.css', true];
        yield 'folder prefix, folder itself' => ['/_typo3temp-assets/', true];
        yield 'folder prefix, key form' => ['_typo3temp-assets/a/b', true];
        yield 'folder prefix, similar name' => ['/_typo3temp-assets2/x.css', false];
        yield 'object exclusion, exact' => ['/malware-protection-resource-validation-object', true];
        yield 'object exclusion, as folder' => ['/malware-protection-resource-validation-object/x', true];
        yield 'object exclusion, longer name' => ['/malware-protection-resource-validation-object-2', false];
        yield 'ordinary file' => ['/user_upload/x.jpg', false];
        yield 'pos exchange' => ['/pos-exchange/in/1.csv', true];
    }

    #[Test]
    #[DataProvider('exclusionProvider')]
    public function appliesExclusionRules(string $identifierOrKey, bool $excluded): void
    {
        $mapper = new IdentifierMapper('', ['_typo3temp-assets/', 'pos-exchange/', 'malware-protection-resource-validation-object']);
        self::assertSame($excluded, $mapper->isExcluded($identifierOrKey));
    }

    #[Test]
    public function exclusionIgnoresTheKeyPrefix(): void
    {
        $mapper = new IdentifierMapper('tenant/', ['pos-exchange/']);
        self::assertTrue($mapper->isExcluded('tenant/pos-exchange/x'));
        self::assertTrue($mapper->isExcluded('/pos-exchange/x'));
        self::assertFalse($mapper->isExcluded('tenant/user_upload/x'));
    }

    #[Test]
    public function assertNotExcludedThrowsForExcludedPaths(): void
    {
        $mapper = new IdentifierMapper('', ['pos-exchange/']);
        $mapper->assertNotExcluded('/user_upload/x.jpg');
        $this->expectException(ExcludedPrefixException::class);
        $mapper->assertNotExcluded('/pos-exchange/x.jpg');
    }
}
