<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\S3;

use AsyncAws\Core\Exception\Exception as AsyncAwsException;
use AsyncAws\Core\Exception\Http\ClientException;
use AsyncAws\Core\Exception\Http\HttpException;
use AsyncAws\S3\Exception\InvalidObjectStateException;
use AsyncAws\S3\Exception\NoSuchKeyException;
use AsyncAws\S3\Input\GetObjectRequest;
use AsyncAws\S3\Result\GetObjectOutput;
use AsyncAws\S3\Result\HeadObjectOutput;
use AsyncAws\S3\ValueObject\AwsObject;
use AsyncAws\SimpleS3\SimpleS3Client;
use Koh\FalS3\Exception\ObjectArchivedException;
use Koh\FalS3\Exception\PreconditionFailedException;
use Koh\FalS3\Exception\S3OperationFailedException;
use Koh\FalS3\Metrics\RequestCounter;
use Psr\Log\LoggerInterface;
use TYPO3\CMS\Core\Resource\Exception\FileDoesNotExistException;

/**
 * The only class that talks to S3. Every method counts its request, maps the
 * SDK's exceptions to the driver's, and never swallows one: a missing object
 * is a value (ObjectMetadata::missing()), everything else is an exception
 * with the operation, the key and the HTTP status in it.
 */
class ObjectStore
{
    private const SMALL_UPLOAD_LIMIT = 64 * 1024 * 1024;
    private const MULTIPART_COPY_THRESHOLD = 5 * 1024 * 1024 * 1024;
    private const MULTIPART_COPY_PART_SIZE = 512 * 1024 * 1024;
    private const DELETE_BATCH = 1000;

    public function __construct(
        private readonly SimpleS3Client $client,
        private readonly string $bucket,
        private readonly RequestCounter $counter,
        private readonly LoggerInterface $logger,
    ) {}

    public function bucket(): string
    {
        return $this->bucket;
    }

    public function head(string $key): ObjectMetadata
    {
        $this->counter->request('HeadObject');
        try {
            $output = $this->client->headObject(['Bucket' => $this->bucket, 'Key' => $key]);
            $output->resolve();
            return self::fromHead($output);
        } catch (NoSuchKeyException) {
            return ObjectMetadata::missing();
        } catch (AsyncAwsException $e) {
            $this->fail('HeadObject', $key, $e);
        }
    }

    /**
     * @return array{objects: list<array{key: string, size: int, mtime: int, etag: ?string, storageClass: ?string}>, prefixes: list<string>}
     */
    public function list(string $prefix, bool $recursive): array
    {
        $this->counter->request('ListObjectsV2');
        $input = ['Bucket' => $this->bucket, 'Prefix' => $prefix];
        if (!$recursive) {
            $input['Delimiter'] = '/';
        }
        $objects = [];
        $prefixes = [];
        try {
            $output = $this->client->listObjectsV2($input);
            foreach ($output as $item) {
                if ($item instanceof AwsObject) {
                    $objects[] = self::fromListEntry($item);
                } elseif ($item->getPrefix() !== null) {
                    $prefixes[] = $item->getPrefix();
                }
            }
        } catch (AsyncAwsException $e) {
            $this->fail('ListObjectsV2', $prefix, $e);
        }
        return ['objects' => $objects, 'prefixes' => $prefixes];
    }

    /**
     * One request, one key at most: is there anything at all under the prefix
     * (a marker, an object, or an object in a sub-prefix)?
     */
    public function prefixHasAnything(string $prefix): bool
    {
        $this->counter->request('ListObjectsV2');
        try {
            $output = $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $prefix, 'MaxKeys' => 1]);
            foreach ($output->getContents(true) as $ignored) {
                return true;
            }
            return false;
        } catch (AsyncAwsException $e) {
            $this->fail('ListObjectsV2', $prefix, $e);
        }
    }

    /**
     * Empty means: nothing but the folder's own marker directly below it.
     */
    public function isPrefixEmpty(string $prefix): bool
    {
        $this->counter->request('ListObjectsV2');
        try {
            $output = $this->client->listObjectsV2(['Bucket' => $this->bucket, 'Prefix' => $prefix, 'Delimiter' => '/', 'MaxKeys' => 2]);
            foreach ($output->getContents(true) as $object) {
                if ($object->getKey() !== $prefix) {
                    return false;
                }
            }
            foreach ($output->getCommonPrefixes(true) as $ignored) {
                return false;
            }
            return true;
        } catch (AsyncAwsException $e) {
            $this->fail('ListObjectsV2', $prefix, $e);
        }
    }

    /**
     * Headers are resolved, the body is still a stream.
     */
    public function get(string $key, ?string $range = null): GetObjectOutput
    {
        $this->counter->request('GetObject');
        $input = ['Bucket' => $this->bucket, 'Key' => $key];
        if ($range !== null) {
            $input['Range'] = $range;
        }
        try {
            $output = $this->client->getObject($input);
            $output->resolve();
            return $output;
        } catch (NoSuchKeyException $e) {
            throw new FileDoesNotExistException(sprintf('Object "%s" does not exist.', $key), 1760400020, $e);
        } catch (InvalidObjectStateException $e) {
            $this->logger->warning('S3 object is archived and cannot be read', ['key' => $key, 'bucket' => $this->bucket]);
            throw new ObjectArchivedException($key, null, $e);
        } catch (AsyncAwsException $e) {
            $this->fail('GetObject', $key, $e);
        }
    }

    public function downloadTo(string $key, string $localPath): ObjectMetadata
    {
        $output = $this->get($key);
        $handle = fopen($localPath, 'wb');
        if ($handle === false) {
            throw new S3OperationFailedException(sprintf('Cannot open "%s" for writing.', $localPath), 1760400021, 'GetObject', $key);
        }
        try {
            foreach ($output->getBody()->getChunks() as $chunk) {
                fwrite($handle, $chunk);
            }
        } catch (AsyncAwsException $e) {
            fclose($handle);
            $this->fail('GetObject', $key, $e);
        }
        fclose($handle);
        return new ObjectMetadata(
            true,
            $output->getContentLength() ?? (int)filesize($localPath),
            $output->getLastModified()?->getTimestamp() ?? time(),
            $output->getEtag(),
            $output->getContentType(),
            $output->getStorageClass(),
            false,
        );
    }

    public function upload(string $key, string $localPath, UploadOptions $options): ObjectMetadata
    {
        $size = filesize($localPath);
        if ($size === false) {
            throw new S3OperationFailedException(sprintf('Local file "%s" is not readable.', $localPath), 1760400022, 'PutObject', $key);
        }
        $handle = fopen($localPath, 'rb');
        if ($handle === false) {
            throw new S3OperationFailedException(sprintf('Local file "%s" cannot be opened.', $localPath), 1760400023, 'PutObject', $key);
        }
        try {
            if ($size < self::SMALL_UPLOAD_LIMIT) {
                $this->counter->request('PutObject');
                $input = [
                    'Bucket' => $this->bucket,
                    'Key' => $key,
                    'Body' => $handle,
                    'ContentLength' => $size,
                    'ContentType' => $options->contentType,
                    'CacheControl' => $options->cacheControl,
                ];
                if ($options->ifNoneMatch !== null) {
                    $input['IfNoneMatch'] = $options->ifNoneMatch;
                }
                $output = $this->client->putObject($input);
                $output->resolve();
                $etag = $output->getEtag();
            } else {
                // SimpleS3Client::upload() knows no If-None-Match; a multipart-sized
                // object is never a processed file, so the race it guards does not arise.
                $this->counter->request('MultipartUpload');
                $this->client->upload($this->bucket, $key, $handle, [
                    'ContentType' => $options->contentType,
                    'CacheControl' => $options->cacheControl,
                    'ContentLength' => $size,
                ]);
                $etag = $this->head($key)->etag;
            }
        } catch (AsyncAwsException $e) {
            fclose($handle);
            $this->failWrite('PutObject', $key, $e);
        }
        fclose($handle);
        return new ObjectMetadata(true, $size, time(), $etag, $options->contentType, null, false);
    }

    public function putString(string $key, string $body, UploadOptions $options): ObjectMetadata
    {
        $this->counter->request('PutObject');
        try {
            $input = [
                'Bucket' => $this->bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentLength' => strlen($body),
                'ContentType' => $options->contentType,
                'CacheControl' => $options->cacheControl,
            ];
            if ($options->ifNoneMatch !== null) {
                $input['IfNoneMatch'] = $options->ifNoneMatch;
            }
            $output = $this->client->putObject($input);
            $output->resolve();
            $etag = $output->getEtag();
        } catch (AsyncAwsException $e) {
            $this->failWrite('PutObject', $key, $e);
        }
        return new ObjectMetadata(true, strlen($body), time(), $etag, $options->contentType, null, false);
    }

    /**
     * Server-side copy with replaced metadata (Content-Type and Cache-Control
     * are recomputed for the destination; objects the FUSE mount wrote carry
     * neither). Above 5 GiB S3 requires multipart copy.
     */
    public function copy(string $sourceKey, string $targetKey, int $size, UploadOptions $options): ObjectMetadata
    {
        if ($size > self::MULTIPART_COPY_THRESHOLD) {
            return $this->multipartCopy($sourceKey, $targetKey, $size, $options);
        }
        $this->counter->request('CopyObject');
        try {
            $output = $this->client->copyObject([
                'Bucket' => $this->bucket,
                'Key' => $targetKey,
                'CopySource' => $this->copySource($sourceKey),
                'MetadataDirective' => 'REPLACE',
                'ContentType' => $options->contentType,
                'CacheControl' => $options->cacheControl,
            ]);
            $output->resolve();
            $result = $output->getCopyObjectResult();
            return new ObjectMetadata(
                true,
                $size,
                $result?->getLastModified()?->getTimestamp() ?? time(),
                $result?->getEtag(),
                $options->contentType,
                null,
                false,
            );
        } catch (NoSuchKeyException $e) {
            throw new FileDoesNotExistException(sprintf('Object "%s" does not exist.', $sourceKey), 1760400024, $e);
        } catch (InvalidObjectStateException $e) {
            throw new ObjectArchivedException($sourceKey, null, $e);
        } catch (AsyncAwsException $e) {
            $this->fail('CopyObject', $sourceKey . ' -> ' . $targetKey, $e);
        }
    }

    public function delete(string $key): void
    {
        $this->counter->request('DeleteObject');
        try {
            $this->client->deleteObject(['Bucket' => $this->bucket, 'Key' => $key])->resolve();
        } catch (NoSuchKeyException) {
            // Deleting what is not there is the outcome we wanted.
        } catch (AsyncAwsException $e) {
            $this->fail('DeleteObject', $key, $e);
        }
    }

    /**
     * @param list<string> $keys
     */
    public function deleteMany(array $keys): void
    {
        foreach (array_chunk($keys, self::DELETE_BATCH) as $batch) {
            $this->counter->request('DeleteObjects');
            try {
                $output = $this->client->deleteObjects([
                    'Bucket' => $this->bucket,
                    'Delete' => [
                        'Objects' => array_map(static fn(string $key): array => ['Key' => $key], $batch),
                        'Quiet' => true,
                    ],
                ]);
                $output->resolve();
                $errors = $output->getErrors();
                if ($errors !== []) {
                    $first = $errors[0];
                    throw new S3OperationFailedException(
                        sprintf('%d of %d objects could not be deleted; first: "%s" (%s: %s).', count($errors), count($batch), $first->getKey() ?? '?', $first->getCode() ?? '?', $first->getMessage() ?? ''),
                        1760400025,
                        'DeleteObjects',
                        $first->getKey() ?? '',
                    );
                }
            } catch (AsyncAwsException $e) {
                $this->fail('DeleteObjects', $batch[0], $e);
            }
        }
    }

    /**
     * @return array<string, string>
     */
    public function tags(string $key): array
    {
        $this->counter->request('GetObjectTagging');
        try {
            $output = $this->client->getObjectTagging(['Bucket' => $this->bucket, 'Key' => $key]);
            $output->resolve();
            $tags = [];
            foreach ($output->getTagSet() as $tag) {
                $tags[$tag->getKey()] = $tag->getValue();
            }
            return $tags;
        } catch (NoSuchKeyException) {
            return [];
        } catch (AsyncAwsException $e) {
            $this->fail('GetObjectTagging', $key, $e);
        }
    }

    public function presign(string $key, \DateTimeImmutable $expires, ?string $contentDisposition = null, ?string $contentType = null): string
    {
        $input = ['Bucket' => $this->bucket, 'Key' => $key];
        if ($contentDisposition !== null) {
            $input['ResponseContentDisposition'] = $contentDisposition;
        }
        if ($contentType !== null) {
            $input['ResponseContentType'] = $contentType;
        }
        return $this->client->presign(new GetObjectRequest($input), $expires);
    }

    private function multipartCopy(string $sourceKey, string $targetKey, int $size, UploadOptions $options): ObjectMetadata
    {
        $this->counter->request('CreateMultipartUpload');
        $uploadId = null;
        try {
            $created = $this->client->createMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $targetKey,
                'ContentType' => $options->contentType,
                'CacheControl' => $options->cacheControl,
            ]);
            $created->resolve();
            $uploadId = (string)$created->getUploadId();
            $parts = [];
            $partNumber = 1;
            for ($start = 0; $start < $size; $start += self::MULTIPART_COPY_PART_SIZE) {
                $end = min($size - 1, $start + self::MULTIPART_COPY_PART_SIZE - 1);
                $this->counter->request('UploadPartCopy');
                $part = $this->client->uploadPartCopy([
                    'Bucket' => $this->bucket,
                    'Key' => $targetKey,
                    'UploadId' => $uploadId,
                    'PartNumber' => $partNumber,
                    'CopySource' => $this->copySource($sourceKey),
                    'CopySourceRange' => sprintf('bytes=%d-%d', $start, $end),
                ]);
                $part->resolve();
                $parts[] = ['ETag' => (string)$part->getCopyPartResult()?->getEtag(), 'PartNumber' => $partNumber];
                $partNumber++;
            }
            $this->counter->request('CompleteMultipartUpload');
            $this->client->completeMultipartUpload([
                'Bucket' => $this->bucket,
                'Key' => $targetKey,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => $parts],
            ])->resolve();
        } catch (AsyncAwsException $e) {
            if ($uploadId !== null) {
                try {
                    $this->counter->request('AbortMultipartUpload');
                    $this->client->abortMultipartUpload(['Bucket' => $this->bucket, 'Key' => $targetKey, 'UploadId' => $uploadId])->resolve();
                } catch (AsyncAwsException $abortFailure) {
                    $this->logger->error('Aborting a failed multipart copy failed too; the bucket lifecycle cleans it up', ['key' => $targetKey, 'error' => $abortFailure->getMessage()]);
                }
            }
            $this->fail('UploadPartCopy', $sourceKey . ' -> ' . $targetKey, $e);
        }
        $metadata = $this->head($targetKey);
        return new ObjectMetadata(true, $size, $metadata->mtime, $metadata->etag, $options->contentType, $metadata->storageClass, false);
    }

    private function copySource(string $key): string
    {
        return $this->bucket . '/' . implode('/', array_map(rawurlencode(...), explode('/', $key)));
    }

    private static function fromHead(HeadObjectOutput $output): ObjectMetadata
    {
        $storageClass = $output->getStorageClass();
        $restore = (string)$output->getRestore();
        $restored = str_contains($restore, 'ongoing-request="false"');
        $archived = !$restored && (
            $output->getArchiveStatus() !== null
            || in_array($storageClass, ['GLACIER', 'DEEP_ARCHIVE'], true)
        );
        return new ObjectMetadata(
            true,
            $output->getContentLength() ?? 0,
            $output->getLastModified()?->getTimestamp() ?? 0,
            $output->getEtag(),
            $output->getContentType(),
            $storageClass,
            $archived,
        );
    }

    /**
     * @return array{key: string, size: int, mtime: int, etag: ?string, storageClass: ?string}
     */
    private static function fromListEntry(AwsObject $object): array
    {
        return [
            'key' => (string)$object->getKey(),
            'size' => $object->getSize() ?? 0,
            'mtime' => $object->getLastModified()?->getTimestamp() ?? 0,
            'etag' => $object->getEtag(),
            'storageClass' => $object->getStorageClass(),
        ];
    }

    private function failWrite(string $operation, string $key, AsyncAwsException $e): never
    {
        if ($e instanceof ClientException && $e->getResponse()->getStatusCode() === 412) {
            throw new PreconditionFailedException($operation, $key, $e);
        }
        $this->fail($operation, $key, $e);
    }

    private function fail(string $operation, string $key, AsyncAwsException $e): never
    {
        $status = null;
        $requestId = null;
        $awsCode = null;
        if ($e instanceof HttpException) {
            $response = $e->getResponse();
            $status = $response->getStatusCode();
            $headers = $response->getHeaders(false);
            $requestId = $headers['x-amz-request-id'][0] ?? null;
            $awsCode = $e->getAwsCode();
        }
        $message = sprintf('S3 %s on "%s/%s" failed: %s', $operation, $this->bucket, $key, $awsCode ?? $e->getMessage());
        if ($status === 403) {
            $message .= ' — access denied; the role needs s3:ListBucket and s3:GetBucketLocation on the bucket and s3:GetObject, s3:PutObject, s3:DeleteObject, s3:AbortMultipartUpload, s3:ListMultipartUploadParts, s3:GetObjectTagging on bucket/*.';
        }
        $this->logger->error($message, [
            'bucket' => $this->bucket,
            'key' => $key,
            'operation' => $operation,
            'status' => $status,
            'request_id' => $requestId,
            'exception' => $e::class,
        ]);
        throw new S3OperationFailedException($message, 1760400030, $operation, $key, $status, $requestId, $e);
    }
}
