<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Command;

use Koh\FalS3\Driver\S3Driver;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;
use TYPO3\CMS\Core\Configuration\FlexForm\FlexFormTools;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Registry;

/**
 * Switches a sys_file_storage record between the Local driver and this one,
 * in place. Identifiers, sys_file rows and processed-file records stay as
 * they are: the bucket root is the fileadmin root, so "/user_upload/x.jpg"
 * means the same object on both sides.
 *
 * Idempotent, so a deployment hook can run it on every rollout. The previous
 * configuration is kept in sys_registry for the way back.
 */
#[AsCommand(
    name: 'koh_fal_s3:storage',
    description: 'Switch a file storage between the Local driver and the S3 driver without touching its files.',
)]
final class StorageCommand extends Command
{
    private const REGISTRY_NAMESPACE = 'koh_fal_s3';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
        private readonly FlexFormTools $flexFormTools,
        private readonly Registry $registry,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this
            ->addOption('uid', null, InputOption::VALUE_REQUIRED, 'The sys_file_storage uid', '1')
            ->addOption('driver', null, InputOption::VALUE_REQUIRED, 'Target driver: "KohS3" or "Local"', S3Driver::SHORT_NAME)
            ->addOption('bucket', null, InputOption::VALUE_REQUIRED, 'Bucket to write into the FlexForm (optional; env KOH_FAL_S3_BUCKET wins at runtime anyway)')
            ->addOption('region', null, InputOption::VALUE_REQUIRED, 'Region to write into the FlexForm (optional)')
            ->addOption('endpoint', null, InputOption::VALUE_REQUIRED, 'Custom endpoint for the FlexForm, e.g. a MinIO (optional)')
            ->addOption('path-style', null, InputOption::VALUE_NONE, 'Use path-style addressing (MinIO)')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Show what would change, change nothing');
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $io = new SymfonyStyle($input, $output);
        $uid = (int)self::option($input, 'uid');
        $target = self::option($input, 'driver');
        if (!in_array($target, [S3Driver::SHORT_NAME, 'Local'], true)) {
            $io->error(sprintf('Unknown driver "%s"; use "%s" or "Local".', $target, S3Driver::SHORT_NAME));
            return Command::INVALID;
        }

        $connection = $this->connectionPool->getConnectionForTable('sys_file_storage');
        $record = $connection->select(['uid', 'name', 'driver', 'configuration', 'is_online'], 'sys_file_storage', ['uid' => $uid])->fetchAssociative();
        if ($record === false) {
            $io->error(sprintf('sys_file_storage %d does not exist.', $uid));
            return Command::FAILURE;
        }
        $currentDriver = is_string($record['driver']) ? $record['driver'] : '';
        $name = is_string($record['name']) ? $record['name'] : '';
        $currentConfiguration = is_string($record['configuration']) && $record['configuration'] !== ''
            ? $this->flexFormTools->convertFlexFormContentToArray($record['configuration'])
            : [];

        if ($currentDriver === $target) {
            $io->writeln(sprintf('Storage %d ("%s") already uses driver %s; nothing to do.', $uid, $name, $target));
            return Command::SUCCESS;
        }

        if ($target === S3Driver::SHORT_NAME) {
            $this->registry->set(self::REGISTRY_NAMESPACE, 'storage_' . $uid . '_previous', ['driver' => $currentDriver, 'configuration' => $currentConfiguration]);
            $flexForm = array_filter([
                'bucket' => self::option($input, 'bucket'),
                'region' => self::option($input, 'region'),
                'endpoint' => self::option($input, 'endpoint'),
                'pathStyle' => $input->getOption('path-style') === true ? '1' : '',
            ], static fn(string $value): bool => $value !== '');
        } else {
            $previous = $this->registry->get(self::REGISTRY_NAMESPACE, 'storage_' . $uid . '_previous');
            $flexForm = ['basePath' => 'fileadmin/', 'pathType' => 'relative', 'caseSensitive' => '1'];
            if (is_array($previous) && is_array($previous['configuration'] ?? null)) {
                $flexForm = [];
                foreach ($previous['configuration'] as $key => $value) {
                    if (is_string($key) && is_scalar($value)) {
                        $flexForm[$key] = $value;
                    }
                }
            }
        }

        $xml = $this->toFlexFormXml($flexForm);
        $io->writeln(sprintf('Storage %d ("%s"): %s -> %s', $uid, $name, $currentDriver, $target));
        $io->writeln('Configuration: ' . json_encode($flexForm, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
        if ($input->getOption('dry-run')) {
            $io->note('Dry run, nothing written.');
            return Command::SUCCESS;
        }
        $connection->update('sys_file_storage', ['driver' => $target, 'configuration' => $xml, 'is_online' => 1, 'tstamp' => time()], ['uid' => $uid]);
        $io->success(sprintf('Storage %d now uses driver %s. Flush caches (cache:flush) so running processes pick it up.', $uid, $target));
        return Command::SUCCESS;
    }

    private static function option(InputInterface $input, string $name): string
    {
        $value = $input->getOption($name);
        return is_scalar($value) ? (string)$value : '';
    }

    /**
     * @param array<string, scalar> $values
     */
    private function toFlexFormXml(array $values): string
    {
        $fields = [];
        foreach ($values as $key => $value) {
            $fields[$key] = ['vDEF' => (string)$value];
        }
        return $this->flexFormTools->flexArray2Xml(['data' => ['sDEF' => ['lDEF' => $fields]]]);
    }
}
