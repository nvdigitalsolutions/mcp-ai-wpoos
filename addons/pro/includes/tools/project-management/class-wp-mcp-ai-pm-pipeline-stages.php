<?php
/**
 * Project Management Toolkit Pipeline Stages Registry
 *
 * Manages project lifecycle pipeline stage definitions — the stages a
 * project moves through from idea to completion, cancellation, or
 * archival.
 *
 * Each stage carries:
 *  - label         Human-readable name.
 *  - probability   Completion likelihood at this stage (0–1), used for
 *                  weighted portfolio forecasting.
 *  - is_completed  (optional) Flag marking a completed stage.
 *  - is_cancelled  (optional) Flag marking a cancelled stage.
 *  - is_archived   (optional) Flag marking an archived stage.
 *  - order         Sort position in the pipeline.
 *  - color         Hex colour for Kanban visualisation (optional).
 *
 * Partners can extend / override via the wp_mcp_ai_pm_pipeline_stages
 * filter.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.6.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

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
				'label'       => __( 'Idea', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.10,
				'order'       => 10,
				'color'       => '#e3e3e3',
			),
			'planning'  => array(
				'label'       => __( 'Planning', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.25,
				'order'       => 20,
				'color'       => '#d1ecf1',
			),
			'active'    => array(
				'label'       => __( 'Active', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.50,
				'order'       => 30,
				'color'       => '#c3e6cb',
			),
			'at-risk'   => array(
				'label'       => __( 'At Risk', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.35,
				'order'       => 40,
				'color'       => '#fff3cd',
			),
			'on-hold'   => array(
				'label'       => __( 'On Hold', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.20,
				'order'       => 50,
				'color'       => '#f5c6cb',
			),
			'completed' => array(
				'label'        => __( 'Completed', 'mcp-ai-wpoos-pro' ),
				'probability'  => 1.00,
				'is_completed' => true,
				'order'        => 90,
				'color'        => '#28a745',
			),
			'cancelled' => array(
				'label'        => __( 'Cancelled', 'mcp-ai-wpoos-pro' ),
				'probability'  => 0.00,
				'is_cancelled' => true,
				'order'        => 95,
				'color'        => '#dc3545',
			),
			'archived'  => array(
				'label'       => __( 'Archived', 'mcp-ai-wpoos-pro' ),
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
