<?php
/**
 * WP_MCP_AI_Tool_Generate_Invoice_PDF (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


// Load the document response trait from base plugin.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Document_Response' ) ) {
	$nvoos_content_graph_pro_tool_document_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-document-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_document_response ) ) {
		require_once $nvoos_content_graph_pro_tool_document_response;
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	$nvoos_content_graph_pro_tool_chat_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/trait-wp-mcp-ai-tool-chat-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_chat_response ) ) {
		require_once $nvoos_content_graph_pro_tool_chat_response;
	}
}

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
		return __( 'Generate Invoice PDF', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate professional invoice PDFs with itemized billing, calculations, and branding. Perfect for freelancers, agencies, and businesses. Supports multiple currencies, tax rates, and payment terms.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Invoice number or ID.', 'nvoos-content-graph-pro' ),
				),
				'date'           => array(
					'type'        => 'string',
					'description' => __( 'Invoice date (YYYY-MM-DD format).', 'nvoos-content-graph-pro' ),
				),
				'due_date'       => array(
					'type'        => 'string',
					'description' => __( 'Payment due date (YYYY-MM-DD format).', 'nvoos-content-graph-pro' ),
				),
				'bill_to'        => array(
					'type'        => 'object',
					'description' => __( 'Billing recipient information (name, address, etc.).', 'nvoos-content-graph-pro' ),
				),
				'items'          => array(
					'type'        => 'array',
					'items'       => array( 'type' => 'object' ),
					'description' => __( 'Array of invoice items with description, quantity, rate, amount.', 'nvoos-content-graph-pro' ),
				),
				'subtotal'       => array(
					'type'        => 'number',
					'description' => __( 'Subtotal amount before tax.', 'nvoos-content-graph-pro' ),
				),
				'tax_rate'       => array(
					'type'        => 'number',
					'description' => __( 'Tax rate as percentage (e.g., 10 for 10%).', 'nvoos-content-graph-pro' ),
				),
				'total'          => array(
					'type'        => 'number',
					'description' => __( 'Total amount including tax.', 'nvoos-content-graph-pro' ),
				),
				'currency'       => array(
					'type'        => 'string',
					'description' => __( 'Currency code (e.g., USD, EUR, GBP). Default: USD', 'nvoos-content-graph-pro' ),
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
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check user capability.
		if ( ! current_user_can( 'upload_files' ) ) {
			return array(
				'error' => __( 'You do not have permission to generate invoices.', 'nvoos-content-graph-pro' ),
			);
		}

		// Validate required parameters.
		if ( empty( $arguments['invoice_number'] ) || empty( $arguments['items'] ) ) {
			return array(
				'error' => __( 'invoice_number and items are required.', 'nvoos-content-graph-pro' ),
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
					__( 'Failed to generate invoice: %s', 'nvoos-content-graph-pro' ),
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
		$html = $this->build_invoice_html(
			array(
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
			)
		);

		// Try DomPDF first.
		if ( class_exists( '\Dompdf\Dompdf' ) ) {
			return $this->generate_with_dompdf( $html, $invoice_number, $total, $currency );
		}

		// Fallback: Delegate to pro_pdf_document tool.
		if ( class_exists( 'WP_MCP_AI_Tool_Pro_PDF' ) ) {
			return $this->generate_with_pro_pdf( $html, $invoice_number );
		}

		// No suitable PDF generation method available.
		return new WP_Error(
			'no_pdf_generator',
			__( 'Invoice PDF generation requires DomPDF library (Composer: composer require dompdf/dompdf) or Pro PDF tool.', 'nvoos-content-graph-pro' )
		);
	}

	/**
	 * Generate invoice using DomPDF.
	 *
	 * @param string $html           Invoice HTML.
	 * @param string $invoice_number Invoice number.
	 * @param float  $total          Total amount.
	 * @param string $currency       Currency code.
	 * @return array|WP_Error Result array or error.
	 */
	protected function generate_with_dompdf( $html, $invoice_number, $total, $currency ) {
		try {
			$dompdf = new \Dompdf\Dompdf();
			$dompdf->loadHtml( $html );
			$dompdf->setPaper( 'a4', 'portrait' );
			$dompdf->render();

			// Get PDF content.
			$pdf_content = $dompdf->output();

			// Create temp file.
			$temp_file = wp_mcp_ai_tempnam( 'invoice_', '.pdf' );
			if ( is_wp_error( $temp_file ) ) {
				return $temp_file;
			}
			file_put_contents( $temp_file, $pdf_content ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

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
					__( 'Successfully generated invoice #%1$s for %3$s%2$s.', 'nvoos-content-graph-pro' ),
					$invoice_number,
					number_format( $total, 2 ),
					$currency
				),
			);

		} catch ( Exception $e ) {
			return new WP_Error(
				'dompdf_error',
				sprintf(
					/* translators: %s: error message */
					__( 'DomPDF invoice generation failed: %s', 'nvoos-content-graph-pro' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Generate invoice using Pro PDF tool.
	 *
	 * @param string $html           Invoice HTML.
	 * @param string $invoice_number Invoice number.
	 * @return array|WP_Error Result array or error.
	 */
	protected function generate_with_pro_pdf( $html, $invoice_number ) {
		$pro_pdf_tool = new WP_MCP_AI_Tool_Pro_PDF();

		// Convert HTML to plain content for Pro PDF.
		$content = wp_strip_all_tags( $html );

		return $pro_pdf_tool->execute(
			array(
				'operation' => 'generate',
				'content'   => $content,
				'title'     => 'Invoice ' . $invoice_number,
			),
			array()
		);
	}

	/**
	 * Build invoice HTML.
	 *
	 * @param array $data Invoice data.
	 * @return string HTML content.
	 */
	protected function build_invoice_html( $data ) {
		$html  = '<html><body>';
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
}
