<?php

/**
 * Social channels and social copy — the read-only half of the social toolkit.
 *
 * The worker's `/api/social` surface has three routes: list the channels, compose
 * copy, and publish. Only the first two are in scope here, and the name says so.
 * A contract called `SocialPublisher` with no publish method would be an
 * invitation to add one without the approval gate the necessity guard puts on
 * an irreversible send; a `SocialPublisherInterface` will be its own contract,
 * and will be approval-gated in the registry rather than by convention here.
 *
 * ## `compose()` can answer two different things, and says which
 *
 * The worker generates copy with a model when a key resolves, and otherwise
 * falls back to **prompt templates** — instructions for a human, not finished
 * copy. Those are not interchangeable, so the result separates them: `posts` is
 * populated only when a model wrote something, `prompts` only when it did not,
 * and `provider` names which happened.
 *
 * A tool that rendered `prompts` as captions would be the exact class of lie
 * this codebase keeps fixing: success reported for work not done.
 *
 * ## `connected` is a credential fact, not a health check
 *
 * `accounts()` reports whether a platform has credentials configured. It does
 * not prove the token is valid — a revoked token answers 401 at publish time.
 * The vocabulary is therefore `connected`, never `ready`.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface SocialComposerInterface
{
    /**
     * The platforms this worker knows about, and whether each has credentials.
     *
     * @return list<array{id: string, name: string, connected: bool, max_chars: int}>
     *
     * @throws \RuntimeException When the engine is unavailable or the worker answers an error.
     */
    public function accounts(): array;

    /**
     * Compose post copy for one topic.
     *
     * @param array<string, mixed> $options {
     *     @type string       $topic            What the post is about. Required.
     *     @type string       $tone             Voice, e.g. `professional`, `casual`.
     *     @type string       $platform         Limit to one channel; empty means every known channel.
     *     @type bool         $include_hashtags Whether to suggest hashtags. Default true.
     *     @type int          $count            How many variants per channel. Default 3.
     *     @type string       $language         BCP-47-ish language code. Default `en`.
     * }
     *
     * @return array{
     *     provider: string,
     *     language: string,
     *     topic: string,
     *     tone: string,
     *     suggested_hashtags: list<string>,
     *     posts: list<array{platform: string, character_limit: int, variants: list<string>}>,
     *     prompts: array<string, string>,
     *     note: string|null
     * }
     *
     * @throws \InvalidArgumentException When the topic is empty.
     * @throws \RuntimeException         When the engine is unavailable or the worker answers an error.
     */
    public function compose(array $options = array()): array;

    /**
     * Whether one read operation can be served right now.
     *
     * @param string $operation `accounts` or `compose`.
     */
    public function supports(string $operation): bool;

    /**
     * Whether any operation can be served.
     */
    public function isAvailable(): bool;
}
