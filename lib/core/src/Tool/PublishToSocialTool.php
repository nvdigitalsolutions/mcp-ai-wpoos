<?php

/**
 * Publish one post to one social platform, now — with a dry run by default.
 *
 * Proposal 164 §164.4.6. This is the only tool in the port that sends something
 * irreversible, and three things follow from that:
 *
 *  1. **`dry_run` defaults to true.** A caller who says nothing gets the exact
 *     payload that would be sent and no network call. Asking for the real thing
 *     is a second, explicit decision.
 *  2. **The necessity guard gates it.** `publish_to_social` declares
 *     `irreversible` + `external-communication`, so a call without an approval
 *     ticket is refused with the ticket id and a retry with an approved ticket
 *     spends it exactly once.
 *  3. **The schema says `destructiveHint: true`.** A published post has been
 *     seen; deleting it does not undo that.
 *
 * ## What it will not do
 *
 * It does not schedule. The worker's route accepts `schedule`, and a scheduled
 * post fires with no human present — a different risk from a post a person just
 * approved, which is why scheduling stays out until it has its own gate.
 *
 * It also does not silently drop `link_url`. The worker has no link field, and
 * most platforms linkify a URL in the body, so the link is **appended to the
 * copy** and the response says `link_appended: true`. A caller that cares can
 * see it; a caller that does not still gets a working post.
 *
 * @package Nvoos\Core
 * @since   2.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SocialPublisherInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

class PublishToSocialTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The channels the worker can publish to. `tiktok` is in the WordPress enum
     * and is **not** here: the worker has no TikTok publisher, and offering a
     * channel the engine refuses would be a promise the runtime cannot keep.
     */
    private const PLATFORMS = array('facebook', 'instagram', 'twitter', 'linkedin');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SocialPublisherInterface $publisher,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'publish_to_social';
    }

    public function getName(): string
    {
        return 'Publish to Social';
    }

    public function getDescription(): string
    {
        return 'Publish one post to one social platform immediately. Dry run is the default: it returns the exact payload without posting. Publishing is irreversible and requires human approval. Scheduling is not supported.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'platform' => array(
                    'type' => 'string',
                    'description' => 'Channel to publish to. TikTok is absent: this worker has no TikTok publisher.',
                    'enum' => self::PLATFORMS,
                ),
                'content' => array(
                    'type' => 'string',
                    'description' => 'The post body. Must fit the channel limit, which the tool enforces before calling.',
                ),
                'image_url' => array(
                    'type' => 'string',
                    'description' => 'Optional image or video URL to attach. Instagram requires one.',
                ),
                'link_url' => array(
                    'type' => 'string',
                    'description' => 'Optional link. The worker has no link field, so it is appended to the copy and the response reports it.',
                ),
                'hashtags' => array(
                    'type' => 'array',
                    'description' => 'Optional tags. A leading # is added when missing.',
                    'items' => array('type' => 'string'),
                ),
                'media_type' => array(
                    'type' => 'string',
                    'description' => 'What kind of asset image_url points at.',
                    'enum' => array('image', 'video'),
                    'default' => 'image',
                ),
                'dry_run' => array(
                    'type' => 'boolean',
                    'description' => 'Return the payload without posting. Default: true, because a published post cannot be taken back.',
                    'default' => true,
                ),
            ),
            'required' => array('platform', 'content'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'publish_posts';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array(
            'external-api',
            'external-communication',
            'irreversible',
            'requires-capability',
            'state-changing',
            'write',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $platform = \strtolower($this->stringParam($arguments, 'platform'));
        $content = \trim($this->stringParam($arguments, 'content'));

        if ('' === $platform || '' === $content) {
            return $this->errors->validationFailed(
                'platform and content are required.',
                array(
                    'platform' => array('Give a channel.'),
                    'content' => array('Give the post body.'),
                ),
            );
        }

        if (! \in_array($platform, self::PLATFORMS, true)) {
            return $this->errors->validationFailed(
                'Unknown platform: ' . $platform . '.',
                array('platform' => array('Allowed: ' . \implode(', ', self::PLATFORMS) . '.')),
            );
        }

        $link = \trim($this->stringParam($arguments, 'link_url'));
        $body = $content;

        // The worker has no link field. Appending is what every platform would do
        // with the URL anyway, and the response says so rather than hiding it.
        if ('' !== $link) {
            $body = $content . "\n\n" . $link;
        }

        $payload = array(
            'platform' => $platform,
            'content' => $body,
            'media_type' => $this->stringParam($arguments, 'media_type', 'image'),
            'hashtags' => $this->stringListParam($arguments, 'hashtags'),
            'link_appended' => '' !== $link,
        );

        $imageUrl = \trim($this->stringParam($arguments, 'image_url'));

        if ('' !== $imageUrl) {
            $payload['image_url'] = $imageUrl;
        }

        if ($this->boolParam($arguments, 'dry_run', true)) {
            return $this->success(
                \sprintf('Dry run: this payload would be published to %s. Nothing was sent.', $platform),
                array('dry_run' => true) + $payload,
            );
        }

        if (! $this->publisher->supports('publish')) {
            return $this->errors->create(
                'social_publishing_unavailable',
                'The bound social engine cannot publish. Configure a media worker that advertises social_publishing and has credentials for the channel.',
            );
        }

        try {
            $result = $this->publisher->publish(array(
                'platform' => $platform,
                'content' => $body,
                'media_url' => $imageUrl,
                'hashtags' => $payload['hashtags'],
                'media_type' => $payload['media_type'],
            ));
        } catch (\Throwable $e) {
            // An adapter that refuses the request itself (unknown channel, no
            // credential, over-length copy, a schedule) is a validation problem,
            // not a publish failure: the caller can fix it without retrying.
            if ($e instanceof \InvalidArgumentException) {
                return $this->errors->validationFailed($e->getMessage());
            }

            return $this->errors->create('publish_failed', $e->getMessage());
        }

        return $this->success(
            \sprintf('Published to %s.', $result['platform']),
            array(
                'dry_run' => false,
                'platform' => $result['platform'],
                'post_id' => $result['post_id'],
                'url' => $result['url'],
                'media_type' => $result['media_type'],
                'link_appended' => '' !== $link,
            ),
        );
    }
}
