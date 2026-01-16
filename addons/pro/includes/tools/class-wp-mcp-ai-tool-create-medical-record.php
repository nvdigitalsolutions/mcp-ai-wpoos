<?php
/**
 * Tool for creating medical records.
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
		return __( 'Creates a new medical record for a member including lab results, diagnoses, treatments, vaccinations, imaging, or procedures.', 'mcp-ai-wpoos-pro' );
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
				'record_type' => array(
					'type'        => 'string',
					'description' => __( 'Type of medical record (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'lab-result', 'diagnosis', 'treatment', 'vaccination', 'imaging', 'procedure', 'hospitalization' ),
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Record title (required)', 'mcp-ai-wpoos-pro' ),
					'minLength'   => 1,
					'maxLength'   => 200,
				),
				'date'        => array(
					'type'        => 'string',
					'description' => __( 'Date of record (YYYY-MM-DD) (optional, defaults to today)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'provider'    => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider or facility name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'details'     => array(
					'type'        => 'string',
					'description' => __( 'Detailed information about the medical record (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 10000,
				),
				'notes'       => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
			),
			'required'             => array( 'member_id', 'record_type', 'title' ),
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
		$member_id   = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$record_type = isset( $arguments['record_type'] ) ? sanitize_key( $arguments['record_type'] ) : '';
		$title       = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $record_type ) {
			return new WP_Error( 'wp_mcp_ai_missing_type', __( 'Record type is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( '' === $title ) {
			return new WP_Error( 'wp_mcp_ai_missing_title', __( 'Title is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_member', __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$date     = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : current_time( 'Y-m-d' );
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$details  = isset( $arguments['details'] ) ? wp_kses_post( $arguments['details'] ) : '';
		$notes    = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		// Validate date.
		if ( $date && ! $this->validate_date( $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Create medical record post.
		$post_data = array(
			'post_type'    => 'mcp_ai_medical_record',
			'post_title'   => $title,
			'post_content' => $details,
			'post_excerpt' => $notes,
			'post_status'  => 'publish',
			'post_author'  => $current_user_id,
		);

		$record_id = wp_insert_post( $post_data, true );

		if ( is_wp_error( $record_id ) ) {
			return $record_id;
		}

		// Set record type taxonomy.
		wp_set_object_terms( $record_id, $record_type, 'mcp_ai_record_type' );

		// Save record metadata.
		update_post_meta( $record_id, '_medical_record_member_id', $member_id );
		update_post_meta( $record_id, '_medical_record_date', $date );

		if ( $provider ) {
			update_post_meta( $record_id, '_medical_record_provider', $provider );
		}

		return array(
			'success'   => true,
			'message'   => __( 'Medical record created successfully.', 'mcp-ai-wpoos-pro' ),
			'record_id' => $record_id,
			'record'    => array(
				'id'          => $record_id,
				'member_id'   => $member_id,
				'record_type' => $record_type,
				'title'       => $title,
				'date'        => $date,
				'provider'    => $provider,
				'details'     => $details,
				'notes'       => $notes,
				'created_at'  => current_time( 'mysql' ),
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
