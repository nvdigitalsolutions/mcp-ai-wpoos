<?php
/**
 * Content-Security-Policy Headers for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-csp-headers.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). Default directive set, the `wp_mcp_ai_csp_directives` /
 * `wp_mcp_ai_csp_report_only` filters, and the emitted header names keep
 * their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - `is_admin_context()` and `send_header()` are protected seams so tests
 *   can drive header emission without a real admin request context.
 * - `register()` is registered standalone-only by `Plugin.php` — the
 *   base plugin owns the same admin_init hook in monolith installs.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Content-Security-Policy header emission for admin pages.
 *
 * @since 1.1.0
 */
class CspHeaders {

	/**
	 * Register the admin_init hook.
	 *
	 * @return void
	 */
	public static function register() {
		add_action( 'admin_init', array( __CLASS__, 'emit_csp_headers' ) );
	}

	/**
	 * Whether the current request is an admin context (seam).
	 *
	 * @return bool
	 */
	protected static function is_admin_context() {
		return is_admin();
	}

	/**
	 * Emit a header line (seam for test capture).
	 *
	 * @param string $name  Header name.
	 * @param string $value Header value.
	 * @return void
	 */
	protected static function send_header( $name, $value ): void {
		header( $name . ': ' . $value );
	}

	/**
	 * Build and emit the Content-Security-Policy header.
	 *
	 * Only fires when is_admin() is true (defence-in-depth).
	 *
	 * @return void
	 */
	public static function emit_csp_headers() {
		if ( ! static::is_admin_context() ) {
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
		 * @param bool $report_only Whether to use report-only mode. Default false.
		 */
		$report_only = apply_filters( 'wp_mcp_ai_csp_report_only', false );

		$header_name = $report_only
			? 'Content-Security-Policy-Report-Only'
			: 'Content-Security-Policy';

		static::send_header( $header_name, $header_value );
	}
}
