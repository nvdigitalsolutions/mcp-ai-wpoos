<?php
/**
 * Generate Invoice PDF Tool - Create professional invoice PDFs.
 *
 * Template-based invoice generation with itemized billing,
 * calculations, and professional formatting.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the document response trait from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Generate professional invoice PDFs.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Invoice_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_invoice_pdf';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Invoice PDF', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate professional invoice PDFs with itemized billing, calculations, and branding. Perfect for freelancers, agencies, and businesses. Supports multiple currencies, tax rates, and payment terms.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'invoice_number' => array(
					'type'        => 'string',
					'description' => __( 'Invoice number or ID.', 'mcp-ai-wpoos-pro' ),
				),
				'date'           => array(
					'type'        => 'string',
					'description' => __( 'Invoice date (YYYY-MM-DD format).', 'mcp-ai-wpoos-pro' ),
				),
				'due_date'       => array(
					'type'        => 'string',
					'description' => __( 'Payment due date (YYYY-MM-DD format).', 'mcp-ai-wpoos-pro' ),
				),
				'bill_to'        => array(
					'type'        => 'object',
					'description' => __( 'Billing recipient information (name, address, etc.).', 'mcp-ai-wpoos-pro' ),
				),
				'items'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'Array of invoice items with description, quantity, rate, amount.', 'mcp-ai-wpoos-pro' ),
				),
				'subtotal'       => array(
					'type'        => 'number',
					'description' => __( 'Subtotal amount before tax.', 'mcp-ai-wpoos-pro' ),
				),
				'tax_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Tax rate as percentage (e.g., 10 for 10%).', 'mcp-ai-wpoos-pro' ),
				),
				'total'          => array(
					'type'        => 'number',
					'description' => __( 'Total amount including tax.', 'mcp-ai-wpoos-pro' ),
				),
				'currency'       => array(
					'type'        => 'string',
					'description' => __( 'Currency code (e.g., USD, EUR, GBP). Default: USD', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'invoice_number', 'items' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability', // upload_files.
			'requires-model',
			'consumes-tokens',
			'write',
			'state-changing',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'error' => __( 'You do not have permission to generate invoices.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['invoice_number'] ) || empty( $arguments['items'] ) ) {
			return array(
				'error' => __( 'invoice_number and items are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		try {
			// Generate invoice PDF.
			$result = $this->generate_invoice( $arguments );

			if ( is_wp_error( $result ) ) {
				return array(
					'error' => $result->get_error_message(),
				);
			}

			// Add document HTML to response for chat display.
			return $this->add_document_html_to_response( $result );

		} catch ( Exception $e ) {
			return array(
				'error' => sprintf(
					/* translators: %s: error message */
					__( 'Failed to generate invoice: %s', 'mcp-ai-wpoos-pro' ),
					$e->getMessage()
				),
			);
		}
	}

	/**
	 * Generate invoice PDF.
	 *
	 * @param array $data Invoice data.
	 * @return array|WP_Error Result array or error.
	 */
	protected function generate_invoice( $data ) {
		// Extract invoice data with defaults.
		$invoice_number = sanitize_text_field( $data['invoice_number'] );
		$date           = ! empty( $data['date'] ) ? sanitize_text_field( $data['date'] ) : gmdate( 'Y-m-d' );
		$due_date       = ! empty( $data['due_date'] ) ? sanitize_text_field( $data['due_date'] ) : gmdate( 'Y-m-d', strtotime( '+30 days' ) );
		$currency       = ! empty( $data['currency'] ) ? sanitize_text_field( $data['currency'] ) : 'USD';
		$items          = $data['items'];

		// Calculate totals if not provided.
		$subtotal = 0;
		foreach ( $items as $item ) {
			if ( isset( $item['amount'] ) ) {
				$subtotal += floatval( $item['amount'] );
			}
		}

		$tax_rate = ! empty( $data['tax_rate'] ) ? floatval( $data['tax_rate'] ) : 0;
		$tax      = $subtotal * ( $tax_rate / 100 );
		$total    = $subtotal + $tax;

		// Build invoice HTML content.
		$html = $this->build_invoice_html( array(
			'invoice_number' => $invoice_number,
			'date'           => $date,
			'due_date'       => $due_date,
			'bill_to'        => $data['bill_to'] ?? array(),
			'items'          => $items,
			'subtotal'       => $subtotal,
			'tax_rate'       => $tax_rate,
			'tax'            => $tax,
			'total'          => $total,
			'currency'       => $currency,
		) );

		// This is a placeholder implementation.
		// In production, use proper PDF generation library or delegate to Pro PDF tool.
		
		// Create a simple PDF (placeholder).
		$temp_file = tempnam( sys_get_temp_dir(), 'invoice_' );
		$pdf_content = $this->html_to_simple_pdf( $html, 'Invoice ' . $invoice_number );
		file_put_contents( $temp_file, $pdf_content );

		// Upload to WordPress media library.
		$file_array = array(
			'name'     => 'invoice-' . $invoice_number . '.pdf',
			'tmp_name' => $temp_file,
		);

		$attachment_id = media_handle_sideload( $file_array, 0 );

		@unlink( $temp_file );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		// Get attachment details.
		$attachment_url = wp_get_attachment_url( $attachment_id );
		$file_path      = get_attached_file( $attachment_id );
		$file_size      = filesize( $file_path );

		return array(
			'attachment_id' => $attachment_id,
			'url'           => $attachment_url,
			'filename'      => basename( $file_path ),
			'mime_type'     => 'application/pdf',
			'size'          => $file_size,
			'text'          => sprintf(
				/* translators: 1: invoice number, 2: total amount, 3: currency */
				__( 'Successfully generated invoice #%1$s for %3$s%2$s.', 'mcp-ai-wpoos-pro' ),
				$invoice_number,
				number_format( $total, 2 ),
				$currency
			),
		);
	}

	/**
	 * Build invoice HTML.
	 *
	 * @param array $data Invoice data.
	 * @return string HTML content.
	 */
	protected function build_invoice_html( $data ) {
		$html = '<html><body>';
		$html .= '<h1>INVOICE</h1>';
		$html .= '<p><strong>Invoice #:</strong> ' . esc_html( $data['invoice_number'] ) . '</p>';
		$html .= '<p><strong>Date:</strong> ' . esc_html( $data['date'] ) . '</p>';
		$html .= '<p><strong>Due Date:</strong> ' . esc_html( $data['due_date'] ) . '</p>';
		
		// Items table.
		$html .= '<table border="1" style="width:100%; border-collapse:collapse;"><tr><th>Description</th><th>Qty</th><th>Rate</th><th>Amount</th></tr>';
		foreach ( $data['items'] as $item ) {
			$html .= '<tr>';
			$html .= '<td>' . esc_html( $item['description'] ?? '' ) . '</td>';
			$html .= '<td>' . esc_html( $item['quantity'] ?? 1 ) . '</td>';
			$html .= '<td>' . number_format( $item['rate'] ?? 0, 2 ) . '</td>';
			$html .= '<td>' . number_format( $item['amount'] ?? 0, 2 ) . '</td>';
			$html .= '</tr>';
		}
		$html .= '</table>';
		
		// Totals.
		$html .= '<p style="text-align:right;"><strong>Subtotal:</strong> ' . number_format( $data['subtotal'], 2 ) . '</p>';
		if ( $data['tax_rate'] > 0 ) {
			$html .= '<p style="text-align:right;"><strong>Tax (' . $data['tax_rate'] . '%):</strong> ' . number_format( $data['tax'], 2 ) . '</p>';
		}
		$html .= '<p style="text-align:right;"><strong>Total:</strong> ' . $data['currency'] . ' ' . number_format( $data['total'], 2 ) . '</p>';
		
		$html .= '</body></html>';
		return $html;
	}

	/**
	 * Convert HTML to simple PDF (placeholder).
	 *
	 * @param string $html  HTML content.
	 * @param string $title Document title.
	 * @return string PDF binary content.
	 */
	protected function html_to_simple_pdf( $html, $title ) {
		// Placeholder - use basic PDF structure.
		$text = wp_strip_all_tags( $html );
		$pdf  = "%PDF-1.4\n1 0 obj\n<< /Type /Catalog /Pages 2 0 R >>\nendobj\n";
		$pdf .= "2 0 obj\n<< /Type /Pages /Kids [3 0 R] /Count 1 >>\nendobj\n";
		$pdf .= "3 0 obj\n<< /Type /Page /Parent 2 0 R /Resources 4 0 R /MediaBox [0 0 595 842] /Contents 5 0 R >>\nendobj\n";
		$pdf .= "4 0 obj\n<< /Font << /F1 << /Type /Font /Subtype /Type1 /BaseFont /Helvetica >> >> >>\nendobj\n";
		$pdf .= "5 0 obj\n<< /Length " . strlen( $text ) . " >>\nstream\nBT /F1 10 Tf 50 800 Td (" . substr( $text, 0, 1000 ) . ") Tj ET\nendstream\nendobj\n";
		$pdf .= "xref\n0 6\n0000000000 65535 f\n0000000009 00000 n\n0000000056 00000 n\n0000000115 00000 n\n0000000214 00000 n\n0000000304 00000 n\n";
		$pdf .= "trailer\n<< /Size 6 /Root 1 0 R >>\nstartxref\n" . ( strlen( $pdf ) + 20 ) . "\n%%EOF";
		return $pdf;
	}
}
