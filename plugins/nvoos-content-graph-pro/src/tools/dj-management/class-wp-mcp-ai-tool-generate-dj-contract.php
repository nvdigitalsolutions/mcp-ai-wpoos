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
 * Tool for generating DJ service contracts.
 *
 * Allows AI assistants to generate service contracts for DJ events.
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
 * Generates DJ service contracts.
 */
class WP_MCP_AI_Tool_Generate_DJ_Contract implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_dj_contract';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate DJ Contract', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates a professional DJ service contract with event details, terms, and conditions. Creates a formatted contract document.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'booking_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Booking ID to generate contract for (required)', 'nvoos-content-graph-pro' ),
				),
				'dj_name'             => array(
					'type'        => 'string',
					'description' => __( 'DJ or business name (required)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 200,
				),
				'dj_address'          => array(
					'type'        => 'string',
					'description' => __( 'DJ business address (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'payment_terms'       => array(
					'type'        => 'string',
					'description' => __( 'Payment terms (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 500,
				),
				'cancellation_policy' => array(
					'type'        => 'string',
					'description' => __( 'Cancellation policy (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 1000,
				),
				'additional_terms'    => array(
					'type'        => 'string',
					'description' => __( 'Additional terms and conditions (optional)', 'nvoos-content-graph-pro' ),
					'maxLength'   => 2000,
				),
			),
			'required'             => array( 'booking_id', 'dj_name' ),
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
		if ( empty( $arguments['booking_id'] ) || empty( $arguments['dj_name'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Booking ID and DJ name are required.', 'nvoos-content-graph-pro' ),
			);
		}

		$booking_id = absint( $arguments['booking_id'] );

		if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid booking ID.', 'nvoos-content-graph-pro' ),
			);
		}

		// Get booking details.
		$event_name    = get_post_meta( $booking_id, '_event_name', true );
		$event_date    = get_post_meta( $booking_id, '_event_date', true );
		$start_time    = get_post_meta( $booking_id, '_start_time', true );
		$end_time      = get_post_meta( $booking_id, '_end_time', true );
		$venue_name    = get_post_meta( $booking_id, '_venue_name', true );
		$venue_address = get_post_meta( $booking_id, '_venue_address', true );
		$client_name   = get_post_meta( $booking_id, '_client_name', true );
		$client_email  = get_post_meta( $booking_id, '_client_email', true );
		$total_price   = get_post_meta( $booking_id, '_total_price', true );
		$deposit       = get_post_meta( $booking_id, '_deposit', true );

		$dj_name             = sanitize_text_field( $arguments['dj_name'] );
		$dj_address          = ! empty( $arguments['dj_address'] ) ? sanitize_textarea_field( $arguments['dj_address'] ) : '';
		$payment_terms       = ! empty( $arguments['payment_terms'] ) ? sanitize_textarea_field( $arguments['payment_terms'] ) : '';
		$cancellation_policy = ! empty( $arguments['cancellation_policy'] ) ? sanitize_textarea_field( $arguments['cancellation_policy'] ) : '';
		$additional_terms    = ! empty( $arguments['additional_terms'] ) ? sanitize_textarea_field( $arguments['additional_terms'] ) : '';

		// Generate contract content.
		$contract = $this->build_contract(
			$dj_name,
			$dj_address,
			$client_name,
			$event_name,
			$event_date,
			$start_time,
			$end_time,
			$venue_name,
			$venue_address,
			$total_price,
			$deposit,
			$payment_terms,
			$cancellation_policy,
			$additional_terms
		);

		// Store contract.
		update_post_meta( $booking_id, '_contract', $contract );
		update_post_meta( $booking_id, '_contract_generated', current_time( 'mysql' ) );

		return array(
			'success'    => true,
			'message'    => __( 'DJ service contract generated successfully.', 'nvoos-content-graph-pro' ),
			'booking_id' => $booking_id,
			'contract'   => $contract,
		);
	}

	/**
	 * Build contract content.
	 *
	 * @param string $dj_name DJ name.
	 * @param string $dj_address DJ address.
	 * @param string $client_name Client name.
	 * @param string $event_name Event name.
	 * @param string $event_date Event date.
	 * @param string $start_time Start time.
	 * @param string $end_time End time.
	 * @param string $venue_name Venue name.
	 * @param string $venue_address Venue address.
	 * @param float  $total_price Total price.
	 * @param float  $deposit Deposit.
	 * @param string $payment_terms Payment terms.
	 * @param string $cancellation_policy Cancellation policy.
	 * @param string $additional_terms Additional terms.
	 * @return string Contract content.
	 */
	private function build_contract( $dj_name, $dj_address, $client_name, $event_name, $event_date, $start_time, $end_time, $venue_name, $venue_address, $total_price, $deposit, $payment_terms, $cancellation_policy, $additional_terms ) {
		$contract  = "DJ SERVICE AGREEMENT\n\n";
		$contract .= 'Date: ' . current_time( 'F j, Y' ) . "\n\n";

		$contract .= "SERVICE PROVIDER:\n";
		$contract .= $dj_name . "\n";
		if ( $dj_address ) {
			$contract .= $dj_address . "\n";
		}
		$contract .= "\n";

		$contract .= "CLIENT:\n";
		$contract .= $client_name . "\n\n";

		$contract .= "EVENT DETAILS:\n";
		$contract .= 'Event: ' . $event_name . "\n";
		// phpcs:ignore WordPress.DateTime.RestrictedFunctions.date_date
		$contract .= 'Date: ' . date( 'F j, Y', strtotime( $event_date ) ) . "\n";
		$contract .= 'Time: ' . $start_time . ' - ' . $end_time . "\n";
		$contract .= 'Venue: ' . $venue_name . "\n";
		if ( $venue_address ) {
			$contract .= 'Address: ' . $venue_address . "\n";
		}
		$contract .= "\n";

		$contract .= "SERVICES:\n";
		$contract .= "The DJ agrees to provide professional DJ services including equipment, music, and performance for the duration of the event.\n\n";

		$contract .= "COMPENSATION:\n";
		if ( $total_price ) {
			$contract .= 'Total Fee: $' . number_format( $total_price, 2 ) . "\n";
			if ( $deposit ) {
				$contract .= 'Deposit: $' . number_format( $deposit, 2 ) . " (due upon signing)\n";
				$contract .= 'Balance: $' . number_format( $total_price - $deposit, 2 ) . " (due on or before event date)\n";
			}
		}
		if ( $payment_terms ) {
			$contract .= "\nPayment Terms:\n" . $payment_terms . "\n";
		}
		$contract .= "\n";

		if ( $cancellation_policy ) {
			$contract .= "CANCELLATION POLICY:\n";
			$contract .= $cancellation_policy . "\n\n";
		} else {
			$contract .= "CANCELLATION POLICY:\n";
			$contract .= "- Cancellation 60+ days prior: Full refund minus deposit\n";
			$contract .= "- Cancellation 30-59 days prior: 50% refund\n";
			$contract .= "- Cancellation less than 30 days: No refund\n\n";
		}

		$contract .= "TERMS AND CONDITIONS:\n";
		$contract .= "1. The DJ reserves the right to substitute equipment of equal or greater quality if needed.\n";
		$contract .= "2. Client agrees to provide a safe and suitable performance space with adequate power.\n";
		$contract .= "3. DJ is not responsible for requests that cannot be fulfilled due to music availability.\n";

		if ( $additional_terms ) {
			$contract .= "\n" . $additional_terms . "\n";
		}

		$contract .= "\n\nBoth parties agree to the terms outlined in this agreement.\n\n";
		$contract .= "Service Provider: _________________________ Date: _________\n\n";
		$contract .= "Client: _________________________ Date: _________\n";

		return $contract;
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
