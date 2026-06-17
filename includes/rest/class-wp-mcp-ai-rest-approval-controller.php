<?php
/**
 * REST API: Approval Queue Controller.
 *
 * Exposes HITL approval management endpoints. All routes require
 * `manage_options` unless the requester is the original approval requester,
 * in which case they can read their own approvals.
 *
 * Routes:
 *   GET  /mcp-ai/v1/approvals           — list pending approvals (manage_options)
 *   GET  /mcp-ai/v1/approvals/{id}      — get single approval
 *   POST /mcp-ai/v1/approvals/{id}/approve — approve
 *   POST /mcp-ai/v1/approvals/{id}/deny   — deny
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for the HITL approval queue.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_REST_Approval_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 */
	const BASE = 'approvals';

	/**
	 * Register routes.
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'get_items_permissions_check' ),
					'args'                => array(
						'assistant_id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 0,
						),
						'session_id'   => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
							'default'           => '',
						),
						'limit'        => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'default'           => 20,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>[\d]+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'get_item_permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>[\d]+)/approve',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'approve_item' ),
					'permission_callback' => array( $this, 'resolve_permissions_check' ),
					'args'                => array(
						'id'   => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
						'note' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>[\d]+)/deny',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'deny_item' ),
					'permission_callback' => array( $this, 'resolve_permissions_check' ),
					'args'                => array(
						'id'   => array(
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
							'required'          => true,
						),
						'note' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_textarea_field',
							'default'           => '',
						),
					),
				),
			)
		);
	}

	/**
	 * GET /approvals u2014 list pending approvals.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_items( $request ) {
		$queue = WP_MCP_AI_Approval_Queue::get_instance();
		$items = $queue->get_pending(
			array(
				'assistant_id' => (int) $request['assistant_id'],
				'session_id'   => (string) $request['session_id'],
				'limit'        => min( 100, (int) $request['limit'] ),
			)
		);

		return rest_ensure_response(
			array(
				'approvals' => $items,
				'total'     => count( $items ),
			)
		);
	}

	/**
	 * GET /approvals/{id}
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$queue  = WP_MCP_AI_Approval_Queue::get_instance();
		$record = $queue->get( (int) $request['id'] );

		if ( null === $record ) {
			return new WP_Error( 'approval_not_found', __( 'Approval not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		return rest_ensure_response( $record );
	}

	/**
	 * POST /approvals/{id}/approve
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function approve_item( $request ) {
		$queue  = WP_MCP_AI_Approval_Queue::get_instance();
		$result = $queue->approve(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request['note']
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'approval_id' => (int) $request['id'],
				'status'      => 'approved',
			)
		);
	}

	/**
	 * POST /approvals/{id}/deny
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function deny_item( $request ) {
		$queue  = WP_MCP_AI_Approval_Queue::get_instance();
		$result = $queue->deny(
			(int) $request['id'],
			get_current_user_id(),
			(string) $request['note']
		);

		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				array( 'status' => 400 )
			);
		}

		return rest_ensure_response(
			array(
				'success'     => true,
				'approval_id' => (int) $request['id'],
				'status'      => 'denied',
			)
		);
	}

	/**
	 * Permission check: list approvals requires manage_options.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_items_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to view the approval queue.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	/**
	 * Permission check: reading a single approval.
	 * Admin OR the original requester can read their own approval.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function get_item_permissions_check( $request ) {
		if ( current_user_can( 'manage_options' ) ) {
			return true;
		}

		// Check if current user is the requester.
		$queue  = WP_MCP_AI_Approval_Queue::get_instance();
		$record = $queue->get( (int) $request['id'] );
		if ( $record && get_current_user_id() === (int) $record['requester_id'] ) {
			return true;
		}

		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to view this approval.', 'mcp-ai-wpoos' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Permission check: resolve (approve/deny) requires manage_options.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function resolve_permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to resolve approvals.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}
}
