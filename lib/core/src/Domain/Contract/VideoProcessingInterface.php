<?php

/**
 * Video processing — the operations a hosted ffmpeg performs.
 *
 * Proposal 164 §164.4.5. The worker's `/api/video/process` is one route with an
 * `operation` discriminator, so this contract mirrors that rather than inventing
 * one method per op: adding `trim()` next year would be a breaking interface
 * change for every host, while an `operation` string is a value.
 *
 * ## Two steps, because the engine writes to its own disk
 *
 * `/process` answers `output_file` on the **worker's** filesystem and keeps it
 * for a minute. There is no bytes-in-response path for video (a 200 MB base64
 * body is not a thing to put in a JSON envelope), so `process()` performs the
 * download as part of the operation and returns a local path. A caller never
 * sees the intermediate name.
 *
 * ## `isAvailable()` asks the matrix, and the answer is often no
 *
 * `video_processing` requires **ffmpeg and ffprobe** on the worker. A managed
 * Node host frequently has neither, so a tool must ask before it spends an
 * upload — and refuse with a named error rather than a 503 mid-transfer.
 *
 * ## Presets are not here yet
 *
 * §164.4.5 wants named profiles (`web_1080p`, `social_vertical_9x16`) so an
 * agent cannot invent an unplayable combination. This contract takes raw
 * operation options because the tools that will hold the presets are not ported
 * yet; a preset layer belongs above this, not inside it.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface VideoProcessingInterface
{
    /**
     * The operations this contract may be asked for.
     *
     * @return list<string>
     */
    public static function operations(): array;

    /**
     * Read a video's container, duration and streams.
     *
     * @param string $localPath Absolute path to the source file.
     *
     * @return array{
     *     format: string|null,
     *     duration: float,
     *     size: int,
     *     bitrate: int,
     *     video: array{codec: string, width: int, height: int, fps: float, bitrate: int, pixel_format: string}|null,
     *     audio: array{codec: string, channels: int, sample_rate: int, bitrate: int}|null
     * }
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When the engine is unavailable or the file cannot be parsed.
     */
    public function info(string $localPath): array;

    /**
     * Run one operation and return the produced file.
     *
     * @param string               $localPath Absolute path to the source file.
     * @param string               $operation One of {@see operations()}.
     * @param array<string, mixed> $options {
     *     @type int    $width    Target width, or the crop width.
     *     @type int    $height   Target height, or the crop height.
     *     @type string $format   Output container: mp4, webm, mov, mkv, gif, mp3, m4a, aac.
     *     @type float  $start    Seconds from the start, for trim and thumbnail.
     *     @type float  $duration Seconds to keep, for trim.
     *     @type float  $fps      Frame rate, for gif.
     * }
     *
     * @return array{path: string, operation: string, format: string, bytes: int, original_bytes: int, savings_percent: float}
     *
     * @throws \InvalidArgumentException When the operation or an option is not allowed.
     * @throws \RuntimeException         When the engine is unavailable or the run fails.
     */
    public function process(string $localPath, string $operation, array $options = array()): array;

    /**
     * Whether one operation can be served right now.
     *
     * @param string $operation A member of {@see operations()}.
     */
    public function supports(string $operation): bool;

    /**
     * Whether any operation can be served.
     */
    public function isAvailable(): bool;
}
