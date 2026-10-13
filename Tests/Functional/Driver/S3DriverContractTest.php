<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Driver;

use Koh\FalS3\Driver\S3Driver;
use Koh\FalS3\Exception\ExcludedPrefixException;
use Koh\FalS3\Tests\Functional\Support\S3TestBucket;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\CMS\Core\Resource\Capabilities;
use TYPO3\CMS\Core\Resource\Driver\DriverInterface;
use TYPO3\CMS\Core\Resource\Driver\LocalDriver;
use TYPO3\CMS\Core\Resource\Exception\ExistingTargetFileNameException;

final class S3DriverContractTest extends DriverContractTestCase
{
    private ?S3TestBucket $bucket = null;

    protected function setUp(): void
    {
        if (S3TestBucket::endpoint() === null) {
            self::markTestSkipped('KOH_FAL_S3_TEST_ENDPOINT is not set; no S3 server to test against.');
        }
        parent::setUp();
    }

    protected function tearDown(): void
    {
        $this->bucket?->destroy();
        $this->bucket = null;
        parent::tearDown();
    }

    protected function createDriver(): DriverInterface
    {
        $this->bucket = S3TestBucket::create();
        return $this->newS3Driver();
    }

    protected function expectedPublicUrl(string $identifier): string
    {
        return '/fileadmin' . $identifier;
    }

    #[Test]
    public function addingOverAnExistingFileIsRefusedByTheConditionalWrite(): void
    {
        $this->driver->addFile($this->localFile('first', 'txt'), '/', 'same.txt');
        $this->expectException(ExistingTargetFileNameException::class);
        $this->driver->addFile($this->localFile('second', 'txt'), '/', 'same.txt');
    }

    #[Test]
    public function processedFileRacesAreNotErrors(): void
    {
        $this->driver->createFolder('_processed_', '/');
        $other = $this->newS3Driver();
        $a = $this->driver->addFile($this->localFile('variant', 'jpg'), '/_processed_/', 'csm_x.jpg');
        $b = $other->addFile($this->localFile('variant', 'jpg'), '/_processed_/', 'csm_x.jpg');
        self::assertSame($a, $b);
        self::assertSame('variant', $this->driver->getFileContents('/_processed_/csm_x.jpg'));
    }

    #[Test]
    public function identicalReplaceWritesNoNewObject(): void
    {
        $this->driver->addFile($this->localFile('same bytes', 'txt'), '/', 'idem.txt');
        $before = $this->driver->getFileInfoByIdentifier('/idem.txt')['mtime'];
        $counter = $this->s3Driver()->getRequestCounter();
        $putsBefore = $counter->snapshot()['requests']['PutObject'] ?? 0;
        $this->driver->replaceFile('/idem.txt', $this->localFile('same bytes', 'txt'));
        self::assertSame($putsBefore, $counter->snapshot()['requests']['PutObject'] ?? 0, 'no PUT for identical content');
        self::assertSame($before, $this->driver->getFileInfoByIdentifier('/idem.txt')['mtime']);
    }

    #[Test]
    public function excludedPrefixesAreInvisibleAndReadOnly(): void
    {
        $bucket = $this->bucket();
        $bucket->client()->putObject(['Bucket' => $bucket->name, 'Key' => '_typo3temp-assets/x.css', 'Body' => 'css'])->resolve();
        $bucket->client()->putObject(['Bucket' => $bucket->name, 'Key' => 'pos-exchange/in/1.csv', 'Body' => 'csv'])->resolve();
        $bucket->client()->putObject(['Bucket' => $bucket->name, 'Key' => 'malware-protection-resource-validation-object', 'Body' => ''])->resolve();
        $this->driver->addFile($this->localFile('mine', 'txt'), '/', 'mine.txt');

        self::assertSame(['/mine.txt'], self::values($this->driver->getFilesInFolder('/')));
        self::assertSame([], self::values($this->driver->getFoldersInFolder('/')));
        self::assertFalse($this->driver->fileExists('/_typo3temp-assets/x.css'));
        self::assertFalse($this->driver->folderExists('/pos-exchange/'));
        self::assertNull($this->driver->getPublicUrl('/pos-exchange/in/1.csv'));
        self::assertSame('', $this->driver->getFileContents('/_typo3temp-assets/x.css'));

        $this->expectException(ExcludedPrefixException::class);
        $this->driver->addFile($this->localFile('x', 'css'), '/_typo3temp-assets/', 'y.css');
    }

    #[Test]
    public function objectsWrittenByOthersAreVisibleWithTheirKeysAsIdentifiers(): void
    {
        $bucket = $this->bucket();
        $bucket->client()->putObject(['Bucket' => $bucket->name, 'Key' => 'user_upload/legacy/photo.jpg', 'Body' => str_repeat('x', 100)])->resolve();
        $bucket->client()->putObject(['Bucket' => $bucket->name, 'Key' => 'user_upload/marker/', 'Body' => ''])->resolve();

        self::assertTrue($this->driver->fileExists('/user_upload/legacy/photo.jpg'));
        self::assertTrue($this->driver->folderExists('/user_upload/legacy/'), 'implied by its content');
        self::assertTrue($this->driver->folderExists('/user_upload/marker/'), 'explicit marker');
        self::assertSame(['/user_upload/legacy/', '/user_upload/marker/'], self::values($this->driver->getFoldersInFolder('/user_upload/')));
        self::assertSame(100, $this->driver->getFileInfoByIdentifier('/user_upload/legacy/photo.jpg')['size']);
        self::assertSame('image/jpeg', $this->driver->getFileInfoByIdentifier('/user_upload/legacy/photo.jpg')['mimetype'], 'no Content-Type on the object: derived from the extension');
    }

    #[Test]
    public function writesCarryContentTypeAndCacheControl(): void
    {
        $this->driver->createFolder('_processed_', '/');
        $this->driver->addFile($this->localFile('img', 'png'), '/_processed_/', 'csm_a.png');
        $this->driver->addFile($this->localFile('doc', 'pdf'), '/', 'a.pdf');
        $bucket = $this->bucket();

        $processed = $bucket->client()->headObject(['Bucket' => $bucket->name, 'Key' => '_processed_/csm_a.png']);
        self::assertSame('image/png', $processed->getContentType());
        self::assertSame('public, max-age=31536000, immutable', $processed->getCacheControl());
        $original = $bucket->client()->headObject(['Bucket' => $bucket->name, 'Key' => 'a.pdf']);
        self::assertSame('application/pdf', $original->getContentType());
        self::assertSame('public, max-age=86400', $original->getCacheControl());
    }

    #[Test]
    public function streamsAFileWithHeaders(): void
    {
        $this->driver->addFile($this->localFile('stream me', 'txt'), '/', 's.txt');
        $response = $this->s3Driver()->streamFile('/s.txt', ['as_download' => true, 'filename_overwrite' => 'dl.txt']);
        self::assertSame(200, $response->getStatusCode());
        self::assertSame('text/plain', $response->getHeaderLine('Content-Type'));
        self::assertSame('9', $response->getHeaderLine('Content-Length'));
        self::assertStringStartsWith('attachment; filename="dl.txt"', $response->getHeaderLine('Content-Disposition'));
        self::assertNotSame('', $response->getHeaderLine('Last-Modified'));
        self::assertSame('stream me', $response->getBody()->getContents());

        $missing = $this->s3Driver()->streamFile('/missing.txt', []);
        self::assertSame(404, $missing->getStatusCode());
    }

    #[Test]
    public function fileNameSanitisingMatchesLocalDriver(): void
    {
        $local = new LocalDriver();
        foreach (['Ärger & Freude.JPG', 'a  b.txt', 'x/y.txt', 'trailing...', 'ünïcödé.png', 'tab	name.txt'] as $name) {
            self::assertSame($local->sanitizeFileName($name), $this->driver->sanitizeFileName($name), $name);
        }
    }

    private function newS3Driver(): S3Driver
    {
        $driver = new S3Driver($this->bucket()->driverConfiguration());
        $driver->setStorageUid(1);
        $driver->mergeConfigurationCapabilities(new Capabilities(Capabilities::CAPABILITY_BROWSABLE | Capabilities::CAPABILITY_PUBLIC | Capabilities::CAPABILITY_WRITABLE | Capabilities::CAPABILITY_HIERARCHICAL_IDENTIFIERS));
        $driver->processConfiguration();
        $driver->initialize();
        return $driver;
    }

    private function s3Driver(): S3Driver
    {
        self::assertInstanceOf(S3Driver::class, $this->driver);
        return $this->driver;
    }

    private function bucket(): S3TestBucket
    {
        self::assertNotNull($this->bucket);
        return $this->bucket;
    }
}
