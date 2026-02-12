<?php
/**
 * Tool for tracking vaccination history and schedules.
 *
 * Manages vaccination records, schedules, and compliance tracking for both humans and pets.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tracks vaccinations and immunization records.
 */
class WP_MCP_AI_Tool_Track_Vaccinations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'track_vaccinations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Track Vaccinations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Comprehensive vaccination tracking for members (humans and pets). Log vaccination history, track immunization schedules, manage boosters, and ensure compliance with healthcare requirements. Supports both person and pet vaccination protocols.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action to perform (required)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'add', 'get', 'list', 'schedule', 'check_compliance' ),
				),
				'member_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Member ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'vaccine_name'     => array(
					'type'        => 'string',
					'description' => __( 'Name of vaccine (required for add action)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'vaccine_type'     => array(
					'type'        => 'string',
					'description' => __( 'Type/category of vaccine (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'routine', 'travel', 'occupational', 'emergency', 'rabies', 'distemper', 'parvovirus', 'other' ),
				),
				'administration_date' => array(
					'type'        => 'string',
					'description' => __( 'Date vaccine was administered (YYYY-MM-DD) (required for add)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'lot_number'       => array(
					'type'        => 'string',
					'description' => __( 'Vaccine lot/batch number (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 100,
				),
				'manufacturer'     => array(
					'type'        => 'string',
					'description' => __( 'Vaccine manufacturer (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'administering_provider' => array(
					'type'        => 'string',
					'description' => __( 'Name of healthcare provider who administered (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'facility'         => array(
					'type'        => 'string',
					'description' => __( 'Facility where administered (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'site_of_administration' => array(
					'type'        => 'string',
					'description' => __( 'Body site where vaccine was given (optional)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'left_arm', 'right_arm', 'left_thigh', 'right_thigh', 'buttock', 'other' ),
				),
				'dose_number'      => array(
					'type'        => 'integer',
					'description' => __( 'Dose number in series (e.g., 1, 2, 3) (optional)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'series_complete'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether the vaccine series is complete (optional)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'booster_required' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether booster shots are required (optional)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'next_booster_date' => array(
					'type'        => 'string',
					'description' => __( 'Next booster due date (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'reaction_notes'   => array(
					'type'        => 'string',
					'description' => __( 'Notes about adverse reactions (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 2000,
				),
				'compliance_program' => array(
					'type'        => 'string',
					'description' => __( 'Compliance program to check against (optional for check_compliance)', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'cdc_routine', 'cdc_adult', 'school_entry', 'pet_boarding', 'travel_international', 'custom' ),
				),
			),
			'required'             => array( 'action', 'member_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'database-read', 'database-write', 'pii-data', 'hipaa-relevant' );
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to track vaccinations.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$action    = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : '';
		$member_id = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;

		if ( ! $action ) {
			return new WP_Error( 'wp_mcp_ai_missing_action', __( 'Action is required.', 'mcp-ai-wpoos-pro' ) );
		}

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

		// Execute based on action.
		switch ( $action ) {
			case 'add':
				return $this->add_vaccination( $arguments, $member_id, $member_type, $current_user_id );

			case 'list':
				return $this->list_vaccinations( $member_id, $member_type );

			case 'get':
				return $this->get_vaccination_details( $member_id, $member_type );

			case 'schedule':
				return $this->generate_vaccination_schedule( $member_id, $member_type );

			case 'check_compliance':
				$compliance_program = isset( $arguments['compliance_program'] ) ? sanitize_text_field( $arguments['compliance_program'] ) : 'cdc_routine';
				return $this->check_compliance( $member_id, $member_type, $compliance_program );

			default:
				return new WP_Error( 'wp_mcp_ai_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Add a vaccination record.
	 *
	 * @param array  $arguments      Tool arguments.
	 * @param int    $member_id      Member ID.
	 * @param string $member_type    Member type.
	 * @param int    $current_user_id Current user ID.
	 * @return array|WP_Error Result or error.
	 */
	private function add_vaccination( $arguments, $member_id, $member_type, $current_user_id ) {
		if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to add vaccination records.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate required fields for add.
		$vaccine_name        = isset( $arguments['vaccine_name'] ) ? sanitize_text_field( $arguments['vaccine_name'] ) : '';
		$administration_date = isset( $arguments['administration_date'] ) ? sanitize_text_field( $arguments['administration_date'] ) : '';

		if ( ! $vaccine_name ) {
			return new WP_Error( 'wp_mcp_ai_missing_vaccine_name', __( 'Vaccine name is required.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! $administration_date ) {
			return new WP_Error( 'wp_mcp_ai_missing_date', __( 'Administration date is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Store vaccination as a medical record with vaccination type.
		$vaccination_title = sprintf(
			/* translators: 1: vaccine name, 2: date */
			__( 'Vaccination: %1$s (%2$s)', 'mcp-ai-wpoos-pro' ),
			$vaccine_name,
			$administration_date
		);

		$vaccination_content = '';
		if ( isset( $arguments['administering_provider'] ) ) {
			$vaccination_content .= '<p><strong>' . __( 'Provider:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $arguments['administering_provider'] ) . '</p>';
		}
		if ( isset( $arguments['facility'] ) ) {
			$vaccination_content .= '<p><strong>' . __( 'Facility:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $arguments['facility'] ) . '</p>';
		}
		if ( isset( $arguments['lot_number'] ) ) {
			$vaccination_content .= '<p><strong>' . __( 'Lot Number:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $arguments['lot_number'] ) . '</p>';
		}
		if ( isset( $arguments['manufacturer'] ) ) {
			$vaccination_content .= '<p><strong>' . __( 'Manufacturer:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $arguments['manufacturer'] ) . '</p>';
		}
		if ( isset( $arguments['reaction_notes'] ) && ! empty( $arguments['reaction_notes'] ) ) {
			$vaccination_content .= '<p><strong>' . __( 'Reactions/Notes:', 'mcp-ai-wpoos-pro' ) . '</strong> ' . esc_html( $arguments['reaction_notes'] ) . '</p>';
		}

		// Create medical record.
		$record_id = wp_insert_post(
			array(
				'post_type'    => 'mcp_ai_med_record',
				'post_title'   => $vaccination_title,
				'post_content' => $vaccination_content,
				'post_status'  => 'publish',
				'post_author'  => $current_user_id,
			)
		);

		if ( is_wp_error( $record_id ) ) {
			return $record_id;
		}

		// Store vaccination-specific metadata.
		update_post_meta( $record_id, '_record_member_id', $member_id );
		update_post_meta( $record_id, '_record_date', $administration_date );
		update_post_meta( $record_id, '_vaccination_name', $vaccine_name );
		update_post_meta( $record_id, '_is_vaccination', true );

		if ( isset( $arguments['vaccine_type'] ) ) {
			update_post_meta( $record_id, '_vaccination_type', sanitize_text_field( $arguments['vaccine_type'] ) );
		}
		if ( isset( $arguments['lot_number'] ) ) {
			update_post_meta( $record_id, '_vaccination_lot_number', sanitize_text_field( $arguments['lot_number'] ) );
		}
		if ( isset( $arguments['manufacturer'] ) ) {
			update_post_meta( $record_id, '_vaccination_manufacturer', sanitize_text_field( $arguments['manufacturer'] ) );
		}
		if ( isset( $arguments['administering_provider'] ) ) {
			update_post_meta( $record_id, '_record_provider', sanitize_text_field( $arguments['administering_provider'] ) );
		}
		if ( isset( $arguments['facility'] ) ) {
			update_post_meta( $record_id, '_vaccination_facility', sanitize_text_field( $arguments['facility'] ) );
		}
		if ( isset( $arguments['site_of_administration'] ) ) {
			update_post_meta( $record_id, '_vaccination_site', sanitize_text_field( $arguments['site_of_administration'] ) );
		}
		if ( isset( $arguments['dose_number'] ) ) {
			update_post_meta( $record_id, '_vaccination_dose_number', absint( $arguments['dose_number'] ) );
		}
		if ( isset( $arguments['series_complete'] ) ) {
			update_post_meta( $record_id, '_vaccination_series_complete', (bool) $arguments['series_complete'] );
		}
		if ( isset( $arguments['booster_required'] ) ) {
			update_post_meta( $record_id, '_vaccination_booster_required', (bool) $arguments['booster_required'] );
		}
		if ( isset( $arguments['next_booster_date'] ) ) {
			update_post_meta( $record_id, '_vaccination_next_booster_date', sanitize_text_field( $arguments['next_booster_date'] ) );
		}

		// Set vaccination taxonomy term.
		wp_set_object_terms( $record_id, 'vaccination', 'mcp_ai_record_type' );

		return array(
			'success'            => true,
			'message'            => __( 'Vaccination record added successfully.', 'mcp-ai-wpoos-pro' ),
			'record_id'          => $record_id,
			'member_id'          => $member_id,
			'vaccine_name'       => $vaccine_name,
			'administration_date' => $administration_date,
		);
	}

	/**
	 * List all vaccinations for a member.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Vaccination list.
	 */
	private function list_vaccinations( $member_id, $member_type ) {
		// Query vaccination records.
		$args = array(
			'post_type'      => 'mcp_ai_med_record',
			'post_status'    => 'publish',
			'meta_query'     => array(
				array(
					'key'   => '_record_member_id',
					'value' => $member_id,
				),
				array(
					'key'   => '_is_vaccination',
					'value' => true,
				),
			),
			'posts_per_page' => -1,
			'orderby'        => 'meta_value',
			'meta_key'       => '_record_date',
			'order'          => 'DESC',
		);

		$query        = new WP_Query( $args );
		$vaccinations = array();

		if ( $query->have_posts() ) {
			while ( $query->have_posts() ) {
				$query->the_post();
				$record_id           = get_the_ID();
				$vaccinations[] = array(
					'record_id'          => $record_id,
					'vaccine_name'       => get_post_meta( $record_id, '_vaccination_name', true ),
					'administration_date' => get_post_meta( $record_id, '_record_date', true ),
					'vaccine_type'       => get_post_meta( $record_id, '_vaccination_type', true ),
					'lot_number'         => get_post_meta( $record_id, '_vaccination_lot_number', true ),
					'manufacturer'       => get_post_meta( $record_id, '_vaccination_manufacturer', true ),
					'provider'           => get_post_meta( $record_id, '_record_provider', true ),
					'dose_number'        => get_post_meta( $record_id, '_vaccination_dose_number', true ),
					'series_complete'    => (bool) get_post_meta( $record_id, '_vaccination_series_complete', true ),
					'booster_required'   => (bool) get_post_meta( $record_id, '_vaccination_booster_required', true ),
					'next_booster_date'  => get_post_meta( $record_id, '_vaccination_next_booster_date', true ),
				);
			}
			wp_reset_postdata();
		}

		return array(
			'success'        => true,
			'member_id'      => $member_id,
			'member_type'    => $member_type,
			'total_count'    => count( $vaccinations ),
			'vaccinations'   => $vaccinations,
		);
	}

	/**
	 * Get comprehensive vaccination details.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Vaccination details.
	 */
	private function get_vaccination_details( $member_id, $member_type ) {
		$vaccinations_result = $this->list_vaccinations( $member_id, $member_type );

		// Add analysis.
		$boosters_due = array();
		$completed_series = 0;
		$incomplete_series = 0;

		foreach ( $vaccinations_result['vaccinations'] as $vacc ) {
			if ( $vacc['series_complete'] ) {
				++$completed_series;
			} else {
				++$incomplete_series;
			}

			if ( $vacc['next_booster_date'] ) {
				$booster_date = strtotime( $vacc['next_booster_date'] );
				$now          = current_time( 'timestamp' );
				if ( $booster_date <= $now + ( 90 * DAY_IN_SECONDS ) ) { // Due within 90 days.
					$boosters_due[] = array(
						'vaccine_name' => $vacc['vaccine_name'],
						'due_date'     => $vacc['next_booster_date'],
						'days_until'   => floor( ( $booster_date - $now ) / DAY_IN_SECONDS ),
					);
				}
			}
		}

		return array(
			'success'           => true,
			'member_id'         => $member_id,
			'member_type'       => $member_type,
			'total_vaccinations' => $vaccinations_result['total_count'],
			'completed_series'  => $completed_series,
			'incomplete_series' => $incomplete_series,
			'boosters_due'      => $boosters_due,
			'vaccinations'      => $vaccinations_result['vaccinations'],
		);
	}

	/**
	 * Generate recommended vaccination schedule.
	 *
	 * @param int    $member_id   Member ID.
	 * @param string $member_type Member type.
	 * @return array Vaccination schedule.
	 */
	private function generate_vaccination_schedule( $member_id, $member_type ) {
		// This would ideally integrate with CDC/WHO guidelines or veterinary protocols.
		$schedule = array();

		if ( 'person' === $member_type ) {
			$schedule = array(
				array(
					'vaccine'      => 'Influenza',
					'frequency'    => 'Annual',
					'recommended'  => true,
					'notes'        => __( 'Recommended annually before flu season', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'vaccine'      => 'COVID-19',
					'frequency'    => 'As recommended',
					'recommended'  => true,
					'notes'        => __( 'Follow current public health guidelines', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'vaccine'      => 'Tdap/Td',
					'frequency'    => 'Every 10 years',
					'recommended'  => true,
					'notes'        => __( 'Tetanus, diphtheria, pertussis booster', 'mcp-ai-wpoos-pro' ),
				),
			);
		} else { // Pet.
			$schedule = array(
				array(
					'vaccine'      => 'Rabies',
					'frequency'    => 'Annual or 3-year',
					'recommended'  => true,
					'notes'        => __( 'Required by law in most areas', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'vaccine'      => 'DHPP',
					'frequency'    => 'Every 1-3 years',
					'recommended'  => true,
					'notes'        => __( 'Distemper, hepatitis, parvovirus, parainfluenza', 'mcp-ai-wpoos-pro' ),
				),
				array(
					'vaccine'      => 'Bordetella',
					'frequency'    => 'Every 6-12 months',
					'recommended'  => false,
					'notes'        => __( 'Recommended for dogs in boarding or social settings', 'mcp-ai-wpoos-pro' ),
				),
			);
		}

		return array(
			'success'            => true,
			'member_id'          => $member_id,
			'member_type'        => $member_type,
			'vaccination_schedule' => $schedule,
			'note'               => __( 'Consult with a qualified healthcare provider or veterinarian for personalized vaccination recommendations.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Check vaccination compliance against a program.
	 *
	 * @param int    $member_id         Member ID.
	 * @param string $member_type       Member type.
	 * @param string $compliance_program Compliance program.
	 * @return array Compliance check result.
	 */
	private function check_compliance( $member_id, $member_type, $compliance_program ) {
		$vaccinations_result = $this->list_vaccinations( $member_id, $member_type );
		$vaccinations        = $vaccinations_result['vaccinations'];

		// Define compliance requirements (simplified).
		$requirements = array();
		$is_compliant = false;
		$missing      = array();

		// This is a simplified example. Real implementation would need comprehensive rules.
		if ( 'school_entry' === $compliance_program && 'person' === $member_type ) {
			$requirements = array( 'MMR', 'DTaP', 'Polio', 'Varicella', 'Hepatitis B' );
		} elseif ( 'pet_boarding' === $compliance_program && 'pet' === $member_type ) {
			$requirements = array( 'Rabies', 'DHPP', 'Bordetella' );
		}

		// Check which required vaccines are present.
		$vaccine_names = array_column( $vaccinations, 'vaccine_name' );

		foreach ( $requirements as $required_vaccine ) {
			$found = false;
			foreach ( $vaccine_names as $vaccine_name ) {
				if ( false !== stripos( $vaccine_name, $required_vaccine ) ) {
					$found = true;
					break;
				}
			}
			if ( ! $found ) {
				$missing[] = $required_vaccine;
			}
		}

		$is_compliant = empty( $missing );

		return array(
			'success'            => true,
			'member_id'          => $member_id,
			'member_type'        => $member_type,
			'compliance_program' => $compliance_program,
			'is_compliant'       => $is_compliant,
			'required_vaccines'  => $requirements,
			'missing_vaccines'   => $missing,
			'recorded_vaccines'  => count( $vaccinations ),
		);
	}
}
