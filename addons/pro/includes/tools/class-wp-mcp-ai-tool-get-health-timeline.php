<?php
/**
 * Tool for getting health timeline for a member.
 *
 * Provides chronological view of all health events (checkups, medical records, prescriptions, allergies).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gets chronological health timeline for a member.
 */
class WP_MCP_AI_Tool_Get_Health_Timeline implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_health_timeline';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Health Timeline', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a chronological timeline of all health events for a member, including checkups, medical records, prescriptions, and allergies. Useful for getting a complete health history overview.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'member_id'  => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to get health timeline for (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'start_date' => array(
					'type'        => 'string',
					'description' => __( 'Start date for timeline (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'   => array(
					'type'        => 'string',
					'description' => __( 'End date for timeline (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of events to return (optional, default: 50, max: 200)', 'mcp-ai-wpoos-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to view health timelines.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate inputs.
		$member_id  = isset( $arguments['member_id'] ) ? absint( $arguments['member_id'] ) : 0;
		$start_date = isset( $arguments['start_date'] ) ? sanitize_text_field( $arguments['start_date'] ) : '';
		$end_date   = isset( $arguments['end_date'] ) ? sanitize_text_field( $arguments['end_date'] ) : '';
		$limit      = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;

		if ( ! $member_id ) {
			return new WP_Error( 'wp_mcp_ai_missing_member_id', __( 'Member ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate dates.
		if ( $start_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $start_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_start_date', __( 'Start date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( $end_date && ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $end_date ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_end_date', __( 'End date must be in YYYY-MM-DD format.', 'mcp-ai-wpoos-pro' ) );
		}

		// Validate limit.
		if ( $limit < 1 ) {
			$limit = 50;
		}
		if ( $limit > 200 ) {
			$limit = 200;
		}

		// Verify member exists.
		$member = get_post( $member_id );
		if ( ! $member || 'mcp_ai_member' !== $member->post_type ) {
			return new WP_Error( 'wp_mcp_ai_member_not_found', __( 'Member not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Collect all health events.
		$events = array();

		// Get checkups.
		$checkup_args = array(
			'post_type'      => 'mcp_ai_checkup',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_query'     => array(
				array(
					'key'   => '_checkup_member_id',
					'value' => $member_id,
				),
			),
		);

		if ( $start_date || $end_date ) {
			$date_query = array( 'relation' => 'AND' );
			if ( $start_date ) {
				$date_query[] = array(
					'key'     => '_checkup_date',
					'value'   => $start_date,
					'compare' => '>=',
					'type'    => 'DATE',
				);
			}
			if ( $end_date ) {
				$date_query[] = array(
					'key'     => '_checkup_date',
					'value'   => $end_date,
					'compare' => '<=',
					'type'    => 'DATE',
				);
			}
			$checkup_args['meta_query'][] = $date_query;
		}

		$checkups = get_posts( $checkup_args );
		foreach ( $checkups as $checkup ) {
			$date     = get_post_meta( $checkup->ID, '_checkup_date', true );
			$events[] = array(
				'type'     => 'checkup',
				'id'       => $checkup->ID,
				'date'     => $date,
				'title'    => $checkup->post_title,
				'provider' => get_post_meta( $checkup->ID, '_checkup_provider', true ),
				'status'   => get_post_meta( $checkup->ID, '_checkup_status', true ),
			);
		}

		// Get medical records.
		$record_args = array(
			'post_type'      => 'mcp_ai_medical_record',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_query'     => array(
				array(
					'key'   => '_medical_record_member_id',
					'value' => $member_id,
				),
			),
		);

		if ( $start_date || $end_date ) {
			$date_query = array( 'relation' => 'AND' );
			if ( $start_date ) {
				$date_query[] = array(
					'key'     => '_medical_record_date',
					'value'   => $start_date,
					'compare' => '>=',
					'type'    => 'DATE',
				);
			}
			if ( $end_date ) {
				$date_query[] = array(
					'key'     => '_medical_record_date',
					'value'   => $end_date,
					'compare' => '<=',
					'type'    => 'DATE',
				);
			}
			$record_args['meta_query'][] = $date_query;
		}

		$records = get_posts( $record_args );
		foreach ( $records as $record ) {
			$date     = get_post_meta( $record->ID, '_medical_record_date', true );
			$types    = wp_get_post_terms( $record->ID, 'mcp_ai_record_type', array( 'fields' => 'names' ) );
			$events[] = array(
				'type'        => 'medical_record',
				'id'          => $record->ID,
				'date'        => $date,
				'title'       => $record->post_title,
				'record_type' => ! empty( $types ) ? $types[0] : '',
				'provider'    => get_post_meta( $record->ID, '_medical_record_provider', true ),
			);
		}

		// Get prescriptions.
		$prescription_args = array(
			'post_type'      => 'mcp_ai_prescription',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_query'     => array(
				array(
					'key'   => '_prescription_member_id',
					'value' => $member_id,
				),
			),
		);

		if ( $start_date || $end_date ) {
			$date_query = array( 'relation' => 'AND' );
			if ( $start_date ) {
				$date_query[] = array(
					'key'     => '_prescription_start_date',
					'value'   => $start_date,
					'compare' => '>=',
					'type'    => 'DATE',
				);
			}
			if ( $end_date ) {
				$date_query[] = array(
					'key'     => '_prescription_start_date',
					'value'   => $end_date,
					'compare' => '<=',
					'type'    => 'DATE',
				);
			}
			$prescription_args['meta_query'][] = $date_query;
		}

		$prescriptions = get_posts( $prescription_args );
		foreach ( $prescriptions as $prescription ) {
			$start_date_val = get_post_meta( $prescription->ID, '_prescription_start_date', true );
			$events[]       = array(
				'type'       => 'prescription',
				'id'         => $prescription->ID,
				'date'       => $start_date_val,
				'medication' => $prescription->post_title,
				'dosage'     => get_post_meta( $prescription->ID, '_prescription_dosage', true ),
				'prescriber' => get_post_meta( $prescription->ID, '_prescription_prescriber', true ),
			);
		}

		// Get allergies.
		$allergy_args = array(
			'post_type'      => 'mcp_ai_allergy',
			'post_status'    => 'publish',
			'posts_per_page' => $limit,
			'meta_query'     => array(
				array(
					'key'   => '_allergy_member_id',
					'value' => $member_id,
				),
			),
		);

		if ( $start_date || $end_date ) {
			$date_query = array( 'relation' => 'AND' );
			if ( $start_date ) {
				$date_query[] = array(
					'key'     => '_allergy_diagnosed_date',
					'value'   => $start_date,
					'compare' => '>=',
					'type'    => 'DATE',
				);
			}
			if ( $end_date ) {
				$date_query[] = array(
					'key'     => '_allergy_diagnosed_date',
					'value'   => $end_date,
					'compare' => '<=',
					'type'    => 'DATE',
				);
			}
			$allergy_args['meta_query'][] = $date_query;
		}

		$allergies = get_posts( $allergy_args );
		foreach ( $allergies as $allergy ) {
			$diagnosed_date = get_post_meta( $allergy->ID, '_allergy_diagnosed_date', true );
			$events[]       = array(
				'type'         => 'allergy',
				'id'           => $allergy->ID,
				'date'         => $diagnosed_date ? $diagnosed_date : $allergy->post_date,
				'allergen'     => $allergy->post_title,
				'severity'     => get_post_meta( $allergy->ID, '_allergy_severity', true ),
				'allergy_type' => get_post_meta( $allergy->ID, '_allergy_type', true ),
			);
		}

		// Sort events by date (most recent first).
		usort(
			$events,
			function ( $a, $b ) {
				$date_a = isset( $a['date'] ) ? $a['date'] : '';
				$date_b = isset( $b['date'] ) ? $b['date'] : '';
				return strcmp( $date_b, $date_a );  // Descending order.
			}
		);

		// Apply limit.
		if ( count( $events ) > $limit ) {
			$events = array_slice( $events, 0, $limit );
		}

		return array(
			'success'      => true,
			'member_id'    => $member_id,
			'member_name'  => $member->post_title,
			'start_date'   => $start_date,
			'end_date'     => $end_date,
			'total_events' => count( $events ),
			'events'       => $events,
		);
	}
}
