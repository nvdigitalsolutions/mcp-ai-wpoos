<?php

/**
 * File header.
 *
 * @package WP_MCP_AI
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

namespace Nvoos\Core\Domain\Contract;

/**
 * Operations that need more than one input.
 *
 * `VideoProcessingInterface` takes one file and returns one file. A watermark
 * is inherently two — the video and the mark — and a merge is N. Rather than
 * widen that interface (which would change the meaning of `process()` for every
 * existing caller), composition is its own contract, the same split as
 * `SocialComposerInterface` versus `SocialPublisherInterface`.
 *
 * `$inputs` is an **ordered** list: the first entry is the base, the rest are
 * applied to it. An adapter must not reorder it, and a route that sorts files
 * by arrival instead of by name is a silent corruption waiting to happen.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 */
interface VideoCompositionInterface
{
    /**
     * The operations this engine implements.
     *
     * @return list<string>
     */
    public static function operations(): array;

    /**
     * Run one operation over several inputs.
     *
     * @param string                $operation One of `operations()`.
     * @param list<string>          $inputs    Local paths, base first.
     * @param array<string, mixed>  $options   Operation-specific settings.
     *
     * @return array{path: string, operation: string, format: string, bytes: int, original_bytes: int, savings_percent: float}
     *
     * @throws \InvalidArgumentException When the operation, the inputs or an option is unusable.
     * @throws \RuntimeException         When the engine fails.
     */
    public function compose(string $operation, array $inputs, array $options = array()): array;

    /**
     * Whether this engine can run an operation right now.
     */
    public function supports(string $operation): bool;

    /**
     * Whether an engine is bound at all, regardless of its capabilities.
     */
    public function isAvailable(): bool;
}
