<?php
/**
 * REST controller for the orchestration stats cluster (Proposal 084 Phase 3).
 *
 * The multi-agent and orchestration admin dashboards are AJAX stats
 * surfaces. This controller serves the same data behind one read-only
 * overview the Complete Console's Orchestration page renders:
 *   GET /orchestration/stats
 *
 * Wraps the dashboards' own collectors (promoted to public — no logic
 * forks):
 *   - multi_agent      WP_MCP_AI_Admin_Multi_Agent_Dashboard::get_agent_statistics()
 *   - orchestration    WP_MCP_AI_Admin_Orchestration_Dashboard::get_orchestration_statistics()
 *                      + get_system_status()
 *   - recent_workflows WP_MCP_AI_Admin_Orchestration_Dashboard::get_recent_workflows()
 *
 * Destructive/write actions (agent re-install, workflow execute/restart,
 * seeders) stay wp-admin for now — tracked follow-up.
 *
 * @package WP_MCP_AI
 * @since   2.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestration stats REST controller.
 */
class WP_MCP_AI_REST_Orchestration_Stats_Controller {

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
	const ROUTE_BASE = '/orchestration';

	/**
	 * Register the orchestration stats route.
	 *
	 * @since 2.4.0
	 * @return void
	 */
	public function register_routes() {
		register_rest_route(
			self::REST_NAMESPACE,
			self::ROUTE_BASE . '/stats',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'get_stats' ),
					'permission_callback' => array( $this, 'permissions_check' ),
				),
			)
		);
	}

	/**
	 * Permission check — stats are administrator-only.
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
	 * GET /orchestration/stats — the consolidated stats payload.
	 *
	 * @since 2.4.0
	 * @return WP_REST_Response
	 */
	public function get_stats() {
		return rest_ensure_response(
			array(
				'success' => true,
				'data'    => array(
					'multi_agent'      => $this->collect_multi_agent(),
					'orchestration'    => $this->collect_orchestration(),
					'recent_workflows' => $this->collect_recent_workflows(),
				),
			)
		);
	}

	/**
	 * Multi-agent dashboard stats, reshaped for the SPA.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_multi_agent() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Multi_Agent_Dashboard' ) ) {
			return array( 'installed' => false );
		}

		// The collectors are stateless; a fresh instance is inert on REST
		// requests (its constructor hooks only fire on admin/ajax paths).
		$dashboard = new WP_MCP_AI_Admin_Multi_Agent_Dashboard();
		$stats     = $dashboard->get_agent_statistics();

		if ( ! is_array( $stats ) ) {
			return array( 'installed' => false );
		}

		$agents = array();
		if ( ! empty( $stats['agents'] ) && is_array( $stats['agents'] ) ) {
			foreach ( $stats['agents'] as $agent ) {
				$tools = isset( $agent['tools'] ) && is_array( $agent['tools'] )
					? array_map( 'sanitize_key', $agent['tools'] )
					: array();
				$roles = isset( $agent['primary_roles'] ) && is_array( $agent['primary_roles'] )
					? array_map( 'sanitize_text_field', $agent['primary_roles'] )
					: array();

				$agents[] = array(
					'id'               => isset( $agent['id'] ) ? absint( $agent['id'] ) : 0,
					'title'            => isset( $agent['title'] ) ? sanitize_text_field( $agent['title'] ) : '',
					'slug'             => isset( $agent['slug'] ) ? sanitize_key( $agent['slug'] ) : '',
					'status'           => isset( $agent['status'] ) ? sanitize_key( $agent['status'] ) : '',
					'description'      => isset( $agent['description'] ) ? wp_strip_all_tags( (string) $agent['description'] ) : '',
					'provider'         => isset( $agent['provider'] ) ? sanitize_text_field( (string) $agent['provider'] ) : '',
					'model'            => isset( $agent['model'] ) ? sanitize_text_field( (string) $agent['model'] ) : '',
					'tools'            => $tools,
					'tool_count'       => isset( $agent['tool_count'] ) ? absint( $agent['tool_count'] ) : count( $tools ),
					'primary_roles'    => $roles,
					'last_used'        => isset( $agent['last_used'] ) ? sanitize_text_field( (string) $agent['last_used'] ) : '',
					'workflow_pattern' => isset( $agent['workflow_pattern'] ) ? sanitize_key( $agent['workflow_pattern'] ) : '',
				);
			}
		}

		return array(
			'installed'     => ! empty( $stats['installed'] ),
			'total_agents'  => isset( $stats['total_agents'] ) ? absint( $stats['total_agents'] ) : 0,
			'active_agents' => isset( $stats['active_agents'] ) ? absint( $stats['active_agents'] ) : 0,
			'total_tools'   => isset( $stats['total_tools'] ) ? absint( $stats['total_tools'] ) : 0,
			'is_pro_active' => ! empty( $stats['is_pro_active'] ),
			'patterns'      => $this->sanitize_deep( isset( $stats['patterns'] ) ? $stats['patterns'] : array() ),
			'agents'        => $agents,
		);
	}

	/**
	 * Orchestration dashboard stats + system status.
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_orchestration() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			return array();
		}

		$dashboard = new WP_MCP_AI_Admin_Orchestration_Dashboard();
		$stats     = $dashboard->get_orchestration_statistics();

		if ( ! is_array( $stats ) ) {
			return array();
		}

		$roles = array();
		if ( isset( $stats['roles'] ) && is_array( $stats['roles'] ) ) {
			foreach ( $stats['roles'] as $role => $count ) {
				$roles[ sanitize_key( $role ) ] = absint( $count );
			}
		}

		return array(
			'total_professions'  => isset( $stats['total_professions'] ) ? absint( $stats['total_professions'] ) : 0,
			'seeded_professions' => isset( $stats['seeded_professions'] ) ? absint( $stats['seeded_professions'] ) : 0,
			'with_task_patterns' => isset( $stats['with_task_patterns'] ) ? absint( $stats['with_task_patterns'] ) : 0,
			'seeder_version'     => isset( $stats['seeder_version'] ) ? sanitize_text_field( (string) $stats['seeder_version'] ) : '',
			'roles'              => $roles,
			'system_status'      => $this->sanitize_deep( $dashboard->get_system_status() ),
		);
	}

	/**
	 * Recent workflow rows (the dashboard's 5-minute transient cache).
	 *
	 * @since 2.4.0
	 * @return array
	 */
	private function collect_recent_workflows() {
		if ( ! class_exists( 'WP_MCP_AI_Admin_Orchestration_Dashboard' ) ) {
			return array();
		}

		$dashboard = new WP_MCP_AI_Admin_Orchestration_Dashboard();
		$rows      = $dashboard->get_recent_workflows();

		if ( ! is_array( $rows ) ) {
			return array();
		}

		$workflows = array();
		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			$workflows[] = array(
				'workflow_id'  => isset( $row['workflow_id'] ) ? sanitize_text_field( (string) $row['workflow_id'] ) : '',
				'state'        => isset( $row['state'] ) ? sanitize_key( $row['state'] ) : 'unknown',
				'tasks_total'  => isset( $row['tasks_total'] ) ? absint( $row['tasks_total'] ) : 0,
				'tasks_done'   => isset( $row['tasks_done'] ) ? absint( $row['tasks_done'] ) : 0,
				'created_at'   => isset( $row['created_at'] ) ? sanitize_text_field( (string) $row['created_at'] ) : '',
				'updated_at'   => isset( $row['updated_at'] ) ? sanitize_text_field( (string) $row['updated_at'] ) : '',
				'started_at'   => isset( $row['started_at'] ) ? sanitize_text_field( (string) $row['started_at'] ) : '',
				'completed_at' => isset( $row['completed_at'] ) ? sanitize_text_field( (string) $row['completed_at'] ) : '',
				'team_id'      => isset( $row['team_id'] ) ? absint( $row['team_id'] ) : 0,
				'task_type'    => isset( $row['task_type'] ) ? sanitize_key( $row['task_type'] ) : 'generic',
			);
		}

		return $workflows;
	}

	/**
	 * Recursive sanitizer for open shapes (strings only — scalars pass).
	 *
	 * @since 2.4.0
	 * @param mixed $value Value to sanitize.
	 * @return mixed
	 */
	private function sanitize_deep( $value ) {
		if ( is_array( $value ) ) {
			$clean = array();
			foreach ( $value as $key => $item ) {
				$clean[ sanitize_key( $key ) ] = $this->sanitize_deep( $item );
			}
			return $clean;
		}

		if ( is_string( $value ) ) {
			return sanitize_text_field( $value );
		}

		return $value;
	}
}
