<?php
/**
 * Fee Calculator Tool
 *
 * Calculates legal fees using various billing models.
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

/**
 * Calculates fees for hourly, contingency, flat, blended, and lodestar models.
 */
class WP_MCP_AI_Tool_LF_Fee_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	const DISCLAIMER = 'This is not legal advice. Consult a licensed attorney for specific legal matters.';

	/**
	 * Check if tool is available.
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
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason(): string {
		return __( 'Law Firm toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lf_fee_calculator'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Fee Calculator', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Calculates legal fees using hourly, contingency, flat fee, blended rate, or lodestar methods.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'fee_type'          => array(
					'type'        => 'string',
					'description' => __( 'Fee calculation method.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'hourly', 'contingency', 'flat_fee', 'blended', 'lodestar' ),
				),
				'hours'             => array(
					'type'        => 'number',
					'description' => __( 'Hours worked.', 'mcp-ai-wpoos-pro' ),
				),
				'rate'              => array(
					'type'        => 'number',
					'description' => __( 'Hourly rate.', 'mcp-ai-wpoos-pro' ),
				),
				'recovery_amount'   => array(
					'type'        => 'number',
					'description' => __( 'Recovery amount (contingency).', 'mcp-ai-wpoos-pro' ),
				),
				'contingency_stage' => array(
					'type'        => 'string',
					'description' => __( 'Stage for contingency rate.', 'mcp-ai-wpoos-pro' ),
				),
				'attorneys'         => array(
					'type'        => 'array',
					'description' => __( 'Attorney list with hours/rate (blended).', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'multiplier'        => array(
					'type'        => 'number',
					'description' => __( 'Lodestar multiplier.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'fee_type' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'read-only', 'cacheable' ); }

	/**
	 * {@inheritdoc}
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
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$fee_type   = isset( $arguments['fee_type'] ) ? sanitize_text_field( $arguments['fee_type'] ) : '';
		$hours      = isset( $arguments['hours'] ) ? floatval( $arguments['hours'] ) : 0;
		$rate       = isset( $arguments['rate'] ) ? floatval( $arguments['rate'] ) : 0;
		$recovery   = isset( $arguments['recovery_amount'] ) ? floatval( $arguments['recovery_amount'] ) : 0;
		$stage      = isset( $arguments['contingency_stage'] ) ? sanitize_text_field( $arguments['contingency_stage'] ) : 'pre_filing';
		$attorneys  = isset( $arguments['attorneys'] ) && is_array( $arguments['attorneys'] ) ? $arguments['attorneys'] : array();
		$multiplier = isset( $arguments['multiplier'] ) ? floatval( $arguments['multiplier'] ) : 1.0;

		$result = array();

		switch ( $fee_type ) {
			case 'hourly':
				$fee    = WP_MCP_AI_Law_Firm_Calculator::calculate_hourly_fee( $hours, $rate );
				$result = array(
					'fee_amount'          => $fee,
					'calculation_details' => sprintf( '%s hours × $%s/hr', $hours, number_format( $rate, 2 ) ),
				);
				break;

			case 'contingency':
				$calc   = WP_MCP_AI_Law_Firm_Calculator::calculate_contingency_fee( $recovery, 0, $stage );
				$result = array(
					'fee_amount'          => $calc['fee_amount'],
					'client_share'        => $calc['client_share'],
					'calculation_details' => sprintf( '%s × $%s recovery', WP_MCP_AI_Law_Firm_Calculator::format_percentage( $calc['rate'] ), number_format( $recovery, 2 ) ),
				);
				break;

			case 'flat_fee':
				$result = array(
					'fee_amount'          => $rate,
					'calculation_details' => __( 'Flat fee arrangement', 'mcp-ai-wpoos-pro' ),
				);
				break;

			case 'blended':
				$blended     = WP_MCP_AI_Law_Firm_Calculator::calculate_blended_rate( $attorneys );
				$total_hours = 0;
				foreach ( $attorneys as $a ) {
					$total_hours += (float) ( $a['hours'] ?? 0 );
				}
				$fee    = round( $blended * $total_hours, 2 );
				$result = array(
					'fee_amount'          => $fee,
					'blended_rate'        => $blended,
					'total_hours'         => $total_hours,
					'calculation_details' => sprintf( 'Blended rate $%s × %s hours', number_format( $blended, 2 ), $total_hours ),
				);
				break;

			case 'lodestar':
				$fee    = WP_MCP_AI_Law_Firm_Calculator::calculate_lodestar( $hours, $rate, $multiplier );
				$result = array(
					'fee_amount'          => $fee,
					'calculation_details' => sprintf( '%s hours × $%s × %s multiplier', $hours, number_format( $rate, 2 ), $multiplier ),
				);
				break;

			default:
				return new WP_Error( 'invalid_param', __( 'Invalid fee type.', 'mcp-ai-wpoos-pro' ) );
		}

		$result['fee_type'] = $fee_type;

		return array(
			'success'    => true,
			'message'    => sprintf( __( 'Fee calculated: %s. ', 'mcp-ai-wpoos-pro' ), WP_MCP_AI_Law_Firm_Calculator::format_currency( $result['fee_amount'] ) ) . self::DISCLAIMER,
			'data'       => $result,
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
