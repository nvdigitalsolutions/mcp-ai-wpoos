<?php
/**
 * Fired before a chat request is sent to the LLM.
 *
 * Replaces: do_action('wp_mcp_ai_before_chat_request', ...)
 *
 * @package Nvoos\Core
 * @since   1.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Event;

final class BeforeChatRequest {

	public function __construct(
		public readonly int $assistantId,
		public array $messages,
		public array $options,
		public readonly array $authContext,
	) {}
}
