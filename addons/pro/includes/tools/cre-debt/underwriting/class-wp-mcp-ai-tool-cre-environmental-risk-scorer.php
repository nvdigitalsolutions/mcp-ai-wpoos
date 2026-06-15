<?php
/**
 * CRE Environmental Risk Scorer — Phase I/II, flood, seismic & climate scoring
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-cre-debt-calculator.php';

/**
 * Scores environmental risk on a 0–100 scale from Phase I/II status, flood zone,
 * seismic zone, brownfield status, and climate risk. Returns composite score
 * with risk-level classification and category breakdowns.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_CRE_Environmental_Risk_Scorer implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_cre_debt_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason(): string {
		return __( 'CRE Debt & Securitization toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug(): string {
		return 'cre_environmental_risk_scorer';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name(): string {
		return __( 'CRE Environmental Risk Scorer', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description(): string {
		return __( 'Score environmental risk for a CRE property on a 0–100 scale. Evaluates Phase I/II ESA status, flood zone classification, seismic zone, brownfield status, and climate risk to produce a composite risk score with category breakdowns and risk-level classification.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema(): array {
		return array(
			'type'       => 'object',
			'properties' => array(
				'phase_one_status'   => array(
					'type'        => 'string',
					'description' => __( 'Phase I ESA result: "clean" (no RECs), "rec" (recognized environmental conditions), "recognized" (same as rec).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'clean', 'rec', 'recognized' ),
				),
				'phase_two_required' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether a Phase II ESA is required or was conducted.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'flood_zone'         => array(
					'type'        => 'string',
					'description' => __( 'FEMA flood zone classification.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'X', 'A', 'AE', 'V', 'VE' ),
				),
				'seismic_zone'       => array(
					'type'        => 'integer',
					'description' => __( 'Seismic zone rating 0-4 (0 = minimal, 4 = highest).', 'mcp-ai-wpoos-pro' ),
				),
				'brownfield_status'  => array(
					'type'        => 'string',
					'description' => __( 'Brownfield listing status.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'none', 'listed', 'remediated' ),
					'default'     => 'none',
				),
				'climate_risk_score' => array(
					'type'        => 'integer',
					'description' => __( 'Climate risk score 1-10 (1 = low, 10 = extreme).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'phase_one_status', 'flood_zone', 'seismic_zone', 'climate_risk_score' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ): array|WP_Error {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$phase_one  = sanitize_text_field( $arguments['phase_one_status'] ?? 'clean' );
		$phase_two  = (bool) ( $arguments['phase_two_required'] ?? false );
		$flood_zone = sanitize_text_field( $arguments['flood_zone'] ?? 'X' );
		$seismic    = min( 4, max( 0, (int) ( $arguments['seismic_zone'] ?? 0 ) ) );
		$brownfield = sanitize_text_field( $arguments['brownfield_status'] ?? 'none' );
		$climate    = min( 10, max( 1, (int) ( $arguments['climate_risk_score'] ?? 1 ) ) );

		// Category weights (total = 100).
		$weights = array(
			'contamination' => 30, // Phase I/II + brownfield.
			'flood'         => 25,
			'seismic'       => 20,
			'climate'       => 25,
		);

		// Contamination sub-score (0–1, higher = worse).
		$contam_score = 0.0;
		if ( in_array( $phase_one, array( 'rec', 'recognized' ), true ) ) {
			$contam_score += 0.40;
		}
		if ( $phase_two ) {
			$contam_score += 0.30;
		}
		if ( 'listed' === $brownfield ) {
			$contam_score += 0.30;
		} elseif ( 'remediated' === $brownfield ) {
			$contam_score += 0.10;
		}
		$contam_score = min( 1.0, $contam_score );

		// Flood sub-score (0–1).
		$flood_scores = array(
			'X'  => 0.0,
			'A'  => 0.40,
			'AE' => 0.60,
			'V'  => 0.80,
			'VE' => 1.00,
		);
		$flood_score  = $flood_scores[ $flood_zone ] ?? 0.0;

		// Seismic sub-score (0–1).
		$seismic_score = $seismic / 4.0;

		// Climate sub-score (0–1).
		$climate_score = ( $climate - 1 ) / 9.0;

		// Composite: weighted sum → 0–100.
		$composite = (
			$contam_score * $weights['contamination'] +
			$flood_score * $weights['flood'] +
			$seismic_score * $weights['seismic'] +
			$climate_score * $weights['climate']
		);

		$composite = round( min( 100, max( 0, $composite ) ), 1 );

		// Risk level classification.
		$risk_level = match ( true ) {
			$composite <= 15 => 'Low',
			$composite <= 35 => 'Moderate',
			$composite <= 55 => 'Elevated',
			$composite <= 75 => 'High',
			default          => 'Critical',
		};

		// Recommendations.
		$recommendations = array();
		if ( $contam_score >= 0.40 ) {
			$recommendations[] = __( 'Environmental indemnification or escrow recommended.', 'mcp-ai-wpoos-pro' );
		}
		if ( $phase_two ) {
			$recommendations[] = __( 'Phase II results should be reviewed before loan closing.', 'mcp-ai-wpoos-pro' );
		}
		if ( $flood_score >= 0.40 ) {
			$recommendations[] = __( 'Flood insurance required; verify NFIP compliance.', 'mcp-ai-wpoos-pro' );
		}
		if ( $seismic_score >= 0.50 ) {
			$recommendations[] = __( 'Probable Maximum Loss (PML) seismic report recommended.', 'mcp-ai-wpoos-pro' );
		}
		if ( $climate_score >= 0.50 ) {
			$recommendations[] = __( 'Climate resilience assessment and insurance adequacy review advised.', 'mcp-ai-wpoos-pro' );
		}
		if ( empty( $recommendations ) ) {
			$recommendations[] = __( 'No material environmental concerns identified.', 'mcp-ai-wpoos-pro' );
		}

		return array(
			'success' => true,
			'message' => __( 'Environmental risk scoring complete. ANALYSIS ONLY - Not investment advice.', 'mcp-ai-wpoos-pro' ),
			'data'    => array(
				'composite_score' => $composite,
				'risk_level'      => $risk_level,
				'max_score'       => 100,
				'category_scores' => array(
					array(
						'category'  => __( 'Contamination (Phase I/II & Brownfield)', 'mcp-ai-wpoos-pro' ),
						'raw_score' => round( $contam_score, 2 ),
						'weight'    => $weights['contamination'],
						'weighted'  => round( $contam_score * $weights['contamination'], 1 ),
					),
					array(
						'category'  => __( 'Flood Risk', 'mcp-ai-wpoos-pro' ),
						'raw_score' => round( $flood_score, 2 ),
						'weight'    => $weights['flood'],
						'weighted'  => round( $flood_score * $weights['flood'], 1 ),
					),
					array(
						'category'  => __( 'Seismic Risk', 'mcp-ai-wpoos-pro' ),
						'raw_score' => round( $seismic_score, 2 ),
						'weight'    => $weights['seismic'],
						'weighted'  => round( $seismic_score * $weights['seismic'], 1 ),
					),
					array(
						'category'  => __( 'Climate Risk', 'mcp-ai-wpoos-pro' ),
						'raw_score' => round( $climate_score, 2 ),
						'weight'    => $weights['climate'],
						'weighted'  => round( $climate_score * $weights['climate'], 1 ),
					),
				),
				'inputs'          => array(
					'phase_one_status'   => $phase_one,
					'phase_two_required' => $phase_two,
					'flood_zone'         => $flood_zone,
					'seismic_zone'       => $seismic,
					'brownfield_status'  => $brownfield,
					'climate_risk_score' => $climate,
				),
				'recommendations' => $recommendations,
			),
		);
	}
}
