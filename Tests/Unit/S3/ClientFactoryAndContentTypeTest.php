<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\S3;

use Koh\FalS3\Configuration\DriverConfiguration;
use Koh\FalS3\S3\ClientFactory;
use Koh\FalS3\S3\ContentTypeResolver;
use PHPUnit\Framework\Attributes\Test;
use Psr\Log\NullLogger;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class ClientFactoryAndContentTypeTest extends UnitTestCase
{
    protected bool $resetSingletonInstances = true;

    /** @var list<string> */
    private array $envSet = [];

    protected function tearDown(): void
    {
        foreach ($this->envSet as $name) {
            putenv($name);
        }
        parent::tearDown();
    }

    #[Test]
    public function endpointPrecedence(): void
    {
        self::assertSame('http://minio:9000', ClientFactory::endpointFor($this->configuration(endpoint: 'http://minio:9000', dualstack: true)));
        self::assertNull(ClientFactory::endpointFor($this->configuration()));
        self::assertSame('https://s3.dualstack.eu-north-1.amazonaws.com', ClientFactory::endpointFor($this->configuration(dualstack: true)));
        $this->env('AWS_ENDPOINT_URL', 'https://gateway.example/');
        self::assertSame('https://gateway.example', ClientFactory::endpointFor($this->configuration(dualstack: true)), 'AWS_ENDPOINT_URL beats dualstack');
        $this->env('AWS_ENDPOINT_URL_S3', 'https://s3.example');
        self::assertSame('https://s3.example', ClientFactory::endpointFor($this->configuration()), 'the service-specific variable wins');
    }

    #[Test]
    public function clientsAreMemoisedPerEndpointRegionAndStyle(): void
    {
        $factory = new ClientFactory();
        $a = $factory->create($this->configuration(), new NullLogger());
        $b = $factory->create($this->configuration(), new NullLogger());
        $c = $factory->create($this->configuration(pathStyle: true), new NullLogger());
        self::assertSame($a, $b);
        self::assertNotSame($a, $c);
    }

    #[Test]
    public function contentTypeComesFromTheExtensionThenFinfoThenFallback(): void
    {
        $resolver = new ContentTypeResolver();
        self::assertSame('image/jpeg', $resolver->forFile('/user_upload/Photo.JPG'));
        self::assertSame('application/pdf', $resolver->forFile('doc.pdf'));
        $local = tempnam(sys_get_temp_dir(), 'ct');
        self::assertNotFalse($local);
        file_put_contents($local, "%PDF-1.4\n%");
        self::assertSame('application/pdf', $resolver->forFile('no-extension', $local), 'finfo on the local file');
        self::assertSame(ContentTypeResolver::FALLBACK, $resolver->forFile('no-extension-at-all'));
        self::assertSame(ContentTypeResolver::FALLBACK, $resolver->forFile('x.unknownextension'));
        unlink($local);
    }

    private function configuration(?string $endpoint = null, bool $pathStyle = false, bool $dualstack = false): DriverConfiguration
    {
        return new DriverConfiguration(
            bucket: 'b',
            region: 'eu-north-1',
            endpoint: $endpoint,
            pathStyle: $pathStyle,
            dualstack: $dualstack,
            keyPrefix: '',
            publicBaseUri: '/fileadmin/',
            excludedPrefixes: [],
            cacheControlDefault: 'public',
            cacheControlProcessed: 'public, immutable',
            conditionalWrites: true,
            streamMode: 'proxy',
            presignLifetime: 900,
            malwareGate: false,
            maxFolderOperationObjects: 100,
            negativeCacheLifetime: 60,
            cacheLifetime: 3600,
            caseSensitive: true,
        );
    }

    private function env(string $name, string $value): void
    {
        putenv($name . '=' . $value);
        $this->envSet[] = $name;
    }
}
