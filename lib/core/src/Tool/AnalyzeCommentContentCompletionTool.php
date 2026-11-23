<?php

/**
 * Tool: analyze_comment_content — is this user-generated text spam or abusive?
 *
 * `AnalyzeCommentContentCompletionTool` because `AnalyzeCommentContentTool` is the
 * quarantined prompt-builder answering to `analyze_comment_content_prompt`.
 *
 * The verdict shape is WordPress's, field for field: `is_spam`, `is_toxic`,
 * `toxicity_level`, `spam_indicators`, `recommended_action`, `confidence`,
 * `reason`, `sensitivity_applied`. Porting a different shape would make the two
 * runtimes answer the same question differently, and this one is consumed by
 * moderation workflows that already branch on those names.
 *
 * ## What is *not* accepted, and why
 *
 * WordPress also takes `comment_email`, `comment_url` and `user_ip`. None are
 * accepted here. They are personal data, the model needs none of them to judge
 * whether text is abusive, and sending them to a third-party provider turns a
 * content decision into a data-disclosure one. The author's display name is
 * accepted because impersonation and naming are part of what the text is doing.
 *
 * ## Sensitivity is validated, not defaulted
 *
 * A typo in `sensitivity` silently becoming `medium` would mean a caller who asked
 * for strict moderation got balanced and was never told. Unknown values are
 * refused; the applied level is echoed back in `sensitivity_applied`.
 *
 * ## Unparseable answers are an error
 *
 * WordPress returns a 500 when the model's answer is not JSON. This does the same
 * rather than defaulting every field: a defaulted verdict reads as "not spam",
 * which is the one answer that must never be produced by accident.
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

final class AnalyzeCommentContentCompletionTool extends AbstractCompletionTool
{
    private const SENSITIVITIES = array( 'low', 'medium', 'high' );

    private const ACTIONS = array( 'approved', 'spam', 'hold' );

    private const TOXICITY = array( 'none', 'low', 'medium', 'high' );

    private const MAX_CONTENT = 20000;

    public function getSlug(): string
    {
        return 'analyze_comment_content';
    }

    public function getName(): string
    {
        return 'Analyze Comment Content';
    }

    public function getDescription(): string
    {
        return 'Judges a piece of user-generated text for spam and abuse at a chosen sensitivity, and recommends approving, holding or marking it as spam. Returns a structured verdict with a confidence and a reason. Commenter emails, URLs and IP addresses are not accepted.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'comment_content' => array(
                    'type'        => 'string',
                    'description' => 'The text to judge.',
                    'maxLength'   => self::MAX_CONTENT,
                ),
                'comment_author'  => array(
                    'type'        => 'string',
                    'description' => 'Display name of the author, when known.',
                    'maxLength'   => 200,
                ),
                'sensitivity'     => array(
                    'type'        => 'string',
                    'description' => 'Moderation sensitivity: low (permissive), medium (balanced), high (strict).',
                    'enum'        => self::SENSITIVITIES,
                    'default'     => 'medium',
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array( 'comment_content' ),
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
        $content = $this->stringParam($arguments, 'comment_content');

        if ('' === $content) {
            return $this->errors->validationFailed(
                'Some content is required.',
                array( 'comment_content' => 'Required.' )
            );
        }

        $sensitivity = $this->strictChoice($arguments, 'sensitivity', self::SENSITIVITIES, 'medium');

        if (null === $sensitivity) {
            return $this->errors->validationFailed(
                'sensitivity must be one of: ' . \implode(', ', self::SENSITIVITIES) . '.',
                array( 'sensitivity' => 'Unsupported value.' )
            );
        }

        $author = \mb_substr($this->stringParam($arguments, 'comment_author'), 0, 200);

        $answer = $this->completeTool($arguments, $this->prompt($content, $author, $sensitivity));

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        $parsed = JsonPayload::decode($answer['text']);

        if (null === $parsed) {
            return $this->errors->create(
                'unparseable_response',
                'The model\'s verdict could not be read as JSON: ' . \mb_substr(\trim($answer['text']), 0, 300)
            );
        }

        $verdict = array(
            'is_spam'             => (bool) ($parsed['is_spam'] ?? false),
            'is_toxic'            => (bool) ($parsed['is_toxic'] ?? false),
            'toxicity_level'      => JsonPayload::oneOf($parsed['toxicity_level'] ?? null, self::TOXICITY, 'none'),
            'spam_indicators'     => JsonPayload::stringList($parsed['spam_indicators'] ?? null, 20),
            'recommended_action'  => JsonPayload::oneOf($parsed['recommended_action'] ?? null, self::ACTIONS, 'hold'),
            'confidence'          => JsonPayload::confidence($parsed['confidence'] ?? null),
            'reason'              => \mb_substr(\trim((string) ($parsed['reason'] ?? '')), 0, 1000),
            'sensitivity_applied' => $sensitivity,
        );

        return $this->success(
            \sprintf(
                'Recommendation: %s (%s toxicity, %.2f confidence at %s sensitivity).',
                $verdict['recommended_action'],
                $verdict['toxicity_level'],
                $verdict['confidence'],
                $sensitivity
            ),
            $verdict + array(
                'provider' => $answer['provider'],
                'model'    => $answer['model'],
                'usage'    => $answer['usage'],
            )
        );
    }

    private function prompt(string $content, string $author, string $sensitivity): string
    {
        $rules = array(
            'low'    => 'Only flag clear-cut abuse and unmistakable spam.',
            'medium' => 'Flag abuse and spam, ignoring rudeness that is merely blunt.',
            'high'   => 'Flag anything that could be read as abusive, promotional or off-topic.',
        );

        return \sprintf(
            "Judge the user-generated text below.\n\nSensitivity: %s. %s\n\nReply with a JSON object only, no prose and no fence:\n{\n  \"is_spam\": bool,\n  \"is_toxic\": bool,\n  \"toxicity_level\": \"none|low|medium|high\",\n  \"spam_indicators\": [string],\n  \"recommended_action\": \"approved|spam|hold\",\n  \"confidence\": 0.0-1.0,\n  \"reason\": string\n}\n\nAuthor: %s\n\nText:\n%s",
            $sensitivity,
            $rules[ $sensitivity ],
            '' === $author ? '(unknown)' : $author,
            $content
        );
    }
}
