<?php
/**
 * Tool for getting member health summary.
 *
 * Retrieves a comprehensive health summary for a member including allergies,
 * active prescriptions, upcoming checkups, and recent medical records.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets a comprehensive health summary for a member.
 */
class WP_MCP_AI_Tool_Get_Member_Health_Summary implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_member_health_summary';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Member Health Summary', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a comprehensive health summary for a member including demographics, allergies, active prescriptions, upcoming checkups, and recent medical records. Provides an at-a-glance health overview inspired by AI health platforms like Claude Health and ChatGPT Health.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'include_records' => array(
					'type'        => 'boolean',
					'description' => __( 'Include recent medical records (optional, default: true)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'             => array( 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read' );
	}

	/**
	 * Check if the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		// Health and Wellness management is a Pro feature.
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

		if ( ! $current_user_id || ! user_can( $current_user_id, 'read' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view member health summaries.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id       = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$include_records = isset( $arguments['include_records'] ) ? (bool) $arguments['include_records'] : true;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Get member type.
		$types       = wp_get_object_terms( $member_id, 'mcp_ai_member_type', array( 'fields' => 'slugs' ) );
		$member_type = ! empty( $types ) && ! is_wp_error( $types ) ? $types[0] : 'person';

		// Build member demographics.
		$demographics = array(
			'id'                => $member_id,
			'name'              => $member->post_title,
			'type'              => $member_type,
			'date_of_birth'     => get_post_meta( $member_id, '_member_date_of_birth', true ),
			'gender'            => get_post_meta( $member_id, '_member_gender', true ),
			'blood_type'        => get_post_meta( $member_id, '_member_blood_type', true ),
			'email'             => get_post_meta( $member_id, '_member_email', true ),
			'phone'             => get_post_meta( $member_id, '_member_phone', true ),
			'emergency_contact' => get_post_meta( $member_id, '_member_emergency_contact', true ),
		);

		// Add pet-specific fields.
		if ( 'pet' === $member_type ) {
			$demographics['species'] = get_post_meta( $member_id, '_pet_species', true );
			$demographics['breed']   = get_post_meta( $member_id, '_pet_breed', true );
		}

		// Get allergies.
		$allergies_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_allergy',
				'post_status'    => 'publish',
				'meta_key'       => '_allergy_member_id',
				'meta_value'     => $member_id,
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$allergies = array();
		if ( $allergies_query->have_posts() ) {
			while ( $allergies_query->have_posts() ) {
				$allergies_query->the_post();
				$allergy_id     = get_the_ID();
				$severity_terms = wp_get_object_terms( $allergy_id, 'mcp_ai_allergy_severity', array( 'fields' => 'names' ) );
				$allergies[]    = array(
					'id'       => $allergy_id,
					'allergen' => get_the_title(),
					'severity' => ! empty( $severity_terms ) && ! is_wp_error( $severity_terms ) ? $severity_terms[0] : '',
					'reaction' => get_post_meta( $allergy_id, '_allergy_reaction', true ),
				);
			}
			wp_reset_postdata();
		}

		// Get active prescriptions.
		$prescriptions_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_prescription',
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => '_prescription_member_id',
						'value' => $member_id,
					),
					array(
						'key'     => '_prescription_end_date',
						'value'   => current_time( 'Y-m-d' ),
						'compare' => '>=',
						'type'    => 'DATE',
					),
				),
				'posts_per_page' => -1,
				'orderby'        => 'title',
				'order'          => 'ASC',
			)
		);

		$prescriptions = array();
		if ( $prescriptions_query->have_posts() ) {
			while ( $prescriptions_query->have_posts() ) {
				$prescriptions_query->the_post();
				$prescription_id = get_the_ID();
				$prescriptions[] = array(
					'id'         => $prescription_id,
					'medication' => get_the_title(),
					'dosage'     => get_post_meta( $prescription_id, '_prescription_dosage', true ),
					'frequency'  => get_post_meta( $prescription_id, '_prescription_frequency', true ),
					'start_date' => get_post_meta( $prescription_id, '_prescription_start_date', true ),
					'end_date'   => get_post_meta( $prescription_id, '_prescription_end_date', true ),
				);
			}
			wp_reset_postdata();
		}

		// Get upcoming checkups (next 90 days).
		$upcoming_date  = gmdate( 'Y-m-d', strtotime( '+90 days' ) );
		$checkups_query = new WP_Query(
			array(
				'post_type'      => 'mcp_ai_checkup',
				'post_status'    => 'publish',
				'meta_query'     => array(
					array(
						'key'   => '_checkup_member_id',
						'value' => $member_id,
					),
					array(
						'key'     => '_checkup_date',
						'value'   => array( current_time( 'Y-m-d' ), $upcoming_date ),
						'compare' => 'BETWEEN',
						'type'    => 'DATE',
					),
				),
				'meta_key'       => '_checkup_date',
				'orderby'        => 'meta_value',
				'order'          => 'ASC',
				'posts_per_page' => 10,
			)
		);

		$upcoming_checkups = array();
		if ( $checkups_query->have_posts() ) {
			while ( $checkups_query->have_posts() ) {
				$checkups_query->the_post();
				$checkup_id          = get_the_ID();
				$upcoming_checkups[] = array(
					'id'       => $checkup_id,
					'title'    => get_the_title(),
					'date'     => get_post_meta( $checkup_id, '_checkup_date', true ),
					'time'     => get_post_meta( $checkup_id, '_checkup_time', true ),
					'provider' => get_post_meta( $checkup_id, '_checkup_provider', true ),
					'location' => get_post_meta( $checkup_id, '_checkup_location', true ),
				);
			}
			wp_reset_postdata();
		}

		// Build response.
		$response = array(
			'success'              => true,
			'member'               => $demographics,
			'allergies'            => $allergies,
			'active_prescriptions' => $prescriptions,
			'upcoming_checkups'    => $upcoming_checkups,
		);

		// Get recent medical records if requested.
		if ( $include_records ) {
			$records_query = new WP_Query(
				array(
					'post_type'      => 'mcp_ai_medical_record',
					'post_status'    => 'publish',
					'meta_key'       => '_record_member_id',
					'meta_value'     => $member_id,
					'posts_per_page' => 10,
					'orderby'        => 'date',
					'order'          => 'DESC',
				)
			);

			$recent_records = array();
			if ( $records_query->have_posts() ) {
				while ( $records_query->have_posts() ) {
					$records_query->the_post();
					$record_id        = get_the_ID();
					$record_types     = wp_get_object_terms( $record_id, 'mcp_ai_record_type', array( 'fields' => 'names' ) );
					$recent_records[] = array(
						'id'          => $record_id,
						'title'       => get_the_title(),
						'type'        => ! empty( $record_types ) && ! is_wp_error( $record_types ) ? $record_types[0] : '',
						'date'        => get_post_meta( $record_id, '_record_date', true ),
						'provider'    => get_post_meta( $record_id, '_record_provider', true ),
						'description' => wp_trim_words( get_the_content(), 30 ),
					);
				}
				wp_reset_postdata();
			}

			$response['recent_medical_records'] = $recent_records;
		}

		return $response;
	}
}
