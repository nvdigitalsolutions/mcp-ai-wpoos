<?php
/**
 * Fired after a chat response is received from the LLM.
 *
 * Replaces: do_action('wp_mcp_ai_after_chat_response', ...)
 *
 * @package Oos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Oos\Core\Domain\Event;

final class AfterChatResponse {

	public function __construct(
		public readonly int $assistantId,
		public readonly array $response,
		public readonly array $requestContext,
		public readonly float $durationMs,
	) {}
}
