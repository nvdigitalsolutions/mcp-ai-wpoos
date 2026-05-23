<?php
/**
 * Shipping Rate Estimator Tool
 *
 * Estimates shipping rates by packing items into optimal boxes and rate-shopping
 * across carriers via ShipEngine or ShipStation APIs. Supports USPS Priority Mail
 * cubic and flat-rate pricing, PirateShip CSV export format, and WooCommerce order
 * shipping plan generation.
 *
 * Inspired by the nv-boxpacker plugin (ShipEngine/ShipStation services).
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for estimating shipping rates with box-packing optimization.
 *
 * Supports:
 * - ShipEngine and ShipStation API integration
 * - USPS Priority Mail cubic and flat-rate pricing
 * - Automatic box-packing with rate comparison
 * - WooCommerce order rate estimation
 * - PirateShip-compatible CSV export data
 * - Ship-from/ship-to address configuration
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Shipping_Rate_Estimator implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.2.0
	 *
	 * @return bool True if WooCommerce is active and toolkit is enabled.
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
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.2.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WooCommerce' ) ) {
			return __( 'Shipping rate estimator requires WooCommerce to be installed and activated.', 'mcp-ai-wpoos-pro' );
		}

		if ( function_exists( 'wp_mcp_ai_is_ecommerce_toolkit_enabled' ) && ! wp_mcp_ai_is_ecommerce_toolkit_enabled() ) {
			return __( 'E-commerce toolkit is not enabled. Please enable it in plugin settings.', 'mcp-ai-wpoos-pro' );
		}

		return __( 'Shipping rate estimator tool is not available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'shipping_rate_estimator';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Shipping Rate Estimator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Estimate shipping rates by packing items into optimal boxes and rate-shopping across carriers via ShipStation API (formerly ShipEngine) or legacy ShipStation V1 API. Supports USPS Priority Mail cubic and flat-rate pricing. Provide items with dimensions and a destination address to get per-package rate estimates with packing plans. ShipStation API is the recommended default.', 'mcp-ai-wpoos-pro' );
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
				'action'          => array(
					'type'        => 'string',
					'description' => __( 'Rate estimation action to perform.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'estimate_rates', 'estimate_order_rates', 'test_connection' ),
					'default'     => 'estimate_rates',
				),
				'carrier'         => array(
					'type'        => 'string',
					'description' => __( 'Carrier API to use for rate-shopping. Defaults to "shipengine" (ShipStation API). Use "shipstation" for legacy ShipStation V1 API.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'shipengine', 'shipstation' ),
				),
				'order_id'        => array(
					'type'        => 'integer',
					'description' => __( 'WooCommerce order ID (required for estimate_order_rates action).', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'items'           => array(
					'type'        => 'array',
					'description' => __( 'Items to pack and rate (required for estimate_rates action).', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'      => array(
								'type'        => 'string',
								'description' => __( 'Item name.', 'mcp-ai-wpoos-pro' ),
							),
							'length'    => array(
								'type'        => 'number',
								'description' => __( 'Length in inches.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'width'     => array(
								'type'        => 'number',
								'description' => __( 'Width in inches.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'height'    => array(
								'type'        => 'number',
								'description' => __( 'Height in inches.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'weight_oz' => array(
								'type'        => 'number',
								'description' => __( 'Weight in ounces.', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 0.1,
							),
							'quantity'  => array(
								'type'        => 'integer',
								'description' => __( 'Quantity (default: 1).', 'mcp-ai-wpoos-pro' ),
								'minimum'     => 1,
								'default'     => 1,
							),
						),
						'required'   => array( 'name', 'length', 'width', 'height', 'weight_oz' ),
					),
				),
				'ship_to'         => array(
					'type'        => 'object',
					'description' => __( 'Destination address for rate calculation.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'name'          => array(
							'type'        => 'string',
							'description' => __( 'Recipient name.', 'mcp-ai-wpoos-pro' ),
						),
						'company'       => array(
							'type'        => 'string',
							'description' => __( 'Company name (optional).', 'mcp-ai-wpoos-pro' ),
						),
						'address_line1' => array(
							'type'        => 'string',
							'description' => __( 'Street address.', 'mcp-ai-wpoos-pro' ),
						),
						'address_line2' => array(
							'type'        => 'string',
							'description' => __( 'Suite/unit (optional).', 'mcp-ai-wpoos-pro' ),
						),
						'city'          => array(
							'type'        => 'string',
							'description' => __( 'City.', 'mcp-ai-wpoos-pro' ),
						),
						'state'         => array(
							'type'        => 'string',
							'description' => __( 'State/province code (e.g. "CA").', 'mcp-ai-wpoos-pro' ),
						),
						'postal_code'   => array(
							'type'        => 'string',
							'description' => __( 'ZIP/postal code.', 'mcp-ai-wpoos-pro' ),
						),
						'country_code'  => array(
							'type'        => 'string',
							'description' => __( 'Two-letter country code (default: "US").', 'mcp-ai-wpoos-pro' ),
							'default'     => 'US',
						),
					),
					'required'    => array( 'postal_code' ),
				),
				'ship_from'       => array(
					'type'        => 'object',
					'description' => __( 'Origin address override (optional, reads from plugin settings if not provided).', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'name'          => array(
							'type'        => 'string',
							'description' => __( 'Sender name.', 'mcp-ai-wpoos-pro' ),
						),
						'company'       => array(
							'type'        => 'string',
							'description' => __( 'Company name.', 'mcp-ai-wpoos-pro' ),
						),
						'address_line1' => array(
							'type'        => 'string',
							'description' => __( 'Street address.', 'mcp-ai-wpoos-pro' ),
						),
						'city'          => array(
							'type'        => 'string',
							'description' => __( 'City.', 'mcp-ai-wpoos-pro' ),
						),
						'state'         => array(
							'type'        => 'string',
							'description' => __( 'State/province code.', 'mcp-ai-wpoos-pro' ),
						),
						'postal_code'   => array(
							'type'        => 'string',
							'description' => __( 'ZIP/postal code.', 'mcp-ai-wpoos-pro' ),
						),
						'country_code'  => array(
							'type'        => 'string',
							'description' => __( 'Country code (default: "US").', 'mcp-ai-wpoos-pro' ),
							'default'     => 'US',
						),
					),
				),
				'api_credentials' => array(
					'type'        => 'object',
					'description' => __( 'API credentials override (optional, reads from remote connections or plugin settings if not provided). For ShipStation API (shipengine): api_key + carrier_id. For ShipStation V1 (shipstation): api_key + api_secret + carrier_code.', 'mcp-ai-wpoos-pro' ),
					'properties'  => array(
						'shipengine_api_key'       => array(
							'type'        => 'string',
							'description' => __( 'ShipEngine API key.', 'mcp-ai-wpoos-pro' ),
						),
						'shipengine_carrier_id'    => array(
							'type'        => 'string',
							'description' => __( 'ShipEngine carrier ID (e.g. "se-123456").', 'mcp-ai-wpoos-pro' ),
						),
						'shipstation_api_key'      => array(
							'type'        => 'string',
							'description' => __( 'ShipStation API key.', 'mcp-ai-wpoos-pro' ),
						),
						'shipstation_api_secret'   => array(
							'type'        => 'string',
							'description' => __( 'ShipStation API secret.', 'mcp-ai-wpoos-pro' ),
						),
						'shipstation_carrier_code' => array(
							'type'        => 'string',
							'description' => __( 'ShipStation carrier code (default: "stamps_com").', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
			),
			'required'   => array( 'action' ),
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
			'requires-plugin',
			'external-api',
			'network-dependent',
			'requires-credentials',
			'rate-limited',
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
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_woocommerce' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the shipping rate estimator.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'estimate_rates';

		switch ( $action ) {
			case 'estimate_rates':
				return $this->handle_estimate_rates( $arguments );

			case 'estimate_order_rates':
				return $this->handle_estimate_order_rates( $arguments );

			case 'test_connection':
				return $this->handle_test_connection( $arguments );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_action',
					/* translators: %s: action name */
					sprintf( __( 'Invalid action: %s. Use estimate_rates, estimate_order_rates, or test_connection.', 'mcp-ai-wpoos-pro' ), $action )
				);
		}
	}

	/**
	 * Handle the estimate_rates action.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_estimate_rates( array $arguments ) {
		if ( empty( $arguments['items'] ) || ! is_array( $arguments['items'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_items',
				__( 'Items array is required for rate estimation.', 'mcp-ai-wpoos-pro' )
			);
		}

		if ( empty( $arguments['ship_to'] ) || ! is_array( $arguments['ship_to'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_destination',
				__( 'ship_to address with at least postal_code is required for rate estimation.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Use the box packer tool to pack items first.
		$packer      = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$pack_result = $packer->execute(
			array(
				'action' => 'pack_items',
				'items'  => $arguments['items'],
				'boxes'  => isset( $arguments['boxes'] ) ? $arguments['boxes'] : array(),
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $pack_result ) ) {
			return $pack_result;
		}

		if ( empty( $pack_result['packages'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_packing_failed',
				__( 'Unable to pack items into any available boxes.', 'mcp-ai-wpoos-pro' )
			);
		}

		$carrier   = $this->get_carrier( $arguments );
		$ship_to   = $this->build_ship_to_address( $arguments['ship_to'] );
		$ship_from = $this->get_ship_from_address( $arguments );
		$creds     = $this->get_credentials( $arguments, $carrier );

		$result = array(
			'success'           => true,
			'carrier'           => $carrier,
			'total_items'       => $pack_result['total_items'],
			'total_packages'    => $pack_result['total_packages'],
			'total_rate_amount' => 0.0,
			'currency'          => 'USD',
			'sandbox_mode'      => ! empty( $creds['sandbox_mode'] ),
			'packages'          => array(),
			'pirateship_rows'   => array(),
			'warnings'          => array(),
		);

		foreach ( $pack_result['packages'] as $package ) {
			$rate_result = $this->get_rate_for_package( $package, $ship_to, $ship_from, $carrier, $creds );

			if ( is_wp_error( $rate_result ) ) {
				$result['warnings'][] = sprintf(
					/* translators: 1: package number, 2: error message */
					__( 'Package %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$package['package_number'],
					$rate_result->get_error_message()
				);
				// Include packing data even without rate.
				$result['packages'][] = array_merge(
					$package,
					array(
						'rate_amount' => null,
						'rate_error'  => $rate_result->get_error_message(),
					)
				);
				continue;
			}

			$rated_package                = array_merge( $package, $rate_result );
			$result['packages'][]         = $rated_package;
			$result['total_rate_amount'] += (float) $rate_result['rate_amount'];
			$result['currency']           = $rate_result['currency'];

			// Build PirateShip-compatible row.
			$result['pirateship_rows'][] = array(
				'package_number' => $package['package_number'],
				'carrier'        => 'USPS',
				'service'        => 'Priority Mail',
				'package_type'   => $package['package_code'],
				'package_name'   => $package['package_name'],
				'weight_oz'      => $package['weight_oz'],
				'length'         => $package['dimensions']['length'],
				'width'          => $package['dimensions']['width'],
				'height'         => $package['dimensions']['height'],
				'rate_amount'    => $rate_result['rate_amount'],
				'packing_list'   => implode( '; ', $package['packing_list'] ),
			);
		}

		$result['total_rate_amount'] = round( $result['total_rate_amount'], 2 );

		$result['message'] = sprintf(
			/* translators: 1: number of packages, 2: formatted rate amount, 3: carrier name */
			__( 'Estimated %1$d package(s) at $%2$s total via %3$s.', 'mcp-ai-wpoos-pro' ),
			count( $result['packages'] ),
			number_format( $result['total_rate_amount'], 2 ),
			ucfirst( $carrier )
		);

		return $result;
	}

	/**
	 * Handle the estimate_order_rates action.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_estimate_order_rates( array $arguments ) {
		if ( empty( $arguments['order_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_order_id',
				__( 'order_id is required for order rate estimation.', 'mcp-ai-wpoos-pro' )
			);
		}

		$order = wc_get_order( absint( $arguments['order_id'] ) );

		if ( ! $order instanceof WC_Order ) {
			return new WP_Error(
				'wp_mcp_ai_order_not_found',
				/* translators: %d: order ID */
				sprintf( __( 'Order #%d not found.', 'mcp-ai-wpoos-pro' ), absint( $arguments['order_id'] ) )
			);
		}

		// Use the box packer to pack order items.
		$packer      = new WP_MCP_AI_Tool_Shipping_Box_Packer();
		$pack_result = $packer->execute(
			array(
				'action'   => 'pack_order',
				'order_id' => $order->get_id(),
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( is_wp_error( $pack_result ) ) {
			return $pack_result;
		}

		if ( empty( $pack_result['packages'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_packing_failed',
				__( 'Unable to pack order items.', 'mcp-ai-wpoos-pro' )
			);
		}

		$carrier   = $this->get_carrier( $arguments );
		$ship_to   = $this->build_order_ship_to( $order );
		$ship_from = $this->get_ship_from_address( $arguments );
		$creds     = $this->get_credentials( $arguments, $carrier );

		$result = array(
			'success'           => true,
			'carrier'           => $carrier,
			'order_id'          => $order->get_id(),
			'order_number'      => $order->get_order_number(),
			'total_items'       => $pack_result['total_items'],
			'total_packages'    => $pack_result['total_packages'],
			'total_rate_amount' => 0.0,
			'currency'          => 'USD',
			'sandbox_mode'      => ! empty( $creds['sandbox_mode'] ),
			'packages'          => array(),
			'pirateship_rows'   => array(),
			'warnings'          => array(),
		);

		foreach ( $pack_result['packages'] as $package ) {
			$rate_result = $this->get_rate_for_package( $package, $ship_to, $ship_from, $carrier, $creds );

			if ( is_wp_error( $rate_result ) ) {
				$result['warnings'][] = sprintf(
					/* translators: 1: package number, 2: error message */
					__( 'Package %1$d: %2$s', 'mcp-ai-wpoos-pro' ),
					$package['package_number'],
					$rate_result->get_error_message()
				);
				$result['packages'][] = array_merge(
					$package,
					array(
						'rate_amount' => null,
						'rate_error'  => $rate_result->get_error_message(),
					)
				);
				continue;
			}

			$rated_package                = array_merge( $package, $rate_result );
			$result['packages'][]         = $rated_package;
			$result['total_rate_amount'] += (float) $rate_result['rate_amount'];
			$result['currency']           = $rate_result['currency'];

			$result['pirateship_rows'][] = array(
				'order_number'   => $order->get_order_number(),
				'package_number' => $package['package_number'],
				'recipient_name' => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
				'carrier'        => 'USPS',
				'service'        => 'Priority Mail',
				'package_type'   => $package['package_code'],
				'weight_oz'      => $package['weight_oz'],
				'length'         => $package['dimensions']['length'],
				'width'          => $package['dimensions']['width'],
				'height'         => $package['dimensions']['height'],
				'rate_amount'    => $rate_result['rate_amount'],
				'packing_list'   => implode( '; ', $package['packing_list'] ),
			);
		}

		$result['total_rate_amount'] = round( $result['total_rate_amount'], 2 );

		$result['message'] = sprintf(
			/* translators: 1: order number, 2: number of packages, 3: formatted rate amount */
			__( 'Order #%1$s: %2$d package(s) estimated at $%3$s total.', 'mcp-ai-wpoos-pro' ),
			$order->get_order_number(),
			count( $result['packages'] ),
			number_format( $result['total_rate_amount'], 2 )
		);

		return $result;
	}

	/**
	 * Handle the test_connection action.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error
	 */
	protected function handle_test_connection( array $arguments ) {
		$carrier = $this->get_carrier( $arguments );
		$creds   = $this->get_credentials( $arguments, $carrier );

		if ( 'shipengine' === $carrier ) {
			return $this->test_shipengine_connection( $creds );
		}

		return $this->test_shipstation_connection( $creds );
	}

	/**
	 * Get a shipping rate for a packed package from the carrier API.
	 *
	 * @param array  $package   Packed package data from the box packer.
	 * @param array  $ship_to   Formatted ship-to address.
	 * @param array  $ship_from Formatted ship-from address.
	 * @param string $carrier   Carrier identifier ('shipengine' or 'shipstation').
	 * @param array  $creds     API credentials.
	 * @return array|WP_Error Rate result or error.
	 */
	protected function get_rate_for_package( array $package, array $ship_to, array $ship_from, string $carrier, array $creds ) {
		if ( 'shipengine' === $carrier ) {
			return $this->get_shipengine_rate( $package, $ship_to, $ship_from, $creds );
		}

		return $this->get_shipstation_rate( $package, $ship_to, $ship_from, $creds );
	}

	/**
	 * Get User-Agent string for carrier API requests.
	 *
	 * Industry standard: identify the integration software by name and version
	 * so carrier support can diagnose issues from specific integrations.
	 *
	 * @since 1.2.0
	 *
	 * @return string
	 */
	protected function get_user_agent() {
		$version = defined( 'WP_MCP_AI_PRO_VERSION' ) ? WP_MCP_AI_PRO_VERSION : '1.0.0';
		return 'NV-oOS/' . $version . ' (WordPress/' . get_bloginfo( 'version' ) . '; +' . home_url() . ')';
	}

	/**
	 * Make an HTTP request with automatic retry on 429 rate-limit responses.
	 *
	 * ShipEngine (ShipStation API) enforces rate limits (200 req/min production,
	 * 20 req/min sandbox). This method honours the Retry-After header and retries
	 * up to the configured maximum to avoid transient failures during peak usage.
	 *
	 * Note: sleep() is intentionally used for synchronous blocking within a single
	 * AI tool execution context. Rate-limit retries are short-lived (≤10 s) and
	 * bounded (≤2 retries), making background scheduling unnecessary.
	 *
	 * @since 1.2.0
	 *
	 * @param string $url     Request URL.
	 * @param array  $args    Wp_remote_request() arguments (must include 'method').
	 * @param int    $retries Maximum number of retries (default 2).
	 * @return array|WP_Error
	 */
	protected function request_with_retry( string $url, array $args, int $retries = 2 ) {
		$response = wp_remote_request( $url, $args );

		for ( $attempt = 0; $attempt < $retries; $attempt++ ) {
			if ( is_wp_error( $response ) ) {
				break;
			}

			$code = (int) wp_remote_retrieve_response_code( $response );

			if ( 429 !== $code ) {
				break;
			}

			// Honour the Retry-After header (seconds to wait) per industry standard.
			$retry_after = (int) wp_remote_retrieve_header( $response, 'retry-after' );
			$retry_after = max( $retry_after, 1 ); // Minimum 1 second.
			$retry_after = min( $retry_after, 10 ); // Cap at 10 seconds.

			sleep( $retry_after ); // Intentional blocking; see method docblock.

			$response = wp_remote_request( $url, $args );
		}

		return $response;
	}

	/**
	 * Parse a structured error message from a carrier API JSON response body.
	 *
	 * Both ShipEngine and ShipStation return error details in their response bodies.
	 * This method extracts human-readable messages for better diagnostics.
	 *
	 * @since 1.2.0
	 *
	 * @param array|null $body    Decoded JSON response body.
	 * @param string     $carrier Carrier identifier.
	 * @param int        $code    HTTP status code.
	 * @return string Formatted error message.
	 */
	protected function parse_api_error( $body, string $carrier, int $code ) {
		if ( is_array( $body ) ) {
			// ShipEngine structured errors.
			if ( ! empty( $body['errors'] ) && is_array( $body['errors'] ) ) {
				$messages = array();
				foreach ( $body['errors'] as $err ) {
					if ( isset( $err['message'] ) ) {
						$messages[] = $err['message'];
					}
				}
				if ( ! empty( $messages ) ) {
					return implode( '; ', $messages );
				}
			}

			// ShipStation V1 error message.
			if ( ! empty( $body['Message'] ) ) {
				return (string) $body['Message'];
			}

			// Generic message field.
			if ( ! empty( $body['message'] ) ) {
				return (string) $body['message'];
			}
		}

		$label = 'shipengine' === $carrier ? 'ShipEngine' : 'ShipStation';
		/* translators: 1: carrier name, 2: HTTP status code */
		return sprintf( __( '%1$s returned HTTP %2$d.', 'mcp-ai-wpoos-pro' ), $label, $code );
	}

	/**
	 * Get a rate from ShipEngine API.
	 *
	 * @param array $package   Packed package data.
	 * @param array $ship_to   Ship-to address.
	 * @param array $ship_from Ship-from address.
	 * @param array $creds     API credentials.
	 * @return array|WP_Error
	 */
	protected function get_shipengine_rate( array $package, array $ship_to, array $ship_from, array $creds ) {
		$api_key    = $creds['shipengine_api_key'] ?? '';
		$carrier_id = $creds['shipengine_carrier_id'] ?? '';

		if ( '' === $api_key || '' === $carrier_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'ShipEngine API key and carrier ID are required. Configure them in WooCommerce > USPS Optimizer settings or pass via api_credentials parameter.', 'mcp-ai-wpoos-pro' )
			);
		}

		$payload = array(
			'rate_options' => array(
				'carrier_ids' => array( $carrier_id ),
			),
			'shipment'     => array(
				'validate_address' => 'no_validation',
				'ship_to'          => $ship_to,
				'ship_from'        => $ship_from,
				'packages'         => array(
					array(
						'package_code' => $package['package_code'],
						'weight'       => array(
							'value' => round( (float) $package['weight_oz'], 2 ),
							'unit'  => 'ounce',
						),
						'dimensions'   => array(
							'unit'   => 'inch',
							'length' => $package['dimensions']['length'],
							'width'  => $package['dimensions']['width'],
							'height' => $package['dimensions']['height'],
						),
					),
				),
				'service_code'     => 'usps_priority_mail',
			),
		);

		$response = $this->request_with_retry(
			'https://api.shipengine.com/v1/rates',
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'API-Key'      => $api_key,
					'Content-Type' => 'application/json',
					'User-Agent'   => $this->get_user_agent(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				/* translators: %s: error message */
				sprintf( __( 'ShipEngine request failed: %s', 'mcp-ai-wpoos-pro' ), $response->get_error_message() )
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				$this->parse_api_error( $body, 'shipengine', $code )
			);
		}

		$rates = $body['rate_response']['rates'] ?? array();

		if ( empty( $rates ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_rates',
				__( 'ShipEngine returned no rates for this package configuration.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Sort by price ascending and pick cheapest.
		usort(
			$rates,
			static function ( array $a, array $b ) {
				return (float) $a['shipping_amount']['amount'] <=> (float) $b['shipping_amount']['amount'];
			}
		);

		$best = $rates[0];

		return array(
			'rate_amount'  => (float) $best['shipping_amount']['amount'],
			'currency'     => (string) ( $best['shipping_amount']['currency'] ?? 'USD' ),
			'service_code' => 'usps_priority_mail',
			'carrier_id'   => $carrier_id,
		);
	}

	/**
	 * Get a rate from ShipStation API.
	 *
	 * @param array $package   Packed package data.
	 * @param array $ship_to   Ship-to address.
	 * @param array $ship_from Ship-from address.
	 * @param array $creds     API credentials.
	 * @return array|WP_Error
	 */
	protected function get_shipstation_rate( array $package, array $ship_to, array $ship_from, array $creds ) {
		$api_key      = $creds['shipstation_api_key'] ?? '';
		$api_secret   = $creds['shipstation_api_secret'] ?? '';
		$carrier_code = $creds['shipstation_carrier_code'] ?? 'stamps_com';

		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error(
				'wp_mcp_ai_missing_credentials',
				__( 'ShipStation API key and secret are required. Configure them in WooCommerce > USPS Optimizer settings or pass via api_credentials parameter.', 'mcp-ai-wpoos-pro' )
			);
		}

		$payload = array(
			'carrierCode'    => $carrier_code,
			'serviceCode'    => null,
			'packageCode'    => null,
			'fromPostalCode' => $ship_from['postal_code'] ?? '',
			'toState'        => $ship_to['state_province'] ?? '',
			'toCountry'      => $ship_to['country_code'] ?? 'US',
			'toPostalCode'   => $ship_to['postal_code'] ?? '',
			'toCity'         => $ship_to['city_locality'] ?? '',
			'weight'         => array(
				'value' => round( (float) $package['weight_oz'], 2 ),
				'units' => 'ounces',
			),
			'dimensions'     => array(
				'units'  => 'inches',
				'length' => $package['dimensions']['length'],
				'width'  => $package['dimensions']['width'],
				'height' => $package['dimensions']['height'],
			),
			'confirmation'   => 'none',
			'residential'    => false,
		);

		$auth = base64_encode( $api_key . ':' . $api_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Basic-Auth encoding.

		/**
		 * Filter the ShipStation API base URL.
		 *
		 * @since 1.2.0
		 *
		 * @param string $url Default ShipStation API base URL.
		 */
		$api_url  = (string) apply_filters( 'wp_mcp_ai_shipstation_api_url', 'https://ssapi.shipstation.com' );
		$endpoint = trailingslashit( $api_url ) . 'shipments/getrates';

		$response = $this->request_with_retry(
			$endpoint,
			array(
				'method'  => 'POST',
				'timeout' => 30,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
					'User-Agent'    => $this->get_user_agent(),
				),
				'body'    => wp_json_encode( $payload ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				/* translators: %s: error message */
				sprintf( __( 'ShipStation request failed: %s', 'mcp-ai-wpoos-pro' ), $response->get_error_message() )
			);
		}

		$code  = (int) wp_remote_retrieve_response_code( $response );
		$body  = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$rates = is_array( $body ) ? $body : array();

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_api_error',
				$this->parse_api_error( $body, 'shipstation', $code )
			);
		}

		if ( empty( $rates ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_rates',
				__( 'ShipStation returned no rates for this package configuration.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Pick cheapest rate.
		usort(
			$rates,
			static function ( array $a, array $b ) {
				$cost_a = (float) $a['shipmentCost'] + (float) ( $a['otherCost'] ?? 0 );
				$cost_b = (float) $b['shipmentCost'] + (float) ( $b['otherCost'] ?? 0 );
				return $cost_a <=> $cost_b;
			}
		);

		$best = $rates[0];

		return array(
			'rate_amount'  => (float) $best['shipmentCost'],
			'currency'     => 'USD',
			'service_code' => (string) ( $best['serviceCode'] ?? 'usps_priority_mail' ),
			'carrier_code' => $carrier_code,
		);
	}

	/**
	 * Test ShipEngine API connection.
	 *
	 * @param array $creds API credentials.
	 * @return array
	 */
	protected function test_shipengine_connection( array $creds ) {
		$api_key    = $creds['shipengine_api_key'] ?? '';
		$carrier_id = $creds['shipengine_carrier_id'] ?? '';

		if ( '' === $api_key ) {
			return array(
				'success' => false,
				'message' => __( 'ShipEngine API key is not configured.', 'mcp-ai-wpoos-pro' ),
				'carrier' => 'shipengine',
			);
		}

		$response = $this->request_with_retry(
			'https://api.shipengine.com/v1/carriers',
			array(
				'method'  => 'GET',
				'timeout' => 15,
				'headers' => array(
					'API-Key'      => $api_key,
					'Content-Type' => 'application/json',
					'User-Agent'   => $this->get_user_agent(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				'carrier' => 'shipengine',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid ShipEngine API key.', 'mcp-ai-wpoos-pro' ),
				'carrier' => 'shipengine',
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'message' => $this->parse_api_error( $body, 'shipengine', $code ),
				'carrier' => 'shipengine',
			);
		}

		$carriers = $body['carriers'] ?? array();

		// If carrier_id provided, verify it exists.
		if ( '' !== $carrier_id ) {
			$found = false;
			foreach ( $carriers as $c ) {
				if ( isset( $c['carrier_id'] ) && $c['carrier_id'] === $carrier_id ) {
					$found = true;
					break;
				}
			}

			if ( ! $found ) {
				return array(
					'success' => false,
					'message' => sprintf(
						/* translators: %s: carrier ID */
						__( 'Carrier ID "%s" was not found in your ShipEngine account.', 'mcp-ai-wpoos-pro' ),
						$carrier_id
					),
					'carrier' => 'shipengine',
				);
			}
		}

		$is_sandbox = ! empty( $creds['sandbox_mode'] ) || 0 === strpos( $api_key, 'TEST_' );

		// Extract rate-limit diagnostics from response headers (industry standard).
		$rate_limit_info = array();
		$rl_remaining    = wp_remote_retrieve_header( $response, 'x-ratelimit-remaining' );
		$rl_limit        = wp_remote_retrieve_header( $response, 'x-ratelimit-limit' );
		if ( '' !== $rl_remaining ) {
			$rate_limit_info['requests_remaining'] = (int) $rl_remaining;
		}
		if ( '' !== $rl_limit ) {
			$rate_limit_info['requests_limit'] = (int) $rl_limit;
		}

		$result = array(
			'success'        => true,
			'message'        => $is_sandbox
				? __( 'ShipStation API sandbox connection successful!', 'mcp-ai-wpoos-pro' )
				: __( 'ShipStation API connection successful!', 'mcp-ai-wpoos-pro' ),
			'carrier'        => 'shipengine',
			'sandbox_mode'   => $is_sandbox,
			'environment'    => $is_sandbox ? 'sandbox' : 'production',
			'carriers_found' => count( $carriers ),
		);

		if ( ! empty( $rate_limit_info ) ) {
			$result['rate_limits'] = $rate_limit_info;
		}

		return $result;
	}

	/**
	 * Test ShipStation API connection.
	 *
	 * @param array $creds API credentials.
	 * @return array
	 */
	protected function test_shipstation_connection( array $creds ) {
		$api_key    = $creds['shipstation_api_key'] ?? '';
		$api_secret = $creds['shipstation_api_secret'] ?? '';

		if ( '' === $api_key || '' === $api_secret ) {
			return array(
				'success' => false,
				'message' => __( 'ShipStation API key and secret are not configured.', 'mcp-ai-wpoos-pro' ),
				'carrier' => 'shipstation',
			);
		}

		$auth = base64_encode( $api_key . ':' . $api_secret ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode -- Standard Basic-Auth encoding.

		/** This filter is documented in this file. */
		$api_url  = (string) apply_filters( 'wp_mcp_ai_shipstation_api_url', 'https://ssapi.shipstation.com' );
		$endpoint = trailingslashit( $api_url ) . 'carriers';

		$response = $this->request_with_retry(
			$endpoint,
			array(
				'method'  => 'GET',
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Basic ' . $auth,
					'Content-Type'  => 'application/json',
					'User-Agent'    => $this->get_user_agent(),
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return array(
				'success' => false,
				'message' => sprintf(
					/* translators: %s: error message */
					__( 'Connection failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				'carrier' => 'shipstation',
			);
		}

		$code = (int) wp_remote_retrieve_response_code( $response );
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );

		if ( 401 === $code || 403 === $code ) {
			return array(
				'success' => false,
				'message' => __( 'Invalid ShipStation V1 credentials.', 'mcp-ai-wpoos-pro' ),
				'carrier' => 'shipstation',
			);
		}

		if ( $code < 200 || $code >= 300 ) {
			return array(
				'success' => false,
				'message' => $this->parse_api_error( $body, 'shipstation', $code ),
				'carrier' => 'shipstation',
			);
		}

		$is_sandbox = ! empty( $creds['sandbox_mode'] );

		// Extract ShipStation V1 rate-limit headers.
		$rate_limit_info = array();
		$rl_remaining    = wp_remote_retrieve_header( $response, 'x-rate-limit-remaining' );
		$rl_limit        = wp_remote_retrieve_header( $response, 'x-rate-limit-limit' );
		if ( '' !== $rl_remaining ) {
			$rate_limit_info['requests_remaining'] = (int) $rl_remaining;
		}
		if ( '' !== $rl_limit ) {
			$rate_limit_info['requests_limit'] = (int) $rl_limit;
		}

		$result = array(
			'success'      => true,
			'message'      => $is_sandbox
				? __( 'ShipStation V1 sandbox connection successful!', 'mcp-ai-wpoos-pro' )
				: __( 'ShipStation V1 connection successful!', 'mcp-ai-wpoos-pro' ),
			'carrier'      => 'shipstation',
			'sandbox_mode' => $is_sandbox,
			'environment'  => $is_sandbox ? 'sandbox' : 'production',
		);

		if ( ! empty( $rate_limit_info ) ) {
			$result['rate_limits'] = $rate_limit_info;
		}

		return $result;
	}

	/**
	 * Get the active carrier from arguments or plugin settings.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string Carrier identifier.
	 */
	protected function get_carrier( array $arguments ) {
		if ( ! empty( $arguments['carrier'] ) && in_array( $arguments['carrier'], array( 'shipengine', 'shipstation' ), true ) ) {
			return $arguments['carrier'];
		}

		// Try to read from nv-boxpacker plugin settings.
		$optimizer_settings = get_option( 'fk_usps_optimizer_settings', array() );
		if ( ! empty( $optimizer_settings['carrier'] ) ) {
			return $optimizer_settings['carrier'];
		}

		return 'shipengine';
	}

	/**
	 * Get API credentials from arguments, remote connections, or plugin settings.
	 *
	 * Resolution priority:
	 * 1. Tool arguments (api_credentials parameter)
	 * 2. Pro remote connection (shipengine/shipstation connection type)
	 * 3. nv-boxpacker plugin settings (fk_usps_optimizer_settings option)
	 *
	 * @param array  $arguments Tool arguments.
	 * @param string $carrier   Active carrier.
	 * @return array Credentials.
	 */
	protected function get_credentials( array $arguments, string $carrier ) {
		$creds = array();

		// Check tool arguments first.
		$arg_creds = isset( $arguments['api_credentials'] ) ? $arguments['api_credentials'] : array();

		// Attempt to resolve from a pro remote connection.
		$remote_creds = $this->resolve_remote_connection_credentials( $carrier );

		// Load from nv-boxpacker plugin settings as final fallback.
		$optimizer_settings = get_option( 'fk_usps_optimizer_settings', array() );

		if ( 'shipengine' === $carrier ) {
			$creds['shipengine_api_key']    = ! empty( $arg_creds['shipengine_api_key'] )
				? sanitize_text_field( $arg_creds['shipengine_api_key'] )
				: ( ! empty( $remote_creds['api_key'] ) ? $remote_creds['api_key'] : ( $optimizer_settings['shipengine_api_key'] ?? '' ) );
			$creds['shipengine_carrier_id'] = ! empty( $arg_creds['shipengine_carrier_id'] )
				? sanitize_text_field( $arg_creds['shipengine_carrier_id'] )
				: ( ! empty( $remote_creds['carrier_id'] ) ? $remote_creds['carrier_id'] : ( $optimizer_settings['shipengine_carrier_id'] ?? '' ) );
		} else {
			$creds['shipstation_api_key']      = ! empty( $arg_creds['shipstation_api_key'] )
				? sanitize_text_field( $arg_creds['shipstation_api_key'] )
				: ( ! empty( $remote_creds['api_key'] ) ? $remote_creds['api_key'] : ( $optimizer_settings['shipstation_api_key'] ?? '' ) );
			$creds['shipstation_api_secret']   = ! empty( $arg_creds['shipstation_api_secret'] )
				? sanitize_text_field( $arg_creds['shipstation_api_secret'] )
				: ( ! empty( $remote_creds['api_secret'] ) ? $remote_creds['api_secret'] : ( $optimizer_settings['shipstation_api_secret'] ?? '' ) );
			$creds['shipstation_carrier_code'] = ! empty( $arg_creds['shipstation_carrier_code'] )
				? sanitize_text_field( $arg_creds['shipstation_carrier_code'] )
				: ( ! empty( $remote_creds['carrier_code'] ) ? $remote_creds['carrier_code'] : ( $optimizer_settings['shipstation_carrier_code'] ?? 'stamps_com' ) );
		}

		// Propagate sandbox mode from the remote connection or auto-detect from ShipEngine TEST_ key prefix.
		$creds['sandbox_mode'] = ! empty( $remote_creds['sandbox_mode'] );
		if ( 'shipengine' === $carrier && ! $creds['sandbox_mode'] && ! empty( $creds['shipengine_api_key'] ) ) {
			$creds['sandbox_mode'] = 0 === strpos( $creds['shipengine_api_key'], 'TEST_' );
		}

		return $creds;
	}

	/**
	 * Resolve credentials from a pro remote connection of the matching carrier type.
	 *
	 * Finds the first enabled connection of type 'shipengine' or 'shipstation'
	 * and decrypts its stored credentials.
	 *
	 * @param string $carrier Carrier name ('shipengine' or 'shipstation').
	 * @return array Resolved credentials with keys: api_key, api_secret, carrier_id, carrier_code, sandbox_mode.
	 */
	protected function resolve_remote_connection_credentials( string $carrier ) {
		$result = array(
			'api_key'      => '',
			'api_secret'   => '',
			'carrier_id'   => '',
			'carrier_code' => '',
			'sandbox_mode' => false,
		);

		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			return $result;
		}

		$connections = WP_MCP_AI_Pro_Remote_Site_Manager::get_all_connections();

		foreach ( $connections as $conn ) {
			if ( ! isset( $conn['connection_type'] ) || $conn['connection_type'] !== $carrier ) {
				continue;
			}
			if ( empty( $conn['enabled'] ) ) {
				continue;
			}

			// Decrypt stored credentials.
			if ( ! empty( $conn['api_key'] ) ) {
				$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $conn['api_key'] );
				if ( false !== $decrypted ) {
					$result['api_key'] = $decrypted;
				}
			}
			if ( ! empty( $conn['api_secret'] ) ) {
				$decrypted = WP_MCP_AI_Pro_Remote_Site_Manager::decrypt_value( $conn['api_secret'] );
				if ( false !== $decrypted ) {
					$result['api_secret'] = $decrypted;
				}
			}

			// Non-encrypted carrier fields.
			if ( 'shipengine' === $carrier && ! empty( $conn['shipengine_carrier_id'] ) ) {
				$result['carrier_id'] = $conn['shipengine_carrier_id'];
			}
			if ( 'shipstation' === $carrier && ! empty( $conn['shipstation_carrier_code'] ) ) {
				$result['carrier_code'] = $conn['shipstation_carrier_code'];
			}

			// Sandbox mode flag.
			$result['sandbox_mode'] = ! empty( $conn['sandbox_mode'] );

			break; // Use the first enabled connection of this type.
		}

		return $result;
	}

	/**
	 * Build a formatted ship-to address from tool arguments.
	 *
	 * @param array $address Raw address data.
	 * @return array Formatted address suitable for carrier APIs.
	 */
	protected function build_ship_to_address( array $address ) {
		return array(
			'name'                          => isset( $address['name'] ) ? sanitize_text_field( $address['name'] ) : '',
			'company_name'                  => isset( $address['company'] ) ? sanitize_text_field( $address['company'] ) : '',
			'phone'                         => isset( $address['phone'] ) ? sanitize_text_field( $address['phone'] ) : '',
			'address_line1'                 => isset( $address['address_line1'] ) ? sanitize_text_field( $address['address_line1'] ) : '',
			'address_line2'                 => isset( $address['address_line2'] ) ? sanitize_text_field( $address['address_line2'] ) : '',
			'city_locality'                 => isset( $address['city'] ) ? sanitize_text_field( $address['city'] ) : '',
			'state_province'                => isset( $address['state'] ) ? sanitize_text_field( $address['state'] ) : '',
			'postal_code'                   => isset( $address['postal_code'] ) ? sanitize_text_field( $address['postal_code'] ) : '',
			'country_code'                  => isset( $address['country_code'] ) ? sanitize_text_field( $address['country_code'] ) : 'US',
			'address_residential_indicator' => 'unknown',
		);
	}

	/**
	 * Build a ship-to address from a WooCommerce order.
	 *
	 * @param WC_Order $order The WooCommerce order.
	 * @return array Formatted address.
	 */
	protected function build_order_ship_to( $order ) {
		return array(
			'name'                          => trim( $order->get_shipping_first_name() . ' ' . $order->get_shipping_last_name() ),
			'company_name'                  => $order->get_shipping_company(),
			'phone'                         => $order->get_billing_phone(),
			'address_line1'                 => $order->get_shipping_address_1(),
			'address_line2'                 => $order->get_shipping_address_2(),
			'city_locality'                 => $order->get_shipping_city(),
			'state_province'                => $order->get_shipping_state(),
			'postal_code'                   => $order->get_shipping_postcode(),
			'country_code'                  => $order->get_shipping_country() ? $order->get_shipping_country() : 'US',
			'address_residential_indicator' => 'unknown',
		);
	}

	/**
	 * Get the ship-from address from arguments or plugin settings.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Formatted ship-from address.
	 */
	protected function get_ship_from_address( array $arguments ) {
		// Check tool arguments first.
		if ( ! empty( $arguments['ship_from'] ) && is_array( $arguments['ship_from'] ) ) {
			$sf = $arguments['ship_from'];
			return array(
				'name'                          => isset( $sf['name'] ) ? sanitize_text_field( $sf['name'] ) : '',
				'company_name'                  => isset( $sf['company'] ) ? sanitize_text_field( $sf['company'] ) : '',
				'phone'                         => isset( $sf['phone'] ) ? sanitize_text_field( $sf['phone'] ) : '',
				'address_line1'                 => isset( $sf['address_line1'] ) ? sanitize_text_field( $sf['address_line1'] ) : '',
				'address_line2'                 => isset( $sf['address_line2'] ) ? sanitize_text_field( $sf['address_line2'] ) : '',
				'city_locality'                 => isset( $sf['city'] ) ? sanitize_text_field( $sf['city'] ) : '',
				'state_province'                => isset( $sf['state'] ) ? sanitize_text_field( $sf['state'] ) : '',
				'postal_code'                   => isset( $sf['postal_code'] ) ? sanitize_text_field( $sf['postal_code'] ) : '',
				'country_code'                  => isset( $sf['country_code'] ) ? sanitize_text_field( $sf['country_code'] ) : 'US',
				'address_residential_indicator' => 'no',
			);
		}

		// Fall back to nv-boxpacker plugin settings.
		$optimizer_settings = get_option( 'fk_usps_optimizer_settings', array() );

		if ( ! empty( $optimizer_settings['ship_from_address1'] ) ) {
			return array(
				'name'                          => $optimizer_settings['ship_from_name'] ?? '',
				'company_name'                  => $optimizer_settings['ship_from_company'] ?? '',
				'phone'                         => $optimizer_settings['ship_from_phone'] ?? '',
				'address_line1'                 => $optimizer_settings['ship_from_address1'] ?? '',
				'address_line2'                 => $optimizer_settings['ship_from_address2'] ?? '',
				'city_locality'                 => $optimizer_settings['ship_from_city'] ?? '',
				'state_province'                => $optimizer_settings['ship_from_state'] ?? '',
				'postal_code'                   => $optimizer_settings['ship_from_postal_code'] ?? '',
				'country_code'                  => $optimizer_settings['ship_from_country'] ?? 'US',
				'address_residential_indicator' => 'no',
			);
		}

		// Fall back to WooCommerce store address.
		$default_country = get_option( 'woocommerce_default_country', 'US:CA' );
		$country_parts   = explode( ':', $default_country );

		return array(
			'name'                          => get_option( 'blogname', '' ),
			'company_name'                  => '',
			'phone'                         => '',
			'address_line1'                 => get_option( 'woocommerce_store_address', '' ),
			'address_line2'                 => get_option( 'woocommerce_store_address_2', '' ),
			'city_locality'                 => get_option( 'woocommerce_store_city', '' ),
			'state_province'                => $country_parts[1] ?? '',
			'postal_code'                   => get_option( 'woocommerce_store_postcode', '' ),
			'country_code'                  => $country_parts[0] ?? 'US',
			'address_residential_indicator' => 'no',
		);
	}
}
