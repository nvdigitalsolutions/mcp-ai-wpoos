<?php

/**
 * Propose post ideas from an industry, topical seeds and a brand voice.
 *
 * Proposal 164 §164.4.6. The WordPress tool takes five separate context fields
 * (`industry`, `topics`, `target_audience`, `content_types`, `brand_voice`); the
 * worker's composer accepts one `topic`. Rather than silently dropping four of
 * them, this port **folds them into the topic sentence** — which is what a
 * composer can act on — and says so in the description. Dropping is the failure
 * mode that matters: a caller who named an audience would get ideas aimed at
 * nobody and never learn why.
 *
 * Same two-shape result as `generate_social_captions`: finished copy when a
 * model resolves, prompt templates otherwise, with `provider` naming which.
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

class GeneratePostIdeasTool extends AbstractTool
{
    /**
     * Platforms the composer knows, plus the three the WordPress tool offers that
     * this worker cannot post to. They are accepted for idea generation — an idea
     * for Pinterest is still an idea — and the payload says the channel is
     * unconnected.
     */
    private const PLATFORMS = array(
        'facebook',
        'instagram',
        'twitter',
        'linkedin',
        'pinterest',
        'tiktok',
        'youtube',
    );

    /**
     * Platforms the worker can actually compose for.
     */
    private const COMPOSABLE = array('facebook', 'instagram', 'twitter', 'linkedin');

    private const VOICES = array(
        'professional',
        'casual',
        'friendly',
        'authoritative',
        'humorous',
        'inspirational',
        'educational',
    );

    public function __construct(
        ErrorFactoryInterface $e,
        private readonly SocialComposerInterface $social,
    ) {
        parent::__construct($e);
    }

    public function getSlug(): string
    {
        return 'generate_post_ideas';
    }

    public function getName(): string
    {
        return 'Generate Post Ideas';
    }

    public function getDescription(): string
    {
        return 'Propose social post ideas for an industry and brand voice. Industry, audience, content types and topics are combined into the subject the composer works from, because the composer takes one topic; none of them is silently dropped. Does not publish.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'industry' => array(
                    'type' => 'string',
                    'description' => 'Industry or niche the ideas should serve.',
                ),
                'topics' => array(
                    'type' => 'array',
                    'description' => 'Seed subjects to build ideas around.',
                    'items' => array('type' => 'string'),
                ),
                'target_audience' => array(
                    'type' => 'string',
                    'description' => 'Who the posts are for. Folded into the composer\'s subject.',
                ),
                'content_types' => array(
                    'type' => 'array',
                    'description' => 'Kinds of post to favour. Folded into the composer\'s subject.',
                    'items' => array(
                        'type' => 'string',
                        'enum' => array(
                            'promotional',
                            'educational',
                            'entertaining',
                            'inspirational',
                            'news',
                            'behind-the-scenes',
                            'user-generated',
                            'poll',
                            'question',
                        ),
                    ),
                ),
                'brand_voice' => array(
                    'type' => 'string',
                    'description' => 'Voice for the ideas.',
                    'enum' => self::VOICES,
                    'default' => 'professional',
                ),
                'platform' => array(
                    'type' => 'string',
                    'description' => 'Target platform. Pinterest, TikTok and YouTube can be suggested but are not composable by this worker, and the response says so.',
                    'enum' => self::PLATFORMS,
                ),
                'count' => array(
                    'type' => 'integer',
                    'description' => 'Variants to propose (1-10).',
                    'minimum' => 1,
                    'maximum' => 10,
                    'default' => 5,
                ),
                'language' => array(
                    'type' => 'string',
                    'description' => 'Language code for the ideas.',
                    'default' => 'en',
                ),
            ),
            'required' => array(),
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
        $topic = $this->subject($arguments);

        if ('' === $topic) {
            return $this->errors->validationFailed(
                'Give at least one of industry, topics, target_audience or content_types.',
                array('industry' => array('Any one seed is enough.')),
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

        $voice = \strtolower($this->stringParam($arguments, 'brand_voice', 'professional'));

        if (! \in_array($voice, self::VOICES, true)) {
            $voice = 'professional';
        }

        try {
            $result = $this->social->compose(array(
                // Only a composable platform is sent. An idea for Pinterest is a
                // text idea, and the composer would reject a channel it cannot
                // measure against a character limit.
                'platform' => \in_array($platform, self::COMPOSABLE, true) ? $platform : '',
                'topic' => $topic,
                'tone' => $voice,
                'count' => \max(1, \min(10, $this->intParam($arguments, 'count', 5))),
                'include_hashtags' => $this->boolParam($arguments, 'include_hashtags', true),
                'language' => $this->stringParam($arguments, 'language', 'en'),
            ));
        } catch (\Throwable $e) {
            return $this->errors->create('social_compose_failed', $e->getMessage());
        }

        $result['requested_platform'] = '' === $platform ? null : $platform;
        $result['composable'] = '' === $platform || \in_array($platform, self::COMPOSABLE, true);
        $result['subject'] = $topic;

        return $this->success($this->summary($result, $platform), $result);
    }

    /**
     * Fold the separate WordPress context fields into the one subject the
     * composer accepts.
     *
     * Each field is optional on its own; the join is what makes "ideas for a
     * bakery aimed at new parents, favouring polls" reach the model as one
     * instruction instead of one word.
     *
     * @param array<string, mixed> $arguments
     */
    private function subject(array $arguments): string
    {
        $parts = array();

        $industry = \trim($this->stringParam($arguments, 'industry'));

        if ('' !== $industry) {
            $parts[] = 'industry: ' . $industry;
        }

        $topics = array();

        foreach ($this->stringListParam($arguments, 'topics') as $topic) {
            $topic = \trim((string) $topic);

            if ('' !== $topic) {
                $topics[] = $topic;
            }
        }

        if (array() !== $topics) {
            $parts[] = 'topics: ' . \implode(', ', $topics);
        }

        $audience = \trim($this->stringParam($arguments, 'target_audience'));

        if ('' !== $audience) {
            $parts[] = 'audience: ' . $audience;
        }

        $types = array();

        foreach ($this->stringListParam($arguments, 'content_types') as $type) {
            $type = \trim((string) $type);

            if ('' !== $type) {
                $types[] = $type;
            }
        }

        if (array() !== $types) {
            $parts[] = 'content types: ' . \implode(', ', $types);
        }

        return \implode('; ', $parts);
    }

    /**
     * A message that states what came back and where it can go.
     *
     * @param array<string, mixed> $result
     */
    private function summary(array $result, string $platform): string
    {
        $posts = (array) ($result['posts'] ?? array());
        $prompts = (array) ($result['prompts'] ?? array());

        $unconnected = '' !== $platform && ! \in_array($platform, self::COMPOSABLE, true)
            ? ' ' . $platform . ' is not a channel this worker composes for, so the ideas are generic.'
            : '';

        if (array() !== $posts) {
            return \sprintf('%d idea variant(s) from %s.%s', \count($posts), (string) ($result['provider'] ?? 'the composer'), $unconnected);
        }

        if (array() !== $prompts) {
            return \sprintf(
                'No model key is configured, so the composer returned %d prompt template(s) instead of ideas. Set OPENAI_API_KEY to generate them.%s',
                \count($prompts),
                $unconnected
            );
        }

        return 'The composer returned no ideas and no prompts.' . $unconnected;
    }
}
