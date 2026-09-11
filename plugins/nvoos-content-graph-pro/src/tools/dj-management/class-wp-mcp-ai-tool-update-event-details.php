<?php
/**
 * DJ-management tool batch (ecosystem port - Wave F2, dj-management toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/dj-management/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no path
 * constants in the gated batch - the D8-compat interface copies resolve via the entry's spl autoloader; the
 * import tool's `NVOOS_CONTENT_GRAPH_PRO_PATH` refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` with the `src/` root).
 *
 * Tool for updating event details.
 *
 * Allows AI assistants to update existing DJ event booking information.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Updates DJ event booking details.
 */
class WP_MCP_AI_Tool_Update_Event_Details implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'update_event_details';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Update Event Details', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Updates existing DJ event booking details. Modify event information, client details, pricing, and status.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Booking ID to update (required)', 'nvoos-content-graph-pro' ),
				),
				'event_name'     => array(
					'type'        => 'string',
					'description' => __( 'Event name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'event_date'     => array(
					'type'        => 'string',
					'description' => __( 'Event date in ISO 8601 format (YYYY-MM-DD) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'start_time'     => array(
					'type'        => 'string',
					'description' => __( 'Event start time in 24-hour format (HH:MM) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'end_time'       => array(
					'type'        => 'string',
					'description' => __( 'Event end time in 24-hour format (HH:MM) (optional)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^([01]\d|2[0-3]):([0-5]\d)$',
				),
				'venue_name'     => array(
					'type'        => 'string',
					'description' => __( 'Venue name (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'venue_address'  => array(
					'type'        => 'string',
					'description' => __( 'Venue address (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'client_phone'   => array(
					'type'        => 'string',
					'description' => __( 'Client phone number (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 20,
				),
				'guest_count'    => array(
					'type'        => 'integer',
					'description' => __( 'Expected number of guests (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'package'        => array(
					'type'        => 'string',
					'description' => __( 'Service package (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'basic', 'standard', 'premium', 'custom' ),
				),
				'total_price'    => array(
					'type'        => 'number',
					'description' => __( 'Total price (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'deposit'        => array(
					'type'        => 'number',
					'description' => __( 'Deposit amount (optional)', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'booking_status' => array(
					'type'        => 'string',
					'description' => __( 'Booking status (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'pending', 'confirmed', 'completed', 'cancelled' ),
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Additional notes (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'booking_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['booking_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Booking ID is required.', 'nvoos-content-graph-pro' ),
			);
		}

		$booking_id = absint( $arguments['booking_id'] );

		// Verify booking exists.
		if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid booking ID.', 'nvoos-content-graph-pro' ),
			);
		}

		$updated_fields = array();

		// Update post content if notes provided.
		if ( isset( $arguments['notes'] ) ) {
			$notes = sanitize_textarea_field( $arguments['notes'] );
			wp_update_post(
				array(
					'ID'           => $booking_id,
					'post_content' => $notes,
				)
			);
			$updated_fields[] = 'notes';
		}

		// Update metadata fields.
		$meta_fields = array(
			'event_name',
			'event_date',
			'start_time',
			'end_time',
			'venue_name',
			'venue_address',
			'client_phone',
			'guest_count',
			'package',
			'total_price',
			'deposit',
			'booking_status',
		);

		foreach ( $meta_fields as $field ) {
			if ( isset( $arguments[ $field ] ) ) {
				$value = '';
				switch ( $field ) {
					case 'guest_count':
						$value = absint( $arguments[ $field ] );
						break;
					case 'total_price':
					case 'deposit':
						$value = floatval( $arguments[ $field ] );
						break;
					default:
						$value = sanitize_text_field( $arguments[ $field ] );
						break;
				}
				update_post_meta( $booking_id, '_' . $field, $value );
				$updated_fields[] = $field;
			}
		}

		// Update modification timestamp.
		update_post_meta( $booking_id, '_last_updated', current_time( 'mysql' ) );

		// Get current booking data.
		$booking_data = array(
			'id'             => $booking_id,
			'event_name'     => get_post_meta( $booking_id, '_event_name', true ),
			'event_date'     => get_post_meta( $booking_id, '_event_date', true ),
			'start_time'     => get_post_meta( $booking_id, '_start_time', true ),
			'venue_name'     => get_post_meta( $booking_id, '_venue_name', true ),
			'client_name'    => get_post_meta( $booking_id, '_client_name', true ),
			'booking_status' => get_post_meta( $booking_id, '_booking_status', true ),
		);

		return array(
			'success'        => true,
			'message'        => __( 'Event booking updated successfully.', 'nvoos-content-graph-pro' ),
			'booking_id'     => $booking_id,
			'updated_fields' => $updated_fields,
			'booking'        => $booking_data,
		);
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
	public function get_capability_flags() {
		return array( 'write' );
	}
}
