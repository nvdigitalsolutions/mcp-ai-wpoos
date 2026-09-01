<?php
/**
 * ACP JSON-RPC 2.0 Dispatcher.
 *
 * Handles the parsing and dispatching of incoming JSON-RPC methods
 * like `initialize`, `session/prompt`, `session/new`, etc.
 *
 * @package NvoosContentGraphAiPlatform
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 * @since     2.0.0 Ported from mcp-ai-wpoos/includes/acp/class-wp-mcp-ai-acp-jsonrpc-dispatcher.php.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ACP;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Dispatcher for ACP JSON-RPC methods.
 */
class JsonRpcDispatcher {

	/**
	 * Session Manager instance.
	 *
	 * @var SessionManager
	 */
	protected $session_manager;

	/**
	 * Session Bridge instance.
	 *
	 * @var SessionBridge
	 */
	protected $session_bridge;

	/**
	 * Chat Service Instance.
	 *
	 * @var object|null
	 */
	protected $chat_service;

	/**
	 * Constructor.
	 *
	 * @param SessionManager $session_manager Session manager.
	 * @param SessionBridge  $session_bridge  Session bridge.
	 */
	public function __construct( SessionManager $session_manager, SessionBridge $session_bridge ) {
		$this->session_manager = $session_manager;
		$this->session_bridge  = $session_bridge;
		$this->chat_service    = null; // Injected on first real dispatch.
	}

	/**
	 * Inject the chat service lazily if not provided.
	 *
	 * @param object $chat_service Instance of chat service.
	 */
	public function set_chat_service( $chat_service ) {
		$this->chat_service = $chat_service;
		$this->session_bridge->set_chat_service( $chat_service );
	}

	/**
	 * Process an incoming JSON-RPC request.
	 *
	 * @param array $request Request body array.
	 * @return array|null Response array in JSON-RPC format, or null for notifications.
	 */
	public function dispatch( $request ) {
		// Validate JSON-RPC format.
		if ( ! isset( $request['jsonrpc'] ) || '2.0' !== $request['jsonrpc'] ) {
			return $this->error_response( null, -32600, 'Invalid Request: Missing or invalid jsonrpc version' );
		}

		if ( ! isset( $request['method'] ) || ! is_string( $request['method'] ) ) {
			return $this->error_response( isset( $request['id'] ) ? $request['id'] : null, -32600, 'Invalid Request: Missing method' );
		}

		$method = $request['method'];
		$params = isset( $request['params'] ) ? $request['params'] : array();
		$id     = isset( $request['id'] ) ? $request['id'] : null;

		// Route to appropriate handler method.
		switch ( $method ) {
			case 'initialize':
				return $this->handle_initialize( $params, $id );

			case 'session/new':
				return $this->handle_session_new( $params, $id );

			case 'session/prompt':
				return $this->handle_session_prompt( $params, $id );

			case 'session/load':
				return $this->handle_session_load( $params, $id );

			case 'session/list':
				return $this->handle_session_list( $params, $id );

			case 'session/cancel':
				// Cancel is usually a notification, so it doesn't return a JSON-RPC result if it doesn't have an ID.
				$this->handle_session_cancel( $params );
				if ( null === $id ) {
					return null; // Notifications don't get responses.
				}
				return $this->success_response( $id, array( 'status' => 'cancelled' ) );

			default:
				return $this->error_response( $id, -32601, 'Method not found' );
		}
	}

	/**
	 * Handle 'initialize' method.
	 *
	 * @param array $params Method parameters.
	 * @param mixed $id     Request ID.
	 * @return array JSON-RPC response.
	 */
	protected function handle_initialize( $params, $id ) {
		// Capability negotiation.
		$result = array(
			'protocolVersion'   => 1,
			'agentCapabilities' => array(
				'loadSession'        => true,
				'promptCapabilities' => array(
					'image'           => true,
					'audio'           => false,
					'embeddedContext' => true,
				),
				'mcpCapabilities'    => array(
					'http' => true,
					'sse'  => false,
				),
			),
			'agentInfo'         => array(
				'name'    => 'nv-oos',
				'title'   => 'NV oOS Assistant',
				'version' => '1.0.0', // Update with actual version if needed.
			),
			'authMethods'       => array(
				'wp_nonce',
				'bearer_credential',
				'bearer_auth0',
				'guest',
			),
		);

		return $this->success_response( $id, $result );
	}

	/**
	 * Handle 'session/new' method.
	 *
	 * @param array $params Method parameters.
	 * @param mixed $id     Request ID.
	 * @return array JSON-RPC response.
	 */
	protected function handle_session_new( $params, $id ) {
		$result = $this->session_manager->create_session( $params );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $id, $result->get_error_code(), $result->get_error_message() );
		}
		return $this->success_response( $id, $result );
	}

	/**
	 * Handle 'session/load' method.
	 *
	 * @param array $params Method parameters.
	 * @param mixed $id     Request ID.
	 * @return array JSON-RPC response.
	 */
	protected function handle_session_load( $params, $id ) {
		if ( empty( $params['sessionId'] ) ) {
			return $this->error_response( $id, -32602, 'Invalid params: Missing sessionId' );
		}

		$result = $this->session_manager->load_session( $params['sessionId'] );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $id, $result->get_error_code(), $result->get_error_message() );
		}

		return $this->success_response( $id, $result );
	}

	/**
	 * Handle 'session/list' method.
	 *
	 * @param array $params Method parameters.
	 * @param mixed $id     Request ID.
	 * @return array JSON-RPC response.
	 */
	protected function handle_session_list( $params, $id ) {
		$result = $this->session_manager->list_sessions();
		return $this->success_response( $id, $result );
	}

	/**
	 * Handle 'session/prompt' method.
	 *
	 * @param array $params Method parameters.
	 * @param mixed $id     Request ID.
	 * @return array JSON-RPC response.
	 */
	protected function handle_session_prompt( $params, $id ) {
		if ( empty( $params['sessionId'] ) ) {
			return $this->error_response( $id, -32602, 'Invalid params: Missing sessionId' );
		}

		if ( empty( $params['prompt'] ) || ! is_array( $params['prompt'] ) ) {
			return $this->error_response( $id, -32602, 'Invalid params: Missing or invalid prompt' );
		}

		$result = $this->session_bridge->handle_prompt( $params );
		if ( is_wp_error( $result ) ) {
			return $this->error_response( $id, $result->get_error_code(), $result->get_error_message() );
		}

		// Success response is usually a stopReason object.
		return $this->success_response( $id, $result );
	}

	/**
	 * Handle 'session/cancel' notification.
	 *
	 * @param array $params Method parameters.
	 */
	protected function handle_session_cancel( $params ) {
		if ( ! empty( $params['sessionId'] ) ) {
			$this->session_manager->cancel_session( $params['sessionId'] );
		}
	}

	/**
	 * Helper to format a successful JSON-RPC response.
	 *
	 * @param mixed $id     Request ID.
	 * @param array $result Result payload.
	 * @return array JSON-RPC formatted response.
	 */
	protected function success_response( $id, $result ) {
		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'result'  => $result,
		);
	}

	/**
	 * Helper to format an error JSON-RPC response.
	 *
	 * @param mixed  $id      Request ID.
	 * @param int    $code    Error code.
	 * @param string $message Error message.
	 * @param mixed  $data    Optional error data.
	 * @return array JSON-RPC formatted error response.
	 */
	protected function error_response( $id, $code, $message, $data = null ) {
		$error = array(
			'code'    => $code,
			'message' => $message,
		);

		if ( null !== $data ) {
			$error['data'] = $data;
		}

		return array(
			'jsonrpc' => '2.0',
			'id'      => $id,
			'error'   => $error,
		);
	}
}
