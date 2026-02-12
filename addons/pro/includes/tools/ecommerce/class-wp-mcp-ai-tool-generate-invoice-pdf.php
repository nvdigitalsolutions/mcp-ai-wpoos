<?php
/**
 * Generate Invoice PDF Tool
 *
 * Creates professional PDF invoices for WooCommerce orders with
 * customizable branding and formatting.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

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
class WP_MCP_AI_Tool_Generate_Invoice_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
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
			return __( 'Invoice generation requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Invoice generation tool is not available.', 'mcp-ai-wpoos-pro' );
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
		return __( 'Generate Invoice PDF', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Generate professional PDF invoices for WooCommerce orders. Includes company branding, order details, line items, taxes, shipping, payment information, and custom notes. Automatically uploads to media library for customer access.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'WooCommerce order ID (required)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'branding'         => array(
					'type'        => 'object',
					'description' => __( 'Company branding information', 'mcp-ai-wpoos-pro' ),
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
					'description' => __( 'Custom invoice number (default: INV-{order_id})', 'mcp-ai-wpoos-pro' ),
				),
				'include_items'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include order line items', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_tax'      => array(
					'type'        => 'boolean',
					'description' => __( 'Include tax breakdown', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_shipping' => array(
					'type'        => 'boolean',
					'description' => __( 'Include shipping information', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'notes'            => array(
					'type'        => 'string',
					'description' => __( 'Custom notes to include in the invoice', 'mcp-ai-wpoos-pro' ),
				),
				'terms'            => array(
					'type'        => 'string',
					'description' => __( 'Payment terms and conditions', 'mcp-ai-wpoos-pro' ),
				),
				'upload'           => array(
					'type'        => 'boolean',
					'description' => __( 'Upload PDF to WordPress media library', 'mcp-ai-wpoos-pro' ),
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to generate invoices.', 'mcp-ai-wpoos-pro' )
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
				__( 'Order ID is required.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order_id = absint( $arguments['order_id'] );
		$order    = wc_get_order( $order_id );

		if ( ! $order ) {
			return new WP_Error(
				'invalid_order',
				sprintf(
					/* translators: %d: Order ID */
					__( 'Order %d not found.', 'mcp-ai-wpoos-pro' ),
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
				@unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
				return $upload_result;
			}

			$attachment_id = $upload_result['attachment_id'];
			$file_url      = $upload_result['url'];

			// Attach to order.
			update_post_meta( $order_id, '_invoice_pdf_id', $attachment_id );

			// Clean up temp file.
			@unlink( $pdf_path ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
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
				__( 'Invoice %s generated successfully.', 'mcp-ai-wpoos-pro' ),
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
		$script_path = WP_MCP_AI_PRO_PATH . 'addons/pro/scripts/generate-invoice.js';

		// Use Node.js script to generate PDF.
		$input_data = wp_json_encode(
			array(
				'invoiceData' => $invoice_data,
				'outputFile'  => $file_path,
			)
		);

		// Execute Node.js script.
		$node_path = 'node'; // Assume node is in PATH.
		$command   = sprintf(
			'%s %s %s 2>&1',
			escapeshellcmd( $node_path ),
			escapeshellarg( $script_path ),
			escapeshellarg( $input_data )
		);

		$output     = array();
		$return_var = 0;
		exec( $command, $output, $return_var ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec

		if ( 0 !== $return_var || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'pdf_generation_failed',
				sprintf(
					/* translators: %s: error output */
					__( 'Failed to generate invoice PDF: %s', 'mcp-ai-wpoos-pro' ),
					implode( "\n", $output )
				)
			);
		}

		return $file_path;
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
