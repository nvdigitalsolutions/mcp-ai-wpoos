<?php
/**
 * vitals/class-wp-mcp-ai-tool-analyze-vital-trends.php (ecosystem port — Wave F4, healthcare vitals batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/healthcare/vitals/class-wp-mcp-ai-tool-analyze-vital-trends.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps where the source references the Pro addon path.
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
 * Analyze Vital Trends tool.
 */
class WP_MCP_AI_Tool_Analyze_Vital_Trends implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( class_exists( 'WP_MCP_AI_Healthcare_Engine' ) ) {
			return WP_MCP_AI_Healthcare_Engine::is_subtoolkit_enabled( 'vitals' );
		}
		return false;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'analyze_vital_trends';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Analyze Vital Trends', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Compute trend statistics (min/max/mean and direction) over a member\'s recent vital-sign readings.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member post ID.', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'days_back' => array(
					'type'        => 'integer',
					'description' => __( 'How many days of history to analyse (default 30).', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
					'maximum'     => 3650,
					'default'     => 30,
				),
			),
			'required'   => array( 'member_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'pii-data', 'cacheable' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return mixed|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view vital-sign data.', 'nvoos-content-graph-pro' ) );
		}

		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		if ( $member_id <= 0 ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'A valid member_id is required.', 'nvoos-content-graph-pro' ) );
		}
		$days_back = isset( $arguments['days_back'] ) ? max( 1, absint( $arguments['days_back'] ) ) : 30;

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'read',
				'vital_log',
				$member_id,
				array(
					'user_id' => $user_id,
					'tool'    => $this->get_slug(),
				)
			);
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Log_Vital_Signs' ) ) {
			return new WP_Error( 'wp_mcp_ai_unavailable', __( 'log_vital_signs tool is not available.', 'nvoos-content-graph-pro' ) );
		}
		$delegate = new WP_MCP_AI_Tool_Log_Vital_Signs();
		return $delegate->execute(
			array(
				'action'    => 'analyze_trends',
				'member_id' => $member_id,
				'days_back' => $days_back,
			),
			$context
		);
	}
}
