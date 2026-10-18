<!--
SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
SPDX-License-Identifier: MIT
-->

# koh/typo3-fal-s3

A TYPO3 14 FAL driver for Amazon S3 (and S3-compatible storage) that lets a
storage move off a mounted bucket without changing a single identifier, hash or
URL.

- **Identifiers are keys.** The bucket root is the storage root
  (`/user_upload/x.jpg` is the object `user_upload/x.jpg`), folders are key
  prefixes with an optional zero-byte marker. A storage that used the Local
  driver on a FUSE mount of the same bucket switches in place: `sys_file`,
  `sys_file_processedfile` and every rendered URL stay valid.
- **URLs stay yours.** Public URLs are `publicBaseUri` + identifier,
  `/fileadmin/…` by default. Delivery is the web server's job (an S3 proxy in
  Caddy, a CDN, a presigned redirect); the driver does not hand out S3 URLs.
- **No credentials in TYPO3.** The AWS default chain decides: web identity
  (`AWS_ROLE_ARN` + `AWS_WEB_IDENTITY_TOKEN_FILE`, one IAM role per tenant in
  Kubernetes), static keys from the environment for a local S3, then container
  and instance metadata. There is no field for an access key.
- **S3 is asked once.** HEAD results, listings and folder existence live in a
  shared TYPO3 cache (`koh_fal_s3_meta`) with precise invalidation; listings
  seed the object cache so an indexer run costs one `ListObjectsV2`, not one
  `HeadObject` per file. Writes carry their metadata through, hashes are cached
  per ETag forever, `md5` comes from the ETag without a request.
- **Replicas do not overwrite each other.** New objects are written with
  `If-None-Match: *`. Two pods rendering the same processed variant both
  succeed; a real name collision is an `ExistingTargetFileNameException`.
- **Shared buckets are fine.** `excludedPrefixes` hides what other systems
  keep in the same bucket (defaults: `_typo3temp-assets/`, `pos-exchange/`, the
  GuardDuty probe object) from listings and refuses writes there.
- **Processed variants land in the bucket** with `Cache-Control: … immutable`
  and the correct `Content-Type`; originals get a shorter TTL. Both are
  configurable.
- **Storage 0 can follow.** `fallbackProcessingFolder = 1:/_processed_/_fallback_/`
  sends the variants of `EXT:` images into this storage, so nothing of the
  frontend depends on a pod-local `typo3temp/assets` anymore.

Built on [`async-aws/s3`](https://async-aws.com) (Symfony HTTP client, no Guzzle
version coupling, presigning included). Requires TYPO3 ^14.3 and PHP ^8.3.

## Install

```bash
composer require koh/typo3-fal-s3
```

The private registry entry in this repository's `composer.json` resolves `koh/*`
until the vendor is on Packagist.

## Configure a storage

Either create a storage record with driver **Amazon S3 (koh_fal_s3)** and fill
the FlexForm, or switch an existing Local storage in place:

```bash
vendor/bin/typo3 koh_fal_s3:storage --uid 1 --driver KohS3
vendor/bin/typo3 cache:flush
```

The command is idempotent and keeps the previous configuration in
`sys_registry`; `--driver Local` is the way back.

Every option can come from the environment, so the record stays
deployment-neutral. Precedence, first non-null wins:

1. `KOH_FAL_S3_STORAGE<uid>_<KEY>` (one storage)
2. `KOH_FAL_S3_<KEY>` (every storage)
3. `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_fal_s3']['storages'][<uid>][key]`
4. `$GLOBALS['TYPO3_CONF_VARS']['EXTENSIONS']['koh_fal_s3'][key]`
   (`TYPO3__EXTENSIONS__koh_fal_s3__<key>` with helhum/config-loader)
5. the FlexForm value
6. the default

| Key | Env | Default | Meaning |
|---|---|---|---|
| `bucket` | `KOH_FAL_S3_BUCKET` | — (required) | the bucket |
| `region` | `KOH_FAL_S3_REGION` | `AWS_REGION`, else `eu-north-1` | |
| `endpoint` | `KOH_FAL_S3_ENDPOINT` | AWS; `AWS_ENDPOINT_URL_S3`/`AWS_ENDPOINT_URL` honoured; dualstack when `AWS_USE_DUALSTACK_ENDPOINT=true` | S3-compatible servers |
| `pathStyle` | `KOH_FAL_S3_PATH_STYLE` | `0` | path-style addressing (MinIO, RustFS) |
| `keyPrefix` | `KOH_FAL_S3_KEY_PREFIX` | `` | storage root inside the bucket |
| `publicBaseUri` | `KOH_FAL_S3_PUBLIC_BASE_URI` | `/fileadmin/` | where the web server serves the bucket |
| `excludedPrefixes` | `KOH_FAL_S3_EXCLUDED_PREFIXES` | `_typo3temp-assets/`, `pos-exchange/`, `malware-protection-resource-validation-object` | hidden, read-only prefixes (newline or comma separated) |
| `cacheControlDefault` | `KOH_FAL_S3_CACHE_CONTROL_DEFAULT` | `public, max-age=86400` | written as object metadata |
| `cacheControlProcessed` | `KOH_FAL_S3_CACHE_CONTROL_PROCESSED` | `public, max-age=31536000, immutable` | for `_processed_/` |
| `conditionalWrites` | `KOH_FAL_S3_CONDITIONAL_WRITES` | `1` | `If-None-Match: *` on new objects |
| `streamMode` | `KOH_FAL_S3_STREAM_MODE` | `proxy` | non-public storage: `proxy` streams through PHP, `redirect` sends a 307 to a presigned URL |
| `presignLifetime` | `KOH_FAL_S3_PRESIGN_LIFETIME` | `900` | seconds, 60–86400 |
| `malwareGate` | `KOH_FAL_S3_MALWARE_GATE` | `0` | refuse objects GuardDuty tagged `THREATS_FOUND` (one `GetObjectTagging` per object version, cached) |
| `maxFolderOperationObjects` | `KOH_FAL_S3_MAX_FOLDER_OPERATION_OBJECTS` | `10000` | ceiling for one folder move/copy/delete |
| `cacheLifetime` | `KOH_FAL_S3_CACHE_LIFETIME` | `86400` | metadata cache TTL, the safety net for writers outside TYPO3 |
| `negativeCacheLifetime` | `KOH_FAL_S3_NEGATIVE_CACHE_LIFETIME` | `60` | how long "does not exist" is believed |
| `fallbackProcessingFolder` (global) | `KOH_FAL_S3_FALLBACK_PROCESSING_FOLDER` | off | e.g. `1:/_processed_/_fallback_/`: processing folder of storage 0 |

Whether files are public is the storage record's **Is public?** flag, as with
every FAL driver. Unchecked, TYPO3 hands out `eID=dumpFile` links and the driver
streams (or redirects, see `streamMode`).

## Cache backend

`koh_fal_s3_meta` defaults to the database backend so every replica shares one
cache without extra infrastructure. In production point it at Redis/Valkey:

```bash
TYPO3__SYS__caching__cacheConfigurations__koh_fal_s3_meta__backend=TYPO3\CMS\Core\Cache\Backend\RedisBackend
TYPO3__SYS__caching__cacheConfigurations__koh_fal_s3_meta__options__hostname=valkey
```

A `TransientMemoryBackend` works but turns every request into a fresh set of
S3 calls; use it only in tests.

## IAM

The role the pod runs under needs, on the bucket:
`s3:ListBucket`, `s3:GetBucketLocation` — without `ListBucket` a missing object
is a 403, not a 404, and every `fileExists()` becomes an error. On `bucket/*`:
`s3:GetObject`, `s3:PutObject`, `s3:DeleteObject`, `s3:AbortMultipartUpload`,
`s3:ListMultipartUploadParts`, and `s3:GetObjectTagging` if the malware gate is
on. Nothing about versioning or ACLs.

## Delivery

The driver returns `/fileadmin/<identifier>`; something has to serve that path
from the bucket. The reference setup is a `caddy-fs-s3` filesystem in the
application's Caddy, under the same web-identity role, with `Cache-Control`
rules keyed on `/fileadmin/_processed_/*`. A CDN in front of the bucket works
the same way with `publicBaseUri` pointing at it.

## Metrics

Every process writes one `info` line to the log channel `Koh.FalS3.Metrics`
with the S3 requests it made per operation and the cache hit/miss count. Route
it to a file or JSON writer to compare request volume with whatever the storage
replaced:

```php
$GLOBALS['TYPO3_CONF_VARS']['LOG']['Koh']['FalS3']['Metrics']['writerConfiguration'] = [
    \Psr\Log\LogLevel::INFO => [\TYPO3\CMS\Core\Log\Writer\FileWriter::class => ['logFileInfix' => 'fal_s3']],
];
```

## Archived objects

Intelligent-Tiering archive tiers and Glacier classes make `GetObject` fail
until the object is restored. The driver raises `ObjectArchivedException`
(logged as a warning, a 503 with `Retry-After` when streaming) rather than a
500, and names the key. Keep archive tiers off buckets that serve a website.

## Development

```bash
composer install
vendor/bin/phpunit -c phpunit.xml.dist            # unit
docker run -d -p 19000:9000 -e RUSTFS_ACCESS_KEY=rustfsadmin -e RUSTFS_SECRET_KEY=rustfsadmin rustfs/rustfs:1.0.1
AWS_ACCESS_KEY_ID=rustfsadmin AWS_SECRET_ACCESS_KEY=rustfsadmin \
KOH_FAL_S3_TEST_ENDPOINT=http://localhost:19000 vendor/bin/phpunit -c Tests/FunctionalTests.xml
vendor/bin/phpstan
```

The functional suite runs the full `DriverInterface` contract against this
driver and against the core's `LocalDriver`; the S3 tests skip when
`KOH_FAL_S3_TEST_ENDPOINT` is unset.

## License

MIT, see `LICENSE`.
