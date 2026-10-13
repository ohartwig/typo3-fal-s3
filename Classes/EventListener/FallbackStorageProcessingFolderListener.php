<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\EventListener;

use Koh\FalS3\Configuration\ConfigurationResolver;
use Psr\EventDispatcher\EventDispatcherInterface;
use TYPO3\CMS\Core\Attribute\AsEventListener;
use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Event\AfterResourceStorageInitializationEvent;
use TYPO3\CMS\Core\Resource\ResourceStorage;

/**
 * The fallback storage (uid 0, "EXT:" resources) has a hard-coded processing
 * folder under typo3temp/assets, which is pod-local once fileadmin lives in
 * S3: a variant rendered on one replica is a 404 on the next. With
 * fallbackProcessingFolder = "1:/_processed_/_fallback_/" those variants go
 * through storage 1 — this driver — and are served from the bucket.
 *
 * The record of storage 0 is built in StorageRepository and cannot be
 * altered before construction, so the storage is rebuilt here with the same
 * driver configuration and the changed processingfolder.
 */
#[AsEventListener(identifier: 'koh-fal-s3/fallback-storage-processing-folder')]
final class FallbackStorageProcessingFolderListener
{
    public function __construct(
        private readonly ConfigurationResolver $resolver,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {}

    public function __invoke(AfterResourceStorageInitializationEvent $event): void
    {
        $storage = $event->getStorage();
        if ($storage->getUid() !== 0) {
            return;
        }
        $target = $this->resolver->resolveGlobal('fallbackProcessingFolder');
        if ($target === null || !str_contains($target, ':')) {
            return;
        }
        $record = $storage->getStorageRecord();
        if (($record['processingfolder'] ?? '') === $target) {
            return;
        }
        $record['processingfolder'] = $target;
        $record['configuration'] = [];
        $driver = new LocalDriver(['basePath' => Environment::getPublicPath(), 'pathType' => 'absolute']);
        $event->setStorage(new ResourceStorage($driver, $record, $this->eventDispatcher));
    }
}
