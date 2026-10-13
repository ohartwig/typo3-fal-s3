<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\S3;

use AsyncAws\Core\Configuration;
use AsyncAws\SimpleS3\SimpleS3Client;
use Koh\FalS3\Configuration\DriverConfiguration;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpClient\HttpClient;
use Symfony\Component\HttpClient\RetryableHttpClient;

/**
 * Builds one S3 client per bucket/endpoint/region and keeps it for the
 * process: the credential chain (web identity in the cluster) is resolved
 * once, not per storage method call.
 *
 * No credential option is ever passed. async-aws' default chain reads
 * AWS_ROLE_ARN + AWS_WEB_IDENTITY_TOKEN_FILE (the projected ServiceAccount
 * token), AWS_ACCESS_KEY_ID/… for a local MinIO, then container and instance
 * metadata. The driver has no field for a static key on purpose.
 */
final class ClientFactory
{
    /** @var array<string, SimpleS3Client> */
    private array $clients = [];

    public function create(DriverConfiguration $configuration, LoggerInterface $logger): SimpleS3Client
    {
        $endpoint = self::endpointFor($configuration);
        $cacheKey = implode('|', [$configuration->region, $endpoint ?? '-', $configuration->pathStyle ? 'path' : 'vhost']);
        if (isset($this->clients[$cacheKey])) {
            return $this->clients[$cacheKey];
        }

        $options = [
            Configuration::OPTION_REGION => $configuration->region,
            Configuration::OPTION_PATH_STYLE_ENDPOINT => $configuration->pathStyle ? 'true' : 'false',
            // MinIO and some proxies reject aws-chunked bodies; a plain body is always valid.
            Configuration::OPTION_SEND_CHUNKED_BODY => 'false',
        ];
        if ($endpoint !== null) {
            $options[Configuration::OPTION_ENDPOINT] = $endpoint;
        }

        $httpClient = new RetryableHttpClient(HttpClient::create(), null, 3, $logger);

        return $this->clients[$cacheKey] = new SimpleS3Client($options, null, $httpClient, $logger);
    }

    /**
     * Explicit endpoint > AWS_ENDPOINT_URL_S3 > AWS_ENDPOINT_URL > dualstack
     * (AWS_USE_DUALSTACK_ENDPOINT=true, which async-aws does not know) > default.
     */
    public static function endpointFor(DriverConfiguration $configuration): ?string
    {
        if ($configuration->endpoint !== null) {
            return $configuration->endpoint;
        }
        foreach (['AWS_ENDPOINT_URL_S3', 'AWS_ENDPOINT_URL'] as $name) {
            $value = getenv($name);
            if (is_string($value) && trim($value) !== '') {
                return rtrim(trim($value), '/');
            }
        }
        if ($configuration->dualstack) {
            return sprintf('https://s3.dualstack.%s.amazonaws.com', $configuration->region);
        }
        return null;
    }
}
