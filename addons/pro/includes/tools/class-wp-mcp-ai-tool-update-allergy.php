<?php
/**
 * Tool for updating allergy information.
 *
 * Allows AI assistants to update existing allergy records.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing allergy record.
 */
class WP_MCP_AI_Tool_Update_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_allergy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Allergy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing allergy record. Only the allergy creator or users with edit_others_posts capability can update allergy records.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'allergy_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Allergy ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'allergen'       => array(
					'type'        => 'string',
					'description' => __( 'Allergen name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'type'           => array(
					'type'        => 'string',
					'description' => __( 'Allergy type (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'food', 'medication', 'environmental', 'insect', 'other' ),
				),
				'severity'       => array(
					'type'        => 'string',
					'description' => __( 'Severity level (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe', 'life-threatening' ),
				),
				'reactions'      => array(
					'type'        => 'string',
					'description' => __( 'Symptoms/reactions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'diagnosed_date' => array(
					'type'        => 'string',
					'description' => __( 'Diagnosed date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'allergy_id' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be logged in to update allergy records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get allergy ID.
		$allergy_id = isset( $arguments['allergy_id'] ) ? absint( $arguments['allergy_id'] ) : 0;

		if ( ! $allergy_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_allergy_id', __( 'Allergy ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify allergy exists.
		$allergy = get_post( $allergy_id );

		if ( ! $allergy || 'mcp_ai_allergy' !== $allergy->post_type ) {
			return new WP_Error( 'wp_mcp_ai_allergy_not_found', __( 'Allergy record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		$is_author       = absint( $allergy->post_author ) === $current_user_id;
		$can_edit_others = user_can( $current_user_id, 'edit_others_posts' );

		if ( ! $is_author && ! $can_edit_others ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update this allergy record.', 'mcp-ai-wpoos-pro' ) );
		}

		// Track updated fields.
		$updated_fields = array();

		// Update allergen if provided.
		if ( isset( $arguments['allergen'] ) ) {
			$allergen = sanitize_text_field( $arguments['allergen'] );
			if ( '' === $allergen ) {
				return new WP_Error( 'wp_mcp_ai_invalid_allergen', __( 'Allergen name cannot be empty.', 'mcp-ai-wpoos-pro' ) );
			}

			$result = wp_update_post(
				array(
					'ID'         => $allergy_id,
					'post_title' => $allergen,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'allergen';
		}

		// Update notes if provided.
		if ( isset( $arguments['notes'] ) ) {
			$notes  = sanitize_textarea_field( $arguments['notes'] );
			$result = wp_update_post(
				array(
					'ID'           => $allergy_id,
					'post_content' => $notes,
				),
				true
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$updated_fields[] = 'notes';
		}

		// Update type if provided.
		if ( isset( $arguments['type'] ) ) {
			$type        = sanitize_text_field( $arguments['type'] );
			$valid_types = array( 'food', 'medication', 'environmental', 'insect', 'other' );
			if ( $type && ! in_array( $type, $valid_types, true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Invalid allergy type.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $allergy_id, '_allergy_type', $type );
			$updated_fields[] = 'type';
		}

		// Update severity if provided.
		if ( isset( $arguments['severity'] ) ) {
			$severity         = sanitize_text_field( $arguments['severity'] );
			$valid_severities = array( 'mild', 'moderate', 'severe', 'life-threatening' );
			if ( $severity && ! in_array( $severity, $valid_severities, true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_severity', __( 'Invalid severity level.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $allergy_id, '_allergy_severity', $severity );
			$updated_fields[] = 'severity';
		}

		// Update reactions if provided.
		if ( isset( $arguments['reactions'] ) ) {
			$reactions = sanitize_text_field( $arguments['reactions'] );
			update_post_meta( $allergy_id, '_allergy_reactions', $reactions );
			$updated_fields[] = 'reactions';
		}

		// Update diagnosed date if provided.
		if ( isset( $arguments['diagnosed_date'] ) ) {
			$diagnosed_date = sanitize_text_field( $arguments['diagnosed_date'] );
			if ( ! empty( $diagnosed_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $diagnosed_date ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Diagnosed date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
			}
			update_post_meta( $allergy_id, '_allergy_diagnosed_date', $diagnosed_date );
			$updated_fields[] = 'diagnosed_date';
		}

		if ( empty( $updated_fields ) ) {
			return new WP_Error( 'wp_mcp_ai_no_updates', __( 'No fields were provided to update.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get updated allergy data.
		$updated_allergy = get_post( $allergy_id );

		$allergy_data = array(
			'id'             => $allergy_id,
			'allergen'       => $updated_allergy->post_title,
			'type'           => get_post_meta( $allergy_id, '_allergy_type', true ),
			'severity'       => get_post_meta( $allergy_id, '_allergy_severity', true ),
			'reactions'      => get_post_meta( $allergy_id, '_allergy_reactions', true ),
			'diagnosed_date' => get_post_meta( $allergy_id, '_allergy_diagnosed_date', true ),
			'notes'          => $updated_allergy->post_content,
			'modified_at'    => $updated_allergy->post_modified,
		);

		return array(
			'success'        => true,
			'allergy'        => $allergy_data,
			'updated_fields' => $updated_fields,
		);
	}
}
