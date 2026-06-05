<?php
declare(strict_types=1);

namespace NvoosGraphifyAi\Rest;

/**
 * REST API chat controller for the AI addon.
 *
 * Registers chat endpoints under the core's REST namespace
 * (`nvoos-graphify/v1`) so they can be discovered alongside
 * the graph endpoints.
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
	 * Handle a chat request.
	 *
	 * @param \WP_REST_Request $request The request.
	 * @return \WP_REST_Response|\WP_Error
	 */
	public function handleChat( \WP_REST_Request $request ) {
		$messages = $request->get_param( 'messages' );
		$provider = $request->get_param( 'provider' ) ?: null;
		$stream   = (bool) $request->get_param( 'stream' );

		if ( ! class_exists( 'NvoosGraphifyAi\Chat\ChatService' ) ) {
			return new \WP_Error(
				'nvoos_graphify_ai_unavailable',
				__( 'AI chat service is not available.', 'nvoos-graphify-ai' ),
				array( 'status' => 503 )
			);
		}

		if ( $stream ) {
			// SSE streaming — set headers and flush.
			header( 'Content-Type: text/event-stream' );
			header( 'Cache-Control: no-cache' );
			header( 'Connection: keep-alive' );
			header( 'X-Accel-Buffering: no' );

			// phpcs:ignore WordPress.PHP.DevelopmentFunctions,WordPress.PHP.IniSet.Risky,Generic.PHP.NoSilencedErrors.Discouraged — required for SSE streaming.
			@ini_set( 'output_buffering', 'off' );
			// phpcs:ignore WordPress.PHP.DevelopmentFunctions,WordPress.PHP.IniSet.Risky,Generic.PHP.NoSilencedErrors.Discouraged — required for SSE streaming.
			@ini_set( 'zlib.output_compression', 'off' );

			if ( function_exists( 'wp_ob_end_flush_all' ) ) {
				wp_ob_end_flush_all();
			}

			$streamCb = static function ( string $chunk ) {
				if ( '' !== $chunk ) {
					echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
					flush();
				}
			};

			$result = \NvoosGraphifyAi\Chat\ChatService::process(
				$messages,
				$provider,
				'static::sseOutput'
			);

			if ( is_wp_error( $result ) ) {
				echo 'data: ' . wp_json_encode( array( 'error' => $result->get_error_message() ) ) . "\n\n";
			}

			echo "data: [DONE]\n\n";
			exit;
		}

		// Non-streaming.
		$result = \NvoosGraphifyAi\Chat\ChatService::process( $messages, $provider );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return new \WP_REST_Response(
			array(
				'success' => true,
				'data'    => $result,
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
		$registry = \NvoosGraphifyAi\Plugin::instance()->getProviderRegistry();
		$slugs    = $registry->slugs();

		return new \WP_REST_Response(
			array(
				'success'   => true,
				'providers' => $slugs,
			),
			200
		);
	}

	/**
	 * SSE output callback for chunk streaming.
	 *
	 * @param string $chunk The token chunk.
	 * @param bool   $done  Whether the stream is finished.
	 * @return void
	 */
	public static function sseOutput( string $chunk, bool $done = false ): void {
		if ( $done ) {
			echo "data: [DONE]\n\n";
			flush();
			return;
		}
		echo 'data: ' . wp_json_encode( array( 'content' => $chunk ) ) . "\n\n";
		flush();
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
