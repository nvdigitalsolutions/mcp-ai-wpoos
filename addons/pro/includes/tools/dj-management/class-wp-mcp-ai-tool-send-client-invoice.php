<?php
/**
 * Tool for sending client invoices.
 *
 * Allows AI assistants to generate and send invoices to DJ clients.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 * @phase Phase 2.7
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Sends invoices to DJ clients.
 */
class WP_MCP_AI_Tool_Send_Client_Invoice implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_client_invoice';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Client Invoice', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates and sends a professional invoice to the client via email. Includes event details, pricing, and payment information.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Booking ID to generate invoice for (required)', 'mcp-ai-wpoos-pro' ),
				),
				'invoice_number' => array(
					'type'        => 'string',
					'description' => __( 'Invoice number (optional, auto-generated if not provided)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 50,
				),
				'due_date'       => array(
					'type'        => 'string',
					'description' => __( 'Payment due date in ISO 8601 format (YYYY-MM-DD) (optional)', 'mcp-ai-wpoos-pro' ),
					'pattern'     => '^\d{4}-\d{2}-\d{2}$',
				),
				'custom_message' => array(
					'type'        => 'string',
					'description' => __( 'Custom message to include in invoice (optional)', 'mcp-ai-wpoos-pro' ),
					'maxLength'   => 500,
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
	public function execute( array $arguments, array $context = array() ) {
		if ( empty( $arguments['booking_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Booking ID is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$booking_id = absint( $arguments['booking_id'] );

		if ( ! get_post( $booking_id ) || get_post_type( $booking_id ) !== 'dj_booking' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid booking ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get booking details.
		$event_name   = get_post_meta( $booking_id, '_event_name', true );
		$event_date   = get_post_meta( $booking_id, '_event_date', true );
		$client_name  = get_post_meta( $booking_id, '_client_name', true );
		$client_email = get_post_meta( $booking_id, '_client_email', true );
		$total_price  = floatval( get_post_meta( $booking_id, '_total_price', true ) );
		$deposit      = floatval( get_post_meta( $booking_id, '_deposit', true ) );
		$total_paid   = floatval( get_post_meta( $booking_id, '_total_paid', true ) );

		$invoice_number = ! empty( $arguments['invoice_number'] ) ? sanitize_text_field( $arguments['invoice_number'] ) : 'INV-' . $booking_id . '-' . gmdate( 'Ymd' );
		$due_date       = ! empty( $arguments['due_date'] ) ? sanitize_text_field( $arguments['due_date'] ) : '';
		$custom_message = ! empty( $arguments['custom_message'] ) ? sanitize_textarea_field( $arguments['custom_message'] ) : '';

		$balance = $total_price - $total_paid;

		// Build invoice.
		$invoice = $this->build_invoice(
			$invoice_number,
			$client_name,
			$event_name,
			$event_date,
			$total_price,
			$deposit,
			$total_paid,
			$balance,
			$due_date,
			$custom_message
		);

		// Send email.
		$subject = sprintf(
			/* translators: %s: invoice number */
			__( 'Invoice %s', 'mcp-ai-wpoos-pro' ),
			$invoice_number
		);

		$sent = wp_mail( $client_email, $subject, $invoice );

		if ( $sent ) {
			update_post_meta( $booking_id, '_invoice_sent', current_time( 'mysql' ) );
			update_post_meta( $booking_id, '_invoice_number', $invoice_number );

			return array(
				'success'        => true,
				'message'        => __( 'Invoice sent successfully.', 'mcp-ai-wpoos-pro' ),
				'invoice_number' => $invoice_number,
				'sent_to'        => $client_email,
				'amount_due'     => $balance,
			);
		} else {
			return array(
				'success' => false,
				'error'   => __( 'Failed to send invoice email.', 'mcp-ai-wpoos-pro' ),
			);
		}
	}

	/**
	 * Build invoice content.
	 *
	 * @param string $invoice_number Invoice number.
	 * @param string $client_name Client name.
	 * @param string $event_name Event name.
	 * @param string $event_date Event date.
	 * @param float  $total_price Total price.
	 * @param float  $deposit Deposit.
	 * @param float  $total_paid Total paid.
	 * @param float  $balance Balance.
	 * @param string $due_date Due date.
	 * @param string $custom_message Custom message.
	 * @return string Invoice content.
	 */
	private function build_invoice( $invoice_number, $client_name, $event_name, $event_date, $total_price, $deposit, $total_paid, $balance, $due_date, $custom_message ) {
		$invoice  = "INVOICE\n\n";
		$invoice .= 'Invoice Number: ' . $invoice_number . "\n";
		$invoice .= 'Invoice Date: ' . current_time( 'F j, Y' ) . "\n";
		if ( $due_date ) {
			$invoice .= 'Due Date: ' . gmdate( 'F j, Y', strtotime( $due_date ) ) . "\n";
		}
		$invoice .= "\n";

		$invoice .= "Bill To:\n";
		$invoice .= $client_name . "\n\n";

		$invoice .= "Event Details:\n";
		$invoice .= 'Event: ' . $event_name . "\n";
		$invoice .= 'Date: ' . gmdate( 'F j, Y', strtotime( $event_date ) ) . "\n\n";

		$invoice .= "SERVICES\n";
		$invoice .= str_repeat( '-', 50 ) . "\n";
		$invoice .= 'DJ Services' . str_repeat( ' ', 30 ) . '$' . number_format( $total_price, 2 ) . "\n";
		$invoice .= str_repeat( '-', 50 ) . "\n";
		$invoice .= 'Subtotal:' . str_repeat( ' ', 30 ) . '$' . number_format( $total_price, 2 ) . "\n";
		$invoice .= 'Total:' . str_repeat( ' ', 33 ) . '$' . number_format( $total_price, 2 ) . "\n\n";

		if ( $total_paid > 0 ) {
			$invoice .= 'Payments Received:' . str_repeat( ' ', 21 ) . '-$' . number_format( $total_paid, 2 ) . "\n";
		}

		$invoice .= 'BALANCE DUE:' . str_repeat( ' ', 26 ) . '$' . number_format( $balance, 2 ) . "\n\n";

		if ( $custom_message ) {
			$invoice .= $custom_message . "\n\n";
		}

		$invoice .= "Thank you for your business!\n";

		return $invoice;
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
