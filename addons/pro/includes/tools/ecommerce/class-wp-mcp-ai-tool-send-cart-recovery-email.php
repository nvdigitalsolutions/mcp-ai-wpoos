<?php
/**
 * Send Cart Recovery Email Tool
 *
 * Sends cart recovery emails to customers who abandoned their carts,
 * with support for custom templates and dry-run mode.
 *
 * @package WP_MCP_AI_Pro
 * @since 2.8.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for sending cart recovery emails.
 *
 * Sends targeted recovery emails to identified abandoned cart owners.
 * Supports dry-run mode for previewing recipients without actually sending,
 * custom email templates, and optional discount incentive inclusion.
 *
 * @since 2.8.0
 */
class WP_MCP_AI_Tool_Send_Cart_Recovery_Email implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'send_cart_recovery_email';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Send Cart Recovery Email', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends cart recovery emails to customers who abandoned their carts. Supports dry_run mode for previewing the recipients and email content without actually sending. Optionally accepts a custom email template and cart session keys to target specific abandoned carts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'cart_ids'       => array(
					'type'        => 'array',
					'description' => __( 'Array of cart session keys to target. If omitted, all abandoned carts are targeted.', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'email_template' => array(
					'type'        => 'string',
					'description' => __( 'Optional custom email template content. Supports basic HTML. Use {{cart_url}}, {{cart_items}}, {{cart_total}} as merge tags.', 'mcp-ai-wpoos-pro' ),
				),
				'dry_run'        => array(
					'type'        => 'boolean',
					'description' => __( 'If true, previews the recipients and email content without actually sending. Default: true (safe mode).', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_woocommerce';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'ecommerce',
			'post_type'             => 'shop_order',
			'pattern_compatibility' => array( 'orchestrator', 'sequential' ),
			'profession_tags'       => array( 'administrator', 'shop_manager' ),
			'risk_level'            => 'medium',
		);
	}

	/**
	 * Get capability flags for this tool.
	 *
	 * @return array
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'database-read',
			'database-write',
			'requires-plugin',
			'email',
			'requires-capability',
		);
	}

	/**
	 * Check if the tool is available.
	 *
	 * Requires the E-commerce Toolkit to be enabled and WooCommerce to be active.
	 *
	 * @since 2.8.0
	 * @return bool
	 */
	public static function is_available() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return false;
		}

		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		return function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && wp_mcp_ai_is_ecommerce_toolkit_enabled();
	}

	/**
	 * Message explaining why the tool is unavailable.
	 *
	 * @since 2.8.0
	 * @return string
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'The Send Cart Recovery Email tool requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Send Cart Recovery Email tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check permissions.
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			if ( ! user_can( $current_user_id, 'edit_posts' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to send cart recovery emails.', 'mcp-ai-wpoos-pro' )
				);
			}
		}

		// Check if the tool is available.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'tool_not_available',
				self::get_unavailable_reason()
			);
		}

		$cart_ids       = isset( $arguments['cart_ids'] ) ? (array) $arguments['cart_ids'] : array();
		$email_template = isset( $arguments['email_template'] ) ? wp_kses_post( $arguments['email_template'] ) : '';
		$dry_run        = isset( $arguments['dry_run'] ) ? (bool) $arguments['dry_run'] : true;

		// Fetch abandoned carts.
		$abandoned_carts = $this->fetch_abandoned_carts( $cart_ids );

		if ( is_wp_error( $abandoned_carts ) ) {
			return $abandoned_carts;
		}

		if ( empty( $abandoned_carts ) ) {
			return array(
				'success'    => true,
				'dry_run'    => $dry_run,
				'sent_count' => 0,
				'skipped'    => 0,
				'recipients' => array(),
				'message'    => __( 'No abandoned carts found to send recovery emails for.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Dry-run mode: preview recipients without sending.
		if ( $dry_run ) {
			$preview = array();

			foreach ( $abandoned_carts as $cart ) {
				$email_content = $this->build_recovery_email( $cart, $email_template );
				$preview[]     = array(
					'session_key'    => $cart['session_key'],
					'customer_email' => $cart['customer_email'],
					'cart_total'     => $cart['cart_total'],
					'items_count'    => isset( $cart['items_count'] ) ? $cart['items_count'] : count( $cart['items'] ),
					'email_subject'  => $this->get_recovery_subject(),
					'email_preview'  => $email_content,
				);
			}

			return array(
				'success'    => true,
				'dry_run'    => true,
				'recipients' => $preview,
				'message'    => sprintf(
					/* translators: %d: Number of recipients in dry-run mode */
					__( 'Dry-run mode: %d emails would be sent. Set dry_run to false to send.', 'mcp-ai-wpoos-pro' ),
					count( $preview )
				),
			);
		}

		// Send actual emails.
		$sent_count = 0;
		$skipped    = 0;
		$results    = array();

		foreach ( $abandoned_carts as $cart ) {
			if ( empty( $cart['customer_email'] ) ) {
				$results[] = array(
					'session_key' => $cart['session_key'],
					'status'      => 'skipped',
					'reason'      => __( 'No customer email available.', 'mcp-ai-wpoos-pro' ),
				);
				++$skipped;
				continue;
			}

			$subject  = $this->get_recovery_subject();
			$message  = $this->build_recovery_email( $cart, $email_template );
			$headers  = array( 'Content-Type: text/html; charset=UTF-8' );
			$was_sent = wp_mail( $cart['customer_email'], $subject, $message, $headers );

			if ( $was_sent ) {
				$results[] = array(
					'session_key'    => $cart['session_key'],
					'customer_email' => $cart['customer_email'],
					'status'         => 'sent',
				);
				++$sent_count;
			} else {
				$results[] = array(
					'session_key'    => $cart['session_key'],
					'customer_email' => $cart['customer_email'],
					'status'         => 'failed',
					'reason'         => __( 'wp_mail() returned false.', 'mcp-ai-wpoos-pro' ),
				);
				++$skipped;
			}
		}

		return array(
			'success'    => true,
			'dry_run'    => false,
			'sent_count' => $sent_count,
			'skipped'    => $skipped,
			'results'    => $results,
			'message'    => sprintf(
				/* translators: 1: Number sent, 2: Number skipped */
				__( 'Sent %1$d recovery emails, %2$d skipped.', 'mcp-ai-wpoos-pro' ),
				$sent_count,
				$skipped
			),
		);
	}

	/**
	 * Fetch abandoned carts from WooCommerce session data.
	 *
	 * @since 2.8.0
	 *
	 * @param array $cart_ids Optional specific session keys to retrieve.
	 * @return array|WP_Error List of abandoned carts or error.
	 */
	protected function fetch_abandoned_carts( $cart_ids = array() ) {
		global $wpdb;

		$table_name = $wpdb->prefix . 'woocommerce_sessions';

		// Check if the sessions table exists.
		$table_exists = $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table_name ) );

		$where_clauses = array();
		$where_args    = array();

		if ( $table_exists ) {
			// Only expired sessions.
			$where_clauses[] = 'session_expiry < %d';
			$where_args[]    = time();

			if ( ! empty( $cart_ids ) ) {
				$placeholders = array();
				foreach ( $cart_ids as $cart_id ) {
					$placeholders[] = '%s';
					$where_args[]   = sanitize_text_field( $cart_id );
				}
				$where_clauses[] = 'session_key IN (' . implode( ',', $placeholders ) . ')';
			}

			$where_sql = 'WHERE ' . implode( ' AND ', $where_clauses );
			$query     = "SELECT session_key, session_value, session_expiry FROM {$table_name} {$where_sql} ORDER BY session_expiry DESC LIMIT 100";

			// phpcs:ignore WordPress.DB.PreparedSQL.NotPrepared -- table name is safe, query built with %d/%s placeholders.
			$prepared_query = $wpdb->prepare( $query, $where_args );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.NotPrepared
			$sessions = $wpdb->get_results( $prepared_query );
		} else {
			$sessions = array();
		}

		$carts = array();

		foreach ( $sessions as $session_data ) {
			$session = maybe_unserialize( $session_data->session_value );
			if ( ! is_array( $session ) ) {
				continue;
			}

			$cart = isset( $session['cart'] ) ? maybe_unserialize( $session['cart'] ) : array();
			if ( empty( $cart ) ) {
				continue;
			}

			$customer       = isset( $session['customer'] ) ? $session['customer'] : array();
			$customer_email = isset( $customer['email'] ) ? sanitize_email( $customer['email'] ) : '';

			if ( empty( $customer_email ) ) {
				continue;
			}

			$cart_total = 0;
			$items      = array();

			foreach ( $cart as $cart_item_key => $cart_item ) {
				$product_id = isset( $cart_item['product_id'] ) ? absint( $cart_item['product_id'] ) : 0;
				$quantity   = isset( $cart_item['quantity'] ) ? absint( $cart_item['quantity'] ) : 0;
				$variation  = isset( $cart_item['variation_id'] ) ? absint( $cart_item['variation_id'] ) : 0;

				$product = wc_get_product( $variation ? $variation : $product_id );
				if ( ! $product ) {
					continue;
				}

				$price       = floatval( $product->get_price() );
				$item_total  = $price * $quantity;
				$cart_total += $item_total;

				$items[] = array(
					'product_id'   => $product_id,
					'product_name' => $product->get_name(),
					'quantity'     => $quantity,
					'price'        => wc_format_decimal( $price, 2 ),
					'total'        => wc_format_decimal( $item_total, 2 ),
				);
			}

			$carts[] = array(
				'session_key'    => sanitize_text_field( $session_data->session_key ),
				'customer_email' => $customer_email,
				'cart_total'     => wc_format_decimal( $cart_total, 2 ),
				'items_count'    => count( $items ),
				'items'          => $items,
				'session_expiry' => absint( $session_data->session_expiry ),
			);
		}

		return $carts;
	}

	/**
	 * Get the recovery email subject line.
	 *
	 * @since 2.8.0
	 *
	 * @return string Email subject.
	 */
	protected function get_recovery_subject() {
		return __( 'You left items in your cart — come back and complete your order!', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Build the recovery email HTML content.
	 *
	 * @since 2.8.0
	 *
	 * @param array  $cart           Cart data.
	 * @param string $email_template Optional custom template.
	 * @return string HTML email body.
	 */
	protected function build_recovery_email( $cart, $email_template = '' ) {
		$cart_url   = wc_get_cart_url();
		$cart_items = '';
		$items      = isset( $cart['items'] ) ? $cart['items'] : array();

		foreach ( $items as $item ) {
			$cart_items .= sprintf(
				'<li>%s &times; %d — %s</li>',
				esc_html( $item['product_name'] ),
				absint( $item['quantity'] ),
				wp_kses_post( wc_price( floatval( $item['total'] ) ) )
			);
		}

		// If a custom template is provided, use it with merge tags.
		if ( ! empty( $email_template ) ) {
			$replacements = array(
				'{{cart_url}}'   => esc_url( $cart_url ),
				'{{cart_items}}' => $cart_items,
				'{{cart_total}}' => wp_kses_post( wc_price( floatval( $cart['cart_total'] ) ) ),
			);

			return str_replace( array_keys( $replacements ), array_values( $replacements ), $email_template );
		}

		// Default email template.
		$message  = '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body>';
		$message .= '<h2>' . esc_html__( 'Your cart is waiting for you!', 'mcp-ai-wpoos-pro' ) . '</h2>';
		$message .= '<p>' . esc_html__( 'We noticed you left some items in your shopping cart. Don\'t worry — we saved them for you!', 'mcp-ai-wpoos-pro' ) . '</p>';
		$message .= '<h3>' . esc_html__( 'Items in your cart:', 'mcp-ai-wpoos-pro' ) . '</h3>';
		$message .= '<ul>' . $cart_items . '</ul>';
		$message .= '<p><strong>' . esc_html__( 'Cart Total:', 'mcp-ai-wpoos-pro' ) . ' ' . wp_kses_post( wc_price( floatval( $cart['cart_total'] ) ) ) . '</strong></p>';
		$message .= '<p>' . esc_html__( 'Click the button below to return to your cart and complete your purchase.', 'mcp-ai-wpoos-pro' ) . '</p>';
		$message .= '<p><a href="' . esc_url( $cart_url ) . '" style="display:inline-block;background:#7f54b3;color:#ffffff;padding:12px 24px;text-decoration:none;border-radius:4px;font-weight:bold;">'
			. esc_html__( 'Return to Cart', 'mcp-ai-wpoos-pro' ) . '</a></p>';
		$message .= '<p style="font-size:0.9em;color:#666;">' . esc_html__( 'If you have questions, reply to this email.', 'mcp-ai-wpoos-pro' ) . '</p>';
		$message .= '</body></html>';

		return $message;
	}
}
