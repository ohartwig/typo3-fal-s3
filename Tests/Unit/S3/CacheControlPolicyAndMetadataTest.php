<?php

/*
 * SPDX-FileCopyrightText: 2026 Kai Ole Hartwig
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Koh\FalS3\Tests\Unit\S3;

use Koh\FalS3\S3\CacheControlPolicy;
use Koh\FalS3\S3\ObjectMetadata;
use PHPUnit\Framework\Attributes\Test;
use TYPO3\TestingFramework\Core\Unit\UnitTestCase;

final class CacheControlPolicyAndMetadataTest extends UnitTestCase
{
    #[Test]
    public function processedFilesAreImmutableEverythingElseDefault(): void
    {
        $policy = new CacheControlPolicy('public, max-age=1', 'public, max-age=2, immutable');
        self::assertSame('public, max-age=2, immutable', $policy->for('_processed_/csm_x.jpg'));
        self::assertSame('public, max-age=1', $policy->for('user_upload/x.jpg'));
        self::assertSame('no-store', $policy->for('user_upload/'));
        self::assertTrue(CacheControlPolicy::isProcessed('_processed_/a'));
        self::assertFalse(CacheControlPolicy::isProcessed('a/_processed_/a'));
    }

    #[Test]
    public function md5IsOnlyDerivedFromSinglePartEtags(): void
    {
        self::assertSame('d41d8cd98f00b204e9800998ecf8427e', (new ObjectMetadata(true, 0, 0, '"D41D8CD98F00B204E9800998ECF8427E"', null, null, false))->md5FromEtag());
        self::assertNull((new ObjectMetadata(true, 0, 0, '"abc-3"', null, null, false))->md5FromEtag(), 'multipart');
        self::assertNull((new ObjectMetadata(true, 0, 0, null, null, null, false))->md5FromEtag());
        self::assertFalse(ObjectMetadata::missing()->exists);
    }
}
