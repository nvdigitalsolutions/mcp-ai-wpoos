<?php
/**
 * GTmetrix Toolkit — Abstract Tool Base.
 *
 * Shared boilerplate for the gtmetrix_* tools: capability gate, toolkit/key
 * availability guard, client access and the canonical-envelope discipline.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the toolkit services so every tool can rely on them being present.
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-settings.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-client.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-fixture-reader.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-score-engine.php';
require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-fix-engine.php';

/**
 * Base class for GTmetrix toolkit tools.
 *
 * @since 1.2.5
 */
abstract class WP_MCP_AI_GTmetrix_Tool_Base implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_slug();

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_name();

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_description();

	/**
	 * {@inheritdoc}
	 */
	abstract public function get_parameters_schema();

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	abstract public function execute( array $arguments = array(), array $context = array() );

	/**
	 * {@inheritdoc}
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Auditing, testing or optimising WordPress page performance with GTmetrix.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Anything unrelated to page-speed testing or the GTmetrix account.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'gtmetrix_start_test', 'gtmetrix_analyze_report', 'gtmetrix_compare_reports' ),
			'notes'           => __( 'Requires a GTmetrix API key in the NV oOS API key settings. Starting tests consumes GTmetrix API credits.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Whether the GTmetrix toolkit is usable on this site.
	 *
	 * @return bool
	 */
	public static function is_available() {
		return WP_MCP_AI_GTmetrix_Settings::is_enabled() && ( '' !== WP_MCP_AI_GTmetrix_Settings::get_api_key() || WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() );
	}

	/**
	 * {@inheritdoc}
	 */
	public static function get_unavailable_reason() {
		if ( ! WP_MCP_AI_GTmetrix_Settings::is_enabled() ) {
			return __( 'Enable the GTmetrix Performance Toolkit in the NV oOS Pro tools settings.', 'mcp-ai-wpoos-pro' );
		}
		if ( '' === WP_MCP_AI_GTmetrix_Settings::get_api_key() && ! WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return __( 'Add a GTmetrix API key in the NV oOS API key settings.', 'mcp-ai-wpoos-pro' );
		}

		return '';
	}

	/**
	 * Required WordPress capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * Whether this tool requires the Pro addon.
	 *
	 * @return bool
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'read-only', 'external-api', 'cacheable', 'idempotent' );
	}

	/**
	 * Standard permission gate.
	 *
	 * @return WP_Error|null
	 */
	protected function guard() {
		if ( ! current_user_can( $this->get_required_capability() ) ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_forbidden', __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( ! WP_MCP_AI_GTmetrix_Settings::is_enabled() ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_disabled', __( 'The GTmetrix Performance Toolkit is disabled.', 'mcp-ai-wpoos-pro' ) );
		}

		return null;
	}

	/**
	 * Guard that additionally requires a configured API key (or fixture mode).
	 *
	 * @return WP_Error|null
	 */
	protected function guard_key() {
		$guard = $this->guard();
		if ( $guard ) {
			return $guard;
		}

		if ( '' === WP_MCP_AI_GTmetrix_Settings::get_api_key() && ! WP_MCP_AI_GTmetrix_Settings::is_fixture_mode() ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_no_key', __( 'No GTmetrix API key configured. Add it in the NV oOS API key settings.', 'mcp-ai-wpoos-pro' ) );
		}

		return null;
	}

	/**
	 * Fresh client instance.
	 *
	 * @return WP_MCP_AI_GTmetrix_Client
	 */
	protected function client() {
		return new WP_MCP_AI_GTmetrix_Client();
	}

	/**
	 * Sanitised URL argument (esc_url_raw + protocol allowlist) or empty string.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	protected function arg_url( $arguments ) {
		$url = isset( $arguments['url'] ) ? esc_url_raw( (string) $arguments['url'], array( 'http', 'https' ) ) : '';

		return $url;
	}

	/**
	 * Sanitised report-id argument or empty string.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	protected function arg_report_id( $arguments ) {
		return isset( $arguments['report_id'] ) ? sanitize_text_field( (string) $arguments['report_id'] ) : '';
	}

	/**
	 * Sanitised page-id argument or empty string.
	 *
	 * @param array $arguments Tool arguments.
	 * @return string
	 */
	protected function arg_page_id( $arguments ) {
		return isset( $arguments['page_id'] ) ? sanitize_text_field( (string) $arguments['page_id'] ) : '';
	}
}
