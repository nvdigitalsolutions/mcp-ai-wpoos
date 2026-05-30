<?php
/**
 * Tool for updating ECAs.
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
 * Updates an existing ECA.
 */
class WP_MCP_AI_Tool_Update_ECA implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_eca';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update ECA', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates an existing Extra-Curricular Activity. Provide only the fields you want to update. Tracks all changes in an audit trail and automatically adjusts capacity status.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'eca_id'            => array(
					'type'        => 'integer',
					'description' => __( 'ECA ID to update (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'name'              => array(
					'type'        => 'string',
					'description' => __( 'New ECA name', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'eca_code'          => array(
					'type'        => 'string',
					'description' => __( 'New ECA code', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'description'       => array(
					'type'        => 'string',
					'description' => __( 'New description', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 10000,
				),
				'eca_type'          => array(
					'type'        => 'string',
					'description' => __( 'New ECA type', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'club', 'society', 'sport_squad', 'sport_academy', 'activity' ),
				),
				'day'               => array(
					'type'        => 'string',
					'description' => __( 'New day of the week', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday', 'Sunday' ),
				),
				'start_time'        => array(
					'type'        => 'string',
					'description' => __( 'New start time (HH:MM AM/PM)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'end_time'          => array(
					'type'        => 'string',
					'description' => __( 'New end time (HH:MM AM/PM)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{1,2}:\d{2}\s?(AM|PM|am|pm)$',
				),
				'venue'             => array(
					'type'        => 'string',
					'description' => __( 'New venue/location', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'year_groups'       => array(
					'type'        => 'array',
					'description' => __( 'New eligible year groups', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'max_students'      => array(
					'type'        => 'integer',
					'description' => __( 'New maximum students', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'teachers'          => array(
					'type'        => 'array',
					'description' => __( 'New teacher names', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
				),
				'is_paid'           => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this ECA requires payment', 'mcp-ai-wpoos-pro' ),
				),
				'cost'              => array(
					'type'        => 'number',
					'description' => __( 'New cost', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'cost_period'       => array(
					'type'        => 'string',
					'description' => __( 'Cost billing period', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'term', 'month', 'session', 'year' ),
				),
				'currency'          => array(
					'type'        => 'string',
					'description' => __( 'New currency code', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'GBP', 'USD', 'EUR', 'AED', 'INR', 'AUD', 'CAD', 'SGD', 'ZAR' ),
				),
				'term'              => array(
					'type'        => 'string',
					'description' => __( 'New term', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'Term 1', 'Term 2', 'Term 3', 'Yearly' ),
				),
				'requires_audition' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether this ECA requires an audition/tryout', 'mcp-ai-wpoos-pro' ),
				),
				'booking_type'      => array(
					'type'        => 'string',
					'description' => __( 'Enrollment booking method', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'first_come_first_served', 'preference_based', 'preselected', 'signup' ),
				),
				'status'            => array(
					'type'        => 'string',
					'description' => __( 'ECA status', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'active', 'inactive', 'full', 'cancelled' ),
				),
			),
			'required'             => array( 'eca_id' ),
			'additionalProperties' => false,
		);
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
			'toolkit'               => 'education',
			'post_type'             => 'mcp_ai_eca',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'educator', 'school_admin' ),
			'risk_level'            => 'standard',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-write' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		return ! empty( $settings['enable_eca_management'] );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to update ECAs.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca_id = isset( $arguments['eca_id'] ) ? absint( $arguments['eca_id'] ) : 0;

		if ( ! $eca_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_id', __( 'ECA ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$eca = get_post( $eca_id );

		if ( ! $eca || 'mcp_ai_eca' !== $eca->post_type ) {
			return new WP_Error( 'wp_mcp_ai_invalid_eca', __( 'Invalid ECA ID.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate max_students against current enrollment.
		if ( isset( $arguments['max_students'] ) ) {
			$current_enrollment = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
			if ( absint( $arguments['max_students'] ) < $current_enrollment ) {
				return new WP_Error(
					'wp_mcp_ai_capacity_conflict',
					sprintf(
						/* translators: %d: current enrollment count */
						__( 'Cannot set max students below current enrollment count (%d).', 'mcp-ai-wpoos-pro' ),
						$current_enrollment
					)
				);
			}
		}

		// Track changes for audit trail.
		$changes = array();

		// Update post data if provided.
		$post_data = array( 'ID' => $eca_id );

		if ( isset( $arguments['name'] ) ) {
			$old_value               = $eca->post_title;
			$new_value               = sanitize_text_field( $arguments['name'] );
			$post_data['post_title'] = $new_value;
			if ( $old_value !== $new_value ) {
				$changes[] = array(
					'field'     => 'name',
					'old_value' => $old_value,
					'new_value' => $new_value,
				);
			}
		}

		if ( isset( $arguments['description'] ) ) {
			$old_value                 = $eca->post_content;
			$new_value                 = wp_kses_post( $arguments['description'] );
			$post_data['post_content'] = $new_value;
			if ( $old_value !== $new_value ) {
				$changes[] = array(
					'field'     => 'description',
					'old_value' => wp_trim_words( $old_value, 20 ),
					'new_value' => wp_trim_words( $new_value, 20 ),
				);
			}
		}

		if ( count( $post_data ) > 1 ) {
			$result = wp_update_post( $post_data, true );
			if ( is_wp_error( $result ) ) {
				return $result;
			}
		}

		// Update metadata fields.
		$meta_fields = array(
			'eca_code'     => '_eca_code',
			'eca_type'     => '_eca_type',
			'day'          => '_eca_day',
			'start_time'   => '_eca_start_time',
			'end_time'     => '_eca_end_time',
			'venue'        => '_eca_venue',
			'year_groups'  => '_eca_year_groups',
			'max_students' => '_eca_max_students',
			'teachers'     => '_eca_teachers',
			'cost'         => '_eca_cost',
			'cost_period'  => '_eca_cost_period',
			'currency'     => '_eca_currency',
			'term'         => '_eca_term',
			'booking_type' => '_eca_booking_type',
			'status'       => '_eca_status',
		);

		foreach ( $meta_fields as $arg_key => $meta_key ) {
			if ( isset( $arguments[ $arg_key ] ) ) {
				$old_value = get_post_meta( $eca_id, $meta_key, true );
				$value     = $arguments[ $arg_key ];

				if ( is_array( $value ) ) {
					$value = array_map( 'sanitize_text_field', $value );
				} else {
					$value = sanitize_text_field( $value );
				}

				update_post_meta( $eca_id, $meta_key, $value );

				if ( $old_value != $value ) { // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison
					$changes[] = array(
						'field'     => $arg_key,
						'old_value' => is_array( $old_value ) ? wp_json_encode( $old_value ) : (string) $old_value,
						'new_value' => is_array( $value ) ? wp_json_encode( $value ) : (string) $value,
					);
				}
			}
		}

		// Handle boolean fields.
		if ( isset( $arguments['is_paid'] ) ) {
			$old_value = get_post_meta( $eca_id, '_eca_is_paid', true );
			$new_value = $arguments['is_paid'] ? 'yes' : 'no';
			update_post_meta( $eca_id, '_eca_is_paid', $new_value );
			if ( $old_value !== $new_value ) {
				$changes[] = array(
					'field'     => 'is_paid',
					'old_value' => $old_value,
					'new_value' => $new_value,
				);
			}
		}

		if ( isset( $arguments['requires_audition'] ) ) {
			$old_value = get_post_meta( $eca_id, '_eca_requires_audition', true );
			$new_value = $arguments['requires_audition'] ? 'yes' : 'no';
			update_post_meta( $eca_id, '_eca_requires_audition', $new_value );
			if ( $old_value !== $new_value ) {
				$changes[] = array(
					'field'     => 'requires_audition',
					'old_value' => $old_value,
					'new_value' => $new_value,
				);
			}
		}

		// Auto-adjust status when max_students changes.
		if ( isset( $arguments['max_students'] ) ) {
			$current_enrollment = absint( get_post_meta( $eca_id, '_eca_current_enrollment', true ) );
			$new_max            = absint( $arguments['max_students'] );
			$current_status     = get_post_meta( $eca_id, '_eca_status', true );

			if ( 'full' === $current_status && $new_max > $current_enrollment ) {
				update_post_meta( $eca_id, '_eca_status', 'active' );
				$changes[] = array(
					'field'     => 'status',
					'old_value' => 'full',
					'new_value' => 'active',
				);
			} elseif ( 'active' === $current_status && $new_max > 0 && $current_enrollment >= $new_max ) {
				update_post_meta( $eca_id, '_eca_status', 'full' );
				$changes[] = array(
					'field'     => 'status',
					'old_value' => 'active',
					'new_value' => 'full',
				);
			}
		}

		// Record audit trail.
		if ( ! empty( $changes ) ) {
			$history = get_post_meta( $eca_id, '_eca_change_history', true );
			if ( ! is_array( $history ) ) {
				$history = array();
			}

			$history[] = array(
				'timestamp' => current_time( 'mysql' ),
				'user_id'   => $current_user_id,
				'changes'   => $changes,
			);

			// Keep last 100 entries.
			if ( count( $history ) > 100 ) {
				$history = array_slice( $history, -100 );
			}

			update_post_meta( $eca_id, '_eca_change_history', $history );
		}

		return array(
			'success'        => true,
			'message'        => __( 'ECA updated successfully.', 'mcp-ai-wpoos-pro' ),
			'eca_id'         => $eca_id,
			'fields_changed' => count( $changes ),
			'changes'        => $changes,
		);
	}
}
