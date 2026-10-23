<?php
/**
 * Feature Flags — server-side availability detection for optional NV oOS surfaces.
 *
 * Single source of truth for "is surface X available on this site": consumed
 * by the REST features endpoint (GET /mcp-ai/v1/features), the localized Pro
 * SPA runtime config, and — in later admin-migration phases — the per-page
 * kill-switch filters. Every flag passes through a filter so deployments can
 * override a default without touching core code.
 *
 * The console and the SPA never guess availability from URLs; they consume
 * this map (Proposal 084 / migration plan Phase 0).
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Feature_Flags
 */
class WP_MCP_AI_Feature_Flags {

	/**
	 * Docs Hub — the `addons/docs-hub` addon or the standalone
	 * `nvoos-docs-hub` wp.org plugin (same plugin class).
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	public static function docs_hub() {
		return (bool) apply_filters(
			'wp_mcp_ai_feature_available_docs_hub',
			class_exists( 'NV_oOS_Docs_Hub_Plugin' )
		);
	}

	/**
	 * NV oOS Content Graph — the standalone `plugins/nvoos-content-graph`
	 * plugin backing the #/knowledge-graph explorer page.
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	public static function knowledge_graph() {
		return (bool) apply_filters(
			'wp_mcp_ai_feature_available_knowledge_graph',
			class_exists( 'NvoosContentGraph\Plugin' )
		);
	}

	/**
	 * NV oOS Media Studio — the addon backing the #/media framed surface.
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	public static function media_studio() {
		return (bool) apply_filters(
			'wp_mcp_ai_feature_available_media_studio',
			class_exists( 'NV_oOS_Media_Studio_Shortcode' )
		);
	}

	/**
	 * NV oOS Document Editor — the addon backing the #/documents framed surface.
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	public static function document_editor() {
		return (bool) apply_filters(
			'wp_mcp_ai_feature_available_document_editor',
			class_exists( 'NV_oOS_Document_Editor_Shortcode' )
		);
	}

	/**
	 * Pro addon — the WP_MCP_AI_PRO_FILE constant is defined by the Pro
	 * addon bootstrap and by the standalone Pro plugin.
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	public static function pro() {
		return (bool) apply_filters(
			'wp_mcp_ai_feature_available_pro',
			defined( 'WP_MCP_AI_PRO_FILE' )
		);
	}

	/**
	 * OKF — the Open Knowledge Format bundle manager (Pro surface).
	 *
	 * @since 2.4.0
	 * @return bool
	 */
	public static function okf() {
		return (bool) apply_filters(
			'wp_mcp_ai_feature_available_okf',
			class_exists( 'WP_MCP_AI_OKF_Bundle_Manager' )
		);
	}

	/**
	 * The full availability map for the REST features endpoint and the
	 * localized SPA runtime config.
	 *
	 * @since 2.4.0
	 * @return array<string,bool>
	 */
	public static function all() {
		return array(
			'docs_hub'        => self::docs_hub(),
			'knowledge_graph' => self::knowledge_graph(),
			'media_studio'    => self::media_studio(),
			'document_editor' => self::document_editor(),
			'pro'             => self::pro(),
			'okf'             => self::okf(),
			// Toolkit enable toggles — the console gates the command-center
			// pages (CRM, Projects, Calendar, F&B) on these so disabled
			// features do not show in the app.
			'fnb'                => self::toolkit_flag( 'enable_fnb_toolkit' ),
			'crm'                => self::toolkit_flag( 'enable_crm_toolkit' ),
			'project_management' => self::toolkit_flag( 'enable_project_management' ),
			'calendar_booking'   => self::toolkit_flag( 'enable_calendar_booking_toolkit' ),
		);
	}

	/**
	 * Read a boolean toolkit toggle from the plugin settings.
	 *
	 * @since 2.4.0
	 * @param string $key Settings key (e.g. 'enable_fnb_toolkit').
	 * @return bool
	 */
	private static function toolkit_flag( $key ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return is_array( $settings ) && ! empty( $settings[ $key ] );
	}
}
