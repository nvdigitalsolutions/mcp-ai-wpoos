<?php
/**
 * CRM Toolkit Pipeline Stages Registry
 *
 * Manages deal/opportunity pipeline stage definitions — the stages a deal
 * moves through from prospecting to closed-won or closed-lost.
 *
 * Each stage carries:
 *  - label         Human-readable name.
 *  - probability   Win probability at this stage (0–1), used for weighted
 *                  pipeline forecasting.
 *  - is_won        (optional) Flag marking a won stage.
 *  - is_lost       (optional) Flag marking a lost stage.
 *  - order         Sort position in the pipeline.
 *  - color         Hex colour for Kanban visualisation (optional).
 *
 * The default set mirrors Salesforce's standard pipeline.  Partners can
 * extend / override via the wp_mcp_ai_crm_pipeline_stages filter.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
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
 * @since 2.3.0
 */
class WP_MCP_AI_CRM_Pipeline_Stages {

	/**
	 * Default pipeline stage definitions.
	 *
	 * @return array<string,array>
	 */
	public static function defaults() {
		return array(
			'prospecting'         => array(
				'label'       => __( 'Prospecting', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.05,
				'order'       => 10,
				'color'       => '#e3e3e3',
			),
			'qualification'       => array(
				'label'       => __( 'Qualification', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.10,
				'order'       => 20,
				'color'       => '#d1ecf1',
			),
			'needs_analysis'      => array(
				'label'       => __( 'Needs Analysis', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.20,
				'order'       => 30,
				'color'       => '#bee5eb',
			),
			'value_prop'          => array(
				'label'       => __( 'Value Proposition', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.35,
				'order'       => 40,
				'color'       => '#c3e6cb',
			),
			'id_decision_makers'  => array(
				'label'       => __( 'ID Decision Makers', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.45,
				'order'       => 50,
				'color'       => '#d4edda',
			),
			'perception_analysis' => array(
				'label'       => __( 'Perception Analysis', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.55,
				'order'       => 60,
				'color'       => '#fff3cd',
			),
			'proposal'            => array(
				'label'       => __( 'Proposal', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.65,
				'order'       => 70,
				'color'       => '#ffeeba',
			),
			'negotiation'         => array(
				'label'       => __( 'Negotiation', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.80,
				'order'       => 80,
				'color'       => '#f5c6cb',
			),
			'closed_won'          => array(
				'label'       => __( 'Closed Won', 'mcp-ai-wpoos-pro' ),
				'probability' => 1.00,
				'is_won'      => true,
				'order'       => 90,
				'color'       => '#28a745',
			),
			'closed_lost'         => array(
				'label'       => __( 'Closed Lost', 'mcp-ai-wpoos-pro' ),
				'probability' => 0.00,
				'is_lost'     => true,
				'order'       => 100,
				'color'       => '#dc3545',
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
		$filtered = apply_filters( 'wp_mcp_ai_crm_pipeline_stages', $stages );
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
	 * Check whether a stage is a won stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return bool
	 */
	public static function is_won( $stage_id ) {
		$stage = self::get_stage( $stage_id );
		return $stage && ! empty( $stage['is_won'] );
	}

	/**
	 * Check whether a stage is a lost stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return bool
	 */
	public static function is_lost( $stage_id ) {
		$stage = self::get_stage( $stage_id );
		return $stage && ! empty( $stage['is_lost'] );
	}

	/**
	 * Get the win probability for a stage.
	 *
	 * @param string $stage_id Stage slug.
	 * @return float
	 */
	public static function probability( $stage_id ) {
		$stage = self::get_stage( $stage_id );
		return $stage && isset( $stage['probability'] ) ? (float) $stage['probability'] : 0.0;
	}

	/**
	 * Get the default (first non-won/non-lost) stage.
	 *
	 * @return string Stage slug, defaults to 'prospecting'.
	 */
	public static function default_stage() {
		return 'prospecting';
	}

	/**
	 * Get only the open (non-won, non-lost) stages for forms/dropdowns.
	 *
	 * @return array
	 */
	public static function get_open_stages() {
		$stages = self::get_stages();
		return array_filter(
			$stages,
			function ( $stage ) {
				return empty( $stage['is_won'] ) && empty( $stage['is_lost'] );
			}
		);
	}
}
