<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Cache;

use TYPO3\CMS\Core\Cache\Backend\TransientMemoryBackend;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;

/**
 * A memory backend that records what MetadataCacheFactory did to it, in the
 * order the CacheManager does it.
 */
final class RecordingBackend extends TransientMemoryBackend
{
    /** @var list<string> */
    public array $calls = [];

    public ?bool $compression = null;

    public string $hostname = '';

    public function setCompression(bool $compression): void
    {
        $this->calls[] = 'setCompression';
        $this->compression = $compression;
    }

    public function setHostname(string $hostname): void
    {
        $this->calls[] = 'setHostname';
        $this->hostname = $hostname;
    }

    public function initializeObject(): void
    {
        $this->calls[] = 'initializeObject';
    }

    public function setCache(FrontendInterface $cache): void
    {
        $this->calls[] = 'setCache:' . $cache->getIdentifier();
        parent::setCache($cache);
    }
}
