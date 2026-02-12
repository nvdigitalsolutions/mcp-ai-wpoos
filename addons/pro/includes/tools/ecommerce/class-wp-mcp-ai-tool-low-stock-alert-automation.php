<?php
/**
 * Low Stock Alert Automation Tool
 *
 * Automated low stock notifications with email alerts, threshold configuration,
 * and product tracking.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for automated low stock alerts.
 *
 * Supports:
 * - Automated low stock detection
 * - Email alert notifications
 * - Configurable thresholds
 * - Product tracking
 * - Stock report generation
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Low_Stock_Alert_Automation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Low stock alert automation requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Low stock alert tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'low_stock_alert_automation';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Low Stock Alert Automation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Automated low stock monitoring and notification system. Detects products below threshold, sends email alerts, and generates stock reports. Supports custom thresholds and product-specific alerts.', 'mcp-ai-wpoos-pro' );
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
				'action'               => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'check', 'send_alerts', 'get_report' ),
					'default'     => 'check',
				),
				'threshold'            => array(
					'type'        => 'integer',
					'description' => __( 'Stock quantity threshold for alerts', 'mcp-ai-wpoos-pro' ),
					'default'     => 5,
					'minimum'     => 0,
				),
				'include_out_of_stock' => array(
					'type'        => 'boolean',
					'description' => __( 'Include out of stock products', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'category_ids'         => array(
					'type'        => 'array',
					'description' => __( 'Filter by specific category IDs', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'send_email'           => array(
					'type'        => 'boolean',
					'description' => __( 'Send email notifications', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'email_recipients'     => array(
					'type'        => 'array',
					'description' => __( 'Email addresses to notify', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
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
			'email',
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
				__( 'You do not have permission to manage stock alerts.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'check';

		switch ( $action ) {
			case 'check':
				return $this->check_low_stock( $arguments );
			case 'send_alerts':
				return $this->send_stock_alerts( $arguments );
			case 'get_report':
				return $this->get_stock_report( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Check for low stock products.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function check_low_stock( $arguments ) {
		$threshold            = isset( $arguments['threshold'] ) ? absint( $arguments['threshold'] ) : 5;
		$include_out_of_stock = isset( $arguments['include_out_of_stock'] ) ? (bool) $arguments['include_out_of_stock'] : true;
		$category_ids         = isset( $arguments['category_ids'] ) && is_array( $arguments['category_ids'] ) ? array_map( 'absint', $arguments['category_ids'] ) : array();

		$query_args = array(
			'post_type'      => 'product',
			'post_status'    => 'publish',
			'posts_per_page' => -1,
			'meta_query'     => array(
				'relation' => 'AND',
				array(
					'key'     => '_manage_stock',
					'value'   => 'yes',
					'compare' => '=',
				),
			),
		);

		// Add stock status condition.
		$stock_condition = array(
			'key'     => '_stock',
			'value'   => $threshold,
			'type'    => 'NUMERIC',
			'compare' => '<=',
		);

		if ( ! $include_out_of_stock ) {
			$stock_condition['compare'] = '<';
			$stock_condition['value']   = $threshold;

			$query_args['meta_query'][] = array(
				'key'     => '_stock',
				'value'   => 0,
				'type'    => 'NUMERIC',
				'compare' => '>',
			);
		}

		$query_args['meta_query'][] = $stock_condition;

		// Add category filter.
		if ( ! empty( $category_ids ) ) {
			$query_args['tax_query'] = array(
				array(
					'taxonomy' => 'product_cat',
					'field'    => 'term_id',
					'terms'    => $category_ids,
				),
			);
		}

		$products = get_posts( $query_args );

		$low_stock_products = array();
		foreach ( $products as $product_post ) {
			$product = wc_get_product( $product_post->ID );
			if ( ! $product ) {
				continue;
			}

			$stock_quantity = $product->get_stock_quantity();

			$low_stock_products[] = array(
				'product_id'     => $product->get_id(),
				'product_name'   => $product->get_name(),
				'sku'            => $product->get_sku(),
				'stock_quantity' => $stock_quantity,
				'stock_status'   => $product->get_stock_status(),
				'price'          => $product->get_price(),
				'manage_stock'   => $product->managing_stock(),
			);
		}

		// Sort by stock quantity ascending.
		usort(
			$low_stock_products,
			function ( $a, $b ) {
				return $a['stock_quantity'] <=> $b['stock_quantity'];
			}
		);

		return array(
			'success'            => true,
			'action'             => 'check',
			'threshold'          => $threshold,
			'products_found'     => count( $low_stock_products ),
			'low_stock_products' => $low_stock_products,
			'message'            => sprintf(
				/* translators: 1: Number of products, 2: Threshold */
				__( 'Found %1$d products with stock at or below threshold of %2$d.', 'mcp-ai-wpoos-pro' ),
				count( $low_stock_products ),
				$threshold
			),
		);
	}

	/**
	 * Send stock alerts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function send_stock_alerts( $arguments ) {
		$stock_data = $this->check_low_stock( $arguments );

		if ( is_wp_error( $stock_data ) ) {
			return $stock_data;
		}

		$send_email       = isset( $arguments['send_email'] ) && $arguments['send_email'];
		$email_recipients = isset( $arguments['email_recipients'] ) && is_array( $arguments['email_recipients'] ) ? $arguments['email_recipients'] : array();

		// Default to admin email if no recipients specified.
		if ( empty( $email_recipients ) ) {
			$email_recipients = array( get_option( 'admin_email' ) );
		}

		$emails_sent = 0;
		if ( $send_email && ! empty( $stock_data['low_stock_products'] ) ) {
			$subject = sprintf(
				/* translators: %d: Number of low stock products */
				__( 'Low Stock Alert: %d Products Need Attention', 'mcp-ai-wpoos-pro' ),
				$stock_data['products_found']
			);

			$message = $this->generate_alert_email( $stock_data['low_stock_products'], $stock_data['threshold'] );

			foreach ( $email_recipients as $recipient ) {
				$sent = wp_mail( sanitize_email( $recipient ), $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );
				if ( $sent ) {
					++$emails_sent;
				}
			}
		}

		return array(
			'success'            => true,
			'action'             => 'send_alerts',
			'products_found'     => $stock_data['products_found'],
			'emails_sent'        => $emails_sent,
			'recipients'         => $email_recipients,
			'low_stock_products' => $stock_data['low_stock_products'],
			'message'            => sprintf(
				/* translators: 1: Number of emails, 2: Number of products */
				__( 'Sent %1$d alert emails for %2$d low stock products.', 'mcp-ai-wpoos-pro' ),
				$emails_sent,
				$stock_data['products_found']
			),
		);
	}

	/**
	 * Generate alert email content.
	 *
	 * @param array $products  Low stock products.
	 * @param int   $threshold Threshold value.
	 * @return string Email content.
	 */
	protected function generate_alert_email( $products, $threshold ) {
		$message  = '<html><body>';
		$message .= '<h2>' . __( 'Low Stock Alert', 'mcp-ai-wpoos-pro' ) . '</h2>';
		$message .= sprintf(
			'<p>%s</p>',
			sprintf(
				/* translators: 1: Number of products, 2: Threshold */
				__( 'The following %1$d products have stock levels at or below the threshold of %2$d:', 'mcp-ai-wpoos-pro' ),
				count( $products ),
				$threshold
			)
		);

		$message .= '<table border="1" cellpadding="10" cellspacing="0" style="border-collapse: collapse; width: 100%;">';
		$message .= '<thead><tr>';
		$message .= '<th>' . __( 'Product', 'mcp-ai-wpoos-pro' ) . '</th>';
		$message .= '<th>' . __( 'SKU', 'mcp-ai-wpoos-pro' ) . '</th>';
		$message .= '<th>' . __( 'Stock', 'mcp-ai-wpoos-pro' ) . '</th>';
		$message .= '<th>' . __( 'Status', 'mcp-ai-wpoos-pro' ) . '</th>';
		$message .= '</tr></thead><tbody>';

		foreach ( $products as $product ) {
			$edit_url = admin_url( 'post.php?post=' . $product['product_id'] . '&action=edit' );

			$message .= '<tr>';
			$message .= sprintf( '<td><a href="%s">%s</a></td>', esc_url( $edit_url ), esc_html( $product['product_name'] ) );
			$message .= '<td>' . esc_html( $product['sku'] ) . '</td>';
			$message .= '<td style="' . ( 0 === $product['stock_quantity'] ? 'color: red; font-weight: bold;' : 'color: orange;' ) . '">' . absint( $product['stock_quantity'] ) . '</td>';
			$message .= '<td>' . esc_html( ucfirst( $product['stock_status'] ) ) . '</td>';
			$message .= '</tr>';
		}

		$message .= '</tbody></table>';
		$message .= '<p>' . __( 'Please review and restock these products as needed.', 'mcp-ai-wpoos-pro' ) . '</p>';
		$message .= '</body></html>';

		return $message;
	}

	/**
	 * Get stock report.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_stock_report( $arguments ) {
		$stock_data = $this->check_low_stock( $arguments );

		if ( is_wp_error( $stock_data ) ) {
			return $stock_data;
		}

		// Calculate summary statistics.
		$out_of_stock = 0;
		$critical     = 0; // 0-2 items.
		$low          = 0; // 3-5 items.
		$warning      = 0; // 6+ items but below threshold.

		foreach ( $stock_data['low_stock_products'] as $product ) {
			$qty = $product['stock_quantity'];
			if ( 0 === $qty ) {
				++$out_of_stock;
			} elseif ( $qty <= 2 ) {
				++$critical;
			} elseif ( $qty <= 5 ) {
				++$low;
			} else {
				++$warning;
			}
		}

		return array(
			'success' => true,
			'action'  => 'get_report',
			'report'  => array(
				'threshold'      => $stock_data['threshold'],
				'total_flagged'  => $stock_data['products_found'],
				'out_of_stock'   => $out_of_stock,
				'critical_stock' => $critical,
				'low_stock'      => $low,
				'warning_stock'  => $warning,
				'products'       => $stock_data['low_stock_products'],
				'generated_at'   => current_time( 'mysql' ),
			),
			'message' => __( 'Stock report generated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
