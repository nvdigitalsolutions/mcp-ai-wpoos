<?php
/**
 * PM Pipeline Stages Map (ecosystem port — Wave F2, PM toolkit data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/project-management/class-wp-mcp-ai-pm-pipeline-stages.php` (or
 * `addons/pro/includes/`) for the standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The
 * base Pro addon owns the class in monolith installs — the addon's
 * autoloader skips its copy when `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`; no path constants — no path swaps.
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
 * Pipeline stages registry.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_PM_Pipeline_Stages {

	/**
	 * Default pipeline stage definitions.
	 *
	 * @return array<string,array>
	 */
	public static function defaults() {
		return array(
			'idea'      => array(
				'label'       => __( 'Idea', 'nvoos-content-graph-pro' ),
				'probability' => 0.10,
				'order'       => 10,
				'color'       => '#e3e3e3',
			),
			'planning'  => array(
				'label'       => __( 'Planning', 'nvoos-content-graph-pro' ),
				'probability' => 0.25,
				'order'       => 20,
				'color'       => '#d1ecf1',
			),
			'active'    => array(
				'label'       => __( 'Active', 'nvoos-content-graph-pro' ),
				'probability' => 0.50,
				'order'       => 30,
				'color'       => '#c3e6cb',
			),
			'at-risk'   => array(
				'label'       => __( 'At Risk', 'nvoos-content-graph-pro' ),
				'probability' => 0.35,
				'order'       => 40,
				'color'       => '#fff3cd',
			),
			'on-hold'   => array(
				'label'       => __( 'On Hold', 'nvoos-content-graph-pro' ),
				'probability' => 0.20,
				'order'       => 50,
				'color'       => '#f5c6cb',
			),
			'completed' => array(
				'label'        => __( 'Completed', 'nvoos-content-graph-pro' ),
				'probability'  => 1.00,
				'is_completed' => true,
				'order'        => 90,
				'color'        => '#28a745',
			),
			'cancelled' => array(
				'label'        => __( 'Cancelled', 'nvoos-content-graph-pro' ),
				'probability'  => 0.00,
				'is_cancelled' => true,
				'order'        => 95,
				'color'        => '#dc3545',
			),
			'archived'  => array(
				'label'       => __( 'Archived', 'nvoos-content-graph-pro' ),
				'probability' => 0.00,
				'is_archived' => true,
				'order'       => 100,
				'color'       => '#6c757d',
			),
		);
	}

	/**
	 * Get all pipeline stages (filterable).
	 *
	 * @return array<string,array>
	 */
	public static function get_stages() {
		$stages = self::defaults();

		/**
		 * Filter the pipeline stage definitions.
		 *
		 * @param array $stages Stage map (stage_id => definition).
		 */
		$filtered = apply_filters( 'wp_mcp_ai_pm_pipeline_stages', $stages );
		$stages   = is_array( $filtered ) ? $filtered : $stages;

		// Sort by order.
		uasort(
			$stages,
			function ( $a, $b ) {
				$order_a = isset( $a['order'] ) ? (int) $a['order'] : 0;
				$order_b = isset( $b['order'] ) ? (int) $b['order'] : 0;
				return $order_a - $order_b;
			}
		);

		return $stages;
	}

	/**
	 * Get a single stage definition.
	 *
	 * @param string $stage_id Stage slug.
	 * @return array|null Definition or null if not found.
	 */
	public static function get_stage( $stage_id ) {
		$stages = self::get_stages();
		return isset( $stages[ sanitize_key( $stage_id ) ] ) ? $stages[ sanitize_key( $stage_id ) ] : null;
	}

	/**
	 * Check whether a stage is valid.
	 *
	 * @param string $stage_id Stage slug.
	 * @return bool
	 */
	public static function is_valid( $stage_id ) {
		return null !== self::get_stage( $stage_id );
	}

	/**
	 * Check whether a stage is a completed stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return bool
	 */
	public static function is_completed( $stage_id ) {
		$stage = self::get_stage( $stage_id );
		return $stage && ! empty( $stage['is_completed'] );
	}

	/**
	 * Check whether a stage is a cancelled stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return bool
	 */
	public static function is_cancelled( $stage_id ) {
		$stage = self::get_stage( $stage_id );
		return $stage && ! empty( $stage['is_cancelled'] );
	}

	/**
	 * Get the completion probability for a stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return float
	 */
	public static function probability( $stage_id ) {
		$stage = self::get_stage( $stage_id );
		return $stage && isset( $stage['probability'] ) ? (float) $stage['probability'] : 0.0;
	}

	/**
	 * Get the default (first open) stage.
	 *
	 * @return string Stage slug, defaults to 'planning'.
	 */
	public static function default_stage() {
		return 'planning';
	}

	/**
	 * Get only the open (non-completed, non-cancelled, non-archived)
	 * stages for forms/dropdowns.
	 *
	 * @return array
	 */
	public static function get_open_stages() {
		$stages = self::get_stages();
		return array_filter(
			$stages,
			function ( $stage ) {
				return empty( $stage['is_completed'] )
					&& empty( $stage['is_cancelled'] )
					&& empty( $stage['is_archived'] );
			}
		);
	}
}
