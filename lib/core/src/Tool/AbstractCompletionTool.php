<?php

/**
 * Shared plumbing for tools that ask a model a question.
 *
 * A completion tool has one job that is its own — deciding what to ask — and four
 * jobs that are identical everywhere: resolve the provider, bound the answer,
 * call it, and turn three failure shapes into something a model can relay. The
 * second half is here so a text tool and a vision tool cannot drift on it.
 *
 * ## The three failures, and why they are separate
 *
 * - **No router** — the host cannot call a model at all. Not retryable.
 * - **A provider refusal** — the API answered 4xx/5xx. The provider's own text is
 *   the most useful thing a caller can be told ("this model does not accept
 *   images"), so it is surfaced, truncated at 500 characters so a verbose
 *   upstream body cannot become the transcript.
 * - **An empty answer** — the call succeeded and produced nothing. Reporting that
 *   as success with a blank string is how a caller concludes the input was empty.
 *
 * ## What a subclass controls
 *
 * The prompt, the schema, the capability and flags, and the four error codes,
 * which are overridable because `analyze_image` shipped with `vision_*` codes and
 * renaming a live refusal is a breaking change for no gain.
 *
 * @package Nvoos\Core
 * @since   2.1.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool;

use Nvoos\Core\Application\Provider\CompletionRequest;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;

abstract class AbstractCompletionTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The ceiling on a single answer, whatever the caller asks for.
     *
     * A tool call is not a generation job: an unbounded `max_tokens` is a way to
     * spend a provider budget by accident.
     */
    private const MAX_COMPLETION_TOKENS = 8192;

    private const DEFAULT_COMPLETION_TOKENS = 1024;

    /**
     * How much of a provider's error text reaches the caller.
     */
    private const MAX_PROVIDER_MESSAGE = 500;

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly ?ProviderRouter $providers = null,
    ) {
        parent::__construct($errors);
    }

    /**
     * Ask the resolved provider, and return its answer or the error to return.
     *
     * @param array<string, mixed> $arguments  The tool arguments, for `provider`,
     *                                         `model` and `max_tokens`.
     * @param string               $prompt     What to ask.
     * @param string|null          $dataUri    An image, when the model is being
     *                                         asked about one.
     * @param string               $system     An optional system prompt.
     * @param bool                 $allowEmpty A tool whose empty answer is a
     *                                         finding rather than a failure — OCR
     *                                         of an image with no text. Every
     *                                         other tool treats empty as an error,
     *                                         because a blank success reads as
     *                                         "nothing to report".
     *
     * @return array{text: string, provider: string, model: string, usage: array<string, mixed>}|mixed
     *                                                                                              The answer, or the error value to return unchanged.
     */
    protected function completeTool(
        array $arguments,
        string $prompt,
        ?string $dataUri = null,
        string $system = '',
        bool $allowEmpty = false,
    ): mixed {
        if (null === $this->providers) {
            return $this->errors->create(
                $this->unavailableCode(),
                'This host has no provider router, so it cannot call a model.'
            );
        }

        $client = $this->client($arguments);

        if ($this->errors->isError($client)) {
            return $client;
        }

        $options = array( 'max_tokens' => $this->tokenBudget($arguments) );

        $model = \mb_substr($this->stringParam($arguments, 'model'), 0, 120);

        if ('' !== $model) {
            $options['model'] = $model;
        }

        $response = $client->chat(CompletionRequest::messages($prompt, $dataUri, $system), $options);

        if ($this->errors->isError($response)) {
            $normalised = $this->errors->normalize($response);
            $message    = is_array($normalised) ? (string) ($normalised['message'] ?? '') : '';

            if ('' === \trim($message)) {
                $message = 'The provider refused the request.';
            }

            return $this->errors->create(
                $this->providerErrorCode(),
                \mb_substr($message, 0, self::MAX_PROVIDER_MESSAGE)
            );
        }

        $text = is_array($response) ? ($response['choices'][0]['message']['content'] ?? null) : null;

        if (! is_string($text)) {
            $text = '';
        }

        if (! $allowEmpty && '' === \trim($text)) {
            return $this->errors->create(
                $this->emptyResponseCode(),
                'The provider returned no text.'
            );
        }

        return array(
            'text'     => $text,
            'provider' => $client->getProviderSlug(),
            'model'    => is_array($response) ? (string) ($response['model'] ?? ($options['model'] ?? '')) : '',
            'usage'    => is_array($response) ? (array) ($response['usage'] ?? array()) : array(),
        );
    }

    /**
     * The bounded answer size for this call.
     *
     * @param array<string, mixed> $arguments
     */
    protected function tokenBudget(array $arguments): int
    {
        $requested = $this->intParam($arguments, 'max_tokens', self::DEFAULT_COMPLETION_TOKENS);

        return \max(1, \min(self::MAX_COMPLETION_TOKENS, $requested));
    }

    /**
     * A document the caller supplied, trimmed but **not** tag-stripped.
     *
     * `stringParam()` runs `strip_tags()`, which is right for prose and fatal for
     * code: `strip_tags('<?php echo 1;')` returns an empty string, and
     * `if ($a < $b)` loses everything from the `<` onward. A tool that reviews
     * code, or a reasoning step that mentions `<foo>`, must receive the bytes the
     * caller wrote — the same reason the import bundle bypasses it.
     *
     * @param array<string, mixed> $arguments
     */
    protected function documentParam(array $arguments, string $key, int $maxLength = 0): string
    {
        $value = $arguments[ $key ] ?? '';

        if (! is_string($value)) {
            return '';
        }

        $value = \trim($value);

        return $maxLength > 0 ? \mb_substr($value, 0, $maxLength) : $value;
    }

    /**
     * A list of raw strings, trimmed and bounded.
     *
     * @param array<string, mixed> $arguments
     *
     * @return list<string>
     */
    protected function documentListParam(array $arguments, string $key, int $limit = 20, int $maxItem = 2000): array
    {
        $items = array();

        foreach (\is_array($arguments[ $key ] ?? null) ? $arguments[ $key ] : array() as $item) {
            if (! \is_string($item)) {
                continue;
            }

            $item = \trim($item);

            if ('' === $item) {
                continue;
            }

            $items[] = \mb_substr($item, 0, $maxItem);

            if (\count($items) >= $limit) {
                break;
            }
        }

        return $items;
    }

    /**
     * The answer-size parameter every subclass exposes.
     *
     * @return array<string, mixed>
     */
    protected function maxTokensParameter(string $description, int $default = self::DEFAULT_COMPLETION_TOKENS): array
    {
        return array(
            'type'        => 'integer',
            'description' => $description,
            'minimum'     => 1,
            'maximum'     => self::MAX_COMPLETION_TOKENS,
            'default'     => $default,
        );
    }

    /**
     * Coerce an enum-like argument to one of a closed set, falling back.
     *
     * Use this where the value is cosmetic — a tone, a length — and the applied
     * value is reported back, so a typo is visible without being fatal.
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $allowed
     */
    protected function choice(array $arguments, string $key, array $allowed, string $fallback): string
    {
        $candidate = \strtolower($this->stringParam($arguments, $key));

        return \in_array($candidate, $allowed, true) ? $candidate : $fallback;
    }

    /**
     * Coerce an enum-like argument, or null when it is present and not one of the set.
     *
     * Use this where the value changes the answer rather than its style — a
     * moderation sensitivity, for instance. Silently falling back there would
     * mean a caller who asked for strict moderation got balanced and was never
     * told.
     *
     * **An absent value is not an invalid one.** With a `$default`, omitting the
     * argument yields the default; only a value the caller actually supplied and
     * that is not in the set comes back null. Treating absence as invalid would
     * refuse every call that relied on the documented default — which is exactly
     * what the first version of this did.
     *
     * @param array<string, mixed> $arguments
     * @param list<string>         $allowed
     */
    protected function strictChoice(array $arguments, string $key, array $allowed, string $default = ''): ?string
    {
        $candidate = \strtolower($this->stringParam($arguments, $key));

        if ('' === $candidate) {
            return '' === $default ? null : $default;
        }

        return \in_array($candidate, $allowed, true) ? $candidate : null;
    }

    /**
     * Remove the framing a model adds, keeping the text's own structure.
     *
     * Quotes are stripped before and after the fence, because models produce both
     * orders — a fenced block inside quotes is the one that survives a single pass.
     */
    protected function tidyBlock(string $text): string
    {
        $text = \trim($text);
        $text = \trim((string) \preg_replace('/^"(.*)"$/s', '$1', $text));
        $text = \trim((string) \preg_replace('/^```[a-z]*\s*|\s*```$/i', '', $text));
        $text = \trim((string) \preg_replace('/^"(.*)"$/s', '$1', $text));

        return $text;
    }

    /**
     * Remove the framing **and** collapse to one line.
     *
     * An alt text, a caption and an excerpt are all rendered where a newline would
     * show up as a stray gap, so they get this. A transcription asked to preserve
     * layout gets {@see tidyBlock()} instead — collapsing its newlines would throw
     * away the thing the caller asked for.
     */
    protected function tidyText(string $text): string
    {
        return \trim((string) \preg_replace('/\s+/', ' ', $this->tidyBlock($text)));
    }

    /**
     * The provider and model parameters every subclass exposes.
     *
     * @return array<string, array<string, mixed>>
     */
    protected function providerParameters(): array
    {
        return array(
            'provider' => array(
                'type'        => 'string',
                'description' => 'Provider slug (openai, gemini, anthropic, …). Defaults to the site default.',
                'maxLength'   => 40,
            ),
            'model'    => array(
                'type'        => 'string',
                'description' => 'Model id. Defaults to the provider\'s default.',
                'maxLength'   => 120,
            ),
        );
    }

    /**
     * The client that will answer.
     *
     * An unregistered slug is distinguished from "no default configured" and the
     * registered list is named, because a refusal without the alternatives is a
     * dead end for a model.
     *
     * @param array<string, mixed> $arguments
     */
    private function client(array $arguments): mixed
    {
        $requested = \mb_substr($this->stringParam($arguments, 'provider'), 0, 40);

        $client = $this->providers->resolveForChat('' === $requested ? array() : array( 'provider' => $requested ));

        if (null !== $client) {
            return $client;
        }

        $registered = $this->providers->getRegisteredSlugs();

        if ('' === $requested) {
            return $this->errors->create(
                $this->noDefaultProviderCode(),
                array() === $registered
                    ? 'No AI provider is registered, so this tool cannot run.'
                    : 'No default AI provider is configured. Registered providers: ' . \implode(', ', $registered) . '.'
            );
        }

        return $this->errors->validationFailed(
            \sprintf(
                'No provider answers to "%s". Registered providers: %s.',
                $requested,
                array() === $registered ? '(none)' : \implode(', ', $registered)
            ),
            array( 'provider' => 'Unknown or unregistered provider.' )
        );
    }

    /**
     * The code returned when the host has no provider router.
     */
    protected function unavailableCode(): string
    {
        return 'completion_unavailable';
    }

    /**
     * The code returned when a router exists but no default provider is set.
     *
     * Distinct from {@see unavailableCode()}: one means the host cannot call a
     * model at all, the other means it can but has not been told which one. A
     * caller fixes those differently, so collapsing them would throw away the
     * only information the refusal carries.
     */
    protected function noDefaultProviderCode(): string
    {
        return 'completion_provider_unavailable';
    }

    /**
     * The code returned when the provider refuses the request.
     */
    protected function providerErrorCode(): string
    {
        return 'completion_provider_error';
    }

    /**
     * The code returned when the provider answers with nothing.
     */
    protected function emptyResponseCode(): string
    {
        return 'completion_empty_response';
    }
}
