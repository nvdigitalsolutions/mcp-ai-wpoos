<?php

/**
 * Tool: seo_meta_optimizer — a title and description that fit.
 *
 * `SeoMetaOptimizerCompletionTool` because `SEOMetaOptimizerTool` is the
 * quarantined prompt-builder answering to `seo_meta_optimizer_prompt`.
 *
 * The two numbers that matter are 60 and 160 characters: a search result truncates
 * a longer title and clips a longer description. The tool therefore **reports the
 * lengths and whether each fits** rather than truncating. Silent truncation would
 * cut a sentence in half and leave the caller believing it had a finished
 * description; `within_limits` lets a caller loop once with a shorter brief
 * instead.
 *
 * ## Same two decisions as the other generators
 *
 * No `post_id` and no `auto_save` — the value comes back, saving it is
 * `update_post`. See `GeneratePostExcerptCompletionTool` for the reasoning, which
 * is shared.
 *
 * ## Unparseable answers are an error
 *
 * The answer is a JSON object. If it cannot be read, the tool refuses and shows the
 * beginning of what arrived, because an empty meta description is a silent
 * regression on the page it was meant to improve.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Provider\JsonPayload;

final class SeoMetaOptimizerCompletionTool extends AbstractCompletionTool
{
    /**
     * What a search result shows before it truncates.
     */
    public const MAX_TITLE_LENGTH = 60;

    public const MAX_DESCRIPTION_LENGTH = 160;

    private const MAX_CONTENT = 100000;

    public function getSlug(): string
    {
        return 'seo_meta_optimizer';
    }

    public function getName(): string
    {
        return 'SEO Meta Optimizer';
    }

    public function getDescription(): string
    {
        return 'Writes a search-result title (max 60 characters) and meta description (max 160 characters) for a piece of content around a focus keyword, and reports whether each fits and what still needs attention. Returns the fields; saving them stays a separate update.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'content'            => array(
                    'type'        => 'string',
                    'description' => 'The text to optimise. HTML is stripped before it reaches the model.',
                    'maxLength'   => self::MAX_CONTENT,
                ),
                'title'              => array(
                    'type'        => 'string',
                    'description' => 'The current title, when there is one.',
                    'maxLength'   => 300,
                ),
                'focus_keyword'      => array(
                    'type'        => 'string',
                    'description' => 'The phrase the page should be found for.',
                    'maxLength'   => 120,
                ),
                'target_audience'    => array(
                    'type'        => 'string',
                    'description' => 'Who the page is for, when that should shape the wording.',
                    'maxLength'   => 200,
                ),
                'include_variations' => array(
                    'type'        => 'boolean',
                    'description' => 'Return up to two alternative titles and descriptions.',
                    'default'     => false,
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array( 'content', 'focus_keyword' ),
            'additionalProperties' => false,
        );
    }

    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    public function getCapabilityFlags(): array
    {
        return array(
            'read-only',
            'external-api',
            'network-dependent',
            'consumes-tokens',
            'requires-credentials',
            'model-dependent',
            'rate-limited',
            'requires-capability',
        );
    }

    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $content = $this->stringParam($arguments, 'content');

        if ('' === $content) {
            return $this->errors->validationFailed(
                'Some content is required.',
                array( 'content' => 'Required.' )
            );
        }

        $keyword = \mb_substr($this->stringParam($arguments, 'focus_keyword'), 0, 120);

        if ('' === $keyword) {
            return $this->errors->validationFailed(
                'A focus keyword is required.',
                array( 'focus_keyword' => 'Required.' )
            );
        }

        $includeVariations = $this->boolParam($arguments, 'include_variations');

        $answer = $this->completeTool($arguments, $this->prompt(
            $content,
            \mb_substr($this->stringParam($arguments, 'title'), 0, 300),
            $keyword,
            \mb_substr($this->stringParam($arguments, 'target_audience'), 0, 200),
            $includeVariations
        ));

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $parsed = JsonPayload::decode($answer['text']);

        if (null === $parsed) {
            return $this->errors->create(
                'unparseable_response',
                'The model\'s answer could not be read as JSON: ' . \mb_substr(\trim($answer['text']), 0, 300)
            );
        }

        $metaTitle       = \trim((string) ($parsed['meta_title'] ?? ''));
        $metaDescription = \trim((string) ($parsed['meta_description'] ?? ''));

        if ('' === $metaTitle || '' === $metaDescription) {
            return $this->errors->create(
                'incomplete_response',
                'The model\'s answer was missing a title or a description.'
            );
        }

        $titleFits       = \mb_strlen($metaTitle) <= self::MAX_TITLE_LENGTH;
        $descriptionFits = \mb_strlen($metaDescription) <= self::MAX_DESCRIPTION_LENGTH;
        $withinLimits    = $titleFits && $descriptionFits;

        return $this->success(
            \sprintf(
                'Meta title %d/%d characters, description %d/%d.%s',
                \mb_strlen($metaTitle),
                self::MAX_TITLE_LENGTH,
                \mb_strlen($metaDescription),
                self::MAX_DESCRIPTION_LENGTH,
                $withinLimits ? '' : ' One or both exceed the limit.'
            ),
            array(
                'meta_title'              => $metaTitle,
                'meta_description'        => $metaDescription,
                'meta_title_length'       => \mb_strlen($metaTitle),
                'meta_description_length' => \mb_strlen($metaDescription),
                'within_limits'           => $withinLimits,
                'focus_keyword'           => $keyword,
                'keywords'                => JsonPayload::stringList($parsed['keywords'] ?? null, 20),
                'issues'                  => JsonPayload::stringList($parsed['issues'] ?? null, 20),
                'variations'              => $includeVariations ? $this->variations($parsed['variations'] ?? null) : array(),
                'provider'                => $answer['provider'],
                'model'                   => $answer['model'],
                'usage'                   => $answer['usage'],
            )
        );
    }

    /**
     * @return list<array{meta_title: string, meta_description: string}>
     */
    private function variations(mixed $value): array
    {
        $variations = array();

        foreach (\is_array($value) ? $value : array() as $item) {
            if (! \is_array($item)) {
                continue;
            }

            $title       = \trim((string) ($item['meta_title'] ?? ''));
            $description = \trim((string) ($item['meta_description'] ?? ''));

            if ('' === $title || '' === $description) {
                continue;
            }

            $variations[] = array(
                'meta_title'       => $title,
                'meta_description' => $description,
            );

            if (\count($variations) >= 2) {
                break;
            }
        }

        return $variations;
    }

    private function prompt(string $content, string $title, string $keyword, string $audience, bool $includeVariations): string
    {
        $lines = array(
            'Write search-result metadata for the content below.',
            '',
            \sprintf('- meta_title: at most %d characters, must contain "%s".', self::MAX_TITLE_LENGTH, $keyword),
            \sprintf('- meta_description: at most %d characters, one or two sentences, must contain or clearly imply "%s".', self::MAX_DESCRIPTION_LENGTH, $keyword),
            '- No clickbait, no ALL CAPS, no keyword stuffing.',
            '',
            'Reply with a JSON object only, no prose and no fence:',
            '{',
            '  "meta_title": string,',
            '  "meta_description": string,',
            '  "keywords": [string],',
            '  "issues": [string]',
        );

        if ($includeVariations) {
            $lines[] = '  ,"variations": [{"meta_title": string, "meta_description": string}]';
        }

        $lines[] = '}';

        if ('' !== $title) {
            $lines[] = '';
            $lines[] = 'Current title: ' . $title;
        }

        if ('' !== $audience) {
            $lines[] = 'Audience: ' . $audience;
        }

        $lines[] = '';
        $lines[] = 'Content:';
        $lines[] = $content;

        return \implode("\n", $lines);
    }
}
