<?php

/**
 * Publish to a social platform — the irreversible half of the social toolkit.
 *
 * Separate from {@see SocialComposerInterface} on purpose. Composing copy is a
 * read; publishing is an outbound, irreversible send, and the necessity guard
 * gates it because `publish_to_social` declares `irreversible` +
 * `external-communication`. Keeping the two contracts apart means an adapter
 * cannot hand a caller a publish method it did not mean to offer.
 *
 * ## The pre-flight is part of the contract, not an optimisation
 *
 * `publish()` asks the channel list first. Three refusals happen there rather
 * than at the platform's API:
 *
 *  - an unknown platform is named with the list this worker actually has;
 *  - a channel with no credential is named before the call, because the
 *    platform's own 401 arrives as "LinkedIn API error" and tells the operator
 *    nothing about which environment variable is missing;
 *  - copy longer than the channel's limit is refused with the limit, instead of
 *    being truncated by the platform or rejected opaquely.
 *
 * All three are the same rule: an error the host can name for free should not be
 * delegated to a remote service.
 *
 * ## Scheduling is not offered
 *
 * The worker's route accepts a `schedule` field and enqueues. Scheduling is
 * deliberately absent here: a scheduled publish becomes an irreversible send
 * when it fires, with no human in the loop at that moment, so it needs its own
 * gate rather than riding this one.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface SocialPublisherInterface
{
    /**
     * Publish one post to one platform, now.
     *
     * @param array<string, mixed> $options {
     *     @type string       $platform   Channel id, e.g. `linkedin`. Required.
     *     @type string       $content    The post body. Required.
     *     @type string       $media_url  Optional image or video URL.
     *     @type list<string> $hashtags   Optional tags, appended to the body.
     *     @type string       $media_type `image` (default) or `video`.
     * }
     *
     * @return array{platform: string, post_id: string|null, url: string|null, media_type: string}
     *
     * @throws \InvalidArgumentException When the platform is unknown, the content is empty, or the copy exceeds the channel limit.
     * @throws \RuntimeException         When the channel has no credential, the engine is unavailable, or the platform refuses.
     */
    public function publish(array $options): array;

    /**
     * Whether one operation can be served right now.
     *
     * @param string $operation `publish`.
     */
    public function supports(string $operation): bool;

    /**
     * Whether any operation can be served.
     */
    public function isAvailable(): bool;
}
