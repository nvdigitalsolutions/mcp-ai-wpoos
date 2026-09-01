<?php
/**
 * ACP HTTP Transport Controller.
 *
 * Exposes the `/wp-json/mcp-ai/v1/acp` endpoint for clients.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/acp/transport/class-wp-mcp-ai-acp-transport-http.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * HTTP Transport for ACP.
 */
class TransportHttp extends AbstractRestController {

	/**
	 * ACP JSON-RPC Dispatcher.
	 *
	 * @var JsonRpcDispatcher
	 */
	protected $dispatcher;

	/**
	 * Constructor.
	 *
	 * @param JsonRpcDispatcher $dispatcher Dispatcher instance.
	 */
	public function __construct( JsonRpcDispatcher $dispatcher ) {
		$this->dispatcher = $dispatcher;
		$this->namespace  = 'mcp-ai/v1';
		$this->rest_base  = 'acp';
	}

	/**
	 * Register the routes for the objects of the controller.
	 */
	public function register_routes() {
		// POST /mcp-ai/v1/acp (JSON-RPC methods).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base,
			array(
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'handle_request' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);

		// GET /mcp-ai/v1/acp/sse (Server-Sent Events).
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/sse',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_sse_request' ),
					'permission_callback' => array( $this, 'check_permissions' ),
				),
			)
		);
	}

	/**
	 * Check permissions.
	 *
	 * NOTE — intentional deviation from the base plugin's ported source: the
	 * base copy ships a permissive `return true` TODO. The platform port is
	 * only mounted in standalone mode (no base plugin auth stack), so it
	 * enforces a real capability check instead of reproducing the bypass.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return bool|\WP_Error True if the request has access, WP_Error object otherwise.
	 */
	public function check_permissions( $request ) {
		if ( is_user_logged_in() && current_user_can( 'edit_posts' ) ) {
			return true;
		}

		return new \WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to access the ACP endpoint.', 'nvoos-content-graph-ai-platform' ),
			array( 'status' => 401 )
		);
	}

	/**
	 * Handle the incoming JSON-RPC POST request.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response Response object.
	 */
	public function handle_request( $request ) {
		$body = $request->get_json_params();

		if ( empty( $body ) ) {
			return new \WP_REST_Response( array( 'error' => 'Invalid JSON' ), 400 );
		}

		$response = $this->dispatcher->dispatch( $body );
		return new \WP_REST_Response( $response, 200 );
	}

	/**
	 * Handle the incoming SSE connection request.
	 *
	 * @param \WP_REST_Request $request Full details about the request.
	 * @return \WP_REST_Response Response object.
	 */
	public function handle_sse_request( $request ) {
		$session_id = $request->get_param( 'sessionId' );
		if ( empty( $session_id ) ) {
			return new \WP_REST_Response( array( 'error' => 'Missing sessionId' ), 400 );
		}

		// Turn off output buffering for SSE.
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}

		header( 'Content-Type: text/event-stream' );
		header( 'Cache-Control: no-cache' );
		header( 'Connection: keep-alive' );
		header( 'X-Accel-Buffering: no' );

		// Establish connection message.
		echo "event: endpoint\n";
		echo 'data: ' . wp_json_encode( rest_url( $this->namespace . '/' . $this->rest_base ) ) . "\n\n";
		flush();

		// Keep connection alive, listen for ACP update payloads
		// In a true SSE implementation for WP, we would rely on a job queue, Redis PubSub, or sleep-loop polling
		// to grab pending updates for this session_id from the SessionManager.

		$max_execution_time = 300; // 5 minutes max
		$start_time         = time();

		while ( ( time() - $start_time ) < $max_execution_time && ! connection_aborted() ) {
			// Pull pending updates from Transient/DB for this $session_id.
			$updates = get_transient( 'acp_updates_' . $session_id );

			if ( ! empty( $updates ) ) {
				foreach ( $updates as $update ) {
					echo "event: message\n";
					echo 'data: ' . wp_json_encode( $update ) . "\n\n";
				}
				flush();
				delete_transient( 'acp_updates_' . $session_id );
			}

			// Keep-alive heartbeat.
			if ( time() % 15 === 0 ) {
				echo ": keep-alive\n\n";
				flush();
			}

			sleep( 1 );
		}

		exit;
	}
}
