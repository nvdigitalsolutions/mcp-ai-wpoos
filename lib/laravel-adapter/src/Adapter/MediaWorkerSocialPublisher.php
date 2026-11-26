<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\SocialPublisherInterface;

/**
 * Publishing over the media worker — Proposal 164 §164.4.6.
 *
 * `POST /api/social/post` is the worker's per-platform publisher. This adapter
 * owns the pre-flight that makes its failures legible: the channel list is read
 * first, so an unknown platform, a missing credential and over-length copy are
 * all named here instead of surfacing as `Media worker error: LinkedIn API
 * error`.
 *
 * ## The provider detail is not invented
 *
 * When the platform refuses, `postJson()` raises `Media worker error: <error>`,
 * and the worker's `detail` field — the actual provider response — is not part
 * of that contract. Rather than guess at it, the message says a token may have
 * expired and points at the worker's log. Fabricating a plausible reason is the
 * failure mode this codebase keeps removing.
 *
 * ## Scheduling is refused, not ignored
 *
 * The route accepts `schedule`. This adapter never sends it: a scheduled publish
 * fires with no human present, which is a different risk from a publish a
 * person just approved. Refusing is safer than silently scheduling.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerSocialPublisher implements SocialPublisherInterface
{
    /**
     * Operation => the worker capability that has to be declared.
     */
    private const CAPABILITIES = array(
        'publish' => 'social_publishing',
    );

    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
        private readonly MediaWorkerSocialComposer $channels,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function publish(array $options): array
    {
        $platform = \strtolower(\trim((string) ($options['platform'] ?? '')));

        if ('' === $platform) {
            throw new \InvalidArgumentException('A platform is required to publish.');
        }

        $content = \trim((string) ($options['content'] ?? ''));

        if ('' === $content) {
            throw new \InvalidArgumentException('Content is required to publish.');
        }

        if (\array_key_exists('schedule', $options) && '' !== (string) $options['schedule']) {
            throw new \InvalidArgumentException(
                'Scheduling is not offered by this publisher: a scheduled post fires without a human present. Publish now, or use a scheduling tool that has its own approval gate.'
            );
        }

        $channel = $this->channel($platform);

        if (null === $channel) {
            throw new \InvalidArgumentException(
                \sprintf(
                    'Unknown platform: %s. This worker can publish to: %s.',
                    $platform,
                    \implode(', ', \array_column($this->channels->accounts(), 'id'))
                )
            );
        }

        if (true !== $channel['connected']) {
            throw new \RuntimeException(
                \sprintf(
                    '%s has no credentials configured on the media worker, so nothing can be published. Set the platform token on the worker (its /api/social/accounts response names which one).',
                    $channel['name']
                )
            );
        }

        $limit = (int) $channel['max_chars'];

        if ($limit > 0 && \mb_strlen($content) > $limit) {
            throw new \InvalidArgumentException(
                \sprintf('%s accepts at most %d characters; the copy is %d.', $channel['name'], $limit, \mb_strlen($content))
            );
        }

        $mediaType = \strtolower(\trim((string) ($options['media_type'] ?? 'image')));

        if (! \in_array($mediaType, array('image', 'video'), true)) {
            $mediaType = 'image';
        }

        $fields = array(
            'platform' => $platform,
            'content' => $content,
            'media_type' => $mediaType,
        );

        $mediaUrl = \trim((string) ($options['media_url'] ?? ''));

        if ('' !== $mediaUrl) {
            $fields['media_url'] = $mediaUrl;
        }

        $hashtags = $this->hashtags($options['hashtags'] ?? null);

        if ('' !== $hashtags) {
            $fields['hashtags'] = $hashtags;
        }

        try {
            $body = $this->worker->postJson(
                '/api/social/post',
                $fields,
                \max(1, (int) \config('oos.media_worker.social_timeout', 60)),
            );
        } catch (\RuntimeException $e) {
            // A 502 from the platform is a credential or scope problem far more
            // often than anything else, and the worker's own `detail` is not in
            // this error contract. Say what is knowable rather than invent it.
            throw new \RuntimeException(
                $e->getMessage().' — the platform token may be expired or lack write scope; the worker log carries the provider response.',
                0,
                $e
            );
        }

        return array(
            'platform' => (string) ($body['platform'] ?? $platform),
            'post_id' => isset($body['post_id']) ? (string) $body['post_id'] : null,
            'url' => isset($body['url']) && \is_string($body['url']) ? $body['url'] : null,
            'media_type' => $mediaType,
        );
    }

    /**
     * {@inheritDoc}
     */
    public function supports(string $operation): bool
    {
        $capability = self::CAPABILITIES[$operation] ?? null;

        if (null === $capability || ! $this->worker->isConfigured()) {
            return false;
        }

        return $this->probe->report()->supports($capability);
    }

    /**
     * {@inheritDoc}
     */
    public function isAvailable(): bool
    {
        return $this->worker->isConfigured();
    }

    /**
     * One channel row from the worker's own list, or null when it does not exist.
     *
     * @return array{id: string, name: string, connected: bool, max_chars: int}|null
     */
    private function channel(string $platform): ?array
    {
        foreach ($this->channels->accounts() as $row) {
            if ($row['id'] === $platform) {
                return $row;
            }
        }

        return null;
    }

    /**
     * The worker appends `hashtags` to the body as one string, so a list is
     * joined here rather than sent as an array the route would stringify badly.
     *
     * @param mixed $raw
     */
    private function hashtags(mixed $raw): string
    {
        if (\is_string($raw)) {
            return \trim($raw);
        }

        if (! \is_array($raw)) {
            return '';
        }

        $tags = array();

        foreach ($raw as $tag) {
            if (! \is_string($tag)) {
                continue;
            }

            $tag = \trim($tag);

            if ('' === $tag) {
                continue;
            }

            $tags[] = '#' === $tag[0] ? $tag : '#'.$tag;
        }

        return \implode(' ', $tags);
    }
}
