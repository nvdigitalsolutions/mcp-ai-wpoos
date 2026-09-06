<?php
/**
 * Pro SPA v2 Shortcode — Front-end embed of the Pro SPA v2 chat surface.
 *
 * Registers [nvoos_pro_spa] which mounts the TypeScript/esbuild SPA in
 * "embedded" mode: chat-first (AgentPanel + drawers), router-free, no admin
 * routes. Mirrors the chat-spa addon's [nvoos_chat_spa] pattern (per-instance
 * data-config attribute + shared NVOOS_PRO_SPA runtime global).
 *
 * Guests are supported via the base plugin's guest-token machinery
 * (WP_MCP_AI_Shortcode::generate_guest_token) and the X-WP-MCP-AI-Guest
 * header in the SSE adapter. Guest mode is opt-in per shortcode and requires
 * the global "Allow Guest Access" security setting.
 *
 * @package NV_oOS_Pro
 * @since   2.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Pro_SPA_Shortcode
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_SPA_Shortcode {

	/**
	 * Shortcode tag.
	 *
	 * @since 2.1.0
	 * @var string
	 */
	const SHORTCODE = 'nvoos_pro_spa';

	/**
	 * Register the shortcode.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	public static function register() {
		add_shortcode( self::SHORTCODE, array( __CLASS__, 'render' ) );
	}

	/**
	 * Render the shortcode.
	 *
	 * @since 2.1.0
	 *
	 * @param array|string $atts Shortcode attributes.
	 * @return string HTML output (root div with data-config).
	 */
	public static function render( $atts ) {
		$atts = shortcode_atts(
			array(
				'assistant_id'          => '',
				'mode'                  => 'embedded',
				'theme'                 => 'auto',
				'height'                => '',
				'guest'                 => '0',
				'allow_sensitive_tools' => '0',
				'show_sidebar'          => '1',
			),
			$atts,
			self::SHORTCODE
		);

		/**
		 * Short-circuit the shortcode render.
		 *
		 * @since 2.1.0
		 *
		 * @param bool  $can_render Whether the instance may render.
		 * @param array $atts       Merged shortcode attributes (defaults applied).
		 */
		$can_render = apply_filters( 'nvoos_pro_spa_can_render', true, $atts );
		if ( ! $can_render ) {
			return '';
		}

		$guest = ! empty( $atts['guest'] ) && '0' !== (string) $atts['guest'];

		// Guests cannot be authenticated users by definition — when the
		// visitor is logged in, serve the authenticated surface instead.
		if ( $guest && is_user_logged_in() ) {
			$guest = false;
		}

		if ( ! class_exists( 'WP_MCP_AI_Pro_SPA_Config' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-spa-config.php';
		}

		$per_instance = array(
			'assistant_id'          => absint( $atts['assistant_id'] ),
			'mode'                  => sanitize_key( (string) $atts['mode'] ),
			'theme'                 => sanitize_key( (string) $atts['theme'] ),
			'height'                => sanitize_text_field( $atts['height'] ),
			'guest'                 => $guest,
			'allow_sensitive_tools' => ! empty( $atts['allow_sensitive_tools'] ) && '0' !== (string) $atts['allow_sensitive_tools'],
			'show_sidebar'          => ! empty( $atts['show_sidebar'] ) && '0' !== (string) $atts['show_sidebar'],
			'routes'                => array( 'chat' ),
		);

		// Guest mode requested but no token could be minted (assistant missing
		// or guest access disabled) — nothing usable to render for anonymous
		// visitors.
		$runtime = WP_MCP_AI_Pro_SPA_Config::build( $per_instance );
		if ( $guest && empty( $runtime['config']['guestToken'] ) ) {
			return '';
		}

		if ( ! WP_MCP_AI_Pro_SPA_Config::register_assets() ) {
			return '';
		}

		WP_MCP_AI_Pro_SPA_Config::enqueue( $runtime );

		// The SPA reads the per-instance overrides from the data-config
		// attribute (see src/index.tsx / src/api/config.ts), while shared
		// endpoints/user/assistant data live in the NVOOS_PRO_SPA global.
		$config_json = wp_json_encode( $runtime['config'] );
		if ( false === $config_json ) {
			$config_json = '{}';
		}

		return sprintf(
			'<div class="nvoos-pro-spa-root nvoos-pro-spa-embedded" role="application" aria-label="%1$s" data-config="%2$s"></div>',
			esc_attr__( 'AI Assistant', 'mcp-ai-wpoos-pro' ),
			esc_attr( $config_json )
		);
	}
}
