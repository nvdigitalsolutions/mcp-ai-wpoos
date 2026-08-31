<?php
/**
 * ACP Session Bridge.
 *
 * Translates ACP ContentBlocks <-> chat messages.
 * Maps ACP `session/request_permission` to the approval controller.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/acp/class-wp-mcp-ai-acp-session-bridge.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridge between ACP specification and the internal chat implementation.
 */
class SessionBridge {

	/**
	 * Chat Service Instance.
	 *
	 * @var object|null
	 */
	protected $chat_service;

	/**
	 * Set the chat service reference.
	 *
	 * @param object $chat_service The chat service.
	 */
	public function set_chat_service( $chat_service ) {
		$this->chat_service = $chat_service;
	}

	/**
	 * Process a prompt turn from ACP.
	 *
	 * @param array $params Prompt parameters containing session ID and content.
	 * @return array|\WP_Error Stop reason or error.
	 */
	public function handle_prompt( $params ) {
		// 1. Map ACP ContentBlocks to standard message format.
		$mapped_messages = $this->map_acp_content_to_messages( $params['prompt'] );

		// 2. Add to session history.
		$session_manager = new SessionManager();
		$session_data    = $session_manager->get_session_data( $params['sessionId'] );

		if ( ! $session_data ) {
			return new \WP_Error( -32001, 'Session not found' );
		}

		$session_data['messages'] = array_merge( $session_data['messages'], $mapped_messages );
		$session_manager->update_session_data( $params['sessionId'], $session_data );

		// 3. Prepare parameters for the chat service.
		// For an ACP session, if an assistant ID isn't provided in the config, default to 0 (default assistant context).
		$assistant_id       = isset( $session_data['config']['assistant_id'] ) ? intval( $session_data['config']['assistant_id'] ) : 0;
		$user_id            = $session_data['user_id'];
		$options            = array( 'stream' => false ); // We'll manage streaming via the ACP queue.
		$assistant_config   = array();
		$transcript_context = array(
			'source'     => 'acp',
			'session_id' => $params['sessionId'],
		);
		$max_iterations     = 5;

		if ( $this->chat_service ) {
			// Hook into the chat service to intercept streaming chunks or tool calls if needed.
			// For simplicity in Phase 1, we await the full response and emit it,
			// though true ACP streaming would involve a custom observer.

			// Empty WP_REST_Request as it's not strictly an HTTP boundary here.
			$request = new \WP_REST_Request( 'POST', '/mcp-ai/v1/acp' );

			$result = $this->chat_service->process_chat_request(
				$assistant_id,
				$session_data['messages'],
				$options,
				$assistant_config,
				$transcript_context,
				$user_id,
				$max_iterations,
				$request
			);

			if ( is_wp_error( $result ) ) {
				$this->emit_update(
					$params['sessionId'],
					array(
						'sessionUpdate' => 'agent_message_chunk',
						'content'       => array(
							'type' => 'text',
							'text' => 'Error: ' . $result->get_error_message(),
						),
					)
				);

				return array(
					'stopReason' => 'refusal',
				);
			}

			// We have a successful response from the chat service.
			$this->emit_update(
				$params['sessionId'],
				array(
					'sessionUpdate' => 'agent_message_chunk',
					'content'       => array(
						'type' => 'text',
						'text' => isset( $result['response'] ) ? $result['response'] : '',
					),
				)
			);

			// Save the assistant's response to the session history.
			$session_data['messages'][] = array(
				'role'    => 'assistant',
				'content' => isset( $result['response'] ) ? $result['response'] : '',
			);
			$session_manager->update_session_data( $params['sessionId'], $session_data );

		} else {
			// Stub response if chat service isn't injected yet.
			$this->emit_update(
				$params['sessionId'],
				array(
					'sessionUpdate' => 'agent_message_chunk',
					'content'       => array(
						'type' => 'text',
						'text' => 'Thinking... (Chat service unavailable)',
					),
				)
			);
		}

		// 4. Return stopReason (end_turn, max_tokens, refusal, etc.).
		return array(
			'stopReason' => 'end_turn',
		);
	}

	/**
	 * Emit an SSE update for a specific session.
	 *
	 * @param string $session_id ACP Session ID.
	 * @param array  $update     The sessionUpdate payload.
	 */
	protected function emit_update( $session_id, $update ) {
		$updates = get_transient( 'acp_updates_' . $session_id );
		if ( ! is_array( $updates ) ) {
			$updates = array();
		}

		$updates[] = array(
			'jsonrpc' => '2.0',
			'method'  => 'session/update',
			'params'  => array(
				'sessionId' => $session_id,
				'update'    => $update,
			),
		);

		set_transient( 'acp_updates_' . $session_id, $updates, 60 );
	}

	/**
	 * Map ACP ContentBlocks to message format.
	 *
	 * @param array $prompt ACP Prompt block array.
	 * @return array Compatible message structures.
	 */
	protected function map_acp_content_to_messages( $prompt ) {
		$messages     = array();
		$text_content = '';

		foreach ( $prompt as $block ) {
			if ( 'text' === $block['type'] && ! empty( $block['text'] ) ) {
				$text_content .= $block['text'] . "\n";
			} elseif ( 'resource' === $block['type'] && ! empty( $block['resource'] ) ) {
				// Map embedded resources natively as contextual content injections.
				$uri           = isset( $block['resource']['uri'] ) ? $block['resource']['uri'] : 'unknown_resource';
				$text_content .= "\n--- Attached Context: " . $uri . " ---\n";

				if ( isset( $block['resource']['text'] ) ) {
					$text_content .= $block['resource']['text'] . "\n";
				}
			} elseif ( 'image' === $block['type'] ) {
				// We drop native image handling in base version for now, fallback string attached.
				$text_content .= "\n[User provided an image. Handling is deferred.]\n";
			}
		}

		if ( ! empty( trim( $text_content ) ) ) {
			$messages[] = array(
				'role'    => 'user',
				'content' => trim( $text_content ),
			);
		}

		return $messages;
	}
}
