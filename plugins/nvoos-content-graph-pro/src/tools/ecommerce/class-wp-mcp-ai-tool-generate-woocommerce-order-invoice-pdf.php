<?php
/**
 * Generate WooCommerce Order Invoice PDF Tool (ecosystem port — Wave F2, e-commerce shipping + invoice
 * batch).
 *
 * Ported from the base Pro addon's
 * `addons/pro/includes/tools/ecommerce/class-wp-mcp-ai-tool-generate-woocommerce-order-invoice-pdf.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro
 * addon owns the class in monolith installs — the addon's autoloader
 * skips its copy when `WP_MCP_AI_PRO_PATH` is defined (see the plugin
 * entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; script/version refs resolve from
 * `NVOOS_CONTENT_GRAPH_PRO_*` (the invoice renders through the bundled
 * `generate-pdf.js`, mirrored from the base Pro scripts dir).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 */

declare(strict_types=1);

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for generating WooCommerce order invoices as PDF.
 *
 * Supports:
 * - Professional PDF invoice generation
 * - Custom branding and logos
 * - Line items with pricing
 * - Tax and shipping details
 * - Payment information
 * - Custom notes and terms
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_WooCommerce_Order_Invoice_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.1.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
	 */
	public static function is_available() {
		// Check if WooCommerce is active.
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		// Check if base version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return false;
		}

		// Check if e-commerce toolkit is enabled.
		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.1.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Invoice generation requires WooCommerce to be installed and activated.', 'nvoos-content-graph-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'nvoos-content-graph-pro' );
		}

		return __( 'Invoice generation tool is not available.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'generate_invoice_pdf';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Generate Invoice PDF', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate professional PDF invoices for WooCommerce orders. Includes company branding, order details, line items, taxes, shipping, payment information, and custom notes. Automatically uploads to media library for customer access.', 'nvoos-content-graph-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'order_id'         => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce order ID (required)', 'nvoos-content-graph-pro' ),
					'minimum'     => 1,
				),
				'branding'         => array(
					'type'        => 'object',
					'description' => __( 'Company branding information', 'nvoos-content-graph-pro' ),
					'properties'  => array(
						'company_name'    => array(
							'type'        => 'string',
							'description' => 'Company name',
						),
						'company_address' => array(
							'type'        => 'string',
							'description' => 'Company address',
						),
						'company_email'   => array(
							'type'        => 'string',
							'description' => 'Company email',
						),
						'company_phone'   => array(
							'type'        => 'string',
							'description' => 'Company phone',
						),
						'logo_url'        => array(
							'type'        => 'string',
							'description' => 'Company logo URL',
						),
						'website'         => array(
							'type'        => 'string',
							'description' => 'Company website',
						),
					),
				),
				'invoice_number'   => array(
					'type'        => 'string',
					'description' => __( 'Custom invoice number (default: INV-{order_id})', 'nvoos-content-graph-pro' ),
				),
				'include_items'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include order line items', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_tax'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include tax breakdown', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'include_shipping' => array(
					'type'        => 'boolean',
					'description' => __( 'Include shipping information', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
				'notes'            => array(
					'type'        => 'string',
					'description' => __( 'Custom notes to include in the invoice', 'nvoos-content-graph-pro' ),
				),
				'terms'            => array(
					'type'        => 'string',
					'description' => __( 'Payment terms and conditions', 'nvoos-content-graph-pro' ),
				),
				'upload'           => array(
					'type'        => 'boolean',
					'description' => __( 'Upload PDF to WordPress media library', 'nvoos-content-graph-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'order_id' ),
		);
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'requires-plugin',
			'file-write',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate invoices.', 'nvoos-content-graph-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Validate order ID.
		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'missing_order_id',
				__( 'Order ID is required.', 'nvoos-content-graph-pro' )
			);
		}

		$order_id = absint( $arguments['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'invalid_order',
				sprintf(
					/* translators: %d: Order ID */
					__( 'Order %d not found.', 'nvoos-content-graph-pro' ),
					$order_id
				)
			);
		}

		// Prepare invoice data.
		$invoice_data = $this->prepare_invoice_data( $order, $arguments );

		// Generate PDF using Node.js microservice.
		$pdf_path = $this->generate_pdf( $invoice_data );

		if ( is_wp_error( $pdf_path ) ) {
			return $pdf_path;
		}

		// Upload to media library if requested.
		$upload        = isset( $arguments['upload'] ) ? (bool) $arguments['upload'] : true;
		$attachment_id = 0;
		$file_url      = '';

		if ( $upload ) {
			$upload_result = $this->upload_to_media_library( $pdf_path, $order_id );

			if ( is_wp_error( $upload_result ) ) {
				// Clean up temp file.
				wp_delete_file( $pdf_path );
				return $upload_result;
			}

			$attachment_id = $upload_result['attachment_id'];
			$file_url      = $upload_result['url'];

			// Attach to order.
			update_post_meta( $order_id, '_invoice_pdf_id', $attachment_id );

			// Clean up temp file.
			wp_delete_file( $pdf_path );
		} else {
			$file_url = $pdf_path;
		}

		return array(
			'success'        => true,
			'order_id'       => $order_id,
			'invoice_number' => $invoice_data['invoice_number'],
			'file_path'      => $upload ? '' : $pdf_path,
			'file_url'       => $file_url,
			'attachment_id'  => $attachment_id,
			'message'        => sprintf(
				/* translators: %s: Invoice number */
				__( 'Invoice %s generated successfully.', 'nvoos-content-graph-pro' ),
				$invoice_data['invoice_number']
			),
		);
	}

	/**
	 * Prepare invoice data from order.
	 *
	 * @param WC_Order $order     Order object.
	 * @param array    $arguments Tool arguments.
	 * @return array Invoice data.
	 */
	protected function prepare_invoice_data( $order, $arguments ) {
		// Get branding info.
		$branding = isset( $arguments['branding'] ) && is_array( $arguments['branding'] ) ? $arguments['branding'] : array();

		$company_name    = isset( $branding['company_name'] ) ? sanitize_text_field( $branding['company_name'] ) : get_bloginfo( 'name' );
		$company_address = isset( $branding['company_address'] ) ? sanitize_textarea_field( $branding['company_address'] ) : '';
		$company_email   = isset( $branding['company_email'] ) ? sanitize_email( $branding['company_email'] ) : get_option( 'admin_email' );
		$company_phone   = isset( $branding['company_phone'] ) ? sanitize_text_field( $branding['company_phone'] ) : '';
		$logo_url        = isset( $branding['logo_url'] ) ? esc_url_raw( $branding['logo_url'] ) : '';
		$website         = isset( $branding['website'] ) ? esc_url_raw( $branding['website'] ) : home_url();

		// Invoice number.
		$invoice_number = isset( $arguments['invoice_number'] ) ? sanitize_text_field( $arguments['invoice_number'] ) : 'INV-' . $order->get_id();

		// Options.
		$include_items    = isset( $arguments['include_items'] ) ? (bool) $arguments['include_items'] : true;
		$include_tax      = isset( $arguments['include_tax'] ) ? (bool) $arguments['include_tax'] : true;
		$include_shipping = isset( $arguments['include_shipping'] ) ? (bool) $arguments['include_shipping'] : true;

		// Prepare line items.
		$line_items = array();
		if ( $include_items ) {
			foreach ( $order->get_items() as $item ) {
				$product      = $item->get_product();
				$line_items[] = array(
					'name'     => $item->get_name(),
					'sku'      => $product ? $product->get_sku() : '',
					'quantity' => $item->get_quantity(),
					'price'    => wc_format_decimal( $item->get_subtotal() / $item->get_quantity(), 2 ),
					'total'    => wc_format_decimal( $item->get_total(), 2 ),
				);
			}
		}

		// Prepare shipping.
		$shipping = array();
		if ( $include_shipping ) {
			foreach ( $order->get_shipping_methods() as $shipping_item ) {
				$shipping[] = array(
					'method' => $shipping_item->get_method_title(),
					'cost'   => wc_format_decimal( $shipping_item->get_total(), 2 ),
				);
			}
		}

		// Prepare taxes.
		$taxes = array();
		if ( $include_tax ) {
			foreach ( $order->get_tax_totals() as $tax ) {
				$taxes[] = array(
					'label'  => $tax->label,
					'amount' => wc_format_decimal( $tax->amount, 2 ),
				);
			}
		}

		return array(
			'invoice_number' => $invoice_number,
			'order_id'       => $order->get_id(),
			'order_number'   => $order->get_order_number(),
			'order_date'     => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			'company'        => array(
				'name'    => $company_name,
				'address' => $company_address,
				'email'   => $company_email,
				'phone'   => $company_phone,
				'logo'    => $logo_url,
				'website' => $website,
			),
			'customer'       => array(
				'name'             => $order->get_formatted_billing_full_name(),
				'email'            => $order->get_billing_email(),
				'phone'            => $order->get_billing_phone(),
				'billing_address'  => $order->get_formatted_billing_address(),
				'shipping_address' => $order->get_formatted_shipping_address(),
			),
			'items'          => $line_items,
			'shipping'       => $shipping,
			'taxes'          => $taxes,
			'totals'         => array(
				'subtotal'       => wc_format_decimal( $order->get_subtotal(), 2 ),
				'shipping_total' => wc_format_decimal( $order->get_shipping_total(), 2 ),
				'tax_total'      => wc_format_decimal( $order->get_total_tax(), 2 ),
				'total'          => wc_format_decimal( $order->get_total(), 2 ),
				'currency'       => $order->get_currency(),
			),
			'payment'        => array(
				'method' => $order->get_payment_method_title(),
				'status' => $order->get_status(),
			),
			'notes'          => isset( $arguments['notes'] ) ? sanitize_textarea_field( $arguments['notes'] ) : '',
			'terms'          => isset( $arguments['terms'] ) ? sanitize_textarea_field( $arguments['terms'] ) : '',
		);
	}

	/**
	 * Generate PDF using Node.js microservice.
	 *
	 * @param array $invoice_data Invoice data.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_pdf( $invoice_data ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/wp-mcp-ai-temp';

		// Create temp directory if it doesn't exist.
		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$file_path   = $temp_dir . '/invoice-' . $invoice_data['order_id'] . '-' . time() . '.pdf';
		$script_path = NVOOS_CONTENT_GRAPH_PRO_PATH . 'scripts/generate-pdf.js';

		// The bundled Node script reads its input from a JSON file and writes
		// the PDF to the output path passed as its second argument.
		$input_file = $this->write_invoice_input_file(
			array(
				'title'        => $invoice_data['invoice_number'],
				'author'       => isset( $invoice_data['company']['name'] ) ? $invoice_data['company']['name'] : '',
				'html_content' => $this->build_invoice_html( $invoice_data ),
			),
			$temp_dir
		);

		if ( is_wp_error( $input_file ) ) {
			return $input_file;
		}

		$result = wp_mcp_ai_ecommerce_run_node_script( $script_path, $input_file, $file_path );
		wp_delete_file( $input_file );

		if ( is_wp_error( $result ) ) {
			if ( file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}
			return new WP_Error(
				'pdf_generation_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Failed to generate invoice PDF: %s', 'nvoos-content-graph-pro' ),
					$result->get_error_message()
				)
			);
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'pdf_generation_failed',
				__( 'Failed to generate invoice PDF: no output was produced.', 'nvoos-content-graph-pro' )
			);
		}

		return $file_path;
	}

	/**
	 * Write the invoice JSON payload for the Node PDF script to a temp file.
	 *
	 * @param array  $payload  Payload to encode.
	 * @param string $temp_dir Directory to write into.
	 * @return string|WP_Error Temp file path or error.
	 */
	protected function write_invoice_input_file( array $payload, $temp_dir ) {
		$input_file = $temp_dir . '/invoice-input-' . wp_generate_password( 12, false ) . '.json';
		$encoded    = wp_json_encode( $payload );

		if ( false === $encoded ) {
			return new WP_Error(
				'pdf_generation_failed',
				__( 'Failed to encode invoice generation input.', 'nvoos-content-graph-pro' )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for the Node script input.
		if ( false === file_put_contents( $input_file, $encoded ) ) {
			return new WP_Error(
				'pdf_generation_failed',
				__( 'Failed to write invoice generation input file.', 'nvoos-content-graph-pro' )
			);
		}

		return $input_file;
	}

	/**
	 * Build the invoice HTML body for the Node PDF renderer.
	 *
	 * @param array $invoice_data Prepared invoice data.
	 * @return string HTML fragment (escaped).
	 */
	protected function build_invoice_html( array $invoice_data ) {
		$company  = isset( $invoice_data['company'] ) ? $invoice_data['company'] : array();
		$customer = isset( $invoice_data['customer'] ) ? $invoice_data['customer'] : array();
		$totals   = isset( $invoice_data['totals'] ) ? $invoice_data['totals'] : array();
		$payment  = isset( $invoice_data['payment'] ) ? $invoice_data['payment'] : array();

		$html = '<h2>' . esc_html( isset( $company['name'] ) ? $company['name'] : '' ) . '</h2>';

		if ( ! empty( $company['address'] ) ) {
			$html .= '<p>' . esc_html( $company['address'] ) . '</p>';
		}

		$contact_bits = array();
		if ( ! empty( $company['email'] ) ) {
			$contact_bits[] = esc_html( $company['email'] );
		}
		if ( ! empty( $company['phone'] ) ) {
			$contact_bits[] = esc_html( $company['phone'] );
		}
		if ( ! empty( $company['website'] ) ) {
			$contact_bits[] = esc_html( $company['website'] );
		}
		if ( ! empty( $contact_bits ) ) {
			$html .= '<p>' . implode( ' | ', $contact_bits ) . '</p>';
		}

		$html .= '<h3>' . esc_html( isset( $invoice_data['invoice_number'] ) ? $invoice_data['invoice_number'] : '' ) . '</h3>';

		$meta_lines = array();
		if ( ! empty( $invoice_data['order_number'] ) ) {
			$meta_lines[] = esc_html__( 'Order', 'nvoos-content-graph-pro' ) . ': ' . esc_html( $invoice_data['order_number'] );
		}
		if ( ! empty( $invoice_data['order_date'] ) ) {
			$meta_lines[] = esc_html__( 'Date', 'nvoos-content-graph-pro' ) . ': ' . esc_html( $invoice_data['order_date'] );
		}
		if ( ! empty( $meta_lines ) ) {
			$html .= '<p>' . implode( '<br/>', $meta_lines ) . '</p>';
		}

		$html .= '<h4>' . esc_html__( 'Bill To', 'nvoos-content-graph-pro' ) . '</h4>';
		$html .= '<p>' . esc_html( isset( $customer['name'] ) ? $customer['name'] : '' ) . '</p>';

		if ( ! empty( $customer['billing_address'] ) ) {
			$html .= '<p>' . esc_html( $customer['billing_address'] ) . '</p>';
		}

		if ( ! empty( $invoice_data['items'] ) ) {
			$html .= '<table>';
			$html .= '<tr><th>' . esc_html__( 'Item', 'nvoos-content-graph-pro' ) . '</th><th>' . esc_html__( 'SKU', 'nvoos-content-graph-pro' ) . '</th><th>' . esc_html__( 'Qty', 'nvoos-content-graph-pro' ) . '</th><th>' . esc_html__( 'Price', 'nvoos-content-graph-pro' ) . '</th><th>' . esc_html__( 'Total', 'nvoos-content-graph-pro' ) . '</th></tr>';
			foreach ( $invoice_data['items'] as $item ) {
				$html .= '<tr>';
				$html .= '<td>' . esc_html( $item['name'] ) . '</td>';
				$html .= '<td>' . esc_html( isset( $item['sku'] ) ? $item['sku'] : '' ) . '</td>';
				$html .= '<td>' . esc_html( (string) $item['quantity'] ) . '</td>';
				$html .= '<td>' . esc_html( (string) $item['price'] ) . '</td>';
				$html .= '<td>' . esc_html( (string) $item['total'] ) . '</td>';
				$html .= '</tr>';
			}
			$html .= '</table>';
		}

		if ( ! empty( $invoice_data['shipping'] ) ) {
			foreach ( $invoice_data['shipping'] as $shipping_item ) {
				$html .= '<p>' . esc_html( $shipping_item['method'] ) . ': ' . esc_html( (string) $shipping_item['cost'] ) . '</p>';
			}
		}

		if ( ! empty( $invoice_data['taxes'] ) ) {
			foreach ( $invoice_data['taxes'] as $tax ) {
				$html .= '<p>' . esc_html( $tax['label'] ) . ': ' . esc_html( (string) $tax['amount'] ) . '</p>';
			}
		}

		if ( ! empty( $totals ) ) {
			$html .= '<h4>' . esc_html__( 'Totals', 'nvoos-content-graph-pro' ) . '</h4>';

			$total_lines   = array();
			$total_lines[] = esc_html__( 'Subtotal', 'nvoos-content-graph-pro' ) . ': ' . esc_html( isset( $totals['subtotal'] ) ? (string) $totals['subtotal'] : '' );
			if ( isset( $totals['shipping_total'] ) && '' !== $totals['shipping_total'] ) {
				$total_lines[] = esc_html__( 'Shipping', 'nvoos-content-graph-pro' ) . ': ' . esc_html( (string) $totals['shipping_total'] );
			}
			if ( isset( $totals['tax_total'] ) && '' !== $totals['tax_total'] ) {
				$total_lines[] = esc_html__( 'Tax', 'nvoos-content-graph-pro' ) . ': ' . esc_html( (string) $totals['tax_total'] );
			}
			$total_lines[] = esc_html__( 'Total', 'nvoos-content-graph-pro' ) . ': ' . esc_html( isset( $totals['total'] ) ? (string) $totals['total'] : '' ) . ' ' . esc_html( isset( $totals['currency'] ) ? $totals['currency'] : '' );
			$html         .= '<p>' . implode( '<br/>', $total_lines ) . '</p>';
		}

		if ( ! empty( $payment['method'] ) ) {
			$html .= '<p>' . esc_html__( 'Payment', 'nvoos-content-graph-pro' ) . ': ' . esc_html( $payment['method'] ) . '</p>';
		}

		if ( ! empty( $invoice_data['notes'] ) ) {
			$html .= '<h4>' . esc_html__( 'Notes', 'nvoos-content-graph-pro' ) . '</h4>';
			$html .= '<p>' . esc_html( $invoice_data['notes'] ) . '</p>';
		}

		if ( ! empty( $invoice_data['terms'] ) ) {
			$html .= '<h4>' . esc_html__( 'Terms', 'nvoos-content-graph-pro' ) . '</h4>';
			$html .= '<p>' . esc_html( $invoice_data['terms'] ) . '</p>';
		}

		return $html;
	}

	/**
	 * Upload file to WordPress media library.
	 *
	 * @param string $file_path File path.
	 * @param int    $order_id  Order ID.
	 * @return array|WP_Error Upload result or error.
	 */
	protected function upload_to_media_library( $file_path, $order_id ) {
		$filename = 'invoice-order-' . $order_id . '.pdf';

		$file = array(
			'name'     => $filename,
			'type'     => 'application/pdf',
			'tmp_name' => $file_path,
			'error'    => 0,
			'size'     => filesize( $file_path ),
		);

		$attachment_id = media_handle_sideload( $file, 0 );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return array(
			'attachment_id' => $attachment_id,
			'url'           => wp_get_attachment_url( $attachment_id ),
		);
	}
}
