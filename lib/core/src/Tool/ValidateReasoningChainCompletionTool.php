<?php

/**
 * Tool: validate_reasoning_chain — does the conclusion follow?
 *
 * `ValidateReasoningChainCompletionTool` because `ValidateReasoningChainTool` is
 * the quarantined prompt-builder answering to `validate_reasoning_chain_prompt`.
 *
 * A chain of steps in, a verdict out. The interesting design question is what
 * "valid" means, and the answer this tool gives is deliberately narrow: **the
 * conclusion follows from the stated premises and steps**, not that the conclusion
 * is true. A model asked to judge truth will judge plausibility; asked to judge
 * inference, it can point at the step that breaks.
 *
 * Steps are read with `documentListParam()`, not sanitised as prose: a step may
 * legitimately quote code or markup, and the reviewer must see what the caller
 * wrote.
 *
 * ## Unparseable answers are an error
 *
 * `valid` defaults to nothing. A verdict of "valid" produced by a parse failure is
 * the single most damaging outcome this tool could have, so a JSON failure — or a
 * JSON object without the field — is refused, with the beginning of what arrived.
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

final class ValidateReasoningChainCompletionTool extends AbstractCompletionTool
{
    private const MAX_STEPS = 20;

    private const MAX_STEP_LENGTH = 2000;

    public function getSlug(): string
    {
        return 'validate_reasoning_chain';
    }

    public function getName(): string
    {
        return 'Validate Reasoning Chain';
    }

    public function getDescription(): string
    {
        return 'Checks whether a conclusion follows from its premises and intermediate steps, and names the step where the inference breaks. Judges inference, not truth. Returns a verdict with a confidence, the unsupported claims it found and concrete repairs.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'reasoning_steps' => array(
                    'type'        => 'array',
                    'description' => 'The chain, in order. Each element is one step.',
                    'items'       => array(
                        'type'      => 'string',
                        'maxLength' => self::MAX_STEP_LENGTH,
                    ),
                    'maxItems'    => self::MAX_STEPS,
                ),
                'premises'        => array(
                    'type'        => 'array',
                    'description' => 'What the chain takes as given.',
                    'items'       => array(
                        'type'      => 'string',
                        'maxLength' => self::MAX_STEP_LENGTH,
                    ),
                    'maxItems'    => self::MAX_STEPS,
                ),
                'conclusion'      => array(
                    'type'        => 'string',
                    'description' => 'The claim being argued for. Omit when the last step is the conclusion.',
                    'maxLength'   => self::MAX_STEP_LENGTH,
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.'),
            ),
            'required'             => array( 'reasoning_steps' ),
            'additionalProperties' => false,
        );
    }

    /**
     * WordPress gates this on `read`: judging an argument reveals nothing the
     * caller could not read for itself.
     */
    public function getRequiredCapability(): string
    {
        return 'read';
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
        $steps = $this->documentListParam($arguments, 'reasoning_steps', self::MAX_STEPS, self::MAX_STEP_LENGTH);

        if (array() === $steps) {
            return $this->errors->validationFailed(
                'At least one reasoning step is required.',
                array( 'reasoning_steps' => 'Required.' )
            );
        }

        $premises   = $this->documentListParam($arguments, 'premises', self::MAX_STEPS, self::MAX_STEP_LENGTH);
        $conclusion = $this->documentParam($arguments, 'conclusion', self::MAX_STEP_LENGTH);

        $answer = $this->completeTool($arguments, $this->prompt($steps, $premises, $conclusion));

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

        if (! \array_key_exists('valid', $parsed)) {
            // Without the field the tool's whole answer is missing. Defaulting it
            // would decide the question the caller asked.
            return $this->errors->create(
                'unparseable_response',
                'The model\'s verdict did not include a "valid" field.'
            );
        }

        $verdict = array(
            'valid'              => (bool) $parsed['valid'],
            'confidence'         => JsonPayload::confidence($parsed['confidence'] ?? null),
            'broken_step'        => \is_numeric($parsed['broken_step'] ?? null) ? (int) $parsed['broken_step'] : null,
            'issues'             => JsonPayload::stringList($parsed['issues'] ?? null),
            'unsupported_claims' => JsonPayload::stringList($parsed['unsupported_claims'] ?? null),
            'suggestions'        => JsonPayload::stringList($parsed['suggestions'] ?? null),
            'steps_checked'      => \count($steps),
        );

        return $this->success(
            \sprintf(
                'The chain %s (%.2f confidence, %d step%s checked).',
                $verdict['valid'] ? 'holds' : 'does not hold',
                $verdict['confidence'],
                \count($steps),
                1 === \count($steps) ? '' : 's'
            ),
            $verdict + array(
                'provider' => $answer['provider'],
                'model'    => $answer['model'],
                'usage'    => $answer['usage'],
            )
        );
    }

    /**
     * @param list<string> $steps
     * @param list<string> $premises
     */
    private function prompt(array $steps, array $premises, string $conclusion): string
    {
        $lines = array(
            'You are checking an argument for validity, not for truth.',
            'The question is only: do the steps follow from the premises, and does the conclusion follow from the steps?',
            'A step that is true but does not follow is still a broken step.',
            '',
            'Reply with a JSON object only, no prose and no fence:',
            '{',
            '  "valid": bool,',
            '  "confidence": 0.0-1.0,',
            '  "broken_step": 1-based index of the first step that does not follow, or null,',
            '  "issues": [string],',
            '  "unsupported_claims": [string],',
            '  "suggestions": [string]',
            '}',
        );

        if (array() !== $premises) {
            $lines[] = '';
            $lines[] = 'Premises:';

            foreach ($premises as $premise) {
                $lines[] = '- ' . $premise;
            }
        }

        $lines[] = '';
        $lines[] = 'Steps:';

        foreach ($steps as $index => $step) {
            $lines[] = \sprintf('%d. %s', $index + 1, $step);
        }

        if ('' !== $conclusion) {
            $lines[] = '';
            $lines[] = 'Conclusion to test: ' . $conclusion;
        }

        return \implode("\n", $lines);
    }
}
