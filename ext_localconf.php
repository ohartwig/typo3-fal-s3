<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

use Koh\FalS3\Driver\DriverRuntimeFactory;
use Koh\FalS3\Driver\S3Driver;
use Koh\FalS3\Imaging\ImageService;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;
use TYPO3\CMS\Extbase\Service\ImageService as ExtbaseImageService;

defined('TYPO3') or die();

// Registered through the configuration array, not DriverRegistry::registerDriverClass():
// the Install Tool's failsafe container builds its own registry from this array only
// (forge #93249), and a driver missing there cannot be selected for a storage.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['fal']['registeredDrivers'][S3Driver::SHORT_NAME] = [
    'class' => S3Driver::class,
    'shortName' => S3Driver::SHORT_NAME,
    'label' => 'Amazon S3 (koh_fal_s3)',
    'flexFormDS' => 'FILE:EXT:koh_fal_s3/Configuration/FlexForm/S3DriverFlexForm.xml',
];

// Shared metadata cache. The database backend is the safe default: every replica
// sees one cache without extra infrastructure. Production points it at Valkey via
// TYPO3__SYS__caching__cacheConfigurations__koh_fal_s3_meta__backend; ??= keeps
// that override intact.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations'][DriverRuntimeFactory::CACHE_IDENTIFIER] ??= [
    'frontend' => VariableFrontend::class,
    'backend' => Typo3DatabaseBackend::class,
    'options' => ['defaultLifetime' => 86400],
    'groups' => ['system'],
];

// Extbase's ImageService downloads every processed image to register it with the
// AssetCollector; ours skips that for S3 storages (Classes/Imaging/ImageService.php).
// Extbase builds the service in its ServiceProvider, which honours XCLASS but not a
// Services.yaml override. ??= leaves an XCLASS the site registered itself in place.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['Objects'][ExtbaseImageService::class] ??= ['className' => ImageService::class];
