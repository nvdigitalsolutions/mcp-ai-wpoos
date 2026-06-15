<?php
/**
 * Assign Lead to Owner Tool — routing + manual assignment.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Assign Lead to Owner — routing + manual assignment tool.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Assign_Lead_To_Owner implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public static function is_available() {
		$s = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $s['enable_crm_toolkit'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		return __( 'CRM Toolkit required.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'assign_lead_to_owner';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Assign Lead to Owner', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Assign a lead to a specific owner, or use the automatic routing strategy (round_robin, weighted).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'lead_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Lead or contact post ID.', 'mcp-ai-wpoos-pro' ),
				),
				'owner_id' => array(
					'type'        => 'integer',
					'description' => __( 'WP user ID to assign. If 0 or omitted, uses the automatic routing strategy.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'lead_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write', 'requires-capability' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! self::is_available() ) {
			return new WP_Error( 'unavailable', self::get_unavailable_reason() );
		}
		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$lead_id = absint( $arguments['lead_id'] );
		$post    = get_post( $lead_id );
		if ( ! $post || ! in_array( $post->post_type, array( 'mcp_ai_lead', 'mcp_crm_contacts' ), true ) ) {
			return new WP_Error( 'not_found', __( 'Lead not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$previous_owner = get_post_meta( $lead_id, 'contact_owner', true );

		// Determine owner.
		$owner_id = isset( $arguments['owner_id'] ) ? absint( $arguments['owner_id'] ) : 0;
		if ( 0 === $owner_id && class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
			$owner_id = WP_MCP_AI_CRM_Engine::get_next_owner();
		}
		if ( ! $owner_id || ! get_userdata( $owner_id ) ) {
			return new WP_Error( 'invalid_owner', __( 'No valid owner available.  Configure a routing pool in CRM settings.', 'mcp-ai-wpoos-pro' ) );
		}

		update_post_meta( $lead_id, 'contact_owner', $owner_id );

		$user          = get_userdata( $owner_id );
		$assigned_name = $user ? $user->display_name : (string) $owner_id;

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_assigned',
				'lead',
				$lead_id,
				array(
					'previous_owner' => $previous_owner,
					'new_owner'      => $owner_id,
				)
			);
		}

		return array(
			'success'        => true,
			/* translators: %s: assigned user name */
			'message'        => sprintf( __( 'Lead assigned to %s.', 'mcp-ai-wpoos-pro' ), $assigned_name ),
			'lead_id'        => $lead_id,
			'owner_id'       => $owner_id,
			'owner_name'     => $assigned_name,
			'previous_owner' => $previous_owner,
			'strategy_used'  => ( 0 === ( $arguments['owner_id'] ?? 0 ) ) ? 'auto' : 'manual',
		);
	}
}
