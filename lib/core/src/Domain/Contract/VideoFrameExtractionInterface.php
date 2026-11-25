<?php

/**
 * Frame extraction — many files out of one video.
 *
 * Proposal 164 §164.25. `VideoProcessingInterface::process()` is one file in,
 * one file out, and eight video tools depend on that meaning. A contact sheet is
 * N files, so it gets a contract of its own rather than widening a return shape
 * eight tools already read — the same split `VideoCompositionInterface` made for
 * N *inputs* in §164.20.
 *
 * ## Three modes, one at a time
 *
 * A caller names the moments (`timestamps`), a cadence (`interval`) or a count
 * (`count`). They are not additive: "every 5 s, and also at these five moments"
 * is a list, and the list is `timestamps`. An adapter resolves the precedence and
 * must not quietly merge the modes — a contact sheet at the wrong moments looks
 * exactly like one at the right ones.
 *
 * ## The bytes never travel in a response
 *
 * An adapter downloads each frame and returns local paths. A twenty-frame
 * contact sheet as base64 is the thing `process()` already refuses to do, and
 * the reason is the same: a JSON envelope is not a transport for megabytes.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface VideoFrameExtractionInterface
{
    /**
     * Extract frames and say where they landed.
     *
     * @param string               $localPath Absolute path to the source file.
     * @param array<string, mixed> $options {
     *     @type list<float> $timestamps Seconds to seek to, in the caller's order.
     *     @type float       $interval   Seconds between frames.
     *     @type int         $count      Frames spread evenly across the duration.
     *     @type int         $width      Scale each frame to this width, keeping aspect.
     *     @type int         $quality    JPEG quality, 1 (best) to 31 (worst).
     * }
     *
     * @return array{
     *     frames: list<array{path: string, bytes: int}>,
     *     frame_count: int,
     *     source_duration: float
     * }
     *
     * @throws \InvalidArgumentException When the path or an option is not acceptable.
     * @throws \RuntimeException         When the engine is unavailable or the run fails.
     */
    public function extract(string $localPath, array $options = array()): array;

    /**
     * Whether this host can extract frames right now.
     */
    public function supports(): bool;

    /**
     * Whether the engine is configured at all.
     */
    public function isAvailable(): bool;
}
