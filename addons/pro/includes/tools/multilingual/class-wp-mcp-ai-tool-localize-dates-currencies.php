<?php
/**
 * Localize Dates and Currencies Tool
 *
 * Format dates, times, numbers, and currencies according to locale standards.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * WP_MCP_AI_Tool_Localize_Dates_Currencies tool.
 */
class WP_MCP_AI_Tool_Localize_Dates_Currencies implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Check if tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}

		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_multilingual_toolkit'] );
	}

	/**
	 * Get unavailable reason.
	 *
	 * @return string
	 */
	public static function get_unavailable_reason() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_multilingual_toolkit'] ) ) {
			return __( 'Multi-language Content toolkit is not enabled.', 'mcp-ai-wpoos-pro' );
		}
		return __( 'Localize Dates and Currencies tool is not available.', 'mcp-ai-wpoos-pro' );
	}


	/**

	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'localize_dates_currencies';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Localize Dates and Currencies', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Format dates, times, numbers, and currencies according to locale standards.', 'mcp-ai-wpoos-pro' );
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
				'locale'       => array(
					'type'        => 'string',
					'description' => 'Locale code (e.g., en_US, fr_FR)',
				),
				'date'         => array(
					'type'        => 'string',
					'description' => 'Date to format (any strtotime-compatible string)',
				),
				'amount'       => array(
					'type'        => 'number',
					'description' => 'Currency amount to format',
				),
				'currency'     => array(
					'type'        => 'string',
					'description' => 'ISO 4217 currency code (USD, EUR, etc.)',
					'default'     => 'USD',
				),
				'phone'        => array(
					'type'        => 'string',
					'description' => 'Phone number to format using libphonenumber-js',
				),
				'country_code' => array(
					'type'        => 'string',
					'description' => 'ISO 3166-1 alpha-2 country code for phone formatting (e.g., US, GB)',
					'default'     => 'US',
				),
			),
			'required'   => array(),
		);
	}


	/**

	 * Get the required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

		/**
		 * Get capability flags for this tool.
		 *
		 * @return array
		 */
	public function get_capability_flags() {
		return array(
			'content'     => true,
			'translation' => true,
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
		$locale   = ! empty( $arguments['locale'] ) ? sanitize_text_field( $arguments['locale'] ) : get_locale();
		$date     = ! empty( $arguments['date'] ) ? sanitize_text_field( $arguments['date'] ) : '';
		$amount   = isset( $arguments['amount'] ) ? floatval( $arguments['amount'] ) : null;
		$currency = ! empty( $arguments['currency'] ) ? strtoupper( sanitize_text_field( $arguments['currency'] ) ) : 'USD';
		$phone    = ! empty( $arguments['phone'] ) ? sanitize_text_field( $arguments['phone'] ) : '';
		$country  = ! empty( $arguments['country_code'] ) ? strtoupper( sanitize_text_field( $arguments['country_code'] ) ) : 'US';

		$output = array(
			'success' => true,
			'locale'  => $locale,
		);

		// Format currency amount using PHP Intl NumberFormatter when available.
		if ( null !== $amount ) {
			if ( class_exists( 'NumberFormatter' ) ) {
				$fmt                        = new NumberFormatter( $locale, NumberFormatter::CURRENCY );
				$output['formatted_amount'] = $fmt->formatCurrency( $amount, $currency );
			} else {
				// Basic fallback.
				$output['formatted_amount'] = $currency . ' ' . number_format( $amount, 2 );
			}
			$output['currency'] = $currency;
		}

		// Format date using PHP Intl IntlDateFormatter when available.
		if ( '' !== $date ) {
			$timestamp = strtotime( $date );
			if ( false !== $timestamp ) {
				if ( class_exists( 'IntlDateFormatter' ) ) {
					$fmt                      = new IntlDateFormatter(
						$locale,
						IntlDateFormatter::LONG,
						IntlDateFormatter::NONE,
						wp_timezone()
					);
					$output['formatted_date'] = $fmt->format( $timestamp );
				} else {
					$output['formatted_date'] = wp_date( get_option( 'date_format' ), $timestamp );
				}
			} else {
				$output['formatted_date'] = $date;
			}
		}

		// Format phone via libphonenumber-js service.
		if ( '' !== $phone ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-language-detection-service.php';
			$svc                       = new WP_MCP_AI_Language_Detection_Service();
			$phone_result              = $svc->format_phone( $phone, $country );
			$output['formatted_phone'] = $phone_result['formatted'];
			$output['phone_valid']     = $phone_result['valid'];
		}

		$output['message'] = __( 'Localization applied successfully.', 'mcp-ai-wpoos-pro' );
		return $output;
	}
}
