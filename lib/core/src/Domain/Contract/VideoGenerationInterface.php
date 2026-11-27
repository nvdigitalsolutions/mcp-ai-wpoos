<?php

/**
 * Video generation, split into start and ask.
 *
 * A render takes minutes, so a single blocking call is the wrong shape for a
 * tool turn: it would occupy a request for as long as the provider works, which
 * is longer than any queue job or HTTP request this runtime budgets for. The
 * contract is therefore two operations — `submit()` returns as soon as the
 * provider has the prediction, and `status()` answers where it is.
 *
 * ## Two model families, one contract
 *
 * A provider may animate a reference frame (image-to-video) or invent from a
 * prompt (text-to-video). Both are `submit()`; the difference is whether
 * `$options['reference_path']` is set. An implementation refuses a reference
 * for a model that cannot read one rather than dropping it, and refuses a
 * prompt-only call to a model that requires a frame.
 *
 * ## Nothing is stored here
 *
 * `status()` reports the provider's URL and nothing else. A URL from a provider
 * expires on the provider's schedule, so the caller — usually a queued job —
 * downloads it and stores the bytes in the file registry. The runtime rule is
 * unchanged: durable bytes live in the registry, a provider URL is a transient
 * handle.
 *
 * ## Terminal states are named
 *
 * `status()` answers `terminal: true` for `succeeded`, `failed` and `canceled`
 * and nowhere else. A polling caller can therefore stop on one boolean instead
 * of re-implementing the provider's vocabulary, and an unknown state (a
 * provider rename, a proxy) is not mistaken for completion.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface VideoGenerationInterface
{
    /**
     * Start a render and return before it finishes.
     *
     * @param string               $prompt  What should happen in the clip. May be
     *                                       empty only when a reference frame is given.
     * @param array<string, mixed> $options {
     *     @type string $reference_path Absolute path to the frame to animate. Set
     *                                  this for an image-to-video model; leave it
     *                                  unset for a text-to-video model.
     *     @type string $model          Provider model id. An implementation picks
     *                                  a default; a model that needs a frame must
     *                                  be chosen explicitly by the caller that has one.
     *     @type int    $duration_seconds Requested clip length. Models expose frame
     *                                  counts more often than seconds, so an
     *                                  implementation may round.
     *     @type int    $fps            Frames per second, when the model takes one.
     *     @type int    $seed           Reproducibility seed.
     *     @type string $negative_prompt Text-to-video only; ignored by a frame model.
     *     @type int    $width          Text-to-video output width.
     *     @type int    $height         Text-to-video output height.
     * }
     *
     * @return array{
     *     prediction_id: string,
     *     provider: string,
     *     model: string,
     *     status: string,
     *     prompt: string,
     *     duration_seconds: int,
     *     fps: int
     * }
     *
     * @throws \InvalidArgumentException When no prompt and no frame are given, a path is unreadable, or the model cannot accept what was sent.
     * @throws \RuntimeException         When no provider is configured, or the provider refuses the submission.
     */
    public function submit(string $prompt, array $options = array()): array;

    /**
     * Ask where a render is.
     *
     * Never throws for a provider-side failure: a failed render is a fact to
     * report, not an exception. An unreachable worker or an unknown prediction id
     * is still an exception, because those are the caller's problem to retry.
     *
     * @param string $predictionId The id `submit()` returned.
     *
     * @return array{
     *     prediction_id: string,
     *     status: string,
     *     terminal: bool,
     *     video_url: string|null,
     *     error: string|null,
     *     model: string,
     *     metrics: array<string, mixed>
     * }
     *
     * @throws \InvalidArgumentException When the id is empty.
     * @throws \RuntimeException         When the worker cannot be reached or refuses the query.
     */
    public function status(string $predictionId): array;

    /**
     * Whether one operation can be served right now.
     *
     * @param string $operation `generate`.
     */
    public function supports(string $operation): bool;

    /**
     * Whether any operation can be served.
     */
    public function isAvailable(): bool;
}
