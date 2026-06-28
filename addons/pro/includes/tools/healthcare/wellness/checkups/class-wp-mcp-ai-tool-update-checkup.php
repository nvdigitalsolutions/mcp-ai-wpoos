<?php
/**
 * Tool for updating checkups.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates an existing checkup/appointment.
 */
class WP_MCP_AI_Tool_Update_Checkup implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_checkup';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Checkup', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing checkup or appointment with new information.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'checkup_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Checkup ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'title'            => array(
					'type'        => 'string',
					'description' => __( 'Checkup title (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'datetime'         => array(
					'type'        => 'string',
					'description' => __( 'Date and time (YYYY-MM-DD HH:MM) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2} \d{2}:\d{2}$',
				),
				'provider'         => array(
					'type'        => 'string',
					'description' => __( 'Healthcare provider name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'location'         => array(
					'type'        => 'string',
					'description' => __( 'Location or facility name (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'type'             => array(
					'type'        => 'string',
					'description' => __( 'Type of checkup (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'wellness', 'follow-up', 'consultation', 'procedure', 'vaccination', 'dental', 'vision' ),
				),
				'status'           => array(
					'type'        => 'string',
					'description' => __( 'Appointment status (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'scheduled', 'completed', 'cancelled', 'no-show' ),
				),
				'notes'            => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 5000,
				),
				'chief_complaint'  => array(
					'type'        => 'string',
					'description' => __( 'Chief complaint or reason for visit (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
				'diagnosis'        => array(
					'type'        => 'string',
					'description' => __( 'Working or final diagnosis (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
				),
				'duration_minutes' => array(
					'type'        => 'integer',
					'description' => __( 'Duration in minutes (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'follow_up_date'   => array(
					'type'        => 'string',
					'description' => __( 'Follow-up date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'copay_amount'     => array(
					'type'        => 'string',
					'description' => __( 'Copay paid (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
			),
			'required'             => array( 'checkup_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'health_wellness',
			'post_type'             => 'mcp_ai_checkup',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'healthcare_provider', 'caregiver' ),
			'risk_level'            => 'standard',
		);
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update checkups.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate checkup ID.
		$checkup_id = isset( $arguments['checkup_id'] ) ? absint( $arguments['checkup_id'] ) : 0;

		if ( ! $checkup_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'Checkup ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get checkup.
		$checkup = get_post( $checkup_id );

		if ( ! $checkup || 'mcp_ai_checkup' !== $checkup->post_type ) {
			return new WP_Error( 'wp_mcp_ai_not_found', __( 'Checkup not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Sanitize optional fields.
		$title           = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : '';
		$datetime        = isset( $arguments['datetime'] ) ? sanitize_text_field( $arguments['datetime'] ) : '';
		$provider        = isset( $arguments['provider'] ) ? sanitize_text_field( $arguments['provider'] ) : '';
		$location        = isset( $arguments['location'] ) ? sanitize_text_field( $arguments['location'] ) : '';
		$type            = isset( $arguments['type'] ) ? sanitize_key( $arguments['type'] ) : '';
		$status          = isset( $arguments['status'] ) ? sanitize_key( $arguments['status'] ) : '';
		$notes           = isset( $arguments['notes'] ) ? wp_kses_post( $arguments['notes'] ) : '';
		$chief_complaint = isset( $arguments['chief_complaint'] ) ? sanitize_textarea_field( $arguments['chief_complaint'] ) : '';
		$diagnosis       = isset( $arguments['diagnosis'] ) ? sanitize_textarea_field( $arguments['diagnosis'] ) : '';
		$duration        = isset( $arguments['duration_minutes'] ) ? absint( $arguments['duration_minutes'] ) : 0;
		$follow_up_date  = isset( $arguments['follow_up_date'] ) ? sanitize_text_field( $arguments['follow_up_date'] ) : '';
		$copay_amount    = isset( $arguments['copay_amount'] ) ? sanitize_text_field( $arguments['copay_amount'] ) : '';

		// Validate datetime if provided.
		if ( $datetime && ! $this->validate_datetime( $datetime ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_datetime', __( 'Invalid datetime format. Use YYYY-MM-DD HH:MM.', 'mcp-ai-wpoos-pro' ) );
		}

		// Update post if title or notes changed.
		if ( $title || $notes ) {
			$post_data = array(
				'ID' => $checkup_id,
			);

			if ( $title ) {
				$post_data['post_title'] = $title;
			}

			if ( $notes ) {
				$post_data['post_content'] = $notes;
			}

			$result = wp_update_post( $post_data, true );

			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata.
		if ( $datetime ) {
			update_post_meta( $checkup_id, '_checkup_datetime', $datetime );
		}

		if ( $provider ) {
			update_post_meta( $checkup_id, '_checkup_provider', $provider );
		}

		if ( $location ) {
			update_post_meta( $checkup_id, '_checkup_location', $location );
		}

		if ( $type ) {
			update_post_meta( $checkup_id, '_checkup_type', $type );
		}

		if ( $status ) {
			update_post_meta( $checkup_id, '_checkup_status', $status );
		}

		if ( $chief_complaint ) {
			update_post_meta( $checkup_id, '_checkup_chief_complaint', $chief_complaint );
		}

		if ( $diagnosis ) {
			update_post_meta( $checkup_id, '_checkup_diagnosis', $diagnosis );
		}

		if ( $duration ) {
			update_post_meta( $checkup_id, '_checkup_duration_minutes', $duration );
		}

		if ( $follow_up_date ) {
			update_post_meta( $checkup_id, '_checkup_follow_up_date', $follow_up_date );
		}

		if ( $copay_amount ) {
			update_post_meta( $checkup_id, '_checkup_copay_amount', $copay_amount );
		}

		return array(
			'success' => true,
			'message' => __( 'Checkup updated successfully.', 'mcp-ai-wpoos-pro' ),
			'checkup' => array(
				'id'               => $checkup_id,
				'title'            => $title ? $title : get_post_field( 'post_title', $checkup_id ),
				'datetime'         => $datetime ? $datetime : get_post_meta( $checkup_id, '_checkup_datetime', true ),
				'provider'         => $provider ? $provider : get_post_meta( $checkup_id, '_checkup_provider', true ),
				'location'         => $location ? $location : get_post_meta( $checkup_id, '_checkup_location', true ),
				'type'             => $type ? $type : get_post_meta( $checkup_id, '_checkup_type', true ),
				'status'           => $status ? $status : get_post_meta( $checkup_id, '_checkup_status', true ),
				'chief_complaint'  => $chief_complaint ? $chief_complaint : get_post_meta( $checkup_id, '_checkup_chief_complaint', true ),
				'diagnosis'        => $diagnosis ? $diagnosis : get_post_meta( $checkup_id, '_checkup_diagnosis', true ),
				'duration_minutes' => $duration ? $duration : get_post_meta( $checkup_id, '_checkup_duration_minutes', true ),
				'follow_up_date'   => $follow_up_date ? $follow_up_date : get_post_meta( $checkup_id, '_checkup_follow_up_date', true ),
				'copay_amount'     => $copay_amount ? $copay_amount : get_post_meta( $checkup_id, '_checkup_copay_amount', true ),
				'updated_at'       => current_time( 'mysql' ),
			),
		);
	}

	/**
	 * Validate datetime format (YYYY-MM-DD HH:MM).
	 *
	 * @param string $datetime Datetime string.
	 * @return bool
	 */
	private function validate_datetime( $datetime ) {
		$d = DateTime::createFromFormat( 'Y-m-d H:i', $datetime );
		return $d && $d->format( 'Y-m-d H:i' ) === $datetime;
	}
}
