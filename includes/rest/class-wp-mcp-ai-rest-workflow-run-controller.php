<?php
/**
 * REST Controller: Workflow Run log & cancellation.
 *
 * Exposes the durable execution event log stored in `mcp_ai_workflow_run`
 * posts. All routes require `manage_options`.
 *
 * Routes (namespace `mcp-ai/v1`):
 *   GET    /orchestration/runs                     — list runs
 *   GET    /orchestration/runs/{id}                — get single run (with event log)
 *   DELETE /orchestration/runs/{id}                — cancel a pending/running run
 *   GET    /orchestration/runs/{id}/events         — return event log as JSON array
 *
 * @package WP_MCP_AI
 * @since   2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * REST controller for workflow run records.
 *
 * @since 2.1.0
 */
class WP_MCP_AI_REST_Workflow_Run_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 */
	const NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 */
	const BASE = 'orchestration/runs';

	/**
	 * Register all routes.
	 *
	 * @return void
	 */
	public function register_routes() {
		// Collection: list runs.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE,
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'workflow_id' => array(
							'required'          => false,
							'type'              => 'integer',
							'sanitize_callback' => 'absint',
						),
						'status'      => array(
							'required' => false,
							'type'     => 'string',
							'enum'     => WP_MCP_AI_Workflow_Run_CPT::STATUSES,
						),
						'per_page'    => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 20,
							'minimum'           => 1,
							'maximum'           => 100,
							'sanitize_callback' => 'absint',
						),
						'page'        => array(
							'required'          => false,
							'type'              => 'integer',
							'default'           => 1,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);

		// Single run.
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
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'cancel_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		// Event log sub-resource.
		register_rest_route(
			self::NAMESPACE,
			'/' . self::BASE . '/(?P<id>\d+)/events',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_events' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	// ── Permission ────────────────────────────────────────────────────────────

	/**
	 * All routes require manage_options.
	 *
	 * @param WP_REST_Request $request Current request.
	 * @return bool|WP_Error
	 */
	public function permissions_check( $request ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Sorry, you are not allowed to access workflow runs.', 'mcp-ai-wpoos' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}
		return true;
	}

	// ── Handlers ──────────────────────────────────────────────────────────────

	/**
	 * GET /orchestration/runs — list runs with optional filters.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response
	 */
	public function get_items( $request ) {
		$per_page    = $request->get_param( 'per_page' ) ? absint( $request->get_param( 'per_page' ) ) : 20;
		$page        = $request->get_param( 'page' ) ? absint( $request->get_param( 'page' ) ) : 1;
		$workflow_id = $request->get_param( 'workflow_id' ) ? absint( $request->get_param( 'workflow_id' ) ) : 0;
		$status      = $request->get_param( 'status' ) ? sanitize_key( $request->get_param( 'status' ) ) : '';

		$meta_query = array();

		if ( $workflow_id ) {
			$meta_query[] = array(
				'key'     => '_wp_mcp_ai_run_workflow_id',
				'value'   => $workflow_id,
				'compare' => '=',
				'type'    => 'NUMERIC',
			);
		}

		if ( $status && in_array( $status, WP_MCP_AI_Workflow_Run_CPT::STATUSES, true ) ) {
			$meta_query[] = array(
				'key'     => '_wp_mcp_ai_run_status',
				'value'   => $status,
				'compare' => '=',
			);
		}

		$query_args = array(
			'post_type'      => WP_MCP_AI_Workflow_Run_CPT::CPT,
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'ID',
			'order'          => 'DESC',
			'post_status'    => 'publish',
		);

		if ( ! empty( $meta_query ) ) {
			$query_args['meta_query'] = $meta_query;
		}

		$query = new WP_Query( $query_args );
		$runs  = array();

		foreach ( $query->posts as $post ) {
			$run = WP_MCP_AI_Workflow_Run_CPT::get_run( $post->ID );
			if ( $run ) {
				// Omit event_log from list view for brevity.
				unset( $run['event_log'] );
				$runs[] = $run;
			}
		}

		$response = rest_ensure_response( $runs );
		$response->header( 'X-WP-Total', (int) $query->found_posts );
		$response->header( 'X-WP-TotalPages', (int) $query->max_num_pages );

		return $response;
	}

	/**
	 * GET /orchestration/runs/{id} — get a single run with full event log.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_item( $request ) {
		$run_id = absint( $request->get_param( 'id' ) );
		$run    = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );

		if ( ! $run ) {
			return new WP_Error(
				'run_not_found',
				__( 'Workflow run not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		return rest_ensure_response( $run );
	}

	/**
	 * DELETE /orchestration/runs/{id} — cancel a pending or running run.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function cancel_item( $request ) {
		$run_id = absint( $request->get_param( 'id' ) );
		$run    = WP_MCP_AI_Workflow_Run_CPT::get_run( $run_id );

		if ( ! $run ) {
			return new WP_Error(
				'run_not_found',
				__( 'Workflow run not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$cancellable = array( 'pending', 'running' );
		if ( ! in_array( $run['status'], $cancellable, true ) ) {
			return new WP_Error(
				'run_not_cancellable',
				sprintf(
					/* translators: %s: current run status */
					__( 'Cannot cancel a run with status "%s".', 'mcp-ai-wpoos' ),
					esc_html( $run['status'] )
				),
				array( 'status' => 409 )
			);
		}

		WP_MCP_AI_Workflow_Run_CPT::set_status( $run_id, 'cancelled' );
		WP_MCP_AI_Workflow_Run_CPT::append_event(
			$run_id,
			'step_errored',
			'',
			'',
			array( 'reason' => 'cancelled_via_api' )
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'run_id'  => $run_id,
				'status'  => 'cancelled',
				'message' => __( 'Run cancelled.', 'mcp-ai-wpoos' ),
			)
		);
	}

	/**
	 * GET /orchestration/runs/{id}/events — return the event log JSON array.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_events( $request ) {
		$run_id = absint( $request->get_param( 'id' ) );
		$post   = get_post( $run_id );

		if ( ! $post || WP_MCP_AI_Workflow_Run_CPT::CPT !== $post->post_type ) {
			return new WP_Error(
				'run_not_found',
				__( 'Workflow run not found.', 'mcp-ai-wpoos' ),
				array( 'status' => 404 )
			);
		}

		$events = WP_MCP_AI_Workflow_Run_CPT::get_event_log( $run_id );

		return rest_ensure_response( $events );
	}
}
