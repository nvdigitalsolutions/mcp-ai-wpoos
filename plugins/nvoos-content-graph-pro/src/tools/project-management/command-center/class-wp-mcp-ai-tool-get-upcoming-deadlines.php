<?php
/**
 * PM tool (ecosystem port — Wave F2, PM command-center + workflow batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/project-management/command-center/class-wp-mcp-ai-tool-get-upcoming-deadlines.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants — no path swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns upcoming deadlines across tasks and events.
 */
class WP_MCP_AI_Tool_Get_Upcoming_Deadlines implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_upcoming_deadlines';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Upcoming Deadlines', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns upcoming deadlines including both tasks and events within a configurable number of days (default: 7). Each deadline includes title, type (task or event), due date, priority, days until due, and associated project information for tasks.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'days'  => array(
					'type'        => 'integer',
					'description' => __( 'Number of days to look ahead (default: 7, max: 90)', 'nvoos-content-graph-pro' ),
					'default'     => 7,
					'minimum'     => 1,
					'maximum'     => 90,
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of deadlines to return (default: 20, max: 100)', 'nvoos-content-graph-pro' ),
					'default'     => 20,
					'minimum'     => 1,
					'maximum'     => 100,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'project_management',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'project_manager', 'team_lead', 'developer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read-only',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Project management is a Pro feature.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_project_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view deadlines.', 'nvoos-content-graph-pro' ) );
		}

		// Ensure PM Engine is available.
		if ( ! class_exists( 'WP_MCP_AI_PM_Engine' ) ) {
			return new WP_Error( 'wp_mcp_ai_engine_missing', __( 'Project Management Engine is not available.', 'nvoos-content-graph-pro' ) );
		}

		$days  = isset( $arguments['days'] ) ? min( absint( $arguments['days'] ), 90 ) : 7;
		$limit = isset( $arguments['limit'] ) ? min( absint( $arguments['limit'] ), 100 ) : 20;

		$deadlines = WP_MCP_AI_PM_Engine::get_upcoming_deadlines( $days, $limit );

		// Enrich task deadlines with additional project and priority info.
		$enriched = array();
		foreach ( $deadlines as $item ) {
			$entry = array(
				'id'         => isset( $item['id'] ) ? absint( $item['id'] ) : 0,
				'title'      => isset( $item['title'] ) ? $item['title'] : '',
				'type'       => isset( $item['type'] ) ? $item['type'] : 'task',
				'due_date'   => isset( $item['due_date'] ) ? $item['due_date'] : '',
				'days_until' => isset( $item['days_until'] ) ? (int) $item['days_until'] : 0,
			);

			if ( 'task' === $entry['type'] ) {
				$entry['priority']      = isset( $item['priority'] ) ? $item['priority'] : 'medium';
				$entry['status']        = isset( $item['status'] ) ? $item['status'] : 'todo';
				$entry['project_id']    = isset( $item['project_id'] ) ? absint( $item['project_id'] ) : null;
				$entry['project_title'] = isset( $item['project_title'] ) ? $item['project_title'] : '';
			} else {
				$entry['time'] = isset( $item['time'] ) ? $item['time'] : '';
			}

			$enriched[] = $entry;
		}

		// Categorize as overdue, today, this week, or later.
		$now       = time();
		$overdue   = array();
		$today     = array();
		$this_week = array();
		$later     = array();
		$week_end  = strtotime( 'sunday this week 23:59:59' );

		foreach ( $enriched as $item ) {
			$due_ts = ! empty( $item['due_date'] ) ? strtotime( $item['due_date'] ) : 0;
			if ( ! $due_ts ) {
				$later[] = $item;
				continue;
			}

			$item['due_timestamp'] = $due_ts;

			if ( $due_ts < strtotime( 'today' ) ) {
				$overdue[] = $item;
			} elseif ( $due_ts < strtotime( 'tomorrow' ) ) {
				$today[] = $item;
			} elseif ( $due_ts <= $week_end ) {
				$this_week[] = $item;
			} else {
				$later[] = $item;
			}
		}

		return array(
			'success'   => true,
			'count'     => count( $enriched ),
			'days'      => $days,
			'overdue'   => $overdue,
			'today'     => $today,
			'this_week' => $this_week,
			'later'     => $later,
			'deadlines' => $enriched,
		);
	}
}
