<?php
/**
 * Statute of Limitations Calculator Tool
 *
 * Calculates statute of limitations deadlines based on claim type, jurisdiction, and incident date.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

/**
 * Calculates statute of limitations deadlines.
 */
class WP_MCP_AI_Tool_LF_Statute_Of_Limitations_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

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
	public static function is_available(): bool {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_law_firm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'lf_statute_of_limitations_calculator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Statute of Limitations Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Calculates statute of limitations deadlines based on claim type, incident date, state jurisdiction, discovery date, and tolling factors such as minority or defendant absence.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'claim_type'            => array(
					'type'        => 'string',
					'description' => __( 'Type of legal claim.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'personal_injury',
						'medical_malpractice',
						'breach_of_contract',
						'property_damage',
						'fraud',
						'employment',
						'products_liability',
						'wrongful_death',
						'defamation',
						'professional_negligence',
					),
				),
				'incident_date'         => array(
					'type'        => 'string',
					'description' => __( 'Date of the incident (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'state'                 => array(
					'type'        => 'string',
					'description' => __( 'State jurisdiction (e.g., CA, NY, TX).', 'mcp-ai-wpoos-pro' ),
				),
				'discovery_date'        => array(
					'type'        => 'string',
					'description' => __( 'Date the injury was discovered, if different from incident date (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'plaintiff_minor'       => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the plaintiff was a minor at the time of incident.', 'mcp-ai-wpoos-pro' ),
				),
				'defendant_absent_days' => array(
					'type'        => 'integer',
					'description' => __( 'Number of days the defendant was absent from the jurisdiction (tolling).', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'claim_type', 'incident_date', 'state' ),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$claim_type    = isset( $arguments['claim_type'] ) ? sanitize_text_field( $arguments['claim_type'] ) : '';
		$incident_date = isset( $arguments['incident_date'] ) ? sanitize_text_field( $arguments['incident_date'] ) : '';
		$state         = isset( $arguments['state'] ) ? sanitize_text_field( strtoupper( $arguments['state'] ) ) : '';
		$discovery     = isset( $arguments['discovery_date'] ) ? sanitize_text_field( $arguments['discovery_date'] ) : '';
		$minor         = ! empty( $arguments['plaintiff_minor'] );
		$absent_days   = isset( $arguments['defendant_absent_days'] ) ? absint( $arguments['defendant_absent_days'] ) : 0;

		if ( empty( $claim_type ) || empty( $incident_date ) || empty( $state ) ) {
			return new WP_Error( 'missing_required', __( 'Claim type, incident date, and state are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Statute periods in years by claim type (general defaults).
		$statute_periods = array(
			'personal_injury'         => 2,
			'medical_malpractice'     => 2,
			'breach_of_contract'      => 4,
			'property_damage'         => 3,
			'fraud'                   => 3,
			'employment'              => 2,
			'products_liability'      => 2,
			'wrongful_death'          => 2,
			'defamation'              => 1,
			'professional_negligence' => 2,
		);

		$years = $statute_periods[ $claim_type ] ?? 2;

		// Use discovery date if provided.
		$start_date = $discovery ? $discovery : $incident_date;

		// Calculate tolling days.
		$tolling_days = $absent_days;
		if ( $minor ) {
			// Generally toll until age 18 — approximate with 365 days for simplified calculation.
			$tolling_days += 365;
		}

		$result = WP_MCP_AI_Law_Firm_Calculator::calculate_statute_of_limitations(
			$start_date,
			$years,
			$tolling_days,
			$state
		);

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: expiration date, 2: days remaining */
				__( 'Statute of limitations expires on %1$s (%2$d days remaining). ', 'mcp-ai-wpoos-pro' ),
				$result['expiration_date'],
				$result['days_remaining']
			) . self::DISCLAIMER,
			'data'       => array(
				'expiration_date'    => $result['expiration_date'],
				'days_remaining'     => $result['days_remaining'],
				'is_expired'         => $result['is_expired'],
				'warning_level'      => $result['warning_level'],
				'applicable_statute' => sprintf(
					/* translators: 1: years, 2: claim type */
					__( '%1$d-year statute for %2$s claims', 'mcp-ai-wpoos-pro' ),
					$years,
					str_replace( '_', ' ', $claim_type )
				),
				'claim_type'         => $claim_type,
				'state'              => $state,
				'start_date'         => $start_date,
				'tolling_applied'    => $tolling_days > 0,
				'tolling_days'       => $tolling_days,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
