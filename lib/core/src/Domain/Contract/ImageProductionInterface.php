<?php

/**
 * Image production — the second tier of image work, beyond geometry.
 *
 * `ImageProcessingInterface` covers what a PHP image library has always done:
 * resize, crop, rotate, convert, read metadata. It is deliberately small,
 * because the WordPress adapter implements it with GD/Imagick and nothing else.
 *
 * This contract covers the operations a *pipeline* does and a GD call cannot:
 * re-encode for the web, sharpen and denoise, upscale with a resampling kernel,
 * run a batch, and hand an image to a model for colourisation or restyling. The
 * WordPress tools in `addons/pro/includes/tools/image-production/` are the
 * reference behaviour; this is the portable half.
 *
 * ## Why a separate interface
 *
 * Adding `batch()` to `ImageProcessingInterface` would force every host that has
 * GD to implement a worker protocol it does not speak. Interface segregation is
 * not ceremony here — it is the difference between "this host can resize" and
 * "this host can run a Sharp pipeline".
 *
 * ## Options are delegated, not re-typed
 *
 * `optimize()` and `enhance()` take an options bag because the knobs are the
 * engine's vocabulary (`quality`, `format`, `sharpen`, `contrast`, …) and a
 * typed parameter per knob would need a major version every time Sharp grew one.
 * The tools validate and clamp before calling; this layer passes through.
 *
 * ## Providers are not this contract's business
 *
 * `edit()` is model-backed and answers a named error when no provider key is
 * configured — it does not fall back to a local filter pretending to be AI. A
 * caller that wants a deterministic look uses `enhance()`.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ImageProductionInterface
{
    /**
     * Re-encode an image for delivery, optionally resizing and changing format.
     *
     * @param string                $sourcePath Absolute path to the source image.
     * @param array<string, mixed>  $options {
     *     @type string $format  Output format: `webp`, `avif`, `jpeg`, `png`.
     *                           Empty keeps a sensible default.
     *     @type int    $quality Encoder quality, 1-100.
     *     @type int    $width   Maximum width; the image is never enlarged.
     * }
     *
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     original_bytes: int,
     *     savings_bytes: int,
     *     savings_percent: float
     * }
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When the engine fails.
     */
    public function optimize(string $sourcePath, array $options = array()): array;

    /**
     * Sharpen, denoise, and adjust contrast and saturation.
     *
     * At least one enhancement must be requested; an engine that is asked for
     * nothing is not asked at all, because "re-encode with no changes" is
     * `optimize()` with the source format.
     *
     * @param string               $sourcePath Absolute path to the source image.
     * @param array<string, mixed> $options {
     *     @type bool       $sharpen    Apply a sharpen pass, or a 0-1 amount.
     *     @type float      $contrast   Multiplier around mid-grey; 1 is neutral.
     *     @type float      $saturation Multiplier; 1 is neutral.
     *     @type bool       $denoise    Apply a single-pass median filter.
     *     @type float      $strength   0-1 scalar applied to every amount.
     * }
     *
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     original_bytes: int,
     *     savings_bytes: int,
     *     savings_percent: float,
     *     enhancements: list<string>
     * }
     *
     * @throws \InvalidArgumentException When no enhancement is requested, or the path is unreadable.
     * @throws \RuntimeException         When the engine fails.
     */
    public function enhance(string $sourcePath, array $options = array()): array;

    /**
     * Enlarge an image by an integer factor.
     *
     * @param string $sourcePath Absolute path to the source image.
     * @param int    $factor     Multiplier: 2, 4 or 8.
     * @param string $kernel     Resampling kernel: `lanczos3` (default), `cubic`, `nearest`.
     *
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     original_bytes: int,
     *     factor: int,
     *     kernel: string,
     *     method: string
     * }
     *
     * @throws \InvalidArgumentException When the factor is not 2, 4 or 8.
     * @throws \RuntimeException         When the engine fails or the result exceeds its cap.
     */
    public function upscale(string $sourcePath, int $factor = 2, string $kernel = 'lanczos3'): array;

    /**
     * Resize with content-aware cropping.
     *
     * `position` is the engine's attention strategy: `attention` finds the
     * busiest region rather than the centre. That is what "smart" means here —
     * it is a saliency heuristic, not face or subject detection, and the result
     * says so.
     *
     * @param string $sourcePath Absolute path to the source image.
     * @param int    $width      Target width in pixels.
     * @param int    $height     Target height in pixels.
     * @param string $position   `attention`, `centre`, `entropy`, `top`, `bottom`, `left`, `right`.
     *
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     original_bytes: int,
     *     position: string
     * }
     *
     * @throws \InvalidArgumentException When the dimensions are not positive.
     * @throws \RuntimeException         When the engine fails.
     */
    public function resizeSmart(string $sourcePath, int $width, int $height, string $position = 'attention'): array;

    /**
     * Run one non-generative operation over many images.
     *
     * One call, many files, because the cost of a batch is the per-request
     * overhead and an agent that issues twenty calls will hit a timeout on the
     * twenty-first. Per-file failures are collected, never thrown: a batch of
     * ten where one file is corrupt must still return nine results.
     *
     * @param list<string>         $sourcePaths Absolute paths, in order.
     * @param array<string, mixed> $options {
     *     @type string $format  Output format: `webp`, `avif`, `jpeg`, `png`, `original`.
     *     @type int    $quality Encoder quality, 1-100.
     *     @type int    $width   Maximum width; images are never enlarged.
     * }
     *
     * @return array{
     *     results: list<array{path: string, filename: string, width: int, height: int, format: string, bytes: int, original_bytes: int, savings_percent: float}>,
     *     errors: list<array{filename: string, error: string}>,
     *     processed: int,
     *     failed: int,
     *     total_bytes_saved: int
     * }
     *
     * @throws \InvalidArgumentException When no readable path is given.
     * @throws \RuntimeException         When the engine refuses the whole batch.
     */
    public function batch(array $sourcePaths, array $options = array()): array;

    /**
     * Apply a model-backed edit.
     *
     * @param string               $sourcePath Absolute path to the source image.
     * @param string               $operation  `colorize` or `style_transfer`.
     * @param array<string, mixed> $options {
     *     @type string $style    Style preset slug, for `style_transfer`.
     *     @type string $prompt   Free-text instruction, overriding the preset.
     *     @type string $provider Provider preference: `auto`, `gemini`, `openai`, `replicate`.
     * }
     *
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     operation: string,
     *     provider: string
     * }
     *
     * @throws \InvalidArgumentException When the operation is not supported.
     * @throws \RuntimeException         When no provider is configured, or the provider fails.
     */
    public function edit(string $sourcePath, string $operation, array $options = array()): array;

    /**
     * Whether one operation can be served right now.
     *
     * A host may have the deterministic ops and no model keys, so this is asked
     * per operation rather than once for the whole contract. Unknown operations
     * answer false — a capability nobody vouched for is not offered.
     *
     * @param string $operation `optimize`, `enhance`, `upscale`, `resize_smart`, `batch`, `edit`.
     */
    public function supports(string $operation): bool;

    /**
     * Whether any operation can be served.
     */
    public function isAvailable(): bool;
}
