<?php
/**
 * Time Entry Recorder Tool
 *
 * Records billable time entries for legal matters with UTBMS validation.
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
 * Records and validates time entries for legal billing.
 */
class WP_MCP_AI_Tool_LF_Time_Entry_Recorder implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_time_entry_recorder'; }
	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Time Entry Recorder', 'mcp-ai-wpoos-pro' ); }
	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Records billable time entries for legal matters with UTBMS code validation and block billing detection.', 'mcp-ai-wpoos-pro' ); }


	/**

	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'matter_id'    => array(
					'type'        => 'integer',
					'description' => __( 'Matter ID.', 'mcp-ai-wpoos-pro' ),
				),
				'hours'        => array(
					'type'        => 'number',
					'description' => __( 'Hours worked.', 'mcp-ai-wpoos-pro' ),
				),
				'rate'         => array(
					'type'        => 'number',
					'description' => __( 'Hourly rate in dollars.', 'mcp-ai-wpoos-pro' ),
				),
				'description'  => array(
					'type'        => 'string',
					'description' => __( 'Description of work performed.', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
				),
				'date'         => array(
					'type'        => 'string',
					'description' => __( 'Date of work (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'utbms_code'   => array(
					'type'        => 'string',
					'description' => __( 'UTBMS task code (e.g., L110).', 'mcp-ai-wpoos-pro' ),
				),
				'billing_type' => array(
					'type'        => 'string',
					'description' => __( 'Billing classification.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'billable', 'non_billable', 'pro_bono', 'contingent', 'flat_fee' ),
				),
			),
			'required'   => array( 'matter_id', 'hours', 'description' ),
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags(): array {
		return array( 'pro', 'write', 'state-changing' ); }

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$uid = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		require_once dirname( __DIR__ ) . '/class-wp-mcp-ai-law-firm-calculator.php';

		$matter_id    = isset( $arguments['matter_id'] ) ? absint( $arguments['matter_id'] ) : 0;
		$hours        = isset( $arguments['hours'] ) ? floatval( $arguments['hours'] ) : 0;
		$rate         = isset( $arguments['rate'] ) ? floatval( $arguments['rate'] ) : 0;
		$description  = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$date         = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$utbms_code   = isset( $arguments['utbms_code'] ) ? sanitize_text_field( $arguments['utbms_code'] ) : '';
		$billing_type = isset( $arguments['billing_type'] ) ? sanitize_text_field( $arguments['billing_type'] ) : 'billable';

		if ( ! $matter_id || $hours <= 0 || empty( $description ) ) {
			return new WP_Error( 'missing_required', __( 'Matter ID, hours, and description are required.', 'mcp-ai-wpoos-pro' ) );
		}

		$matter = get_post( $matter_id );
		if ( ! $matter || 'mcp_ai_lf_matter' !== $matter->post_type ) {
			return new WP_Error( 'not_found', __( 'Matter not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$warnings = array();

		// UTBMS validation.
		if ( $utbms_code ) {
			$utbms_result = WP_MCP_AI_Law_Firm_Calculator::validate_utbms_code( $utbms_code );
			if ( ! $utbms_result['is_valid'] ) {
				$warnings[] = sprintf(
					/* translators: %s: UTBMS code */
					__( 'UTBMS code "%s" may be invalid.', 'mcp-ai-wpoos-pro' ),
					$utbms_code
				);
			}
		}

		// Block billing detection.
		$block_check = WP_MCP_AI_Law_Firm_Calculator::detect_block_billing( $description );
		if ( $block_check['is_block_billed'] ) {
			$warnings[] = $block_check['suggestion'];
		}

		// Round to billing increment.
		$hours  = WP_MCP_AI_Law_Firm_Calculator::format_time_increment( $hours );
		$amount = $rate > 0 ? WP_MCP_AI_Law_Firm_Calculator::calculate_hourly_fee( $hours, $rate ) : 0;

		$post_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_lf_time_entry',
				'post_title'   => sprintf( '%s - %s', $date, wp_trim_words( $description, 10 ) ),
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $uid,
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_lf_matter_id', $matter_id );
		update_post_meta( $post_id, '_lf_hours', $hours );
		update_post_meta( $post_id, '_lf_rate', $rate );
		update_post_meta( $post_id, '_lf_amount', $amount );
		update_post_meta( $post_id, '_lf_date', $date );
		update_post_meta( $post_id, '_lf_utbms_code', $utbms_code );
		update_post_meta( $post_id, '_lf_billing_type', $billing_type );
		update_post_meta( $post_id, '_lf_timekeeper_id', $uid );

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: %1$s: hours formatted, %2$s: rate formatted */
				__( 'Time entry recorded: %1$s hours at %2$s. ', 'mcp-ai-wpoos-pro' ),
				WP_MCP_AI_Law_Firm_Calculator::format_hours( $hours ),
				WP_MCP_AI_Law_Firm_Calculator::format_currency( $rate )
			) . self::DISCLAIMER,
			'data'       => array(
				'entry_id'     => $post_id,
				'matter_id'    => $matter_id,
				'hours'        => $hours,
				'rate'         => $rate,
				'amount'       => $amount,
				'date'         => $date,
				'billing_type' => $billing_type,
				'warnings'     => $warnings,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
