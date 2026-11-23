<?php

/**
 * Tool: generate_post_excerpt — a summary of the post, not a rewrite of it.
 *
 * The class is `GeneratePostExcerptCompletionTool` because
 * `GeneratePostExcerptTool` is the quarantined prompt-builder that answers to
 * `generate_post_excerpt_prompt`. Same arrangement as `analyze_image` (§20).
 *
 * WordPress's tool reads the post by id and can save the excerpt back with
 * `auto_save`. This port does neither: it takes the text the caller supplies and
 * returns the excerpt as a string.
 *
 * That is one decision, stated once because the tools that follow make the same
 * one:
 *
 *  - **No `post_id`.** Reading a stored post is a content-store dependency this
 *    runtime has not wired for these tools, and a tool that silently ignored an
 *    id it was given would be worse than one that never accepted it.
 *  - **No `auto_save`.** Writing the result back is `update_post`, which owns the
 *    capability check and the revision history. A generator that also commits
 *    would be a second write path with none of that — the same rule §16 applies
 *    to `status` and §19 to `status_override`.
 *
 * ## Prose is sanitised, code is not
 *
 * The content goes through `stringParam()`, which strips tags — correct here,
 * because an excerpt of HTML should not carry markup and the word count would
 * otherwise be wrong. The tools that take code use `documentParam()` instead; see
 * `AbstractCompletionTool` for why that distinction matters.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

final class GeneratePostExcerptCompletionTool extends AbstractCompletionTool
{
    /**
     * WordPress's tones, verbatim.
     */
    private const TONES = array( 'professional', 'casual', 'engaging', 'informative', 'compelling' );

    private const MAX_CONTENT = 100000;

    public function getSlug(): string
    {
        return 'generate_post_excerpt';
    }

    public function getName(): string
    {
        return 'Generate Post Excerpt';
    }

    public function getDescription(): string
    {
        return 'Writes a short excerpt for a piece of content: a summary in the requested tone and length, without markup. Returns the excerpt text; saving it to a post stays a separate update.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'content'     => array(
                    'type'        => 'string',
                    'description' => 'The text to summarise. HTML is stripped before it reaches the model.',
                    'maxLength'   => self::MAX_CONTENT,
                ),
                'title'       => array(
                    'type'        => 'string',
                    'description' => 'The content\'s title, when it has one.',
                    'maxLength'   => 300,
                ),
                'length'      => array(
                    'type'        => 'integer',
                    'description' => 'Maximum length in words.',
                    'minimum'     => 10,
                    'maximum'     => 100,
                    'default'     => 55,
                ),
                'tone'        => array(
                    'type'        => 'string',
                    'description' => 'Writing tone for the excerpt.',
                    'enum'        => self::TONES,
                    'default'     => 'engaging',
                ),
                'include_cta' => array(
                    'type'        => 'boolean',
                    'description' => 'End with a short call to action.',
                    'default'     => false,
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array( 'content' ),
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

        $length = \max(10, \min(100, $this->intParam($arguments, 'length', 55)));
        $tone   = $this->choice($arguments, 'tone', self::TONES, 'engaging');

        $answer = $this->completeTool(
            $arguments,
            $this->prompt($content, $tone, $length, $this->boolParam($arguments, 'include_cta'))
        );

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $excerpt = $this->tidyText($answer['text']);

        if ('' === $excerpt) {
            return $this->errors->create(
                'excerpt_empty',
                'The model returned an excerpt that was empty after cleanup.'
            );
        }

        return $this->success(
            \sprintf('Wrote a %d-word %s excerpt.', \str_word_count($excerpt), $tone),
            array(
                'excerpt'  => $excerpt,
                'words'    => \str_word_count($excerpt),
                'tone'     => $tone,
                'provider' => $answer['provider'],
                'model'    => $answer['model'],
                'usage'    => $answer['usage'],
            )
        );
    }

    private function prompt(string $content, string $tone, int $length, bool $includeCta): string
    {
        $cta = $includeCta ? ' End with one short sentence inviting the reader to continue.' : '';

        return \sprintf(
            "Write an excerpt for the content below.\n\nRules:\n- At most %d words.\n- %s tone.\n- Plain prose: no markdown, no headings, no quotation marks around it.%s\n- Return the excerpt and nothing else — no preamble, no explanation.\n\nContent:\n%s",
            $length,
            $tone,
            $cta,
            $content
        );
    }
}
