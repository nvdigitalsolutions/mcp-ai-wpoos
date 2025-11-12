<?php
/**
 * Chat Service Interface
 *
 * Defines contract for chat processing services.
 * Part of implementing Interface Segregation Principle (Priority 3 from Architecture Review).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Service Interface
 *
 * Defines the contract for chat message processing.
 *
 * Benefits:
 * - Enables dependency injection of interface instead of concrete class
 * - Simplifies mocking in unit tests
 * - Allows multiple implementations (e.g., different processing strategies)
 * - Makes contract explicit and self-documenting
 * - Supports Dependency Inversion Principle
 *
 * @since 1.0.0
 */
interface WP_MCP_AI_Chat_Service_Interface {

	/**
	 * Process a chat request.
	 *
	 * Main entry point for chat processing. Handles validation, rate limiting,
	 * token budget checking, message processing, agentic tool execution loops,
	 * and transcript recording.
	 *
	 * @param int             $assistant_id       Assistant post ID.
	 * @param array           $messages           Chat messages.
	 * @param array           $options            Chat options.
	 * @param array           $assistant_config   Assistant configuration.
	 * @param array           $transcript_context Transcript recording context.
	 * @param int             $user_id            Current user ID.
	 * @param int             $max_iterations     Maximum agentic loop iterations.
	 * @param WP_REST_Request $request            REST request instance.
	 * @return array|WP_Error Chat response or error.
	 */
	public function process_chat_request(
		$assistant_id,
		$messages,
		$options,
		$assistant_config,
		$transcript_context,
		$user_id,
		$max_iterations,
		$request
	);

	/**
	 * Execute agentic tool loop.
	 *
	 * Handles iterative tool calling when the language model requests tool execution.
	 * Continues until the model provides a final response or max iterations reached.
	 *
	 * @param array $messages          Current conversation messages.
	 * @param array $options           Chat options including tools.
	 * @param int   $max_iterations    Maximum loop iterations.
	 * @param int   $assistant_id      Assistant post ID.
	 * @param array $assistant_config  Assistant configuration.
	 * @return array|WP_Error Final response or error.
	 */
	public function execute_agentic_loop(
		$messages,
		$options,
		$max_iterations,
		$assistant_id,
		$assistant_config
	);

	/**
	 * Record chat transcript if enabled.
	 *
	 * Saves conversation history for future reference or training.
	 *
	 * @param array $messages           Chat messages.
	 * @param array $response           AI response.
	 * @param int   $assistant_id       Assistant post ID.
	 * @param array $transcript_context Transcript context (session key, save flag, etc.).
	 * @return bool|WP_Error True on success, error on failure.
	 */
	public function record_transcript(
		$messages,
		$response,
		$assistant_id,
		$transcript_context
	);
}
