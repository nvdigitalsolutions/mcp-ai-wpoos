<?php
/**
 * WP_MCP_AI_Tool_Generate_Pipeline_Report (ecosystem port - Wave F2, regulatory-registration tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/regulatory-registration/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger seams resolve from the addon's D8-compat `src/` copies (the monorepo root classmap serves the base copies monolith).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


/**
 * Generates pipeline analytics reports.
 */
class WP_MCP_AI_Tool_Generate_Pipeline_Report implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_pipeline_report';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Pipeline Report', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates registration pipeline analytics report showing progress, bottlenecks, stage distribution, and workflow efficiency metrics.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'grouping'            => array(
					'type'        => 'string',
					'description' => __( 'Group results by (optional, default: "status")', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'status', 'country', 'product', 'month' ),
					'default'     => 'status',
				),
				'include_trends'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include historical trends (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_bottlenecks' => array(
					'type'        => 'boolean',
					'description' => __( 'Identify workflow bottlenecks (optional, default: true)', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro-tier tool.
			'database-read',        // Reads from database.
			'read-only',            // Does not modify state.
			'cacheable',            // Results can be cached.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_regulatory_registration_toolkit'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate pipeline reports.', 'nvoos-content-graph-pro' ) );
		}

		$grouping            = ! empty( $arguments['grouping'] ) ? sanitize_text_field( $arguments['grouping'] ) : 'status';
		$include_trends      = isset( $arguments['include_trends'] ) ? (bool) $arguments['include_trends'] : true;
		$include_bottlenecks = isset( $arguments['include_bottlenecks'] ) ? (bool) $arguments['include_bottlenecks'] : true;

		// Get all registrations.
		$registrations_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_registration',
				'post_status'    => 'publish',
				'posts_per_page' => class_exists( 'WP_MCP_AI_Tool_Artifact_Helper' ) ? WP_MCP_AI_Tool_Artifact_Helper::resolve_max_items( 'generate_pipeline_report', 0, 1000 ) : 1000,
			)
		);

		$pipeline_data = array();
		$stage_times   = array();

		if ( $registrations_query->have_posts() ) {
			foreach ( $registrations_query->posts as $post ) {
				// Get grouping key.
				$group_key = '';
				switch ( $grouping ) {
					case 'status':
						$statuses  = wp_get_post_terms( $post->ID, 'mcp_ai_reg_status' );
						$group_key = ! empty( $statuses ) && ! is_wp_error( $statuses ) ? $statuses[0]->name : 'Unknown';
						break;
					case 'country':
						$group_key = get_post_meta( $post->ID, 'country', true ) ? get_post_meta( $post->ID, 'country', true ) : 'Unknown';
						break;
					case 'product':
						$product_id = get_post_meta( $post->ID, 'product_id', true );
						if ( $product_id ) {
							$product   = get_post( $product_id );
							$group_key = $product ? $product->post_title : 'Unknown';
						} else {
							$group_key = 'Unknown';
						}
						break;
					case 'month':
						$group_key = gmdate( 'Y-m', strtotime( $post->post_date ) );
						break;
				}

				if ( ! isset( $pipeline_data[ $group_key ] ) ) {
					$pipeline_data[ $group_key ] = array(
						'count'         => 0,
						'registrations' => array(),
					);
				}

				++$pipeline_data[ $group_key ]['count'];
				$pipeline_data[ $group_key ]['registrations'][] = $post->ID;

				// Calculate stage time.
				$submission_date = get_post_meta( $post->ID, 'submission_date', true );
				$approval_date   = get_post_meta( $post->ID, 'approval_date', true );

				if ( $submission_date ) {
					$submission_time = strtotime( $submission_date );
					$current_time    = $approval_date ? strtotime( $approval_date ) : time();
					$days_in_stage   = floor( ( $current_time - $submission_time ) / DAY_IN_SECONDS );

					$statuses = wp_get_post_terms( $post->ID, 'mcp_ai_reg_status' );
					if ( ! empty( $statuses ) && ! is_wp_error( $statuses ) ) {
						$status = $statuses[0]->name;
						if ( ! isset( $stage_times[ $status ] ) ) {
							$stage_times[ $status ] = array();
						}
						$stage_times[ $status ][] = $days_in_stage;
					}
				}
			}
		}

		// Calculate average stage times.
		$average_stage_times = array();
		foreach ( $stage_times as $status => $times ) {
			$average_stage_times[ $status ] = round( array_sum( $times ) / count( $times ), 1 );
		}

		// Identify bottlenecks.
		$bottlenecks = array();
		if ( $include_bottlenecks ) {
			arsort( $average_stage_times );
			$bottlenecks = array_slice( $average_stage_times, 0, 3, true );
		}

		// Generate trends if requested.
		$trends = array();
		if ( $include_trends ) {
			// Last 6 months trend.
			for ( $i = 5; $i >= 0; $i-- ) {
				$month = gmdate( 'Y-m', strtotime( "-{$i} months" ) );
				$count = 0;

				foreach ( $registrations_query->posts as $post ) {
					if ( gmdate( 'Y-m', strtotime( $post->post_date ) ) === $month ) {
						++$count;
					}
				}

				$trends[ $month ] = $count;
			}
		}

		return array(
			'success'             => true,
			'report_type'         => 'pipeline',
			'generated_at'        => current_time( 'mysql' ),
			'grouping'            => $grouping,
			'total_registrations' => $registrations_query->found_posts,
			'pipeline'            => $pipeline_data,
			'average_stage_times' => $average_stage_times,
			'bottlenecks'         => $bottlenecks,
			'trends'              => $trends,
		);
	}
}
