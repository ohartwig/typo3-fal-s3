<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Driver;

use TYPO3\CMS\Core\Core\Environment;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * The same contract against the core's LocalDriver. If this suite fails, the
 * contract is wrong, not the driver under test.
 */
final class LocalDriverContractTest extends DriverContractTestCase
{
    private string $basePath = '';

    protected function tearDown(): void
    {
        if ($this->basePath !== '' && is_dir($this->basePath)) {
            GeneralUtility::rmdir($this->basePath, true);
        }
        parent::tearDown();
    }

    protected function createDriver(): DriverInterface
    {
        $this->basePath = Environment::getVarPath() . '/transient/local-contract-' . bin2hex(random_bytes(4)) . '/';
        GeneralUtility::mkdir_deep($this->basePath);
        $driver = new LocalDriver(['basePath' => $this->basePath, 'pathType' => 'absolute', 'baseUri' => '/fileadmin/']);
        $driver->setStorageUid(1);
        $driver->mergeConfigurationCapabilities(new Capabilities(Capabilities::CAPABILITY_BROWSABLE | Capabilities::CAPABILITY_PUBLIC | Capabilities::CAPABILITY_WRITABLE | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS));
        $driver->processConfiguration();
        $driver->initialize();
        return $driver;
    }

    protected function expectedPublicUrl(string $identifier): string
    {
        return '/fileadmin' . $identifier;
    }
}
