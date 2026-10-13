<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Command;

use Koh\FalS3\Command\StorageCommand;
use Koh\FalS3\Driver\S3Driver;
use PHPUnit\Framework\Attributes\Test;
use Symfony\Component\Console\Tester\CommandTester;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The in-place switch of a sys_file_storage record, there and back again.
 */
final class StorageCommandTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    protected function setUp(): void
    {
        parent::setUp();
        $xml = GeneralUtility::makeInstance(FlexFormTools::class)->flexArray2Xml(['data' => ['sDEF' => ['lDEF' => [
            'basePath' => ['vDEF' => 'fileadmin/'],
            'pathType' => ['vDEF' => 'relative'],
            'caseSensitive' => ['vDEF' => '1'],
        ]]]]);
        $this->connection()->insert('sys_file_storage', [
            'uid' => 1, 'pid' => 0, 'name' => 'fileadmin', 'driver' => 'Local', 'configuration' => $xml,
            'is_browsable' => 1, 'is_public' => 1, 'is_writable' => 1, 'is_online' => 1, 'is_default' => 1,
        ]);
    }

    #[Test]
    public function switchesToS3AndBackKeepingTheOldConfiguration(): void
    {
        $tester = new CommandTester($this->command());

        $tester->execute(['--uid' => '1', '--driver' => 'KohS3', '--bucket' => 'koh-web-x-media', '--region' => 'eu-north-1', '--dry-run' => true]);
        self::assertSame(0, $tester->getStatusCode());
        self::assertSame('Local', $this->record()['driver'], 'dry run changes nothing');

        $tester->execute(['--uid' => '1', '--driver' => 'KohS3', '--bucket' => 'koh-web-x-media', '--region' => 'eu-north-1']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $record = $this->record();
        self::assertSame(S3Driver::SHORT_NAME, $record['driver']);
        $configuration = GeneralUtility::makeInstance(FlexFormTools::class)->convertFlexFormContentToArray(is_string($record['configuration']) ? $record['configuration'] : '');
        self::assertSame('koh-web-x-media', $configuration['bucket']);
        self::assertSame('eu-north-1', $configuration['region']);

        $tester->execute(['--uid' => '1', '--driver' => 'KohS3']);
        self::assertSame(0, $tester->getStatusCode());
        self::assertStringContainsString('nothing to do', $tester->getDisplay(), 'idempotent');

        $tester->execute(['--uid' => '1', '--driver' => 'Local']);
        self::assertSame(0, $tester->getStatusCode(), $tester->getDisplay());
        $record = $this->record();
        self::assertSame('Local', $record['driver']);
        $configuration = GeneralUtility::makeInstance(FlexFormTools::class)->convertFlexFormContentToArray(is_string($record['configuration']) ? $record['configuration'] : '');
        self::assertSame('fileadmin/', $configuration['basePath'], 'the previous configuration came back from sys_registry');
        self::assertSame('relative', $configuration['pathType']);
    }

    #[Test]
    public function refusesUnknownDriversAndMissingStorages(): void
    {
        $tester = new CommandTester($this->command());
        $tester->execute(['--uid' => '1', '--driver' => 'Flux']);
        self::assertSame(2, $tester->getStatusCode());
        $tester->execute(['--uid' => '99', '--driver' => 'KohS3']);
        self::assertSame(1, $tester->getStatusCode());
    }

    /**
     * @return array<string, mixed>
     */
    private function record(): array
    {
        $row = $this->connection()->select(['driver', 'configuration'], 'sys_file_storage', ['uid' => 1])->fetchAssociative();
        self::assertIsArray($row);
        return $row;
    }

    private function command(): StorageCommand
    {
        $command = $this->get(StorageCommand::class);
        self::assertInstanceOf(StorageCommand::class, $command);
        return $command;
    }

    private function connection(): \TYPO3\CMS\Core\Database\Connection
    {
        return GeneralUtility::makeInstance(ConnectionPool::class)->getConnectionForTable('sys_file_storage');
    }
}
