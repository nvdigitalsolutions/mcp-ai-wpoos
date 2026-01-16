<?php
/**
 * Tool for updating medical records.
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
		return __( 'Updates an existing medical record with new information.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Record ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'       => array(
					'type'        => 'string',
					'description' => __( 'Record title (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'date'        => array(
					'type'        => 'string',
					'description' => __( 'Date of record (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update medical records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate record ID.
		$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

		if ( ! $record_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Record ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get record.
		$record = get_post( $record_id );

		if ( ! $record || 'mcp_ai_medical_record' !== $record->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Medical record not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$title    = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$date     = isset( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
		$provider = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$details  = isset( $arguments['details'] ) ? wp_kses_post( $arguments['details'] ) : '';
		$notes    = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';

		// Validate date if provided.
		if ( $date && ! $this->validate_date( $date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_date', __( 'Invalid date format. Use YYYY-MM-DD.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update post if title, details, or notes changed.
		if ( $title || $details || $notes ) {
			$post_data = array(
				'ID' => $record_id,
			);

			if ( $title ) {
				$post_data['post_title'] = $title;
			}

			if ( $details ) {
				$post_data['post_content'] = $details;
			}

			if ( $notes ) {
				$post_data['post_excerpt'] = $notes;
			}

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata.
		if ( $date ) {
			update_post_meta( $record_id, '_medical_record_date', $date );
		}

		if ( $provider ) {
			update_post_meta( $record_id, '_medical_record_provider', $provider );
		}

		return array(
			'success' => true,
			'message' => __( 'Medical record updated successfully.', 'mcp-ai-wpoos-pro' ),
			'record'  => array(
				'id'         => $record_id,
				'title'      => $title ?: get_post_field( 'post_title', $record_id ),
				'date'       => $date ?: get_post_meta( $record_id, '_medical_record_date', true ),
				'provider'   => $provider ?: get_post_meta( $record_id, '_medical_record_provider', true ),
				'updated_at' => current_time( 'mysql' ),
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
