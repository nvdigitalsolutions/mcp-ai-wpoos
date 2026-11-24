<?php

/**
 * Draft caption variants for one topic. Never publishes.
 *
 * Proposal 164 §164.4.6. Two deviations from the WordPress tool, both because the
 * port has no `wp_posts` to read:
 *
 *  - It takes `topic` instead of `post_id`. A caption needs a subject, and the
 *    subject here is text the caller supplies rather than a post id this runtime
 *    cannot resolve.
 *  - It returns either finished copy or **prompt templates**, and says which.
 *    The worker composes with a model when a key resolves and otherwise returns
 *    instructions for a human. `provider: "template"` with `prompts` is the
 *    honest report of that; rendering a prompt as a caption would be the lie
 *    this codebase keeps fixing.
 *
 * The tool holds no publish path at all. Publishing is `publish_to_social`,
 * which the necessity guard gates because it is irreversible.
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
use Nvoos\Core\Domain\Contract\SocialComposerInterface;

class GenerateSocialCaptionsTool extends AbstractTool
{
    /**
     * The platforms the composer knows. Kept in step with the worker's own map so
     * an unknown slug is refused here with a useful list rather than by a 400.
     */
    private const PLATFORMS = array('facebook', 'instagram', 'twitter', 'linkedin');

    private const TONES = array('professional', 'casual', 'humorous', 'informative');

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SocialComposerInterface $social,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_social_captions';
    }

    public function getName(): string
    {
        return 'Generate Social Captions';
    }

    public function getDescription(): string
    {
        return 'Draft caption variants for a topic, per platform and tone, with optional hashtag suggestions. Returns finished copy when a model is configured and prompt templates otherwise, and says which. Does not publish.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'topic' => array(
                    'type' => 'string',
                    'description' => 'What the post is about. The WordPress tool takes a post id; this runtime has no posts to read, so the subject is given directly.',
                ),
                'platform' => array(
                    'type' => 'string',
                    'description' => 'Target platform. Omit for every known platform.',
                    'enum' => self::PLATFORMS,
                ),
                'tone' => array(
                    'type' => 'string',
                    'description' => 'Voice for the copy.',
                    'enum' => self::TONES,
                    'default' => 'professional',
                ),
                'count' => array(
                    'type' => 'integer',
                    'description' => 'Variants per platform (1-5).',
                    'minimum' => 1,
                    'maximum' => 5,
                    'default' => 3,
                ),
                'include_hashtags' => array(
                    'type' => 'boolean',
                    'description' => 'Whether to suggest hashtags.',
                    'default' => true,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'Language code for the copy.',
                    'default' => 'en',
                ),
            ),
            'required' => array('topic'),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'read';
    }

    /**
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('consumes-tokens', 'external-api', 'read-only', 'requires-capability');
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $topic = \trim($this->stringParam($arguments, 'topic'));

        if ('' === $topic) {
            return $this->errors->validationFailed(
                'A topic is required.',
                array('topic' => array('Describe what the post is about.')),
            );
        }

        if (! $this->social->supports('compose')) {
            return $this->errors->create(
                'social_unavailable',
                'No social composer is available on this host. Configure a media worker that advertises social_composition.',
            );
        }

        $platform = \strtolower($this->stringParam($arguments, 'platform'));

        if ('' !== $platform && ! \in_array($platform, self::PLATFORMS, true)) {
            return $this->errors->validationFailed(
                'Unknown platform: ' . $platform . '.',
                array('platform' => array('Allowed: ' . \implode(', ', self::PLATFORMS) . '.')),
            );
        }

        $tone = \strtolower($this->stringParam($arguments, 'tone', 'professional'));

        if (! \in_array($tone, self::TONES, true)) {
            $tone = 'professional';
        }

        try {
            $result = $this->social->compose(array(
                'topic' => $topic,
                'platform' => $platform,
                'tone' => $tone,
                'count' => \max(1, \min(5, $this->intParam($arguments, 'count', 3))),
                'include_hashtags' => $this->boolParam($arguments, 'include_hashtags', true),
                'language' => $this->stringParam($arguments, 'language', 'en'),
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('social_compose_failed', $e->getMessage());
        }

        return $this->success($this->summary($result), $result);
    }

    /**
     * A message that states what came back, not what was asked for.
     *
     * @param array<string, mixed> $result
     */
    private function summary(array $result): string
    {
        $posts = (array) ($result['posts'] ?? array());
        $prompts = (array) ($result['prompts'] ?? array());

        if (array() !== $posts) {
            $variants = 0;

            foreach ($posts as $row) {
                $variants += \count((array) ($row['variants'] ?? array()));
            }

            return \sprintf(
                '%d caption variant(s) from %s.',
                $variants,
                (string) ($result['provider'] ?? 'the composer')
            );
        }

        if (array() !== $prompts) {
            return \sprintf(
                'No model key is configured, so the composer returned %d prompt template(s) instead of finished copy. Set OPENAI_API_KEY to generate captions.',
                \count($prompts)
            );
        }

        return 'The composer returned no copy and no prompts.';
    }
}
