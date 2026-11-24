<?php

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\SocialComposerInterface;

/**
 * Social channels and copy over the media worker — Proposal 164 §164.4.6.
 *
 * Two routes, both read-only: `GET /api/social/accounts` and
 * `POST /api/social/generate-content`. Publishing (`POST /api/social/post`) is
 * deliberately **not** implemented here — it is an irreversible send and belongs
 * to a contract the necessity guard gates, not to an adapter that would make it
 * one method call away.
 *
 * ## The template fallback is not copy
 *
 * `/generate-content` uses a model when a key resolves and otherwise returns
 * `generated_by: 'template'` with `prompt_templates` — instructions, not posts.
 * `compose()` normalises both shapes into `posts` or `prompts` so the tool layer
 * cannot accidentally render one as the other, and `note` carries the worker's
 * own tip so the caller learns which key to set.
 *
 * ## `supports()` asks the matrix
 *
 * `social_accounts` and `social_composition` are newer than the route file, so a
 * configured worker is not evidence that it has them. Asking the matrix turns a
 * version skew into a named refusal instead of a 404 mid-run.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */
final class MediaWorkerSocialComposer implements SocialComposerInterface
{
    /**
     * Operation => the worker capability that has to be declared.
     */
    private const CAPABILITIES = array(
        'accounts' => 'social_accounts',
        'compose' => 'social_composition',
    );

    public function __construct(
        private readonly MediaWorkerClient $worker,
        private readonly MediaWorkerCapabilityProbe $probe,
    ) {
    }

    /**
     * {@inheritDoc}
     */
    public function accounts(): array
    {
        $body = $this->worker->getJson('/api/social/accounts', $this->timeout('social_timeout', 30));

        $accounts = array();

        foreach ((array) ($body['platforms'] ?? array()) as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $id = (string) ($row['id'] ?? '');

            if ('' === $id) {
                continue;
            }

            $accounts[] = array(
                'id' => $id,
                'name' => (string) ($row['name'] ?? $id),
                'connected' => (bool) ($row['connected'] ?? false),
                'max_chars' => (int) ($row['max_chars'] ?? 0),
            );
        }

        return $accounts;
    }

    /**
     * {@inheritDoc}
     */
    public function compose(array $options = array()): array
    {
        $topic = \trim((string) ($options['topic'] ?? ''));

        if ('' === $topic) {
            throw new \InvalidArgumentException('A topic is required to compose social copy.');
        }

        $fields = array(
            'topic' => $topic,
            'tone' => (string) ($options['tone'] ?? 'professional'),
            'include_hashtags' => \array_key_exists('include_hashtags', $options)
                ? (bool) $options['include_hashtags']
                : true,
            'count' => \max(1, \min(10, (int) ($options['count'] ?? 3))),
            'language' => (string) ($options['language'] ?? 'en'),
        );

        $platform = \trim((string) ($options['platform'] ?? ''));

        if ('' !== $platform) {
            $fields['platform'] = $platform;
        }

        $body = $this->worker->postJson(
            '/api/social/generate-content',
            $fields,
            $this->timeout('social_timeout', 60),
        );

        $provider = (string) ($body['generated_by'] ?? 'unknown');

        return array(
            'provider' => $provider,
            'language' => (string) ($body['language'] ?? $fields['language']),
            'topic' => (string) ($body['topic'] ?? $topic),
            'tone' => (string) ($body['tone'] ?? $fields['tone']),
            'suggested_hashtags' => $this->hashtags($body['suggested_hashtags'] ?? null),
            'posts' => $this->posts($body['content'] ?? null),
            'prompts' => $this->prompts($body['prompt_templates'] ?? null),
            'note' => isset($body['tip']) && \is_string($body['tip']) ? $body['tip'] : null,
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
     * Flatten the worker's hashtag categories into one list.
     *
     * The route answers a map of category => tags; a caller wants the tags.
     * Duplicates are dropped because two categories share entries.
     *
     * @return list<string>
     */
    private function hashtags(mixed $raw): array
    {
        if (! \is_array($raw)) {
            return array();
        }

        $tags = array();

        foreach ($raw as $group) {
            if (! \is_array($group)) {
                continue;
            }

            foreach ($group as $tag) {
                if (\is_string($tag) && '' !== \trim($tag)) {
                    $tags[\trim($tag)] = true;
                }
            }
        }

        return \array_keys($tags);
    }

    /**
     * Normalise model-generated copy into a list of per-channel variants.
     *
     * @return list<array{platform: string, character_limit: int, variants: list<string>}>
     */
    private function posts(mixed $raw): array
    {
        if (! \is_array($raw)) {
            return array();
        }

        $posts = array();

        foreach ($raw as $row) {
            if (! \is_array($row)) {
                continue;
            }

            $variants = array();

            foreach ((array) ($row['variants'] ?? array()) as $variant) {
                if (\is_string($variant) && '' !== \trim($variant)) {
                    $variants[] = \trim($variant);
                }
            }

            if (array() === $variants) {
                continue;
            }

            $posts[] = array(
                'platform' => (string) ($row['platform'] ?? ''),
                'character_limit' => (int) ($row['character_limit'] ?? 0),
                'variants' => $variants,
            );
        }

        return $posts;
    }

    /**
     * Normalise the template fallback into a map of platform => instruction.
     *
     * @return array<string, string>
     */
    private function prompts(mixed $raw): array
    {
        if (! \is_array($raw)) {
            return array();
        }

        $prompts = array();

        foreach ($raw as $platform => $prompt) {
            if (\is_string($platform) && \is_string($prompt) && '' !== \trim($prompt)) {
                $prompts[$platform] = \trim($prompt);
            }
        }

        return $prompts;
    }

    private function timeout(string $key, int $fallback): int
    {
        return \max(1, (int) \config('oos.media_worker.'.$key, $fallback));
    }
}
