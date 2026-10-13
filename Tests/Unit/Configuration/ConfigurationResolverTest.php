<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\Configuration;

use Koh\FalS3\Configuration\ConfigurationResolver;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Exception\InvalidConfigurationException;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ConfigurationResolverTest extends UnitTestCase
{
    protected bool $backupEnvironment = true;

    /** @var list<string> */
    private array $envSet = [];
    private mixed $confVarsBackup = null;

    protected function setUp(): void
    {
        parent::setUp();
        $this->confVarsBackup = $GLOBALS['TYPO3_CONF_VARS'] ?? null;
    }

    protected function tearDown(): void
    {
        foreach ($this->envSet as $name) {
            putenv($name);
            unset($_ENV[$name], $_SERVER[$name]);
        }
        $GLOBALS['TYPO3_CONF_VARS'] = $this->confVarsBackup;
        parent::tearDown();
    }

    /**
     * @param array<string, mixed> $extensionConfiguration
     */
    private function confVars(array $extensionConfiguration): void
    {
        $GLOBALS['TYPO3_CONF_VARS'] = ['EXTENSIONS' => ['koh_fal_s3' => $extensionConfiguration]];
    }

    #[Test]
    public function flexFormValuesAndDefaultsApply(): void
    {
        $this->confVars([]);
        $this->env('AWS_REGION', 'eu-west-1');
        $configuration = (new ConfigurationResolver())->resolve(['bucket' => 'media', 'pathStyle' => '0', 'excludedPrefixes' => "a/\r\nb/"], 1);

        self::assertSame('media', $configuration->bucket);
        self::assertSame('eu-west-1', $configuration->region);
        self::assertNull($configuration->endpoint);
        self::assertFalse($configuration->pathStyle);
        self::assertSame('/fileadmin/', $configuration->publicBaseUri);
        self::assertSame(['a/', 'b/'], $configuration->excludedPrefixes);
        self::assertTrue($configuration->conditionalWrites);
        self::assertSame('proxy', $configuration->streamMode);
        self::assertSame(900, $configuration->presignLifetime);
        self::assertSame(10000, $configuration->maxFolderOperationObjects);
        self::assertSame('', $configuration->keyPrefix);
    }

    #[Test]
    public function environmentBeatsConfVarsBeatsFlexForm(): void
    {
        $this->confVars([
            'bucket' => 'from-confvars',
            'region' => 'from-confvars',
            'storages' => ['7' => ['region' => 'from-storage-confvars']],
        ]);
        $this->env('KOH_FAL_S3_BUCKET', 'from-env');
        $this->env('KOH_FAL_S3_STORAGE7_KEY_PREFIX', 'tenant');
        $this->env('KOH_FAL_S3_PUBLIC_BASE_URI', '/media');

        $configuration = (new ConfigurationResolver())->resolve(['bucket' => 'from-flexform', 'region' => 'from-flexform', 'keyPrefix' => 'ff'], 7);

        self::assertSame('from-env', $configuration->bucket);
        self::assertSame('from-storage-confvars', $configuration->region);
        self::assertSame('tenant/', $configuration->keyPrefix, 'per-storage env wins and prefixes are normalised');
        self::assertSame('/media/', $configuration->publicBaseUri);
    }

    #[Test]
    public function dualstackFollowsTheAwsEnvironmentFlag(): void
    {
        $this->env('AWS_USE_DUALSTACK_ENDPOINT', 'true');
        self::assertTrue((new ConfigurationResolver())->resolve(['bucket' => 'b'], 1)->dualstack);
    }

    #[Test]
    public function missingBucketIsAConfigurationError(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new ConfigurationResolver())->resolve([], 1);
    }

    #[Test]
    public function unknownStreamModeIsAConfigurationError(): void
    {
        $this->expectException(InvalidConfigurationException::class);
        (new ConfigurationResolver())->resolve(['bucket' => 'b', 'streamMode' => 'teleport'], 1);
    }

    #[Test]
    public function globalOptionsResolveFromEnvThenConfVars(): void
    {
        $resolver = new ConfigurationResolver();
        $this->confVars([]);
        self::assertNull($resolver->resolveGlobal('fallbackProcessingFolder'));
        $this->confVars(['fallbackProcessingFolder' => '1:/_processed_/_fallback_/']);
        self::assertSame('1:/_processed_/_fallback_/', $resolver->resolveGlobal('fallbackProcessingFolder'));
        $this->env('KOH_FAL_S3_FALLBACK_PROCESSING_FOLDER', '2:/p/');
        self::assertSame('2:/p/', $resolver->resolveGlobal('fallbackProcessingFolder'));
    }

    #[Test]
    public function booleanAndListParsing(): void
    {
        self::assertTrue(ConfigurationResolver::truthy('yes'));
        self::assertTrue(ConfigurationResolver::truthy('1'));
        self::assertFalse(ConfigurationResolver::truthy('0'));
        self::assertFalse(ConfigurationResolver::truthy(null));
        self::assertSame('a/b/', ConfigurationResolver::normalisePrefix('/a/b'));
        self::assertSame('', ConfigurationResolver::normalisePrefix('/'));
    }

    private function env(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->envSet[] = $name;
    }
}
