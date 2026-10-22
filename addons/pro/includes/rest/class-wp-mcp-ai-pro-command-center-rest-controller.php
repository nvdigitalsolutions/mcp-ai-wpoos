<?php
/**
 * REST controller for the Pro Agent Command Center overview (Proposal 084
 * Phase 5d, gap P-agent-command-center).
 *
 * One read-only overview the Complete Console Dashboard consumes:
 *   GET /mcp-ai-pro/v1/command-center/overview
 *
 * Reuses the page class's promoted static collectors
 * (WP_MCP_AI_Pro_Agent_Command_Center::get_session_overview /
 * get_all_assistants / get_agent_status / get_agent_last_active /
 * get_pending_approval_count / get_tokens_today / get_system_uptime_pct /
 * get_recent_activity_events) — the same data the wp-admin overview tab
 * renders, no logic forks. Write actions (approval handling, restriction
 * lift) stay on their existing ported surfaces (Approvals / Token Manager).
 *
 * @package WP_MCP_AI_Pro
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Command center overview REST controller.
 */
class WP_MCP_AI_Pro_Command_Center_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	const REST_NAMESPACE = 'mcp-ai-pro/v1';

	/**
	 * Route base.
	 *
	 * @var string
	 */
	const ROUTE_BASE = '/command-center';

	/**
	 * Boot the controller (module-registry pattern).
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public static function init() {
		$controller = new self();
		add_action( 'rest_api_init', array( $controller, 'register_routes' ) );
	}

	/**
	 * Register the overview route.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/overview',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_overview' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check — mirrors the wp-admin page's manage_options gate.
	 *
	 * @since 2.4.0
	 * @return bool|WP_Error
	 */
	public function permissions_check() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'Administrator access required.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}
		return true;
	}

	/**
	 * The dashboard overview: KPIs, agent statuses, and recent activity.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_overview() {
		$class = 'WP_MCP_AI_Pro_Agent_Command_Center';
		if ( ! class_exists( $class ) ) {
			return rest_ensure_response(
				array(
					'success' => false,
					'data'    => array(
						'message' => __( 'Command Center unavailable.', 'mcp-ai-wpoos-pro' ),
					),
				)
			);
		}

		$data = $class::with_suppressed_db_errors(
			function () use ( $class ) {
				$session_data   = $class::get_session_overview();
				$assistants     = $class::get_all_assistants();
				$agent_statuses = array();

				foreach ( $assistants as $assistant ) {
					$agent_id                = isset( $assistant['id'] ) ? absint( $assistant['id'] ) : 0;
					$agent_statuses[ $agent_id ] = array(
						'status'      => sanitize_key( $class::get_agent_status( $agent_id ) ),
						'last_active' => $class::get_agent_last_active( $agent_id ) ? (int) $class::get_agent_last_active( $agent_id ) : 0,
					);
				}

				$restriction_count = 0;
				if ( class_exists( 'WP_MCP_AI_Restriction_Registry' ) ) {
					$restriction_count = (int) WP_MCP_AI_Restriction_Registry::count_active_users();
				}

				$pending_approvals = (int) $class::get_pending_approval_count();
				$tokens_today      = (int) $class::get_tokens_today();
				$uptime            = (float) $class::get_system_uptime_pct();
				$recent_events     = $class::get_recent_activity_events( 8 );

				return array(
					'kpis'           => array(
						'total_agents'      => count( $assistants ),
						'agents_online'     => (int) $session_data['active_count'],
						'active_tasks'      => (int) $session_data['task_count'],
						'pending_approvals' => $pending_approvals,
						'tokens_today'      => $tokens_today,
						'uptime'            => $uptime,
					),
					'restriction_count' => $restriction_count,
					'agent_statuses'    => $agent_statuses,
					'recent_events'     => array_values( $recent_events ),
				);
			}
		);

		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => $data,
			)
		);
	}
}
