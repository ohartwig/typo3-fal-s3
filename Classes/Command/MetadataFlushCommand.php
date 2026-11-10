<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Command;

use Koh\FalS3\Cache\MetadataCacheFactory;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

/**
 * Empties the shared S3 metadata cache. `cache:flush` deliberately does not
 * (see MetadataCacheFactory); this is the way to make TYPO3 forget what it
 * knows about the bucket, e.g. after objects were changed outside TYPO3 and
 * the cache lifetime is too long to wait for. The cache is shared, so one run
 * reaches every replica; each replica's request memo ends with its request.
 */
#[AsCommand(
    name: 'koh_fal_s3:metadata:flush',
    description: 'Empty the shared S3 metadata cache (not touched by cache:flush).',
)]
final class MetadataFlushCommand extends Command
{
    public function __construct(
        private readonly MetadataCacheFactory $factory,
    ) {
        parent::__construct();
    }

    protected function configure(): void
    {
        $this->setHelp(
            'The next request asks S3 once for every object it needs, so expect one HEAD per processed image '
            . 'on the first render of each page. Run it after changing bucket contents outside TYPO3, not as part of a deployment.'
        );
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->factory->flush();
        (new SymfonyStyle($input, $output))->success(sprintf(
            'S3 metadata cache "%s" (%s) flushed.',
            MetadataCacheFactory::IDENTIFIER,
            $this->factory->backendClassName(),
        ));
        return Command::SUCCESS;
    }
}
