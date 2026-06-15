<?php
/**
 * Schedule Follow Up — creates a CRM activity task at +N business days.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Create a follow-up task for a lead at +N business days from now.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Schedule_Follow_Up implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'schedule_follow_up';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Schedule Follow-Up', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Create a follow-up task for a lead at +N business days from now.', 'mcp-ai-wpoos-pro' );
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
				'lead_id'     => array( 'type' => 'integer' ),
				'days'        => array(
					'type'        => 'integer',
					'default'     => 2,
					'minimum'     => 1,
					'maximum'     => 30,
					'description' => __( 'Business days from now.', 'mcp-ai-wpoos-pro' ),
				),
				'notes'       => array(
					'type'        => 'string',
					'description' => __( 'Follow-up instructions.', 'mcp-ai-wpoos-pro' ),
				),
				'assigned_to' => array( 'type' => 'integer' ),
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
		$days        = min( 30, max( 1, absint( $arguments['days'] ?? 2 ) ) );
		$notes       = sanitize_textarea_field( $arguments['notes'] ?? '' );
		$due         = gmdate( 'Y-m-d', strtotime( "+{$days} days" ) );

		// Include lead name in the activity title for clarity.
		$lead_post = get_post( $lead_id );
		if ( $lead_post && 'mcp_ai_lead' === $lead_post->post_type ) {
			$title = sprintf(
				/* translators: 1: lead name, 2: lead ID */
				__( 'Follow up with %1$s (Lead #%2$d)', 'mcp-ai-wpoos-pro' ),
				get_the_title( $lead_post ),
				$lead_id
			);
		} else {
			$title = sprintf(
				/* translators: %d: lead ID */
				__( 'Follow up with lead #%d', 'mcp-ai-wpoos-pro' ),
				$lead_id
			);
		}

		$activity_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_crm_activity',
				'post_title'   => $title,
				'post_content' => $notes,
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $activity_id ) ) {
			return $activity_id;
		}

		update_post_meta( $activity_id, 'activity_type', 'task' );
		update_post_meta( $activity_id, 'related_type', 'lead' );
		update_post_meta( $activity_id, 'related_id', $lead_id );
		update_post_meta( $activity_id, 'due_date', $due );
		if ( ! empty( $arguments['assigned_to'] ) ) {
			update_post_meta( $activity_id, 'assigned_to', absint( $arguments['assigned_to'] ) );
		} else {
			$owner = get_post_meta( $lead_id, 'contact_owner', true );
			if ( $owner ) {
				update_post_meta( $activity_id, 'assigned_to', (int) $owner );
			}
		}

		return array(
			'success'     => true,
			'message'     => sprintf(
				/* translators: %s: due date */
				__( 'Follow-up scheduled for %s.', 'mcp-ai-wpoos-pro' ),
				$due
			),
			'lead_id'     => $lead_id,
			'activity_id' => $activity_id,
			'due_date'    => $due,
		);
	}
}
