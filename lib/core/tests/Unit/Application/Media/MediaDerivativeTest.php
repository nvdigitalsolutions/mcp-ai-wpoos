<?php

/**
 * The derivative value object.
 *
 * Two things here are worth pinning because getting either wrong makes an
 * inventory silently lie: the source id is an **integer** (a string criterion is
 * matched by substring, so `'7'` would also match file `17`), and an unknown role
 * produces no relationship at all rather than a relationship nobody can query.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Application\Media;

use Nvoos\Core\Application\Media\MediaDerivative;
use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;
use PHPUnit\Framework\TestCase;

final class MediaDerivativeTest extends TestCase
{
    // ─── When a relationship can be asserted ───────────────────────────────

    public function testAStoredSourceAndAKnownRoleProduceARelationship(): void
    {
        $derivative = MediaDerivative::forSource(7, FileDerivativeStoreInterface::ROLE_POSTER);

        $this->assertNotNull($derivative);
        $this->assertSame(7, $derivative->sourceFileId);
        $this->assertSame('poster', $derivative->role);
    }

    /**
     * A URL input was never in this registry. There is no id to point at, and a
     * relationship to the wrong file is worse than none because it is trusted.
     */
    public function testNoSourceIdProducesNoRelationship(): void
    {
        $this->assertNull(MediaDerivative::forSource(0, FileDerivativeStoreInterface::ROLE_POSTER));
        $this->assertNull(MediaDerivative::forSource(-3, FileDerivativeStoreInterface::ROLE_POSTER));
    }

    public function testAnUnknownRoleProducesNoRelationship(): void
    {
        $this->assertNull(MediaDerivative::forSource(7, 'thumbnail'));
        $this->assertNull(MediaDerivative::forSource(7, ''));
        $this->assertNull(MediaDerivative::forSource(7, 'POSTER'));
    }

    /**
     * Guards the vocabulary against a role that has no producer. If this fails
     * after a role was added, the producer is missing rather than the test.
     */
    public function testEveryRoleInTheVocabularyIsAccepted(): void
    {
        $this->assertNotSame(array(), FileDerivativeStoreInterface::ROLES);

        foreach (FileDerivativeStoreInterface::ROLES as $role) {
            $this->assertNotNull(
                MediaDerivative::forSource(1, $role),
                "The vocabulary advertises `{$role}` but nothing can build one."
            );
        }
    }

    // ─── What gets written and what gets queried ───────────────────────────

    /**
     * The substring trap. `findByMetadata()` compares a string criterion with
     * `stripos`, so `'7'` matches file `17` and file `70`. An integer is compared
     * strictly, which is the only correct reading for an identifier.
     */
    public function testTheSourceIsAnIntegerNotAString(): void
    {
        $criteria = MediaDerivative::criteria(7, FileDerivativeStoreInterface::ROLE_POSTER);

        $this->assertSame(
            array(
                FileDerivativeStoreInterface::META_SOURCE => 7,
                FileDerivativeStoreInterface::META_ROLE   => 'poster',
            ),
            $criteria
        );

        $this->assertIsInt($criteria[FileDerivativeStoreInterface::META_SOURCE]);
    }

    /**
     * A relationship is found by exactly what it recorded, so the write shape and
     * the read shape must not drift apart by a key.
     */
    public function testMetadataAndCriteriaAgree(): void
    {
        $derivative = MediaDerivative::forSource(11, FileDerivativeStoreInterface::ROLE_FRAME);

        $this->assertNotNull($derivative);
        $this->assertSame(
            MediaDerivative::criteria(11, FileDerivativeStoreInterface::ROLE_FRAME),
            $derivative->metadata()
        );
    }
}
