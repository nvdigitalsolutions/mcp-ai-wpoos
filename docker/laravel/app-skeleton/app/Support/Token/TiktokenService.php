<?php

namespace App\Support\Token;

use Nvoos\Core\Domain\Contract\TiktokenServiceInterface;
use RuntimeException;

/**
 * Token counting for the Laravel runtime — Proposal 149 §71.
 *
 * The runtime's only estimator was `chars / 4`. It is a reasonable average for
 * English prose and a poor one for the payloads the pre-flight check actually
 * measures: JSON with punctuation, tool schemas, code. Two errors follow —
 * tool-heavy requests can slip past an under-count, and a short structured
 * request can be rejected by an over-count.
 *
 * This adapter prefers BPE. `rahul900day/tiktoken-php` is a **suggested**
 * dependency, not a required one:
 *
 *  - installed → `countTokens()` is a real tiktoken count, and `isAvailable()`
 *    says so;
 *  - absent → `countTokens()` keeps the chars/4 heuristic, and `isAvailable()`
 *    says so. Diagnostics shows which mode is live, so "why did pre-flight
 *    reject that?" has an answer.
 *
 * It is deliberately not a hard dependency: the library fetches its encoding
 * files over HTTP on first use (Guzzle satisfies PSR-18 here), and a
 * deployment that cannot make that call — or does not want a first-request
 * download — must still run. `composer require rahul900day/tiktoken-php`
 * turns it on; nothing else changes.
 *
 * `countTokensAccurate()` exists for callers that require precision and
 * therefore must not silently receive an estimate.
 */
final class TiktokenService implements TiktokenServiceInterface
{
    /**
     * The optional library's entry point.
     */
    private const TIKTOKEN_CLASS = 'Rahul900day\\Tiktoken\\Tiktoken';

    /**
     * Characters per token for the fallback heuristic.
     */
    private const CHARS_PER_TOKEN = 4;

    /**
     * Model family => tiktoken encoding, longest prefix wins.
     *
     * @var array<string, string>
     */
    private const ENCODING_MAP = [
        'gpt-4o' => 'o200k_base',
        'gpt-4.1' => 'o200k_base',
        'gpt-4.5' => 'o200k_base',
        'gpt-5' => 'o200k_base',
        'o1' => 'o200k_base',
        'o3' => 'o200k_base',
        'o4' => 'o200k_base',
        'gpt-4' => 'cl100k_base',
        'gpt-3.5' => 'cl100k_base',
        'gpt-3' => 'p50k_base',
        'davinci' => 'p50k_base',
        'text-' => 'p50k_base',
    ];

    /**
     * Default encoding when no model is supplied or matches.
     */
    private const DEFAULT_ENCODING = 'cl100k_base';

    public function countTokens(string $text, ?string $model = null): int
    {
        if ('' === $text) {
            return 0;
        }

        if ($this->isAvailable()) {
            try {
                return $this->countTokensAccurate($text, $model);
            } catch (\Throwable $exception) {
                // A failed encoder (offline, cache miss, corrupt vocab) must not
                // fail a chat; fall through to the heuristic and report.
                report($exception);
            }
        }

        return $this->heuristicCount($text);
    }

    public function countTokensAccurate(string $text, ?string $model = null): int
    {
        if (! $this->isAvailable()) {
            throw new RuntimeException(
                'Tiktoken library is not available. Install rahul900day/tiktoken-php.'
            );
        }

        $class = self::TIKTOKEN_CLASS;
        $encoder = $class::getEncoding($this->resolveEncoding($model));

        return count($encoder->encode($text));
    }

    public function isAvailable(): bool
    {
        return class_exists(self::TIKTOKEN_CLASS);
    }

    public function resolveEncoding(?string $model = null): string
    {
        $model = strtolower(trim((string) $model));

        if ('' === $model) {
            return self::DEFAULT_ENCODING;
        }

        foreach (self::ENCODING_MAP as $prefix => $encoding) {
            if (str_starts_with($model, $prefix)) {
                return $encoding;
            }
        }

        return self::DEFAULT_ENCODING;
    }

    /**
     * The chars/4 heuristic, unchanged from the budget manager's own fallback.
     */
    private function heuristicCount(string $text): int
    {
        return (int) ceil(mb_strlen($text, 'UTF-8') / self::CHARS_PER_TOKEN);
    }
}
