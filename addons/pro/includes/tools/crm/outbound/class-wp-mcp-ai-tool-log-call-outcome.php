<?php
/**
 * Log Call Outcome — records a call disposition against a lead.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Record a call outcome with disposition, notes, and optional recording URL.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Log_Call_Outcome implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * Get the reason the tool is unavailable.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'log_call_outcome';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Log Call Outcome', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Record a call outcome with disposition, notes, and optional recording URL.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'          => array( 'type' => 'integer' ),
				'disposition'      => array(
					'type'        => 'string',
					'description' => __( 'Call outcome slug (connected, voicemail, no_answer, callback_scheduled, not_interested, qualified, demo_scheduled).', 'mcp-ai-wpoos-pro' ),
				),
				'notes'            => array( 'type' => 'string' ),
				'recording_url'    => array( 'type' => 'string' ),
				'duration_seconds' => array( 'type' => 'integer' ),
			),
			'required'   => array( 'lead_id' ),
		);
	}

	/**
	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Whether the tool requires base pro.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get the capability flags.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}

		$lead_id     = absint( $arguments['lead_id'] );
		$p           = get_post( $lead_id );
		if ( ! $p ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$disposition = sanitize_key( $arguments['disposition'] ?? 'connected' );
		$notes       = sanitize_textarea_field( $arguments['notes'] ?? '' );
		$recording   = esc_url_raw( $arguments['recording_url'] ?? '' );
		$duration    = absint( $arguments['duration_seconds'] ?? 0 );

		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => sprintf(
					/* translators: %s: call disposition */
					__( 'Call: %s', 'mcp-ai-wpoos-pro' ),
					$disposition
				),
				'post_content' => $notes,
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $activity_id ) ) {
			return $activity_id;
		}

		update_post_meta( $activity_id, 'activity_type', 'call' );
		update_post_meta( $activity_id, 'related_type', 'lead' );
		update_post_meta( $activity_id, 'related_id', $lead_id );
		update_post_meta( $activity_id, 'disposition', $disposition );
		if ( $recording ) {
			update_post_meta( $activity_id, 'recording_url', $recording );
		}
		if ( $duration ) {
			update_post_meta( $activity_id, 'call_duration', $duration );
		}

		// If call resulted in demo/meeting, mark lead as hot.
		if ( in_array( $disposition, array( 'qualified', 'demo_scheduled' ), true ) ) {
			update_post_meta( $lead_id, 'lead_status', 'qualified' );
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
				update_post_meta( $lead_id, 'lead_score', 80 );
			}
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record( 'call_outcome_logged', 'lead', $lead_id, array( 'disposition' => $disposition ) );
		}

		return array(
			'success'     => true,
			'message'     => __( 'Call outcome logged.', 'mcp-ai-wpoos-pro' ),
			'lead_id'     => $lead_id,
			'activity_id' => $activity_id,
			'disposition' => $disposition,
		);
	}
}
