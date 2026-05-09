<?php
/**
 * NV oOS Chat SPA — REST API Controller
 *
 * @package NV_oOS_Chat_Spa
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST API controller for the NV oOS Chat SPA addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Chat_Spa_REST {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'nvoos-chat-spa/v1';

	/**
	 * Register routes.
	 *
	 * @return void
	 */
	public static function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			'/health',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'health' ),
				'permission_callback' => array( __CLASS__, 'admin_permission' ),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/manifest',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'manifest' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			'/config',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( __CLASS__, 'config' ),
				'permission_callback' => '__return_true',
			)
		);
	}

	/**
	 * Manage_options gate.
	 *
	 * @return bool|WP_Error
	 */
	public static function admin_permission() {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}
		return new WP_Error( 'forbidden', __( 'You do not have permission to access this endpoint.', 'nvoos-chat-spa' ), array( 'status' => 403 ) );
	}

	/**
	 * Health endpoint.
	 *
	 * @return WP_REST_Response
	 */
	public static function health() {
		return rest_ensure_response(
			array(
				'status'  => 'ok',
				'version' => defined( 'NVOOS_CHAT_SPA_VERSION' ) ? NVOOS_CHAT_SPA_VERSION : 'unknown',
			)
		);
	}

	/**
	 * Manifest endpoint — addon metadata, surface, and bundle info.
	 *
	 * @return WP_REST_Response
	 */
	public static function manifest() {
		$payload = array(
			'slug'    => 'chat-spa',
			'name'    => __( 'NV oOS Chat SPA', 'nvoos-chat-spa' ),
			'version' => defined( 'NVOOS_CHAT_SPA_VERSION' ) ? NVOOS_CHAT_SPA_VERSION : 'unknown',
			'surface' => 'chat',
			'bundle'  => array(
				'js'  => defined( 'NVOOS_CHAT_SPA_URL' ) ? NVOOS_CHAT_SPA_URL . 'assets/dist/chat-spa.js' : '',
				'css' => defined( 'NVOOS_CHAT_SPA_URL' ) ? NVOOS_CHAT_SPA_URL . 'assets/dist/chat-spa.css' : '',
			),
		);
		return rest_ensure_response( apply_filters( 'nvoos_chat_spa_manifest', $payload ) );
	}

	/**
	 * Config endpoint — returns the NV oOS chat endpoint URLs and feature flags
	 * the SPA needs to wire its custom fetch + SSE → AI SDK Data Stream adapter.
	 *
	 * Public-readable: it returns no secrets. The endpoint URLs themselves are
	 * already discoverable via the WP REST root, and authentication is enforced
	 * by the underlying mcp-ai/v1/* routes (nonce, assistant credentials, Auth0,
	 * or guest token).
	 *
	 * @return WP_REST_Response
	 */
	public static function config() {
		$payload = array(
			'endpoints' => array(
				'chat'        => esc_url_raw( rest_url( 'mcp-ai/v1/chat' ) ),
				'chatClient'  => esc_url_raw( rest_url( 'mcp-ai/v1/chat-client' ) ),
				'transcripts' => esc_url_raw( rest_url( 'mcp-ai/v1/chat-transcripts' ) ),
				'memory'      => esc_url_raw( rest_url( 'mcp-ai/v1/chat-memory' ) ),
			),
			'features'  => array(
				'sseAdapter'      => true,
				'aiSdkDataStream' => false,
				'memoryDrawer'    => true,
				'transcripts'     => true,
				'guestTokens'     => true,
			),
		);
		return rest_ensure_response( apply_filters( 'nvoos_chat_spa_config', $payload ) );
	}
}
