<?php
/**
 * Calendar Rule Calculator Tool
 *
 * Calculates legal deadlines based on calendar rules for service, filing, motions,
 * discovery responses, and appeals.
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
 * Calculates deadlines using calendar rules for various legal events.
 */
class WP_MCP_AI_Tool_LF_Calendar_Rule_Calculator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'lf_calendar_rule_calculator';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Calendar Rule Calculator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Calculates legal deadlines based on calendar rules for service, filing, motions, discovery responses, and appeals across federal and state jurisdictions with adjustments for service method.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'event_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type of legal event.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'service', 'filing', 'motion', 'discovery_response', 'appeal' ),
				),
				'event_date'     => array(
					'type'        => 'string',
					'description' => __( 'Date of the triggering event (YYYY-MM-DD).', 'mcp-ai-wpoos-pro' ),
				),
				'jurisdiction'   => array(
					'type'        => 'string',
					'description' => __( 'Jurisdiction type.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'federal', 'state' ),
				),
				'state'          => array(
					'type'        => 'string',
					'description' => __( 'State abbreviation (when jurisdiction is state).', 'mcp-ai-wpoos-pro' ),
				),
				'service_method' => array(
					'type'        => 'string',
					'description' => __( 'Method of service (affects deadline calculation).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'personal', 'mail', 'electronic' ),
				),
			),
			'required'   => array( 'event_type', 'event_date', 'jurisdiction' ),
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
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( ! self::is_available() ) {
			return new WP_Error( 'tool_not_available', self::get_unavailable_reason() );
		}

		$event_type     = isset( $arguments['event_type'] ) ? sanitize_text_field( $arguments['event_type'] ) : '';
		$event_date     = isset( $arguments['event_date'] ) ? sanitize_text_field( $arguments['event_date'] ) : '';
		$jurisdiction   = isset( $arguments['jurisdiction'] ) ? sanitize_text_field( $arguments['jurisdiction'] ) : 'federal';
		$state          = isset( $arguments['state'] ) ? sanitize_text_field( strtoupper( $arguments['state'] ) ) : '';
		$service_method = isset( $arguments['service_method'] ) ? sanitize_text_field( $arguments['service_method'] ) : 'personal';

		if ( empty( $event_type ) || empty( $event_date ) ) {
			return new WP_Error( 'missing_required', __( 'Event type, event date, and jurisdiction are required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Response periods in days by event type (FRCP defaults).
		$response_periods = array(
			'service'            => 21, // Answer to complaint.
			'filing'             => 21,
			'motion'             => 14, // Opposition to motion.
			'discovery_response' => 30, // Interrogatories / RFP.
			'appeal'             => 30, // Notice of appeal.
		);

		$base_days = $response_periods[ $event_type ] ?? 21;

		// Service method adjustments (FRCP Rule 6(d)).
		$additional_days = 0;
		$rule_note       = '';
		switch ( $service_method ) {
			case 'mail':
				$additional_days = 3;
				$rule_note       = __( 'FRCP Rule 6(d): +3 days for service by mail.', 'mcp-ai-wpoos-pro' );
				break;
			case 'electronic':
				$additional_days = 0;
				$rule_note       = __( 'Electronic service: no additional days under current federal rules.', 'mcp-ai-wpoos-pro' );
				break;
			default:
				$rule_note = __( 'Personal service: standard deadline applies.', 'mcp-ai-wpoos-pro' );
				break;
		}

		$total_days = $base_days + $additional_days;

		$rule_type = ( 'federal' === $jurisdiction ) ? 'frcp' : 'calendar';
		$deadline  = WP_MCP_AI_Law_Firm_Calculator::calculate_filing_deadline( $event_date, $total_days, $rule_type );

		$applicable_rule = '';
		switch ( $event_type ) {
			case 'service':
			case 'filing':
				$applicable_rule = __( 'FRCP Rule 12(a): 21 days to respond to complaint', 'mcp-ai-wpoos-pro' );
				break;
			case 'motion':
				$applicable_rule = __( 'FRCP Rule 6(c)(1): 14 days for opposition to motion', 'mcp-ai-wpoos-pro' );
				break;
			case 'discovery_response':
				$applicable_rule = __( 'FRCP Rules 33/34: 30 days for discovery responses', 'mcp-ai-wpoos-pro' );
				break;
			case 'appeal':
				$applicable_rule = __( 'FRAP Rule 4(a): 30 days for notice of appeal', 'mcp-ai-wpoos-pro' );
				break;
		}

		return array(
			'success'    => true,
			'message'    => sprintf(
				/* translators: 1: deadline date, 2: event type */
				__( 'Deadline for %2$s: %1$s. ', 'mcp-ai-wpoos-pro' ),
				$deadline,
				str_replace( '_', ' ', $event_type )
			) . self::DISCLAIMER,
			'data'       => array(
				'deadline_date'   => $deadline,
				'event_type'      => $event_type,
				'event_date'      => $event_date,
				'base_days'       => $base_days,
				'additional_days' => $additional_days,
				'total_days'      => $total_days,
				'applicable_rule' => $applicable_rule,
				'service_method'  => $service_method,
				'rule_note'       => $rule_note,
				'jurisdiction'    => $jurisdiction,
				'state'           => $state,
			),
			'disclaimer' => self::DISCLAIMER,
		);
	}
}
