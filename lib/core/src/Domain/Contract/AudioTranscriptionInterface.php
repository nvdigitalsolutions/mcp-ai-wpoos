<?php

/**
 * Speech-to-text — a local audio file in, timed words out.
 *
 * Proposal 164 §164.27. The runtime already had `transcribe_openai_audio`, which
 * takes a URL or an attachment id and returns a string. Captions need something
 * that tool cannot give: the **timings**, because a subtitle without a start and
 * an end is a paragraph.
 *
 * ## Why the segments are in the contract rather than parsed by each tool
 *
 * OpenAI answers `verbose_json` with `{text, language, duration, segments[]}`;
 * another provider answers something else, and a tool that parsed a provider
 * payload could only ever serve that provider. The adapter normalises to this
 * shape and the tools reason about cues, which is also what makes the caption
 * formatter a pure unit with no network in its tests.
 *
 * ## Availability is a question, not an exception
 *
 * A host with no key is a normal host. `isAvailable()` lets a tool answer
 * "transcription is not configured" before it uploads an hour of audio to a
 * worker, and the same method is what a diagnostics page reads.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface AudioTranscriptionInterface
{
    /**
     * Whether transcription can be attempted at all — a key, an endpoint, a host.
     */
    public function isAvailable(): bool;

    /**
     * Transcribe one local audio file.
     *
     * @param string $localPath Absolute path to an audio file the adapter can read.
     * @param array{language?: string, model?: string, prompt?: string} $options {
     *     @type string $language ISO 639-1 hint. Omitted means "detect".
     *     @type string $model    Provider model.
     *     @type string $prompt   Optional context to bias spelling of names.
     * }
     *
     * @return array{
     *     text: string,
     *     language: string|null,
     *     duration: float|null,
     *     model: string,
     *     segments: list<array{start: float, end: float, text: string}>
     * }
     *
     * @throws \InvalidArgumentException When the path is empty or unreadable.
     * @throws \RuntimeException         When transcription is unavailable, the
     *                                   file is too large for the provider, or
     *                                   the provider refuses.
     */
    public function transcribe(string $localPath, array $options = array()): array;
}
