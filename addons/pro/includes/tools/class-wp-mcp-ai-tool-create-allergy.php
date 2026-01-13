<?php
/**
 * Tool for creating allergies.
 *
 * Allows AI assistants to create new allergy records for members.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Creates a new allergy record.
 */
class WP_MCP_AI_Tool_Create_Allergy implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_allergy';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Allergy', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new allergy record for a member, including allergen name, type, severity, reactions, and management notes.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'      => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this allergy is for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'allergen'       => array(
					'type'        => 'string',
					'description' => __( 'Allergen name (e.g., "Peanuts", "Penicillin", "Pollen") (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'type'           => array(
					'type'        => 'string',
					'description' => __( 'Allergy type: "food", "medication", "environmental", "insect", "other" (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'food', 'medication', 'environmental', 'insect', 'other' ),
				),
				'severity'       => array(
					'type'        => 'string',
					'description' => __( 'Severity level: "mild", "moderate", "severe", "life-threatening" (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe', 'life-threatening' ),
				),
				'reactions'      => array(
					'type'        => 'string',
					'description' => __( 'Symptoms/reactions (e.g., "hives, swelling, difficulty breathing") (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'diagnosed_date' => array(
					'type'        => 'string',
					'description' => __( 'Date allergy was diagnosed (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Additional notes, management instructions, or treatment details (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'member_id', 'allergen' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create allergy records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$allergen  = isset( $arguments['allergen'] ) ? sanitize_text_field( $arguments['allergen'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $allergen ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_allergen', __( 'Allergen name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Optional fields.
		$type           = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
		$severity       = isset( $arguments['severity'] ) ? sanitize_text_field( $arguments['severity'] ) : '';
		$reactions      = isset( $arguments['reactions'] ) ? sanitize_text_field( $arguments['reactions'] ) : '';
		$diagnosed_date = isset( $arguments['diagnosed_date'] ) ? sanitize_text_field( $arguments['diagnosed_date'] ) : '';
		$notes          = isset( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Validate type if provided.
		$valid_types = array( 'food', 'medication', 'environmental', 'insect', 'other' );
		if ( $type && ! in_array( $type, $valid_types, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_type', __( 'Invalid allergy type.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate severity if provided.
		$valid_severities = array( 'mild', 'moderate', 'severe', 'life-threatening' );
		if ( $severity && ! in_array( $severity, $valid_severities, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_severity', __( 'Invalid severity level.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate diagnosed date if provided.
		if ( ! empty( $diagnosed_date ) && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $diagnosed_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Diagnosed date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create the allergy post.
		$allergy_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_allergy',
				'post_title'   => $allergen,
				'post_content' => $notes,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			),
			true
		);

		if ( is_wp_error( $allergy_id ) ) {
			return $allergy_id;
		}

		// Set allergy metadata.
		update_post_meta( $allergy_id, '_allergy_member_id', $member_id );

		if ( $type ) {
			update_post_meta( $allergy_id, '_allergy_type', $type );
		}
		if ( $severity ) {
			update_post_meta( $allergy_id, '_allergy_severity', $severity );
		}
		if ( $reactions ) {
			update_post_meta( $allergy_id, '_allergy_reactions', $reactions );
		}
		if ( $diagnosed_date ) {
			update_post_meta( $allergy_id, '_allergy_diagnosed_date', $diagnosed_date );
		}

		// Build response.
		$allergy_data = array(
			'id'             => $allergy_id,
			'allergen'       => $allergen,
			'member_id'      => $member_id,
			'member_name'    => $member->post_title,
			'type'           => $type,
			'severity'       => $severity,
			'reactions'      => $reactions,
			'diagnosed_date' => $diagnosed_date,
			'notes'          => $notes,
		);

		return array(
			'success' => true,
			'allergy' => $allergy_data,
			'message' => sprintf(
				/* translators: 1: allergen name, 2: member name */
				__( 'Allergy record for "%1$s" created for %2$s.', 'mcp-ai-wpoos-pro' ),
				$allergen,
				$member->post_title
			),
		);
	}
}
