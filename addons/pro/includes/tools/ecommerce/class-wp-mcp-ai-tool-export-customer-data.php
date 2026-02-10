<?php
/**
 * Export Customer Data Tool
 *
 * Exports customer data in GDPR-compliant format including order history,
 * personal information, and communication records.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for exporting customer data.
 *
 * Supports:
 * - GDPR-compliant data export
 * - Personal information
 * - Order history
 * - Communication records
 * - Multiple export formats (JSON, CSV)
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Export_Customer_Data implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Customer data export requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Customer data export tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'export_customer_data';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Export Customer Data', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Export customer data in GDPR-compliant format. Includes personal information, order history, addresses, and optional communication records. Supports JSON and CSV formats with automatic media library upload.', 'mcp-ai-wpoos-pro' );
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
				'customer_id'       => array(
					'type'        => 'integer',
					'description' => __( 'Customer ID to export (required if no email)', 'mcp-ai-wpoos-pro' ),
				),
				'email'             => array(
					'type'        => 'string',
					'description' => __( 'Customer email to export (required if no customer_id)', 'mcp-ai-wpoos-pro' ),
				),
				'format'            => array(
					'type'        => 'string',
					'description' => __( 'Export format', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'json', 'csv' ),
					'default'     => 'json',
				),
				'include_orders'    => array(
					'type'        => 'boolean',
					'description' => __( 'Include order history', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_addresses' => array(
					'type'        => 'boolean',
					'description' => __( 'Include billing and shipping addresses', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_notes'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include customer notes', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'upload'            => array(
					'type'        => 'boolean',
					'description' => __( 'Upload file to WordPress media library', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
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
			'pii',
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
				__( 'You do not have permission to export customer data.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		// Get customer.
		$customer = null;

		if ( ! empty( $arguments['customer_id'] ) ) {
			$customer = new WC_Customer( absint( $arguments['customer_id'] ) );
		} elseif ( ! empty( $arguments['email'] ) ) {
			$email = sanitize_email( $arguments['email'] );
			$user  = get_user_by( 'email', $email );

			if ( $user ) {
				$customer = new WC_Customer( $user->ID );
			}
		}

		if ( ! $customer || ! $customer->get_id() ) {
			return new WP_Error(
				'customer_not_found',
				__( 'Customer not found.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Gather customer data.
		$customer_data = $this->gather_customer_data( $customer, $arguments );

		// Get format.
		$format = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'json';
		$upload = isset( $arguments['upload'] ) ? (bool) $arguments['upload'] : true;

		// Generate export file.
		$file_path = $this->generate_export_file( $customer_data, $format, $customer->get_email() );

		if ( is_wp_error( $file_path ) ) {
			return $file_path;
		}

		// Upload to media library if requested.
		$attachment_id = 0;
		$file_url      = '';

		if ( $upload ) {
			$upload_result = $this->upload_to_media_library( $file_path, $customer->get_email(), $format );

			if ( is_wp_error( $upload_result ) ) {
				wp_delete_file( $file_path );
				return $upload_result;
			}

			$attachment_id = $upload_result['attachment_id'];
			$file_url      = $upload_result['url'];

			wp_delete_file( $file_path );
		} else {
			$file_url = $file_path;
		}

		return array(
			'success'        => true,
			'customer_id'    => $customer->get_id(),
			'customer_email' => $customer->get_email(),
			'format'         => $format,
			'file_path'      => $upload ? '' : $file_path,
			'file_url'       => $file_url,
			'attachment_id'  => $attachment_id,
			'message'        => sprintf(
				/* translators: %s: Customer email */
				__( 'Customer data for %s exported successfully.', 'mcp-ai-wpoos-pro' ),
				$customer->get_email()
			),
		);
	}

	/**
	 * Gather customer data.
	 *
	 * @param WC_Customer $customer  Customer object.
	 * @param array       $arguments Tool arguments.
	 * @return array Customer data.
	 */
	protected function gather_customer_data( $customer, $arguments ) {
		$data = array(
			'customer_info' => array(
				'id'           => $customer->get_id(),
				'email'        => $customer->get_email(),
				'first_name'   => $customer->get_first_name(),
				'last_name'    => $customer->get_last_name(),
				'display_name' => $customer->get_display_name(),
				'username'     => $customer->get_username(),
				'date_created' => $customer->get_date_created() ? $customer->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
			),
		);

		// Add addresses if requested.
		if ( isset( $arguments['include_addresses'] ) && $arguments['include_addresses'] ) {
			$data['billing_address'] = array(
				'first_name' => $customer->get_billing_first_name(),
				'last_name'  => $customer->get_billing_last_name(),
				'company'    => $customer->get_billing_company(),
				'address_1'  => $customer->get_billing_address_1(),
				'address_2'  => $customer->get_billing_address_2(),
				'city'       => $customer->get_billing_city(),
				'state'      => $customer->get_billing_state(),
				'postcode'   => $customer->get_billing_postcode(),
				'country'    => $customer->get_billing_country(),
				'email'      => $customer->get_billing_email(),
				'phone'      => $customer->get_billing_phone(),
			);

			$data['shipping_address'] = array(
				'first_name' => $customer->get_shipping_first_name(),
				'last_name'  => $customer->get_shipping_last_name(),
				'company'    => $customer->get_shipping_company(),
				'address_1'  => $customer->get_shipping_address_1(),
				'address_2'  => $customer->get_shipping_address_2(),
				'city'       => $customer->get_shipping_city(),
				'state'      => $customer->get_shipping_state(),
				'postcode'   => $customer->get_shipping_postcode(),
				'country'    => $customer->get_shipping_country(),
			);
		}

		// Add order history if requested.
		if ( isset( $arguments['include_orders'] ) && $arguments['include_orders'] ) {
			$data['orders'] = $this->get_customer_orders( $customer );
		}

		// Add notes if requested.
		if ( isset( $arguments['include_notes'] ) && $arguments['include_notes'] ) {
			$data['notes'] = $this->get_customer_notes( $customer );
		}

		$data['export_date'] = gmdate( 'Y-m-d H:i:s' );

		return $data;
	}

	/**
	 * Get customer orders.
	 *
	 * @param WC_Customer $customer Customer object.
	 * @return array Orders data.
	 */
	protected function get_customer_orders( $customer ) {
		$orders = wc_get_orders(
			array(
				'customer_id' => $customer->get_id(),
				'limit'       => -1,
			)
		);

		$orders_data = array();

		foreach ( $orders as $order ) {
			$orders_data[] = array(
				'order_id'       => $order->get_id(),
				'order_number'   => $order->get_order_number(),
				'status'         => $order->get_status(),
				'date_created'   => $order->get_date_created() ? $order->get_date_created()->date( 'Y-m-d H:i:s' ) : '',
				'total'          => $order->get_total(),
				'currency'       => $order->get_currency(),
				'payment_method' => $order->get_payment_method_title(),
			);
		}

		return $orders_data;
	}

	/**
	 * Get customer notes.
	 *
	 * @param WC_Customer $customer Customer object.
	 * @return array Notes data.
	 */
	protected function get_customer_notes( $customer ) {
		$notes = get_comments(
			array(
				'user_id' => $customer->get_id(),
				'type'    => 'order_note',
				'status'  => 'approve',
			)
		);

		$notes_data = array();

		foreach ( $notes as $note ) {
			$notes_data[] = array(
				'date'    => $note->comment_date,
				'content' => $note->comment_content,
			);
		}

		return $notes_data;
	}

	/**
	 * Generate export file.
	 *
	 * @param array  $data   Customer data.
	 * @param string $format Format.
	 * @param string $email  Customer email.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_export_file( $data, $format, $email ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = $upload_dir['basedir'] . '/wp-mcp-ai-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$filename = 'customer-data-' . sanitize_file_name( $email ) . '-' . time();

		if ( 'json' === $format ) {
			return $this->generate_json_file( $data, $temp_dir, $filename );
		} else {
			return $this->generate_csv_file( $data, $temp_dir, $filename );
		}
	}

	/**
	 * Generate JSON file.
	 *
	 * @param array  $data     Customer data.
	 * @param string $temp_dir Temp directory.
	 * @param string $filename Filename.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_json_file( $data, $temp_dir, $filename ) {
		$file_path = $temp_dir . '/' . $filename . '.json';

		$json = wp_json_encode( $data, JSON_PRETTY_PRINT );

		if ( false === file_put_contents( $file_path, $json ) ) { // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
			return new WP_Error( 'file_write_error', __( 'Failed to write JSON file.', 'mcp-ai-wpoos-pro' ) );
		}

		return $file_path;
	}

	/**
	 * Generate CSV file.
	 *
	 * @param array  $data     Customer data.
	 * @param string $temp_dir Temp directory.
	 * @param string $filename Filename.
	 * @return string|WP_Error File path or error.
	 */
	protected function generate_csv_file( $data, $temp_dir, $filename ) {
		$file_path = $temp_dir . '/' . $filename . '.csv';

		$fp = fopen( $file_path, 'w' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen

		if ( ! $fp ) {
			return new WP_Error( 'file_creation_failed', __( 'Failed to create CSV file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Write customer info.
		fputcsv( $fp, array( 'Customer Information' ) );
		foreach ( $data['customer_info'] as $key => $value ) {
			fputcsv( $fp, array( $key, $value ) );
		}

		fputcsv( $fp, array() ); // Empty line.

		// Write orders if present.
		if ( ! empty( $data['orders'] ) ) {
			fputcsv( $fp, array( 'Order History' ) );
			fputcsv( $fp, array( 'Order ID', 'Order Number', 'Status', 'Date', 'Total', 'Currency', 'Payment Method' ) );

			foreach ( $data['orders'] as $order ) {
				fputcsv( $fp, array_values( $order ) );
			}
		}

		fclose( $fp ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		return $file_path;
	}

	/**
	 * Upload file to WordPress media library.
	 *
	 * @param string $file_path File path.
	 * @param string $email     Customer email.
	 * @param string $format    Format.
	 * @return array|WP_Error Upload result or error.
	 */
	protected function upload_to_media_library( $file_path, $email, $format ) {
		$extension = 'json' === $format ? 'json' : 'csv';
		$mime_type = 'json' === $format ? 'application/json' : 'text/csv';
		$filename  = 'customer-data-' . sanitize_file_name( $email ) . '.' . $extension;

		$file = array(
			'name'     => $filename,
			'type'     => $mime_type,
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
