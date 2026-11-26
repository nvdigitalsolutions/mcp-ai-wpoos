<?php

/**
 * Tool: analyze_code_sequence — review code and say what is wrong with it.
 *
 * `AnalyzeCodeSequenceCompletionTool` because `AnalyzeCodeSequenceTool` is the
 * quarantined prompt-builder answering to `analyze_code_sequence_prompt`.
 *
 * The code is read with `documentParam()`, not `stringParam()`. That is not a
 * detail: `stringParam()` runs `strip_tags()`, and `strip_tags('<?php echo 1;')`
 * returns an empty string while `if ($a < $b)` loses everything from the `<`
 * onward. A review tool that mangles the code it is reviewing is worse than no
 * tool, because the model then reports confidently on something the caller never
 * wrote.
 *
 * The review comes back as text. Imposing a JSON schema on a code review buys
 * nothing here — the caller wants the observations, not a parse — so this port
 * does not pretend otherwise.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

final class AnalyzeCodeSequenceCompletionTool extends AbstractCompletionTool
{
    private const MAX_CODE = 100000;

    public function getSlug(): string
    {
        return 'analyze_code_sequence';
    }

    public function getName(): string
    {
        return 'Analyze Code Sequence';
    }

    public function getDescription(): string
    {
        return 'Reviews a block of code against stated requirements and reports correctness problems, risks and concrete improvements. The code is passed through untouched, including tags and angle brackets. Returns the review as text.';
    }

    public function getParametersSchema(): array
    {
        return array(
            'type'                 => 'object',
            'properties'           => array(
                'code'         => array(
                    'type'        => 'string',
                    'description' => 'The code to review, verbatim.',
                    'maxLength'   => self::MAX_CODE,
                ),
                'language'     => array(
                    'type'        => 'string',
                    'description' => 'Language or runtime, e.g. php, javascript, sql.',
                    'maxLength'   => 40,
                ),
                'requirements' => array(
                    'type'        => 'array',
                    'description' => 'What the code is supposed to do. The review is judged against these.',
                    'items'       => array(
                        'type'      => 'string',
                        'maxLength' => 2000,
                    ),
                    'maxItems'    => 20,
                ),
            ) + $this->providerParameters() + array(
                'max_tokens' => $this->maxTokensParameter('Cap on the answer length.', 2048),
            ),
            'required'             => array( 'code' ),
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
        $code = $this->documentParam($arguments, 'code', self::MAX_CODE);

        if ('' === $code) {
            return $this->errors->validationFailed(
                'Some code is required.',
                array( 'code' => 'Required.' )
            );
        }

        $language     = \mb_substr($this->stringParam($arguments, 'language'), 0, 40);
        $requirements = $this->documentListParam($arguments, 'requirements');

        $answer = $this->completeTool($arguments, $this->prompt($code, $language, $requirements));

        if ($this->errors->isError($answer)) {
            return $answer;
        }

        return $this->success(
            \sprintf('Reviewed %d characters of code with %s.', \strlen($code), $answer['provider']),
            array(
                'analysis'     => $answer['text'],
                'language'     => '' === $language ? 'unspecified' : $language,
                'requirements' => $requirements,
                'provider'     => $answer['provider'],
                'model'        => $answer['model'],
                'usage'        => $answer['usage'],
            )
        );
    }

    /**
     * @param list<string> $requirements
     */
    private function prompt(string $code, string $language, array $requirements): string
    {
        $lines = array(
            'Review the code below as a senior engineer.',
            '',
            'Report, in this order and only where there is something to say:',
            '1. Correctness problems, with the offending snippet quoted.',
            '2. Security and data-handling risks.',
            '3. Concrete improvements, each one sentence and actionable.',
            '',
            'Do not restate the code. Do not praise. If the code is sound, say so in one line.',
        );

        if ('' !== $language) {
            $lines[] = '';
            $lines[] = 'Language: ' . $language;
        }

        if (array() !== $requirements) {
            $lines[] = '';
            $lines[] = 'It is supposed to:';

            foreach ($requirements as $requirement) {
                $lines[] = '- ' . $requirement;
            }
        }

        $lines[] = '';
        $lines[] = 'Code:';
        $lines[] = $code;

        return \implode("\n", $lines);
    }
}
