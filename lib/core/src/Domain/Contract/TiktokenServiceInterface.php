<?php
/**
 * Tiktoken Service Interface — the contract for BPE token counting.
 *
 * Abstracts accurate byte-pair encoding tokenization (OpenAI tiktoken)
 * with fallback to the chars/4 heuristic when the tiktoken library
 * is not available.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface TiktokenServiceInterface {

	/**
	 * Count tokens in text using the best available method.
	 *
	 * Uses tiktoken BPE encoding when the library is available;
	 * falls back to chars/4 heuristic otherwise.
	 *
	 * @param string      $text  Text to tokenize.
	 * @param string|null $model Model slug for encoding selection (e.g. 'gpt-4o').
	 *
	 * @return int  Estimated token count (never negative).
	 */
	public function countTokens( string $text, ?string $model = null ): int;

	/**
	 * Count tokens using only the tiktoken library.
	 *
	 * Returns the accurate BPE token count, or throws if the library
	 * is unavailable. Use this when precision is required.
	 *
	 * @param string      $text  Text to tokenize.
	 * @param string|null $model Model slug for encoding selection.
	 *
	 * @return int  Accurate token count.
	 *
	 * @throws \RuntimeException  If tiktoken library is not available.
	 */
	public function countTokensAccurate( string $text, ?string $model = null ): int;

	/**
	 * Whether the tiktoken library is available for accurate counting.
	 *
	 * @return bool
	 */
	public function isAvailable(): bool;

	/**
	 * Resolve the appropriate tiktoken encoding name for a model.
	 *
	 * Maps model families to OpenAI encoding schemes:
	 *   - GPT-4o → o200k_base
	 *   - GPT-4, GPT-3.5 → cl100k_base
	 *   - Davinci, text-* → p50k_base
	 *
	 * @param string|null $model Model slug.
	 *
	 * @return string  Encoding name (e.g. 'cl100k_base').
	 */
	public function resolveEncoding( ?string $model = null ): string;
}
