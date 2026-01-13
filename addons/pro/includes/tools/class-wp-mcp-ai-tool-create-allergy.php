<?php
/**
 * Tool for creating allergies.
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
		return __( 'Creates a new allergy record for a member with allergen, severity, and reaction information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id' => array(
					'type'        => 'integer',
					'description' => __( 'Member ID this allergy belongs to (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'allergen'  => array(
					'type'        => 'string',
					'description' => __( 'Allergen name (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'severity'  => array(
					'type'        => 'string',
					'description' => __( 'Severity level (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'mild', 'moderate', 'severe' ),
				),
				'reactions' => array(
					'type'        => 'string',
					'description' => __( 'Typical reactions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'diagnosed_date' => array(
					'type'        => 'string',
					'description' => __( 'Date diagnosed (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'     => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
			),
			'required'             => array( 'member_id', 'allergen', 'severity' ),
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to create allergies.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields.
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$allergen  = isset( $arguments['allergen'] ) ? sanitize_text_field( $arguments['allergen'] ) : '';
		$severity  = isset( $arguments['severity'] ) ? sanitize_key( $arguments['severity'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $allergen ) {
			return new WP_Error( 'wp_mcp_ai_missing_allergen', __( 'Allergen is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $severity ) {
			return new WP_Error( 'wp_mcp_ai_missing_severity', __( 'Severity is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$reactions      = isset( $arguments['reactions'] ) ? sanitize_textarea_field( $arguments['reactions'] ) : '';
		$diagnosed_date = isset( $arguments['diagnosed_date'] ) ? sanitize_text_field( $arguments['diagnosed_date'] ) : '';
		$notes          = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		// Validate date if provided.
		if ( $diagnosed_date && ! $this->validate_date( $diagnosed_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid diagnosed date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create allergy post.
		$post_data = array(
			'post_type'    => 'mcp_ai_allergy',
			'post_title'   => $allergen,
			'post_content' => $notes,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$allergy_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $allergy_id ) ) {
			return $allergy_id;
		}

		// Set severity taxonomy.
		wp_set_object_terms( $allergy_id, $severity, 'mcp_ai_allergy_severity' );

		// Save allergy metadata.
		update_post_meta( $allergy_id, '_allergy_member_id', $member_id );
		update_post_meta( $allergy_id, '_allergy_allergen', $allergen );
		update_post_meta( $allergy_id, '_allergy_severity', $severity );

		if ( $reactions ) {
			update_post_meta( $allergy_id, '_allergy_reactions', $reactions );
		}

		if ( $diagnosed_date ) {
			update_post_meta( $allergy_id, '_allergy_diagnosed_date', $diagnosed_date );
		}

		return array(
			'success'    => true,
			'message'    => __( 'Allergy created successfully.', 'mcp-ai-wpoos-pro' ),
			'allergy_id' => $allergy_id,
			'allergy'    => array(
				'id'             => $allergy_id,
				'member_id'      => $member_id,
				'allergen'       => $allergen,
				'severity'       => $severity,
				'reactions'      => $reactions,
				'diagnosed_date' => $diagnosed_date,
				'notes'          => $notes,
				'created_at'     => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate date format (YYYY-MM-DD).
	 *
	 * @param string $date Date string.
	 * @return bool
	 */
	private function validate_date( $date ) {
		$d = DateTime::createFromFormat( 'Y-m-d', $date );
		return $d && $d->format( 'Y-m-d' ) === $date;
	}
}
