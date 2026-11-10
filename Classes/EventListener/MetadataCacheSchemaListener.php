<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\EventListener;

use Koh\FalS3\Cache\MetadataCacheFactory;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Database\Event\AlterTableDefinitionStatementsEvent;

/**
 * TYPO3 derives the cache tables (cache_<identifier>, cache_<identifier>_tags)
 * from cacheConfigurations. The metadata cache is not in there any more (see
 * MetadataCacheFactory), so its tables are declared here; otherwise a fresh
 * installation would lack them and the database analyser would offer to drop
 * the existing ones.
 */
#[AsEventListener(identifier: 'koh-fal-s3/metadata-cache-schema')]
final class MetadataCacheSchemaListener
{
    public function __construct(
        private readonly MetadataCacheFactory $factory,
    ) {}

    public function __invoke(AlterTableDefinitionStatementsEvent $event): void
    {
        $sql = $this->factory->tableDefinitions();
        if ($sql !== '') {
            $event->addSqlData($sql);
        }
    }
}
