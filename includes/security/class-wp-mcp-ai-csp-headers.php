<?php
/**
 * Content-Security-Policy Headers — Admin page hardening.
 *
 * Emits a Content-Security-Policy (or Content-Security-Policy-Report-Only)
 * header on WordPress admin pages to restrict script, style, connect,
 * image, font, frame, and object sources.
 *
 * The default directives allow inline scripts/styles (required by many
 * WordPress admin screens), data: URIs for fonts and images, and
 * connectivity to the AI provider APIs used by the plugin.
 *
 * All directive strings are filterable for customisation.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_CSP_Headers' ) ) {
	/**
	 * Content-Security-Policy header emission for admin pages.
	 */
	class WP_MCP_AI_CSP_Headers {

		/**
		 * Register the admin_init hook.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function register() {
			add_action( 'admin_init', array( __CLASS__, 'emit_csp_headers' ) );
		}

		/**
		 * Build and emit the Content-Security-Policy header.
		 *
		 * Only fires when is_admin() is true (defence-in-depth).
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public static function emit_csp_headers() {
			if ( ! is_admin() ) {
				return;
			}

			$directives = array(
				"default-src 'self'",
				"script-src 'self' 'unsafe-inline' 'unsafe-eval'",
				"style-src 'self' 'unsafe-inline'",
				"connect-src 'self' https://api.openai.com https://generativelanguage.googleapis.com https://api.anthropic.com https://openrouter.ai",
				"img-src 'self' data: https:",
				"frame-ancestors 'self'",
				"frame-src 'self'",
				"font-src 'self' data:",
				"object-src 'none'",
			);

			/**
			 * Filter the Content-Security-Policy directives.
			 *
			 * Each element is a full directive string (e.g. "default-src 'self'").
			 * The array is joined with "; " before emission.
			 *
			 * @since 1.2.0
			 *
			 * @param string[] $directives CSP directive strings.
			 */
			$directives = apply_filters( 'wp_mcp_ai_csp_directives', $directives );

			$header_value = implode( '; ', $directives );

			/**
			 * Filter: switch to Content-Security-Policy-Report-Only mode.
			 *
			 * When this returns true, the header is emitted as
			 * `Content-Security-Policy-Report-Only` instead of the
			 * enforcing `Content-Security-Policy`.
			 *
			 * @since 1.2.0
			 *
			 * @param bool $report_only Whether to use report-only mode. Default false.
			 */
			$report_only = apply_filters( 'wp_mcp_ai_csp_report_only', false );

			$header_name = $report_only
				? 'Content-Security-Policy-Report-Only'
				: 'Content-Security-Policy';

			header( $header_name . ': ' . $header_value );
		}
	}
}
