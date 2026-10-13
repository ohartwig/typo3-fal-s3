<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Configuration;

use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;

/**
 * Resolves driver options with a fixed precedence, first non-null wins:
 *
 *  1. env KOH_FAL_S3_STORAGE<uid>_<KEY>      (one storage)
 *  2. env KOH_FAL_S3_<KEY>                   (all storages)
 *  3. TYPO3_CONF_VARS[EXTENSIONS][koh_fal_s3][storages][<uid>][key]
 *  4. TYPO3_CONF_VARS[EXTENSIONS][koh_fal_s3][key]
 *     (reachable as TYPO3__EXTENSIONS__koh_fal_s3__<key> through the config loader)
 *  5. the storage record's FlexForm
 *  6. the default
 *
 * So the bucket and endpoint can stay out of the database record and follow
 * the deployment, while an editor-facing FlexForm still works for a hand-made
 * storage.
 */
final class ConfigurationResolver
{
    public const EXTENSION_KEY = 'koh_fal_s3';

    private const ENV_PREFIX = 'KOH_FAL_S3_';

    /** @var list<string> */
    public const DEFAULT_EXCLUDED_PREFIXES = [
        '_typo3temp-assets/',
        'pos-exchange/',
        'malware-protection-resource-validation-object',
    ];

    public const STREAM_MODE_PROXY = 'proxy';
    public const STREAM_MODE_REDIRECT = 'redirect';

    /**
     * @param array<string, mixed> $flexFormConfiguration the driver configuration TYPO3 flattened from the record
     */
    public function resolve(array $flexFormConfiguration, int $storageUid): DriverConfiguration
    {
        $bucket = $this->string('bucket', $flexFormConfiguration, $storageUid, '');
        if ($bucket === '') {
            throw new InvalidConfigurationException(
                sprintf('Storage %d: no S3 bucket configured (FlexForm "bucket" or env KOH_FAL_S3_BUCKET).', $storageUid),
                1760400010,
            );
        }
        $region = $this->string('region', $flexFormConfiguration, $storageUid, '');
        if ($region === '') {
            $region = self::env('AWS_REGION') ?? self::env('AWS_DEFAULT_REGION') ?? 'eu-north-1';
        }

        $endpoint = $this->string('endpoint', $flexFormConfiguration, $storageUid, '');
        $publicBaseUri = $this->string('publicBaseUri', $flexFormConfiguration, $storageUid, '/fileadmin/');
        $streamMode = $this->string('streamMode', $flexFormConfiguration, $storageUid, self::STREAM_MODE_PROXY);
        if (!in_array($streamMode, [self::STREAM_MODE_PROXY, self::STREAM_MODE_REDIRECT], true)) {
            throw new InvalidConfigurationException(
                sprintf('Storage %d: streamMode must be "proxy" or "redirect", "%s" given.', $storageUid, $streamMode),
                1760400011,
            );
        }

        return new DriverConfiguration(
            bucket: $bucket,
            region: $region,
            endpoint: $endpoint === '' ? null : rtrim($endpoint, '/'),
            pathStyle: $this->bool('pathStyle', $flexFormConfiguration, $storageUid, false),
            dualstack: self::truthy(self::env('AWS_USE_DUALSTACK_ENDPOINT')),
            keyPrefix: self::normalisePrefix($this->string('keyPrefix', $flexFormConfiguration, $storageUid, '')),
            publicBaseUri: rtrim($publicBaseUri, '/') . '/',
            excludedPrefixes: $this->list('excludedPrefixes', $flexFormConfiguration, $storageUid, self::DEFAULT_EXCLUDED_PREFIXES),
            cacheControlDefault: $this->string('cacheControlDefault', $flexFormConfiguration, $storageUid, 'public, max-age=86400'),
            cacheControlProcessed: $this->string('cacheControlProcessed', $flexFormConfiguration, $storageUid, 'public, max-age=31536000, immutable'),
            conditionalWrites: $this->bool('conditionalWrites', $flexFormConfiguration, $storageUid, true),
            streamMode: $streamMode,
            presignLifetime: max(60, min(86400, $this->int('presignLifetime', $flexFormConfiguration, $storageUid, 900))),
            malwareGate: $this->bool('malwareGate', $flexFormConfiguration, $storageUid, false),
            maxFolderOperationObjects: max(1, $this->int('maxFolderOperationObjects', $flexFormConfiguration, $storageUid, 10000)),
            negativeCacheLifetime: max(0, $this->int('negativeCacheLifetime', $flexFormConfiguration, $storageUid, 60)),
            cacheLifetime: max(0, $this->int('cacheLifetime', $flexFormConfiguration, $storageUid, 86400)),
            caseSensitive: $this->bool('caseSensitive', $flexFormConfiguration, $storageUid, true),
        );
    }

    /**
     * Extension-wide options that do not belong to one storage, such as the
     * processing folder the fallback storage (uid 0) should use.
     */
    public function resolveGlobal(string $key): ?string
    {
        $value = self::env(self::ENV_PREFIX . self::envName($key));
        if ($value === null) {
            $value = self::confVar($key);
        }
        if ($value === null || trim($value) === '') {
            return null;
        }
        return trim($value);
    }

    /**
     * @param array<string, mixed> $flexForm
     */
    private function raw(string $key, array $flexForm, int $storageUid): ?string
    {
        $candidates = [
            self::env(self::ENV_PREFIX . 'STORAGE' . $storageUid . '_' . self::envName($key)),
            self::env(self::ENV_PREFIX . self::envName($key)),
            self::confVar('storages', (string)$storageUid, $key),
            self::confVar($key),
        ];
        foreach ($candidates as $candidate) {
            if ($candidate !== null && trim($candidate) !== '') {
                return trim($candidate);
            }
        }
        $fromFlexForm = $flexForm[$key] ?? null;
        if (is_scalar($fromFlexForm) && trim((string)$fromFlexForm) !== '') {
            return trim((string)$fromFlexForm);
        }
        return null;
    }

    /**
     * @param array<string, mixed> $flexForm
     */
    private function string(string $key, array $flexForm, int $storageUid, string $default): string
    {
        return $this->raw($key, $flexForm, $storageUid) ?? $default;
    }

    /**
     * @param array<string, mixed> $flexForm
     */
    private function int(string $key, array $flexForm, int $storageUid, int $default): int
    {
        $raw = $this->raw($key, $flexForm, $storageUid);
        if ($raw === null || !is_numeric($raw)) {
            return $default;
        }
        return (int)$raw;
    }

    /**
     * @param array<string, mixed> $flexForm
     */
    private function bool(string $key, array $flexForm, int $storageUid, bool $default): bool
    {
        $raw = $this->raw($key, $flexForm, $storageUid);
        if ($raw === null) {
            // A FlexForm checkbox stores "0" for unchecked, which raw() treats as a value.
            return $default;
        }
        return self::truthy($raw);
    }

    /**
     * @param array<string, mixed> $flexForm
     * @param list<string> $default
     * @return list<string>
     */
    private function list(string $key, array $flexForm, int $storageUid, array $default): array
    {
        $raw = $this->raw($key, $flexForm, $storageUid);
        if ($raw === null) {
            return $default;
        }
        $items = preg_split('/[\r\n,]+/', $raw) ?: [];
        $items = array_values(array_filter(array_map('trim', $items), static fn(string $item): bool => $item !== ''));
        return array_map(static fn(string $item): string => ltrim($item, '/'), $items);
    }

    public static function truthy(?string $value): bool
    {
        if ($value === null) {
            return false;
        }
        return in_array(strtolower(trim($value)), ['1', 'true', 'yes', 'on'], true);
    }

    public static function normalisePrefix(string $prefix): string
    {
        $prefix = trim($prefix, '/');
        return $prefix === '' ? '' : $prefix . '/';
    }

    private static function envName(string $key): string
    {
        return strtoupper((string)preg_replace('/(?<!^)[A-Z]/', '_$0', $key));
    }

    private static function env(string $name): ?string
    {
        $value = getenv($name);
        if ($value === false) {
            $value = $_ENV[$name] ?? $_SERVER[$name] ?? null;
        }
        return is_string($value) ? $value : null;
    }

    private static function confVar(string ...$path): ?string
    {
        $confVars = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
        if (!is_array($confVars) || !is_array($confVars['EXTENSIONS'] ?? null)) {
            return null;
        }
        $node = $confVars['EXTENSIONS'][self::EXTENSION_KEY] ?? null;
        foreach ($path as $segment) {
            if (!is_array($node) || !array_key_exists($segment, $node)) {
                return null;
            }
            $node = $node[$segment];
        }
        if (is_bool($node)) {
            return $node ? '1' : '0';
        }
        return is_scalar($node) ? (string)$node : null;
    }
}
