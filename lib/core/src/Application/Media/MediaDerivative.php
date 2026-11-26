<?php

/**
 * A derivative relationship, ready to record or to query.
 *
 * Proposal 164 §164.46. {@see \Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface}
 * is the capability; this is the value that travels with a produced file so a
 * tool does not have to carry two loose variables (`?int $source`, `string $role`)
 * through every call.
 *
 * ## Null is a fact, not a failure
 *
 * {@see self::forSource()} returns `null` when there is no stored source id. That
 * is the ordinary case for a tool given a URL: the video was never in this
 * registry, so there is no id to point at, and inventing one would record a
 * relationship to the wrong file — worse than recording none, because a wrong
 * link is trusted.
 *
 * ## Read and write carry the same keys
 *
 * {@see self::criteria()} and {@see self::metadata()} produce the same shape on
 * purpose: a relationship is found by exactly what it recorded. Two shapes would
 * let a writer and a reader disagree by a single key and report "no derivatives"
 * for a library full of them.
 *
 * The source id is an **integer** in both, because `findByMetadata()` compares a
 * string criterion by substring — `'7'` would also match file `17`.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Application\Media;

use Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface;

final readonly class MediaDerivative
{
    public function __construct(
        public int $sourceFileId,
        public string $role,
    ) {
    }

    /**
     * The relationship, or `null` when it cannot be asserted.
     *
     * `null` covers both "no source id" and "role outside the vocabulary". Both
     * mean the same thing to a caller — record nothing — and neither is worth an
     * error on a path that has already produced the bytes successfully.
     */
    public static function forSource(int $sourceFileId, string $role): ?self
    {
        if ($sourceFileId <= 0 || !in_array($role, FileDerivativeStoreInterface::ROLES, true)) {
            return null;
        }

        return new self($sourceFileId, $role);
    }

    /**
     * Find every derivative of one source in one role.
     *
     * @return array{derived_from: int, derived_role: string}
     */
    public static function criteria(int $sourceFileId, string $role): array
    {
        return array(
            FileDerivativeStoreInterface::META_SOURCE => $sourceFileId,
            FileDerivativeStoreInterface::META_ROLE   => $role,
        );
    }

    /**
     * The metadata a derivative records about itself.
     *
     * @return array{derived_from: int, derived_role: string}
     */
    public function metadata(): array
    {
        return self::criteria($this->sourceFileId, $this->role);
    }
}
