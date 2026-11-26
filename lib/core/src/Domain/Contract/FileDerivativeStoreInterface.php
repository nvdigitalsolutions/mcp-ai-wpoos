<?php

/**
 * Record that one stored file was produced from another.
 *
 * Proposal 164 §164.46. The registry has always known what a file *is* — its
 * mime type, size, owner, visibility and slug — and nothing about where it came
 * from. That gap is why a generated poster could not be found again: the bytes
 * were stored, the relationship was not, so the only way back to a video's
 * thumbnail was to guess from the filename.
 *
 * ## The naming is not invented here
 *
 * Adobe's XMP metadata specifies `xmpMM:DerivedFrom` for exactly this: the
 * **immediate** source an asset was made from, not its ultimate ancestor, so a
 * chain of derivations stays walkable one step at a time. Digital asset
 * management calls the same thing a *rendition* — a derivative kept linked to
 * its master. This contract uses the immediate-source reading, and `role` says
 * what kind of derivative it is.
 *
 * ## An optional capability, like visibility
 *
 * This is a separate interface rather than a method on `FileStoreInterface`
 * because the base contract is implemented by three adapters and many test
 * doubles; adding a method there breaks every one of them for a capability they
 * may never need. `FileVisibilityStoreInterface` set the precedent: a host
 * advertises the capability by implementing it, and a caller asks with
 * `instanceof`.
 *
 * ## What an implementation must refuse
 *
 * A relationship is worse than no relationship when it is wrong, because it is
 * then trusted. So:
 *
 * - **A file may not derive from itself.** That is a cycle of length one and it
 *   makes every inventory built on top of it lie.
 * - **The source must exist.** A dangling id is a link to nothing.
 * - **The role must be in the closed vocabulary.** An open role turns the
 *   inventory into a guess about spelling — `thumb`, `thumbnail`, `poster`.
 *
 * Recording the same pair twice is not an error: the link is a fact, and
 * re-asserting a fact is idempotent.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

use Nvoos\Core\Domain\Entity\StoredFile;

interface FileDerivativeStoreInterface
{
    /** A poster image for a video. Produced by `generate_video_thumbnails`. */
    public const ROLE_POSTER = 'poster';

    /** A caption or transcript sidecar (`.srt`, `.vtt`). Produced by `generate_video_captions`. */
    public const ROLE_CAPTION = 'caption';

    /** A frame extracted from a video. Produced by `extract_video_frames`. */
    public const ROLE_FRAME = 'frame';

    /**
     * The closed vocabulary.
     *
     * Every entry has a producer in this runtime. A role no tool writes would be
     * a flag describing behaviour the host does not have, so roles are added
     * when their producer lands, not in advance.
     *
     * @var list<string>
     */
    public const ROLES = array(
        self::ROLE_POSTER,
        self::ROLE_CAPTION,
        self::ROLE_FRAME,
    );

    /**
     * Metadata key holding the source file id.
     *
     * The value is written and matched as an **integer**. `findByMetadata()`
     * compares a string criterion by substring, so `'7'` would also match file
     * `17`; an integer criterion is compared strictly, which is the only correct
     * reading for an identifier.
     */
    public const META_SOURCE = 'derived_from';

    /** Metadata key holding the role. */
    public const META_ROLE = 'derived_role';

    /**
     * Record that `$fileId` is a `$role` derivative of `$sourceFileId`.
     *
     * @param int    $fileId       The derivative's file id.
     * @param int    $sourceFileId The file it was produced from.
     * @param string $role         One of {@see self::ROLES}.
     *
     * @return StoredFile The derivative, with its metadata as stored.
     *
     * @throws \Nvoos\Core\Domain\Error\ValidationException When an id is not
     *                                                      positive, the ids are
     *                                                      equal, the role is not
     *                                                      in the vocabulary, or
     *                                                      the host cannot store
     *                                                      metadata.
     * @throws \Nvoos\Core\Domain\Error\NotFoundException   When either file id does not exist.
     */
    public function recordDerivative(int $fileId, int $sourceFileId, string $role): StoredFile;

    /**
     * Which of these sources already have a derivative in this role.
     *
     * The inventory question, asked once: "of these videos, which already have a
     * poster?". Asking per file would be one scan of the registry per video, so
     * the batch form is part of the contract rather than something each caller
     * assembles out of `FileStoreInterface::findByMetadata()`.
     *
     * Only sources *not* in the returned list are missing the derivative, so an
     * implementation that cannot see a relationship must omit it — never guess.
     *
     * @param list<int> $sourceFileIds Candidate source ids.
     * @param string    $role          One of {@see self::ROLES}.
     *
     * @return list<int> The subset of `$sourceFileIds` that has at least one
     *                   derivative in that role.
     *
     * @throws \Nvoos\Core\Domain\Error\ValidationException When the role is not
     *                                                      in the vocabulary.
     */
    public function sourceIdsWithDerivative(array $sourceFileIds, string $role): array;
}
