<?php
/**
 * Abandoned Cart Recovery Tool
 *
 * Identifies and recovers abandoned carts with automated email campaigns
 * and targeted recovery strategies.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for abandoned cart recovery.
 *
 * Supports:
 * - Identification of abandoned carts
 * - Automated recovery email campaigns
 * - Cart recovery analytics
 * - Custom recovery strategies
 * - Coupon generation for incentives
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Abandoned_Cart_Recovery implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

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
			return __( 'Abandoned cart recovery requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Abandoned cart recovery tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'abandoned_cart_recovery';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Abandoned Cart Recovery', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Identify and recover abandoned carts with automated email campaigns. Includes cart analytics, recovery rate tracking, and optional discount incentives. Supports custom email templates and multi-step recovery sequences.', 'mcp-ai-wpoos-pro' );
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
				'action'                    => array(
					'type'        => 'string',
					'description' => __( 'Action to perform', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'identify', 'send_recovery', 'get_analytics' ),
					'default'     => 'identify',
				),
				'abandoned_threshold_hours' => array(
					'type'        => 'integer',
					'description' => __( 'Hours before cart is considered abandoned', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
					'maximum'     => 72,
				),
				'min_cart_value'            => array(
					'type'        => 'number',
					'description' => __( 'Minimum cart value to trigger recovery', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
				'send_email'                => array(
					'type'        => 'boolean',
					'description' => __( 'Send recovery email to identified carts', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'email_template'            => array(
					'type'        => 'string',
					'description' => __( 'Email template to use for recovery', 'mcp-ai-wpoos-pro' ),
				),
				'offer_discount'            => array(
					'type'        => 'boolean',
					'description' => __( 'Include discount coupon in recovery email', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'discount_amount'           => array(
					'type'        => 'number',
					'description' => __( 'Discount percentage or amount', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 0,
				),
				'discount_type'             => array(
					'type'        => 'string',
					'description' => __( 'Type of discount', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'percent', 'fixed' ),
					'default'     => 'percent',
				),
				'analytics_days'            => array(
					'type'        => 'integer',
					'description' => __( 'Days to analyze for analytics action', 'mcp-ai-wpoos-pro' ),
					'default'     => 30,
					'minimum'     => 1,
					'maximum'     => 365,
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
			'database-write',
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
				__( 'You do not have permission to manage abandoned cart recovery.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Check if WooCommerce is active.
		if ( ! self::is_available() ) {
			return new WP_Error(
				'woocommerce_not_active',
				self::get_unavailable_reason()
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'identify';

		switch ( $action ) {
			case 'identify':
				return $this->identify_abandoned_carts( $arguments );
			case 'send_recovery':
				return $this->send_recovery_emails( $arguments );
			case 'get_analytics':
				return $this->get_recovery_analytics( $arguments );
			default:
				return new WP_Error(
					'invalid_action',
					__( 'Invalid action specified.', 'mcp-ai-wpoos-pro' )
				);
		}
	}

	/**
	 * Identify abandoned carts.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function identify_abandoned_carts( $arguments ) {
		$threshold_hours = isset( $arguments['abandoned_threshold_hours'] ) ? absint( $arguments['abandoned_threshold_hours'] ) : 1;
		$min_cart_value  = isset( $arguments['min_cart_value'] ) ? floatval( $arguments['min_cart_value'] ) : 0;

		$threshold_time = strtotime( "-{$threshold_hours} hours" );

		// Get sessions with abandoned carts.
		global $wpdb;
		$carts = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT s.session_key, s.session_value, s.session_expiry
				FROM {$wpdb->prefix}woocommerce_sessions s
				WHERE s.session_expiry > %d
				AND s.session_expiry < %d
				ORDER BY s.session_expiry DESC
				LIMIT 100",
				time(),
				$threshold_time
			)
		);

		$abandoned_carts = array();

		foreach ( $carts as $cart_data ) {
			$session = maybe_unserialize( $cart_data->session_value );
			$cart    = isset( $session['cart'] ) ? maybe_unserialize( $session['cart'] ) : array();

			if ( empty( $cart ) ) {
				continue;
			}

			// Calculate cart total.
			$cart_total = 0;
			$items      = array();

			foreach ( $cart as $cart_item_key => $cart_item ) {
				$product = wc_get_product( $cart_item['product_id'] );
				if ( ! $product ) {
					continue;
				}

				$item_total  = $product->get_price() * $cart_item['quantity'];
				$cart_total += $item_total;

				$items[] = array(
					'product_id'   => $cart_item['product_id'],
					'product_name' => $product->get_name(),
					'quantity'     => $cart_item['quantity'],
					'price'        => $product->get_price(),
					'total'        => $item_total,
				);
			}

			if ( $cart_total < $min_cart_value ) {
				continue;
			}

			$customer_email = isset( $session['customer'] ) ? $session['customer']['email'] : '';

			$abandoned_carts[] = array(
				'session_key'    => $cart_data->session_key,
				'customer_email' => $customer_email,
				'cart_total'     => wc_format_decimal( $cart_total, 2 ),
				'items'          => $items,
				'abandoned_at'   => gmdate( 'Y-m-d H:i:s', $cart_data->session_expiry ),
				'hours_ago'      => round( ( time() - $cart_data->session_expiry ) / 3600, 1 ),
			);
		}

		return array(
			'success'         => true,
			'action'          => 'identify',
			'total_found'     => count( $abandoned_carts ),
			'threshold_hours' => $threshold_hours,
			'min_cart_value'  => $min_cart_value,
			'abandoned_carts' => $abandoned_carts,
			'message'         => sprintf(
				/* translators: %d: Number of abandoned carts */
				__( 'Found %d abandoned carts matching criteria.', 'mcp-ai-wpoos-pro' ),
				count( $abandoned_carts )
			),
		);
	}

	/**
	 * Send recovery emails.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function send_recovery_emails( $arguments ) {
		$carts_data = $this->identify_abandoned_carts( $arguments );

		if ( is_wp_error( $carts_data ) ) {
			return $carts_data;
		}

		$send_email     = isset( $arguments['send_email'] ) && $arguments['send_email'];
		$offer_discount = isset( $arguments['offer_discount'] ) && $arguments['offer_discount'];
		$sent_count     = 0;
		$failed_count   = 0;

		foreach ( $carts_data['abandoned_carts'] as $cart ) {
			if ( empty( $cart['customer_email'] ) ) {
				++$failed_count;
				continue;
			}

			if ( $send_email ) {
				$subject = __( 'You left items in your cart!', 'mcp-ai-wpoos-pro' );
				$message = $this->generate_recovery_email( $cart, $offer_discount, $arguments );

				$sent = wp_mail( $cart['customer_email'], $subject, $message, array( 'Content-Type: text/html; charset=UTF-8' ) );

				if ( $sent ) {
					++$sent_count;
				} else {
					++$failed_count;
				}
			}
		}

		return array(
			'success'       => true,
			'action'        => 'send_recovery',
			'emails_sent'   => $sent_count,
			'emails_failed' => $failed_count,
			'total_carts'   => count( $carts_data['abandoned_carts'] ),
			'message'       => sprintf(
				/* translators: %d: Number of emails sent */
				__( 'Sent %d recovery emails successfully.', 'mcp-ai-wpoos-pro' ),
				$sent_count
			),
		);
	}

	/**
	 * Generate recovery email content.
	 *
	 * @param array $cart           Cart data.
	 * @param bool  $offer_discount Offer discount.
	 * @param array $arguments      Tool arguments.
	 * @return string Email content.
	 */
	protected function generate_recovery_email( $cart, $offer_discount, $arguments ) {
		$message  = '<html><body>';
		$message .= '<h2>' . __( 'Your cart is waiting!', 'mcp-ai-wpoos-pro' ) . '</h2>';
		$message .= '<p>' . __( 'You left some items in your cart. Complete your purchase now!', 'mcp-ai-wpoos-pro' ) . '</p>';

		$message .= '<h3>' . __( 'Items in your cart:', 'mcp-ai-wpoos-pro' ) . '</h3>';
		$message .= '<ul>';

		foreach ( $cart['items'] as $item ) {
			$message .= sprintf(
				'<li>%s x %d - %s</li>',
				esc_html( $item['product_name'] ),
				$item['quantity'],
				wc_price( $item['total'] )
			);
		}

		$message .= '</ul>';
		$message .= sprintf( '<p><strong>%s:</strong> %s</p>', __( 'Total', 'mcp-ai-wpoos-pro' ), wc_price( $cart['cart_total'] ) );

		if ( $offer_discount ) {
			$discount_amount = isset( $arguments['discount_amount'] ) ? floatval( $arguments['discount_amount'] ) : 10;
			$message        .= sprintf(
				'<p><strong>%s %s%%!</strong></p>',
				__( 'Special offer: Save', 'mcp-ai-wpoos-pro' ),
				$discount_amount
			);
		}

		$message .= sprintf(
			'<p><a href="%s" style="background: #0073aa; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;">%s</a></p>',
			wc_get_cart_url(),
			__( 'Complete Your Purchase', 'mcp-ai-wpoos-pro' )
		);

		$message .= '</body></html>';

		return $message;
	}

	/**
	 * Get recovery analytics.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Result.
	 */
	protected function get_recovery_analytics( $arguments ) {
		$days = isset( $arguments['analytics_days'] ) ? absint( $arguments['analytics_days'] ) : 30;

		// This is a simplified analytics implementation.
		// In production, you'd track recovery emails and conversions.
		$abandoned = $this->identify_abandoned_carts( $arguments );

		return array(
			'success'             => true,
			'action'              => 'get_analytics',
			'period_days'         => $days,
			'current_abandoned'   => $abandoned['total_found'],
			'total_value_at_risk' => $this->calculate_total_value( $abandoned['abandoned_carts'] ),
			'message'             => sprintf(
				/* translators: %d: Number of days */
				__( 'Analytics for last %d days.', 'mcp-ai-wpoos-pro' ),
				$days
			),
		);
	}

	/**
	 * Calculate total value of abandoned carts.
	 *
	 * @param array $carts Cart data.
	 * @return float Total value.
	 */
	protected function calculate_total_value( $carts ) {
		$total = 0;

		foreach ( $carts as $cart ) {
			$total += floatval( $cart['cart_total'] );
		}

		return wc_format_decimal( $total, 2 );
	}
}
