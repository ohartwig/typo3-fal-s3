<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Driver;

use Koh\FalS3\Driver\S3Driver;
use Koh\FalS3\Exception\ThreatsFoundException;
use Koh\FalS3\Http\S3ObjectStream;
use Koh\FalS3\Security\MalwareScanGate;
use Koh\FalS3\Tests\Functional\Support\S3TestBucket;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\TestingFramework\Core\Functional\FunctionalTestCase;

/**
 * The parts of the driver that sit outside the DriverInterface contract:
 * streaming for a non-public storage in both modes, and the GuardDuty gate.
 */
final class S3DriverStreamingAndGateTest extends FunctionalTestCase
{
    protected array $testExtensionsToLoad = ['koh/typo3-fal-s3'];

    private ?S3TestBucket $bucket = null;

    protected function setUp(): void
    {
        if (S3TestBucket::endpoint() === null) {
            self::markTestSkipped('KOH_FAL_S3_TEST_ENDPOINT is not set; no S3 server to test against.');
        }
        parent::setUp();
        $this->bucket = S3TestBucket::create();
    }

    protected function tearDown(): void
    {
        $this->bucket?->destroy();
        parent::tearDown();
    }

    #[Test]
    public function streamsThroughTheReadAndEmitApi(): void
    {
        $driver = $this->driver([]);
        $driver->addFile($this->localFile(str_repeat('abcdefghij', 1000)), '/', 'big.txt');

        $response = $driver->streamFile('/big.txt', []);
        $body = $response->getBody();
        self::assertSame(10000, $body->getSize());
        self::assertFalse($body->isSeekable());
        self::assertFalse($body->isWritable());
        self::assertTrue($body->isReadable());
        self::assertSame('abcde', $body->read(5));
        self::assertSame(5, $body->tell());
        self::assertFalse($body->eof());
        self::assertSame('fghij', $body->read(5));
        $rest = $body->getContents();
        self::assertSame(9990, strlen($rest));
        self::assertTrue($body->eof());
        self::assertStringStartsWith('inline; filename="big.txt"', $response->getHeaderLine('Content-Disposition'));
        self::assertSame('public, max-age=86400', $response->getHeaderLine('Cache-Control'));

        $emitting = $driver->streamFile('/big.txt', [])->getBody();
        self::assertInstanceOf(S3ObjectStream::class, $emitting);
        ob_start();
        $emitting->emit();
        $emitted = (string)ob_get_clean();
        self::assertSame(10000, strlen($emitted));

        ob_start();
        $driver->dumpFileContents('/big.txt');
        self::assertSame(10000, strlen((string)ob_get_clean()));
    }

    #[Test]
    public function redirectModeAnswersWithAPresignedUrl(): void
    {
        $driver = $this->driver(['streamMode' => 'redirect', 'presignLifetime' => '120']);
        $driver->addFile($this->localFile('redirect me'), '/', 'r.txt');

        $response = $driver->streamFile('/r.txt', ['as_download' => true]);
        self::assertSame(307, $response->getStatusCode());
        $location = $response->getHeaderLine('Location');
        self::assertStringContainsString($this->bucket()->name, $location);
        self::assertStringContainsString('r.txt', $location);
        self::assertStringContainsString('X-Amz-Signature=', $location);
        self::assertStringContainsString('X-Amz-Expires=120', $location);
        self::assertStringContainsString('response-content-disposition=attachment', $location);
        self::assertSame('private, no-store', $response->getHeaderLine('Cache-Control'));
    }

    #[Test]
    public function malwareGateHidesFlaggedObjectsOnly(): void
    {
        $driver = $this->driver(['malwareGate' => '1']);
        $driver->addFile($this->localFile('clean'), '/', 'clean.txt');
        $driver->addFile($this->localFile('evil'), '/', 'evil.txt');
        $bucket = $this->bucket();
        $bucket->client()->putObjectTagging([
            'Bucket' => $bucket->name,
            'Key' => 'evil.txt',
            'Tagging' => ['TagSet' => [['Key' => MalwareScanGate::TAG, 'Value' => MalwareScanGate::THREATS_FOUND]]],
        ])->resolve();
        $bucket->client()->putObjectTagging([
            'Bucket' => $bucket->name,
            'Key' => 'clean.txt',
            'Tagging' => ['TagSet' => [['Key' => MalwareScanGate::TAG, 'Value' => 'NO_THREATS_FOUND']]],
        ])->resolve();

        self::assertSame('/fileadmin/clean.txt', $driver->getPublicUrl('/clean.txt'));
        self::assertNull($driver->getPublicUrl('/evil.txt'));
        self::assertSame(403, $driver->streamFile('/evil.txt', [])->getStatusCode());
        self::assertSame(200, $driver->streamFile('/clean.txt', [])->getStatusCode());
        self::assertFileExists($driver->getFileForLocalProcessing('/clean.txt', false));

        $requests = $driver->getRequestCounter()->snapshot()['requests']['GetObjectTagging'] ?? 0;
        $driver->getPublicUrl('/evil.txt');
        $driver->getPublicUrl('/clean.txt');
        self::assertSame($requests, $driver->getRequestCounter()->snapshot()['requests']['GetObjectTagging'] ?? 0, 'verdicts are cached per object version');

        $this->expectException(ThreatsFoundException::class);
        $driver->getFileForLocalProcessing('/evil.txt', false);
    }

    #[Test]
    public function unscannedObjectsAreNotBlocked(): void
    {
        $driver = $this->driver(['malwareGate' => '1']);
        $driver->addFile($this->localFile('fresh'), '/', 'fresh.txt');
        self::assertSame('/fileadmin/fresh.txt', $driver->getPublicUrl('/fresh.txt'));
        self::assertSame(200, $driver->streamFile('/fresh.txt', [])->getStatusCode());
    }

    /**
     * @param array<string, string> $extra
     */
    private function driver(array $extra): S3Driver
    {
        $driver = new S3Driver($this->bucket()->driverConfiguration() + $extra);
        $driver->setStorageUid(1);
        $driver->mergeConfigurationCapabilities(new Capabilities(Capabilities::CAPABILITY_BROWSABLE | Capabilities::CAPABILITY_PUBLIC | Capabilities::CAPABILITY_WRITABLE | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS));
        $driver->processConfiguration();
        $driver->initialize();
        return $driver;
    }

    /**
     * @return non-empty-string
     */
    private function localFile(string $content): string
    {
        $path = GeneralUtility::tempnam('stream-', '.txt');
        file_put_contents($path, $content);
        return $path;
    }

    private function bucket(): S3TestBucket
    {
        self::assertNotNull($this->bucket);
        return $this->bucket;
    }
}
