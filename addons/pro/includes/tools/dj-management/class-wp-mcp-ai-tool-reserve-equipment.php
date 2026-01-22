<?php
/**
 * Tool for reserving equipment for events.
 *
 * Allows AI assistants to reserve DJ equipment for specific events and dates.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Reserves equipment for events.
 */
class WP_MCP_AI_Tool_Reserve_Equipment implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'reserve_equipment';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Reserve Equipment', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Reserves DJ equipment for a specific event or date range. Prevents double-booking and tracks equipment usage.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'equipment_ids'     => array(
					'type'        => 'array',
					'description' => __( 'Array of equipment IDs to reserve (required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'integer',
					),
					'minItems'    => 1,
				),
				'event_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Event ID to reserve equipment for (optional)', 'mcp-ai-wpoos-pro' ),
				),
				'event_name'        => array(
					'type'        => 'string',
					'description' => __( 'Event name if event_id not provided (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 200,
				),
				'start_date'        => array(
					'type'        => 'string',
					'description' => __( 'Reservation start date in ISO 8601 format (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'end_date'          => array(
					'type'        => 'string',
					'description' => __( 'Reservation end date in ISO 8601 format (YYYY-MM-DD) (required)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'notes'             => array(
					'type'        => 'string',
					'description' => __( 'Reservation notes (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 1000,
				),
			),
			'required'             => array( 'equipment_ids', 'start_date', 'end_date' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments, array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['equipment_ids'] ) || empty( $arguments['start_date'] ) || empty( $arguments['end_date'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Equipment IDs, start date, and end date are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Sanitize inputs.
		$equipment_ids = array_map( 'absint', (array) $arguments['equipment_ids'] );
		$event_id      = ! empty( $arguments['event_id'] ) ? absint( $arguments['event_id'] ) : 0;
		$event_name    = ! empty( $arguments['event_name'] ) ? sanitize_text_field( $arguments['event_name'] ) : '';
		$start_date    = sanitize_text_field( $arguments['start_date'] );
		$end_date      = sanitize_text_field( $arguments['end_date'] );
		$notes         = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Validate dates.
		if ( strtotime( $start_date ) > strtotime( $end_date ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Start date must be before end date.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$reserved_items = array();
		$conflicts      = array();

		foreach ( $equipment_ids as $equipment_id ) {
			// Verify equipment exists.
			if ( ! get_post( $equipment_id ) || get_post_type( $equipment_id ) !== 'dj_equipment' ) {
				$conflicts[] = array(
					'equipment_id' => $equipment_id,
					'reason'       => __( 'Invalid equipment ID', 'mcp-ai-wpoos-pro' ),
				);
				continue;
			}

			// Check for existing reservations.
			$existing_reservations = get_post_meta( $equipment_id, '_reservations', true );
			if ( ! is_array( $existing_reservations ) ) {
				$existing_reservations = array();
			}

			$has_conflict = false;
			foreach ( $existing_reservations as $reservation ) {
				if ( $this->check_date_overlap( $start_date, $end_date, $reservation['start_date'], $reservation['end_date'] ) ) {
					$conflicts[] = array(
						'equipment_id'   => $equipment_id,
						'equipment_name' => get_the_title( $equipment_id ),
						'reason'         => __( 'Date conflict with existing reservation', 'mcp-ai-wpoos-pro' ),
						'conflict_dates' => $reservation['start_date'] . ' to ' . $reservation['end_date'],
					);
					$has_conflict = true;
					break;
				}
			}

			if ( ! $has_conflict ) {
				// Add reservation.
				$reservation = array(
					'event_id'   => $event_id,
					'event_name' => $event_name,
					'start_date' => $start_date,
					'end_date'   => $end_date,
					'notes'      => $notes,
					'created_at' => current_time( 'mysql' ),
				);

				$existing_reservations[] = $reservation;
				update_post_meta( $equipment_id, '_reservations', $existing_reservations );
				update_post_meta( $equipment_id, '_status', 'in_use' );

				$reserved_items[] = array(
					'equipment_id'   => $equipment_id,
					'equipment_name' => get_the_title( $equipment_id ),
					'reservation'    => $reservation,
				);
			}
		}

		$success = count( $reserved_items ) > 0;

		return array(
			'success'        => $success,
			'message'        => $success ? __( 'Equipment reserved successfully.', 'mcp-ai-wpoos-pro' ) : __( 'No equipment could be reserved due to conflicts.', 'mcp-ai-wpoos-pro' ),
			'reserved_items' => $reserved_items,
			'conflicts'      => $conflicts,
			'reserved_count' => count( $reserved_items ),
			'conflict_count' => count( $conflicts ),
		);
	}

	/**
	 * Check if two date ranges overlap.
	 *
	 * @param string $start1 Start date of first range.
	 * @param string $end1 End date of first range.
	 * @param string $start2 Start date of second range.
	 * @param string $end2 End date of second range.
	 * @return bool True if dates overlap.
	 */
	private function check_date_overlap( $start1, $end1, $start2, $end2 ) {
		$start1_ts = strtotime( $start1 );
		$end1_ts   = strtotime( $end1 );
		$start2_ts = strtotime( $start2 );
		$end2_ts   = strtotime( $end2 );

		return ( $start1_ts <= $end2_ts ) && ( $end1_ts >= $start2_ts );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_flag_capabilities() {
		return array( 'write' );
	}
}
