<?php
/**
 * Content Assistant bootstrap (Wave E4, sub-cluster 4).
 *
 * Aligned port of the base plugin's `includes/content-assistant-init.php`:
 * byte-identical feature gate — the `enable_content_assistant_metabox`
 * setting (default enabled) with the `wp_mcp_ai_content_assistant_enabled`
 * filter — and the `admin_init` instantiation of the metabox class.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - The base's global function becomes static methods on this class:
 *    the gate moves into `is_enabled()` and the `admin_init`-hooked
 *    `wp_mcp_ai_init_content_assistant()` → `register()` (hooked on
 *    `admin_init` by `Plugin::registerContentAssistant()`).
 *  - Standalone-only: the base loader owns the same `admin_init`
 *    wiring in monolith installs; double registration would double-add
 *    the metabox to every post edit screen.
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ContentAssistant
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ContentAssistant;

/**
 * Initializes the AI Content Assistant metabox feature.
 *
 * @since 2.1.0
 */
class ContentAssistantBootstrap {

	/**
	 * Whether the Content Assistant metabox feature is enabled.
	 *
	 * @since 2.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		$enabled  = isset( $settings['enable_content_assistant_metabox'] ) ? $settings['enable_content_assistant_metabox'] : true;

		/**
		 * Filters whether the Content Assistant metabox feature is enabled.
		 *
		 * @since 2.1.0
		 *
		 * @param bool $enabled Whether the feature is enabled.
		 */
		$enabled = apply_filters( 'wp_mcp_ai_content_assistant_enabled', $enabled );

		return (bool) $enabled;
	}

	/**
	 * Initialize the Content Assistant feature.
	 *
	 * Standalone-only: `Plugin::registerContentAssistant()` hooks this on
	 * `admin_init`, matching the base's
	 * `add_action( 'admin_init', 'wp_mcp_ai_init_content_assistant' )`.
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! self::is_enabled() ) {
			return;
		}

		new ContentAssistantMetabox();
	}
}
