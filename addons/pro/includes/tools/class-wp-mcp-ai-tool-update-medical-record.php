<?php
/**
 * Tool for updating medical record information.
 *
 * Allows AI assistants to update existing medical records.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing medical record.
 */
class WP_MCP_AI_Tool_Update_Medical_Record implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_medical_record';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Medical Record', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing medical record. Only the record creator or users with edit_others_posts capability can update records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'record_id'   => array(
					'type'        => 'integer',
					'description' => __( 'Medical record ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Record title or name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'type'        => array(
					'type'        => 'string',
					'description' => __( 'Type of medical record (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization' ),
				),
				'date'        => array(
					'type'        => 'string',
					'description' => __( 'Date of the medical event (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
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
			'required'             => array( 'record_id' ),
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

		if ( ! $current_user_id ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get record ID.
		$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( ! $record_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_record_id', __( 'Medical record ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify record exists.
		$record = get_post( $record_id );

		if ( ! $record || 'mcp_ai_medical_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_record_not_found', __( 'Medical record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author = absint( $record->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this medical record.', 'mcp-ai-wpoos-pro' ) );
		}

		// Track updated fields.
		$updated_fields = array();

		// Update title if provided.
		if ( isset( $arguments['title'] ) ) {
			$title = sanitize_text_field( $arguments['title'] );
			if ( '' === $title ) {
				return new WP_Error( 'wp_mcp_ai_invalid_title', __( 'Record title cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = wp_update_post( array(
				'ID'         => $record_id,
				'post_title' => $title,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'title';
		}

		// Update description if provided.
		if ( isset( $arguments['description'] ) ) {
			$description = sanitize_textarea_field( $arguments['description'] );
			$result = wp_update_post( array(
				'ID'           => $record_id,
				'post_content' => $description,
			), true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'description';
		}

		// Update record type if provided.
		if ( isset( $arguments['type'] ) ) {
			$type = sanitize_key( $arguments['type'] );
			$valid_types = array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization' );
			if ( ! in_array( $type, $valid_types, true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Invalid record type.', 'mcp-ai-wpoos-pro' ) );
			}

			wp_set_object_terms( $record_id, $type, 'mcp_ai_record_type' );
			$updated_fields[] = 'type';
		}

		// Update date if provided.
		if ( isset( $arguments['date'] ) ) {
			$date = sanitize_text_field( $arguments['date'] );
			if ( ! empty( $date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $record_id, '_record_date', $date );
			$updated_fields[] = 'date';
		}

		// Update provider if provided.
		if ( isset( $arguments['provider'] ) ) {
			$provider = sanitize_text_field( $arguments['provider'] );
			update_post_meta( $record_id, '_record_provider', $provider );
			$updated_fields[] = 'provider';
		}

		// Update facility if provided.
		if ( isset( $arguments['facility'] ) ) {
			$facility = sanitize_text_field( $arguments['facility'] );
			update_post_meta( $record_id, '_record_facility', $facility );
			$updated_fields[] = 'facility';
		}

		// Update diagnosis if provided.
		if ( isset( $arguments['diagnosis'] ) ) {
			$diagnosis = sanitize_text_field( $arguments['diagnosis'] );
			update_post_meta( $record_id, '_record_diagnosis', $diagnosis );
			$updated_fields[] = 'diagnosis';
		}

		// Update attachments if provided.
		if ( isset( $arguments['attachments'] ) ) {
			$attachments = sanitize_text_field( $arguments['attachments'] );
			update_post_meta( $record_id, '_record_attachments', $attachments );
			$updated_fields[] = 'attachments';
		}

		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields were provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get updated record data.
		$updated_record = get_post( $record_id );
		$types          = wp_get_object_terms( $record_id, 'mcp_ai_record_type', array( 'fields' => 'names' ) );
		$record_type    = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : '';

		$record_data = array(
			'id'          => $record_id,
			'title'       => $updated_record->post_title,
			'type'        => $record_type,
			'date'        => get_post_meta( $record_id, '_record_date', true ),
			'provider'    => get_post_meta( $record_id, '_record_provider', true ),
			'facility'    => get_post_meta( $record_id, '_record_facility', true ),
			'diagnosis'   => get_post_meta( $record_id, '_record_diagnosis', true ),
			'description' => $updated_record->post_content,
			'attachments' => get_post_meta( $record_id, '_record_attachments', true ),
			'modified_at' => $updated_record->post_modified,
		);

		return array(
			'success'        => true,
			'record'         => $record_data,
			'updated_fields' => $updated_fields,
		);
	}
}
