<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Cache;

use TYPO3\CMS\Core\Cache\Backend\BackendInterface;
use TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend;
use TYPO3\CMS\Core\Cache\Exception\InvalidBackendException;
use TYPO3\CMS\Core\Cache\Exception\InvalidCacheException;
use TYPO3\CMS\Core\Cache\Frontend\FrontendInterface;
use TYPO3\CMS\Core\Cache\Frontend\VariableFrontend;

/**
 * Builds the shared metadata cache outside the CacheManager.
 *
 * The entries describe S3 objects (exists, size, mtime, ETag, listings), not
 * code or rendered output. A cache registered with the CacheManager is
 * emptied by every `cache:flush`, every "Flush all caches" and every
 * CacheManager::flushCaches(), whatever its group; a deployment that runs
 * `cache:flush` would then make the first render of each page ask S3 once
 * per processed image again. Built here, those flushes do not reach it. What
 * bounds staleness is unchanged: the driver invalidates on its own writes,
 * every key contains the bucket, and the positive and negative lifetimes
 * cover changes made outside TYPO3. `koh_fal_s3:metadata:flush` empties it
 * on purpose.
 *
 * The configuration has the shape of a cacheConfigurations entry and lives
 * at $GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['koh_fal_s3_meta'], next
 * to cacheConfigurations rather than inside it. ext_localconf.php moves a
 * legacy entry out of cacheConfigurations (see relocate()), so existing
 * overrides such as TYPO3__SYS__caching__cacheConfigurations__koh_fal_s3_meta__backend
 * keep working and the CacheManager never learns about the cache.
 *
 * Frontend and backend are instantiated exactly as CacheManager::createCache()
 * does in TYPO3 v14: backend constructed with its options (legacy non-bool
 * `compression` normalised), initializeObject() if it has one, frontend
 * constructed with identifier and backend (which calls setCache() on the
 * backend), initializeObject() if it has one. Any backend that works in
 * cacheConfigurations, including RedisBackend and its derivatives, works here
 * with the same options.
 */
class MetadataCacheFactory
{
    public const IDENTIFIER = 'koh_fal_s3_meta';

    /**
     * The database backend is the safe default: every replica sees one cache
     * without extra infrastructure.
     */
    public const DEFAULT_CONFIGURATION = [
        'frontend' => VariableFrontend::class,
        'backend' => Typo3DatabaseBackend::class,
        'options' => ['defaultLifetime' => 86400],
    ];

    private ?FrontendInterface $frontend = null;

    /**
     * Takes the `SYS.caching` array and returns it with the metadata cache
     * configuration at its own key and removed from cacheConfigurations.
     * Defaults, the legacy cacheConfigurations entry and the new key are
     * merged in that order, so the new key wins field by field.
     *
     * @param array<array-key, mixed> $caching
     * @return array<array-key, mixed>
     */
    public static function relocate(array $caching): array
    {
        $legacy = [];
        if (is_array($caching['cacheConfigurations'] ?? null)) {
            $legacy = $caching['cacheConfigurations'][self::IDENTIFIER] ?? [];
            unset($caching['cacheConfigurations'][self::IDENTIFIER]);
        }
        $current = $caching[self::IDENTIFIER] ?? [];
        $merged = array_replace_recursive(
            self::DEFAULT_CONFIGURATION,
            is_array($legacy) ? $legacy : [],
            is_array($current) ? $current : [],
        );
        // Groups only mean something to the CacheManager.
        unset($merged['groups']);
        $caching[self::IDENTIFIER] = $merged;
        return $caching;
    }

    /**
     * The process-wide instance: one backend connection per process, as the
     * CacheManager would keep it.
     */
    public function frontend(): FrontendInterface
    {
        return $this->frontend ??= $this->create();
    }

    /**
     * Empties the shared cache for every replica.
     */
    public function flush(): void
    {
        $this->frontend()->flush();
    }

    /**
     * @return class-string
     */
    public function backendClassName(): string
    {
        $backend = $this->configuration()['backend'] ?? self::DEFAULT_CONFIGURATION['backend'];
        /** @var class-string $class */
        $class = ltrim(is_string($backend) ? $backend : self::DEFAULT_CONFIGURATION['backend'], '\\');
        return $class;
    }

    /**
     * SQL for the database backend's tables, or '' for any other backend.
     * Without a cacheConfigurations entry, TYPO3's own schema listener no
     * longer emits them; DatabaseSchemaListener does instead.
     */
    public function tableDefinitions(): string
    {
        if (!is_a($this->backendClassName(), Typo3DatabaseBackend::class, true)) {
            return '';
        }
        $backend = $this->create()->getBackend();
        return $backend instanceof Typo3DatabaseBackend ? $backend->getTableDefinitions() : '';
    }

    public function create(): FrontendInterface
    {
        return self::build($this->configuration());
    }

    /**
     * @param array<array-key, mixed> $configuration
     */
    public static function build(array $configuration): FrontendInterface
    {
        $frontendClass = is_string($configuration['frontend'] ?? null) ? $configuration['frontend'] : self::DEFAULT_CONFIGURATION['frontend'];
        $backendClass = is_string($configuration['backend'] ?? null) ? $configuration['backend'] : self::DEFAULT_CONFIGURATION['backend'];
        $options = is_array($configuration['options'] ?? null) ? $configuration['options'] : [];
        if (isset($options['compression']) && !is_bool($options['compression'])) {
            $options['compression'] = (bool)$options['compression'];
        }

        // Checked before construction, unlike the CacheManager: a class
        // that is no backend has no business receiving the options.
        $backendClass = ltrim($backendClass, '\\');
        if (!is_a($backendClass, BackendInterface::class, true)) {
            throw new InvalidBackendException('"' . $backendClass . '" is not a valid cache backend object.', 1762790401);
        }
        $backend = new $backendClass($options);
        if (is_callable([$backend, 'initializeObject'])) {
            $backend->initializeObject();
        }

        $frontendClass = ltrim($frontendClass, '\\');
        if (!is_a($frontendClass, FrontendInterface::class, true)) {
            throw new InvalidCacheException('"' . $frontendClass . '" is not a valid cache frontend object.', 1762790403);
        }
        $frontend = new $frontendClass(self::IDENTIFIER, $backend);
        if (is_callable([$frontend, 'initializeObject'])) {
            $frontend->initializeObject();
        }
        return $frontend;
    }

    /**
     * @return array<array-key, mixed>
     */
    protected function configuration(): array
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        $sys = is_array($confVars) ? ($confVars['SYS'] ?? null) : null;
        $caching = is_array($sys) ? ($sys['caching'] ?? null) : null;
        $configuration = is_array($caching) ? ($caching[self::IDENTIFIER] ?? null) : null;
        // ext_localconf.php relocates on every boot; this only covers code
        // that runs without it (unit tests, a broken extension list).
        if (!is_array($configuration)) {
            $relocated = self::relocate(is_array($caching) ? $caching : []);
            $configuration = $relocated[self::IDENTIFIER];
        }
        /** @var array<array-key, mixed> $configuration */
        return $configuration;
    }
}
