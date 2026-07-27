<?php
/**
 * WordPress adapter: ChatServiceInterface implementation.
 *
 * Wraps the legacy WP_MCP_AI_Chat_Service and its dependencies
 * (rate limiter, token budget manager) behind the framework-agnostic
 * ChatServiceInterface.
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Adapter;

use Nvoos\Core\Domain\Contract\ChatServiceInterface;

class ChatService implements ChatServiceInterface {

	private ?\WP_MCP_AI_Chat_Service $chatService = null;
	private ?\WP_MCP_AI_Rate_Limit_Manager $rateLimiter = null;
	private ?\WP_MCP_AI_Token_Budget_Manager $tokenBudget = null;

	/**
	 * Lazy-load the legacy chat service.
	 */
	private function chatService(): \WP_MCP_AI_Chat_Service {
		if ( null === $this->chatService ) {
			$this->chatService = new \WP_MCP_AI_Chat_Service(
				\WP_MCP_AI_Language_Model_Router::get_instance(),
				new \WP_MCP_AI_Rate_Limit_Manager(),
				new \WP_MCP_AI_Token_Budget_Manager(),
				\WP_MCP_AI_Tool_Registry::get_instance()
			);
		}

		return $this->chatService;
	}

	/**
	 * Lazy-load the rate limit manager.
	 */
	private function rateLimiter(): \WP_MCP_AI_Rate_Limit_Manager {
		if ( null === $this->rateLimiter ) {
			$this->rateLimiter = new \WP_MCP_AI_Rate_Limit_Manager();
		}

		return $this->rateLimiter;
	}

	/**
	 * Lazy-load the token budget manager.
	 */
	private function tokenBudget(): \WP_MCP_AI_Token_Budget_Manager {
		if ( null === $this->tokenBudget ) {
			$this->tokenBudget = new \WP_MCP_AI_Token_Budget_Manager();
		}

		return $this->tokenBudget;
	}

	public function processChatRequest(
		int   $assistant_id,
		array $messages,
		array $options,
		array $assistant_config,
		array $transcript_context,
		int   $user_id,
		int   $max_iterations = 5,
		mixed $request = null
	): mixed {
		return $this->chatService()->process_chat_request(
			$assistant_id,
			$messages,
			$options,
			$assistant_config,
			$transcript_context,
			$user_id,
			$max_iterations,
			$request
		);
	}

	public function checkRateLimits(
		int   $assistant_id,
		int   $user_id,
		array $options
	): bool {
		$result = $this->rateLimiter()->check_rate_limit(
			$user_id,
			'chat',
			array(
				'assistant_id' => $assistant_id,
				'options'      => $options,
			)
		);

		return ! \is_wp_error( $result );
	}

	public function checkTokenBudget(
		int   $assistant_id,
		int   $user_id,
		array $messages,
		array $options
	): bool {
		$result = $this->tokenBudget()->check_budget(
			$user_id,
			$assistant_id,
			$messages,
			$options
		);

		return ! \is_wp_error( $result );
	}
}
