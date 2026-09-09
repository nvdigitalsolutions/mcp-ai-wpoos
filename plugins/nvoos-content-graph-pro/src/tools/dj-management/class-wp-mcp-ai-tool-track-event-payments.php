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
 * Tool for tracking event payments.
 *
 * Allows AI assistants to track payment status for DJ events.
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
 * Tracks event payment status.
 */
class WP_MCP_AI_Tool_Track_Event_Payments implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'track_event_payments';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Track Event Payments', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Tracks payment status for DJ event bookings. Records deposits, payments, and outstanding balances.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Booking ID to track payment for (required)', 'nvoos-content-graph-pro' ),
				),
				'payment_amount' => array(
					'type'        => 'number',
					'description' => __( 'Payment amount (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 0,
				),
				'payment_method' => array(
					'type'        => 'string',
					'description' => __( 'Payment method (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'cash', 'check', 'credit_card', 'bank_transfer', 'paypal', 'other' ),
				),
				'payment_date'   => array(
					'type'        => 'string',
					'description' => __( 'Payment date in ISO 8601 format (YYYY-MM-DD) (optional, defaults to today)', 'nvoos-content-graph-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'payment_type'   => array(
					'type'        => 'string',
					'description' => __( 'Payment type (optional)', 'nvoos-content-graph-pro' ),
					'enum'        => array( 'deposit', 'partial', 'final', 'full' ),
				),
				'notes'          => array(
					'type'        => 'string',
					'description' => __( 'Payment notes (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
			),
			'required'             => array( 'booking_id', 'payment_amount' ),
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
		if ( empty( $arguments['booking_id'] ) || ! isset( $arguments['payment_amount'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Booking ID and payment amount are required.', 'nvoos-content-graph-pro' ),
			);
		}

		$booking_id = absint( $arguments['booking_id'] );

		if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid booking ID.', 'nvoos-content-graph-pro' ),
			);
		}

		$payment_amount = floatval( $arguments['payment_amount'] );
		$payment_method = ! empty( $arguments['payment_method'] ) ? sanitize_text_field( $arguments['payment_method'] ) : 'other';
		$payment_date   = ! empty( $arguments['payment_date'] ) ? sanitize_text_field( $arguments['payment_date'] ) : current_time( 'Y-m-d' );
		$payment_type   = ! empty( $arguments['payment_type'] ) ? sanitize_text_field( $arguments['payment_type'] ) : 'partial';
		$notes          = ! empty( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '';

		// Get existing payments.
		$payments = get_post_meta( $booking_id, '_payments', true );
		if ( ! is_array( $payments ) ) {
			$payments = array();
		}

		// Add new payment.
		$payment_record = array(
			'amount'      => $payment_amount,
			'method'      => $payment_method,
			'date'        => $payment_date,
			'type'        => $payment_type,
			'notes'       => $notes,
			'recorded_at' => current_time( 'mysql' ),
		);

		$payments[] = $payment_record;
		update_post_meta( $booking_id, '_payments', $payments );

		// Calculate totals.
		$total_paid = 0;
		foreach ( $payments as $payment ) {
			$total_paid += $payment['amount'];
		}

		$total_price = floatval( get_post_meta( $booking_id, '_total_price', true ) );
		$balance     = $total_price - $total_paid;

		// Update payment status.
		if ( $balance <= 0 ) {
			update_post_meta( $booking_id, '_payment_status', 'paid' );
		} elseif ( $total_paid > 0 ) {
			update_post_meta( $booking_id, '_payment_status', 'partial' );
		} else {
			update_post_meta( $booking_id, '_payment_status', 'pending' );
		}

		update_post_meta( $booking_id, '_total_paid', $total_paid );
		update_post_meta( $booking_id, '_balance', $balance );

		return array(
			'success'         => true,
			'message'         => __( 'Payment recorded successfully.', 'nvoos-content-graph-pro' ),
			'payment'         => $payment_record,
			'payment_summary' => array(
				'total_price' => $total_price,
				'total_paid'  => $total_paid,
				'balance'     => $balance,
				'status'      => $balance <= 0 ? 'paid' : ( $total_paid > 0 ? 'partial' : 'pending' ),
			),
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
