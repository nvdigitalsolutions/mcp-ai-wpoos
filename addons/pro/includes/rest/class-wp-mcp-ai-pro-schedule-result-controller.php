<?php
/**
 * Pro Schedule Result REST Controller
 *
 * Exposes the structured result envelope produced by Pro Schedule runs so
 * that the Scheduled Result block / Elementor widget (and any external
 * dashboard) can render the latest output without reading options directly.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_Schedule_Result_Controller
 *
 * REST controller for scheduled-result envelopes and previews.
 */
class WP_MCP_AI_Pro_Schedule_Result_Controller {

	/**
	 * REST namespace for Pro endpoints.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai-pro/v1';

	/**
	 * Required capability for full (authenticated) reads / state-changing routes.
	 *
	 * Filterable so site owners can swap in a custom cap such as
	 * `mcp_ai_pro_view_schedule_results`.
	 *
	 * @return string Capability slug.
	 */
	protected function admin_capability() {
		/**
		 * Filter the capability required to read full schedule result envelopes.
		 *
		 * @since 1.0.0
		 *
		 * @param string $capability Default capability ('read_private_posts').
		 */
		return (string) apply_filters( 'wp_mcp_ai_pro_schedule_result_capability', 'read_private_posts' );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		register_rest_route(
			$this->namespace,
			'/schedules',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'list_selectable_schedules' ),
				'permission_callback' => array( $this, 'permission_admin_read' ),
				'args'                => array(
					'selectable' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9_-]+)/latest-result',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_latest_result' ),
				'permission_callback' => array( $this, 'permission_read_latest' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9_-]+)/results',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_results_history' ),
				'permission_callback' => array( $this, 'permission_admin_read' ),
				'args'                => array(
					'id'    => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
					'limit' => array(
						'type'    => 'integer',
						'default' => 10,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		register_rest_route(
			$this->namespace,
			'/schedules/(?P<id>[A-Za-z0-9_-]+)/preview',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'trigger_preview' ),
				'permission_callback' => array( $this, 'permission_admin_write' ),
				'args'                => array(
					'id' => array(
						'type'              => 'string',
						'required'          => true,
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);
	}

	// -------------------------------------------------------------------------
	// Permission callbacks
	// -------------------------------------------------------------------------

	/**
	 * Allow reads when the user can see private posts or the schedule has opted into public render.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permission_read_latest( $request ) {
		if ( current_user_can( $this->admin_capability() ) ) {
			return true;
		}
		$schedule_id = $request->get_param( 'id' );
		$schedule    = $this->get_schedule( $schedule_id );
		if ( $schedule && ! empty( $schedule['display']['public_render'] ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'You do not have permission to view this schedule result.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Authenticated read permission.
	 *
	 * @return bool|WP_Error
	 */
	public function permission_admin_read() {
		if ( current_user_can( $this->admin_capability() ) ) {
			return true;
		}
		return new WP_Error(
			'rest_forbidden',
			__( 'Authentication required.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => rest_authorization_required_code() )
		);
	}

	/**
	 * Authenticated write permission (requires nonce + manage_options).
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return bool|WP_Error
	 */
	public function permission_admin_write( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to trigger a preview.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		// Nonce is verified by WordPress core when the request includes X-WP-Nonce
		// and the user is authenticated; double-check for state-changing routes.
		$nonce = $request->get_header( 'X-WP-Nonce' );
		if ( empty( $nonce ) || ! wp_verify_nonce( $nonce, 'wp_rest' ) ) {
			return new WP_Error(
				'rest_cookie_invalid_nonce',
				__( 'Missing or invalid nonce.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	// -------------------------------------------------------------------------
	// Route handlers
	// -------------------------------------------------------------------------

	/**
	 * GET /schedules?selectable=1 — lightweight picker for the block inspector.
	 *
	 * @return WP_REST_Response
	 */
	public function list_selectable_schedules() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return rest_ensure_response( array() );
		}
		$schedules = WP_MCP_AI_Pro_Schedule_Manager::get_schedules();
		$out       = array();
		foreach ( $schedules as $id => $schedule ) {
			$out[] = array(
				'id'            => (string) $id,
				'name'          => isset( $schedule['name'] ) ? (string) $schedule['name'] : (string) $id,
				'schedule_type' => isset( $schedule['schedule_type'] ) ? (string) $schedule['schedule_type'] : '',
				'last_run_time' => isset( $schedule['last_run_time'] ) ? (int) $schedule['last_run_time'] : 0,
				'enabled'       => ! empty( $schedule['enabled'] ),
			);
		}
		return rest_ensure_response( $out );
	}

	/**
	 * GET /schedules/{id}/latest-result.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_latest_result( $request ) {
		$schedule_id = $request->get_param( 'id' );
		$schedule    = $this->get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error( 'not_found', __( 'Schedule not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$envelope = WP_MCP_AI_Pro_Schedule_Manager::get_latest_result( $schedule_id );
		if ( null === $envelope ) {
			$envelope = array(
				'summary'      => __( 'No runs recorded yet.', 'mcp-ai-wpoos-pro' ),
				'data'         => array(),
				'render'       => 'text',
				'status'       => 'pending',
				'error'        => '',
				'generated_at' => 0,
			);
		}

		if ( ! current_user_can( $this->admin_capability() ) ) {
			$envelope = WP_MCP_AI_Pro_Schedule_Manager::redact_envelope_for_public( $envelope, $schedule );
		}

		$response = rest_ensure_response(
			array(
				'schedule_id' => $schedule_id,
				'name'        => isset( $schedule['name'] ) ? $schedule['name'] : '',
				'envelope'    => $envelope,
			)
		);

		// Send a weak ETag based on generated_at so clients can revalidate cheaply.
		if ( ! empty( $envelope['generated_at'] ) ) {
			$etag = 'W/"' . md5( $schedule_id . '|' . $envelope['generated_at'] . '|' . (int) current_user_can( $this->admin_capability() ) ) . '"';
			$response->header( 'ETag', $etag );
			$response->header( 'Cache-Control', 'private, max-age=30' );
		}

		return $response;
	}

	/**
	 * GET /schedules/{id}/results.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_results_history( $request ) {
		$schedule_id = $request->get_param( 'id' );
		$limit       = (int) $request->get_param( 'limit' );
		$schedule    = $this->get_schedule( $schedule_id );
		if ( ! $schedule ) {
			return new WP_Error( 'not_found', __( 'Schedule not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}
		$envelopes = WP_MCP_AI_Pro_Schedule_Manager::get_results( $schedule_id, $limit );
		return rest_ensure_response(
			array(
				'schedule_id' => $schedule_id,
				'results'     => $envelopes,
				'count'       => count( $envelopes ),
			)
		);
	}

	/**
	 * POST /schedules/{id}/preview — runs a one-shot synchronous preview.
	 *
	 * @param WP_REST_Request $request Incoming request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function trigger_preview( $request ) {
		$schedule_id = $request->get_param( 'id' );
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return new WP_Error( 'not_available', __( 'Pro Schedule Manager is unavailable.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		// Simple per-user rate limit: 1 preview every 10 seconds.
		$user_id  = get_current_user_id();
		$rate_key = 'wp_mcp_ai_pro_preview_' . $user_id . '_' . md5( $schedule_id );
		if ( get_transient( $rate_key ) ) {
			return new WP_Error(
				'rate_limited',
				__( 'Please wait a few seconds before triggering another preview.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 429 )
			);
		}
		set_transient( $rate_key, 1, 10 );

		$result = WP_MCP_AI_Pro_Schedule_Manager::trigger_preview( $schedule_id );
		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'schedule_id' => $schedule_id,
				'envelope'    => $result,
			)
		);
	}

	/**
	 * Internal helper: fetch a schedule by ID safely.
	 *
	 * @param string $schedule_id Schedule ID.
	 * @return array|null Schedule record or null when missing.
	 */
	protected function get_schedule( $schedule_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return null;
		}
		$schedule = WP_MCP_AI_Pro_Schedule_Manager::get_schedule( $schedule_id );
		return is_array( $schedule ) ? $schedule : null;
	}
}

// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed -- Bootstrap function must live beside the class.
if ( ! function_exists( 'wp_mcp_ai_register_pro_schedule_result_routes' ) ) {
	/**
	 * Bootstrap the controller on `rest_api_init`.
	 *
	 * @return void
	 */
	function wp_mcp_ai_register_pro_schedule_result_routes() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Manager' ) ) {
			return;
		}
		$controller = new WP_MCP_AI_Pro_Schedule_Result_Controller();
		$controller->register_routes();
	}
}
add_action( 'rest_api_init', 'wp_mcp_ai_register_pro_schedule_result_routes' );
// phpcs:enable Universal.Files.SeparateFunctionsFromOO.Mixed
