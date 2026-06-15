<?php
/**
 * Extract Lead from Message — creates or matches a lead from an inbound
 * message.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Extract or match a lead/contact from an inbound message. Creates a new
 * lead if no match found.
 *
 * @since 2.3.0
 */
class WP_MCP_AI_Tool_Extract_Lead_From_Message implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		return 'extract_lead_from_message';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Extract Lead from Message', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Extract or match a lead/contact from an inbound message. Creates a new lead if no match found.', 'mcp-ai-wpoos-pro' );
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
				'message_body'       => array( 'type' => 'string' ),
				'sender_email'       => array( 'type' => 'string' ),
				'sender_phone'       => array( 'type' => 'string' ),
				'sender_name'        => array( 'type' => 'string' ),
				'channel'            => array(
					'type'    => 'string',
					'default' => 'email',
				),
				'channel_contact_id' => array(
					'type'        => 'string',
					'description' => __( 'Platform-side contact/user ID for source traceability.', 'mcp-ai-wpoos-pro' ),
				),
				'connection_id'      => array(
					'type'        => 'string',
					'description' => __( 'Remote Site Manager connection ID for source attribution.', 'mcp-ai-wpoos-pro' ),
				),
				'message_id'         => array(
					'type'        => 'string',
					'description' => __( 'Platform message ID for source traceability.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'message_body' ),
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

		$uid = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $uid || ! user_can( $uid, 'edit_posts' ) ) {
			return new WP_Error( 'forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		$email   = sanitize_email( $arguments['sender_email'] ?? '' );
		$phone   = sanitize_text_field( $arguments['sender_phone'] ?? '' );
		$name    = sanitize_text_field( $arguments['sender_name'] ?? '' );
		$channel = sanitize_key( $arguments['channel'] ?? 'email' );
		if ( ! $email && ! $phone ) {
			return new WP_Error(
				'missing_identifier',
				__( 'At least sender_email or sender_phone is required to match or create a lead.', 'mcp-ai-wpoos-pro' )
			);
		}

		$contact_id = 0;
		$is_new     = true;
		if ( $email ) {
			$q = new WP_Query(
				array(
					'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => 'email',
							'value' => $email,
						),
					),
					'no_found_rows'  => true,
				)
			);
			if ( $q->have_posts() ) {
				$contact_id = $q->posts[0];
				$is_new     = false;
			}
		}
		if ( ! $contact_id && $phone ) {
			$q = new WP_Query(
				array(
					'post_type'      => array( 'mcp_ai_lead', 'mcp_crm_contacts' ),
					'post_status'    => 'publish',
					'posts_per_page' => 1,
					'fields'         => 'ids',
					'meta_query'     => array(
						array(
							'key'   => 'phone',
							'value' => $phone,
						),
					),
					'no_found_rows'  => true,
				)
			);
			if ( $q->have_posts() ) {
				$contact_id = $q->posts[0];
				$is_new     = false;
			}
		}

		if ( ! $contact_id ) {
			$contact_id = wp_insert_post(
				array(
					'post_type'   => 'mcp_ai_lead',
					'post_title'  => $name ? $name : __( 'Inbound Lead', 'mcp-ai-wpoos-pro' ),
					'post_status' => 'publish',
				),
				true
			);
			if ( is_wp_error( $contact_id ) ) {
				return $contact_id;
			}
			if ( $name ) {
				$parts = explode( ' ', $name, 2 );
				update_post_meta( $contact_id, 'first_name', sanitize_text_field( $parts[0] ) );
				if ( isset( $parts[1] ) ) {
					update_post_meta( $contact_id, 'last_name', sanitize_text_field( $parts[1] ) );
				}
			}
			update_post_meta( $contact_id, 'email', $email );
			update_post_meta( $contact_id, 'phone', $phone );
			update_post_meta( $contact_id, 'source', $channel );
			update_post_meta( $contact_id, 'lead_status', 'new' );
			update_post_meta( $contact_id, 'lifecycle_stage', 'lead' );
			if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) ) {
				$o = WP_MCP_AI_CRM_Engine::get_next_owner();
				if ( $o ) {
					update_post_meta( $contact_id, 'contact_owner', $o );
				}
			}
		}

		// Store source connection metadata for traceability (both new and existing leads).
		$connection_id_arg = isset( $arguments['connection_id'] ) ? sanitize_text_field( $arguments['connection_id'] ) : '';
		$message_id_arg    = isset( $arguments['message_id'] ) ? sanitize_text_field( $arguments['message_id'] ) : '';
		if ( $contact_id && $connection_id_arg ) {
			update_post_meta( $contact_id, '_source_connection_id', $connection_id_arg );
		}
		if ( $contact_id && $message_id_arg ) {
			update_post_meta( $contact_id, '_source_message_id', $message_id_arg );
		}
		if ( $contact_id && ! empty( $arguments['channel_contact_id'] ) ) {
			update_post_meta( $contact_id, '_source_channel_contact_id', sanitize_text_field( $arguments['channel_contact_id'] ) );
		}

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'lead_extracted',
				'lead',
				$contact_id,
				array(
					'is_new'  => $is_new,
					'channel' => $channel,
				)
			);
		}
		return array(
			'success'    => true,
			'contact_id' => $contact_id,
			'is_new'     => $is_new,
			'message'    => $is_new
				? __( 'New lead created from inbound message.', 'mcp-ai-wpoos-pro' )
				: __( 'Existing contact matched.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
