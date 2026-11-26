<?php

/**
 * Which videos are missing a poster, or a caption file.
 *
 * Proposal 164 §164.46. Two tools ask this question with a different role, so the
 * query lives here once: find the videos, ask the registry which of them already
 * have a derivative, and return the difference.
 *
 * ## Two scans, not N
 *
 * `findByMetadata()` is a scan over the newest rows, so asking it once per video
 * would be one scan per video. {@see \Nvoos\Core\Domain\Contract\FileDerivativeStoreInterface::sourceIdsWithDerivative()}
 * answers the batch question in a single pass, and that is why it is on the
 * contract rather than assembled by every caller.
 *
 * ## Why the answer is trustworthy inside the window
 *
 * Ids are monotonic: a derivative is created *after* its source, so it always has
 * a higher id. If a video is inside the scanned window, its poster is too. That
 * is what makes "not in the derivative set" mean "has no poster" rather than
 * "the scan did not reach that far" — and it is why both reads share one bound.
 *
 * Outside the window nothing is claimed: a video older than the scan is not
 * listed at all, rather than listed as missing a poster it may well have. A false
 * "missing" costs a regenerated poster; a missing row costs nothing.
 *
 * ## A record, not an inference
 *
 * A poster counts when the registry says it was produced from that video. An
 * image that merely looks like a thumbnail, or a caption returned as text and
 * never saved, is not a record and cannot be inferred from — which is the whole
 * reason §164.46 added the relationship instead of guessing from filenames.
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
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;

final class VideoAssetGaps
{
    /** No file registry is bound, so there are no videos to ask about. */
    public const NO_FILE_REGISTRY = 'no_file_registry';

    /** The registry cannot record relationships, so nothing can be answered. */
    public const NO_DERIVATIVE_REGISTRY = 'no_derivative_registry';

    /**
     * The registry's own marker for video, matched as a prefix.
     *
     * `FileStore::rowMatches()` compares a string criterion with `stripos`, so
     * `video/` matches every `video/*` type without enumerating containers.
     */
    private const VIDEO_MIME = 'video/';

    public function __construct(private readonly ?FileStoreInterface $files = null)
    {
    }

    /**
     * Why this report cannot be produced, or `null` when it can.
     *
     * Kept as two reasons because they are two different hosts: one has no
     * registry at all, the other has a registry that cannot record where a file
     * came from. The second is a real deployment (an older adapter), and an
     * operator fixes it by upgrading rather than by configuring.
     */
    public function unavailableReason(): ?string
    {
        if (null === $this->files) {
            return self::NO_FILE_REGISTRY;
        }

        return $this->files instanceof FileDerivativeStoreInterface ? null : self::NO_DERIVATIVE_REGISTRY;
    }

    /**
     * The videos with no derivative in this role.
     *
     * @param string $role  One of {@see FileDerivativeStoreInterface::ROLES}.
     * @param int    $limit How many videos to examine.
     *
     * @return array{
     *     role: string,
     *     videos: list<array{file_id: int, filename: string, mime_type: string, size_bytes: int, created_at: string}>,
     *     count: int,
     *     examined: int,
     *     truncated: bool
     * }
     *
     * @throws \LogicException When called on a host that cannot answer; ask
     *                         {@see self::unavailableReason()} first.
     */
    public function report(string $role, int $limit): array
    {
        $store = $this->derivativeStore();

        $videos = $store->findByMetadata(array('mime_type' => self::VIDEO_MIME), $limit);

        $ids = array_map(static fn (StoredFile $file): int => $file->id, $videos);

        $have = array();

        if (array() !== $ids) {
            // array_flip for membership: the answer is "which of these", asked
            // once per video, and an `in_array` per video would be quadratic.
            $have = array_flip($store->sourceIdsWithDerivative($ids, $role));
        }

        $missing = array();

        foreach ($videos as $video) {
            if (isset($have[$video->id])) {
                continue;
            }

            $missing[] = array(
                'file_id'    => $video->id,
                'filename'   => $video->filename,
                'mime_type'  => $video->mimeType,
                'size_bytes' => $video->sizeBytes,
                'created_at' => $video->createdAt->format('c'),
            );
        }

        return array(
            'role'      => $role,
            'videos'    => $missing,
            'count'     => count($missing),
            'examined'  => count($videos),
            // The registry caps a metadata read, so a full page means "there may
            // be more". Reporting it is what stops a caller treating a window as
            // a census.
            'truncated' => count($videos) >= $limit,
        );
    }

    /**
     * The registry, once it is known to be able to answer.
     *
     * @throws \LogicException When the host cannot answer.
     */
    private function derivativeStore(): FileStoreInterface&FileDerivativeStoreInterface
    {
        if (!$this->files instanceof FileDerivativeStoreInterface) {
            throw new \LogicException(
                'Call unavailableReason() before report(); this host is '
                . ($this->unavailableReason() ?? 'unknown') . '.'
            );
        }

        return $this->files;
    }
}
