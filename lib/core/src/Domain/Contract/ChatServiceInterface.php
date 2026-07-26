<?php
/**
 * Chat Service Interface — the contract for processing AI chat requests.
 *
 * Abstracts the chat orchestration layer: message processing,
 * agentic tool loops, rate limiting checks, and token budget validation.
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ChatServiceInterface {

	/**
	 * Process a chat request through the full agentic loop.
	 *
	 * Handles validation, rate limiting, token budget checking,
	 * message processing, tool execution loops, and transcript recording.
	 *
	 * @param int   $assistant_id       Assistant identifier.
	 * @param array $messages           Array of chat messages.
	 * @param array $options            Chat options (model, temperature, etc.).
	 * @param array $assistant_config   Assistant configuration.
	 * @param array $transcript_context Transcript recording context.
	 * @param int   $user_id            Authenticated user ID.
	 * @param int   $max_iterations     Maximum agentic loop iterations (default 5).
	 * @param mixed $request            Platform-specific request object (nullable).
	 *
	 * @return array|mixed  Success array or platform error.
	 */
	public function processChatRequest(
		int $assistant_id,
		array $messages,
		array $options,
		array $assistant_config,
		array $transcript_context,
		int $user_id,
		int $max_iterations = 5,
		mixed $request = null
	): mixed;

	/**
	 * Check whether the request exceeds rate limits.
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param int   $user_id      User ID.
	 * @param array $options      Request options.
	 *
	 * @return bool  True if rate limits are OK, false if exceeded.
	 */
	public function checkRateLimits(
		int $assistant_id,
		int $user_id,
		array $options
	): bool;

	/**
	 * Check whether the request fits within the token budget.
	 *
	 * @param int   $assistant_id Assistant ID.
	 * @param int   $user_id      User ID.
	 * @param array $messages     Chat messages.
	 * @param array $options      Request options.
	 *
	 * @return bool  True if within budget, false if exceeded.
	 */
	public function checkTokenBudget(
		int $assistant_id,
		int $user_id,
		array $messages,
		array $options
	): bool;
}
