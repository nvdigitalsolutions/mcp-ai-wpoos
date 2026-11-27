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
     * Place one image inside another with a model-backed edit.
     *
     * `edit()` cannot express this: its operations are a closed set and it
     * sends a single image. Composition is a question about two images at
     * once — "put this product in that scene" — so both travel to the
     * provider in one turn. It lives on this contract rather than a new one
     * because any host that can `edit()` has the same keys, the same worker
     * and the same synchronous shape; a separate interface would draw a
     * boundary no implementer can satisfy differently.
     *
     * ## The order is part of the instruction
     *
     * Scene first, product second. A caller that swaps them gets the product
     * painted into the scene's role — a plausible image and a wrong one — so
     * implementations preserve the order and the worker orders `files[n]`
     * parts by their index rather than trusting arrival order.
     *
     * ## The geometry is the output's
     *
     * Unlike `edit()`, which reports `format`/`width`/`height` from the
     * uploaded source, this reports the provider's image. A composition may
     * change the canvas, so a caller that resized to the input's dimensions
     * would be reading the wrong number.
     *
     * ## Why there is no `original_bytes`
     *
     * Two sources do not add up to anything meaningful. Their sizes are
     * reported separately and `bytes` describes the output.
     *
     * @param string               $scenePath   Absolute path to the scene image.
     * @param string               $productPath Absolute path to the product image.
     * @param array<string, mixed> $options {
     *     @type string $prompt   Instruction; an implementation supplies a
     *                            default when empty.
     *     @type string $provider `auto` (default), `gemini` or `openai`.
     *                            Replicate is not offered — its style models
     *                            take one image.
     * }
     *
     * @return array{
     *     path: string,
     *     width: int,
     *     height: int,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     scene_bytes: int,
     *     product_bytes: int,
     *     operation: string,
     *     provider: string,
     *     model: string,
     *     prompt: string
     * }
     *
     * @throws \InvalidArgumentException When a path is empty or unreadable, or the provider is unknown.
     * @throws \RuntimeException         When no provider is configured, or the provider fails.
     *
     * @since 3.0.0
     */
    public function compose(string $scenePath, string $productPath, array $options = array()): array;

    /**
     * Create an image from a text prompt.
     *
     * The only production method with no source path: everything else
     * transforms an image that already exists. It lives on this contract for
     * the same reason `compose()` does — same host capability, same worker,
     * same synchronous shape — so a separate generation interface would draw
     * a boundary no implementer could satisfy differently.
     *
     * ## Dimensions may be read from the bytes
     *
     * A generation route may or may not report geometry: a provider that
     * answers inline can include it, one that answers a URL cannot. An
     * implementation reads the dimensions from the stored bytes when the
     * worker does not. `null` means neither knew, which a caller can print;
     * `0` would be a size.
     *
     * @param string               $prompt  What to draw.
     * @param array<string, mixed> $options {
     *     @type string $aspect_ratio `1:1`, `4:5`, `16:9`, `9:16`, `3:2`, `2:3`
     *                                or `auto` (default `3:2`). Implementations
     *                                may round to the nearest size a provider
     *                                accepts.
     *     @type string $provider     `auto` (default), `gemini` or `openai` —
     *                                the same closed set as `compose()`, so a
     *                                scene and its composition resolve alike.
     *     @type string $model        Provider model id; an implementation picks
     *                                a default when empty.
     * }
     *
     * @return array{
     *     path: string,
     *     width: int|null,
     *     height: int|null,
     *     format: string,
     *     mime_type: string,
     *     bytes: int,
     *     operation: string,
     *     provider: string,
     *     model: string,
     *     prompt: string
     * }
     *
     * @throws \InvalidArgumentException When the prompt is empty or the provider is unknown.
     * @throws \RuntimeException         When no provider is configured, or the provider fails.
     *
     * @since 3.0.0
     */
    public function generate(string $prompt, array $options = array()): array;

    /**
     * Whether one operation can be served right now.
     *
     * A host may have the deterministic ops and no model keys, so this is asked
     * per operation rather than once for the whole contract. Unknown operations
     * answer false — a capability nobody vouched for is not offered.
     *
     * @param string $operation `optimize`, `enhance`, `upscale`, `resize_smart`, `batch`, `edit`, `compose`, `generate`.
     */
    public function supports(string $operation): bool;

    /**
     * Whether any operation can be served.
     */
    public function isAvailable(): bool;
}
