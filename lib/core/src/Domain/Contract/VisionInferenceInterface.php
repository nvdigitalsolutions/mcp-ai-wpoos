<?php

/**
 * Vision inference — run a specific model over an image for a structured answer.
 *
 * Proposal 164 section 164.126. This contract had no docblock, and that absence is
 * the reason §164.122 mistook it for the seam the image validators needed. It is
 * not that seam, and the difference is the whole point of this file.
 *
 * ## Two vision seams, and what separates them
 *
 * | | {@see \Nvoos\Core\Tool\AbstractCompletionTool} | this interface |
 * |---|---|---|
 * | The question | "what is in this image", answered in prose or JSON | "run *this* model over the image" |
 * | The model | chosen by `ProviderRouter` from a host default | pinned by the caller |
 * | The answer | text | whatever the model emits: detections, boxes, labels, scores |
 * | Multi-image | yes, one turn with several parts | one image per call |
 *
 * The completion path is the right seam for a question a chat model can answer,
 * and it is what `analyze_image` and both ecommerce validators use. A text
 * completion cannot express bounding boxes, and it cannot be told to use a
 * particular checkpoint rather than the host's chat model — so a detection tool
 * needs this interface instead.
 *
 * ## The return shape, which is a convention rather than a type
 *
 * Both adapters agree on it, and a caller should rely on it:
 *
 *     ['success' => true,  ...model output]                 // the model answered
 *     ['success' => false, 'error' => string]               // it did not
 *
 * A bad input — a path that does not exist, an unreadable file — may throw
 * `InvalidArgumentException` instead, because that is a caller bug rather than a
 * runtime condition. {@see isAvailable()} exists so a caller can decline before
 * calling at all.
 *
 * ## Who implements it, and who calls it
 *
 * Implemented by `Nvoos\WordPress\Adapter\VisionInference` (over the Pro
 * plugin's Hugging Face service) and by `Nvoos\Laravel\Adapter\OpenAiVision` (over
 * an OpenAI-compatible chat route).
 *
 * **Nothing in core calls it yet.** `rfdetr_catalog_search` is the intended
 * consumer and is registered as deferred on a checkpoint rather than on this
 * transport, so the contract is declared ahead of its first caller on purpose
 * rather than by accident. The WordPress extended-cognition tools that do
 * detection today instantiate the Hugging Face service directly; when they are
 * ported they should come through here.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface VisionInferenceInterface
{
    /**
     * Run the model over one image.
     *
     * @param string               $imagePath A readable local image file.
     * @param string               $prompt    What to ask, when the model takes a prompt at all.
     * @param array<string, mixed> $options   Model-specific options. `model` selects a checkpoint;
     *                                        the rest are passed through, so an adapter that cannot
     *                                        honour one should say so in its own docblock rather than
     *                                        ignore it silently.
     *
     * @return array<string, mixed> `success` plus the model's output, or `success: false` and `error`.
     *
     * @throws \InvalidArgumentException When the image cannot be read.
     */
    public function infer(string $imagePath, string $prompt = '', array $options = []): array;

    /**
     * The checkpoints this binding can reach, when it can enumerate them.
     *
     * An empty array is a legitimate answer: a gateway that serves whatever model
     * it is asked for has nothing to enumerate.
     *
     * @return array<int, string>
     */
    public function getAvailableModels(): array;

    /**
     * Whether this binding can serve a call at all — a configured key, a reachable
     * service. A caller should ask before calling so it can decline with its own
     * reason instead of surfacing a transport failure.
     */
    public function isAvailable(): bool;
}
