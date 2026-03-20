<?php
/**
 * TMA Template: Jewelry Shop (Shopify)
 *
 * Telegram Mini App template that serves a gold-themed React SPA connected to
 * a Shopify store via the plugin's Remote Sites / `shopify_products` tool
 * infrastructure. The SPA provides:
 *
 *  - Product catalog with debounced search
 *  - Product detail page with add-to-cart
 *  - Shopping cart with quantity controls
 *  - Checkout / order enquiry form
 *  - Shopify order history
 *  - AI jewelry concierge (full chat)
 *
 * The compiled React bundle is expected at:
 *   addons/shopify-jewelry-tma/build/tma-shopify-jewelry/tma-shopify-jewelry.js
 *   addons/shopify-jewelry-tma/build/tma-shopify-jewelry/tma-shopify-jewelry.css
 *
 * Build with: npm run build:tma-shopify-jewelry
 *
 * @package WP_MCP_AI_Shopify_Jewelry_TMA
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Jewelry Shop Telegram Mini App template.
 *
 * Registered by the main plugin file via the `wp_mcp_ai_tma_templates_registered`
 * action hook. Requires NV oOS Pro >= 1.1.3 for the base class and registry.
 */
class WP_MCP_AI_TMA_Template_Jewelry_Shop extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'jewelry_shop';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'Jewelry Shop (Shopify)', 'mcp-ai-shopify-jewelry-tma' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Gold-themed React SPA for jewelry retailers. Connects to any Shopify store via Remote Sites. Includes product catalog, cart, checkout and an AI jewelry concierge.', 'mcp-ai-shopify-jewelry-tma' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '💍';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#c9a227';
	}

	/**
	 * Render the body HTML for this template.
	 *
	 * Injects a `window.wpTmaJewelryConfig` JS object with all URLs and IDs
	 * the React SPA needs, then loads the compiled bundle.
	 *
	 * Context keys used:
	 *   validate_url    – POST to verify Telegram initData + get fresh nonce/token
	 *   tools_url       – base URL for tool execution endpoint
	 *   chat_url        – TMA-aware chat endpoint
	 *   nonce           – initial WordPress nonce
	 *   assistant_id    – resolved Mini App assistant ID
	 *   site_name       – site display name
	 *   shopify_connection_id – Shopify Remote Sites connection ID (optional)
	 *
	 * @param  array $ctx Context variables injected by the controller.
	 * @return string     HTML body fragment.
	 */
	public function render_html( array $ctx ) {
		$plugin_url = defined( 'WP_MCP_AI_SJ_TMA_URL' ) ? WP_MCP_AI_SJ_TMA_URL : '';
		$js_url     = $plugin_url ? $plugin_url . 'build/tma-shopify-jewelry/tma-shopify-jewelry.js' : '';
		$css_url    = $plugin_url ? $plugin_url . 'build/tma-shopify-jewelry/tma-shopify-jewelry.css' : '';

		// Resolve the Shopify connection ID from per-connection or global settings.
		$connection_id = '';
		if ( ! empty( $ctx['shopify_connection_id'] ) ) {
			$connection_id = sanitize_key( $ctx['shopify_connection_id'] );
		} else {
			$connection_id = sanitize_key( get_option( 'wp_mcp_ai_shopify_jewelry_connection_id', '' ) );
		}

		// wp_json_encode() uses JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
		// by default, ensuring the output is safe for inline <script> embedding.
		$config = wp_json_encode(
			array(
				'validateUrl'  => $ctx['validate_url']  ?? '',
				'toolsUrl'     => $ctx['tools_url']     ?? '',
				'chatUrl'      => $ctx['chat_url']      ?? '',
				'nonce'        => $ctx['nonce']         ?? '',
				'assistantId'  => $ctx['assistant_id']  ?? '',
				'siteName'     => $ctx['site_name']     ?? get_bloginfo( 'name' ),
				'connectionId' => $connection_id,
			)
		);

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $config produced by wp_json_encode (HTML-safe); CSS/JS URLs escaped with esc_url().
		return '<body class="wp-mcp-ai-telegram-mini-app tma-jw-template">' .
			'<div id="tma-shopify-jewelry-root"></div>' .
			'<script>window.wpTmaJewelryConfig=' . $config . ';</script>' .
			( $css_url ? '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' : '' ) .
			( $js_url  ? '<script src="' . esc_url( $js_url ) . '"></script>' : '' ) .
			'</body>';
		// phpcs:enable
	}
}
