<?php
/**
 * REST Controller: Workflow Triggers & Outbound Webhooks.
 *
 * @package WP_MCP_AI
 * @since   2.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for workflow triggers and outbound webhook subscriptions.
 *
 * @since 2.2.0
 */
class WP_MCP_AI_REST_Triggers_Controller extends WP_REST_Controller {

	const NAMESPACE    = 'mcp-ai/v1';
	const BASE         = 'orchestration/triggers';
	const WEBHOOK_BASE = 'orchestration/webhooks';

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'name'        => array(
							'required' => true,
							'type' => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'type'        => array(
							'required' => true,
							'type' => 'string',
							'sanitize_callback' => 'sanitize_key',
						),
						'config'      => array(
							'required' => false,
							'type' => 'object',
							'default' => array(),
						),
						'workflow_id' => array(
							'required' => true,
							'type' => 'integer',
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::EDITABLE,
					'callback'            => array( $this, 'update_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'name'        => array(
							'required' => false,
							'type' => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
						'config'      => array(
							'required' => false,
							'type' => 'object',
						),
						'workflow_id' => array(
							'required' => false,
							'type' => 'integer',
							'sanitize_callback' => 'absint',
						),
						'enabled'     => array(
							'required' => false,
							'type' => 'boolean',
						),
					),
				),
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Public inbound webhook receiver — no auth, HMAC verified.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/webhook/(?P<id>\d+)',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'receive_webhook' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::WEBHOOK_BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_webhooks' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'subscribe_webhook' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'url'    => array(
							'required' => true,
							'type' => 'string',
							'sanitize_callback' => 'esc_url_raw',
						),
						'events' => array(
							'required' => true,
							'type' => 'array',
							'items' => array( 'type' => 'string' ),
						),
						'secret' => array(
							'required' => false,
							'type' => 'string',
							'default' => '',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE,
			'/' . self::WEBHOOK_BASE . '/(?P<id>[a-z0-9_]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'unsubscribe_webhook' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission callback.
	 *
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error( 'rest_forbidden', __( 'You do not have permission to manage workflow triggers.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		return true;
	}

	/**
	 * List triggers.
	 *
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$posts = get_posts(
			array(
				'post_type'      => WP_MCP_AI_Workflow_Trigger_CPT::CPT,
				'post_status'    => array( 'publish', 'draft' ),
				'posts_per_page' => 100,
			)
		);
		return rest_ensure_response( array_map( array( $this, 'prepare_trigger' ), $posts ) );
	}

	/**
	 * Get single trigger.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$post = get_post( absint( $request->get_param( 'id' ) ) );
		if ( ! $post || WP_MCP_AI_Workflow_Trigger_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Trigger not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response( $this->prepare_trigger( $post ) );
	}

	/**
	 * Create trigger.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_item( $request ) {
		$type = sanitize_key( $request->get_param( 'type' ) );
		if ( ! WP_MCP_AI_Workflow_Trigger_Registry::get_instance()->get_trigger( $type ) ) {
			return new WP_Error( 'invalid_type', __( 'Unknown trigger type.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}
		$post_id = wp_insert_post(
			array(
				'post_title'  => sanitize_text_field( $request->get_param( 'name' ) ),
				'post_type'   => WP_MCP_AI_Workflow_Trigger_CPT::CPT,
				'post_status' => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}
		update_post_meta( $post_id, '_wp_mcp_ai_trigger_type', $type );
		update_post_meta( $post_id, '_wp_mcp_ai_trigger_config', wp_json_encode( $request->get_param( 'config' ) ?: array() ) );
		update_post_meta( $post_id, '_wp_mcp_ai_trigger_workflow_id', absint( $request->get_param( 'workflow_id' ) ) );
		update_post_meta( $post_id, '_wp_mcp_ai_trigger_enabled', true );
		return rest_ensure_response( $this->prepare_trigger( get_post( $post_id ) ) );
	}

	/**
	 * Update trigger.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );
		if ( ! $post || WP_MCP_AI_Workflow_Trigger_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Trigger not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}
		if ( $request->has_param( 'name' ) ) {
			wp_update_post(
				array(
					'ID' => $id,
					'post_title' => sanitize_text_field( $request->get_param( 'name' ) ),
				)
			);
		}
		if ( $request->has_param( 'config' ) ) {
			update_post_meta( $id, '_wp_mcp_ai_trigger_config', wp_json_encode( $request->get_param( 'config' ) ) );
		}
		if ( $request->has_param( 'workflow_id' ) ) {
			update_post_meta( $id, '_wp_mcp_ai_trigger_workflow_id', absint( $request->get_param( 'workflow_id' ) ) );
		}
		if ( $request->has_param( 'enabled' ) ) {
			update_post_meta( $id, '_wp_mcp_ai_trigger_enabled', (bool) $request->get_param( 'enabled' ) );
		}
		return rest_ensure_response( $this->prepare_trigger( get_post( $id ) ) );
	}

	/**
	 * Delete trigger.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_item( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );
		if ( ! $post || WP_MCP_AI_Workflow_Trigger_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Trigger not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}
		wp_delete_post( $id, true );
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id' => $id,
			)
		);
	}

	/**
	 * Public inbound webhook receiver.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function receive_webhook( $request ) {
		$id   = absint( $request->get_param( 'id' ) );
		$post = get_post( $id );
		if ( ! $post || WP_MCP_AI_Workflow_Trigger_CPT::CPT !== $post->post_type ) {
			return new WP_Error( 'not_found', __( 'Trigger not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}
		if ( ! (bool) get_post_meta( $id, '_wp_mcp_ai_trigger_enabled', true ) ) {
			return new WP_Error( 'trigger_disabled', __( 'Trigger is disabled.', 'mcp-ai-wpoos' ), array( 'status' => 403 ) );
		}
		$config_json = get_post_meta( $id, '_wp_mcp_ai_trigger_config', true );
		$config      = ! empty( $config_json ) ? json_decode( $config_json, true ) : array();
		$secret      = ! empty( $config['secret'] ) ? $config['secret'] : '';
		if ( ! empty( $secret ) ) {
			$signature = (string) $request->get_header( 'X-WP-MCP-AI-Signature-256' );
			if ( ! WP_MCP_AI_Outbound_Webhook::get_instance()->verify_signature( $request->get_body(), $signature, $secret ) ) {
				return new WP_Error( 'invalid_signature', __( 'Signature verification failed.', 'mcp-ai-wpoos' ), array( 'status' => 401 ) );
			}
		}
		$workflow_id = (int) get_post_meta( $id, '_wp_mcp_ai_trigger_workflow_id', true );
		WP_MCP_AI_Workflow_Trigger_CPT::fire_trigger( $id, $workflow_id, $request->get_json_params() ?: array() );
		return rest_ensure_response(
			array(
				'success' => true,
				'trigger_id' => $id,
			)
		);
	}

	/**
	 * List outbound webhook subscriptions.
	 *
	 * @return WP_REST_Response
	 */
	public function list_webhooks( $request ) {
		$items = WP_MCP_AI_Outbound_Webhook::get_instance()->list_subscriptions();
		$items = array_map(
			function ( $item ) {
				unset( $item['secret'] );
				return $item;
			},
			$items
		);
		return rest_ensure_response( $items );
	}

	/**
	 * Subscribe outbound webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function subscribe_webhook( $request ) {
		$url = esc_url_raw( $request->get_param( 'url' ) );
		if ( empty( $url ) ) {
			return new WP_Error( 'invalid_url', __( 'A valid URL is required.', 'mcp-ai-wpoos' ), array( 'status' => 400 ) );
		}
		$events = array_map( 'sanitize_text_field', (array) $request->get_param( 'events' ) );
		$secret = sanitize_text_field( $request->get_param( 'secret' ) ?: '' );
		$id     = WP_MCP_AI_Outbound_Webhook::get_instance()->subscribe( $url, $events, $secret );
		return rest_ensure_response(
			array(
				'id' => $id,
				'url' => $url,
				'events' => $events,
			)
		);
	}

	/**
	 * Unsubscribe outbound webhook.
	 *
	 * @param WP_REST_Request $request Request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function unsubscribe_webhook( $request ) {
		$webhook_id = sanitize_key( $request->get_param( 'id' ) );
		if ( ! WP_MCP_AI_Outbound_Webhook::get_instance()->unsubscribe( $webhook_id ) ) {
			return new WP_Error( 'not_found', __( 'Webhook subscription not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}
		return rest_ensure_response(
			array(
				'deleted' => true,
				'id' => $webhook_id,
			)
		);
	}

	/**
	 * Prepare a trigger post for REST response.
	 *
	 * @param WP_Post $post Trigger post.
	 * @return array
	 */
	private function prepare_trigger( $post ) {
		$type        = (string) get_post_meta( $post->ID, '_wp_mcp_ai_trigger_type', true );
		$config_json = get_post_meta( $post->ID, '_wp_mcp_ai_trigger_config', true );
		$webhook_url = '';
		if ( 'rest_webhook' === $type ) {
			$webhook_url = rest_url( self::NAMESPACE . '/' . self::BASE . '/webhook/' . $post->ID );
		}
		return array(
			'id'            => $post->ID,
			'name'          => esc_html( $post->post_title ),
			'type'          => esc_html( $type ),
			'config'        => ! empty( $config_json ) ? json_decode( $config_json, true ) : array(),
			'workflow_id'   => (int) get_post_meta( $post->ID, '_wp_mcp_ai_trigger_workflow_id', true ),
			'enabled'       => (bool) get_post_meta( $post->ID, '_wp_mcp_ai_trigger_enabled', true ),
			'last_fired_at' => (int) get_post_meta( $post->ID, '_wp_mcp_ai_trigger_last_fired_at', true ),
			'webhook_url'   => esc_url( $webhook_url ),
		);
	}
}
