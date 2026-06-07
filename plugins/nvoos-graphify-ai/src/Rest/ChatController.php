<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Rest;

use NvoosGraphifyAi\CoreBridge;

/**
 * REST API chat controller for the AI addon.
 *
 * Registers chat endpoints under the core's REST namespace
 * (`nvoos-graphify/v1`) so they can be discovered alongside
 * the graph endpoints. Delegates all chat handling to
 * nvoos/core's ChatOrchestrator via CoreBridge.
 *
 * @since 1.0.0
 */
class ChatController {

	/**
	 * Register the chat routes.
	 *
	 * Hooked to `rest_api_init`.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// POST /nvoos-graphify/v1/ai/chat
		register_rest_route(
			'nvoos-graphify/v1',
			'/ai/chat',
			array(
				'methods'             => \WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'handleChat' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
				'args'                => array(
					'messages' => array(
						'required'          => true,
						'type'              => 'array',
						'sanitize_callback' => array( $this, 'sanitizeMessages' ),
					),
					'provider' => array(
						'required'          => false,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'stream'   => array(
						'required' => false,
						'type'     => 'boolean',
						'default'  => false,
					),
				),
			)
		);

		// GET /nvoos-graphify/v1/ai/providers
		register_rest_route(
			'nvoos-graphify/v1',
			'/ai/providers',
			array(
				'methods'             => \WP_REST_Server::READABLE,
				'callback'            => array( $this, 'listProviders' ),
				'permission_callback' => function (): bool {
					return current_user_can( 'edit_posts' );
				},
			)
		);
	}

	/**
	 * Handle a chat request via the core ChatOrchestrator.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handleChat( \WP_REST_Request $request ) {
		$bridge   = CoreBridge::instance();
		$messages = $request->get_param( 'messages' );
		$provider = $request->get_param( 'provider' ) ?: null;
		$stream   = (bool) $request->get_param( 'stream' );

		$options = array();
		if ( null !== $provider ) {
			$options['provider'] = $provider;
		}

		$userId      = \get_current_user_id();
		$assistantId = 0; // Not tied to a specific assistant post.

		if ( $stream ) {
			// Delegate all streaming to ChatOrchestrator which uses
			// SseHandler for header setup, event dispatch, and DONE signal.
			$bridge->chat->handleChatStreaming(
				$messages,
				array(),       // assistantConfig
				$userId,
				$assistantId,
				$options,
			);

			exit;
		}

		// Non-streaming chat.
		$result = $bridge->chat->handleChat(
			$messages,
			array(),       // assistantConfig
			$userId,
			$assistantId,
			$options,
		);

		$response = $result['response'] ?? array();

		if ( $bridge->errors->isError( $response ) ) {
			$normalized = $bridge->errors->normalize( $response );
			return new \WP_Error(
				$normalized['code'],
				$normalized['message'],
				array( 'status' => $normalized['data']['status'] ?? 500 )
			);
		}

		return new \WP_REST_Response(
			array(
				'success'      => true,
				'data'         => $response,
				'tool_results' => $result['tool_results'] ?? array(),
				'iterations'   => $result['iterations'] ?? 0,
				'cost'         => $result['cost'] ?? null,
			),
			200
		);
	}

	/**
	 * List available AI providers.
	 *
	 * @return \WP_REST_Response
	 */
	public function listProviders(): \WP_REST_Response {
		$slugs = CoreBridge::instance()->getProviderSlugs();

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'providers' => $slugs,
			),
			200
		);
	}

	/**
	 * Sanitize the messages array.
	 *
	 * @param array $messages Raw messages from the request.
	 * @return array Sanitized messages.
	 */
	public function sanitizeMessages( array $messages ): array {
		$sanitized = array();

		foreach ( $messages as $msg ) {
			if ( ! is_array( $msg ) ) {
				continue;
			}

			$sanitized[] = array(
				'role'    => sanitize_text_field( $msg['role'] ?? 'user' ),
				'content' => wp_kses_post( $msg['content'] ?? '' ),
			);
		}

		return $sanitized;
	}
}
