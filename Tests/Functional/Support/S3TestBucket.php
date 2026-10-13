<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Functional\Support;

use AsyncAws\Core\Configuration;
use AsyncAws\SimpleS3\SimpleS3Client;

/**
 * A throw-away bucket on the S3-compatible server the environment names
 * (KOH_FAL_S3_TEST_ENDPOINT). Tests skip when there is none, so the suite
 * stays green on a laptop without a container and CI always has one.
 */
final class S3TestBucket
{
    public readonly string $name;
    private readonly SimpleS3Client $client;

    private function __construct(public readonly string $endpoint, public readonly string $region)
    {
        $this->name = 'fal-test-' . bin2hex(random_bytes(6));
        $this->client = new SimpleS3Client([
            Configuration::OPTION_REGION => $region,
            Configuration::OPTION_ENDPOINT => $endpoint,
            Configuration::OPTION_PATH_STYLE_ENDPOINT => 'true',
            Configuration::OPTION_SEND_CHUNKED_BODY => 'false',
        ]);
    }

    public static function endpoint(): ?string
    {
        $endpoint = getenv('KOH_FAL_S3_TEST_ENDPOINT');
        return is_string($endpoint) && trim($endpoint) !== '' ? rtrim(trim($endpoint), '/') : null;
    }

    public static function create(): self
    {
        $endpoint = self::endpoint();
        if ($endpoint === null) {
            throw new \RuntimeException('KOH_FAL_S3_TEST_ENDPOINT is not set.', 1760400100);
        }
        $region = getenv('KOH_FAL_S3_TEST_REGION');
        $bucket = new self($endpoint, is_string($region) && $region !== '' ? $region : 'eu-north-1');
        $bucket->client->createBucket(['Bucket' => $bucket->name])->resolve();
        return $bucket;
    }

    public function client(): SimpleS3Client
    {
        return $this->client;
    }

    /**
     * @return array<string, mixed>
     */
    public function driverConfiguration(): array
    {
        return [
            'bucket' => $this->name,
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'pathStyle' => '1',
        ];
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        $keys = [];
        foreach ($this->client->listObjectsV2(['Bucket' => $this->name])->getContents() as $object) {
            $keys[] = (string)$object->getKey();
        }
        sort($keys);
        return $keys;
    }

    public function destroy(): void
    {
        $keys = $this->keys();
        foreach ($keys as $key) {
            $this->client->deleteObject(['Bucket' => $this->name, 'Key' => $key])->resolve();
        }
        $this->client->deleteBucket(['Bucket' => $this->name])->resolve();
    }
}
