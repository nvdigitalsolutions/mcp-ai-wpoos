<?php
/**
 * REST controller for the operations cluster (Proposal 084 Phase 3).
 *
 * Serves the read-mostly operational surfaces the Complete Console's
 * Operations page replaces from wp-admin:
 *   GET    /operations/cron            — tracked plugin cron jobs
 *   DELETE /operations/cron/{job_id}   — remove + unschedule a job
 *   GET    /operations/dlq             — dead-letter queue items
 *   POST   /operations/dlq/{id}/retry  — reprocess a dead letter
 *   POST   /operations/dlq/{id}/dismiss— mark a dead letter dismissed
 *
 * Every route wraps the existing service classes (WP_MCP_AI_Cron_Manager,
 * WP_MCP_AI_Dead_Letter_Queue) — no logic forks. All routes require
 * manage_options.
 *
 * @package WP_MCP_AI
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Operations REST controller.
 */
class WP_MCP_AI_REST_Operations_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/operations';

	/**
	 * Register the operations routes.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/cron',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_cron_jobs' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/cron/(?P<job_id>[\w.-]+)',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_cron_job' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'job_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/dlq',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_dlq_items' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'dismissed' => array(
							'type'              => 'boolean',
							'required'          => false,
							'default'           => false,
							'sanitize_callback' => 'rest_sanitize_boolean',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/dlq/(?P<id>[\w.-]+)/retry',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'retry_dlq_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/dlq/(?P<id>[\w.-]+)/dismiss',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'dismiss_dlq_item' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/timeline',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_timeline' ),
					'permission_callback' => array( $this, 'permissions_check' ),
					'args'                => array(
						'page'         => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 1,
							'sanitize_callback' => 'absint',
						),
						'assistant_id' => array(
							'type'              => 'integer',
							'required'          => false,
							'default'           => 0,
							'sanitize_callback' => 'absint',
						),
					),
				),
			)
		);
	}

	/**
	 * Permission check — operations surfaces are administrator-only.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * GET /operations/cron — tracked plugin cron jobs.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_cron_jobs() {
		$jobs = array();

		if ( class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			foreach ( WP_MCP_AI_Cron_Manager::get_jobs() as $job_id => $job ) {
				$jobs[] = array_merge(
					array( 'job_id' => (string) $job_id ),
					is_array( $job ) ? $job : array()
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'jobs'  => $jobs,
					'total' => count( $jobs ),
				),
			)
		);
	}

	/**
	 * DELETE /operations/cron/{job_id} — remove and unschedule a job.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_cron_job( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
			return new WP_Error( 'cron_manager_unavailable', __( 'Cron manager is not available.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
		}

		$job_id = (string) $request['job_id'];
		if ( null === WP_MCP_AI_Cron_Manager::get_job( $job_id ) ) {
			return new WP_Error( 'cron_job_not_found', __( 'Cron job not found.', 'mcp-ai-wpoos' ), array( 'status' => 404 ) );
		}

		WP_MCP_AI_Cron_Manager::remove_job( $job_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'deleted' => true,
					'job_id'  => $job_id,
				),
			)
		);
	}

	/**
	 * GET /operations/dlq — dead-letter queue items.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response
	 */
	public function get_dlq_items( WP_REST_Request $request ) {
		$items = array();

		if ( class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			$raw = WP_MCP_AI_Dead_Letter_Queue::get_all(
				array( 'dismissed' => (bool) $request['dismissed'] )
			);
			foreach ( $raw as $item ) {
				if ( ! is_array( $item ) ) {
					continue;
				}
				$items[] = array(
					'item_id'        => isset( $item['item_id'] ) ? (string) $item['item_id'] : ( isset( $item['id'] ) ? (string) $item['id'] : '' ),
					'type'           => isset( $item['type'] ) ? sanitize_key( (string) $item['type'] ) : '',
					'identifier'     => isset( $item['identifier'] ) ? sanitize_text_field( (string) $item['identifier'] ) : '',
					'failure_reason' => isset( $item['failure_reason'] ) ? sanitize_textarea_field( (string) $item['failure_reason'] ) : '',
					'dismissed'      => ! empty( $item['dismissed'] ),
					'created_at'     => isset( $item['created_at'] ) ? (string) $item['created_at'] : '',
				);
			}
		}

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'items' => $items,
					'total' => count( $items ),
				),
			)
		);
	}

	/**
	 * POST /operations/dlq/{id}/retry — reprocess a dead letter.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function retry_dlq_item( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			return new WP_Error( 'dlq_unavailable', __( 'Dead-letter queue is not available.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
		}

		$result = WP_MCP_AI_Dead_Letter_Queue::retry( (string) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => (bool) $result,
				'data'    => array( 'item_id' => (string) $request['id'] ),
			)
		);
	}

	/**
	 * POST /operations/dlq/{id}/dismiss — mark a dead letter dismissed.
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function dismiss_dlq_item( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ) {
			return new WP_Error( 'dlq_unavailable', __( 'Dead-letter queue is not available.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
		}

		$result = WP_MCP_AI_Dead_Letter_Queue::dismiss( (string) $request['id'] );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return rest_ensure_response(
			array(
				'success' => (bool) $result,
				'data'    => array( 'item_id' => (string) $request['id'] ),
			)
		);
	}

	/**
	 * GET /operations/timeline — recent run summaries (the wp-admin Run
	 * Timeline page's own summarizer, now exposed statically).
	 *
	 * @since 2.4.0
	 *
	 * @param WP_REST_Request $request REST request.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_timeline( WP_REST_Request $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Run_Timeline' ) ) {
			$timeline_file = WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-run-timeline.php';
			if ( file_exists( $timeline_file ) ) {
				require_once $timeline_file;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Admin_Run_Timeline' ) || ! method_exists( 'WP_MCP_AI_Admin_Run_Timeline', 'load_run_summaries' ) ) {
			return new WP_Error( 'timeline_unavailable', __( 'Run timeline is not available.', 'mcp-ai-wpoos' ), array( 'status' => 500 ) );
		}

		$summaries = WP_MCP_AI_Admin_Run_Timeline::load_run_summaries(
			max( 1, (int) $request['page'] ),
			(int) $request['assistant_id']
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $summaries,
			)
		);
	}
}
