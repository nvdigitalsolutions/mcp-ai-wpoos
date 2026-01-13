<?php
/**
 * Tool for creating medical records.
 *
 * Allows AI assistants to create new medical records for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new medical record.
 */
class WP_MCP_AI_Tool_Create_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Medical Record', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new medical record (lab result, diagnosis, treatment, vaccination, imaging, procedure, or hospitalization) for a member.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this record belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Record title or name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of medical record (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization' ),
				),
				'date'        => array(
					'type'        => 'string',
					'description' => __( 'Date of the medical event (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'facility'    => array(
					'type'        => 'string',
					'description' => __( 'Facility or location name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'diagnosis'   => array(
					'type'        => 'string',
					'description' => __( 'Primary diagnosis or finding (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'description' => array(
					'type'        => 'string',
					'description' => __( 'Detailed description, notes, or results (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'attachments' => array(
					'type'        => 'string',
					'description' => __( 'Attachment URLs or IDs (comma-separated) (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
			),
			'required'             => array( 'member_id', 'title', 'type', 'date' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$title     = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$type      = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : '';
		$date      = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $title ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Record title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $type ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_type', __( 'Record type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$valid_types = array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization' );
		if ( ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Invalid record type.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $date ) || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Valid date (YYYY-MM-DD) is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Optional fields.
		$provider    = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$facility    = isset( $arguments['facility'] ) ? sanitize_text_field( $arguments['facility'] ) : '';
		$diagnosis   = isset( $arguments['diagnosis'] ) ? sanitize_text_field( $arguments['diagnosis'] ) : '';
		$description = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$attachments = isset( $arguments['attachments'] ) ? sanitize_text_field( $arguments['attachments'] ) : '';

		// Create the medical record post.
		$record_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_medical_record',
				'post_title'   => $title,
				'post_content' => $description,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			),
			true
		);

		if ( is_wp_error( $record_id ) ) {
			return $record_id;
		}

		// Set record metadata.
		update_post_meta( $record_id, '_record_member_id', $member_id );
		update_post_meta( $record_id, '_record_date', $date );

		if ( $provider ) {
			update_post_meta( $record_id, '_record_provider', $provider );
		}
		if ( $facility ) {
			update_post_meta( $record_id, '_record_facility', $facility );
		}
		if ( $diagnosis ) {
			update_post_meta( $record_id, '_record_diagnosis', $diagnosis );
		}
		if ( $attachments ) {
			update_post_meta( $record_id, '_record_attachments', $attachments );
		}

		// Set record type taxonomy.
		wp_set_object_terms( $record_id, $type, 'mcp_ai_record_type' );

		// Build response.
		$record_data = array(
			'id'          => $record_id,
			'title'       => $title,
			'type'        => $type,
			'member_id'   => $member_id,
			'member_name' => $member->post_title,
			'date'        => $date,
			'provider'    => $provider,
			'facility'    => $facility,
			'diagnosis'   => $diagnosis,
			'description' => $description,
			'attachments' => $attachments,
		);

		return array(
			'success' => true,
			'record'  => $record_data,
			'message' => sprintf(
				/* translators: 1: record title, 2: member name */
				__( 'Medical record "%1$s" created successfully for %2$s.', 'mcp-ai-wpoos-pro' ),
				$title,
				$member->post_title
			),
		);
	}
}
