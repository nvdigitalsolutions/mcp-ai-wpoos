<?php // phpcs:ignore WordPress.Files.FileName -- Class name does not match filename; file included explicitly.
/**
 * Telegram Mini App Template Registry
 *
 * Provides a registry of pre-built Mini App templates. Each template targets a
 * specific Pro toolkit while the "default" template retains the existing
 * full-featured CMS edition behaviour.
 *
 * Industry-standard approach:
 *  - Every template is a self-contained PHP class extending the abstract base.
 *  - Templates declare their target toolkit slug so the settings UI can group
 *    and badge them correctly.
 *  - The active template is stored in the WordPress options table under the key
 *    `wp_mcp_ai_telegram_mini_app_template` and resolved at request time.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.3
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed
// phpcs:disable Generic.Files.OneObjectStructurePerFile.MultipleFound
// phpcs:disable Universal.Files.SeparateFunctionsFromOO.Mixed

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base for all Telegram Mini App templates.
 */
abstract class WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Return the unique machine-readable slug (e.g. "default", "ecommerce").
	 *
	 * @return string
	 */
	abstract public function get_slug();

	/**
	 * Return the human-readable display name.
	 *
	 * @return string
	 */
	abstract public function get_name();

	/**
	 * Return a short description shown in the template picker.
	 *
	 * @return string
	 */
	abstract public function get_description();

	/**
	 * Return the Pro toolkit slug this template is optimised for, or empty
	 * string for a general-purpose template.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return '';
	}

	/**
	 * Return an emoji icon used in the picker card.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '📱';
	}

	/**
	 * Return the accent colour used for the picker card (CSS value).
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#2481cc';
	}

	/**
	 * Render the body content for this template.
	 *
	 * The controller calls this method and wraps the output in the HTML
	 * document boilerplate (DOCTYPE, head, Telegram SDK, etc.).
	 * Return empty string to signal that the controller should use its own
	 * built-in render logic (used by the Default template).
	 *
	 * @param  array $ctx Context variables injected by the controller:
	 *                    bot_username, validate_url, content_url, tools_url,
	 *                    media_url, settings_url, analytics_url, shop_url,
	 *                    login_url, chart_js_url, site_name, nonce, page_title,
	 *                    assistant_id (resolved Mini App assistant ID string),
	 *                    connection_id (Telegram connection ID for multi-bot
	 *                    setups; empty string when using the global endpoint),
	 *                    chat_url (absolute URL to /mcp-ai/v1/telegram-mini-app/chat –
	 *                    a TMA-aware endpoint that accepts both WordPress nonces and the
	 *                    TMA session token returned by validate_url),
	 *                    validate_url (absolute URL to /mcp-ai/v1/telegram-mini-app/validate –
	 *                    call this on page load to authenticate the Telegram user and
	 *                    receive a fresh wp_nonce and tma_token for subsequent requests).
	 *                    All URLs include the connection_id path segment when present.
	 * @return string
	 */
	abstract public function render_html( array $ctx );
}

/**
 * Registry singleton.
 */
class WP_MCP_AI_Telegram_Mini_App_Template_Registry {

	/**
	 * WordPress option key that stores the selected template slug.
 */
	const OPTION_KEY = 'wp_mcp_ai_telegram_mini_app_template';

	/**
	 * Prefix for per-template customization option keys.
	 * Full key: wp_mcp_ai_tma_custom_{slug}
 */
	const CUSTOM_OPTION_PREFIX = 'wp_mcp_ai_tma_custom_';

	/**
	 * Registered templates indexed by slug.
	 *
	 * @var WP_MCP_AI_Telegram_Mini_App_Template_Base[]
	 */
	private $templates = array();

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	private static $instance = null;

	/**
	 * Get (or create) the singleton instance.
	 *
	 * @return static
	 */
	public static function instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
			self::$instance->register_built_in();
		}
		return self::$instance;
	}

	/**
	 * Register a template object.
	 *
	 * @param WP_MCP_AI_Telegram_Mini_App_Template_Base $template Template instance.
	 */
	public function register( WP_MCP_AI_Telegram_Mini_App_Template_Base $template ) {
		$this->templates[ $template->get_slug() ] = $template;
	}

	/**
	 * Retrieve a registered template by slug. Falls back to "default".
	 *
	 * @param  string $slug Template slug.
	 * @return WP_MCP_AI_Telegram_Mini_App_Template_Base|null
	 */
	public function find( $slug ) {
		if ( isset( $this->templates[ $slug ] ) ) {
			return $this->templates[ $slug ];
		}
		return isset( $this->templates['default'] ) ? $this->templates['default'] : null;
	}

	/**
	 * Return all registered templates.
	 *
	 * @return WP_MCP_AI_Telegram_Mini_App_Template_Base[]
	 */
	public function all() {
		return array_values( $this->templates );
	}

	/**
	 * Check whether a template slug is registered.
	 *
	 * @param  string $slug Template slug.
	 * @return bool
	 */
	public function has( $slug ) {
		return isset( $this->templates[ $slug ] );
	}

	// ── Static proxy helpers (for call-sites that don't hold the singleton) ──

	/**
	 * Static proxy: check whether a template slug is registered.
	 *
	 * @since 1.1.3
	 *
	 * @param  string $slug Template slug.
	 * @return bool
	 */
	public static function exists( $slug ) {
		return self::instance()->has( sanitize_key( (string) $slug ) );
	}

	/**
	 * Static proxy: retrieve a registered template by slug.
	 *
	 * @since 1.1.3
	 *
	 * @param  string $slug Template slug.
	 * @return WP_MCP_AI_Telegram_Mini_App_Template_Base|null Template or null if slug
	 *                                                         is unknown and 'default' is
	 *                                                         not registered.
	 */
	public static function get( $slug ) {
		$inst = self::instance();
		$slug = sanitize_key( (string) $slug );
		return $inst->find( $slug );
	}

	/**
	 * Static proxy: return metadata arrays for all registered templates.
	 *
	 * Each item contains: slug, name, description, icon, accent_color, toolkit,
	 * has_customizations, custom_css.  When a template has saved customizations
	 * the display fields (name, description, icon, accent_color) are merged so
	 * the React picker always shows the user-edited values.
	 *
	 * @since 1.1.3
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public static function get_all_meta() {
		$meta = array();
		foreach ( self::instance()->all() as $tpl ) {
			$custom = self::get_customizations( $tpl->get_slug() );
			$meta[] = array(
				'slug'               => $tpl->get_slug(),
				'name'               => ! empty( $custom['name'] ) ? $custom['name'] : $tpl->get_name(),
				'description'        => ! empty( $custom['description'] ) ? $custom['description'] : $tpl->get_description(),
				'icon'               => ! empty( $custom['icon'] ) ? $custom['icon'] : $tpl->get_icon(),
				'accent_color'       => ! empty( $custom['accent_color'] ) ? $custom['accent_color'] : $tpl->get_accent_color(),
				'toolkit'            => $tpl->get_toolkit(),
				'has_customizations' => ! empty( $custom ),
				'custom_css'         => isset( $custom['custom_css'] ) ? $custom['custom_css'] : '',
				// Base (original) values always available for the editor to show defaults.
				'base_name'          => $tpl->get_name(),
				'base_description'   => $tpl->get_description(),
				'base_icon'          => $tpl->get_icon(),
				'base_accent_color'  => $tpl->get_accent_color(),
			);
		}
		return $meta;
	}

	/**
	 * Return the full metadata for a single registered template, including any
	 * saved customizations.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $slug Template slug.
	 * @return array|null   Metadata array or null if the slug is not registered.
	 */
	public static function get_meta( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$tpl  = self::get( $slug );
		if ( ! $tpl ) {
			return null;
		}
		$custom = self::get_customizations( $slug );
		return array(
			'slug'               => $tpl->get_slug(),
			'name'               => ! empty( $custom['name'] ) ? $custom['name'] : $tpl->get_name(),
			'description'        => ! empty( $custom['description'] ) ? $custom['description'] : $tpl->get_description(),
			'icon'               => ! empty( $custom['icon'] ) ? $custom['icon'] : $tpl->get_icon(),
			'accent_color'       => ! empty( $custom['accent_color'] ) ? $custom['accent_color'] : $tpl->get_accent_color(),
			'toolkit'            => $tpl->get_toolkit(),
			'has_customizations' => ! empty( $custom ),
			'custom_css'         => isset( $custom['custom_css'] ) ? $custom['custom_css'] : '',
			'base_name'          => $tpl->get_name(),
			'base_description'   => $tpl->get_description(),
			'base_icon'          => $tpl->get_icon(),
			'base_accent_color'  => $tpl->get_accent_color(),
		);
	}

	// ── Per-template customization helpers ──────────────────────────────────

	/**
	 * Return saved customizations for a template slug.
	 *
	 * Returns an associative array with keys: name, description, icon,
	 * accent_color, custom_css.  Returns an empty array when no customizations
	 * have been saved.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $slug Template slug.
	 * @return array
	 */
	public static function get_customizations( $slug ) {
		$slug = sanitize_key( (string) $slug );
		$data = get_option( self::CUSTOM_OPTION_PREFIX . $slug, array() );
		return is_array( $data ) ? $data : array();
	}

	/**
	 * Save per-template customizations.
	 *
	 * Only the following keys are accepted; all values are sanitized.
	 *   name         – display name shown in the picker card (≤ 120 chars)
	 *   description  – short description shown below the name (≤ 500 chars)
	 *   icon         – emoji / short string shown as the card icon (≤ 10 chars)
	 *   accent_color – valid CSS colour value (≤ 30 chars)
	 *   custom_css   – raw CSS injected into the rendered Mini App <head>
	 *
	 * @since 1.1.4
	 *
	 * @param  string $slug Template slug.
	 * @param  array  $data Associative array of fields to save.
	 * @return bool         True on success.
	 */
	public static function save_customizations( $slug, array $data ) {
		$slug = sanitize_key( (string) $slug );
		if ( ! self::instance()->has( $slug ) ) {
			return false;
		}

		$allowed = array( 'name', 'description', 'icon', 'accent_color', 'custom_css' );
		$current = self::get_customizations( $slug );
		$merged  = $current;

		foreach ( $allowed as $key ) {
			if ( ! array_key_exists( $key, $data ) ) {
				continue;
			}
			switch ( $key ) {
				case 'name':
					$merged[ $key ] = substr( sanitize_text_field( (string) $data[ $key ] ), 0, 120 );
					break;
				case 'description':
					$merged[ $key ] = substr( sanitize_textarea_field( (string) $data[ $key ] ), 0, 500 );
					break;
				case 'icon':
					$merged[ $key ] = substr( sanitize_text_field( (string) $data[ $key ] ), 0, 10 );
					break;
				case 'accent_color':
					// Allow only CSS-safe colour strings (hex, rgb/rgba, hsl/hsla, named colours).
					// Pattern matches: #rgb, #rrggbb, #rrggbbaa, rgb(...), rgba(...), hsl(...), hsla(...), CSS named colours.
					$color = sanitize_text_field( (string) $data[ $key ] );
					if ( preg_match( '/^(#(?:[0-9a-fA-F]{3,4}|[0-9a-fA-F]{6,8})|rgba?\(\s*\d+\s*,\s*\d+\s*,\s*\d+[\s,.\d]*\)|hsla?\(\s*[\d.]+\s*,\s*[\d.]+%\s*,\s*[\d.]+%[\s,.\d]*\)|[a-zA-Z]{2,30})$/', $color ) ) {
						$merged[ $key ] = $color;
					}
					break;
				case 'custom_css':
					// Strip PHP/HTML tags and @import directives to prevent loading external
					// stylesheets via the Mini App page (admins-only feature, but defence-in-depth).
					$css = wp_strip_all_tags( (string) $data[ $key ] );
					// Remove @import rules (case-insensitive, with optional whitespace).
					$css            = preg_replace( '/@import\s[^;]+;?/i', '', $css );
					$merged[ $key ] = $css;
					break;
			}
		}

		// Remove empty strings to keep the stored value compact.
		$merged = array_filter(
			$merged,
			function ( $v ) {
				return '' !== $v;
			}
		);

		return update_option( self::CUSTOM_OPTION_PREFIX . $slug, $merged, false );
	}

	/**
	 * Delete all customizations for a template slug, restoring defaults.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $slug Template slug.
	 * @return bool         True if the option was successfully deleted or did not exist.
	 */
	public static function reset_customizations( $slug ) {
		$slug = sanitize_key( (string) $slug );
		return delete_option( self::CUSTOM_OPTION_PREFIX . $slug );
	}

	/**
	 * Return any saved custom CSS for a template slug (empty string if none).
	 *
	 * Convenience helper used by the REST controller when rendering HTML.
	 *
	 * @since 1.1.4
	 *
	 * @param  string $slug Template slug.
	 * @return string
	 */
	public static function get_custom_css( $slug ) {
		$custom = self::get_customizations( sanitize_key( (string) $slug ) );
		return isset( $custom['custom_css'] ) ? $custom['custom_css'] : '';
	}

	/**
	 * Get the currently active template (reads from options).
	 *
	 * @return WP_MCP_AI_Telegram_Mini_App_Template_Base
	 */
	public function get_active() {
		$slug = get_option( self::OPTION_KEY, 'default' );
		return $this->find( sanitize_key( (string) $slug ) );
	}

	/**
	 * Save the active template slug to the options table.
	 *
	 * @param  string $slug Template slug.
	 * @return bool         True on success.
	 */
	public function save_active( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( ! isset( $this->templates[ $slug ] ) ) {
			return false;
		}
		return update_option( self::OPTION_KEY, $slug );
	}

	/**
	 * Register all built-in templates.
	 */
	private function register_built_in() {
		$this->register( new WP_MCP_AI_TMA_Template_Default() );
		$this->register( new WP_MCP_AI_TMA_Template_AI_Chat() );
		$this->register( new WP_MCP_AI_TMA_Template_Ecommerce() );
		$this->register( new WP_MCP_AI_TMA_Template_Woo_Shop() );
		$this->register( new WP_MCP_AI_TMA_Template_Shopify_Jewelry() );
		$this->register( new WP_MCP_AI_TMA_Template_Shopify_Shop() );
		$this->register( new WP_MCP_AI_TMA_Template_Flowhub_Ecommerce() );
		$this->register( new WP_MCP_AI_TMA_Template_Shopify_Ecommerce() );
		$this->register( new WP_MCP_AI_TMA_Template_CRM() );
		$this->register( new WP_MCP_AI_TMA_Template_Analytics() );
		$this->register( new WP_MCP_AI_TMA_Template_Booking() );
		$this->register( new WP_MCP_AI_TMA_Template_Health_Wellness() );
		$this->register( new WP_MCP_AI_TMA_Template_Medical_Vitals() );

		/**
		 * Fires after built-in Telegram Mini App templates are registered.
		 *
		 * Third-party code can register additional templates here:
		 *   add_action( 'wp_mcp_ai_tma_templates_registered', function( $registry ) {
		 *       $registry->register( new My_Custom_Template() );
		 *   } );
		 *
		 * @since 1.1.3
		 *
		 * @param WP_MCP_AI_Telegram_Mini_App_Template_Registry $registry Registry instance.
	 */
		do_action( 'wp_mcp_ai_tma_templates_registered', $this );
	}
}

/*
==========================================================================
	Shared helpers used by non-default templates
	==========================================================================

 */

/**
 * Return the shared CSS reset and Telegram CSS-variable scaffold.
 *
 * @return string CSS string (no style tags).
 */
function wp_mcp_ai_tma_base_css() {
	return ':root{' .
		'--tma-bg:#ffffff;--tma-text:#000000;--tma-hint:#999999;' .
		'--tma-link:#2481cc;--tma-btn:#2481cc;--tma-btn-text:#ffffff;' .
		'--tma-secondary-bg:#f1f1f1;--tma-header-bg:#ffffff;' .
		'--tma-accent:#2481cc;--tma-section-bg:#ffffff;' .
		'--tma-section-header:#6d6d71;--tma-subtitle:#999999;' .
		'--tma-destructive:#e53935;--tma-border:rgba(0,0,0,.1);' .
		'--tma-shadow:0 1px 3px rgba(0,0,0,.12);' .
		'--tma-radius:12px;--tma-nav-height:60px;--tma-header-height:56px;' .
		'--tma-transition:.2s ease;--tma-vh:100vh;' .
	'}' .
	'*{box-sizing:border-box}' .
	'html,body{margin:0;padding:0;height:100%;overflow:hidden;' .
		'background:var(--tma-bg);color:var(--tma-text);' .
		'font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Helvetica,Arial,sans-serif;' .
		'-webkit-font-smoothing:antialiased}' .
	'.tma-shell{display:flex;flex-direction:column;height:var(--tma-vh,100vh);max-height:100dvh;overflow:hidden;background:var(--tma-bg)}' .
	'.tma-header{display:flex;align-items:center;gap:10px;padding:8px 16px;' .
		'height:var(--tma-header-height);background:var(--tma-header-bg);' .
		'border-bottom:1px solid var(--tma-border);flex-shrink:0;z-index:10}' .
	'.tma-avatar-wrap{flex-shrink:0;width:36px;height:36px}' .
	'.tma-avatar-initials{width:36px;height:36px;border-radius:50%;background:var(--tma-btn);' .
		'color:var(--tma-btn-text);display:flex;align-items:center;justify-content:center;' .
		'font-weight:700;font-size:14px}' .
	'.tma-header-info{flex:1;min-width:0}' .
	'.tma-header-name{font-weight:600;font-size:15px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;line-height:1.2}' .
	'.tma-header-status{font-size:12px;color:var(--tma-hint);margin-top:1px}' .
	'.tma-header-actions{display:flex;gap:6px;flex-shrink:0}' .
	'.tma-icon-btn{background:none;border:none;padding:6px;border-radius:8px;cursor:pointer;' .
		'color:var(--tma-hint);display:flex;align-items:center;justify-content:center;' .
		'-webkit-tap-highlight-color:transparent}' .
	'.tma-content{flex:1;overflow:hidden;position:relative}' .
	'.tma-tab-pane{position:absolute;top:0;left:0;right:0;bottom:0;overflow-y:auto;' .
		'-webkit-overflow-scrolling:touch;opacity:0;pointer-events:none;' .
		'transition:opacity var(--tma-transition)}' .
	'.tma-tab-pane.tma-active{opacity:1;pointer-events:auto}' .
	'.tma-nav{display:flex;background:var(--tma-bg);border-top:1px solid var(--tma-border);' .
		'flex-shrink:0;height:var(--tma-nav-height)}' .
	'.tma-nav-btn{flex:1;background:none;border:none;display:flex;flex-direction:column;' .
		'align-items:center;justify-content:center;gap:3px;cursor:pointer;' .
		'color:var(--tma-hint);font-size:10px;padding:6px 0;' .
		'-webkit-tap-highlight-color:transparent;transition:color var(--tma-transition)}' .
	'.tma-nav-btn.tma-active{color:var(--tma-btn)}' .
	'.tma-nav-svg{width:22px;height:22px}' .
	'.tma-section-title{font-size:13px;font-weight:600;color:var(--tma-section-header);' .
		'padding:12px 16px 4px;text-transform:uppercase;letter-spacing:.5px}' .
	'.tma-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
		'border-radius:var(--tma-radius);margin:8px 12px;padding:14px;box-shadow:var(--tma-shadow)}' .
	'.tma-empty{text-align:center;padding:40px 20px;color:var(--tma-hint);font-size:14px}' .
	'.tma-btn{padding:10px 20px;border-radius:8px;border:none;font-size:14px;font-weight:600;' .
		'cursor:pointer;-webkit-tap-highlight-color:transparent;transition:opacity var(--tma-transition)}' .
	'.tma-btn-primary{background:var(--tma-btn);color:var(--tma-btn-text)}' .
	'.tma-btn-secondary{background:var(--tma-secondary-bg);color:var(--tma-text)}' .
	'.tma-btn:active{opacity:.7}' .
	'.tma-input{width:100%;padding:10px 14px;border:1px solid var(--tma-border);' .
		'border-radius:8px;background:var(--tma-bg);color:var(--tma-text);' .
		'font-size:14px;font-family:inherit;outline:none}';
}

/**
 * Return shared JS that applies Telegram theme variables and manages viewport.
 *
 * @return string JavaScript string (no script tags).
 */
function wp_mcp_ai_tma_base_js() {
	return 'var twa=(window.Telegram&&window.Telegram.WebApp)?window.Telegram.WebApp:null;' .
	'function tmaApplyTheme(){' .
		'if(!twa)return;' .
		'var tp=twa.themeParams||{},r=document.documentElement;' .
		'var map={"--tma-bg":tp.bg_color,"--tma-text":tp.text_color,"--tma-hint":tp.hint_color,' .
			'"--tma-link":tp.link_color,"--tma-btn":tp.button_color,"--tma-btn-text":tp.button_text_color,' .
			'"--tma-secondary-bg":tp.secondary_bg_color,"--tma-header-bg":tp.header_bg_color,' .
			'"--tma-accent":tp.accent_text_color,"--tma-section-bg":tp.section_bg_color,' .
			'"--tma-border":tp.section_separator_color};' .
		'Object.keys(map).forEach(function(p){if(map[p])r.style.setProperty(p,map[p]);});' .
		'if(tp.bg_color)document.body.style.background=tp.bg_color;' .
	'}' .
	'function tmaUpdateVH(){' .
		'var h=twa?twa.viewportStableHeight:window.innerHeight;' .
		'document.documentElement.style.setProperty("--tma-vh",h+"px");' .
	'}' .

	/*
	 * Haptic feedback helper – routes to the correct Telegram WebApp API method:
	 *   - "selectionChanged"       → HapticFeedback.selectionChanged()
	 *   - "success"/"error"/"warning" → HapticFeedback.notificationOccurred(type)
	 *   - "notification*" prefix   → legacy compat, e.g. "notificationSuccess" → notificationOccurred("success")
	 *   - anything else            → HapticFeedback.impactOccurred(style), default "light"

	*/
	'function tmaHaptic(t){if(!twa||!twa.HapticFeedback)return;var h=twa.HapticFeedback;if(t==="selectionChanged"){h.selectionChanged();}else if(t==="success"||t==="error"||t==="warning"){h.notificationOccurred(t);}else if(t&&t.indexOf("notification")===0){h.notificationOccurred(t.slice(12).toLowerCase()||"success");}else{h.impactOccurred(t||"light");}}' .

	/*
	 * Build tool-execution request headers, including TMA token when available

	*/
	'function tmaToolHeaders(){' .
		'var h={"Content-Type":"application/json"};' .
		'if(typeof NONCE!=="undefined"&&NONCE){h["X-WP-Nonce"]=NONCE;}' .
		'if(typeof TMA_TOKEN!=="undefined"&&TMA_TOKEN){h["X-WP-MCP-AI-TMA-Token"]=TMA_TOKEN;}' .
		'return h;' .
	'}' .
	'tmaApplyTheme();tmaUpdateVH();' .
	'if(twa){twa.onEvent("viewportChanged",tmaUpdateVH);twa.onEvent("themeChanged",tmaApplyTheme);twa.ready();}' .
	'window.addEventListener("resize",tmaUpdateVH);';
}

/*
==========================================================================
	Built-in Template Classes
	==========================================================================

 */

/**
 * Default template – delegates to the controller's existing built-in render.
 */
class WP_MCP_AI_TMA_Template_Default extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'default';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Content Manager (Default)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Full-featured CMS template with analytics dashboard, content editor, tools executor, media library, shop, and settings.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '📋';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#2481cc';
	}

	/**
	 * Empty return signals the controller to use its built-in render logic.
	 *
	 * @param array $ctx Context variables.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		return '';
	}
}

/**
 * AI Chat template – minimal conversational interface.
 */
class WP_MCP_AI_TMA_Template_AI_Chat extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ai_chat';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'AI Chat', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Clean conversational interface powered by the AI assistant. Perfect for customer-facing chatbots.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'chat_channels';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '💬';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#4CAF50';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$chat_url     = $ctx['chat_url'];
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$validate_url = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id = $ctx['assistant_id'];

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- standalone HTML document; all values escaped inline.
		return '<body class="wp-mcp-ai-telegram-mini-app tma-ai-chat-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables ──

		*/
		':root{--tma-btn:#4CAF50;--tma-accent:#4CAF50;--tma-secondary-bg:#e8f5e9;' .
			'--chat-base:14px;--chat-label:12px;--chat-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.chat-font-small{--chat-base:12px;--chat-label:10px;--chat-heading:14px}' .
		'.chat-font-large{--chat-base:16px;--chat-label:14px;--chat-heading:18px}' .
		'.chat-compact .tma-msg{padding:6px 10px}' .
		'.chat-compact .chat-tool-card{padding:8px 10px;margin:0 8px 6px}' .
		'.chat-compact .chat-settings-section{margin:0 8px 8px;padding:10px}' .

		/*
		 * ── Chat messages ──

		*/
		'.tma-chat-messages{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.tma-chat-welcome{text-align:center;padding:40px 20px;color:var(--tma-hint)}' .
		'.tma-welcome-icon{font-size:48px;margin-bottom:12px}' .
		'.tma-msg{max-width:85%;padding:10px 14px;border-radius:18px;font-size:var(--chat-base);line-height:1.5;word-wrap:break-word}' .
		'.tma-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.tma-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.tma-msg.bot p{margin:0 0 6px}.tma-msg.bot p:last-child{margin-bottom:0}' .
		'.tma-msg.bot ul,.tma-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.tma-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.tma-msg.typing::after{content:"\u2026";animation:tma-dots 1s steps(3,end) infinite}' .
		'@keyframes tma-dots{0%,33%{content:"."}33%,66%{content:".."}66%,100%{content:"\u2026"}}' .

		/*
		 * ── Chat input ──

		*/
		'.tma-chat-input-wrap{display:flex;align-items:flex-end;gap:8px;padding:8px 12px;background:var(--tma-secondary-bg);border-top:1px solid var(--tma-border);flex-shrink:0}' .
		'.tma-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 16px;font-size:var(--chat-base);background:var(--tma-bg);color:var(--tma-text);resize:none;outline:none;max-height:120px;overflow-y:auto;font-family:inherit;line-height:1.4}' .
		'.tma-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;-webkit-tap-highlight-color:transparent}' .
		'.tma-send-btn:active{opacity:.7}' .

		/*
		 * ── Quick actions ──

		*/
		'.chat-quick-actions{display:flex;gap:6px;flex-wrap:wrap;padding:0 12px 8px}' .
		'.chat-quick-btn{padding:6px 12px;border:1px solid var(--tma-border);border-radius:16px;background:var(--tma-bg);color:var(--tma-btn);font-size:var(--chat-label);cursor:pointer;white-space:nowrap}' .
		'.chat-quick-btn:active{background:var(--tma-secondary-bg)}' .

		/*
		 * ── Rich content card ──

		*/
		'.chat-rich-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px 12px;margin-top:6px}' .
		'.chat-rich-card-title{font-size:var(--chat-base);font-weight:600;margin-bottom:2px}' .
		'.chat-rich-card-meta{font-size:var(--chat-label);color:var(--tma-hint)}' .

		/*
		 * ── Tools tab ──

		*/
		'.chat-tools-header{padding:14px 12px;text-align:center;color:var(--tma-hint);font-size:var(--chat-label)}' .
		'.chat-tools-header h3{font-size:var(--chat-heading);color:var(--tma-text);margin:0 0 4px}' .
		'.chat-tool-categories{display:flex;gap:6px;padding:0 12px 10px;overflow-x:auto;-webkit-overflow-scrolling:touch}' .
		'.chat-cat-btn{padding:6px 14px;border:1px solid var(--tma-border);border-radius:16px;background:var(--tma-bg);color:var(--tma-text);font-size:var(--chat-label);font-weight:600;cursor:pointer;white-space:nowrap}' .
		'.chat-cat-btn.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.chat-tools-search{padding:0 12px 10px}' .
		'.chat-tools-search input{width:100%;box-sizing:border-box;border:1px solid var(--tma-border);border-radius:10px;padding:10px 12px;font-size:var(--chat-base);background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.chat-tool-card{display:flex;align-items:center;gap:10px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);margin:0 12px 8px;padding:12px 14px;cursor:pointer}' .
		'.chat-tool-card:active{opacity:.85}' .
		'.chat-tool-icon{width:36px;height:36px;border-radius:50%;background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}' .
		'.chat-tool-info{flex:1;min-width:0}' .
		'.chat-tool-name{font-size:var(--chat-base);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.chat-tool-desc{font-size:var(--chat-label);color:var(--tma-hint);display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden}' .

		/*
		 * ── Settings tab ──

		*/
		'.chat-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.chat-settings-title{font-size:var(--chat-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.chat-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.chat-settings-row:last-child{border-bottom:none}' .
		'.chat-settings-label{font-size:var(--chat-base);color:var(--tma-text)}' .
		'.chat-settings-value{font-size:var(--chat-base);color:var(--tma-hint)}' .
		'.chat-font-btns{display:flex;gap:4px}' .
		'.chat-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);color:var(--tma-text);font-size:var(--chat-label);cursor:pointer}' .
		'.chat-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.chat-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.chat-toggle.on{background:var(--tma-btn)}' .
		'.chat-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.chat-toggle.on::after{transform:translateX(20px)}' .
		'.chat-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);background:var(--tma-bg);color:var(--tma-text);font-size:var(--chat-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.chat-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.chat-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">💬</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="tma-status-text">' . esc_html__( 'AI Assistant', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="tma-header-actions">' .
					'<button class="tma-icon-btn" title="' . esc_attr__( 'Clear chat', 'mcp-ai-wpoos-pro' ) . '" onclick="chatClearHistory()">' .
						'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>' .
					'</button>' .
				'</div>' .
			'</header>' .

			/*
			 * ── Content panes ──

		 */
			'<div class="tma-content">' .

				/*
				 * Tab 1: Chat (default)

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-chat">' .
					'<div class="chat-quick-actions" id="chat-quick-actions">' .
						'<button class="chat-quick-btn" onclick="chatQuickAction(\'' . esc_js( __( 'Search content', 'mcp-ai-wpoos-pro' ) ) . '\')">' . esc_html__( 'Search content', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<button class="chat-quick-btn" onclick="chatQuickAction(\'' . esc_js( __( 'Run a tool', 'mcp-ai-wpoos-pro' ) ) . '\')">' . esc_html__( 'Run a tool', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<button class="chat-quick-btn" onclick="chatQuickAction(\'' . esc_js( __( 'Check remote sites', 'mcp-ai-wpoos-pro' ) ) . '\')">' . esc_html__( 'Check remote sites', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'</div>' .
					'<div class="tma-chat-messages" id="tma-messages">' .
						'<div class="tma-chat-welcome"><div class="tma-welcome-icon">🤖</div>' .
						'<p>' . esc_html__( 'Hello! How can I assist you today?', 'mcp-ai-wpoos-pro' ) . '</p></div>' .
					'</div>' .
					'<div class="tma-chat-input-wrap">' .
						'<textarea id="tma-chat-input" class="tma-chat-input" rows="1"' .
							' placeholder="' . esc_attr__( 'Type a message…', 'mcp-ai-wpoos-pro' ) . '"' .
							' onkeydown="chatKeydown(event)" oninput="chatAutoResize(this)"></textarea>' .
						'<button class="tma-send-btn" onclick="chatSendMessage()">' .
							'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
						'</button>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 2: Tools

			 */
				'<div class="tma-tab-pane" id="tma-tab-tools">' .
					'<div class="chat-tools-header"><h3>' . esc_html__( 'Tools', 'mcp-ai-wpoos-pro' ) . '</h3>' .
						'<p>' . esc_html__( 'Discover available tools and capabilities', 'mcp-ai-wpoos-pro' ) . '</p>' .
					'</div>' .
					'<div class="chat-tool-categories" id="chat-tool-cats">' .
						'<button class="chat-cat-btn active" data-cat="all" onclick="chatFilterCat(\'all\')">' . esc_html__( 'All', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<button class="chat-cat-btn" data-cat="content" onclick="chatFilterCat(\'content\')">' . esc_html__( 'Content', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<button class="chat-cat-btn" data-cat="analytics" onclick="chatFilterCat(\'analytics\')">' . esc_html__( 'Analytics', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<button class="chat-cat-btn" data-cat="remote" onclick="chatFilterCat(\'remote\')">' . esc_html__( 'Remote', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<button class="chat-cat-btn" data-cat="ai" onclick="chatFilterCat(\'ai\')">' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'</div>' .
					'<div class="chat-tools-search">' .
						'<input type="search" id="chat-tools-search" placeholder="' . esc_attr__( 'Search tools…', 'mcp-ai-wpoos-pro' ) . '" />' .
					'</div>' .
					'<div id="chat-tools-list"><div class="tma-empty">' . esc_html__( 'Loading tools…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 3: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div style="padding-top:12px">' .

					/*
					 * Display section

				 */
					'<div class="chat-settings-section">' .
						'<div class="chat-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="chat-settings-row">' .
							'<span class="chat-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="chat-font-btns" id="chat-font-btns">' .
								'<button data-size="small" onclick="chatSetFontSize(\'small\')">' . esc_html__( 'S', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="medium" onclick="chatSetFontSize(\'medium\')">' . esc_html__( 'M', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="large" onclick="chatSetFontSize(\'large\')">' . esc_html__( 'L', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'</div>' .
						'</div>' .
						'<div class="chat-settings-row">' .
							'<span class="chat-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="chat-toggle" id="chat-compact-toggle" onclick="chatToggleCompact()"></button>' .
						'</div>' .
						'<div class="chat-settings-row">' .
							'<span class="chat-settings-label">' . esc_html__( 'Auto-scroll', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="chat-toggle" id="chat-autoscroll-toggle" onclick="chatToggleAutoScroll()"></button>' .
						'</div>' .
					'</div>' .

					/*
					 * Assistant section

				 */
					'<div class="chat-settings-section">' .
						'<div class="chat-settings-title">' . esc_html__( 'Assistant', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="chat-settings-row">' .
							'<span class="chat-settings-label">' . esc_html__( 'Assistant ID', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="chat-settings-value" id="chat-assistant-id-val">—</span>' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="chat-settings-section">' .
						'<div class="chat-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="chat-settings-row">' .
							'<span class="chat-settings-label" id="chat-data-summary"></span>' .
						'</div>' .
						'<button class="chat-settings-btn" onclick="chatSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="chat-settings-btn danger" onclick="chatClearData()">' .
							esc_html__( 'Clear Chat History', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .

					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (3 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-chat" onclick="chatSwitch(\'chat\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'Chat', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-tools" onclick="chatSwitch(\'tools\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14.7 6.3a1 1 0 0 0 0 1.4l1.6 1.6a1 1 0 0 0 1.4 0l3.77-3.77a6 6 0 0 1-7.94 7.94l-6.91 6.91a2.12 2.12 0 0 1-3-3l6.91-6.91a6 6 0 0 1 7.94-7.94l-3.76 3.76z"/></svg>' .
					'<span>' . esc_html__( 'Tools', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="chatSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="chat";' .
		'var hist=[];' .
		'var busy=false;' .
		'var toolsCache=[];' .
		'var activeCat="all";' .
		'var SK="wp_mcp_ai_tma_ai_chat";' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		 * ── Markdown renderer for bot messages ──

		*/
		'function chatRenderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── Extract reply from API response ──

		*/
		'function chatExtractReply(d){' .
			'if(!d||!d.data)return"";' .
			'var data=d.data;' .
			'if(data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)return data.choices[0].message.content;' .
			'if(data.content)return data.content;' .
			'if(data.response)return data.response;' .
			'return"";}' .

		/*
		 * ── Session init (Telegram WebApp auth) ──

		*/
		'function chatInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp)return;' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData)return;' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d)return;if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function chatApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("chat_font_size","medium");' .
				'shell.classList.remove("chat-font-small","chat-font-large");' .
				'if(size==="small")shell.classList.add("chat-font-small");' .
				'else if(size==="large")shell.classList.add("chat-font-large");' .
				'var compact=lsGet("chat_compact",false);' .
				'if(compact)shell.classList.add("chat-compact");' .
				'else shell.classList.remove("chat-compact");' .
				'var btns=document.querySelectorAll("#chat-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("chat-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
				'var asTog=document.getElementById("chat-autoscroll-toggle");' .
				'var autoScroll=lsGet("chat_auto_scroll",true);' .
				'if(asTog)asTog.classList.toggle("on",!!autoScroll);' .
			'}catch(e){}' .
		'}' .
		'window.chatSetFontSize=function(s){lsSet("chat_font_size",s);tmaHaptic("selectionChanged");chatApplyDisplaySettings();};' .
		'window.chatToggleCompact=function(){var c=!lsGet("chat_compact",false);lsSet("chat_compact",c);tmaHaptic("selectionChanged");chatApplyDisplaySettings();};' .
		'window.chatToggleAutoScroll=function(){var c=!lsGet("chat_auto_scroll",true);lsSet("chat_auto_scroll",c);tmaHaptic("selectionChanged");chatApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.chatSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'activeTab=tab;' .
			'if(tab==="tools"&&!toolsCache.length)chatLoadTools();' .
			'if(tab==="settings")chatRenderSettings();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Chat
			══════════════════════════════════════════════════════════

		*/

		'function chatScrollBottom(){' .
			'var autoScroll=lsGet("chat_auto_scroll",true);if(!autoScroll)return;' .
			'var m=document.getElementById("tma-messages");if(m)m.scrollTop=m.scrollHeight;' .
		'}' .

		'function chatSave(){try{localStorage.setItem(SK,JSON.stringify(hist.slice(-50)));}catch(e){}}' .

		'function chatAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="tma-msg "+(role==="user"?"user":"bot");' .
			'if(role==="bot"&&text){el.innerHTML=chatRenderMd(text);}' .
			'else if(role==="user"){el.textContent=text;}' .
			'var m=document.getElementById("tma-messages");' .
			'if(m){var w=m.querySelector(".tma-chat-welcome");if(w)w.remove();m.appendChild(el);chatScrollBottom();}' .
			'return el;' .
		'}' .

		/*
		 * Check if reply contains rich post references

		*/
		'function chatMaybeRichCards(text,container){' .
			'var postPattern=/\\[post:(.+?)\\|(.+?)\\|(.+?)\\]/g;var match;' .
			'while((match=postPattern.exec(text))!==null){' .
				'var card=document.createElement("div");card.className="chat-rich-card";' .
				'card.innerHTML=\'<div class="chat-rich-card-title">\'+escH(match[1])+\'</div><div class="chat-rich-card-meta">\'+escH(match[2])+" · "+escH(match[3])+\'</div>\';' .
				'container.appendChild(card);' .
			'}' .
		'}' .

		'window.chatQuickAction=function(text){' .
			'var inp=document.getElementById("tma-chat-input");' .
			'if(inp){inp.value=text;inp.focus();}' .
			'var qa=document.getElementById("chat-quick-actions");if(qa)qa.style.display="none";' .
		'};' .

		'window.chatSendMessage=function(){' .
			'if(busy)return;' .
			'var inp=document.getElementById("tma-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;' .
			'inp.value="";inp.style.height="";tmaHaptic("light");' .
			'var qa=document.getElementById("chat-quick-actions");if(qa)qa.style.display="none";' .
			'hist.push({role:"user",content:txt});chatAppendMsg("user",txt,false);chatSave();' .
			'busy=true;' .
			'var el=document.createElement("div");el.className="tma-msg bot typing";' .
			'var m=document.getElementById("tma-messages");' .
			'if(m){var w=m.querySelector(".tma-chat-welcome");if(w)w.remove();m.appendChild(el);chatScrollBottom();}' .
			'var st=document.getElementById("tma-status-text");if(st)st.textContent="' . esc_js( __( 'Thinking…', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'var body={messages:hist.slice(-20)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var rep=chatExtractReply(d)||"' . esc_js( __( 'Sorry, I could not process that.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.classList.remove("typing");el.innerHTML=chatRenderMd(rep);' .
				'chatMaybeRichCards(rep,el);' .
				'hist.push({role:"assistant",content:rep});chatSave();})' .
			'.catch(function(){el.classList.remove("typing");el.textContent="' . esc_js( __( 'Connection error. Please try again.', 'mcp-ai-wpoos-pro' ) ) . '";})' .
			'.finally(function(){busy=false;if(st)st.textContent="' . esc_js( __( 'AI Assistant', 'mcp-ai-wpoos-pro' ) ) . '";chatScrollBottom();});' .
		'};' .

		'window.chatClearHistory=function(){' .
			'hist=[];chatSave();tmaHaptic("medium");' .
			'var m=document.getElementById("tma-messages");' .
			'if(m)m.innerHTML=\'<div class="tma-chat-welcome"><div class="tma-welcome-icon">\u{1F916}</div><p>' . esc_js( __( 'Hello! How can I assist you today?', 'mcp-ai-wpoos-pro' ) ) . '</p></div>\';' .
			'var qa=document.getElementById("chat-quick-actions");if(qa)qa.style.display="flex";' .
		'};' .

		'window.chatKeydown=function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();chatSendMessage();}};' .
		'window.chatAutoResize=function(el){el.style.height="";el.style.height=Math.min(el.scrollHeight,120)+"px";};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – Tools
			══════════════════════════════════════════════════════════

		*/

		'var TOOL_CATS={' .
			'content:["search","post","page","content","media","menu","taxonomy","term","category","tag"],' .
			'analytics:["analytics","stats","report","log","monitor","count","metric"],' .
			'remote:["remote","site","fetch","crawl","http","url","ping","external"],' .
			'ai:["ai","gpt","generate","summarize","translate","chat","assistant","model"]' .
		'};' .

		'function chatToolCategory(name,desc){' .
			'var s=(name+" "+desc).toLowerCase();' .
			'var cats=Object.keys(TOOL_CATS);' .
			'for(var i=0;i<cats.length;i++){' .
				'var kws=TOOL_CATS[cats[i]];' .
				'for(var j=0;j<kws.length;j++){if(s.indexOf(kws[j])>-1)return cats[i];}' .
			'}return "other";' .
		'}' .

		'function chatToolIcon(cat){' .
			'switch(cat){case "content":return "\u{1F4DD}";case "analytics":return "\u{1F4CA}";case "remote":return "\u{1F310}";case "ai":return "\u{1F916}";default:return "\u{1F527}";}' .
		'}' .

		'function chatLoadTools(){' .
			'var l=document.getElementById("chat-tools-list");if(!l)return;' .
			'var cached=lsGet("chat_tools_cache",[]);' .
			'if(cached.length){toolsCache=cached;chatRenderTools(toolsCache);return;}' .
			'l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading tools…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"list_available_tools",arguments:{}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var tools=(d&&d.data&&d.data.tools)?d.data.tools:[];' .
				'if(!tools.length&&d&&d.data&&Array.isArray(d.data))tools=d.data;' .
				'toolsCache=tools;lsSet("chat_tools_cache",tools);' .
				'chatRenderTools(tools);' .
			'}).catch(function(){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load tools.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .

		'function chatRenderTools(tools){' .
			'var l=document.getElementById("chat-tools-list");if(!l)return;' .
			'var q=(document.getElementById("chat-tools-search")||{}).value||"";' .
			'q=q.toLowerCase();' .
			'var filtered=tools.filter(function(t){' .
				'var name=t.name||t.slug||"";var desc=t.description||"";' .
				'if(q&&(name+" "+desc).toLowerCase().indexOf(q)===-1)return false;' .
				'if(activeCat!=="all"&&chatToolCategory(name,desc)!==activeCat)return false;' .
				'return true;' .
			'});' .
			'if(!filtered.length){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No tools found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'l.innerHTML=filtered.map(function(t){' .
				'var name=escH(t.name||t.slug||"");var desc=escH(t.description||"");' .
				'var cat=chatToolCategory(t.name||t.slug||"",t.description||"");' .
				'var icon=chatToolIcon(cat);' .
				'return \'<div class="chat-tool-card" onclick="chatUseTool(\\\'\'+escH(t.name||t.slug||"")+\'\\\')">' .
					'<div class="chat-tool-icon">\'+icon+\'</div>' .
					'<div class="chat-tool-info">' .
						'<div class="chat-tool-name">\'+name+\'</div>' .
						'<div class="chat-tool-desc">\'+desc+\'</div>' .
					'</div>' .
				'</div>\';' .
			'}).join("");' .
		'}' .

		'window.chatUseTool=function(name){' .
			'chatSwitch("chat");' .
			'var inp=document.getElementById("tma-chat-input");' .
			'if(inp){inp.value="' . esc_js( __( 'Use the ', 'mcp-ai-wpoos-pro' ) ) . '"+name+" ' . esc_js( __( 'tool to ', 'mcp-ai-wpoos-pro' ) ) . '";inp.focus();}' .
		'};' .

		'window.chatFilterCat=function(cat){' .
			'activeCat=cat;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll("#chat-tool-cats .chat-cat-btn").forEach(function(b){b.classList.toggle("active",b.getAttribute("data-cat")===cat);});' .
			'chatRenderTools(toolsCache);' .
		'};' .

		/*
		 * Debounced search

		*/
		'var toolSearchTimer=null;' .
		'document.getElementById("chat-tools-search").addEventListener("input",function(){' .
			'clearTimeout(toolSearchTimer);toolSearchTimer=setTimeout(function(){chatRenderTools(toolsCache);},300);' .
		'});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function chatRenderSettings(){' .
			'chatApplyDisplaySettings();' .
			'var aid=document.getElementById("chat-assistant-id-val");' .
			'if(aid)aid.textContent=ASSISTANT_ID||"' . esc_js( __( 'Default', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'var ds=document.getElementById("chat-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Messages', 'mcp-ai-wpoos-pro' ) ) . ': "+hist.length+", ' .
				esc_js( __( 'Tools cached', 'mcp-ai-wpoos-pro' ) ) . ': "+toolsCache.length;' .
		'}' .

		'window.chatSyncFromServer=function(){' .
			'tmaHaptic("medium");toolsCache=[];' .
			'try{localStorage.removeItem("chat_tools_cache");}catch(e){}' .
			'chatLoadTools();' .
		'};' .

		'window.chatClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all chat history? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)chatDoClear();});' .
			'}else if(confirm(msg)){chatDoClear();}' .
		'};' .

		'function chatDoClear(){' .
			'try{' .
				'localStorage.removeItem(SK);' .
				'localStorage.removeItem("chat_tools_cache");' .
				'localStorage.removeItem("chat_font_size");' .
				'localStorage.removeItem("chat_compact");' .
				'localStorage.removeItem("chat_auto_scroll");' .
			'}catch(e){}' .
			'hist=[];toolsCache=[];' .
			'chatRenderSettings();tmaHaptic("notificationSuccess");' .
			'chatClearHistory();' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Restore chat history from localStorage

		*/
		'try{var s=localStorage.getItem(SK);if(s){hist=JSON.parse(s)||[];' .
			'hist=hist.filter(function(h){return h&&(h.role==="user"||h.role==="assistant")&&typeof h.content==="string";});' .
		'}}catch(e){hist=[];}' .
		'if(hist.length){var m=document.getElementById("tma-messages");if(m){m.innerHTML="";' .
			'var qa=document.getElementById("chat-quick-actions");if(qa)qa.style.display="none";' .
			'hist.forEach(function(h){chatAppendMsg(h.role,h.content,true);});' .
		'}}' .

		'chatApplyDisplaySettings();' .

		/*
		 * Session init for Telegram WebApp

		*/
		'chatInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * E-commerce template – WooCommerce shop assistant.
 */
class WP_MCP_AI_TMA_Template_Ecommerce extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'ecommerce';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'E-Commerce Store', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Shop assistant with product search, order tracking, and AI-powered recommendations. Designed for WooCommerce stores.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🛒';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#9c27b0';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name      = esc_html( $ctx['site_name'] );
		$tools_exec     = $ctx['tools_url'] . '/execute';
		$chat_url       = $ctx['chat_url'];
		$validate_url   = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id   = $ctx['assistant_id'];
		$chart_js_url   = isset( $ctx['chart_js_url'] ) ? $ctx['chart_js_url'] : '';
		$woo_source     = isset( $ctx['woo_source'] ) ? $ctx['woo_source'] : 'local';
		$woo_connection = isset( $ctx['woo_connection_id'] ) ? $ctx['woo_connection_id'] : '';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-ecommerce-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables ──

		*/
		':root{--tma-btn:#9c27b0;--tma-accent:#9c27b0;--tma-secondary-bg:#f8f4fb;' .
			'--ec-base:14px;--ec-label:12px;--ec-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.ec-font-small{--ec-base:12px;--ec-label:10px;--ec-heading:14px}' .
		'.ec-font-large{--ec-base:16px;--ec-label:14px;--ec-heading:18px}' .
		'.ec-compact .tma-product-grid{gap:6px;padding:6px 8px}' .
		'.ec-compact .tma-product-body{padding:4px 6px}' .
		'.ec-compact .ec-cart-item{padding:8px 10px}' .
		'.ec-compact .tma-order-item{padding:8px 10px;margin:0 8px 6px}' .

		/*
		 * ── Search bar ──

		*/
		'.tma-search-bar{padding:10px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border)}' .
		'.tma-search-wrap{display:flex;align-items:center;gap:8px;background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:10px;padding:0 12px}' .
		'.tma-search-wrap input{flex:1;border:none;outline:none;font-size:var(--ec-base);padding:10px 0;background:transparent;color:var(--tma-text)}' .

		/*
		 * ── Product grid ──

		*/
		'.tma-product-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px 12px}' .
		'.tma-product-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);overflow:hidden;cursor:pointer;position:relative}' .
		'.tma-product-card:active{opacity:.8}' .
		'.tma-product-img{width:100%;aspect-ratio:1;object-fit:cover;background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;font-size:32px}' .
		'.tma-product-img img{width:100%;height:100%;object-fit:cover}' .
		'.tma-product-body{padding:8px 10px}' .
		'.tma-product-name{font-size:var(--ec-label);font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-product-price{font-size:var(--ec-base);color:var(--tma-btn);font-weight:700}' .
		'.tma-product-price .ec-sale-price{text-decoration:line-through;color:var(--tma-hint);font-weight:400;font-size:var(--ec-label);margin-left:4px}' .
		'.ec-stock-badge{position:absolute;top:6px;right:6px;font-size:10px;padding:2px 6px;border-radius:8px;font-weight:600}' .
		'.ec-stock-instock{background:#e8f5e9;color:#2e7d32}' .
		'.ec-stock-outofstock{background:#ffebee;color:#c62828}' .
		'.ec-add-cart-btn{display:block;width:100%;padding:6px 0;margin-top:6px;border:none;border-radius:6px;' .
			'background:var(--tma-btn);color:var(--tma-btn-text);font-size:var(--ec-label);font-weight:600;cursor:pointer}' .
		'.ec-add-cart-btn:active{opacity:.8}' .

		/*
		 * ── Cart ──

		*/
		'.ec-cart-item{display:flex;align-items:center;gap:10px;background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);margin:0 12px 8px;padding:12px 14px}' .
		'.ec-cart-item-info{flex:1;min-width:0}' .
		'.ec-cart-item-name{font-size:var(--ec-base);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.ec-cart-item-price{font-size:var(--ec-label);color:var(--tma-hint)}' .
		'.ec-cart-qty{display:flex;align-items:center;gap:6px}' .
		'.ec-cart-qty button{width:28px;height:28px;border:1px solid var(--tma-border);border-radius:50%;background:var(--tma-bg);' .
			'color:var(--tma-text);font-size:16px;cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.ec-cart-qty button:active{background:var(--tma-secondary-bg)}' .
		'.ec-cart-qty span{font-size:var(--ec-base);font-weight:600;min-width:20px;text-align:center}' .
		'.ec-cart-remove{background:none;border:none;color:#c62828;font-size:18px;cursor:pointer;padding:0 4px}' .
		'.ec-cart-total{margin:12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);' .
			'display:flex;justify-content:space-between;align-items:center;font-size:var(--ec-heading);font-weight:700}' .
		'.ec-checkout-btn{display:block;margin:0 12px 12px;padding:14px;border:none;border-radius:var(--tma-radius);' .
			'background:var(--tma-btn);color:var(--tma-btn-text);font-size:var(--ec-base);font-weight:700;width:calc(100% - 24px);cursor:pointer;text-align:center}' .
		'.ec-checkout-btn:active{opacity:.8}' .

		/*
		 * ── Badge on nav ──

		*/
		'.ec-badge{position:absolute;top:-4px;right:-4px;min-width:16px;height:16px;padding:0 4px;border-radius:8px;' .
			'background:#c62828;color:#fff;font-size:10px;font-weight:700;display:flex;align-items:center;justify-content:center;line-height:1}' .

		/*
		 * ── Orders ──

		*/
		'.tma-order-item{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);margin:0 12px 8px;padding:14px}' .
		'.tma-order-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}' .
		'.tma-order-id{font-weight:700;font-size:var(--ec-base)}' .
		'.tma-order-total{font-size:var(--ec-base);font-weight:700;color:var(--tma-btn);margin-top:4px}' .
		'.tma-order-meta{font-size:var(--ec-label);color:var(--tma-hint)}' .
		'.ec-status-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;border:1px solid transparent}' .
		'.ec-status-completed{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.ec-status-processing{background:#e3f2fd;color:#1565c0;border-color:#90caf9}' .
		'.ec-status-pending,.ec-status-on-hold{background:#fff3e0;color:#e65100;border-color:#ffcc80}' .
		'.ec-status-failed,.ec-status-cancelled,.ec-status-refunded{background:#ffebee;color:#c62828;border-color:#ef9a9a}' .
		'.ec-pull-hint{text-align:center;font-size:var(--ec-label);color:var(--tma-hint);padding:8px 0}' .

		/*
		 * ── Spending chart ──

		*/
		'.ec-chart-wrap{margin:12px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px}' .
		'.ec-chart-title{font-size:var(--ec-label);font-weight:600;color:var(--tma-hint);margin-bottom:6px;text-align:center}' .

		/*
		 * ── AI Chat ──

		*/
		'.ec-chat-container{display:flex;flex-direction:column;height:100%}' .
		'.ec-chat-messages{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.ec-msg{max-width:85%;padding:10px 14px;border-radius:16px;font-size:var(--ec-base);line-height:1.5;word-wrap:break-word}' .
		'.ec-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.ec-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.ec-msg.bot p{margin:0 0 6px}' .
		'.ec-msg.bot p:last-child{margin-bottom:0}' .
		'.ec-msg.bot ul,.ec-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.ec-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.ec-chat-input-row{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg)}' .
		'.ec-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 14px;font-size:var(--ec-base);' .
			'background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.ec-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;' .
			'cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.ec-send-btn:active{opacity:.8}' .

		/*
		 * ── Settings ──

		*/
		'.ec-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.ec-settings-title{font-size:var(--ec-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.ec-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.ec-settings-row:last-child{border-bottom:none}' .
		'.ec-settings-label{font-size:var(--ec-base);color:var(--tma-text)}' .
		'.ec-settings-value{font-size:var(--ec-base);color:var(--tma-hint)}' .
		'.ec-font-btns{display:flex;gap:4px}' .
		'.ec-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);' .
			'color:var(--tma-text);font-size:var(--ec-label);cursor:pointer}' .
		'.ec-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.ec-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.ec-toggle.on{background:var(--tma-btn)}' .
		'.ec-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.ec-toggle.on::after{transform:translateX(20px)}' .
		'.ec-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);' .
			'background:var(--tma-bg);color:var(--tma-text);font-size:var(--ec-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.ec-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.ec-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .
		'.ec-connection-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}' .
		'.ec-connection-dot.online{background:#2e7d32}' .
		'.ec-connection-dot.local{background:#e65100}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">🛒</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="ec-header-status">' . esc_html__( 'Online Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .

			/*
			 * ── Search bar (visible on Products tab) ──

		 */
			'<div class="tma-search-bar" id="ec-search-bar">' .
				'<div class="tma-search-wrap">' .
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
					'<input type="search" id="ec-search-input" placeholder="' . esc_attr__( 'Search products…', 'mcp-ai-wpoos-pro' ) . '" />' .
				'</div>' .
			'</div>' .

			/*
			 * ── Content panes ──

		 */
			'<div class="tma-content">' .

				/*
				 * Tab 1: Products

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-products">' .
					'<div class="tma-section-title">' . esc_html__( 'Featured Products', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div class="tma-product-grid" id="ec-product-grid">' .
						'<div class="tma-empty" style="grid-column:span 2">' . esc_html__( 'Loading products…', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 2: Cart

			 */
				'<div class="tma-tab-pane" id="tma-tab-cart">' .
					'<div class="tma-section-title">' . esc_html__( 'Shopping Cart', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div id="ec-cart-list"><div class="tma-empty">' . esc_html__( 'Your cart is empty.', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
					'<div class="ec-cart-total" id="ec-cart-total" style="display:none">' .
						'<span>' . esc_html__( 'Total', 'mcp-ai-wpoos-pro' ) . '</span><span id="ec-cart-total-val">0</span>' .
					'</div>' .
					'<button class="ec-checkout-btn" id="ec-checkout-btn" style="display:none" onclick="ecCheckout()">' .
						esc_html__( 'Checkout', 'mcp-ai-wpoos-pro' ) .
					'</button>' .
				'</div>' .

				/*
				 * Tab 3: Orders

			 */
				'<div class="tma-tab-pane" id="tma-tab-orders">' .
					'<div class="tma-section-title" style="display:flex;justify-content:space-between;align-items:center">' .
						'<span>' . esc_html__( 'My Orders', 'mcp-ai-wpoos-pro' ) . '</span>' .
						'<button style="background:none;border:none;color:var(--tma-btn);font-size:var(--ec-label);cursor:pointer" onclick="ecLoadOrders(true)">' .
							esc_html__( 'Refresh', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .
					'<div id="ec-orders-list"><div class="tma-empty">' . esc_html__( 'Loading orders…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
					'<div class="ec-chart-wrap" id="ec-chart-wrap" style="display:none">' .
						'<div class="ec-chart-title">' . esc_html__( 'Monthly Spending', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<canvas id="ec-spend-chart" height="160"></canvas>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 4: AI Assistant

			 */
				'<div class="tma-tab-pane" id="tma-tab-assistant">' .
					'<div class="ec-chat-container">' .
						'<div class="ec-chat-messages" id="ec-chat-messages"></div>' .
						'<div class="ec-chat-input-row">' .
							'<input type="text" id="ec-chat-input" class="ec-chat-input"' .
								' placeholder="' . esc_attr__( 'Ask about products, orders…', 'mcp-ai-wpoos-pro' ) . '" />' .
							'<button class="ec-send-btn" id="ec-send-btn" onclick="ecChatSend()">' .
								'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
							'</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 5: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div class="tma-section-title">' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</div>' .

					/*
					 * Display section

				 */
					'<div class="ec-settings-section">' .
						'<div class="ec-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="ec-settings-row">' .
							'<span class="ec-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="ec-font-btns" id="ec-font-btns">' .
								'<button data-size="small" onclick="ecSetFontSize(\'small\')">A-</button>' .
								'<button data-size="medium" class="active" onclick="ecSetFontSize(\'medium\')">A</button>' .
								'<button data-size="large" onclick="ecSetFontSize(\'large\')">A+</button>' .
							'</div>' .
						'</div>' .
						'<div class="ec-settings-row">' .
							'<span class="ec-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="ec-toggle" id="ec-compact-toggle" onclick="ecToggleCompact()"></button>' .
						'</div>' .
					'</div>' .

					/*
					 * Store section

				 */
					'<div class="ec-settings-section">' .
						'<div class="ec-settings-title">' . esc_html__( 'Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="ec-settings-row">' .
							'<span class="ec-settings-label">' . esc_html__( 'Connection', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="ec-settings-value" id="ec-connection-info"></span>' .
						'</div>' .
						'<div class="ec-settings-row">' .
							'<span class="ec-settings-label">' . esc_html__( 'Currency', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="ec-settings-value" id="ec-currency-display">—</span>' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="ec-settings-section">' .
						'<div class="ec-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="ec-settings-row">' .
							'<span class="ec-settings-label" id="ec-data-summary"></span>' .
						'</div>' .
						'<button class="ec-settings-btn" onclick="ecSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="ec-settings-btn danger" onclick="ecClearData()">' .
							esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (5 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-products" onclick="ecSwitch(\'products\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' .
					'<span>' . esc_html__( 'Shop', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-cart" onclick="ecSwitch(\'cart\')" style="position:relative">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="9" cy="21" r="1"/><circle cx="20" cy="21" r="1"/><path d="M1 1h4l2.68 13.39a2 2 0 0 0 2 1.61h9.72a2 2 0 0 0 2-1.61L23 6H6"/></svg>' .
					'<span id="ec-cart-badge" class="ec-badge" style="display:none"></span>' .
					'<span>' . esc_html__( 'Cart', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-orders" onclick="ecSwitch(\'orders\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' .
					'<span>' . esc_html__( 'Orders', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-assistant" onclick="ecSwitch(\'assistant\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="ecSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .
		'var WOO_SOURCE=' . wp_json_encode( $woo_source ) . ';' .
		'var WOO_CONNECTION_ID=' . wp_json_encode( $woo_connection ) . ';' .
		'var SITE_NAME=' . wp_json_encode( $ctx['site_name'] ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="products";' .
		'var cart=[];' .
		'var orders=[];' .
		'var chatHist=[];' .
		'var productsCache=[];' .
		'var spendChartInst=null;' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .

		/*
		 * Simple markdown-like renderer for bot messages

		*/
		'function ecRenderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── localStorage helpers ──

		*/
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		── Tool call helper (supports local/remote WooCommerce) ──

		*/

		/*
		 * Remote actions map to remote_wp_connection action parameter values (uses per_page natively)

		*/
		'var EC_REMOTE_MAP={"search_woocommerce_products":"get_wc_products","get_woocommerce_orders":"get_wc_orders","create_woocommerce_order":"create_wc_order"};' .

		/*
		 * Local tool slugs; create_woocommerce_order has no local equivalent – the checkout callback already has a fallback alert

		*/
		'var EC_LOCAL_MAP={"search_woocommerce_products":"get_woo_products","get_woocommerce_orders":"get_woo_recent_orders"};' .
		'function ecToolCall(slug,args,cb){' .
			'var body;' .
			'if(WOO_SOURCE==="remote"&&WOO_CONNECTION_ID){' .

				/*
				 * Remote: route through remote_wp_connection with action + flat args

			 */
				'var remoteAction=EC_REMOTE_MAP[slug]||slug;' .
				'var remoteArgs={action:remoteAction,connection_id:WOO_CONNECTION_ID};' .
				'for(var k in args){if(args.hasOwnProperty(k)){remoteArgs[k]=args[k];}}' .
				'body={slug:"remote_wp_connection",arguments:remoteArgs};' .
			'}else{' .

				/*
				Local: map to actual registered tool slugs and adapt args.
					Local WooCommerce tools use "limit" instead of "per_page";
					remote tools accept "per_page" natively via the WC REST API.

				 */
				'var localSlug=EC_LOCAL_MAP[slug]||slug;' .
				'var localArgs={};for(var k in args){if(args.hasOwnProperty(k)){localArgs[k]=args[k];}}' .
				'if(localArgs.per_page&&!localArgs.limit){localArgs.limit=localArgs.per_page;delete localArgs.per_page;}' .
				'body={slug:localSlug,arguments:localArgs};' .
			'}' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .

				/*
				 * Normalise: controller returns {success,result} but callbacks expect {data}

			 */
				'if(d&&d.result&&!d.data){d.data=d.result;}' .
				'cb(null,d);' .
			'})' .
			'.catch(function(e){cb(e,null);});' .
		'}' .

		/*
		 * ── Session init (matches medical_vitals pattern) ──

		*/
		'function ecInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp){ecLoadProducts();return;}' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData){ecLoadProducts();return;}' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d){ecLoadProducts();return;}if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}ecLoadProducts();ecLoadOrders(false);})' .
			'.catch(function(){ecLoadProducts();});' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function ecApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("ec_font_size","medium");' .
				'shell.classList.remove("ec-font-small","ec-font-large");' .
				'if(size==="small")shell.classList.add("ec-font-small");' .
				'else if(size==="large")shell.classList.add("ec-font-large");' .
				'var compact=lsGet("ec_compact",false);' .
				'if(compact)shell.classList.add("ec-compact");' .
				'else shell.classList.remove("ec-compact");' .

				/*
				 * Update settings UI

			 */
				'var btns=document.querySelectorAll("#ec-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("ec-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
			'}catch(e){}' .
		'}' .
		'window.ecSetFontSize=function(s){lsSet("ec_font_size",s);tmaHaptic("selectionChanged");ecApplyDisplaySettings();};' .
		'window.ecToggleCompact=function(){var c=!lsGet("ec_compact",false);lsSet("ec_compact",c);tmaHaptic("selectionChanged");ecApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.ecSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'var sb=document.getElementById("ec-search-bar");if(sb)sb.style.display=tab==="products"?"":"none";' .
			'activeTab=tab;' .
			'if(tab==="orders")ecLoadOrders(false);' .
			'if(tab==="cart")ecRenderCart();' .
			'if(tab==="assistant"&&!chatHist.length)ecChatInit();' .
			'if(tab==="settings")ecRenderSettings();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Products
			══════════════════════════════════════════════════════════

		*/
		'function ecLoadProducts(q){' .
			'var g=document.getElementById("ec-product-grid");if(!g)return;' .

			/*
			 * Show cached products immediately while fetching

		 */
			'if(!q&&productsCache.length){ecRenderProducts(productsCache);}' .
			'if(!q&&!productsCache.length)g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'ecToolCall("search_woocommerce_products",{search:q||"",per_page:20},function(err,d){' .
				'if(err){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Could not load products.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'var ps=(d&&d.data&&d.data.products)?d.data.products:[];' .
				'if(!q){productsCache=ps;lsSet("ec_products_cache",ps);}' .
				'ecRenderProducts(ps);' .
			'});' .
		'}' .

		'function ecRenderProducts(ps){' .
			'var g=document.getElementById("ec-product-grid");if(!g)return;' .
			'if(!ps.length){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'No products found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'g.innerHTML=ps.map(function(p,i){' .
				'var img=(p.images&&p.images[0]&&p.images[0].src)?"<img src=\\""+escH(p.images[0].src)+"\\" alt=\\"\\"/>":"🛍️";' .
				'var stock=(p.stock_status==="instock"||p.in_stock===true);' .
				'var stockCls=stock?"ec-stock-instock":"ec-stock-outofstock";' .
				'var stockTxt=stock?"' . esc_js( __( 'In Stock', 'mcp-ai-wpoos-pro' ) ) . '":"' . esc_js( __( 'Out of Stock', 'mcp-ai-wpoos-pro' ) ) . '";' .

				/*
				 * Price display with sale support

			 */
				'var priceHtml="";' .
				'if(p.sale_price&&p.regular_price&&p.sale_price!==p.regular_price){' .
					'priceHtml=escH(p.price_html||p.sale_price)+"<span class=\\"ec-sale-price\\">"+escH(p.regular_price)+"</span>";' .
				'}else{' .
					'priceHtml=escH(p.price_html||p.price||"");' .
				'}' .
				'var addBtn=stock?"<button class=\\"ec-add-cart-btn\\" onclick=\\"ecAddToCart("+i+");event.stopPropagation()\\">' . esc_js( __( 'Add to Cart', 'mcp-ai-wpoos-pro' ) ) . '</button>":"";' .
				'return \'<div class="tma-product-card">' .
					'<span class="ec-stock-badge \'+stockCls+\'">\'+escH(stockTxt)+\'</span>' .
					'<div class="tma-product-img">\'+img+\'</div>' .
					'<div class="tma-product-body">' .
						'<div class="tma-product-name">\'+escH(p.name||"Product")+\'</div>' .
						'<div class="tma-product-price">\'+priceHtml+\'</div>' .
						'\'+addBtn+\'' .
					'</div></div>\';' .
			'}).join("");' .
		'}' .

		/*
		 * Debounced search

		*/
		'var searchTimer=null;' .
		'document.getElementById("ec-search-input").addEventListener("input",function(e){' .
			'clearTimeout(searchTimer);var q=e.target.value;' .
			'searchTimer=setTimeout(function(){ecLoadProducts(q);},400);' .
		'});' .

		/*
		 * Add to cart from product grid

		*/
		'window.ecAddToCart=function(idx){' .
			'var p=productsCache[idx];if(!p)return;tmaHaptic("light");' .
			'var existing=cart.find(function(c){return c.id===p.id;});' .
			'if(existing){existing.qty+=1;}else{' .
				'var rawPrice=String(p.price||"0").replace(/[^0-9.\\-]/g,"");' .
				'cart.push({id:p.id,name:p.name||"Product",price:parseFloat(rawPrice)||0,qty:1,' .
					'image:(p.images&&p.images[0]&&p.images[0].src)||""});' .
			'}' .
			'lsSet("ec_cart",cart);ecUpdateCartBadge();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – Cart
			══════════════════════════════════════════════════════════

		*/
		'function ecRenderCart(){' .
			'var l=document.getElementById("ec-cart-list");if(!l)return;' .
			'var totEl=document.getElementById("ec-cart-total");' .
			'var chkEl=document.getElementById("ec-checkout-btn");' .
			'if(!cart.length){' .
				'l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Your cart is empty.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
				'if(totEl)totEl.style.display="none";if(chkEl)chkEl.style.display="none";return;' .
			'}' .
			'var total=0;' .
			'l.innerHTML=cart.map(function(c,i){' .
				'total+=c.price*c.qty;' .
				'return \'<div class="ec-cart-item">' .
					'<div class="ec-cart-item-info"><div class="ec-cart-item-name">\'+escH(c.name)+\'</div>' .
					'<div class="ec-cart-item-price">\'+escH(c.price.toFixed(2))+\' × \'+c.qty+\'</div></div>' .
					'<div class="ec-cart-qty">' .
						'<button onclick="ecCartQty(\'+i+\',-1)">−</button>' .
						'<span>\'+c.qty+\'</span>' .
						'<button onclick="ecCartQty(\'+i+\',1)">+</button>' .
					'</div>' .
					'<button class="ec-cart-remove" onclick="ecCartRemove(\'+i+\')">✕</button>' .
				'</div>\';' .
			'}).join("");' .
			'if(totEl){totEl.style.display="flex";document.getElementById("ec-cart-total-val").textContent=total.toFixed(2);}' .
			'if(chkEl)chkEl.style.display="block";' .
		'}' .

		'window.ecCartQty=function(i,d){' .
			'if(!cart[i])return;tmaHaptic("light");' .
			'cart[i].qty+=d;' .
			'if(cart[i].qty<1)cart.splice(i,1);' .
			'lsSet("ec_cart",cart);ecUpdateCartBadge();ecRenderCart();' .
		'};' .

		'window.ecCartRemove=function(i){' .
			'tmaHaptic("light");cart.splice(i,1);lsSet("ec_cart",cart);ecUpdateCartBadge();ecRenderCart();' .
		'};' .

		'function ecUpdateCartBadge(){' .
			'var b=document.getElementById("ec-cart-badge");if(!b)return;' .
			'var cnt=cart.reduce(function(s,c){return s+c.qty;},0);' .
			'if(cnt>0){b.textContent=cnt>99?"99+":cnt;b.style.display="flex";}' .
			'else{b.style.display="none";}' .
		'}' .

		'window.ecCheckout=function(){' .
			'tmaHaptic("medium");' .
			'var total=cart.reduce(function(s,c){return s+c.price*c.qty;},0);' .
			'var items=cart.map(function(c){return c.name+" x"+c.qty;}).join(", ");' .

			/*
			 * Attempt WooCommerce create order via tool, fallback to summary

		 */
			'ecToolCall("create_woocommerce_order",{line_items:cart.map(function(c){return{product_id:c.id,quantity:c.qty};})},function(err,d){' .
				'if(!err&&d&&d.data&&d.data.order){' .
					'var orderId=d.data.order.id||"";' .
					'cart=[];lsSet("ec_cart",cart);ecUpdateCartBadge();ecRenderCart();' .
					'var sMsg="' . esc_js( __( 'Order placed successfully!', 'mcp-ai-wpoos-pro' ) ) . '"+(orderId?" #"+orderId:"");' .
					'if(window.Telegram&&window.Telegram.WebApp){window.Telegram.WebApp.showAlert(sMsg,function(){ecLoadOrders(true);ecSwitch("orders");});}' .
					'else{alert(sMsg);ecLoadOrders(true);ecSwitch("orders");}' .
				'}else{' .
					'var msg="' . esc_js( __( 'Order Summary', 'mcp-ai-wpoos-pro' ) ) . ':\\n"+items+"\\n' . esc_js( __( 'Total', 'mcp-ai-wpoos-pro' ) ) . ': "+total.toFixed(2);' .
					'if(window.Telegram&&window.Telegram.WebApp){window.Telegram.WebApp.showAlert(msg);}else{alert(msg);}' .
				'}' .
			'});' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – Orders
			══════════════════════════════════════════════════════════

		*/
		'function ecLoadOrders(force){' .
			'var l=document.getElementById("ec-orders-list");if(!l)return;' .

			/*
			 * Show cached orders immediately

		 */
			'if(!force&&!orders.length){orders=lsGet("ec_orders_cache",[]);}' .
			'if(orders.length&&!force){ecRenderOrders(orders);return;}' .
			'l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading orders…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'ecToolCall("get_woocommerce_orders",{per_page:20},function(err,d){' .
				'if(err){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load orders.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'var os=(d&&d.data&&d.data.orders)?d.data.orders:[];' .
				'orders=os;lsSet("ec_orders_cache",os);ecRenderOrders(os);' .
			'});' .
		'}' .

		'function ecStatusClass(s){' .
			'switch(s){' .
				'case "completed":return "ec-status-completed";' .
				'case "processing":return "ec-status-processing";' .
				'case "pending":case "on-hold":return "ec-status-pending";' .
				'default:return "ec-status-failed";' .
			'}' .
		'}' .

		'function ecRenderOrders(os){' .
			'var l=document.getElementById("ec-orders-list");if(!l)return;' .
			'if(!os.length){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No orders found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';ecHideChart();return;}' .
			'l.innerHTML=os.map(function(o){' .
				'var s=o.status||"processing";' .
				'var total=o.total||o.order_total||"";' .
				'var date=o.date_created||o.date||"";' .
				'if(date&&date.length>10)date=date.substring(0,10);' .
				'return \'<div class="tma-order-item">' .
					'<div class="tma-order-header">' .
						'<span class="tma-order-id">#\'+escH(o.id||"")+\'</span>' .
						'<span class="ec-status-badge \'+ecStatusClass(s)+\'">\'+escH(s)+\'</span>' .
					'</div>' .
					'<div class="tma-order-meta">\'+escH(date)+\'</div>' .
					'<div class="tma-order-total">\'+escH(total)+\'</div>' .
				'</div>\';' .
			'}).join("");' .
			'ecRenderSpendChart(os);' .
		'}' .

		'function ecHideChart(){var w=document.getElementById("ec-chart-wrap");if(w)w.style.display="none";}' .

		/*
		 * ── Spending Chart (Chart.js) ──

		*/
		'function ecLoadChartJs(cb){' .
			'if(window.Chart){cb();return;}' .
			'if(!CHART_JS_URL){cb();return;}' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;s.onload=cb;s.onerror=cb;document.head.appendChild(s);' .
		'}' .

		'function ecRenderSpendChart(os){' .
			'ecLoadChartJs(function(){' .
				'if(!window.Chart){ecHideChart();return;}' .
				'var monthly={};' .
				'os.forEach(function(o){' .
					'var d=o.date_created||o.date||"";if(!d)return;' .

					/*
					 * Parse ISO or common date formats to YYYY-MM

				 */
					'var dt=new Date(d);var m;' .
					'if(!isNaN(dt.getTime())){m=dt.getFullYear()+"-"+("0"+(dt.getMonth()+1)).slice(-2);}' .
					'else if(/^\\d{4}-\\d{2}/.test(d)){m=d.substring(0,7);}' .
					'else{return;}' .
					'var t=parseFloat(o.total||o.order_total||0);' .
					'if(!monthly[m])monthly[m]=0;monthly[m]+=t;' .
				'});' .
				'var keys=Object.keys(monthly).sort();' .
				'if(!keys.length){ecHideChart();return;}' .
				'var labels=keys;var data=keys.map(function(k){return Math.round(monthly[k]*100)/100;});' .
				'var wrap=document.getElementById("ec-chart-wrap");if(wrap)wrap.style.display="block";' .
				'var cv=document.getElementById("ec-spend-chart");if(!cv)return;' .
				'if(spendChartInst){spendChartInst.destroy();}' .
				'spendChartInst=new Chart(cv.getContext("2d"),{type:"line",data:{labels:labels,datasets:[{' .
					'label:"' . esc_js( __( 'Spending', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'data:data,borderColor:"#9c27b0",backgroundColor:"rgba(156,39,176,0.1)",' .
					'fill:true,tension:0.3,pointRadius:3' .
				'}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true}}}});' .
			'});' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 4 – AI Assistant
			══════════════════════════════════════════════════════════

		*/
		'function ecChatInit(){' .

			/*
			 * Load chat history from localStorage

		 */
			'chatHist=lsGet("ec_chat_hist",[]);' .
			'var m=document.getElementById("ec-chat-messages");if(!m)return;m.innerHTML="";' .
			'if(chatHist.length){' .
				'chatHist.forEach(function(msg){ecAppendMsg(msg.role==="user"?"user":"bot",msg.content,true);});' .
			'}else{' .

				/*
				 * Pre-seed with store context

			 */
				'var ctx="[' . esc_js( __( 'Store context', 'mcp-ai-wpoos-pro' ) ) . '] ' . esc_js( __( 'Site', 'mcp-ai-wpoos-pro' ) ) . ': "+SITE_NAME+", ' .
					esc_js( __( 'Cart items', 'mcp-ai-wpoos-pro' ) ) . ': "+cart.length+", ' .
					esc_js( __( 'Recent orders', 'mcp-ai-wpoos-pro' ) ) . ': "+orders.length;' .
				'chatHist.push({role:"system",content:ctx});' .
				'ecAppendMsg("bot","' . esc_js( __( 'Hi! I\'m your shopping assistant. I can help you find products, track orders, and answer questions about our store.', 'mcp-ai-wpoos-pro' ) ) . '",false);' .
			'}' .
		'}' .

		'function ecAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="ec-msg "+role;' .
			'if(role==="bot"){el.innerHTML=ecRenderMd(text);}' .
			'else{el.textContent=text;}' .
			'var m=document.getElementById("ec-chat-messages");' .
			'if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;' .
		'}' .

		'window.ecChatSend=function(){' .
			'var inp=document.getElementById("ec-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'chatHist.push({role:"user",content:txt});ecAppendMsg("user",txt,false);' .
			'lsSet("ec_chat_hist",chatHist.slice(-50));' .
			'var el=ecAppendMsg("bot","\u2026",false);' .
			'var body={messages:chatHist.filter(function(m){return m.role!=="system";}).slice(-12)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .

			/*
			 * Include system context

		 */
			'var sys=chatHist.find(function(m){return m.role==="system";});' .
			'if(sys)body.messages.unshift(sys);' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data;' .
				'var rep=(data&&data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)||' .
					'(data&&data.content)||(data&&data.response)||"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.innerHTML=ecRenderMd(rep);chatHist.push({role:"assistant",content:rep});' .
				'lsSet("ec_chat_hist",chatHist.slice(-50));' .
			'})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .

		/*
		 * Enter to send

		*/
		'document.getElementById("ec-chat-input").addEventListener("keydown",function(e){if(e.key==="Enter")ecChatSend();});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 5 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function ecRenderSettings(){' .

			/*
			 * Connection indicator

		 */
			'var ci=document.getElementById("ec-connection-info");' .
			'if(ci){' .
				'if(WOO_SOURCE==="remote"){' .
					'ci.innerHTML=\'<span class="ec-connection-dot online"></span>' . esc_js( __( 'Remote Store', 'mcp-ai-wpoos-pro' ) ) . '\';' .
				'}else{' .
					'ci.innerHTML=\'<span class="ec-connection-dot local"></span>' . esc_js( __( 'Local Store', 'mcp-ai-wpoos-pro' ) ) . '\';' .
				'}' .
			'}' .

			/*
			 * Data summary

		 */
			'var ds=document.getElementById("ec-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Cached products', 'mcp-ai-wpoos-pro' ) ) . ': "+productsCache.length+", ' .
				esc_js( __( 'Orders', 'mcp-ai-wpoos-pro' ) ) . ': "+orders.length+", ' .
				esc_js( __( 'Cart', 'mcp-ai-wpoos-pro' ) ) . ': "+cart.length+", ' .
				esc_js( __( 'Chat messages', 'mcp-ai-wpoos-pro' ) ) . ': "+chatHist.length;' .

			/*
			 * Currency display

		 */
			'var cd=document.getElementById("ec-currency-display");' .
			'if(cd){' .
				'if(orders.length&&orders[0].currency){cd.textContent=orders[0].currency;}' .
				'else if(orders.length&&orders[0].currency_symbol){cd.textContent=orders[0].currency_symbol;}' .
				'else{cd.textContent="\u2014";}' .
			'}' .
		'}' .

		'window.ecSyncFromServer=function(){' .
			'tmaHaptic("medium");ecLoadProducts();ecLoadOrders(true);' .
		'};' .

		'window.ecClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all local data? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)ecDoClear();});' .
			'}else if(confirm(msg)){ecDoClear();}' .
		'};' .

		'function ecDoClear(){' .
			'try{' .
				'localStorage.removeItem("ec_products_cache");' .
				'localStorage.removeItem("ec_orders_cache");' .
				'localStorage.removeItem("ec_cart");' .
				'localStorage.removeItem("ec_chat_hist");' .
				'localStorage.removeItem("ec_font_size");' .
				'localStorage.removeItem("ec_compact");' .
			'}catch(e){}' .
			'productsCache=[];orders=[];cart=[];chatHist=[];' .
			'ecUpdateCartBadge();ecRenderSettings();tmaHaptic("notificationSuccess");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Restore state from localStorage

		*/
		'cart=lsGet("ec_cart",[]);' .
		'productsCache=lsGet("ec_products_cache",[]);' .
		'orders=lsGet("ec_orders_cache",[]);' .
		'ecUpdateCartBadge();' .
		'ecApplyDisplaySettings();' .

		/*
		 * Render cached products immediately, then refresh

		*/
		'if(productsCache.length)ecRenderProducts(productsCache);' .

		/*
		 * Session init for Telegram WebApp (authenticates, then loads fresh data)

		*/
		'ecInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * CRM template – customer relationship management interface.
 */
class WP_MCP_AI_TMA_Template_CRM extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'crm';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'CRM Assistant', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Customer management interface with contact lookup, lead pipeline, and AI-powered follow-up drafts.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'crm';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '👥';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#e65100';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$chat_url     = $ctx['chat_url'];
		$validate_url = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id = $ctx['assistant_id'];
		$chart_js_url = isset( $ctx['chart_js_url'] ) ? $ctx['chart_js_url'] : '';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-crm-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables ──

		*/
		':root{--tma-btn:#e65100;--tma-accent:#e65100;--tma-secondary-bg:#fff3ee;' .
			'--crm-base:14px;--crm-label:12px;--crm-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.crm-font-small{--crm-base:12px;--crm-label:10px;--crm-heading:14px}' .
		'.crm-font-large{--crm-base:16px;--crm-label:14px;--crm-heading:18px}' .
		'.crm-compact .tma-contact-row{padding:8px 12px}' .
		'.crm-compact .tma-pipeline-card{padding:6px 8px;margin-bottom:4px}' .
		'.crm-compact .crm-deal-card{padding:8px 10px;margin:0 8px 6px}' .

		/*
		 * ── Search bar ──

		*/
		'.tma-search-bar{padding:10px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border)}' .
		'.tma-search-wrap{display:flex;align-items:center;gap:8px;background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:10px;padding:0 12px}' .
		'.tma-search-wrap input{flex:1;border:none;outline:none;font-size:var(--crm-base);padding:10px 0;background:transparent;color:var(--tma-text)}' .

		/*
		 * ── Contacts ──

		*/
		'.tma-contact-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--tma-border);cursor:pointer}' .
		'.tma-contact-row:active{background:var(--tma-secondary-bg)}' .
		'.tma-contact-avatar{width:40px;height:40px;border-radius:50%;background:var(--tma-btn);color:var(--tma-btn-text);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0}' .
		'.tma-contact-info{flex:1;min-width:0}' .
		'.tma-contact-name{font-weight:600;font-size:var(--crm-base);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;display:flex;align-items:center;gap:6px}' .
		'.tma-contact-sub{font-size:var(--crm-label);color:var(--tma-hint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-contact-meta{font-size:var(--crm-label);color:var(--tma-subtitle);margin-top:2px}' .
		'.crm-status-dot{display:inline-block;width:8px;height:8px;border-radius:50%;flex-shrink:0}' .
		'.crm-status-active{background:#4caf50}' .
		'.crm-status-inactive{background:#9e9e9e}' .
		'.crm-status-new{background:#2196f3}' .
		'.crm-pull-hint{text-align:center;font-size:var(--crm-label);color:var(--tma-hint);padding:8px 0}' .

		/*
		 * ── Pipeline ──

		*/
		'.tma-pipeline{display:flex;gap:10px;padding:10px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch}' .
		'.tma-pipeline-col{min-width:170px;background:var(--tma-secondary-bg);border-radius:var(--tma-radius);padding:10px;flex-shrink:0}' .
		'.tma-pipeline-header{display:flex;align-items:center;gap:6px;margin-bottom:8px}' .
		'.tma-pipeline-dot{width:10px;height:10px;border-radius:50%;flex-shrink:0}' .
		'.tma-pipeline-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--tma-hint)}' .
		'.tma-pipeline-stats{font-size:10px;color:var(--tma-subtitle);margin-left:auto;white-space:nowrap}' .
		'.tma-pipeline-card{background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:8px;padding:10px;margin-bottom:8px;font-size:var(--crm-label);cursor:pointer}' .
		'.tma-pipeline-card:active{opacity:.85}' .
		'.tma-pipeline-card-name{font-weight:600;font-size:var(--crm-base);margin-bottom:2px}' .
		'.tma-pipeline-card-value{color:var(--tma-btn);font-weight:700;font-size:var(--crm-base)}' .
		'.tma-pipeline-card-detail{display:none;margin-top:6px;padding-top:6px;border-top:1px solid var(--tma-border);font-size:var(--crm-label);color:var(--tma-hint)}' .
		'.tma-pipeline-card.expanded .tma-pipeline-card-detail{display:block}' .

		/*
		 * ── Deals ──

		*/
		'.crm-kpi-row{display:flex;gap:8px;padding:10px 12px;overflow-x:auto}' .
		'.crm-kpi-card{flex:1;min-width:90px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px;text-align:center}' .
		'.crm-kpi-value{font-size:var(--crm-heading);font-weight:700;color:var(--tma-btn)}' .
		'.crm-kpi-label{font-size:var(--crm-label);color:var(--tma-hint);margin-top:2px}' .
		'.crm-donut-wrap{margin:8px 12px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px}' .
		'.crm-donut-title{font-size:var(--crm-label);font-weight:600;color:var(--tma-hint);margin-bottom:6px;text-align:center}' .
		'.crm-deal-card{display:flex;align-items:center;gap:10px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);margin:0 12px 8px;padding:12px 14px}' .
		'.crm-deal-info{flex:1;min-width:0}' .
		'.crm-deal-name{font-size:var(--crm-base);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.crm-deal-value{font-size:var(--crm-base);font-weight:700;color:var(--tma-btn);flex-shrink:0}' .
		'.crm-deal-meta{font-size:var(--crm-label);color:var(--tma-hint);margin-top:2px}' .
		'.crm-stage-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;border:1px solid transparent;white-space:nowrap}' .
		'.crm-stage-lead{background:#e3f2fd;color:#1565c0;border-color:#90caf9}' .
		'.crm-stage-qualified{background:#fff3e0;color:#e65100;border-color:#ffcc80}' .
		'.crm-stage-proposal{background:#f3e5f5;color:#7b1fa2;border-color:#ce93d8}' .
		'.crm-stage-won{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.crm-stage-lost{background:#ffebee;color:#c62828;border-color:#ef9a9a}' .
		'.crm-deal-status-won{border-left:3px solid #4caf50}' .
		'.crm-deal-status-lost{border-left:3px solid #e53935}' .
		'.crm-deal-status-stale{border-left:3px solid #ff9800}' .
		'.crm-deal-status-active{border-left:3px solid #2196f3}' .

		/*
		 * ── AI Coach chat ──

		*/
		'.crm-chat-container{display:flex;flex-direction:column;height:100%}' .
		'.crm-chat-messages{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.crm-msg{max-width:85%;padding:10px 14px;border-radius:16px;font-size:var(--crm-base);line-height:1.5;word-wrap:break-word}' .
		'.crm-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.crm-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.crm-msg.bot p{margin:0 0 6px}.crm-msg.bot p:last-child{margin-bottom:0}' .
		'.crm-msg.bot ul,.crm-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.crm-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.crm-chat-input-row{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg)}' .
		'.crm-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 14px;font-size:var(--crm-base);background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.crm-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.crm-send-btn:active{opacity:.8}' .

		/*
		 * ── Settings ──

		*/
		'.crm-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.crm-settings-title{font-size:var(--crm-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.crm-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.crm-settings-row:last-child{border-bottom:none}' .
		'.crm-settings-label{font-size:var(--crm-base);color:var(--tma-text)}' .
		'.crm-settings-value{font-size:var(--crm-base);color:var(--tma-hint)}' .
		'.crm-font-btns{display:flex;gap:4px}' .
		'.crm-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);color:var(--tma-text);font-size:var(--crm-label);cursor:pointer}' .
		'.crm-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.crm-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.crm-toggle.on{background:var(--tma-btn)}' .
		'.crm-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.crm-toggle.on::after{transform:translateX(20px)}' .
		'.crm-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);background:var(--tma-bg);color:var(--tma-text);font-size:var(--crm-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.crm-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.crm-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">👥</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="crm-header-status">' . esc_html__( 'CRM', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .

			/*
			 * ── Search bar (visible on Contacts tab) ──

		 */
			'<div class="tma-search-bar" id="crm-search-bar">' .
				'<div class="tma-search-wrap">' .
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
					'<input type="search" id="crm-search-input" placeholder="' . esc_attr__( 'Search contacts…', 'mcp-ai-wpoos-pro' ) . '" />' .
				'</div>' .
			'</div>' .

			/*
			 * ── Content panes ──

		 */
			'<div class="tma-content">' .

				/*
				 * Tab 1: Contacts

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-contacts">' .
					'<div id="crm-contact-list"><div class="tma-empty">' . esc_html__( 'Loading contacts…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
					'<div class="crm-pull-hint" id="crm-pull-hint" style="display:none">' . esc_html__( '↑ Pull to refresh', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .

				/*
				 * Tab 2: Pipeline

			 */
				'<div class="tma-tab-pane" id="tma-tab-pipeline">' .
					'<div class="tma-pipeline" id="crm-pipeline"><div class="tma-empty">' . esc_html__( 'Loading pipeline…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 3: Deals

			 */
				'<div class="tma-tab-pane" id="tma-tab-deals">' .
					'<div class="crm-kpi-row" id="crm-kpi-row"></div>' .
					'<div class="crm-donut-wrap" id="crm-donut-wrap" style="display:none">' .
						'<div class="crm-donut-title">' . esc_html__( 'Deal Value by Stage', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<canvas id="crm-donut-chart" height="200"></canvas>' .
					'</div>' .
					'<div class="tma-section-title" style="padding:4px 12px 0">' . esc_html__( 'All Deals', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div id="crm-deals-list"><div class="tma-empty">' . esc_html__( 'Loading deals…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 4: AI Coach

			 */
				'<div class="tma-tab-pane" id="tma-tab-coach">' .
					'<div class="crm-chat-container">' .
						'<div class="crm-chat-messages" id="crm-chat-messages"></div>' .
						'<div class="crm-chat-input-row">' .
							'<input type="text" class="crm-chat-input" id="crm-chat-input" placeholder="' . esc_attr__( 'Ask your CRM coach…', 'mcp-ai-wpoos-pro' ) . '" />' .
							'<button class="crm-send-btn" onclick="crmChatSend()">' .
								'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
							'</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 5: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div style="padding-top:12px">' .

					/*
					 * Display section

				 */
					'<div class="crm-settings-section">' .
						'<div class="crm-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="crm-settings-row">' .
							'<span class="crm-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="crm-font-btns" id="crm-font-btns">' .
								'<button data-size="small" onclick="crmSetFontSize(\'small\')">' . esc_html__( 'S', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="medium" onclick="crmSetFontSize(\'medium\')">' . esc_html__( 'M', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="large" onclick="crmSetFontSize(\'large\')">' . esc_html__( 'L', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'</div>' .
						'</div>' .
						'<div class="crm-settings-row">' .
							'<span class="crm-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="crm-toggle" id="crm-compact-toggle" onclick="crmToggleCompact()"></button>' .
						'</div>' .
						'<div class="crm-settings-row">' .
							'<span class="crm-settings-label">' . esc_html__( 'Default Pipeline View', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="crm-settings-value" id="crm-default-view-label">—</span>' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="crm-settings-section">' .
						'<div class="crm-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="crm-settings-row">' .
							'<span class="crm-settings-label" id="crm-data-summary"></span>' .
						'</div>' .
						'<button class="crm-settings-btn" onclick="crmSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="crm-settings-btn danger" onclick="crmClearData()">' .
							esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .

					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (5 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-contacts" onclick="crmSwitch(\'contacts\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
					'<span>' . esc_html__( 'Contacts', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-pipeline" onclick="crmSwitch(\'pipeline\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>' .
					'<span>' . esc_html__( 'Pipeline', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-deals" onclick="crmSwitch(\'deals\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="1" x2="12" y2="23"/><path d="M17 5H9.5a3.5 3.5 0 0 0 0 7h5a3.5 3.5 0 0 1 0 7H6"/></svg>' .
					'<span>' . esc_html__( 'Deals', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-coach" onclick="crmSwitch(\'coach\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="crmSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .
		'var SITE_NAME=' . wp_json_encode( $ctx['site_name'] ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="contacts";' .
		'var contactsCache=[];' .
		'var pipelineCache=[];' .
		'var dealsCache=[];' .
		'var chatHist=[];' .
		'var donutChartInst=null;' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		 * Simple markdown-like renderer for bot messages

		*/
		'function crmRenderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── Session init (matches ecommerce / medical_vitals pattern) ──

		*/
		'function crmInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp)return;' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData)return;' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d)return;if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}loadContacts();loadPipeline();})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function crmApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("crm_font_size","medium");' .
				'shell.classList.remove("crm-font-small","crm-font-large");' .
				'if(size==="small")shell.classList.add("crm-font-small");' .
				'else if(size==="large")shell.classList.add("crm-font-large");' .
				'var compact=lsGet("crm_compact",false);' .
				'if(compact)shell.classList.add("crm-compact");' .
				'else shell.classList.remove("crm-compact");' .
				'var btns=document.querySelectorAll("#crm-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("crm-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
			'}catch(e){}' .
		'}' .
		'window.crmSetFontSize=function(s){lsSet("crm_font_size",s);tmaHaptic("selectionChanged");crmApplyDisplaySettings();};' .
		'window.crmToggleCompact=function(){var c=!lsGet("crm_compact",false);lsSet("crm_compact",c);tmaHaptic("selectionChanged");crmApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.crmSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'var sb=document.getElementById("crm-search-bar");if(sb)sb.style.display=tab==="contacts"?"":"none";' .
			'activeTab=tab;' .
			'if(tab==="contacts")loadContacts();' .
			'if(tab==="pipeline")loadPipeline();' .
			'if(tab==="deals")crmRenderDeals();' .
			'if(tab==="coach"&&!chatHist.length)crmChatInit();' .
			'if(tab==="settings")crmRenderSettings();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Contacts
			══════════════════════════════════════════════════════════

		*/
		'function crmStatusDot(s){' .
			'var cls="crm-status-inactive";' .
			'if(s==="active")cls="crm-status-active";' .
			'else if(s==="new")cls="crm-status-new";' .
			'return \'<span class="crm-status-dot \'+cls+\'"></span>\';' .
		'}' .

		'function loadContacts(q){' .
			'var l=document.getElementById("crm-contact-list");if(!l)return;' .

			/*
			 * Show cached contacts while fetching

		 */
			'if(!q&&contactsCache.length){crmRenderContacts(contactsCache);}' .
			'if(!q&&!contactsCache.length)l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"manage_crm_contact",arguments:{action:"list",search:q||"",per_page:20}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var cs=(d&&d.data&&d.data.contacts)?d.data.contacts:[];' .
				'if(!q){contactsCache=cs;lsSet("crm_contacts_cache",cs);}' .
				'crmRenderContacts(cs);' .
			'}).catch(function(){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load contacts.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .

		'function crmRenderContacts(cs){' .
			'var l=document.getElementById("crm-contact-list");if(!l)return;' .
			'var hint=document.getElementById("crm-pull-hint");' .
			'if(!cs.length){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No contacts found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';if(hint)hint.style.display="none";return;}' .
			'l.innerHTML=cs.map(function(c){' .
				'var init=(c.name||"?").charAt(0).toUpperCase();' .
				'var status=c.status||"active";' .
				'var company=c.company?"  ·  "+escH(c.company):"";' .
				'var lastAct=c.last_activity||c.modified||"";' .
				'if(lastAct&&lastAct.length>10)lastAct=lastAct.substring(0,10);' .
				'var sub=escH(c.email||c.phone||"");' .
				'return \'<div class="tma-contact-row">' .
					'<div class="tma-contact-avatar">\'+escH(init)+\'</div>' .
					'<div class="tma-contact-info">' .
						'<div class="tma-contact-name">\'+crmStatusDot(status)+escH(c.name||"' . esc_js( __( 'Unknown', 'mcp-ai-wpoos-pro' ) ) . '")+\'</div>' .
						'<div class="tma-contact-sub">\'+sub+company+\'</div>' .
						'\'+(lastAct?\'<div class="tma-contact-meta">\'+escH(lastAct)+\'</div>\':\'\')+\'' .
					'</div>' .
				'</div>\';' .
			'}).join("");' .
			'if(hint)hint.style.display="block";' .
		'}' .

		/*
		 * Pull-to-refresh for contacts

		*/
		'(function(){' .
			'var startY=0;var el=document.getElementById("tma-tab-contacts");if(!el)return;' .
			'el.addEventListener("touchstart",function(e){startY=e.touches[0].clientY;},{passive:true});' .
			'el.addEventListener("touchend",function(e){' .
				'if(el.scrollTop===0&&e.changedTouches[0].clientY-startY>80){tmaHaptic("light");loadContacts();}' .
			'},{passive:true});' .
		'})();' .

		/*
		 * Debounced search

		*/
		'var searchTimer=null;' .
		'document.getElementById("crm-search-input").addEventListener("input",function(e){' .
			'clearTimeout(searchTimer);var q=e.target.value;' .
			'searchTimer=setTimeout(function(){loadContacts(q);},400);' .
		'});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – Pipeline
			══════════════════════════════════════════════════════════

		*/
		'var STAGE_COLORS={lead:"#2196f3",qualified:"#ff9800",proposal:"#9c27b0",won:"#4caf50",lost:"#e53935"};' .

		'function crmStageKey(label){' .
			'var lc=String(label).toLowerCase();' .
			'if(lc.indexOf("lead")>-1)return "lead";' .
			'if(lc.indexOf("qualif")>-1)return "qualified";' .
			'if(lc.indexOf("propos")>-1)return "proposal";' .
			'if(lc.indexOf("won")>-1||lc.indexOf("closed won")>-1)return "won";' .
			'if(lc.indexOf("lost")>-1||lc.indexOf("closed lost")>-1)return "lost";' .
			'return "lead";' .
		'}' .

		'function loadPipeline(){' .
			'var w=document.getElementById("crm-pipeline");if(!w)return;' .
			'if(pipelineCache.length){crmRenderPipeline(pipelineCache);}' .
			'if(!pipelineCache.length)w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"get_crm_pipeline",arguments:{}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var stages=(d&&d.data&&d.data.stages)?d.data.stages:[];' .
				'if(!stages.length)stages=[{label:"' . esc_js( __( 'Lead', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]},{label:"' . esc_js( __( 'Qualified', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]},{label:"' . esc_js( __( 'Proposal', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]},{label:"' . esc_js( __( 'Won', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]},{label:"' . esc_js( __( 'Lost', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]}];' .
				'pipelineCache=stages;lsSet("crm_pipeline_cache",stages);' .
				'crmRenderPipeline(stages);' .
				'crmBuildDealsFromPipeline(stages);' .
			'}).catch(function(){w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Pipeline unavailable.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .

		'function crmRenderPipeline(stages){' .
			'var w=document.getElementById("crm-pipeline");if(!w)return;' .
			'if(!stages.length){w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No pipeline data.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'w.innerHTML=stages.map(function(s){' .
				'var key=crmStageKey(s.label);var color=STAGE_COLORS[key]||"#607d8b";' .
				'var contacts=s.contacts||[];' .
				'var total=contacts.reduce(function(sum,c){return sum+parseFloat(c.value||0);},0);' .
				'var stats=contacts.length+" · $"+Math.round(total).toLocaleString();' .
				'var cards=contacts.map(function(c){' .
					'var val=parseFloat(c.value||0);' .
					'var detail=c.email||c.phone||"";' .
					'return \'<div class="tma-pipeline-card" onclick="this.classList.toggle(\\\'expanded\\\')">' .
						'<div class="tma-pipeline-card-name">\'+escH(c.name||"' . esc_js( __( 'Contact', 'mcp-ai-wpoos-pro' ) ) . '")+\'</div>' .
						'\'+(val?\'<div class="tma-pipeline-card-value">$\'+escH(val.toLocaleString())+\'</div>\':\'\')+\'' .
						'<div class="tma-pipeline-card-detail">\'+escH(detail)+\'</div>' .
					'</div>\';' .
				'}).join("");' .
				'return \'<div class="tma-pipeline-col">' .
					'<div class="tma-pipeline-header">' .
						'<span class="tma-pipeline-dot" style="background:\'+color+\'"></span>' .
						'<span class="tma-pipeline-label">\'+escH(s.label)+\'</span>' .
						'<span class="tma-pipeline-stats">\'+escH(stats)+\'</span>' .
					'</div>' .
					'\'+cards+\'' .
				'</div>\';' .
			'}).join("");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – Deals
			══════════════════════════════════════════════════════════

		*/
		'function crmBuildDealsFromPipeline(stages){' .
			'var deals=[];' .
			'stages.forEach(function(s){' .
				'var key=crmStageKey(s.label);' .
				'(s.contacts||[]).forEach(function(c){' .
					'deals.push({name:c.name||"' . esc_js( __( 'Deal', 'mcp-ai-wpoos-pro' ) ) . '",value:parseFloat(c.value||0),stage:s.label,stageKey:key,' .
						'last_activity:c.last_activity||c.modified||"",email:c.email||"",phone:c.phone||""});' .
				'});' .
			'});' .
			'deals.sort(function(a,b){return b.value-a.value;});' .
			'dealsCache=deals;lsSet("crm_deals_cache",deals);' .
		'}' .

		'function crmDealStatus(d){' .
			'if(d.stageKey==="won")return "won";' .
			'if(d.stageKey==="lost")return "lost";' .
			'if(d.last_activity){' .
				'var diff=Date.now()-new Date(d.last_activity).getTime();' .
				'if(diff>30*86400000)return "stale";' .
			'}' .
			'return "active";' .
		'}' .

		'function crmStageBadgeClass(key){' .
			'switch(key){case "lead":return "crm-stage-lead";case "qualified":return "crm-stage-qualified";' .
				'case "proposal":return "crm-stage-proposal";case "won":return "crm-stage-won";case "lost":return "crm-stage-lost";' .
				'default:return "crm-stage-lead";}' .
		'}' .

		'function crmRenderDeals(){' .
			'if(!dealsCache.length&&pipelineCache.length)crmBuildDealsFromPipeline(pipelineCache);' .
			'var kpi=document.getElementById("crm-kpi-row");' .
			'var dl=document.getElementById("crm-deals-list");' .
			'var totalVal=dealsCache.reduce(function(s,d){return s+d.value;},0);' .
			'var wonVal=dealsCache.filter(function(d){return d.stageKey==="won";}).reduce(function(s,d){return s+d.value;},0);' .
			'var activeCount=dealsCache.filter(function(d){return d.stageKey!=="won"&&d.stageKey!=="lost";}).length;' .

			/*
			 * KPI cards

		 */
			'if(kpi)kpi.innerHTML=' .
				'\'<div class="crm-kpi-card"><div class="crm-kpi-value">$\'+Math.round(totalVal).toLocaleString()+\'</div><div class="crm-kpi-label">' . esc_js( __( 'Pipeline', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
				'\'<div class="crm-kpi-card"><div class="crm-kpi-value">$\'+Math.round(wonVal).toLocaleString()+\'</div><div class="crm-kpi-label">' . esc_js( __( 'Won', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
				'\'<div class="crm-kpi-card"><div class="crm-kpi-value">\'+activeCount+\'</div><div class="crm-kpi-label">' . esc_js( __( 'Active', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\';' .

			/*
			 * Deal list

		 */
			'if(dl){' .
				'if(!dealsCache.length){dl.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No deals found. Load pipeline first.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';crmHideDonut();return;}' .
				'dl.innerHTML=dealsCache.map(function(d){' .
					'var status=crmDealStatus(d);' .
					'var lastAct=d.last_activity;if(lastAct&&lastAct.length>10)lastAct=lastAct.substring(0,10);' .
					'return \'<div class="crm-deal-card crm-deal-status-\'+status+\'">' .
						'<div class="crm-deal-info">' .
							'<div class="crm-deal-name">\'+escH(d.name)+\'</div>' .
							'<div class="crm-deal-meta"><span class="crm-stage-badge \'+crmStageBadgeClass(d.stageKey)+\'">\'+escH(d.stage)+\'</span>' .
								'\'+(lastAct?" · "+escH(lastAct):"")+\'</div>' .
						'</div>' .
						'<div class="crm-deal-value">$\'+escH(d.value.toLocaleString())+\'</div>' .
					'</div>\';' .
				'}).join("");' .
			'}' .

			/*
			 * Donut chart

		 */
			'crmRenderDonut();' .
		'}' .

		/*
		 * ── Chart.js donut ──

		*/
		'function crmLoadChartJs(cb){' .
			'if(window.Chart){cb();return;}' .
			'if(!CHART_JS_URL){cb();return;}' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;s.onload=cb;s.onerror=cb;document.head.appendChild(s);' .
		'}' .

		'function crmHideDonut(){var w=document.getElementById("crm-donut-wrap");if(w)w.style.display="none";}' .

		'function crmRenderDonut(){' .
			'crmLoadChartJs(function(){' .
				'if(!window.Chart||!dealsCache.length){crmHideDonut();return;}' .
				'var byStage={};' .
				'dealsCache.forEach(function(d){' .
					'var k=d.stage||"Other";' .
					'if(!byStage[k])byStage[k]=0;byStage[k]+=d.value;' .
				'});' .
				'var labels=Object.keys(byStage);var data=labels.map(function(k){return Math.round(byStage[k]*100)/100;});' .
				'var colors=labels.map(function(k){var sk=crmStageKey(k);return STAGE_COLORS[sk]||"#607d8b";});' .
				'if(!labels.length){crmHideDonut();return;}' .
				'var wrap=document.getElementById("crm-donut-wrap");if(wrap)wrap.style.display="block";' .
				'var cv=document.getElementById("crm-donut-chart");if(!cv)return;' .
				'if(donutChartInst){donutChartInst.destroy();}' .
				'donutChartInst=new Chart(cv.getContext("2d"),{type:"doughnut",data:{labels:labels,datasets:[{' .
					'data:data,backgroundColor:colors,borderWidth:1' .
				'}]},options:{responsive:true,plugins:{legend:{position:"bottom",labels:{boxWidth:12,font:{size:11}}}}}});' .
			'});' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 4 – AI Coach
			══════════════════════════════════════════════════════════

		*/
		'function crmChatInit(){' .
			'chatHist=lsGet("crm_chat_hist",[]);' .
			'var m=document.getElementById("crm-chat-messages");if(!m)return;m.innerHTML="";' .
			'if(chatHist.length){' .
				'chatHist.forEach(function(msg){crmAppendMsg(msg.role==="user"?"user":"bot",msg.content,true);});' .
			'}else{' .

				/*
				 * Pre-seed with CRM context

			 */
				'var totalContacts=contactsCache.length;' .
				'var stageCounts=pipelineCache.map(function(s){return escH(s.label)+"("+((s.contacts||[]).length)+")";}).join(", ");' .
				'var totalPipeline=dealsCache.reduce(function(s,d){return s+d.value;},0);' .
				'var ctx="[' . esc_js( __( 'CRM Context', 'mcp-ai-wpoos-pro' ) ) . '] ' .
					esc_js( __( 'Total contacts', 'mcp-ai-wpoos-pro' ) ) . ': "+totalContacts+", ' .
					esc_js( __( 'Pipeline stages', 'mcp-ai-wpoos-pro' ) ) . ': "+stageCounts+". ' .
					esc_js( __( 'Total pipeline value', 'mcp-ai-wpoos-pro' ) ) . ': $"+Math.round(totalPipeline).toLocaleString();' .
				'chatHist.push({role:"system",content:ctx});' .
				'crmAppendMsg("bot","' . esc_js( __( 'Hi! I\'m your CRM coach. I can help you prioritize leads, suggest follow-ups, and analyze your pipeline. What would you like to know?', 'mcp-ai-wpoos-pro' ) ) . '",false);' .
			'}' .
		'}' .

		'function crmAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="crm-msg "+role;' .
			'if(role==="bot"){el.innerHTML=crmRenderMd(text);}' .
			'else{el.textContent=text;}' .
			'var m=document.getElementById("crm-chat-messages");' .
			'if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;' .
		'}' .

		'window.crmChatSend=function(){' .
			'var inp=document.getElementById("crm-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'chatHist.push({role:"user",content:txt});crmAppendMsg("user",txt,false);' .
			'lsSet("crm_chat_hist",chatHist.slice(-50));' .
			'var el=crmAppendMsg("bot","\u2026",false);' .
			'var body={messages:chatHist.filter(function(m){return m.role!=="system";}).slice(-12)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var sys=chatHist.find(function(m){return m.role==="system";});' .
			'if(sys)body.messages.unshift(sys);' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data;' .
				'var rep=(data&&data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)||' .
					'(data&&data.content)||(data&&data.response)||"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.innerHTML=crmRenderMd(rep);chatHist.push({role:"assistant",content:rep});' .
				'lsSet("crm_chat_hist",chatHist.slice(-50));' .
			'})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .

		/*
		 * Enter to send

		*/
		'document.getElementById("crm-chat-input").addEventListener("keydown",function(e){if(e.key==="Enter")crmChatSend();});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 5 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function crmRenderSettings(){' .

			/*
			 * Default pipeline view label

		 */
			'var dvl=document.getElementById("crm-default-view-label");' .
			'if(dvl){var dv=lsGet("crm_default_view","kanban");dvl.textContent=dv==="kanban"?"' . esc_js( __( 'Kanban', 'mcp-ai-wpoos-pro' ) ) . '":"' . esc_js( __( 'List', 'mcp-ai-wpoos-pro' ) ) . '";}' .

			/*
			 * Data summary

		 */
			'var ds=document.getElementById("crm-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Contacts', 'mcp-ai-wpoos-pro' ) ) . ': "+contactsCache.length+", ' .
				esc_js( __( 'Deals', 'mcp-ai-wpoos-pro' ) ) . ': "+dealsCache.length+", ' .
				esc_js( __( 'Chat messages', 'mcp-ai-wpoos-pro' ) ) . ': "+chatHist.length;' .
		'}' .

		'window.crmSyncFromServer=function(){' .
			'tmaHaptic("medium");loadContacts();loadPipeline();' .
		'};' .

		'window.crmClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all local data? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)crmDoClear();});' .
			'}else if(confirm(msg)){crmDoClear();}' .
		'};' .

		'function crmDoClear(){' .
			'try{' .
				'localStorage.removeItem("crm_contacts_cache");' .
				'localStorage.removeItem("crm_pipeline_cache");' .
				'localStorage.removeItem("crm_deals_cache");' .
				'localStorage.removeItem("crm_chat_hist");' .
				'localStorage.removeItem("crm_font_size");' .
				'localStorage.removeItem("crm_compact");' .
				'localStorage.removeItem("crm_default_view");' .
			'}catch(e){}' .
			'contactsCache=[];pipelineCache=[];dealsCache=[];chatHist=[];' .
			'crmRenderSettings();tmaHaptic("notificationSuccess");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Restore state from localStorage

		*/
		'contactsCache=lsGet("crm_contacts_cache",[]);' .
		'pipelineCache=lsGet("crm_pipeline_cache",[]);' .
		'dealsCache=lsGet("crm_deals_cache",[]);' .
		'crmApplyDisplaySettings();' .

		/*
		 * Render cached contacts immediately, then refresh

		*/
		'if(contactsCache.length)crmRenderContacts(contactsCache);' .
		'loadContacts();' .
		'loadPipeline();' .

		/*
		 * Session init for Telegram WebApp (refreshes NONCE/TMA_TOKEN, then reloads data)

		*/
		'crmInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Analytics template – data dashboard with Chart.js visualisations.
 */
class WP_MCP_AI_TMA_Template_Analytics extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'analytics';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Analytics Dashboard', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Real-time site analytics with Chart.js visualisations, KPI cards, and AI-powered insights.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'analytics';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '📊';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#00796b';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name     = esc_html( $ctx['site_name'] );
		$tools_exec    = $ctx['tools_url'] . '/execute';
		$chat_url      = $ctx['chat_url'];
		$validate_url  = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id  = $ctx['assistant_id'];
		$chart_js_url  = isset( $ctx['chart_js_url'] ) ? $ctx['chart_js_url'] : '';
		$analytics_url = isset( $ctx['analytics_url'] ) ? $ctx['analytics_url'] : '';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-analytics-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables ──

		*/
		':root{--tma-btn:#00796b;--tma-accent:#00796b;--tma-secondary-bg:#e0f2f1;' .
			'--an-base:14px;--an-label:12px;--an-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.an-font-small{--an-base:12px;--an-label:10px;--an-heading:14px}' .
		'.an-font-large{--an-base:16px;--an-label:14px;--an-heading:18px}' .
		'.an-compact .an-kpi-grid{gap:6px}' .
		'.an-compact .an-kpi-card{padding:8px}' .
		'.an-compact .an-chart-card{padding:8px}' .
		'.an-compact .an-content-row{padding:8px 10px}' .

		/*
		 * ── Period bar ──

		*/
		'.an-period-bar{display:flex;gap:8px;margin-bottom:14px;padding:0 12px}' .
		'.an-period-btn{flex:1;padding:7px;border:1px solid var(--tma-border);border-radius:8px;background:var(--tma-secondary-bg);' .
			'color:var(--tma-hint);font-size:var(--an-label);font-weight:600;cursor:pointer}' .
		'.an-period-btn.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .

		/*
		 * ── KPI grid ──

		*/
		'.an-kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px;padding:0 12px}' .
		'.an-kpi-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:14px;text-align:center}' .
		'.an-kpi-value{font-size:var(--an-heading);font-weight:700;color:var(--tma-btn);line-height:1.1;margin-bottom:2px;display:flex;align-items:center;justify-content:center;gap:4px}' .
		'.an-kpi-label{font-size:var(--an-label);text-transform:uppercase;letter-spacing:.5px;color:var(--tma-hint)}' .
		'.an-trend-up{color:#2e7d32;font-size:var(--an-label)}' .
		'.an-trend-down{color:#c62828;font-size:var(--an-label)}' .
		'.an-trend-flat{color:var(--tma-hint);font-size:var(--an-label)}' .

		/*
		 * ── Chart card ──

		*/
		'.an-chart-card{margin:0 12px 14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:14px}' .
		'.an-chart-title{font-size:var(--an-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-align:center}' .

		/*
		 * ── Content tab ──

		*/
		'.an-content-row{display:flex;align-items:center;gap:10px;padding:12px 16px;border-bottom:1px solid var(--tma-border)}' .
		'.an-content-info{flex:1;min-width:0}' .
		'.an-content-title{font-size:var(--an-base);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.an-content-meta{font-size:var(--an-label);color:var(--tma-hint);margin-top:2px}' .
		'.an-status-badge{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;border:1px solid transparent;white-space:nowrap}' .
		'.an-status-publish{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.an-status-draft{background:#fff3e0;color:#e65100;border-color:#ffcc80}' .
		'.an-status-other{background:#e3f2fd;color:#1565c0;border-color:#90caf9}' .
		'.an-summary-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;padding:10px 12px}' .
		'.an-summary-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px;text-align:center}' .
		'.an-summary-val{font-size:var(--an-heading);font-weight:700;color:var(--tma-btn)}' .
		'.an-summary-lbl{font-size:var(--an-label);color:var(--tma-hint);margin-top:2px}' .

		/*
		 * ── Traffic tab ──

		*/
		'.an-traffic-kpis{display:flex;gap:8px;padding:10px 12px;overflow-x:auto}' .
		'.an-traffic-kpi{flex:1;min-width:80px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:10px;text-align:center}' .
		'.an-traffic-val{font-size:var(--an-heading);font-weight:700;color:var(--tma-btn)}' .
		'.an-traffic-lbl{font-size:var(--an-label);color:var(--tma-hint);margin-top:2px}' .
		'.an-page-row{display:flex;align-items:center;gap:10px;padding:10px 16px;border-bottom:1px solid var(--tma-border)}' .
		'.an-page-rank{font-size:var(--an-heading);font-weight:700;color:var(--tma-btn);min-width:24px;text-align:center}' .
		'.an-page-info{flex:1;min-width:0}' .
		'.an-page-title{font-size:var(--an-base);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.an-page-views{font-size:var(--an-label);color:var(--tma-hint)}' .

		/*
		 * ── AI Chat ──

		*/
		'.an-chat-container{display:flex;flex-direction:column;height:100%}' .
		'.an-chat-messages{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.an-msg{max-width:85%;padding:10px 14px;border-radius:16px;font-size:var(--an-base);line-height:1.5;word-wrap:break-word}' .
		'.an-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.an-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.an-msg.bot p{margin:0 0 6px}.an-msg.bot p:last-child{margin-bottom:0}' .
		'.an-msg.bot ul,.an-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.an-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.an-chat-input-row{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg)}' .
		'.an-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 14px;font-size:var(--an-base);background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.an-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.an-send-btn:active{opacity:.8}' .

		/*
		 * ── Settings ──

		*/
		'.an-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.an-settings-title{font-size:var(--an-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.an-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.an-settings-row:last-child{border-bottom:none}' .
		'.an-settings-label{font-size:var(--an-base);color:var(--tma-text)}' .
		'.an-settings-value{font-size:var(--an-base);color:var(--tma-hint)}' .
		'.an-font-btns{display:flex;gap:4px}' .
		'.an-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);color:var(--tma-text);font-size:var(--an-label);cursor:pointer}' .
		'.an-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.an-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.an-toggle.on{background:var(--tma-btn)}' .
		'.an-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.an-toggle.on::after{transform:translateX(20px)}' .
		'.an-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);background:var(--tma-bg);color:var(--tma-text);font-size:var(--an-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.an-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.an-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .
		'.an-freshness{font-size:var(--an-label);color:var(--tma-hint);text-align:center;padding:6px 0}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">📊</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="an-header-status">' . esc_html__( 'Analytics', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .

			/*
			 * ── Content panes ──

		 */
			'<div class="tma-content">' .

				/*
				 * Tab 1: Overview

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-overview">' .
					'<div id="an-overview-wrap"><div class="tma-empty">' . esc_html__( 'Loading analytics…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 2: Content

			 */
				'<div class="tma-tab-pane" id="tma-tab-content">' .
					'<div id="an-content-wrap"><div class="tma-empty">' . esc_html__( 'Loading content…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 3: Traffic

			 */
				'<div class="tma-tab-pane" id="tma-tab-traffic">' .
					'<div id="an-traffic-wrap"><div class="tma-empty">' . esc_html__( 'Loading traffic…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 4: AI Insights

			 */
				'<div class="tma-tab-pane" id="tma-tab-insights">' .
					'<div class="an-chat-container">' .
						'<div class="an-chat-messages" id="an-chat-messages"></div>' .
						'<div class="an-chat-input-row">' .
							'<input type="text" class="an-chat-input" id="an-chat-input" placeholder="' . esc_attr__( 'Ask about your analytics…', 'mcp-ai-wpoos-pro' ) . '" />' .
							'<button class="an-send-btn" onclick="anChatSend()">' .
								'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
							'</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 5: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div style="padding-top:12px">' .

					/*
					 * Display section

				 */
					'<div class="an-settings-section">' .
						'<div class="an-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="an-settings-row">' .
							'<span class="an-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="an-font-btns" id="an-font-btns">' .
								'<button data-size="small" onclick="anSetFontSize(\'small\')">' . esc_html__( 'S', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="medium" onclick="anSetFontSize(\'medium\')">' . esc_html__( 'M', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="large" onclick="anSetFontSize(\'large\')">' . esc_html__( 'L', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'</div>' .
						'</div>' .
						'<div class="an-settings-row">' .
							'<span class="an-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="an-toggle" id="an-compact-toggle" onclick="anToggleCompact()"></button>' .
						'</div>' .
						'<div class="an-settings-row">' .
							'<span class="an-settings-label">' . esc_html__( 'Default Period', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="an-settings-value" id="an-default-period-label">—</span>' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="an-settings-section">' .
						'<div class="an-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="an-settings-row">' .
							'<span class="an-settings-label" id="an-data-summary"></span>' .
						'</div>' .
						'<div class="an-freshness" id="an-freshness"></div>' .
						'<button class="an-settings-btn" onclick="anSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="an-settings-btn danger" onclick="anClearData()">' .
							esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .

					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (5 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-overview" onclick="anSwitch(\'overview\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M18 20V10"/><path d="M12 20V4"/><path d="M6 20v-6"/></svg>' .
					'<span>' . esc_html__( 'Overview', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-content" onclick="anSwitch(\'content\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' .
					'<span>' . esc_html__( 'Content', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-traffic" onclick="anSwitch(\'traffic\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 6 13.5 15.5 8.5 10.5 1 18"/><polyline points="17 6 23 6 23 12"/></svg>' .
					'<span>' . esc_html__( 'Traffic', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-insights" onclick="anSwitch(\'insights\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="anSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var ANALYTICS_URL=' . wp_json_encode( $analytics_url ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .
		'var SITE_NAME=' . wp_json_encode( $ctx['site_name'] ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="overview";' .
		'var days=7;' .
		'var overviewCache=null;' .
		'var contentCache=null;' .
		'var trafficCache=null;' .
		'var chatHist=[];' .
		'var overviewChartInst=null;' .
		'var contentChartInst=null;' .
		'var trafficChartInst=null;' .
		'var lastUpdated=null;' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		 * Simple markdown-like renderer for bot messages

		*/
		'function renderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── Session init (matches ecInitSession / crmInitSession pattern) ──

		*/
		'function anInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp)return;' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData)return;' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d)return;if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}anLoadOverview();anLoadContent();})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function anApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("an_font_size","medium");' .
				'shell.classList.remove("an-font-small","an-font-large");' .
				'if(size==="small")shell.classList.add("an-font-small");' .
				'else if(size==="large")shell.classList.add("an-font-large");' .
				'var compact=lsGet("an_compact",false);' .
				'if(compact)shell.classList.add("an-compact");' .
				'else shell.classList.remove("an-compact");' .
				'var btns=document.querySelectorAll("#an-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("an-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
			'}catch(e){}' .
		'}' .
		'window.anSetFontSize=function(s){lsSet("an_font_size",s);tmaHaptic("selectionChanged");anApplyDisplaySettings();};' .
		'window.anToggleCompact=function(){var c=!lsGet("an_compact",false);lsSet("an_compact",c);tmaHaptic("selectionChanged");anApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.anSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'activeTab=tab;' .
			'if(tab==="overview")anLoadOverview();' .
			'if(tab==="content")anLoadContent();' .
			'if(tab==="traffic")anLoadTraffic();' .
			'if(tab==="insights"&&!chatHist.length)anChatInit();' .
			'if(tab==="settings")anRenderSettings();' .
		'};' .

		/*
		 * ── Trend indicator helper ──

		*/
		'function anTrend(cur,prev){' .
			'if(typeof prev==="undefined"||prev===null)return \'<span class="an-trend-flat">—</span>\';' .
			'if(cur>prev)return \'<span class="an-trend-up">▲</span>\';' .
			'if(cur<prev)return \'<span class="an-trend-down">▼</span>\';' .
			'return \'<span class="an-trend-flat">●</span>\';' .
		'}' .

		/*
		 * ── Chart.js lazy loader ──

		*/
		'function anLoadChartJs(cb){' .
			'if(window.Chart){cb();return;}' .
			'if(!CHART_JS_URL){cb();return;}' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;s.onload=cb;s.onerror=cb;document.head.appendChild(s);' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Overview
			══════════════════════════════════════════════════════════

		*/
		'function anLoadOverview(){' .
			'var w=document.getElementById("an-overview-wrap");if(!w)return;' .
			'if(overviewCache){anRenderOverview(overviewCache,w);}' .
			'if(!overviewCache)w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(ANALYTICS_URL+"?days="+days,{headers:tmaToolHeaders()})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'overviewCache=d;lsSet("an_overview_cache",d);' .
				'lastUpdated=new Date();lsSet("an_last_updated",lastUpdated.toISOString());' .
				'anRenderOverview(d,w);' .
				'trafficCache=d;lsSet("an_traffic_cache",d);' .
				'var st=document.getElementById("an-header-status");' .
				'if(st)st.textContent="' . esc_js( __( 'Updated', 'mcp-ai-wpoos-pro' ) ) . ' "+lastUpdated.toLocaleTimeString();' .
			'}).catch(function(){' .
				'if(!overviewCache)w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load analytics.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'});' .
		'}' .

		'function anRenderOverview(d,w){' .
			'var prev=d.previous||{};' .
			'var kpis=[' .
				'{l:"' . esc_js( __( 'Views', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_views||0,p:prev.total_views},' .
				'{l:"' . esc_js( __( 'Posts', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_posts||0,p:prev.total_posts},' .
				'{l:"' . esc_js( __( 'Comments', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_comments||0,p:prev.total_comments},' .
				'{l:"' . esc_js( __( 'Users', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_users||0,p:prev.total_users}' .
			'];' .
			'var pb=[7,14,30,90].map(function(n){return \'<button class="an-period-btn\'+(n===days?" active":"")+\'" onclick="anSetDays(\'+n+\')">\'+(n)+"' . esc_js( __( 'd', 'mcp-ai-wpoos-pro' ) ) . '</button>";}).join("");' .
			'var kh=kpis.map(function(k){return \'<div class="an-kpi-card"><div class="an-kpi-value">\'+escH(k.v)+\' \'+anTrend(k.v,k.p)+\'</div><div class="an-kpi-label">\'+escH(k.l)+\'</div></div>\';}).join("");' .
			'var ts=lastUpdated?\'<div class="an-freshness">' . esc_js( __( 'Last updated:', 'mcp-ai-wpoos-pro' ) ) . ' \'+escH(lastUpdated.toLocaleTimeString())+\'</div>\':\'<div class="an-freshness">' . esc_js( __( 'Cached data', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'w.innerHTML=\'<div class="an-period-bar">\'+pb+\'</div><div class="an-kpi-grid">\'+kh+\'</div><div class="an-chart-card"><div class="an-chart-title">' . esc_js( __( 'Activity Over Time', 'mcp-ai-wpoos-pro' ) ) . '</div><canvas id="an-overview-chart" height="180"></canvas></div>\'+ts;' .
			'anLoadChartJs(function(){' .
				'if(!window.Chart)return;' .
				'var cv=document.getElementById("an-overview-chart");if(!cv)return;' .
				'if(overviewChartInst)overviewChartInst.destroy();' .
				'var lbls=(d.daily||[]).map(function(r){return r.date||"";});' .
				'var vals=(d.daily||[]).map(function(r){return r.views||0;});' .
				'var bc=getComputedStyle(document.documentElement).getPropertyValue("--tma-btn").trim()||"#00796b";' .
				'overviewChartInst=new Chart(cv,{type:"line",data:{labels:lbls,datasets:[{label:"' . esc_js( __( 'Views', 'mcp-ai-wpoos-pro' ) ) . '",data:vals,borderColor:bc,backgroundColor:bc+"22",tension:.3,fill:true,pointRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{maxTicksLimit:6,color:"#999"}},y:{ticks:{color:"#999"},beginAtZero:true}}}});' .
			'});' .
		'}' .

		'window.anSetDays=function(n){days=n;lsSet("an_default_period",n);tmaHaptic("light");anLoadOverview();};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – Content
			══════════════════════════════════════════════════════════

		*/
		'function anLoadContent(){' .
			'var w=document.getElementById("an-content-wrap");if(!w)return;' .
			'if(contentCache){anRenderContent(contentCache,w);}' .
			'if(!contentCache)w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"get_recent_posts",arguments:{per_page:20,post_type:"any"}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var posts=(d&&d.data&&d.data.posts)?d.data.posts:((d&&d.data&&Array.isArray(d.data))?d.data:[]);' .
				'contentCache=posts;lsSet("an_content_cache",posts);' .
				'anRenderContent(posts,w);' .
			'}).catch(function(){' .
				'if(!contentCache)w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load content.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'});' .
		'}' .

		'function anRenderContent(posts,w){' .
			'if(!posts.length){w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No content found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .

			/*
			 * Count by type

		 */
			'var byType={};var published=0;var drafts=0;' .
			'posts.forEach(function(p){' .
				'var t=p.post_type||p.type||"post";' .
				'if(!byType[t])byType[t]=0;byType[t]++;' .
				'var s=p.status||p.post_status||"publish";' .
				'if(s==="publish")published++;else if(s==="draft")drafts++;' .
			'});' .

			/*
			 * Summary

		 */
			'var sumHtml=\'<div class="an-summary-grid">\'+' .
				'\'<div class="an-summary-card"><div class="an-summary-val">\'+posts.length+\'</div><div class="an-summary-lbl">' . esc_js( __( 'Total', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
				'\'<div class="an-summary-card"><div class="an-summary-val">\'+published+\'</div><div class="an-summary-lbl">' . esc_js( __( 'Published', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
				'\'<div class="an-summary-card"><div class="an-summary-val">\'+drafts+\'</div><div class="an-summary-lbl">' . esc_js( __( 'Drafts', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
			'\'</div>\';' .

			/*
			 * Chart

		 */
			'var chartHtml=\'<div class="an-chart-card"><div class="an-chart-title">' . esc_js( __( 'Content by Type', 'mcp-ai-wpoos-pro' ) ) . '</div><canvas id="an-content-chart" height="160"></canvas></div>\';' .

			/*
			 * Top 5 recent posts

		 */
			'var top5=posts.slice(0,5);' .
			'var listHtml=\'<div style="padding:4px 12px 0"><div class="an-chart-title" style="font-weight:600;color:var(--tma-text)">' . esc_js( __( 'Recent Posts', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\';' .
			'listHtml+=top5.map(function(p){' .
				'var s=p.status||p.post_status||"publish";' .
				'var badgeCls="an-status-other";' .
				'if(s==="publish")badgeCls="an-status-publish";' .
				'else if(s==="draft")badgeCls="an-status-draft";' .
				'var dt=p.date||p.post_date||"";if(dt&&dt.length>10)dt=dt.substring(0,10);' .
				'return \'<div class="an-content-row"><div class="an-content-info"><div class="an-content-title">\'+escH(p.title||p.post_title||"' . esc_js( __( 'Untitled', 'mcp-ai-wpoos-pro' ) ) . '")+\'</div><div class="an-content-meta">\'+escH(dt)+\'</div></div><span class="an-status-badge \'+badgeCls+\'">\'+escH(s)+\'</span></div>\';' .
			'}).join("");' .
			'w.innerHTML=sumHtml+chartHtml+listHtml;' .

			/*
			 * Render horizontal bar chart

		 */
			'anLoadChartJs(function(){' .
				'if(!window.Chart)return;' .
				'var cv=document.getElementById("an-content-chart");if(!cv)return;' .
				'if(contentChartInst)contentChartInst.destroy();' .
				'var labels=Object.keys(byType);var data=labels.map(function(k){return byType[k];});' .
				'var colors=["#00796b","#0097a7","#00897b","#26a69a","#4db6ac","#80cbc4"];' .
				'contentChartInst=new Chart(cv,{type:"bar",data:{labels:labels,datasets:[{label:"' . esc_js( __( 'Count', 'mcp-ai-wpoos-pro' ) ) . '",data:data,backgroundColor:colors.slice(0,labels.length),borderWidth:0}]},options:{indexAxis:"y",responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{color:"#999"},beginAtZero:true},y:{ticks:{color:"#999"}}}}});' .
			'});' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – Traffic
			══════════════════════════════════════════════════════════

		*/
		'function anLoadTraffic(){' .
			'var w=document.getElementById("an-traffic-wrap");if(!w)return;' .
			'if(trafficCache){anRenderTraffic(trafficCache,w);return;}' .
			'w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(ANALYTICS_URL+"?days="+days,{headers:tmaToolHeaders()})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'trafficCache=d;lsSet("an_traffic_cache",d);' .
				'anRenderTraffic(d,w);' .
			'}).catch(function(){' .
				'w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load traffic data.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'});' .
		'}' .

		'function anRenderTraffic(d,w){' .
			'var daily=d.daily||[];' .
			'var totalViews=d.total_views||0;' .
			'var avgViews=daily.length?Math.round(totalViews/daily.length):0;' .
			'var peakDay=daily.reduce(function(best,r){return(r.views||0)>(best.views||0)?r:best;},{date:"—",views:0});' .

			/*
			 * KPI row

		 */
			'var kpiHtml=\'<div class="an-traffic-kpis">\'+' .
				'\'<div class="an-traffic-kpi"><div class="an-traffic-val">\'+escH(totalViews)+\'</div><div class="an-traffic-lbl">' . esc_js( __( 'Total Views', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
				'\'<div class="an-traffic-kpi"><div class="an-traffic-val">\'+escH(avgViews)+\'</div><div class="an-traffic-lbl">' . esc_js( __( 'Avg/Day', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
				'\'<div class="an-traffic-kpi"><div class="an-traffic-val">\'+escH(peakDay.views||0)+\'</div><div class="an-traffic-lbl">' . esc_js( __( 'Peak', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'+' .
			'\'</div>\';' .

			/*
			 * Chart

		 */
			'var chartHtml=\'<div class="an-chart-card"><div class="an-chart-title">' . esc_js( __( 'Daily Views', 'mcp-ai-wpoos-pro' ) ) . '</div><canvas id="an-traffic-chart" height="180"></canvas></div>\';' .

			/*
			 * Top pages

		 */
			'var pages=d.top_pages||d.pages||[];' .
			'var pagesHtml="";' .
			'if(pages.length){' .
				'pagesHtml=\'<div style="padding:4px 12px 0"><div class="an-chart-title" style="font-weight:600;color:var(--tma-text)">' . esc_js( __( 'Top Pages', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\';' .
				'pagesHtml+=pages.slice(0,10).map(function(pg,i){' .
					'return \'<div class="an-page-row"><div class="an-page-rank">\'+escH(i+1)+\'</div><div class="an-page-info"><div class="an-page-title">\'+escH(pg.title||pg.path||pg.url||"' . esc_js( __( 'Page', 'mcp-ai-wpoos-pro' ) ) . '")+\'</div><div class="an-page-views">\'+escH(pg.views||0)+" ' . esc_js( __( 'views', 'mcp-ai-wpoos-pro' ) ) . '"+\'</div></div></div>\';' .
				'}).join("");' .
			'}' .
			'w.innerHTML=kpiHtml+chartHtml+pagesHtml;' .

			/*
			 * Render line chart

		 */
			'anLoadChartJs(function(){' .
				'if(!window.Chart)return;' .
				'var cv=document.getElementById("an-traffic-chart");if(!cv)return;' .
				'if(trafficChartInst)trafficChartInst.destroy();' .
				'var lbls=daily.map(function(r){return r.date||"";});' .
				'var vals=daily.map(function(r){return r.views||0;});' .
				'var bc=getComputedStyle(document.documentElement).getPropertyValue("--tma-btn").trim()||"#00796b";' .
				'trafficChartInst=new Chart(cv,{type:"line",data:{labels:lbls,datasets:[{label:"' . esc_js( __( 'Views', 'mcp-ai-wpoos-pro' ) ) . '",data:vals,borderColor:bc,backgroundColor:bc+"22",tension:.3,fill:true,pointRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{maxTicksLimit:6,color:"#999"}},y:{ticks:{color:"#999"},beginAtZero:true}}}});' .
			'});' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 4 – AI Insights
			══════════════════════════════════════════════════════════

		*/
		'function anChatInit(){' .
			'chatHist=lsGet("an_chat_hist",[]);' .
			'var m=document.getElementById("an-chat-messages");if(!m)return;m.innerHTML="";' .
			'if(chatHist.length){' .
				'chatHist.forEach(function(msg){anAppendMsg(msg.role==="user"?"user":"bot",msg.content,true);});' .
			'}else{' .

				/*
				 * Pre-seed with analytics context

			 */
				'var views=overviewCache?overviewCache.total_views||0:0;' .
				'var posts=overviewCache?overviewCache.total_posts||0:0;' .
				'var comments=overviewCache?overviewCache.total_comments||0:0;' .
				'var users=overviewCache?overviewCache.total_users||0:0;' .
				'var ctx="[' . esc_js( __( 'Analytics Context', 'mcp-ai-wpoos-pro' ) ) . '] ' .
					esc_js( __( 'Site', 'mcp-ai-wpoos-pro' ) ) . ': "+SITE_NAME+". ' .
					esc_js( __( 'Period', 'mcp-ai-wpoos-pro' ) ) . ': "+days+"' . esc_js( __( 'd', 'mcp-ai-wpoos-pro' ) ) . '. ' .
					esc_js( __( 'Views', 'mcp-ai-wpoos-pro' ) ) . ': "+views+", ' .
					esc_js( __( 'Posts', 'mcp-ai-wpoos-pro' ) ) . ': "+posts+", ' .
					esc_js( __( 'Comments', 'mcp-ai-wpoos-pro' ) ) . ': "+comments+", ' .
					esc_js( __( 'Users', 'mcp-ai-wpoos-pro' ) ) . ': "+users+".";' .
				'chatHist.push({role:"system",content:ctx});' .
				'anAppendMsg("bot","' . esc_js( __( 'Hi! I\'m your analytics assistant. I can help you understand your traffic trends, content performance, and site metrics. What would you like to know?', 'mcp-ai-wpoos-pro' ) ) . '",false);' .
			'}' .
		'}' .

		'function anAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="an-msg "+role;' .
			'if(role==="bot"){el.innerHTML=renderMd(text);}' .
			'else{el.textContent=text;}' .
			'var m=document.getElementById("an-chat-messages");' .
			'if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;' .
		'}' .

		'window.anChatSend=function(){' .
			'var inp=document.getElementById("an-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'chatHist.push({role:"user",content:txt});anAppendMsg("user",txt,false);' .
			'lsSet("an_chat_hist",chatHist.slice(-50));' .
			'var el=anAppendMsg("bot","\u2026",false);' .
			'var body={messages:chatHist.filter(function(m){return m.role!=="system";}).slice(-12)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var sys=chatHist.find(function(m){return m.role==="system";});' .
			'if(sys)body.messages.unshift(sys);' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data;' .
				'var rep=(data&&data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)||' .
					'(data&&data.content)||(data&&data.response)||"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.innerHTML=renderMd(rep);chatHist.push({role:"assistant",content:rep});' .
				'lsSet("an_chat_hist",chatHist.slice(-50));' .
			'})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .

		/*
		 * Enter to send

		*/
		'document.getElementById("an-chat-input").addEventListener("keydown",function(e){if(e.key==="Enter")anChatSend();});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 5 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function anRenderSettings(){' .

			/*
			 * Default period label

		 */
			'var dpl=document.getElementById("an-default-period-label");' .
			'if(dpl)dpl.textContent=days+"' . esc_js( __( 'd', 'mcp-ai-wpoos-pro' ) ) . '";' .

			/*
			 * Data summary

		 */
			'var ds=document.getElementById("an-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Overview cache', 'mcp-ai-wpoos-pro' ) ) . ': "+(overviewCache?"✓":"✗")+", ' .
				esc_js( __( 'Content', 'mcp-ai-wpoos-pro' ) ) . ': "+(contentCache?contentCache.length:0)+", ' .
				esc_js( __( 'Chat messages', 'mcp-ai-wpoos-pro' ) ) . ': "+chatHist.length;' .

			/*
			 * Freshness

		 */
			'var fr=document.getElementById("an-freshness");' .
			'if(fr){' .
				'if(lastUpdated)fr.textContent="' . esc_js( __( 'Last refreshed:', 'mcp-ai-wpoos-pro' ) ) . ' "+lastUpdated.toLocaleString();' .
				'else fr.textContent="' . esc_js( __( 'No live data loaded yet', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'}' .
		'}' .

		'window.anSyncFromServer=function(){' .
			'tmaHaptic("medium");anLoadOverview();anLoadContent();' .
		'};' .

		'window.anClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all local data? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)anDoClear();});' .
			'}else if(confirm(msg)){anDoClear();}' .
		'};' .

		'function anDoClear(){' .
			'try{' .
				'localStorage.removeItem("an_overview_cache");' .
				'localStorage.removeItem("an_content_cache");' .
				'localStorage.removeItem("an_traffic_cache");' .
				'localStorage.removeItem("an_chat_hist");' .
				'localStorage.removeItem("an_font_size");' .
				'localStorage.removeItem("an_compact");' .
				'localStorage.removeItem("an_default_period");' .
				'localStorage.removeItem("an_last_updated");' .
			'}catch(e){}' .
			'overviewCache=null;contentCache=null;trafficCache=null;chatHist=[];lastUpdated=null;' .
			'anRenderSettings();tmaHaptic("notificationSuccess");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Restore state from localStorage

		*/
		'overviewCache=lsGet("an_overview_cache",null);' .
		'contentCache=lsGet("an_content_cache",null);' .
		'trafficCache=lsGet("an_traffic_cache",null);' .
		'days=lsGet("an_default_period",7);' .
		'var savedTs=lsGet("an_last_updated",null);' .
		'if(savedTs)lastUpdated=new Date(savedTs);' .
		'anApplyDisplaySettings();' .

		/*
		 * Render cached overview immediately, then refresh

		*/
		'if(overviewCache){var ow=document.getElementById("an-overview-wrap");if(ow)anRenderOverview(overviewCache,ow);}' .
		'anLoadOverview();' .
		'anLoadContent();' .

		/*
		 * Session init for Telegram WebApp (refreshes NONCE/TMA_TOKEN, then reloads data)

		*/
		'anInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Booking template – calendar appointment scheduling.
 */
class WP_MCP_AI_TMA_Template_Booking extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'booking';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Calendar Booking', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Appointment scheduling interface with availability calendar, booking form, and confirmation flow.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'calendar_booking';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '📅';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#1565c0';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$chat_url     = $ctx['chat_url'];
		$validate_url = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id = $ctx['assistant_id'];
		$chart_js_url = isset( $ctx['chart_js_url'] ) ? $ctx['chart_js_url'] : '';

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-booking-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables ──

		*/
		':root{--tma-btn:#1565c0;--tma-accent:#1565c0;--tma-secondary-bg:#e8eaf6;' .
			'--bk-base:14px;--bk-label:12px;--bk-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.bk-font-small{--bk-base:12px;--bk-label:10px;--bk-heading:14px}' .
		'.bk-font-large{--bk-base:16px;--bk-label:14px;--bk-heading:18px}' .
		'.bk-compact .bk-apt-card{padding:8px 10px;margin:0 8px 6px}' .
		'.bk-compact .tma-booking-form{padding:10px}' .
		'.bk-compact .tma-calendar{padding:8px}' .

		/*
		 * ── Calendar ──

		*/
		'.tma-calendar{padding:12px}' .
		'.tma-cal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}' .
		'.tma-cal-month{font-size:var(--bk-heading);font-weight:700}' .
		'.tma-cal-nav{background:none;border:none;width:32px;height:32px;border-radius:50%;cursor:pointer;display:flex;align-items:center;justify-content:center;color:var(--tma-text);font-size:18px;-webkit-tap-highlight-color:transparent}' .
		'.tma-cal-nav:active{background:var(--tma-secondary-bg)}' .
		'.tma-cal-grid{display:grid;grid-template-columns:repeat(7,1fr);gap:2px;text-align:center}' .
		'.tma-cal-day-name{font-size:10px;font-weight:600;color:var(--tma-hint);padding:4px 0;text-transform:uppercase}' .
		'.tma-cal-day{height:36px;border-radius:8px;cursor:pointer;display:flex;align-items:center;justify-content:center;font-size:13px;font-weight:500;-webkit-tap-highlight-color:transparent}' .
		'.tma-cal-day:active{opacity:.7}' .
		'.tma-cal-day.today{border:2px solid var(--tma-btn);font-weight:700;color:var(--tma-btn)}' .
		'.tma-cal-day.selected{background:var(--tma-btn);color:#fff;font-weight:700}' .
		'.tma-cal-day.empty,.tma-cal-day.past{color:var(--tma-hint);cursor:default;opacity:.4}' .
		'.tma-slots{padding:0 12px}' .
		'.tma-slots-title{font-size:var(--bk-label);font-weight:600;color:var(--tma-section-header);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}' .
		'.tma-slot-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}' .
		'.tma-slot{padding:10px;border:1px solid var(--tma-border);border-radius:8px;text-align:center;font-size:13px;font-weight:600;cursor:pointer;background:var(--tma-bg);color:var(--tma-text);-webkit-tap-highlight-color:transparent}' .
		'.tma-slot.selected{background:var(--tma-btn);color:#fff;border-color:var(--tma-btn)}' .
		'.tma-slot:active{opacity:.7}' .
		'.tma-booking-form{padding:16px}' .
		'.tma-form-label{font-size:var(--bk-label);font-weight:600;color:var(--tma-hint);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;display:block}' .
		'.tma-confirm-wrap{text-align:center;padding:40px 20px}' .
		'.tma-confirm-icon{font-size:64px;margin-bottom:16px}' .

		/*
		 * ── Appointment cards ──

		*/
		'.bk-apt-card{display:flex;align-items:center;gap:10px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);margin:0 12px 8px;padding:12px 14px}' .
		'.bk-apt-info{flex:1;min-width:0}' .
		'.bk-apt-name{font-size:var(--bk-base);font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.bk-apt-meta{font-size:var(--bk-label);color:var(--tma-hint);margin-top:2px}' .
		'.bk-apt-time{font-size:var(--bk-base);font-weight:700;color:var(--tma-btn);flex-shrink:0}' .

		/*
		 * ── Status badges ──

		*/
		'.bk-status{font-size:10px;padding:2px 8px;border-radius:10px;font-weight:600;border:1px solid transparent;white-space:nowrap;display:inline-block}' .
		'.bk-status-confirmed{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.bk-status-pending{background:#fff3e0;color:#e65100;border-color:#ffcc80}' .
		'.bk-status-cancelled{background:#ffebee;color:#c62828;border-color:#ef9a9a}' .
		'.bk-status-completed{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.bk-status-no-show{background:#f5f5f5;color:#616161;border-color:#e0e0e0}' .

		/*
		 * ── History chart ──

		*/
		'.bk-chart-wrap{margin:0 12px 12px;padding:12px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.bk-chart-title{font-size:var(--bk-label);font-weight:600;color:var(--tma-hint);margin-bottom:6px;text-align:center}' .

		/*
		 * ── AI chat ──

		*/
		'.bk-chat-container{display:flex;flex-direction:column;height:100%}' .
		'.bk-chat-messages{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.bk-msg{max-width:85%;padding:10px 14px;border-radius:16px;font-size:var(--bk-base);line-height:1.5;word-wrap:break-word}' .
		'.bk-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.bk-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.bk-msg.bot p{margin:0 0 6px}.bk-msg.bot p:last-child{margin-bottom:0}' .
		'.bk-msg.bot ul,.bk-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.bk-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.bk-chat-input-row{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg)}' .
		'.bk-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 14px;font-size:var(--bk-base);background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.bk-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.bk-send-btn:active{opacity:.8}' .
		'.bk-suggest-row{display:flex;gap:6px;padding:4px 12px;flex-wrap:wrap}' .
		'.bk-suggest-btn{padding:6px 12px;border:1px solid var(--tma-btn);border-radius:16px;background:var(--tma-bg);color:var(--tma-btn);font-size:var(--bk-label);cursor:pointer;white-space:nowrap}' .
		'.bk-suggest-btn:active{opacity:.7}' .

		/*
		 * ── Settings ──

		*/
		'.bk-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.bk-settings-title{font-size:var(--bk-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.bk-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.bk-settings-row:last-child{border-bottom:none}' .
		'.bk-settings-label{font-size:var(--bk-base);color:var(--tma-text)}' .
		'.bk-settings-value{font-size:var(--bk-base);color:var(--tma-hint)}' .
		'.bk-font-btns{display:flex;gap:4px}' .
		'.bk-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);color:var(--tma-text);font-size:var(--bk-label);cursor:pointer}' .
		'.bk-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.bk-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.bk-toggle.on{background:var(--tma-btn)}' .
		'.bk-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.bk-toggle.on::after{transform:translateX(20px)}' .
		'.bk-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);background:var(--tma-bg);color:var(--tma-text);font-size:var(--bk-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.bk-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.bk-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">📅</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="bk-header-status">' . esc_html__( 'Book Appointment', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .
			'<div class="tma-content">' .

				/*
				 * Tab 1: Book (calendar flow)

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-book">' .
					'<div id="bk-step-calendar">' .
						'<div class="tma-calendar">' .
							'<div class="tma-cal-header">' .
								'<button class="tma-cal-nav" onclick="tmaCalPrev()">&#8249;</button>' .
								'<div class="tma-cal-month" id="tma-cal-month-label"></div>' .
								'<button class="tma-cal-nav" onclick="tmaCalNext()">&#8250;</button>' .
							'</div>' .
							'<div class="tma-cal-grid" id="tma-cal-grid"></div>' .
						'</div>' .
						'<div class="tma-slots" id="tma-slots" style="display:none">' .
							'<div class="tma-slots-title" id="tma-slots-date"></div>' .
							'<div class="tma-slot-grid" id="tma-slot-grid"></div>' .
						'</div>' .
						'<div style="padding:16px;display:none" id="tma-next-wrap">' .
							'<button class="tma-btn tma-btn-primary" style="width:100%" onclick="tmaGoToForm()">' . esc_html__( 'Continue →', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
					'</div>' .
					'<div id="bk-step-form" style="display:none">' .
						'<div class="tma-booking-form">' .
							'<div class="tma-section-title" style="padding:0 0 12px">' . esc_html__( 'Your Details', 'mcp-ai-wpoos-pro' ) . '</div>' .
							'<div style="margin-bottom:12px"><label class="tma-form-label">' . esc_html__( 'Name', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="text" id="tma-book-name" class="tma-input" placeholder="' . esc_attr__( 'Full name', 'mcp-ai-wpoos-pro' ) . '" /></div>' .
							'<div style="margin-bottom:12px"><label class="tma-form-label">' . esc_html__( 'Email', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="email" id="tma-book-email" class="tma-input" placeholder="' . esc_attr__( 'email@example.com', 'mcp-ai-wpoos-pro' ) . '" /></div>' .
							'<div style="margin-bottom:12px"><label class="tma-form-label">' . esc_html__( 'Notes', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<textarea id="tma-book-notes" class="tma-input" rows="3" style="resize:none" placeholder="' . esc_attr__( 'Optional notes…', 'mcp-ai-wpoos-pro' ) . '"></textarea></div>' .
							'<div id="tma-book-error" style="color:#e53935;font-size:13px;margin-bottom:8px;display:none"></div>' .
							'<button class="tma-btn tma-btn-secondary" style="width:100%;margin-bottom:8px" onclick="tmaGoToCalendar()">&#8592; ' . esc_html__( 'Back', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'<button class="tma-btn tma-btn-primary" style="width:100%" onclick="tmaConfirm()">' . esc_html__( 'Confirm Booking', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
					'</div>' .
					'<div id="bk-step-confirm" style="display:none">' .
						'<div class="tma-confirm-wrap">' .
							'<div class="tma-confirm-icon">&#9989;</div>' .
							'<div style="font-size:20px;font-weight:700;margin-bottom:8px">' . esc_html__( 'Booking Confirmed!', 'mcp-ai-wpoos-pro' ) . '</div>' .
							'<div id="tma-confirm-details" style="font-size:14px;color:var(--tma-hint);line-height:1.6"></div>' .
							'<button class="tma-btn tma-btn-primary" style="margin-top:24px" onclick="tmaReset()">' . esc_html__( 'Book Another', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 2: Upcoming

			 */
				'<div class="tma-tab-pane" id="tma-tab-upcoming">' .
					'<div class="tma-section-title" style="padding:4px 12px 0">' . esc_html__( 'Upcoming Appointments', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div id="bk-upcoming-list"><div class="tma-empty">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 3: History

			 */
				'<div class="tma-tab-pane" id="tma-tab-history">' .
					'<div class="bk-chart-wrap" id="bk-chart-wrap" style="display:none">' .
						'<div class="bk-chart-title">' . esc_html__( 'Bookings per Month', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<canvas id="bk-history-chart" height="200"></canvas>' .
					'</div>' .
					'<div class="tma-section-title" style="padding:4px 12px 0">' . esc_html__( 'Past Appointments', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div id="bk-history-list"><div class="tma-empty">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .

				/*
				 * Tab 4: AI Assistant

			 */
				'<div class="tma-tab-pane" id="tma-tab-assistant">' .
					'<div class="bk-chat-container">' .
						'<div class="bk-chat-messages" id="bk-chat-messages"></div>' .
						'<div class="bk-suggest-row" id="bk-suggest-row">' .
							'<button class="bk-suggest-btn" onclick="bkSuggest(this)">' . esc_html__( 'Find available times', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'<button class="bk-suggest-btn" onclick="bkSuggest(this)">' . esc_html__( 'Book an appointment', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'<button class="bk-suggest-btn" onclick="bkSuggest(this)">' . esc_html__( 'Check my schedule', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
						'<div class="bk-chat-input-row">' .
							'<input type="text" class="bk-chat-input" id="bk-chat-input" placeholder="' . esc_attr__( 'Ask about scheduling…', 'mcp-ai-wpoos-pro' ) . '" />' .
							'<button class="bk-send-btn" onclick="bkChatSend()">' .
								'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
							'</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 5: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div style="padding-top:12px">' .

					/*
					 * Display section

				 */
					'<div class="bk-settings-section">' .
						'<div class="bk-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="bk-settings-row">' .
							'<span class="bk-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="bk-font-btns" id="bk-font-btns">' .
								'<button data-size="small" onclick="bkSetFontSize(\'small\')">' . esc_html__( 'S', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="medium" onclick="bkSetFontSize(\'medium\')">' . esc_html__( 'M', 'mcp-ai-wpoos-pro' ) . '</button>' .
								'<button data-size="large" onclick="bkSetFontSize(\'large\')">' . esc_html__( 'L', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'</div>' .
						'</div>' .
						'<div class="bk-settings-row">' .
							'<span class="bk-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="bk-toggle" id="bk-compact-toggle" onclick="bkToggleCompact()"></button>' .
						'</div>' .
					'</div>' .

					/*
					 * Defaults section

				 */
					'<div class="bk-settings-section">' .
						'<div class="bk-settings-title">' . esc_html__( 'Booking Defaults', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="bk-settings-row" style="flex-direction:column;align-items:stretch;gap:4px">' .
							'<label class="bk-settings-label" style="margin-bottom:2px">' . esc_html__( 'Default Name', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="text" class="tma-input" id="bk-default-name" placeholder="' . esc_attr__( 'Your name', 'mcp-ai-wpoos-pro' ) . '" onchange="lsSet(\'bk_default_name\',this.value)" />' .
						'</div>' .
						'<div class="bk-settings-row" style="flex-direction:column;align-items:stretch;gap:4px">' .
							'<label class="bk-settings-label" style="margin-bottom:2px">' . esc_html__( 'Default Email', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="email" class="tma-input" id="bk-default-email" placeholder="' . esc_attr__( 'email@example.com', 'mcp-ai-wpoos-pro' ) . '" onchange="lsSet(\'bk_default_email\',this.value)" />' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="bk-settings-section">' .
						'<div class="bk-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="bk-settings-row">' .
							'<span class="bk-settings-label" id="bk-data-summary"></span>' .
						'</div>' .
						'<button class="bk-settings-btn" onclick="bkSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="bk-settings-btn danger" onclick="bkClearData()">' .
							esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .

					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (5 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-book" onclick="bkSwitch(\'book\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>' .
					'<span>' . esc_html__( 'Book', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-upcoming" onclick="bkSwitch(\'upcoming\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>' .
					'<span>' . esc_html__( 'Upcoming', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-history" onclick="bkSwitch(\'history\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="8" y1="6" x2="21" y2="6"/><line x1="8" y1="12" x2="21" y2="12"/><line x1="8" y1="18" x2="21" y2="18"/><line x1="3" y1="6" x2="3.01" y2="6"/><line x1="3" y1="12" x2="3.01" y2="12"/><line x1="3" y1="18" x2="3.01" y2="18"/></svg>' .
					'<span>' . esc_html__( 'History', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-assistant" onclick="bkSwitch(\'assistant\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="bkSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .
		'var SITE_NAME=' . wp_json_encode( $ctx['site_name'] ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="book";' .
		'var upcomingCache=[];' .
		'var historyCache=[];' .
		'var bookingsLocal=[];' .
		'var chatHist=[];' .
		'var histChartInst=null;' .
		'var today=new Date();var vy=today.getFullYear();var vm=today.getMonth();' .
		'var selDate=null;var selSlot=null;' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		 * Simple markdown-like renderer for bot messages

		*/
		'function bkRenderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── Session init (matches ecommerce / crm pattern) ──

		*/
		'function bkInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp)return;' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData)return;' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d)return;if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function bkApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("bk_font_size","medium");' .
				'shell.classList.remove("bk-font-small","bk-font-large");' .
				'if(size==="small")shell.classList.add("bk-font-small");' .
				'else if(size==="large")shell.classList.add("bk-font-large");' .
				'var compact=lsGet("bk_compact",false);' .
				'if(compact)shell.classList.add("bk-compact");' .
				'else shell.classList.remove("bk-compact");' .
				'var btns=document.querySelectorAll("#bk-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("bk-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
			'}catch(e){}' .
		'}' .
		'window.bkSetFontSize=function(s){lsSet("bk_font_size",s);tmaHaptic("selectionChanged");bkApplyDisplaySettings();};' .
		'window.bkToggleCompact=function(){var c=!lsGet("bk_compact",false);lsSet("bk_compact",c);tmaHaptic("selectionChanged");bkApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.bkSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'activeTab=tab;' .
			'if(tab==="upcoming")bkLoadUpcoming(false);' .
			'if(tab==="history")bkLoadHistory(false);' .
			'if(tab==="assistant"&&!chatHist.length)bkChatInit();' .
			'if(tab==="settings")bkRenderSettings();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Book (Calendar flow)
			══════════════════════════════════════════════════════════

		*/
		'function showStep(id){' .
			'["bk-step-calendar","bk-step-form","bk-step-confirm"].forEach(function(s){' .
				'var el=document.getElementById(s);if(el)el.style.display=s===id?"":"none";' .
			'});' .
		'}' .

		'var DAYS=["' . esc_js( __( 'Su', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Mo', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Tu', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'We', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Th', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Fr', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Sa', 'mcp-ai-wpoos-pro' ) ) . '"];' .
		'var MONTHS=["' . esc_js( __( 'January', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'February', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'March', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'April', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'May', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'June', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'July', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'August', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'September', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'October', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'November', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'December', 'mcp-ai-wpoos-pro' ) ) . '"];' .

		'function renderCal(){' .
			'var lbl=document.getElementById("tma-cal-month-label");if(lbl)lbl.textContent=MONTHS[vm]+" "+vy;' .
			'var grid=document.getElementById("tma-cal-grid");if(!grid)return;' .
			'var dn=DAYS.map(function(d){return \'<div class="tma-cal-day-name">\'+d+\'</div>\';}).join("");' .
			'var fd=new Date(vy,vm,1).getDay();' .
			'var dim=new Date(vy,vm+1,0).getDate();' .
			'var tod=today.toISOString().slice(0,10);' .
			'var emp="";for(var i=0;i<fd;i++)emp+=\'<div class="tma-cal-day empty"></div>\';' .
			'var days="";for(var d=1;d<=dim;d++){' .
				'var ds=vy+"-"+(String(vm+1).padStart(2,"0"))+"-"+(String(d).padStart(2,"0"));' .
				'var isPast=ds<tod;var isTod=ds===tod;var isSel=ds===selDate;' .
				'var cls="tma-cal-day"+(isPast?" past":"")+(isTod?" today":"")+(isSel?" selected":"");' .
				'var oc=isPast?"":"onclick=\"tmaSelDate(\\x27"+ds+"\\x27)\"";' .
				'days+=\'<div class="\'+cls+\'" \'+oc+\'>\'+d+\'</div>\';' .
			'}' .
			'grid.innerHTML=dn+emp+days;' .
		'}' .
		'window.tmaCalPrev=function(){tmaHaptic("light");vm--;if(vm<0){vm=11;vy--;}renderCal();};' .
		'window.tmaCalNext=function(){tmaHaptic("light");vm++;if(vm>11){vm=0;vy++;}renderCal();};' .
		'window.tmaSelDate=function(ds){tmaHaptic("light");selDate=ds;selSlot=null;renderCal();loadSlots(ds);};' .

		'function loadSlots(ds){' .
			'var sw=document.getElementById("tma-slots");var dl=document.getElementById("tma-slots-date");' .
			'var sg=document.getElementById("tma-slot-grid");var nw=document.getElementById("tma-next-wrap");' .
			'if(sw)sw.style.display="block";if(nw)nw.style.display="none";if(dl)dl.textContent=ds;' .
			'if(sg)sg.innerHTML=\'<div style="grid-column:span 3;text-align:center;color:var(--tma-hint);padding:12px">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"get_available_slots",arguments:{date:ds}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var slots=(d&&d.data&&d.data.slots)?d.data.slots:["09:00","10:00","11:00","14:00","15:00","16:00"];' .
				'if(!slots.length){if(sg)sg.innerHTML=\'<div style="grid-column:span 3;text-align:center;color:var(--tma-hint)">' . esc_js( __( 'No availability on this date.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'if(sg)sg.innerHTML=slots.map(function(s){return \'<button class="tma-slot" onclick="tmaSelSlot(\\x27\'+escH(s)+\'\\x27,this)">\'+escH(s)+\'</button>\';}).join("");' .
			'}).catch(function(){' .
				'var def=["09:00","10:00","11:00","14:00","15:00","16:00"];' .
				'if(sg)sg.innerHTML=def.map(function(s){return \'<button class="tma-slot" onclick="tmaSelSlot(\\x27\'+s+\'\\x27,this)">\'+s+\'</button>\';}).join("");' .
			'});' .
		'}' .
		'window.tmaSelSlot=function(s,el){tmaHaptic("light");selSlot=s;document.querySelectorAll(".tma-slot").forEach(function(b){b.classList.remove("selected");});if(el)el.classList.add("selected");var nw=document.getElementById("tma-next-wrap");if(nw)nw.style.display="block";};' .
		'window.tmaGoToForm=function(){' .
			'if(!selDate||!selSlot)return;tmaHaptic("medium");' .
			'var ni=document.getElementById("tma-book-name");var ei=document.getElementById("tma-book-email");' .
			'if(ni&&!ni.value){var dn=lsGet("bk_default_name","");if(dn)ni.value=dn;}' .
			'if(ei&&!ei.value){var de=lsGet("bk_default_email","");if(de)ei.value=de;}' .
			'showStep("bk-step-form");' .
		'};' .
		'window.tmaGoToCalendar=function(){showStep("bk-step-calendar");};' .
		'window.tmaConfirm=function(){' .
			'var name=(document.getElementById("tma-book-name")||{}).value||"";' .
			'var email=(document.getElementById("tma-book-email")||{}).value||"";' .
			'var notes=(document.getElementById("tma-book-notes")||{}).value||"";' .
			'var err=document.getElementById("tma-book-error");' .
			'if(!name.trim()||!email.trim()){if(err){err.style.display="block";err.textContent="' . esc_js( __( 'Name and email are required.', 'mcp-ai-wpoos-pro' ) ) . '";}return;}' .
			'if(err)err.style.display="none";tmaHaptic("medium");' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"create_booking",arguments:{date:selDate,time:selSlot,name:name,email:email,notes:notes}})})' .
			'.then(function(){' .
				'var bk={date:selDate,time:selSlot,name:name,email:email,notes:notes,status:"confirmed",created:new Date().toISOString()};' .
				'bookingsLocal.push(bk);lsSet("bk_bookings",bookingsLocal);' .
			'}).catch(function(){}).finally(function(){' .
				'tmaHaptic("success");' .
				'var det=document.getElementById("tma-confirm-details");' .
				'if(det)det.innerHTML="<strong>"+escH(selDate)+" "+escH(selSlot)+"</strong><br>"+escH(name)+"<br>"+escH(email);' .
				'showStep("bk-step-confirm");' .
			'});' .
		'};' .
		'window.tmaReset=function(){' .
			'selDate=null;selSlot=null;' .
			'var sw=document.getElementById("tma-slots");if(sw)sw.style.display="none";' .
			'var nw=document.getElementById("tma-next-wrap");if(nw)nw.style.display="none";' .
			'var ni=document.getElementById("tma-book-name");if(ni)ni.value="";' .
			'var ei=document.getElementById("tma-book-email");if(ei)ei.value="";' .
			'var nt=document.getElementById("tma-book-notes");if(nt)nt.value="";' .
			'showStep("bk-step-calendar");renderCal();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – Upcoming Appointments
			══════════════════════════════════════════════════════════

		*/
		'function bkStatusCls(s){' .
			'var k=String(s||"").toLowerCase().replace(/[\\s_]+/g,"-");' .
			'if(k==="confirmed")return "bk-status-confirmed";' .
			'if(k==="pending")return "bk-status-pending";' .
			'if(k==="cancelled"||k==="canceled")return "bk-status-cancelled";' .
			'if(k==="completed")return "bk-status-completed";' .
			'if(k==="no-show"||k==="noshow")return "bk-status-no-show";' .
			'return "";' .
		'}' .

		'function bkLoadUpcoming(force){' .
			'var list=document.getElementById("bk-upcoming-list");if(!list)return;' .
			'if(!force&&upcomingCache.length){bkRenderUpcoming(upcomingCache);return;}' .
			'list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"get_appointment_details",arguments:{status:"upcoming",per_page:20}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var apts=(d&&d.data&&d.data.appointments)?d.data.appointments:' .
					'(d&&d.data&&Array.isArray(d.data))?d.data:[];' .
				'if(apts.length){upcomingCache=apts;lsSet("bk_upcoming_cache",apts);bkRenderUpcoming(apts);}' .
				'else{' .
					'var local=bookingsLocal.filter(function(b){return b.date>=today.toISOString().slice(0,10);});' .
					'if(local.length){bkRenderUpcoming(local);}' .
					'else{list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No upcoming appointments', 'mcp-ai-wpoos-pro' ) ) . '</div>\';}' .
				'}' .
			'}).catch(function(){' .
				'var local=bookingsLocal.filter(function(b){return b.date>=today.toISOString().slice(0,10);});' .
				'if(local.length){bkRenderUpcoming(local);}' .
				'else if(upcomingCache.length){bkRenderUpcoming(upcomingCache);}' .
				'else{list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No upcoming appointments', 'mcp-ai-wpoos-pro' ) ) . '</div>\';}' .
			'});' .
		'}' .

		'function bkRenderUpcoming(apts){' .
			'var list=document.getElementById("bk-upcoming-list");if(!list)return;' .
			'if(!apts.length){list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No upcoming appointments', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'list.innerHTML=apts.map(function(a){' .
				'var status=a.status||"pending";' .
				'var name=a.name||a.service||a.title||"' . esc_js( __( 'Appointment', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'return \'<div class="bk-apt-card">\'+' .
					'\'<div class="bk-apt-info">\'+' .
						'\'<div class="bk-apt-name">\'+escH(name)+\'</div>\'+' .
						'\'<div class="bk-apt-meta">\'+escH(a.date||"")+\'</div>\'+' .
					'\'</div>\'+' .
					'\'<div class="bk-apt-time">\'+escH(a.time||"")+\'</div>\'+' .
					'\'<span class="bk-status \'+bkStatusCls(status)+\'">\'+escH(status)+\'</span>\'+' .
				'\'</div>\';' .
			'}).join("");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – History
			══════════════════════════════════════════════════════════

		*/
		'function bkLoadHistory(force){' .
			'var list=document.getElementById("bk-history-list");if(!list)return;' .
			'if(!force&&historyCache.length){bkRenderHistory(historyCache);return;}' .
			'list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"get_appointment_details",arguments:{status:"past",per_page:20}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var apts=(d&&d.data&&d.data.appointments)?d.data.appointments:' .
					'(d&&d.data&&Array.isArray(d.data))?d.data:[];' .
				'if(apts.length){historyCache=apts;lsSet("bk_history_cache",apts);}' .
				'else{' .
					'var local=bookingsLocal.filter(function(b){return b.date<today.toISOString().slice(0,10);});' .
					'if(local.length)apts=local;' .
				'}' .
				'bkRenderHistory(apts);bkRenderHistChart(apts);' .
			'}).catch(function(){' .
				'if(historyCache.length){bkRenderHistory(historyCache);bkRenderHistChart(historyCache);}' .
				'else{list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No past appointments', 'mcp-ai-wpoos-pro' ) ) . '</div>\';}' .
			'});' .
		'}' .

		'function bkRenderHistory(apts){' .
			'var list=document.getElementById("bk-history-list");if(!list)return;' .
			'if(!apts.length){list.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No past appointments', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'list.innerHTML=apts.map(function(a){' .
				'var status=a.status||"completed";' .
				'var name=a.name||a.service||a.title||"' . esc_js( __( 'Appointment', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'return \'<div class="bk-apt-card">\'+' .
					'\'<div class="bk-apt-info">\'+' .
						'\'<div class="bk-apt-name">\'+escH(name)+\'</div>\'+' .
						'\'<div class="bk-apt-meta">\'+escH(a.date||"")+\'</div>\'+' .
					'\'</div>\'+' .
					'\'<div class="bk-apt-time">\'+escH(a.time||"")+\'</div>\'+' .
					'\'<span class="bk-status \'+bkStatusCls(status)+\'">\'+escH(status)+\'</span>\'+' .
				'\'</div>\';' .
			'}).join("");' .
		'}' .

		/*
		 * ── Chart.js loader ──

		*/
		'function bkLoadChartJs(cb){' .
			'if(window.Chart){cb();return;}' .
			'if(!CHART_JS_URL){cb();return;}' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;s.onload=cb;s.onerror=cb;document.head.appendChild(s);' .
		'}' .

		'function bkHideChart(){var w=document.getElementById("bk-chart-wrap");if(w)w.style.display="none";}' .

		'function bkRenderHistChart(apts){' .
			'bkLoadChartJs(function(){' .
				'if(!window.Chart||!apts.length){bkHideChart();return;}' .
				'var monthly={};' .
				'apts.forEach(function(a){' .
					'var d=a.date||a.created||"";if(!d)return;' .
					'var dt=new Date(d);var m;' .
					'if(!isNaN(dt.getTime())){m=dt.getFullYear()+"-"+("0"+(dt.getMonth()+1)).slice(-2);}' .
					'else if(/^\\d{4}-\\d{2}/.test(d)){m=d.substring(0,7);}' .
					'else{return;}' .
					'if(!monthly[m])monthly[m]=0;monthly[m]++;' .
				'});' .
				'var keys=Object.keys(monthly).sort().slice(-6);' .
				'if(!keys.length){bkHideChart();return;}' .
				'var labels=keys;var data=keys.map(function(k){return monthly[k];});' .
				'var wrap=document.getElementById("bk-chart-wrap");if(wrap)wrap.style.display="block";' .
				'var cv=document.getElementById("bk-history-chart");if(!cv)return;' .
				'if(histChartInst){histChartInst.destroy();}' .
				'histChartInst=new Chart(cv.getContext("2d"),{type:"bar",data:{labels:labels,datasets:[{' .
					'label:"' . esc_js( __( 'Bookings', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'data:data,backgroundColor:"rgba(21,101,192,0.6)",borderColor:"#1565c0",' .
					'borderWidth:1,borderRadius:4' .
				'}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{y:{beginAtZero:true,ticks:{stepSize:1}}}}});' .
			'});' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Tab 4 – AI Assistant
			══════════════════════════════════════════════════════════

		*/
		'function bkChatInit(){' .
			'chatHist=lsGet("bk_chat_hist",[]);' .
			'var m=document.getElementById("bk-chat-messages");if(!m)return;m.innerHTML="";' .
			'if(chatHist.length){' .
				'chatHist.forEach(function(msg){bkAppendMsg(msg.role==="user"?"user":"bot",msg.content,true);});' .
			'}else{' .
				'var ctx="[' . esc_js( __( 'Booking Context', 'mcp-ai-wpoos-pro' ) ) . '] ' .
					esc_js( __( 'Site', 'mcp-ai-wpoos-pro' ) ) . ': "+SITE_NAME+". ' .
					esc_js( __( 'Use get_available_slots and create_booking tools to help with scheduling.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'chatHist.push({role:"system",content:ctx});' .
				'bkAppendMsg("bot","' . esc_js( __( 'Hi! I\'m your booking assistant. I can help you find available times, schedule appointments, and manage your bookings. What would you like to do?', 'mcp-ai-wpoos-pro' ) ) . '",false);' .
			'}' .
		'}' .

		'function bkAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="bk-msg "+role;' .
			'if(role==="bot"){el.innerHTML=bkRenderMd(text);}' .
			'else{el.textContent=text;}' .
			'var m=document.getElementById("bk-chat-messages");' .
			'if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;' .
		'}' .

		'window.bkChatSend=function(){' .
			'var inp=document.getElementById("bk-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'chatHist.push({role:"user",content:txt});bkAppendMsg("user",txt,false);' .
			'lsSet("bk_chat_hist",chatHist.slice(-50));' .
			'var sr=document.getElementById("bk-suggest-row");if(sr)sr.style.display="none";' .
			'var el=bkAppendMsg("bot","\u2026",false);' .
			'var body={messages:chatHist.filter(function(m){return m.role!=="system";}).slice(-12)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var sys=chatHist.find(function(m){return m.role==="system";});' .
			'if(sys)body.messages.unshift(sys);' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data;' .
				'var rep=(data&&data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)||' .
					'(data&&data.content)||(data&&data.response)||"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.innerHTML=bkRenderMd(rep);chatHist.push({role:"assistant",content:rep});' .
				'lsSet("bk_chat_hist",chatHist.slice(-50));' .
			'})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .

		'window.bkSuggest=function(btn){' .
			'var inp=document.getElementById("bk-chat-input");' .
			'if(inp){inp.value=btn.textContent;bkChatSend();}' .
		'};' .

		/*
		 * Enter to send

		*/
		'document.getElementById("bk-chat-input").addEventListener("keydown",function(e){if(e.key==="Enter")bkChatSend();});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 5 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function bkRenderSettings(){' .

			/*
			 * Default name/email

		 */
			'var dni=document.getElementById("bk-default-name");if(dni)dni.value=lsGet("bk_default_name","");' .
			'var dei=document.getElementById("bk-default-email");if(dei)dei.value=lsGet("bk_default_email","");' .

			/*
			 * Data summary

		 */
			'var ds=document.getElementById("bk-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Total bookings cached', 'mcp-ai-wpoos-pro' ) ) . ': "+bookingsLocal.length+", ' .
				esc_js( __( 'Upcoming', 'mcp-ai-wpoos-pro' ) ) . ': "+upcomingCache.length+", ' .
				esc_js( __( 'Chat messages', 'mcp-ai-wpoos-pro' ) ) . ': "+chatHist.length;' .
		'}' .

		'window.bkSyncFromServer=function(){' .
			'tmaHaptic("medium");bkLoadUpcoming(true);bkLoadHistory(true);' .
		'};' .

		'window.bkClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all local data? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)bkDoClear();});' .
			'}else if(confirm(msg)){bkDoClear();}' .
		'};' .

		'function bkDoClear(){' .
			'try{' .
				'localStorage.removeItem("bk_bookings");' .
				'localStorage.removeItem("bk_upcoming_cache");' .
				'localStorage.removeItem("bk_history_cache");' .
				'localStorage.removeItem("bk_chat_hist");' .
				'localStorage.removeItem("bk_font_size");' .
				'localStorage.removeItem("bk_compact");' .
				'localStorage.removeItem("bk_default_name");' .
				'localStorage.removeItem("bk_default_email");' .
			'}catch(e){}' .
			'bookingsLocal=[];upcomingCache=[];historyCache=[];chatHist=[];' .
			'bkRenderSettings();tmaHaptic("notificationSuccess");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Restore state from localStorage

		*/
		'bookingsLocal=lsGet("bk_bookings",[]);' .
		'upcomingCache=lsGet("bk_upcoming_cache",[]);' .
		'historyCache=lsGet("bk_history_cache",[]);' .
		'bkApplyDisplaySettings();' .

		/*
		 * Render calendar immediately

		*/
		'renderCal();' .

		/*
		 * Session init for Telegram WebApp (refreshes NONCE/TMA_TOKEN)

		*/
		'bkInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Health & Wellness template – personal health dashboard.
 *
 * Features (industry-standard 2025):
 *  - Daily metric tracking: steps, calories, hydration, sleep, sodium (kidney health), mood.
 *  - Chart.js doughnut (calorie macro breakdown) and line chart (7-day steps).
 *  - Streak counter and achievement badges (gamification layer), including Kidney Friendly badge.
 *  - Weekly goal progress bars with sodium/kidney health goal (≤2300 mg/day).
 *  - AI Wellness Coach powered by the MCP tool execution endpoint.
 *  - Persistent offline-first data via localStorage with optional server sync.
 *
 * @since 1.1.4
 */
class WP_MCP_AI_TMA_Template_Health_Wellness extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'health_wellness';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Health & Wellness', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Personal wellness dashboard with daily metric tracking (steps, sleep, hydration, sodium/kidney health), Chart.js activity charts, streak gamification, weekly goal progress with kidney-friendly targets, and an AI coaching tab.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'health_wellness';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🏃';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#2e7d32';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name       = esc_html( $ctx['site_name'] );
		$tools_exec      = $ctx['tools_url'] . '/execute';
		$chat_url        = $ctx['chat_url'];
		$validate_url    = $ctx['validate_url'] ?? '';
		$assistant_id    = $ctx['assistant_id'];
		$chart_js_url    = $ctx['chart_js_url'];
		$markdown_js_url = $ctx['markdown_js_url'] ?? '';

		// Resolve the member linked to the current WordPress user so the mini app
		// can auto-select without showing the picker on the very first load.
		$server_member_id   = absint( $ctx['member_id'] ?? 0 );
		$server_member_name = '';
		if ( $server_member_id ) {
			$member_post = get_post( $server_member_id );
			if ( $member_post && 'mcp_ai_member' === $member_post->post_type ) {
				$server_member_name = $member_post->post_title;
			} else {
				$server_member_id = 0;
			}
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-health-wellness-template">' .

		/*
		 * ── Styles ───────────────────────────────────────────────────────────

		*/
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * Theme overrides

		*/
		':root{--tma-btn:#2e7d32;--tma-accent:#2e7d32;--tma-secondary-bg:#e8f5e9;}' .

		/*
		 * Streak banner

		*/
		'.tma-hw-streak{display:flex;align-items:center;justify-content:center;gap:8px;' .
			'background:linear-gradient(135deg,#2e7d32,#66bb6a);color:#fff;' .
			'padding:10px 16px;margin:8px 12px;border-radius:var(--tma-radius);font-weight:700}' .
		'.tma-hw-streak-fire{font-size:22px;line-height:1}' .
		'.tma-hw-streak-count{font-size:22px;line-height:1}' .
		'.tma-hw-streak-label{font-size:12px;opacity:.9}' .

		/*
		 * Dashboard scroll wrapper

		*/
		'.tma-hw-wrap{padding:8px 12px;overflow-y:auto;height:calc(100% - 8px)}' .

		/*
		 * KPI grid

		*/
		'.tma-hw-kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:10px 0}' .
		'.tma-hw-kpi{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;display:flex;flex-direction:column;' .
			'align-items:center;gap:4px;position:relative;overflow:hidden}' .
		'.tma-hw-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--hw-kpi-color,var(--tma-btn))}' .
		'.tma-hw-kpi-icon{font-size:22px;line-height:1}' .
		'.tma-hw-kpi-value{font-size:24px;font-weight:700;color:var(--tma-text);line-height:1}' .
		'.tma-hw-kpi-label{font-size:11px;color:var(--tma-hint);text-transform:uppercase;letter-spacing:.4px}' .
		'.tma-hw-kpi-pct{font-size:10px;color:var(--tma-hint);margin-top:2px}' .

		/*
		 * Chart cards

		*/
		'.tma-hw-chart-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:14px;margin:10px 0}' .
		'.tma-hw-chart-title{font-size:13px;font-weight:600;color:var(--tma-text);margin-bottom:10px;' .
			'display:flex;justify-content:space-between;align-items:center}' .
		'.tma-hw-chart-sub{font-size:11px;color:var(--tma-hint);font-weight:400}' .
		'.tma-hw-donut-row{display:flex;align-items:center;gap:14px}' .
		'.tma-hw-donut-wrap{flex-shrink:0;width:110px;height:110px}' .
		'.tma-hw-donut-legend{display:flex;flex-direction:column;gap:5px;font-size:12px;flex:1}' .
		'.tma-hw-legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px}' .

		/*
		 * Log form

		*/
		'.tma-hw-log-wrap{padding:12px}' .
		'.tma-hw-log-section{margin-bottom:16px}' .
		'.tma-hw-log-label{font-size:12px;font-weight:600;color:var(--tma-hint);text-transform:uppercase;' .
			'letter-spacing:.4px;margin-bottom:6px;display:block}' .
		'.tma-hw-counter{display:flex;align-items:center;gap:10px}' .
		'.tma-hw-counter-btn{width:36px;height:36px;border-radius:50%;border:2px solid var(--tma-btn);' .
			'background:none;color:var(--tma-btn);font-size:20px;font-weight:700;cursor:pointer;' .
			'display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent;flex-shrink:0}' .
		'.tma-hw-counter-val{font-size:20px;font-weight:700;color:var(--tma-text);min-width:44px;text-align:center}' .
		'.tma-hw-mood-row{display:flex;gap:8px;flex-wrap:wrap}' .
		'.tma-hw-mood-btn{font-size:24px;border:2px solid transparent;border-radius:8px;' .
			'background:var(--tma-secondary-bg);padding:4px 10px;cursor:pointer;' .
			'-webkit-tap-highlight-color:transparent;transition:border-color .15s}' .
		'.tma-hw-mood-btn.selected{border-color:var(--tma-btn)}' .
		'.tma-hw-log-saved{background:var(--tma-secondary-bg);border:1px solid var(--tma-btn);' .
			'color:var(--tma-btn);border-radius:8px;padding:10px;text-align:center;font-size:13px;' .
			'font-weight:600;display:none;margin-bottom:10px}' .

		/*
		 * Goals

		*/
		'.tma-hw-goals-wrap{padding:12px}' .
		'.tma-hw-goal-item{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;margin-bottom:10px}' .
		'.tma-hw-goal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}' .
		'.tma-hw-goal-name{font-size:14px;font-weight:600;color:var(--tma-text)}' .
		'.tma-hw-goal-pct{font-size:13px;font-weight:700;color:var(--tma-btn)}' .
		'.tma-hw-progress-track{height:8px;background:var(--tma-secondary-bg);border-radius:4px;overflow:hidden}' .
		'.tma-hw-progress-fill{height:100%;border-radius:4px;background:var(--tma-btn);transition:width .4s}' .
		'.tma-hw-goal-detail{font-size:11px;color:var(--tma-hint);margin-top:4px}' .

		/*
		 * Badges

		*/
		'.tma-hw-badges-title{font-size:13px;font-weight:600;color:var(--tma-section-header);' .
			'text-transform:uppercase;letter-spacing:.5px;padding:4px 0 8px}' .
		'.tma-hw-badge-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}' .
		'.tma-hw-badge{display:flex;flex-direction:column;align-items:center;gap:4px;' .
			'padding:10px 12px;border-radius:var(--tma-radius);border:1px solid var(--tma-border);' .
			'background:var(--tma-section-bg);min-width:72px;text-align:center;font-size:10px;color:var(--tma-hint)}' .
		'.tma-hw-badge.earned{border-color:var(--tma-btn);background:var(--tma-secondary-bg);color:var(--tma-btn)}' .
		'.tma-hw-badge-icon{font-size:26px;line-height:1}' .

		/*
		 * AI Coach

		*/
		'.tma-hw-coach-wrap{display:flex;flex-direction:column;height:100%}' .
		'.tma-hw-coach-msgs{flex:1;overflow-y:auto;padding:12px;-webkit-overflow-scrolling:touch;' .
			'display:flex;flex-direction:column}' .
		'.tma-hw-coach-msg{max-width:82%;margin-bottom:10px;padding:10px 12px;border-radius:12px;' .
			'font-size:13px;line-height:1.5;word-break:break-word}' .
		'.tma-hw-coach-msg.bot{background:var(--tma-secondary-bg);color:var(--tma-text);' .
			'border-radius:2px 12px 12px 12px;align-self:flex-start}' .
		'.tma-hw-coach-msg.user{background:var(--tma-btn);color:#fff;' .
			'border-radius:12px 2px 12px 12px;align-self:flex-end}' .
		'.tma-hw-coach-bar{display:flex;gap:8px;padding:10px 12px;' .
			'border-top:1px solid var(--tma-border);background:var(--tma-bg);flex-shrink:0}' .
		'.tma-hw-coach-input{flex:1;padding:9px 12px;border:1px solid var(--tma-border);' .
			'border-radius:20px;background:var(--tma-secondary-bg);color:var(--tma-text);' .
			'font-size:13px;font-family:inherit;outline:none}' .
		'.tma-hw-coach-send{width:36px;height:36px;border-radius:50%;border:none;' .
			'background:var(--tma-btn);color:#fff;font-size:16px;cursor:pointer;flex-shrink:0;' .
			'display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}' .

		/*
		 * Member picker overlay

		*/
		'.tma-member-picker{position:fixed;inset:0;background:var(--tma-bg);z-index:900;' .
			'display:none;flex-direction:column;align-items:center;padding:28px 16px 16px;overflow-y:auto}' .
		'.tma-member-picker-icon{font-size:48px;line-height:1;margin-bottom:12px}' .
		'.tma-member-picker-title{font-size:20px;font-weight:700;color:var(--tma-text);margin-bottom:4px;text-align:center}' .
		'.tma-member-picker-sub{font-size:13px;color:var(--tma-hint);margin-bottom:20px;text-align:center;max-width:280px}' .
		'.tma-member-list{width:100%;max-width:420px}' .
		'.tma-member-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:14px 16px;margin-bottom:10px;cursor:pointer;' .
			'display:flex;align-items:center;gap:12px;-webkit-tap-highlight-color:transparent}' .
		'.tma-member-card:active{background:var(--tma-secondary-bg)}' .
		'.tma-member-card-icon{width:40px;height:40px;border-radius:50%;background:var(--tma-btn);' .
			'color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}' .
		'.tma-member-card-name{font-size:15px;font-weight:600;color:var(--tma-text)}' .
		'.tma-member-card-type{font-size:12px;color:var(--tma-hint);text-transform:capitalize}' .
		'.tma-member-msg{color:var(--tma-hint);font-size:14px;text-align:center;padding:20px 0}' .
		'.tma-header-member{font-size:11px;color:var(--tma-btn);font-weight:600;' .
			'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px}' .

		/*
		 * New-member creation form inside the member picker

		*/
		'.hw-new-member-form{width:100%;max-width:420px;background:var(--tma-section-bg);' .
			'border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:14px;display:none}' .
		'.hw-new-member-form-title{font-size:15px;font-weight:700;color:var(--tma-text);margin-bottom:12px}' .
		'.hw-new-member-type-row{display:flex;gap:8px;margin-bottom:12px}' .
		'.hw-type-btn{flex:1;padding:8px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);' .
			'background:var(--tma-secondary-bg);color:var(--tma-hint);font-size:13px;cursor:pointer;' .
			'-webkit-tap-highlight-color:transparent;text-align:center}' .
		'.hw-type-btn.active{border-color:var(--tma-btn);color:var(--tma-btn);background:var(--tma-secondary-bg);font-weight:600}' .
		'.hw-new-member-err{color:#c62828;font-size:12px;margin-bottom:8px;display:none}' .
		'.hw-new-member-saving{color:var(--tma-hint);font-size:12px;margin-bottom:8px;display:none;text-align:center}' .
		'</style>' .

		/*
		 * ── Member picker overlay ───────────────────────────────────────────

		*/
		'<div class="tma-member-picker" id="tma-member-picker">' .
			'<div class="tma-member-picker-icon">&#128101;</div>' .
			'<div class="tma-member-picker-title">' . esc_html__( 'Select Member', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'<div class="tma-member-picker-sub">' . esc_html__( 'Choose a member to view their health & wellness data.', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'<div class="tma-member-list" id="tma-hw-member-list">' .
				'<div class="tma-member-msg">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'</div>' .

			/*
			 * New member creation form (hidden by default, shown on "+ New Member" tap)

		 */
			'<div class="hw-new-member-form" id="hw-new-member-form">' .
				'<div class="hw-new-member-form-title">&#128100; ' . esc_html__( 'Add New Member', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="hw-new-member-type-row">' .
					'<button class="hw-type-btn active" id="hw-type-btn-person" onclick="hwSetMemberType(\'person\',this)">' . esc_html__( '👤 Person', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="hw-type-btn" id="hw-type-btn-pet" onclick="hwSetMemberType(\'pet\',this)">' . esc_html__( '🐶 Pet', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'</div>' .
				'<div style="margin-bottom:10px">' .
					'<label class="tma-hw-log-label" for="hw-new-member-name">' . esc_html__( 'Name', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<input type="text" id="hw-new-member-name" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'Full name…', 'mcp-ai-wpoos-pro' ) . '" />' .
				'</div>' .
				'<div style="margin-bottom:14px">' .
					'<label class="tma-hw-log-label" for="hw-new-member-dob">' . esc_html__( 'Date of Birth', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<input type="date" id="hw-new-member-dob" class="tma-input" style="width:100%" />' .
				'</div>' .
				'<div class="hw-new-member-err" id="hw-new-member-err"></div>' .
				'<div class="hw-new-member-saving" id="hw-new-member-saving">' . esc_html__( 'Saving…', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div style="display:flex;gap:8px">' .
					'<button class="tma-btn tma-btn-secondary" style="flex:1" onclick="hwHideNewMemberForm()">' . esc_html__( 'Cancel', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="tma-btn tma-btn-primary" style="flex:1" onclick="hwSubmitNewMember()">' . esc_html__( 'Create', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'</div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Shell ───────────────────────────────────────────────────────────

		*/
		'<div class="tma-shell" id="tma-shell">' .

		/*
		 * Header

		*/
		'<header class="tma-header">' .
			'<div class="tma-avatar-wrap"><div class="tma-avatar-initials" style="background:var(--tma-btn)">🏃</div></div>' .
			'<div class="tma-header-info">' .
				'<div class="tma-header-name">' . $site_name . '</div>' .
				'<div class="tma-header-status">' . esc_html__( 'Health & Wellness', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-header-member" id="tma-hw-member-label"></div>' .
			'</div>' .
			'<div class="tma-header-actions">' .
				'<button class="tma-icon-btn" title="' . esc_attr__( 'Switch Member', 'mcp-ai-wpoos-pro' ) . '" onclick="hwSwitchMember()">' .
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
				'</button>' .
				'<button class="tma-icon-btn" title="' . esc_attr__( 'Refresh', 'mcp-ai-wpoos-pro' ) . '" onclick="hwRefresh()">' .
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>' .
				'</button>' .
			'</div>' .
		'</header>' .

		/*
		 * Tab panes

		*/
		'<div class="tma-content">' .

		/*
		 * ── Dashboard ──

		*/
		'<div class="tma-tab-pane tma-active" id="tma-hw-tab-dashboard">' .
			'<div class="tma-hw-wrap">' .
				'<div class="tma-hw-streak" id="tma-hw-streak">' .
					'<span class="tma-hw-streak-fire">🔥</span>' .
					'<span class="tma-hw-streak-count" id="tma-hw-streak-count">0</span>' .
					'<span class="tma-hw-streak-label">' . esc_html__( 'day streak', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</div>' .
				'<div class="tma-hw-kpi-grid" id="tma-hw-kpi-grid">' .
					'<div class="tma-empty">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div id="tma-hw-charts"></div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Log ──

		*/
		'<div class="tma-tab-pane" id="tma-hw-tab-log">' .
			'<div class="tma-hw-log-wrap">' .
				'<div class="tma-section-title" style="padding:0 0 8px">' . esc_html__( 'Log Today\'s Activity', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-hw-log-saved" id="tma-hw-log-saved">&#10003; ' . esc_html__( 'Logged successfully!', 'mcp-ai-wpoos-pro' ) . '</div>' .

				/*
				 * Steps

			 */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128099; ' . esc_html__( 'Steps', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'steps\',-500)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-steps-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'steps\',500)">+</button>' .
						'<input type="number" id="hw-steps-input" class="tma-input" style="flex:1;font-size:14px;padding:8px 10px" min="0" max="99999" placeholder="' . esc_attr__( 'or type', 'mcp-ai-wpoos-pro' ) . '" oninput="hwFromInput(\'steps\',this.value)" />' .
					'</div>' .
				'</div>' .

				/*
				 * Water

			 */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128167; ' . esc_html__( 'Water (glasses)', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'water\',-1)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-water-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'water\',1)">+</button>' .
					'</div>' .
				'</div>' .

				/*
				 * Sleep

			 */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128164; ' . esc_html__( 'Sleep (hours)', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'sleep\',-0.5)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-sleep-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'sleep\',0.5)">+</button>' .
					'</div>' .
				'</div>' .

				/*
				 * Calories

			 */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128293; ' . esc_html__( 'Calories (kcal)', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'calories\',-50)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-calories-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'calories\',50)">+</button>' .
						'<input type="number" id="hw-calories-input" class="tma-input" style="flex:1;font-size:14px;padding:8px 10px" min="0" placeholder="' . esc_attr__( 'or type', 'mcp-ai-wpoos-pro' ) . '" oninput="hwFromInput(\'calories\',this.value)" />' .
					'</div>' .
				'</div>' .

				/*
				 * Sodium – kidney health indicator

			 */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#9889; ' . esc_html__( 'Sodium (mg) — kidney health', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'sodium\',-100)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-sodium-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'sodium\',100)">+</button>' .
						'<input type="number" id="hw-sodium-input" class="tma-input" style="flex:1;font-size:14px;padding:8px 10px" min="0" placeholder="' . esc_attr__( 'or type', 'mcp-ai-wpoos-pro' ) . '" oninput="hwFromInput(\'sodium\',this.value)" />' .
					'</div>' .
				'</div>' .

				/*
				 * Mood

			 */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128578; ' . esc_html__( 'Mood', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-mood-row">' .
						'<button class="tma-hw-mood-btn" data-mood="1" onclick="hwSelMood(1,this)">&#128542;</button>' .
						'<button class="tma-hw-mood-btn" data-mood="2" onclick="hwSelMood(2,this)">&#128533;</button>' .
						'<button class="tma-hw-mood-btn" data-mood="3" onclick="hwSelMood(3,this)">&#128528;</button>' .
						'<button class="tma-hw-mood-btn" data-mood="4" onclick="hwSelMood(4,this)">&#128522;</button>' .
						'<button class="tma-hw-mood-btn" data-mood="5" onclick="hwSelMood(5,this)">&#128516;</button>' .
					'</div>' .
				'</div>' .

				'<button class="tma-btn tma-btn-primary" style="width:100%;margin-top:4px" onclick="hwSaveLog()">' . esc_html__( 'Save Today\'s Log', 'mcp-ai-wpoos-pro' ) . '</button>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Goals ──

		*/
		'<div class="tma-tab-pane" id="tma-hw-tab-goals">' .
			'<div class="tma-hw-goals-wrap">' .
				'<div class="tma-section-title" style="padding:0 0 8px">' . esc_html__( 'Weekly Goals', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div id="tma-hw-goals-list"><div class="tma-empty">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'<div class="tma-hw-badges-title">' . esc_html__( 'Achievements', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-hw-badge-row" id="tma-hw-badges"></div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Coach ──

		*/
		'<div class="tma-tab-pane" id="tma-hw-tab-coach">' .
			'<div class="tma-hw-coach-wrap">' .
				'<div class="tma-hw-coach-msgs" id="tma-hw-coach-msgs">' .
					'<div class="tma-hw-coach-msg bot">' . esc_html__( 'Hi! I\'m your AI Wellness Coach. Ask me anything about your health goals, habits, or for personalised advice. 💪', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="tma-hw-coach-bar">' .
					'<input type="text" id="tma-hw-coach-input" class="tma-hw-coach-input" placeholder="' . esc_attr__( 'Ask your coach…', 'mcp-ai-wpoos-pro' ) . '" onkeydown="if(event.key===\'Enter\')hwCoachSend();" />' .
					'<button class="tma-hw-coach-send" onclick="hwCoachSend()" title="' . esc_attr__( 'Send', 'mcp-ai-wpoos-pro' ) . '">&#10148;</button>' .
				'</div>' .
			'</div>' .
		'</div>' .

		'</div>' . /* .tma-content */

		/*
		 * Bottom navigation

		*/
		'<nav class="tma-nav">' .
			'<button class="tma-nav-btn tma-active" id="tma-hw-nav-dashboard" onclick="hwTab(\'dashboard\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>' .
				esc_html__( 'Dashboard', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="tma-hw-nav-log" onclick="hwTab(\'log\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>' .
				esc_html__( 'Log', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="tma-hw-nav-goals" onclick="hwTab(\'goals\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="10"/><circle cx="12" cy="12" r="6"/><circle cx="12" cy="12" r="2"/></svg>' .
				esc_html__( 'Goals', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="tma-hw-nav-coach" onclick="hwTab(\'coach\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
				esc_html__( 'Coach', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
		'</nav>' .

		'</div>' . /* .tma-shell */

		/*
		 * ── JavaScript ───────────────────────────────────────────────────────

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * Config injected from PHP

		*/
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .
		'var MARKDOWN_JS_URL=' . wp_json_encode( $markdown_js_url ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var MEMBER_ID=0;' .
		'var MEMBER_NAME="";' .
		'var TMA_TOKEN="";' .
		'var coachHist=[];' .

		/*
		 * Server-resolved member for the current WordPress user (0 when unknown)

		*/
		'var SERVER_MEMBER_ID=' . wp_json_encode( $server_member_id ) . ';' .
		'var SERVER_MEMBER_NAME=' . wp_json_encode( $server_member_name ) . ';' .

		/*
		── Markdown renderer loader ──

		*/

		/*
		Load the lightweight TMA markdown renderer on demand so coach

		*/

		/*
		 * Replies are displayed as formatted HTML instead of raw markdown.

		*/
		'function hwLoadMarkdown(cb){' .
			'if(window.wpMcpAiChatMarkdown){cb();return;}' .
			'if(!MARKDOWN_JS_URL){cb();return;}' .
			'var s=document.createElement("script");s.src=MARKDOWN_JS_URL;' .
			's.onload=function(){cb();};s.onerror=function(){cb();};' .
			'document.head.appendChild(s);' .
		'}' .

		/*
		 * ── Render reply using the preferred markdown method ──

		*/
		'function hwRenderReply(el,text){' .
			'if(el&&window.wpMcpAiChatMarkdown&&window.wpMcpAiChatMarkdown.renderMarkdown){' .
				'el.innerHTML=window.wpMcpAiChatMarkdown.renderMarkdown(text);' .
			'}else if(el){' .
				'el.textContent=text;' .
			'}' .
		'}' .

		/*
		── Session init: authenticate via Telegram initData ──

		*/

		/*
		 * Calls /validate so the coach tab chat requests are authenticated

		*/

		/*
		 * Even when Telegram's WebView does not persist the auth cookie.

		*/
		'function hwInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp)return;' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData)return;' .
			'fetch(VALIDATE_URL,{method:"POST",' .
				'headers:{"Content-Type":"application/json"},' .
				'body:JSON.stringify({init_data:initData})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(!d)return;' .
				'if(d.wp_nonce){NONCE=d.wp_nonce;}' .
				'if(d.tma_token){TMA_TOKEN=d.tma_token;}' .

				/*
				 * Re-fetch members with fresh auth if the picker is still open

			 */
				'var picker=document.getElementById("tma-member-picker");' .
				'if(picker&&picker.style.display==="flex"){hwFetchMembers();}' .

				/*
				Re-sync server health metrics now that auth is established.
				 * hwSyncFromServer() is called at page-init before this async
				 * /validate response arrives, so Telegram users whose WP auth
				 * cookie did not persist get a silent auth failure on the first
				 * attempt.  Retry here with the fresh nonce / TMA token.
				 */
				'if(MEMBER_ID)hwSyncFromServer();' .
			'})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Storage helpers ──

		*/
		'var SK_PREFIX="hw_";' .
		'function hwTodayKey(){return new Date().toISOString().slice(0,10);}' .

		/*
		 * Central factory for the daily log object — add new fields here only

		*/
		'function hwDefaultLog(){return{steps:0,water:0,sleep:0,calories:0,sodium:0,mood:0};}' .
		'function hwLoadLog(){try{var v=localStorage.getItem(SK_PREFIX+hwTodayKey());return v?JSON.parse(v):hwDefaultLog();}catch(e){return hwDefaultLog();}}' .
		'function hwStoreLog(l){try{localStorage.setItem(SK_PREFIX+hwTodayKey(),JSON.stringify(l));}catch(e){}}' .
		'function hwLoadHistory(){' .
			'var hist=[];var base=new Date();' .
			'for(var i=6;i>=0;i--){' .
				'var dd=new Date(base);dd.setDate(dd.getDate()-i);' .
				'var dk=dd.toISOString().slice(0,10);' .
				'var raw=null;try{raw=localStorage.getItem(SK_PREFIX+dk);}catch(e){}' .
				'var entry=raw?JSON.parse(raw):hwDefaultLog();' .
				'entry.date=dk;hist.push(entry);' .
			'}return hist;' .
		'}' .
		'function hwCalcStreak(){' .
			'var streak=0;var base=new Date();' .
			'for(var i=0;i<365;i++){' .
				'var dd=new Date(base);dd.setDate(dd.getDate()-i);' .
				'var dk=dd.toISOString().slice(0,10);' .
				'var raw=null;try{raw=localStorage.getItem(SK_PREFIX+dk);}catch(e){}' .
				'if(!raw)break;' .
				'var e2=JSON.parse(raw);' .
				'if(!e2.steps&&!e2.water&&!e2.sleep&&!e2.calories)break;' .
				'streak++;' .
			'}return streak;' .
		'}' .

		/*
		 * State

		*/
		'var LOG=hwLoadLog();' .
		'var lineChartInst=null;var donutChartInst=null;' .

		/*
		 * HTML-escape helper

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .

		/*
		 * ── Member picker ──

		*/
		'function hwLoadSavedMember(){' .
			'try{' .
				'var d=JSON.parse(localStorage.getItem("hw_member_id")||"null");' .
				'if(d&&d.id){MEMBER_ID=d.id;MEMBER_NAME=d.name||"";}' .
			'}catch(e){}' .
		'}' .

		'function hwShowMemberPicker(){' .
			'var p=document.getElementById("tma-member-picker");' .
			'if(p)p.style.display="flex";' .

			/*
			In Telegram WebView the TMA token is obtained asynchronously by
			 * hwInitSession().  Calling hwFetchMembers() before the token is
			 * available sends an unauthenticated request that returns 403.
			 * Skip the immediate fetch when we are inside a Telegram WebApp and
			 * TMA_TOKEN has not yet been set; hwInitSession() will call
			 * hwFetchMembers() once auth is established.  When the token is
			 * already present (e.g. member-switch after first load) or we are
			 * in a regular browser (no Telegram WebApp API), fetch immediately.
			 */
			'if(TMA_TOKEN||!(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initData)){' .
				'hwFetchMembers();' .
			'}' .
		'}' .

		'function hwHideMemberPicker(){' .
			'var p=document.getElementById("tma-member-picker");' .
			'if(p)p.style.display="none";' .
		'}' .

		'window.hwFetchMembers=function(){' .
			'var list=document.getElementById("tma-hw-member-list");' .
			'if(!list)return;' .

			/*
			 * Shared error markup — used in both the failed-response and catch paths

		 */
			'var hwErrHtml=\'<div class="tma-member-msg">' . esc_js( __( 'Could not load members.', 'mcp-ai-wpoos-pro' ) ) . ' <button onclick="hwFetchMembers()" style="margin-left:6px;padding:4px 10px;border:1px solid var(--tma-btn);border-radius:8px;background:none;color:var(--tma-btn);font-size:12px;cursor:pointer">' . esc_js( __( 'Retry', 'mcp-ai-wpoos-pro' ) ) . '</button></div>\';' .
			'list.innerHTML=\'<div class="tma-member-msg">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"list_members",arguments:{per_page:50}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .

				/*
				If the request failed (auth not yet established), keep the Loading… placeholder
				 * so hwInitSession() can re-call hwFetchMembers() once auth succeeds.
				 * Outside Telegram we also add a manual retry button so users are not stuck.
				 */
				'if(!d){if(list)list.innerHTML=hwErrHtml;return;}' .
				'var members=d.result&&d.result.members?d.result.members:[];' .

				/*
				 * Auto-select when exactly one member exists — skip picker entirely

			 */
				'if(members.length===1){hwSelectMember(members[0].id,members[0].name);return;}' .

				/*
				 * Build member cards (may be empty) then always append "+ New Member"

			 */
				'var cards=members.map(function(m){' .
					'var icon=m.type==="pet"?"&#128062;":"&#128100;";' .
					'return\'<div class="tma-member-card" onclick="hwSelectMember(\'+m.id+\',\'+JSON.stringify(m.name)+\')">\'' .
						'+\'<div class="tma-member-card-icon">\'+icon+\'</div>\'' .
						'+\'<div><div class="tma-member-card-name">\'+escH(m.name)+\'</div>\'' .
						'+\'<div class="tma-member-card-type">\'+escH(m.type||"person")+\'</div></div>\'' .
						'+\'</div>\';' .
				'}).join("");' .

				/*
				 * Always show the New Member card at the bottom

			 */
				'var newCard=\'<div class="tma-member-card" onclick="hwShowNewMemberForm()" style="border-style:dashed;opacity:.85">\'' .
					'+\'<div class="tma-member-card-icon" style="background:#78909c">+</div>\'' .
					'+\'<div><div class="tma-member-card-name">' . esc_js( __( '+ New Member', 'mcp-ai-wpoos-pro' ) ) . '</div>\'' .
					'+\'<div class="tma-member-card-type">' . esc_js( __( 'Create a new profile', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'' .
					'+\'</div>\';' .
				'list.innerHTML=cards+newCard;' .
			'})' .
			'.catch(function(){if(list)list.innerHTML=hwErrHtml;});' .
		'};' .

		'window.hwSelectMember=function(id,name){' .
			'MEMBER_ID=id;MEMBER_NAME=name;' .
			'try{localStorage.setItem("hw_member_id",JSON.stringify({id:id,name:name}));}catch(e){}' .
			'hwHideMemberPicker();' .
			'var lbl=document.getElementById("tma-hw-member-label");' .
			'if(lbl)lbl.textContent=name;' .
			'LOG=hwLoadLog();hwSyncUI();hwRefresh();' .
			'hwSyncFromServer();' .
		'};' .

		'window.hwSwitchMember=function(){hwHideNewMemberForm();hwShowMemberPicker();};' .

		/*
		── Server sync: pull stored health metrics into localStorage ──

		*/

		/*
		Calls log_health_metrics get_history and merges server entries

		*/

		/*
		 * Into localStorage so historical data survives device changes.

		*/
		'function hwSyncFromServer(){' .
			'if(!TOOLS_EXEC||!MEMBER_ID)return;' .

			/*
			 * 90 days gives 3 months of history for streak/goal calculations

		 */
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"log_health_metrics",arguments:{action:"get_history",member_id:MEMBER_ID,days_back:90}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(!d||!d.result||!d.result.history||!d.result.history.length)return;' .
				'd.result.history.forEach(function(row){' .
					'var k=row.date;if(!k)return;' .

					/*
					 * Only fill dates that have no local data yet

				 */
					'var existing=null;try{existing=localStorage.getItem(SK_PREFIX+k);}catch(e){}' .
					'if(!existing){' .
						'try{localStorage.setItem(SK_PREFIX+k,JSON.stringify({' .
							'steps:row.steps||0,water:row.water||0,sleep:row.sleep||0,' .
							'calories:row.calories||0,sodium:row.sodium||0,mood:row.mood||0' .
						'}));}catch(e){}' .
					'}' .
				'});' .
				'LOG=hwLoadLog();hwSyncUI();hwRefresh();' .
			'})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── New member form ──

		*/
		'var hwNewMemberType="person";' .

		'window.hwSetMemberType=function(type,btn){' .
			'hwNewMemberType=type;' .
			'var btns=document.querySelectorAll(".hw-type-btn");' .
			'btns.forEach(function(b){b.classList.remove("active");});' .
			'if(btn)btn.classList.add("active");' .
		'};' .

		'window.hwShowNewMemberForm=function(){' .
			'var list=document.getElementById("tma-hw-member-list");' .
			'var form=document.getElementById("hw-new-member-form");' .
			'if(list)list.style.display="none";' .
			'if(form)form.style.display="block";' .

			/*
			 * Reset form state

		 */
			'hwNewMemberType="person";' .
			'var btnPerson=document.getElementById("hw-type-btn-person");' .
			'var btnPet=document.getElementById("hw-type-btn-pet");' .
			'if(btnPerson)btnPerson.classList.add("active");' .
			'if(btnPet)btnPet.classList.remove("active");' .
			'var nameEl=document.getElementById("hw-new-member-name");if(nameEl)nameEl.value="";' .
			'var dobEl=document.getElementById("hw-new-member-dob");if(dobEl)dobEl.value="";' .
			'var errEl=document.getElementById("hw-new-member-err");if(errEl)errEl.style.display="none";' .
			'var savingEl=document.getElementById("hw-new-member-saving");if(savingEl)savingEl.style.display="none";' .
		'};' .

		'window.hwHideNewMemberForm=function(){' .
			'var list=document.getElementById("tma-hw-member-list");' .
			'var form=document.getElementById("hw-new-member-form");' .
			'if(form)form.style.display="none";' .
			'if(list)list.style.display="block";' .
		'};' .

		'window.hwSubmitNewMember=function(){' .
			'var nameEl=document.getElementById("hw-new-member-name");' .
			'var name=(nameEl?nameEl.value:"").trim();' .
			'var errEl=document.getElementById("hw-new-member-err");' .
			'var savingEl=document.getElementById("hw-new-member-saving");' .
			'if(!name){' .
				'if(errEl){errEl.textContent="' . esc_js( __( 'Name is required.', 'mcp-ai-wpoos-pro' ) ) . '";errEl.style.display="block";}' .
				'return;' .
			'}' .
			'if(errEl)errEl.style.display="none";' .
			'if(savingEl)savingEl.style.display="block";' .
			'var args={name:name,type:hwNewMemberType};' .
			'var dobEl=document.getElementById("hw-new-member-dob");' .
			'var dob=(dobEl?dobEl.value:"").trim();if(dob)args.date_of_birth=dob;' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"create_member",arguments:args})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(savingEl)savingEl.style.display="none";' .
				'var memberId=d&&d.result&&d.result.member_id?d.result.member_id:0;' .
				'if(!memberId){' .
					'if(errEl){errEl.textContent="' . esc_js( __( 'Could not create member. Please try again.', 'mcp-ai-wpoos-pro' ) ) . '";errEl.style.display="block";}' .
					'return;' .
				'}' .

				/*
				 * Auto-select the newly created member

			 */
				'hwSelectMember(memberId,name);' .
			'})' .
			'.catch(function(){' .
				'if(savingEl)savingEl.style.display="none";' .
				'if(errEl){errEl.textContent="' . esc_js( __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) ) . '";errEl.style.display="block";}' .
			'});' .
		'};' .

		/*
		 * ── Tab switcher ──

		*/
		'window.hwTab=function(tab,btn){' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(p){p.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(b){b.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-hw-tab-"+tab);if(pane)pane.classList.add("tma-active");' .
			'if(btn)btn.classList.add("tma-active");' .
			'tmaHaptic("light");' .
			'if(tab==="dashboard")hwRefresh();' .
			'if(tab==="goals")hwRenderGoals();' .
		'};' .

		/*
		 * ── Dashboard ──

		*/
		'window.hwRefresh=function(){hwRenderStreak();hwRenderKPIs();hwLoadCharts();};' .

		'function hwRenderStreak(){' .
			'var el=document.getElementById("tma-hw-streak-count");if(el)el.textContent=hwCalcStreak();' .
		'}' .

		'function hwRenderKPIs(){' .
			'var log=hwLoadLog();' .
			'var sodium=log.sodium||0;' .

			/*
			 * Sodium status: kidney-safe goal <2300mg/day; alert >3000mg

		 */
			'var sodiumColor=sodium>3000?"#c62828":sodium>2300?"#e65100":"#0277bd";' .
			'var kpis=[' .
				'{icon:"&#128099;",label:"' . esc_js( __( 'Steps', 'mcp-ai-wpoos-pro' ) ) . '",val:log.steps,goal:10000,unit:"",color:"#2e7d32"},' .
				'{icon:"&#128293;",label:"' . esc_js( __( 'Calories', 'mcp-ai-wpoos-pro' ) ) . '",val:log.calories,goal:2000,unit:"kcal",color:"#e65100"},' .
				'{icon:"&#128167;",label:"' . esc_js( __( 'Water', 'mcp-ai-wpoos-pro' ) ) . '",val:log.water,goal:8,unit:"gl",color:"#0277bd"},' .
				'{icon:"&#128164;",label:"' . esc_js( __( 'Sleep', 'mcp-ai-wpoos-pro' ) ) . '",val:log.sleep,goal:8,unit:"h",color:"#6a1b9a"},' .
				'{icon:"&#9889;",label:"' . esc_js( __( 'Sodium', 'mcp-ai-wpoos-pro' ) ) . '",val:sodium,goal:2300,unit:"mg",color:sodiumColor}' .
			'];' .
			'var g=document.getElementById("tma-hw-kpi-grid");if(!g)return;' .
			'g.innerHTML=kpis.map(function(k){' .
				'var pct=k.goal?Math.min(100,Math.round((k.val/k.goal)*100)):0;' .
				'var unitSpan=k.unit?\'<span style="font-size:12px;font-weight:400;color:var(--tma-hint)"> \'+escH(k.unit)+\'</span>\':\'\'  ;' .
				'return \'<div class="tma-hw-kpi" style="--hw-kpi-color:\'+escH(k.color)+\'">\'+' .
					'\'<div class="tma-hw-kpi-icon">\'+k.icon+\'</div>\'+' .
					'\'<div class="tma-hw-kpi-value">\'+escH(k.val)+unitSpan+\'</div>\'+' .
					'\'<div class="tma-hw-kpi-label">\'+escH(k.label)+\'</div>\'+' .
					'\'<div class="tma-hw-kpi-pct">\'+pct+"% ' . esc_js( __( 'of goal', 'mcp-ai-wpoos-pro' ) ) . '"+\'</div></div>\';' .
			'}).join("");' .
		'}' .

		/*
		 * Chart.js loader

		*/
		'function hwLoadCharts(){' .
			'if(window.Chart){hwRenderCharts();return;}' .
			'if(!CHART_JS_URL){hwRenderCharts();return;}' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;' .
			's.onload=function(){hwRenderCharts();};document.head.appendChild(s);' .
		'}' .

		'function hwRenderCharts(){' .
			'var cw=document.getElementById("tma-hw-charts");if(!cw)return;' .
			'var hist=hwLoadHistory();' .
			'var log=hwLoadLog();' .
			'var labels=hist.map(function(h){return h.date.slice(5);});' .
			'var stepData=hist.map(function(h){return h.steps||0;});' .
			'var accent=getComputedStyle(document.documentElement).getPropertyValue("--tma-btn").trim()||"#2e7d32";' .

			/*
			 * Calorie macro split (demo: 40 % carbs, 30 % protein, 30 % fat)

		 */
			'var carbs=Math.round((log.calories||0)*0.40);' .
			'var protein=Math.round((log.calories||0)*0.30);' .
			'var fat=(log.calories||0)-carbs-protein;' .
			'function legItem(color,label,val){return \'<div style="display:flex;align-items:center;gap:4px;font-size:11px;color:var(--tma-text);margin-bottom:3px"><span class="tma-hw-legend-dot" style="background:\'+color+\'"></span>\'+escH(label)+\' — \'+escH(val)+"kcal</div>";}' .
			'cw.innerHTML=' .
				'\'<div class="tma-hw-chart-card">\'+' .
					'\'<div class="tma-hw-chart-title">' . esc_js( __( 'Calorie Breakdown', 'mcp-ai-wpoos-pro' ) ) . '\'+ \'<span class="tma-hw-chart-sub">' . esc_js( __( 'today', 'mcp-ai-wpoos-pro' ) ) . '</span></div>\'+' .
					'\'<div class="tma-hw-donut-row">\'+' .
						'\'<div class="tma-hw-donut-wrap"><canvas id="tma-hw-donut" width="110" height="110"></canvas></div>\'+' .
						'\'<div class="tma-hw-donut-legend" id="tma-hw-donut-leg"></div>\'+' .
					'\'</div>\'+' .
				'\'</div>\'+' .
				'\'<div class="tma-hw-chart-card">\'+' .
					'\'<div class="tma-hw-chart-title">' . esc_js( __( '7-Day Steps', 'mcp-ai-wpoos-pro' ) ) . '</div>\'+' .
					'\'<canvas id="tma-hw-line" height="140"></canvas>\'+' .
				'\'</div>\';' .
			'var leg=document.getElementById("tma-hw-donut-leg");' .
			'if(leg)leg.innerHTML=legItem("#4caf50","' . esc_js( __( 'Carbs', 'mcp-ai-wpoos-pro' ) ) . '",carbs)+legItem("#ff9800","' . esc_js( __( 'Protein', 'mcp-ai-wpoos-pro' ) ) . '",protein)+legItem("#ef5350","' . esc_js( __( 'Fat', 'mcp-ai-wpoos-pro' ) ) . '",fat);' .
			'if(!window.Chart)return;' .

			/*
			 * Doughnut

		 */
			'var dc=document.getElementById("tma-hw-donut");' .
			'if(dc){if(donutChartInst)donutChartInst.destroy();' .
				'donutChartInst=new Chart(dc,{type:"doughnut",data:{labels:["' . esc_js( __( 'Carbs', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Protein', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Fat', 'mcp-ai-wpoos-pro' ) ) . '"],datasets:[{data:[carbs,protein,fat],backgroundColor:["#4caf50","#ff9800","#ef5350"],borderWidth:0}]},options:{cutout:"72%",plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.label+": "+c.raw+"kcal";}}}},animation:{animateScale:true}}});' .
			'}' .

			/*
			 * Line

		 */
			'var lc=document.getElementById("tma-hw-line");' .
			'if(lc){if(lineChartInst)lineChartInst.destroy();' .
				'lineChartInst=new Chart(lc,{type:"line",data:{labels:labels,datasets:[{label:"' . esc_js( __( 'Steps', 'mcp-ai-wpoos-pro' ) ) . '",data:stepData,borderColor:accent,backgroundColor:accent+"22",tension:.4,fill:true,pointRadius:4,pointBackgroundColor:accent}]},options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.raw+" ' . esc_js( __( 'steps', 'mcp-ai-wpoos-pro' ) ) . '";}}}},' .
					'scales:{x:{ticks:{maxTicksLimit:7,color:"#999",font:{size:10}}},y:{ticks:{color:"#999",font:{size:10}},beginAtZero:true,suggestedMax:12000,grid:{color:"rgba(0,0,0,.06)"}}}' .
				'}});' .
			'}' .
		'}' .

		/*
		 * ── Log tab ──

		*/
		'function hwSyncUI(){' .
			'var sv=document.getElementById("hw-steps-val");if(sv)sv.textContent=LOG.steps;' .
			'var wv=document.getElementById("hw-water-val");if(wv)wv.textContent=LOG.water;' .
			'var slv=document.getElementById("hw-sleep-val");if(slv)slv.textContent=LOG.sleep;' .
			'var cv=document.getElementById("hw-calories-val");if(cv)cv.textContent=LOG.calories;' .
			'var snv=document.getElementById("hw-sodium-val");if(snv)snv.textContent=LOG.sodium||0;' .
		'}' .

		'window.hwCount=function(key,delta){' .
			'LOG[key]=Math.max(0,parseFloat((LOG[key]||0))+delta);' .
			'if(key==="sleep")LOG[key]=Math.round(LOG[key]*10)/10;' .
			'hwSyncUI();tmaHaptic("light");' .
		'};' .

		'window.hwFromInput=function(key,val){' .
			'var n=parseFloat(val);if(!isNaN(n)&&n>=0){LOG[key]=Math.round(n);hwSyncUI();}' .
		'};' .

		'window.hwSelMood=function(v,btn){' .
			'LOG.mood=v;tmaHaptic("light");' .
			'document.querySelectorAll(".tma-hw-mood-btn").forEach(function(b){b.classList.remove("selected");});' .
			'if(btn)btn.classList.add("selected");' .
		'};' .

		'window.hwSaveLog=function(){' .
			'hwStoreLog(LOG);tmaHaptic("success");' .

			/*
			 * Optional server-side persistence when a member is resolved — silent on error

		 */
			'if(TOOLS_EXEC&&MEMBER_ID>0){' .
				'fetch(TOOLS_EXEC,{method:"POST",' .
					'headers:tmaToolHeaders(),' .
					'body:JSON.stringify({slug:"log_health_metrics",arguments:{date:hwTodayKey(),' .
						'member_id:MEMBER_ID,steps:LOG.steps,water:LOG.water,sleep:LOG.sleep,' .
						'calories:LOG.calories,sodium:LOG.sodium||0,mood:LOG.mood}})' .
				'}).catch(function(){});' .
			'}' .
			'var msg=document.getElementById("tma-hw-log-saved");' .
			'if(msg){msg.style.display="block";setTimeout(function(){msg.style.display="none";},2500);}' .
		'};' .

		/*
		 * ── Goals tab ──

		*/
		'window.hwRenderGoals=function(){' .
			'var hist=hwLoadHistory();' .
			'var tot={steps:0,water:0,sleep:0,calories:0,sodium:0};' .
			'hist.forEach(function(h){tot.steps+=h.steps||0;tot.water+=h.water||0;tot.sleep+=h.sleep||0;tot.calories+=h.calories||0;tot.sodium+=h.sodium||0;});' .
			'var goals=[' .
				'{icon:"&#128099;",label:"' . esc_js( __( 'Steps this week', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.steps,goal:70000},' .
				'{icon:"&#128167;",label:"' . esc_js( __( 'Water (glasses)', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.water,goal:56},' .
				'{icon:"&#128164;",label:"' . esc_js( __( 'Sleep total (hrs)', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.sleep,goal:56},' .
				'{icon:"&#128293;",label:"' . esc_js( __( 'Calories (kcal)', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.calories,goal:14000},' .

				/*
				 * Sodium goal: ≤2300 mg/day × 7 = 16100 mg/week — inverse goal (lower is better)

			 */
				'{icon:"&#9889;",label:"' . esc_js( __( 'Sodium (mg) — kidney goal <2300/day', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.sodium,goal:16100,inverse:true}' .
			'];' .
			'var streak=hwCalcStreak();' .
			'var gl=document.getElementById("tma-hw-goals-list");' .
			'if(gl)gl.innerHTML=goals.map(function(g){' .
				'var pct=g.goal?Math.min(100,Math.round((g.val/g.goal)*100)):0;' .

				/*
				 * For inverse goals (lower = better), green means low usage

			 */
				'var fillColor=g.inverse?(pct<=100?"#2e7d32":"#c62828"):"var(--tma-btn)";' .
				'return \'<div class="tma-hw-goal-item">\'+' .
					'\'<div class="tma-hw-goal-header">\'+' .
						'\'<div class="tma-hw-goal-name">\'+g.icon+" "+escH(g.label)+\'</div>\'+' .
						'\'<div class="tma-hw-goal-pct">\'+pct+\'%</div>\'+' .
					'\'</div>\'+' .
					'\'<div class="tma-hw-progress-track"><div class="tma-hw-progress-fill" style="width:\'+pct+\'%;background:\'+fillColor+\'"></div></div>\'+' .
					'\'<div class="tma-hw-goal-detail">\'+escH(g.val)+" / "+escH(g.goal)+(g.inverse?\' ' . esc_js( __( '(stay under goal)', 'mcp-ai-wpoos-pro' ) ) . '\':"") +\'</div></div>\';' .
			'}).join("");' .
			'var log=hwLoadLog();' .
			'var badges=[' .
				'{icon:"&#128293;",label:"' . esc_js( __( '3-Day Streak', 'mcp-ai-wpoos-pro' ) ) . '",earned:streak>=3},' .
				'{icon:"&#127885;",label:"' . esc_js( __( '7-Day Streak', 'mcp-ai-wpoos-pro' ) ) . '",earned:streak>=7},' .
				'{icon:"&#128640;",label:"' . esc_js( __( '10k Steps', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.steps>=10000},' .
				'{icon:"&#128167;",label:"' . esc_js( __( 'Hydration Hero', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.water>=8},' .
				'{icon:"&#128164;",label:"' . esc_js( __( 'Sleep Champion', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.sleep>=8},' .
				'{icon:"&#129506;",label:"' . esc_js( __( 'Kidney Friendly', 'mcp-ai-wpoos-pro' ) ) . '",earned:(log.sodium||0)>0&&(log.sodium||0)<=2300&&log.water>=8},' .
				'{icon:"&#127775;",label:"' . esc_js( __( 'Perfect Day', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.steps>=10000&&log.water>=8&&log.sleep>=8}' .
			'];' .
			'var br=document.getElementById("tma-hw-badges");' .
			'if(br)br.innerHTML=badges.map(function(b){' .
				'return \'<div class="tma-hw-badge\'+( b.earned?" earned":"")+\'">\'+' .
					'\'<div class="tma-hw-badge-icon">\'+b.icon+\'</div><div>\'+escH(b.label)+\'</div></div>\';' .
			'}).join("");' .
		'};' .

		/*
		 * ── AI Coach ──

		*/
		'window.hwCoachSend=function(){' .
			'var inp=document.getElementById("tma-hw-coach-input");if(!inp)return;' .
			'var msg=inp.value.trim();if(!msg)return;inp.value="";tmaHaptic("medium");' .
			'var msgs=document.getElementById("tma-hw-coach-msgs");' .
			'if(msgs){var um=document.createElement("div");um.className="tma-hw-coach-msg user";um.textContent=msg;msgs.appendChild(um);msgs.scrollTop=msgs.scrollHeight;}' .
			'var loadEl=null;' .
			'if(msgs){loadEl=document.createElement("div");loadEl.className="tma-hw-coach-msg bot";loadEl.textContent="' . esc_js( __( '…', 'mcp-ai-wpoos-pro' ) ) . '";msgs.appendChild(loadEl);msgs.scrollTop=msgs.scrollHeight;}' .

			/*
			 * Prepend today's wellness data as context on the first message.

		 */
			'var log=hwLoadLog();' .
			'if(!coachHist.length){' .
				'var moodLabels=["","' . esc_js( __( 'Very Poor', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Poor', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Neutral', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Good', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Excellent', 'mcp-ai-wpoos-pro' ) ) . '"];' .
				'var wellnessCtx="[Today\'s wellness data]"' .
					'+" Steps: "+(log.steps||0)' .
					'+", Water: "+(log.water||0)+" glasses"' .
					'+", Sleep: "+(log.sleep||0)+" hrs"' .
					'+", Calories: "+(log.calories||0)' .
					'+", Sodium: "+(log.sodium||0)+" mg"' .
					'+(log.mood?", Mood: "+(moodLabels[log.mood]||log.mood):"")+".";"' .
				'coachHist.push({role:"user",content:wellnessCtx});' .
				'coachHist.push({role:"assistant",content:"' . esc_js( __( 'I can see your wellness data for today. How can I help you reach your goals?', 'mcp-ai-wpoos-pro' ) ) . '"});' .
			'}' .
			'coachHist.push({role:"user",content:msg});' .
			'var body={messages:coachHist.slice(-20)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var hdrs={"Content-Type":"application/json","X-WP-Nonce":NONCE};' .
			'if(TMA_TOKEN){hdrs["X-WP-MCP-AI-TMA-Token"]=TMA_TOKEN;}' .
			'hwLoadMarkdown(function(){' .
			'fetch(CHAT_URL,{method:"POST",' .
				'headers:hdrs,' .
				'body:JSON.stringify(body)' .
			'})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data?d.data:{};' .
				'var reply=(data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)' .
					'||(data.content)||(data.response)||"' . esc_js( __( 'Keep up the great work! Stay consistent with your goals, hydrate well, and aim for 7-9 hours of sleep. 💪', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'hwRenderReply(loadEl,reply);' .
				'coachHist.push({role:"assistant",content:reply});' .
			'}).catch(function(){' .
				'var tips=["' . esc_js( __( 'Stay hydrated! Aim for 8 glasses of water today. 💧', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'A 20-minute walk can boost your mood and energy. 🚶', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Quality sleep is foundational to health. Aim for 7-9 hours tonight. 😴', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Consistency is the key to long-term wellness. 🌟', 'mcp-ai-wpoos-pro' ) ) . '"];' .
				'hwRenderReply(loadEl,tips[Math.floor(Math.random()*tips.length)]);' .
			'}).finally(function(){if(msgs)msgs.scrollTop=msgs.scrollHeight;});' .
			'});' .
		'};' .

		/*
		── Init ──

		*/

		/*
		Helper: hide the member picker overlay and update the header label.

		*/

		/*
		 * Called from both the localStorage branch and the server-ID branch.

		*/
		'function hwActivateMember(){' .
			'hwHideMemberPicker();' .
			'var lbl=document.getElementById("tma-hw-member-label");' .
			'if(lbl&&MEMBER_NAME)lbl.textContent=MEMBER_NAME;' .
		'}' .

		/*
		Priority order for member selection:

		*/

		/*
			1. localStorage (fastest – avoids any flicker)

		 */

		/*
			2. SERVER_MEMBER_ID (server resolved the WP user's linked member)

		 */

		/*
		 *  3. Show member picker (user must choose or create)

		*/
		'hwLoadSavedMember();' .
		'if(MEMBER_ID){' .
			'hwActivateMember();' .
		'}else if(SERVER_MEMBER_ID){' .

			/*
			Server already knows which member belongs to this user – auto-select
			 * without showing the picker so data loads immediately.
			 */
			'MEMBER_ID=SERVER_MEMBER_ID;MEMBER_NAME=SERVER_MEMBER_NAME;' .
			'try{localStorage.setItem("hw_member_id",JSON.stringify({id:MEMBER_ID,name:MEMBER_NAME}));}catch(e){}' .
			'hwActivateMember();' .
		'}else{' .
			'hwShowMemberPicker();' .
		'}' .
		'hwInitSession();LOG=hwLoadLog();hwSyncUI();hwRefresh();' .

		/*
		 * Restore saved mood selection

		*/
		'if(LOG.mood){var mb=document.querySelector(".tma-hw-mood-btn[data-mood=\'"+LOG.mood+"\']");if(mb)mb.classList.add("selected");}' .
		'if(MEMBER_ID)hwSyncFromServer();' .
		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/**
 * Medical Vitals Tracking template – treatment plan monitoring.
 *
 * Features:
 *  - Dashboard: latest vital readings (BP, HR, SpO2, temperature, glucose)
 *    and kidney health indicators (eGFR/CKD stage, creatinine, BUN, K+, Na+,
 *    phosphorus, albumin) with colour-coded status indicators.
 *  - Log tab: record vital measurements and kidney lab values with optional notes.
 *  - Trends tab: Chart.js 7-day line charts for vitals and kidney markers with reference bands.
 *  - Dosage tab: medication tracker with dose scheduling and adherence logging.
 *  - Doctor tab: AI assistant connected to the configured assistant via standard chat endpoint.
 *  - Persistent offline-first data via localStorage with optional server sync.
 *
 * @since 1.1.5
 */
class WP_MCP_AI_TMA_Template_Medical_Vitals extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'medical_vitals';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Medical Vitals Tracking', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Treatment plan monitoring with vital-sign tracking (BP, HR, SpO2, temperature, glucose), kidney health indicators (eGFR, creatinine, BUN, potassium, sodium, phosphorus), 7-day trend charts, medication dosage scheduling, and an AI doctor assistant.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'health_wellness';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🩺';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#1565c0';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name       = esc_html( $ctx['site_name'] );
		$tools_exec      = $ctx['tools_url'] . '/execute';
		$chat_url        = $ctx['chat_url'];
		$validate_url    = $ctx['validate_url'] ?? '';
		$assistant_id    = $ctx['assistant_id'];
		$chart_js_url    = $ctx['chart_js_url'];
		$markdown_js_url = $ctx['markdown_js_url'] ?? '';

		// Resolve the member linked to the current WordPress user so the mini app
		// can auto-select without showing the picker on the very first load.
		$server_member_id   = absint( $ctx['member_id'] ?? 0 );
		$server_member_name = '';
		if ( $server_member_id ) {
			$member_post = get_post( $server_member_id );
			if ( $member_post && 'mcp_ai_member' === $member_post->post_type ) {
				$server_member_name = $member_post->post_title;
			} else {
				$server_member_id = 0;
			}
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-medical-vitals-template">' .

		/*
		 * ── Styles ───────────────────────────────────────────────────────────

		*/
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * Theme overrides

		*/
		':root{--tma-btn:#1565c0;--tma-accent:#1565c0;--tma-secondary-bg:#e3f2fd;}' .
		'.mv-normal{color:#2e7d32}.mv-warning{color:#e65100}.mv-alert{color:#c62828}' .
		'.mv-badge-normal{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.mv-badge-warning{background:#fff3e0;color:#e65100;border-color:#ffcc80}' .
		'.mv-badge-alert{background:#ffebee;color:#c62828;border-color:#ef9a9a}' .

		/*
		 * Vitals KPI grid

		*/
		'.mv-kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px 12px}' .
		'.mv-kpi{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;position:relative;overflow:hidden}' .
		'.mv-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--mv-kpi-color,var(--tma-btn))}' .
		'.mv-kpi-icon{font-size:18px;margin-bottom:4px}' .
		'.mv-kpi-label{font-size:10px;color:var(--tma-hint);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px}' .
		'.mv-kpi-val{font-size:20px;font-weight:700;line-height:1.1;color:var(--tma-text)}' .
		'.mv-kpi-unit{font-size:11px;color:var(--tma-hint);font-weight:400}' .
		'.mv-kpi-status{font-size:10px;font-weight:600;margin-top:4px;padding:2px 6px;border-radius:20px;border:1px solid;display:inline-block}' .
		'.mv-kpi-time{font-size:10px;color:var(--tma-hint);margin-top:3px}' .

		/*
		 * Kidney section

		*/
		'.mv-lab-divider{display:flex;align-items:center;gap:8px;margin:12px 0 10px;' .
			'font-size:11px;font-weight:700;color:var(--tma-hint);text-transform:uppercase;letter-spacing:.6px}' .
		'.mv-lab-divider::before,.mv-lab-divider::after{content:"";flex:1;height:1px;background:var(--tma-border)}' .
		'.mv-ckd-stage{display:inline-flex;align-items:center;gap:6px;padding:6px 10px;' .
			'border-radius:var(--tma-radius);border:1px solid;font-size:12px;font-weight:600;margin-top:4px}' .
		'.mv-kidney-section-title{font-size:12px;font-weight:700;color:var(--tma-hint);' .
			'text-transform:uppercase;letter-spacing:.5px;padding:8px 12px 0;display:flex;align-items:center;gap:6px}' .
		'.mv-banner{margin:8px 12px;background:linear-gradient(135deg,#1565c0,#42a5f5);' .
			'color:#fff;padding:10px 14px;border-radius:var(--tma-radius);' .
			'display:flex;justify-content:space-between;align-items:center}' .
		'.mv-banner-title{font-size:13px;font-weight:600}' .
		'.mv-banner-sub{font-size:11px;opacity:.85;margin-top:2px}' .
		'.mv-banner-icon{font-size:28px}' .

		/*
		 * Scroll wrapper

		*/
		'.mv-scroll{overflow-y:auto;height:100%;-webkit-overflow-scrolling:touch}' .

		/*
		 * Chart cards

		*/
		'.mv-chart-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:14px;margin:0 12px 10px}' .
		'.mv-chart-title{font-size:13px;font-weight:600;margin-bottom:4px;display:flex;justify-content:space-between;align-items:center}' .
		'.mv-chart-range{font-size:10px;color:var(--tma-hint);font-weight:400}' .

		/*
		 * Log form

		*/
		'.mv-log-wrap{padding:12px}' .
		'.mv-log-section{margin-bottom:14px}' .
		'.mv-log-label{font-size:12px;font-weight:600;color:var(--tma-hint);text-transform:uppercase;' .
			'letter-spacing:.4px;margin-bottom:6px;display:block}' .
		'.mv-bp-row{display:flex;align-items:center;gap:8px}' .
		'.mv-bp-sep{font-size:18px;font-weight:700;color:var(--tma-hint);flex-shrink:0}' .
		'.mv-log-saved{background:var(--tma-secondary-bg);border:1px solid var(--tma-btn);' .
			'color:var(--tma-btn);border-radius:8px;padding:10px;text-align:center;font-size:13px;' .
			'font-weight:600;display:none;margin-bottom:10px}' .

		/*
		 * Dosage list

		*/
		'.mv-dosage-wrap{padding:12px}' .
		'.mv-med-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;margin-bottom:10px}' .
		'.mv-med-header{display:flex;align-items:center;gap:8px;margin-bottom:6px}' .
		'.mv-med-icon{font-size:20px;flex-shrink:0}' .
		'.mv-med-name{font-size:14px;font-weight:600;flex:1}' .
		'.mv-med-dose{font-size:12px;color:var(--tma-hint)}' .
		'.mv-med-schedule{font-size:12px;color:var(--tma-hint);margin-bottom:8px}' .
		'.mv-med-actions{display:flex;gap:8px}' .
		'.mv-med-btn{font-size:12px;padding:5px 12px;border-radius:20px;border:1px solid var(--tma-btn);' .
			'background:none;color:var(--tma-btn);cursor:pointer;-webkit-tap-highlight-color:transparent}' .
		'.mv-med-btn.taken{background:var(--tma-btn);color:#fff}' .
		'.mv-add-med-form{background:var(--tma-secondary-bg);border-radius:var(--tma-radius);padding:12px;margin-top:4px;display:none}' .
		'.mv-empty{text-align:center;color:var(--tma-hint);font-size:13px;padding:24px 0}' .

		/*
		 * Doctor (AI) chat

		*/
		'.mv-doctor-wrap{display:flex;flex-direction:column;height:100%}' .
		'.mv-doctor-msgs{flex:1;overflow-y:auto;padding:12px;-webkit-overflow-scrolling:touch;display:flex;flex-direction:column}' .
		'.mv-doctor-msg{max-width:84%;margin-bottom:10px;padding:10px 12px;border-radius:12px;' .
			'font-size:13px;line-height:1.5;word-break:break-word}' .
		'.mv-doctor-msg.bot{background:var(--tma-secondary-bg);color:var(--tma-text);' .
			'border-radius:2px 12px 12px 12px;align-self:flex-start}' .
		'.mv-doctor-msg.user{background:var(--tma-btn);color:#fff;' .
			'border-radius:12px 2px 12px 12px;align-self:flex-end}' .
		'.mv-doctor-bar{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg);flex-shrink:0}' .
		'.mv-doctor-input{flex:1;padding:9px 12px;border:1px solid var(--tma-border);' .
			'border-radius:20px;background:var(--tma-secondary-bg);color:var(--tma-text);' .
			'font-size:13px;font-family:inherit;outline:none}' .
		'.mv-doctor-send{width:36px;height:36px;border-radius:50%;border:none;' .
			'background:var(--tma-btn);color:#fff;font-size:16px;cursor:pointer;flex-shrink:0;' .
			'display:flex;align-items:center;justify-content:center;-webkit-tap-highlight-color:transparent}' .

		/*
		 * Member picker overlay — shared styles (duplicated from hw template so each template is self-contained)

		*/
		'.tma-member-picker{position:fixed;inset:0;background:var(--tma-bg);z-index:900;' .
			'display:none;flex-direction:column;align-items:center;padding:28px 16px 16px;overflow-y:auto}' .
		'.tma-member-picker-icon{font-size:48px;line-height:1;margin-bottom:12px}' .
		'.tma-member-picker-title{font-size:20px;font-weight:700;color:var(--tma-text);margin-bottom:4px;text-align:center}' .
		'.tma-member-picker-sub{font-size:13px;color:var(--tma-hint);margin-bottom:20px;text-align:center;max-width:280px}' .
		'.tma-member-list{width:100%;max-width:420px}' .
		'.tma-member-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:14px 16px;margin-bottom:10px;cursor:pointer;' .
			'display:flex;align-items:center;gap:12px;-webkit-tap-highlight-color:transparent}' .
		'.tma-member-card:active{background:var(--tma-secondary-bg)}' .
		'.tma-member-card-icon{width:40px;height:40px;border-radius:50%;background:var(--tma-btn);' .
			'color:#fff;display:flex;align-items:center;justify-content:center;font-size:18px;flex-shrink:0}' .
		'.tma-member-card-name{font-size:15px;font-weight:600;color:var(--tma-text)}' .
		'.tma-member-card-type{font-size:12px;color:var(--tma-hint);text-transform:capitalize}' .
		'.tma-member-msg{color:var(--tma-hint);font-size:14px;text-align:center;padding:20px 0}' .
		'.tma-header-member{font-size:11px;color:var(--tma-btn);font-weight:600;' .
			'overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:140px}' .

		/*
		 * New-member creation form inside the member picker

		*/
		'.mv-new-member-form{width:100%;max-width:420px;background:var(--tma-section-bg);' .
			'border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:14px;display:none}' .
		'.mv-new-member-form-title{font-size:15px;font-weight:700;color:var(--tma-text);margin-bottom:12px}' .
		'.mv-new-member-type-row{display:flex;gap:8px;margin-bottom:12px}' .
		'.mv-type-btn{flex:1;padding:8px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);' .
			'background:var(--tma-secondary-bg);color:var(--tma-hint);font-size:13px;cursor:pointer;' .
			'-webkit-tap-highlight-color:transparent;text-align:center}' .
		'.mv-type-btn.active{border-color:var(--tma-btn);color:var(--tma-btn);background:var(--tma-secondary-bg);font-weight:600}' .
		'.mv-new-member-err{color:#c62828;font-size:12px;margin-bottom:8px;display:none}' .
		'.mv-new-member-saving{color:var(--tma-hint);font-size:12px;margin-bottom:8px;display:none;text-align:center}' .

		/*
		 * Date range segmented control (trends tab)

		*/
		'.mv-range-bar{display:flex;gap:0;border:1px solid var(--tma-border);border-radius:20px;overflow:hidden;margin:10px 12px 6px}' .
		'.mv-range-btn{flex:1;padding:6px 0;border:none;background:transparent;color:var(--tma-hint);' .
			'font-size:12px;font-weight:600;cursor:pointer;-webkit-tap-highlight-color:transparent;text-align:center}' .
		'.mv-range-btn.active{background:var(--tma-btn);color:#fff}' .

		/*
		 * Settings tab

		*/
		'.mv-settings-wrap{padding:12px}' .
		'.mv-settings-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:14px;margin-bottom:12px}' .
		'.mv-settings-card-title{font-size:12px;font-weight:700;color:var(--tma-hint);text-transform:uppercase;' .
			'letter-spacing:.5px;margin-bottom:10px}' .
		'.mv-settings-row{display:flex;justify-content:space-between;align-items:center;' .
			'padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.mv-settings-row:last-child{border-bottom:none}' .
		'.mv-settings-label{font-size:13px;color:var(--tma-text)}' .
		'.mv-settings-value{font-size:13px;color:var(--tma-hint)}' .
		'.mv-settings-btn{font-size:12px;padding:5px 14px;border-radius:20px;border:1px solid var(--tma-btn);' .
			'background:none;color:var(--tma-btn);cursor:pointer;-webkit-tap-highlight-color:transparent}' .
		'.mv-settings-btn.danger{border-color:#c62828;color:#c62828}' .

		/*
		 * Inline member row inside settings — compact version of the picker cards

		*/
		'.mv-settings-member-row{display:flex;align-items:center;gap:10px;padding:10px 0;' .
			'border-bottom:1px solid var(--tma-border);cursor:pointer;-webkit-tap-highlight-color:transparent}' .
		'.mv-settings-member-row:last-child{border-bottom:none}' .
		'.mv-settings-member-row.selected .tma-member-card-icon{background:#1565c0}' .
		'.mv-settings-member-check{margin-left:auto;font-size:16px;color:var(--tma-btn);opacity:0}' .
		'.mv-settings-member-row.selected .mv-settings-member-check{opacity:1}' .

		/*
		 * Prescription cards

		*/
		'.mv-rx-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;margin-bottom:10px;position:relative;overflow:hidden}' .
		'.mv-rx-card::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--mv-rx-color,#1565c0)}' .
		'.mv-rx-header{display:flex;align-items:center;gap:8px;margin-bottom:6px}' .
		'.mv-rx-icon{font-size:20px;flex-shrink:0}' .
		'.mv-rx-name{font-size:14px;font-weight:600;flex:1}' .
		'.mv-rx-badge{font-size:10px;font-weight:600;padding:2px 8px;border-radius:20px;border:1px solid;display:inline-block;flex-shrink:0}' .
		'.mv-rx-badge.active{background:#e8f5e9;color:#2e7d32;border-color:#a5d6a7}' .
		'.mv-rx-badge.completed{background:#e3f2fd;color:#1565c0;border-color:#90caf9}' .
		'.mv-rx-badge.discontinued{background:#fff3e0;color:#e65100;border-color:#ffcc80}' .
		'.mv-rx-badge.expired{background:#ffebee;color:#c62828;border-color:#ef9a9a}' .
		'.mv-rx-detail{font-size:12px;color:var(--tma-hint);margin-bottom:2px}' .

		/*
		 * History log cards

		*/
		'.mv-hist-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;margin-bottom:8px;cursor:pointer;' .
			'-webkit-tap-highlight-color:transparent}' .
		'.mv-hist-card:active{background:var(--tma-secondary-bg)}' .
		'.mv-hist-date{font-size:14px;font-weight:600;color:var(--tma-text);margin-bottom:4px;display:flex;align-items:center;gap:6px}' .
		'.mv-hist-date-icon{font-size:16px}' .
		'.mv-hist-chips{display:flex;flex-wrap:wrap;gap:4px}' .
		'.mv-hist-chip{font-size:10px;padding:2px 6px;border-radius:10px;' .
			'background:var(--tma-secondary-bg);color:var(--tma-hint);border:1px solid var(--tma-border)}' .
		'.mv-hist-detail{margin-top:8px;padding-top:8px;border-top:1px solid var(--tma-border);display:none}' .
		'.mv-hist-detail.open{display:block}' .
		'.mv-hist-detail-row{display:flex;justify-content:space-between;padding:3px 0;font-size:12px}' .
		'.mv-hist-detail-label{color:var(--tma-hint)}' .
		'.mv-hist-detail-val{font-weight:600;color:var(--tma-text)}' .

		/*
		 * Font size setting buttons

		*/
		'.mv-font-btn{min-width:36px;text-align:center;font-weight:600}' .
		'.mv-font-btn.active{background:var(--tma-btn);color:#fff;border-color:var(--tma-btn)}' .

		/*
		 * Toggle switch knob

		*/
		'input:checked+span{background:var(--tma-btn) !important}' .
		'input:checked+span+.mv-toggle-knob{transform:translateX(18px)}' .

		/*
		 * Font size CSS custom properties (medium is default)

		*/
		'.mv-font-small{--mv-base:12px;--mv-label:10px;--mv-kpi:17px;--mv-section:12px;--mv-hint:9px}' .
		'.mv-font-large{--mv-base:16px;--mv-label:14px;--mv-kpi:24px;--mv-section:16px;--mv-hint:12px}' .
		'.mv-font-small .mv-kpi-val{font-size:var(--mv-kpi)}' .
		'.mv-font-small .mv-kpi-label,.mv-font-small .mv-log-label{font-size:var(--mv-label)}' .
		'.mv-font-small .mv-kpi-unit,.mv-font-small .mv-kpi-time{font-size:var(--mv-hint)}' .
		'.mv-font-small .mv-med-name,.mv-font-small .mv-rx-name,.mv-font-small .mv-hist-date{font-size:var(--mv-base)}' .
		'.mv-font-small .tma-section-title{font-size:var(--mv-section)}' .
		'.mv-font-large .mv-kpi-val{font-size:var(--mv-kpi)}' .
		'.mv-font-large .mv-kpi-label,.mv-font-large .mv-log-label{font-size:var(--mv-label)}' .
		'.mv-font-large .mv-kpi-unit,.mv-font-large .mv-kpi-time{font-size:var(--mv-hint)}' .
		'.mv-font-large .mv-med-name,.mv-font-large .mv-rx-name,.mv-font-large .mv-hist-date{font-size:var(--mv-base)}' .
		'.mv-font-large .tma-section-title{font-size:var(--mv-section)}' .
		'.mv-font-large .mv-med-dose,.mv-font-large .mv-med-schedule,.mv-font-large .mv-rx-detail{font-size:14px}' .
		'.mv-font-large .mv-settings-label,.mv-font-large .mv-settings-value{font-size:15px}' .
		'.mv-font-large .tma-input{font-size:15px}' .
		'.mv-font-small .mv-med-dose,.mv-font-small .mv-med-schedule,.mv-font-small .mv-rx-detail{font-size:10px}' .
		'.mv-font-small .mv-settings-label,.mv-font-small .mv-settings-value{font-size:11px}' .
		'.mv-font-small .tma-input{font-size:11px}' .

		/*
		 * Compact mode

		*/
		'.mv-compact .mv-kpi{padding:8px}' .
		'.mv-compact .mv-kpi-grid{gap:6px;padding:6px 8px}' .
		'.mv-compact .mv-med-card,.mv-compact .mv-rx-card,.mv-compact .mv-hist-card{padding:8px;margin-bottom:6px}' .
		'.mv-compact .mv-log-section{margin-bottom:8px}' .
		'.mv-compact .mv-banner{padding:8px 10px;margin:6px 8px}' .
		'</style>' .

		/*
		 * ── Member picker overlay ───────────────────────────────────────────

		*/
		'<div class="tma-member-picker" id="tma-member-picker">' .
			'<div class="tma-member-picker-icon">&#128101;</div>' .
			'<div class="tma-member-picker-title">' . esc_html__( 'Select Member', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'<div class="tma-member-picker-sub">' . esc_html__( 'Choose a member to view their medical vitals data.', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'<div class="tma-member-list" id="tma-mv-member-list">' .
				'<div class="tma-member-msg">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'</div>' .

			/*
			 * New member creation form (hidden by default, shown on "+ New Member" tap)

		 */
			'<div class="mv-new-member-form" id="mv-new-member-form">' .
				'<div class="mv-new-member-form-title">&#128100; ' . esc_html__( 'Add New Member', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="mv-new-member-type-row">' .
					'<button class="mv-type-btn active" id="mv-type-btn-person" onclick="mvSetMemberType(\'person\',this)">' . esc_html__( '👤 Person', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-type-btn" id="mv-type-btn-pet" onclick="mvSetMemberType(\'pet\',this)">' . esc_html__( '🐶 Pet', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'</div>' .
				'<div style="margin-bottom:10px">' .
					'<label class="mv-log-label">' . esc_html__( 'Name', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<input type="text" id="mv-new-member-name" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'Full name…', 'mcp-ai-wpoos-pro' ) . '" />' .
				'</div>' .
				'<div style="margin-bottom:10px">' .
					'<label class="mv-log-label">' . esc_html__( 'Date of Birth', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<input type="date" id="mv-new-member-dob" class="tma-input" style="width:100%" />' .
				'</div>' .
				'<div style="margin-bottom:14px">' .
					'<label class="mv-log-label">' . esc_html__( 'Blood Type', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<select id="mv-new-member-blood" class="tma-input" style="width:100%">' .
						'<option value="">' . esc_html__( '— Select —', 'mcp-ai-wpoos-pro' ) . '</option>' .
						'<option value="A+">A+</option><option value="A-">A-</option>' .
						'<option value="B+">B+</option><option value="B-">B-</option>' .
						'<option value="AB+">AB+</option><option value="AB-">AB-</option>' .
						'<option value="O+">O+</option><option value="O-">O-</option>' .
					'</select>' .
				'</div>' .
				'<div class="mv-new-member-err" id="mv-new-member-err"></div>' .
				'<div class="mv-new-member-saving" id="mv-new-member-saving">' . esc_html__( 'Saving…', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div style="display:flex;gap:8px">' .
					'<button class="tma-btn tma-btn-secondary" style="flex:1" onclick="mvHideNewMemberForm()">' . esc_html__( 'Cancel', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="tma-btn tma-btn-primary" style="flex:1" onclick="mvSubmitNewMember()">' . esc_html__( 'Create', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'</div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Shell ───────────────────────────────────────────────────────────

		*/
		'<div class="tma-shell" id="tma-shell">' .

		/*
		 * Header

		*/
		'<header class="tma-header">' .
			'<div class="tma-avatar-wrap"><div class="tma-avatar-initials" style="background:#1565c0;font-size:18px">🩺</div></div>' .
			'<div class="tma-header-info">' .
				'<div class="tma-header-name">' . $site_name . '</div>' .
				'<div class="tma-header-status">' . esc_html__( 'Medical Vitals', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-header-member" id="tma-mv-member-label"></div>' .
			'</div>' .
			'<div class="tma-header-actions">' .
				'<button class="tma-icon-btn" title="' . esc_attr__( 'Switch Member', 'mcp-ai-wpoos-pro' ) . '" onclick="mvSwitchMember()">' .
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
				'</button>' .
				'<button class="tma-icon-btn" title="' . esc_attr__( 'Refresh', 'mcp-ai-wpoos-pro' ) . '" onclick="mvRefresh()">' .
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>' .
				'</button>' .
			'</div>' .
		'</header>' .

		'<div class="tma-content">' .

		/*
		 * ── Dashboard ──

		*/
		'<div class="tma-tab-pane tma-active" id="mv-tab-dashboard">' .
			'<div class="mv-scroll" id="mv-dash-scroll">' .
				'<div class="mv-banner">' .
					'<div>' .
						'<div class="mv-banner-title">' . esc_html__( 'Vitals Overview', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="mv-banner-sub" id="mv-last-time">' . esc_html__( 'No readings yet', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'</div>' .
					'<div class="mv-banner-icon">❤️</div>' .
				'</div>' .
				'<div class="mv-kpi-grid" id="mv-kpi-grid">' .
					'<div class="tma-empty" style="grid-column:span 2">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .

				/*
				 * Kidney health section

			 */
				'<div class="mv-kidney-section-title">&#129506; ' . esc_html__( 'Kidney Health', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="mv-kpi-grid" id="mv-kidney-kpi-grid" style="padding-top:6px">' .
					'<div class="tma-empty" style="grid-column:span 2;padding:10px 0">' . esc_html__( 'No lab values logged yet.', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="mv-range-bar" id="mv-dash-range-bar" role="group" aria-label="' . esc_attr__( 'Chart date range', 'mcp-ai-wpoos-pro' ) . '">' .
					'<button class="mv-range-btn active" aria-pressed="true" onclick="mvSetDashChartRange(7,this)">' . esc_html__( '7 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-range-btn" aria-pressed="false" onclick="mvSetDashChartRange(14,this)">' . esc_html__( '14 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-range-btn" aria-pressed="false" onclick="mvSetDashChartRange(30,this)">' . esc_html__( '30 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-range-btn" aria-pressed="false" onclick="mvSetDashChartRange(90,this)">' . esc_html__( '90 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'</div>' .
				'<div id="mv-dash-chart" style="padding-bottom:12px"></div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Log ──

		*/
		'<div class="tma-tab-pane" id="mv-tab-log">' .
			'<div class="mv-scroll">' .
				'<div class="mv-log-wrap">' .

					/*
					 * History list (default view)

				 */
					'<div id="mv-log-history-view">' .
						'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">' .
							'<div class="tma-section-title" style="padding:0">&#128197; ' . esc_html__( 'Vitals History', 'mcp-ai-wpoos-pro' ) . '</div>' .
							'<button class="tma-btn tma-btn-primary" style="font-size:12px;padding:5px 14px" onclick="mvShowLogForm()" aria-label="' . esc_attr__( 'Create new vitals entry', 'mcp-ai-wpoos-pro' ) . '">+ ' . esc_html__( 'New Entry', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
						'<div id="mv-log-history-list"><div class="mv-empty">' . esc_html__( 'No vitals recorded yet.', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
					'</div>' .

					/*
					 * Record form (hidden by default)

				 */
					'<div id="mv-log-form-view" style="display:none">' .
						'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">' .
							'<div class="tma-section-title" style="padding:0">' . esc_html__( 'Record Vitals', 'mcp-ai-wpoos-pro' ) . '</div>' .
							'<button class="tma-btn tma-btn-secondary" style="font-size:12px;padding:5px 14px" onclick="mvShowLogHistory()" aria-label="' . esc_attr__( 'Back to vitals history', 'mcp-ai-wpoos-pro' ) . '">&larr; ' . esc_html__( 'Back', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
						'<div class="mv-log-saved" id="mv-log-saved">&#10003; ' . esc_html__( 'Reading saved!', 'mcp-ai-wpoos-pro' ) . '</div>' .

						/*
						 * Blood Pressure

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#129728; ' . esc_html__( 'Blood Pressure (mmHg)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<div class="mv-bp-row">' .
								'<input type="number" id="mv-bp-sys" class="tma-input" style="flex:1" placeholder="' . esc_attr__( 'Systolic', 'mcp-ai-wpoos-pro' ) . '" min="60" max="250" />' .
								'<span class="mv-bp-sep">/</span>' .
								'<input type="number" id="mv-bp-dia" class="tma-input" style="flex:1" placeholder="' . esc_attr__( 'Diastolic', 'mcp-ai-wpoos-pro' ) . '" min="40" max="150" />' .
							'</div>' .
						'</div>' .

						/*
						 * Heart Rate

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#10084; ' . esc_html__( 'Heart Rate (bpm)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-hr" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 72', 'mcp-ai-wpoos-pro' ) . '" min="30" max="250" />' .
						'</div>' .

						/*
						 * SpO2

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#128164; ' . esc_html__( 'Blood Oxygen SpO₂ (%)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-spo2" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 98', 'mcp-ai-wpoos-pro' ) . '" min="70" max="100" step="1" />' .
						'</div>' .

						/*
						 * Temperature

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#127777; ' . esc_html__( 'Temperature (°F)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-temp" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 98.6', 'mcp-ai-wpoos-pro' ) . '" min="90" max="110" step="0.1" />' .
						'</div>' .

						/*
						 * Glucose

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#128137; ' . esc_html__( 'Blood Glucose (mg/dL)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-glucose" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 95', 'mcp-ai-wpoos-pro' ) . '" min="20" max="600" />' .
						'</div>' .

						/*
						 * ── Kidney Lab Values ──

					 */
						'<div class="mv-lab-divider">&#129506; ' . esc_html__( 'Kidney Lab Values', 'mcp-ai-wpoos-pro' ) . '</div>' .

						/*
						 * EGFR

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">eGFR (mL/min/1.73m²)</label>' .
							'<input type="number" id="mv-egfr" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 72', 'mcp-ai-wpoos-pro' ) . '" min="1" max="200" step="1" />' .
						'</div>' .

						/*
						 * Creatinine

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#129514; ' . esc_html__( 'Creatinine (mg/dL)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-creatinine" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 0.9', 'mcp-ai-wpoos-pro' ) . '" min="0.1" max="20" step="0.1" />' .
						'</div>' .

						/*
						 * BUN

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#129514; ' . esc_html__( 'BUN – Blood Urea Nitrogen (mg/dL)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-bun" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 14', 'mcp-ai-wpoos-pro' ) . '" min="1" max="200" />' .
						'</div>' .

						/*
						 * Two-column row: Potassium + Sodium

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#9889; ' . esc_html__( 'Electrolytes (mEq/L)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<div style="display:flex;gap:8px">' .
								'<div style="flex:1"><input type="number" id="mv-potassium" class="tma-input" placeholder="K\u207a ' . esc_attr__( 'Potassium', 'mcp-ai-wpoos-pro' ) . '" min="1" max="10" step="0.1" /></div>' .
								'<div style="flex:1"><input type="number" id="mv-sodium" class="tma-input" placeholder="Na\u207a ' . esc_attr__( 'Sodium', 'mcp-ai-wpoos-pro' ) . '" min="100" max="180" step="1" /></div>' .
							'</div>' .
						'</div>' .

						/*
						 * Phosphorus

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#129514; ' . esc_html__( 'Phosphorus (mg/dL)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-phosphorus" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 3.5', 'mcp-ai-wpoos-pro' ) . '" min="0.5" max="15" step="0.1" />' .
						'</div>' .

						/*
						 * Albumin

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#129514; ' . esc_html__( 'Albumin (g/dL)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-albumin" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 4.0', 'mcp-ai-wpoos-pro' ) . '" min="0.5" max="7" step="0.1" />' .
						'</div>' .

						/*
						 * Hemoglobin

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#129978; ' . esc_html__( 'Hemoglobin (g/dL)', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="number" id="mv-hemoglobin" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. 13.5', 'mcp-ai-wpoos-pro' ) . '" min="1" max="25" step="0.1" />' .
						'</div>' .

						/*
						 * Notes

					 */
						'<div class="mv-log-section">' .
							'<label class="mv-log-label">&#128221; ' . esc_html__( 'Notes', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<textarea id="mv-notes" class="tma-input" rows="3" style="resize:none;width:100%" placeholder="' . esc_attr__( 'Optional notes…', 'mcp-ai-wpoos-pro' ) . '"></textarea>' .
						'</div>' .

						'<button class="tma-btn tma-btn-primary" style="width:100%" onclick="mvSaveReading()">' . esc_html__( 'Save Reading', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'</div>' .
				'</div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Trends ──

		*/
		'<div class="tma-tab-pane" id="mv-tab-trends">' .
			'<div class="mv-scroll" id="mv-trends-scroll">' .
				'<div style="padding:10px 12px 0">' .
					'<div class="tma-section-title" style="padding:0 0 4px" id="mv-trends-title">' . esc_html__( '7-Day Trends', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div style="font-size:12px;color:var(--tma-hint)">' . esc_html__( 'Shaded bands show normal reference ranges.', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="mv-range-bar" role="group" aria-label="' . esc_attr__( 'Trend date range', 'mcp-ai-wpoos-pro' ) . '">' .
					'<button class="mv-range-btn active" aria-pressed="true" onclick="mvSetTrendRange(7,this)">' . esc_html__( '7 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-range-btn" aria-pressed="false" onclick="mvSetTrendRange(14,this)">' . esc_html__( '14 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-range-btn" aria-pressed="false" onclick="mvSetTrendRange(30,this)">' . esc_html__( '30 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'<button class="mv-range-btn" aria-pressed="false" onclick="mvSetTrendRange(90,this)">' . esc_html__( '90 D', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'</div>' .
				'<div id="mv-trends-content" style="padding-bottom:12px"><div class="tma-empty">' . esc_html__( 'Loading charts…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Dosage ──

		*/
		'<div class="tma-tab-pane" id="mv-tab-dosage">' .
			'<div class="mv-scroll">' .
				'<div class="mv-dosage-wrap">' .

					/*
					 * Server prescriptions section

				 */
					'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">' .
						'<div class="tma-section-title" style="padding:0">&#128203; ' . esc_html__( 'Prescriptions', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<button class="tma-btn tma-btn-secondary" style="font-size:12px;padding:5px 12px" onclick="mvFetchPrescriptions()">' . esc_html__( '↻ Refresh', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'</div>' .
					'<div id="mv-rx-list"><div class="mv-empty">' . esc_html__( 'Loading prescriptions…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .

					'<div class="mv-lab-divider">&#128138; ' . esc_html__( 'Quick Medications', 'mcp-ai-wpoos-pro' ) . '</div>' .

					'<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:10px">' .
						'<div class="tma-section-title" style="padding:0">' . esc_html__( 'Medications & Dosage', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<button class="tma-btn tma-btn-secondary" style="font-size:12px;padding:5px 12px" onclick="mvToggleAddMed()">' . esc_html__( '+ Add', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'</div>' .

					/*
					 * Add medication form (hidden by default)

				 */
					'<div class="mv-add-med-form" id="mv-add-med-form">' .
						'<div style="font-size:13px;font-weight:600;margin-bottom:10px">&#128138; ' . esc_html__( 'Add Medication', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div style="margin-bottom:8px"><label class="mv-log-label">' . esc_html__( 'Medication Name', 'mcp-ai-wpoos-pro' ) . '</label>' .
						'<input type="text" id="mv-med-name" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. Metformin', 'mcp-ai-wpoos-pro' ) . '" /></div>' .
						'<div style="display:flex;gap:8px;margin-bottom:8px">' .
							'<div style="flex:1"><label class="mv-log-label">' . esc_html__( 'Dose', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="text" id="mv-med-dose" class="tma-input" style="width:100%" placeholder="' . esc_attr__( '500mg', 'mcp-ai-wpoos-pro' ) . '" /></div>' .
							'<div style="flex:1"><label class="mv-log-label">' . esc_html__( 'Frequency', 'mcp-ai-wpoos-pro' ) . '</label>' .
							'<input type="text" id="mv-med-freq" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'Twice daily', 'mcp-ai-wpoos-pro' ) . '" /></div>' .
						'</div>' .
						'<div style="margin-bottom:8px"><label class="mv-log-label">' . esc_html__( 'Instructions', 'mcp-ai-wpoos-pro' ) . '</label>' .
						'<input type="text" id="mv-med-notes" class="tma-input" style="width:100%" placeholder="' . esc_attr__( 'e.g. Take with food', 'mcp-ai-wpoos-pro' ) . '" /></div>' .
						'<div style="display:flex;gap:8px">' .
							'<button class="tma-btn tma-btn-secondary" style="flex:1" onclick="mvToggleAddMed()">' . esc_html__( 'Cancel', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'<button class="tma-btn tma-btn-primary" style="flex:1" onclick="mvAddMed()">' . esc_html__( 'Save', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
					'</div>' .

					'<div id="mv-med-list"><div class="mv-empty">' . esc_html__( 'No medications added yet.', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Doctor ──

		*/
		'<div class="tma-tab-pane" id="mv-tab-doctor">' .
			'<div class="mv-doctor-wrap">' .
				'<div class="mv-doctor-msgs" id="mv-doctor-msgs">' .
					'<div class="mv-doctor-msg bot">' . esc_html__( 'Hello! I\'m your AI health assistant. I can help you understand your vitals, review trends, and answer questions about your treatment plan. Please share your concerns! 🩺', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="mv-doctor-bar">' .
					'<input type="text" id="mv-doctor-input" class="mv-doctor-input" placeholder="' . esc_attr__( 'Ask about your vitals…', 'mcp-ai-wpoos-pro' ) . '" onkeydown="if(event.key===\'Enter\')mvDoctorSend();" />' .
					'<button class="mv-doctor-send" onclick="mvDoctorSend()" title="' . esc_attr__( 'Send', 'mcp-ai-wpoos-pro' ) . '">&#10148;</button>' .
				'</div>' .
			'</div>' .
		'</div>' .

		/*
		 * ── Settings ──

		*/
		'<div class="tma-tab-pane" id="mv-tab-settings">' .
			'<div class="mv-scroll">' .
				'<div class="mv-settings-wrap">' .

					/*
					 * Default member selector card

				 */
					'<div class="mv-settings-card">' .
						'<div class="mv-settings-card-title">&#128101; ' . esc_html__( 'Default Member', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div style="font-size:12px;color:var(--tma-hint);margin-bottom:10px">' . esc_html__( 'Select the member whose data is loaded by default when the app opens.', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div id="mv-settings-member-list">' .
							'<div class="tma-member-msg">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'</div>' .
						'<div style="margin-top:8px">' .
							'<button class="mv-settings-btn" onclick="mvSettingsAddMember()">' . esc_html__( '+ Add New Member', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
					'</div>' .

					/*
					 * Data management card

				 */
					'<div class="mv-settings-card">' .
						'<div class="mv-settings-card-title">&#128266; ' . esc_html__( 'Local Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="mv-settings-row">' .
							'<span class="mv-settings-label">' . esc_html__( 'Readings stored', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="mv-settings-value" id="mv-settings-reading-count">—</span>' .
						'</div>' .
						'<div class="mv-settings-row">' .
							'<span class="mv-settings-label">' . esc_html__( 'Medications stored', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="mv-settings-value" id="mv-settings-med-count">—</span>' .
						'</div>' .
						'<div style="margin-top:10px;display:flex;gap:8px;flex-wrap:wrap">' .
							'<button class="mv-settings-btn" onclick="mvSettingsSyncServer()">' . esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) . '</button>' .
							'<button class="mv-settings-btn danger" onclick="mvSettingsClearData()">' . esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'</div>' .
					'</div>' .

					/*
					 * Display / font size card

				 */
					'<div class="mv-settings-card">' .
						'<div class="mv-settings-card-title">&#127912; ' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div style="font-size:12px;color:var(--tma-hint);margin-bottom:10px">' . esc_html__( 'Adjust the font size and styling of the app.', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="mv-settings-row">' .
							'<span class="mv-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div id="mv-font-size-btns" style="display:flex;gap:4px">' .
								'<button class="mv-settings-btn mv-font-btn" data-size="small" onclick="mvSetFontSize(\'small\',this)">A<span style="font-size:9px">&#8722;</span></button>' .
								'<button class="mv-settings-btn mv-font-btn active" data-size="medium" onclick="mvSetFontSize(\'medium\',this)">A</button>' .
								'<button class="mv-settings-btn mv-font-btn" data-size="large" onclick="mvSetFontSize(\'large\',this)">A<span style="font-size:9px">+</span></button>' .
							'</div>' .
						'</div>' .
						'<div class="mv-settings-row">' .
							'<span class="mv-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<label style="position:relative;display:inline-block;width:42px;height:24px;flex-shrink:0">' .
								'<input type="checkbox" id="mv-compact-toggle" onchange="mvToggleCompact(this.checked)" style="opacity:0;width:0;height:0">' .
								'<span style="position:absolute;cursor:pointer;inset:0;background:var(--tma-border);border-radius:24px;transition:.2s"></span>' .
								'<span class="mv-toggle-knob" style="position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s"></span>' .
							'</label>' .
						'</div>' .
					'</div>' .

					/*
					 * About card

				 */
					'<div class="mv-settings-card">' .
						'<div class="mv-settings-card-title">&#8505; ' . esc_html__( 'About', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="mv-settings-row">' .
							'<span class="mv-settings-label">' . esc_html__( 'Template', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="mv-settings-value">' . esc_html__( 'Medical Vitals', 'mcp-ai-wpoos-pro' ) . '</span>' .
						'</div>' .
						'<div class="mv-settings-row">' .
							'<span class="mv-settings-label">' . esc_html__( 'Site', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="mv-settings-value">' . $site_name . '</span>' .
						'</div>' .
					'</div>' .

				'</div>' .
			'</div>' .
		'</div>' .

		'</div>' . /* .tma-content */

		/*
		 * Bottom navigation

		*/
		'<nav class="tma-nav">' .
			'<button class="tma-nav-btn tma-active" id="mv-nav-dashboard" onclick="mvTab(\'dashboard\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>' .
				esc_html__( 'Dashboard', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="mv-nav-log" onclick="mvTab(\'log\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/><polyline points="10 9 9 9 8 9"/></svg>' .
				esc_html__( 'Log', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="mv-nav-trends" onclick="mvTab(\'trends\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>' .
				esc_html__( 'Trends', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="mv-nav-dosage" onclick="mvTab(\'dosage\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>' .
				esc_html__( 'Dosage', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="mv-nav-doctor" onclick="mvTab(\'doctor\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
				esc_html__( 'Doctor', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
			'<button class="tma-nav-btn" id="mv-nav-settings" onclick="mvTab(\'settings\',this)">' .
				'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
				esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) .
			'</button>' .
		'</nav>' .

		'</div>' . /* .tma-shell */

		/*
		 * ── JavaScript ───────────────────────────────────────────────────────

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * Config

		*/
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .
		'var MARKDOWN_JS_URL=' . wp_json_encode( $markdown_js_url ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var MEMBER_ID=0;' .
		'var MEMBER_NAME="";' .
		'var TMA_TOKEN="";' .
		'var doctorHist=[];' .

		/*
		 * Server-resolved member for the current WordPress user (0 when unknown)

		*/
		'var SERVER_MEMBER_ID=' . wp_json_encode( $server_member_id ) . ';' .
		'var SERVER_MEMBER_NAME=' . wp_json_encode( $server_member_name ) . ';' .

		/*
		── Markdown renderer loader ──

		*/

		/*
		Load the lightweight TMA markdown renderer on demand so doctor

		*/

		/*
		 * Replies are displayed as formatted HTML instead of raw markdown.

		*/
		'function mvLoadMarkdown(cb){' .
			'if(window.wpMcpAiChatMarkdown){cb();return;}' .
			'if(!MARKDOWN_JS_URL){cb();return;}' .
			'var s=document.createElement("script");s.src=MARKDOWN_JS_URL;' .
			's.onload=function(){cb();};s.onerror=function(){cb();};' .
			'document.head.appendChild(s);' .
		'}' .

		/*
		 * ── Render reply using the preferred markdown method ──

		*/
		'function mvRenderReply(el,text){' .
			'if(el&&window.wpMcpAiChatMarkdown&&window.wpMcpAiChatMarkdown.renderMarkdown){' .
				'el.innerHTML=window.wpMcpAiChatMarkdown.renderMarkdown(text);' .
			'}else if(el){' .
				'el.textContent=text;' .
			'}' .
		'}' .

		/*
		── Session init: authenticate via Telegram initData ──

		*/

		/*
		 * Calls /validate on page load so the doctor tab chat requests are

		 */

		/*
		Authenticated even when Telegram's WebView does not persist the

		 */

		/*
		 * WordPress auth cookie between page loads.

		*/
		'function mvInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp)return;' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData)return;' .
			'fetch(VALIDATE_URL,{method:"POST",' .
				'headers:{"Content-Type":"application/json"},' .
				'body:JSON.stringify({init_data:initData})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(!d)return;' .

				/*
				 * Update the REST nonce so authenticated requests succeed.

			 */
				'if(d.wp_nonce){NONCE=d.wp_nonce;}' .

				/*
				 * Store the TMA session token for authenticated headers.

			 */
				'if(d.tma_token){TMA_TOKEN=d.tma_token;}' .

				/*
				 * Re-fetch members with fresh auth if the picker is still open

			 */
				'var picker=document.getElementById("tma-member-picker");' .
				'if(picker&&picker.style.display==="flex"){mvFetchMembers();}' .

				/*
				Re-sync server vitals now that auth is established.
				 * mvSyncFromServer() is called at page-init before this async
				 * /validate response arrives, so Telegram users whose WP auth
				 * cookie did not persist get a silent auth failure on the first
				 * attempt.  Retry here with the fresh nonce / TMA token.
				 */
				'if(MEMBER_ID)mvSyncFromServer();' .
			'})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Storage helpers ──

		*/
		'var SK_READINGS="mv_readings";' .
		'var SK_MEDS="mv_meds";' .
		'function mvTodayKey(){return new Date().toISOString().slice(0,10);}' .

		/*
		 * Load all stored readings (array, newest first)

		*/
		'function mvLoadReadings(){' .
			'try{var v=localStorage.getItem(SK_READINGS);return v?JSON.parse(v):[];}' .
			'catch(e){return [];}' .
		'}' .

		/*
		 * Save readings list

		*/
		'function mvStoreReadings(arr){' .
			'try{localStorage.setItem(SK_READINGS,JSON.stringify(arr));}catch(e){}' .
		'}' .

		/*
		 * Load last N days of readings (one per day, last reading of that day)

		*/
		'function mvLoadHistory(days){' .
			'if(!days)days=7;' .
			'var all=mvLoadReadings();' .
			'var byDay={};' .
			'all.forEach(function(r){var d=r.ts?r.ts.slice(0,10):mvTodayKey();byDay[d]=r;});' .
			'var hist=[];var base=new Date();' .
			'for(var i=days-1;i>=0;i--){' .
				'var dd=new Date(base);dd.setDate(dd.getDate()-i);' .
				'var dk=dd.toISOString().slice(0,10);' .
				'hist.push(byDay[dk]||{date:dk});' .
			'}' .
			'return hist;' .
		'}' .

		/*
		 * Load medications list

		*/
		'function mvLoadMeds(){' .
			'try{var v=localStorage.getItem(SK_MEDS);return v?JSON.parse(v):[];}' .
			'catch(e){return [];}' .
		'}' .

		'function mvStoreMeds(arr){' .
			'try{localStorage.setItem(SK_MEDS,JSON.stringify(arr));}catch(e){}' .
		'}' .

		/*
		 * HTML-escape helper

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .

		/*
		 * ── Member picker ──

		*/
		'function mvLoadSavedMember(){' .
			'try{' .
				'var d=JSON.parse(localStorage.getItem("mv_member_id")||"null");' .
				'if(d&&d.id){MEMBER_ID=d.id;MEMBER_NAME=d.name||"";}' .
			'}catch(e){}' .
		'}' .

		'function mvShowMemberPicker(){' .
			'var p=document.getElementById("tma-member-picker");' .
			'if(p)p.style.display="flex";' .

			/*
			In Telegram WebView the TMA token is obtained asynchronously by
			 * mvInitSession().  Calling mvFetchMembers() before the token is
			 * available sends an unauthenticated request that returns 403.
			 * Skip the immediate fetch when we are inside a Telegram WebApp and
			 * TMA_TOKEN has not yet been set; mvInitSession() will call
			 * mvFetchMembers() once auth is established.  When the token is
			 * already present (e.g. member-switch after first load) or we are
			 * in a regular browser (no Telegram WebApp API), fetch immediately.
			 */
			'if(TMA_TOKEN||!(window.Telegram&&window.Telegram.WebApp&&window.Telegram.WebApp.initData)){' .
				'mvFetchMembers();' .
			'}' .
		'}' .

		'function mvHideMemberPicker(){' .
			'var p=document.getElementById("tma-member-picker");' .
			'if(p)p.style.display="none";' .
		'}' .

		'window.mvFetchMembers=function(){' .
			'var list=document.getElementById("tma-mv-member-list");' .
			'if(!list)return;' .

			/*
			 * Shared error markup — used in both the failed-response and catch paths

		 */
			'var mvErrHtml=\'<div class="tma-member-msg">' . esc_js( __( 'Could not load members.', 'mcp-ai-wpoos-pro' ) ) . ' <button onclick="mvFetchMembers()" style="margin-left:6px;padding:4px 10px;border:1px solid var(--tma-btn);border-radius:8px;background:none;color:var(--tma-btn);font-size:12px;cursor:pointer">' . esc_js( __( 'Retry', 'mcp-ai-wpoos-pro' ) ) . '</button></div>\';' .
			'list.innerHTML=\'<div class="tma-member-msg">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"list_members",arguments:{per_page:50}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .

				/*
				If the request failed (auth not yet established), keep the Loading… placeholder
				 * so mvInitSession() can re-call mvFetchMembers() once auth succeeds.
				 * Outside Telegram we also add a manual retry button so users are not stuck.
				 */
				'if(!d){if(list)list.innerHTML=mvErrHtml;return;}' .
				'var members=d.result&&d.result.members?d.result.members:[];' .

				/*
				 * Auto-select when exactly one member exists — skip picker entirely

			 */
				'if(members.length===1){mvSelectMember(members[0].id,members[0].name);return;}' .

				/*
				 * Build member cards (may be empty) then always append "+ New Member"

			 */
				'var cards=members.map(function(m){' .
					'var icon=m.type==="pet"?"&#128062;":"&#128100;";' .
					'return\'<div class="tma-member-card" onclick="mvSelectMember(\'+m.id+\',\'+JSON.stringify(m.name)+\')">\'' .
						'+\'<div class="tma-member-card-icon">\'+icon+\'</div>\'' .
						'+\'<div><div class="tma-member-card-name">\'+escH(m.name)+\'</div>\'' .
						'+\'<div class="tma-member-card-type">\'+escH(m.type||"person")+\'</div></div>\'' .
						'+\'</div>\';' .
				'}).join("");' .

				/*
				 * Always show the New Member card at the bottom

			 */
				'var newCard=\'<div class="tma-member-card" onclick="mvShowNewMemberForm()" style="border-style:dashed;opacity:.85">\'' .
					'+\'<div class="tma-member-card-icon" style="background:#78909c">+</div>\'' .
					'+\'<div><div class="tma-member-card-name">' . esc_js( __( '+ New Member', 'mcp-ai-wpoos-pro' ) ) . '</div>\'' .
					'+\'<div class="tma-member-card-type">' . esc_js( __( 'Create a new profile', 'mcp-ai-wpoos-pro' ) ) . '</div></div>\'' .
					'+\'</div>\';' .
				'list.innerHTML=cards+newCard;' .
			'})' .
			'.catch(function(){if(list)list.innerHTML=mvErrHtml;});' .
		'};' .

		'window.mvSelectMember=function(id,name){' .
			'MEMBER_ID=id;MEMBER_NAME=name;' .
			'try{localStorage.setItem("mv_member_id",JSON.stringify({id:id,name:name}));}catch(e){}' .
			'mvHideMemberPicker();' .
			'var lbl=document.getElementById("tma-mv-member-label");' .
			'if(lbl)lbl.textContent=name;' .

			/*
			 * Clear cached readings and pull fresh data from server for this member

		 */
			'try{localStorage.removeItem(SK_READINGS);}catch(e){}' .
			'mvRefresh();mvRenderMeds();mvSyncFromServer();mvFetchPrescriptions();' .
		'};' .

		'window.mvSwitchMember=function(){' .
			'mvHideNewMemberForm();' .
			'mvShowMemberPicker();' .
		'};' .

		/*
		 * ── New member form ──

		*/
		'var mvNewMemberType="person";' .
		'var mvNewMemberFromSettings=false;' .

		'window.mvSetMemberType=function(type,btn){' .
			'mvNewMemberType=type;' .
			'var btns=document.querySelectorAll(".mv-type-btn");' .
			'btns.forEach(function(b){b.classList.remove("active");});' .
			'if(btn)btn.classList.add("active");' .
		'};' .

		'window.mvShowNewMemberForm=function(){' .
			'var list=document.getElementById("tma-mv-member-list");' .
			'var form=document.getElementById("mv-new-member-form");' .
			'if(list)list.style.display="none";' .
			'if(form)form.style.display="block";' .

			/*
			 * Reset form state

		 */
			'mvNewMemberType="person";' .
			'var btnPerson=document.getElementById("mv-type-btn-person");' .
			'var btnPet=document.getElementById("mv-type-btn-pet");' .
			'if(btnPerson)btnPerson.classList.add("active");' .
			'if(btnPet)btnPet.classList.remove("active");' .
			'var nameEl=document.getElementById("mv-new-member-name");if(nameEl)nameEl.value="";' .
			'var dobEl=document.getElementById("mv-new-member-dob");if(dobEl)dobEl.value="";' .
			'var bloodEl=document.getElementById("mv-new-member-blood");if(bloodEl)bloodEl.value="";' .
			'var errEl=document.getElementById("mv-new-member-err");if(errEl)errEl.style.display="none";' .
			'var savingEl=document.getElementById("mv-new-member-saving");if(savingEl)savingEl.style.display="none";' .
		'};' .

		'window.mvHideNewMemberForm=function(){' .
			'var list=document.getElementById("tma-mv-member-list");' .
			'var form=document.getElementById("mv-new-member-form");' .
			'if(form)form.style.display="none";' .
			'if(list)list.style.display="block";' .
		'};' .

		'window.mvSubmitNewMember=function(){' .
			'var nameEl=document.getElementById("mv-new-member-name");' .
			'var name=(nameEl?nameEl.value:"").trim();' .
			'var errEl=document.getElementById("mv-new-member-err");' .
			'var savingEl=document.getElementById("mv-new-member-saving");' .
			'if(!name){' .
				'if(errEl){errEl.textContent="' . esc_js( __( 'Name is required.', 'mcp-ai-wpoos-pro' ) ) . '";errEl.style.display="block";}' .
				'return;' .
			'}' .
			'if(errEl)errEl.style.display="none";' .
			'if(savingEl)savingEl.style.display="block";' .
			'var args={name:name,type:mvNewMemberType};' .
			'var dobEl=document.getElementById("mv-new-member-dob");' .
			'var dob=(dobEl?dobEl.value:"").trim();if(dob)args.date_of_birth=dob;' .
			'var bloodEl=document.getElementById("mv-new-member-blood");' .
			'var blood=(bloodEl?bloodEl.value:"").trim();if(blood)args.blood_type=blood;' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"create_member",arguments:args})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(savingEl)savingEl.style.display="none";' .
				'var memberId=d&&d.result&&d.result.member_id?d.result.member_id:0;' .
				'if(!memberId){' .
					'if(errEl){errEl.textContent="' . esc_js( __( 'Could not create member. Please try again.', 'mcp-ai-wpoos-pro' ) ) . '";errEl.style.display="block";}' .
					'return;' .
				'}' .

				/*
				 * Auto-select the newly created member

			 */
				'var fromSettings=mvNewMemberFromSettings;' .
				'mvNewMemberFromSettings=false;' .
				'mvSelectMember(memberId,name);' .

				/*
				 * If triggered from Settings, navigate back to the settings tab

			 */
				'if(fromSettings){' .
					'var settingsBtn=document.getElementById("mv-nav-settings");' .
					'mvTab("settings",settingsBtn);' .
				'}' .
			'})' .
			'.catch(function(){' .
				'if(savingEl)savingEl.style.display="none";' .
				'if(errEl){errEl.textContent="' . esc_js( __( 'Network error. Please try again.', 'mcp-ai-wpoos-pro' ) ) . '";errEl.style.display="block";}' .
			'});' .
		'};' .

		/*
		 * Returns "normal" | "warning" | "alert"

		*/
		'function mvBpStatus(sys,dia){' .
			'if(!sys||!dia)return"";' .
			'if(sys>=180||dia>=120)return"alert";' .
			'if(sys>=140||dia>=90)return"warning";' .
			'if(sys<90||dia<60)return"warning";' .
			'return"normal";' .
		'}' .

		'function mvHrStatus(hr){' .
			'if(!hr)return"";' .
			'if(hr>150||hr<40)return"alert";' .
			'if(hr>100||hr<60)return"warning";' .
			'return"normal";' .
		'}' .

		'function mvSpo2Status(spo2){' .
			'if(!spo2)return"";' .
			'if(spo2<90)return"alert";' .
			'if(spo2<95)return"warning";' .
			'return"normal";' .
		'}' .

		'function mvTempStatus(t){' .
			'if(!t)return"";' .
			'if(t>=103||t<95)return"alert";' .
			'if(t>=100.4||t<97)return"warning";' .
			'return"normal";' .
		'}' .

		'function mvGlucoseStatus(g){' .
			'if(!g)return"";' .
			'if(g>=200||g<54)return"alert";' .
			'if(g>=140||g<70)return"warning";' .
			'return"normal";' .
		'}' .

		/*
		── Kidney status helpers ──

		*/

		/*
		 * EGFR → CKD stage label and severity

		*/
		'function mvEgfrStatus(v){' .
			'if(!v)return"";' .
			'if(v<15)return"alert";' . /* CKD Stage 5 */
			'if(v<30)return"alert";' . /* CKD Stage 4 */
			'if(v<45)return"warning";' . /* CKD Stage 3b */
			'if(v<60)return"warning";' . /* CKD Stage 3a */
			'return"normal";' . /* Stage 1-2 */
		'}' .
		'function mvEgfrStageLabel(v){' .
			'if(!v)return"";' .
			'if(v<15)return"' . esc_js( __( 'CKD Stage 5', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(v<30)return"' . esc_js( __( 'CKD Stage 4', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(v<45)return"' . esc_js( __( 'CKD Stage 3b', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(v<60)return"' . esc_js( __( 'CKD Stage 3a', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(v<90)return"' . esc_js( __( 'CKD Stage 2', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'return"' . esc_js( __( 'CKD Stage 1', 'mcp-ai-wpoos-pro' ) ) . '";' .
		'}' .
		'function mvCreatinineStatus(v){' .
			'if(!v)return"";' .
			'if(v>4)return"alert";' .
			'if(v>1.3)return"warning";' .
			'return"normal";' .
		'}' .
		'function mvBunStatus(v){' .
			'if(!v)return"";' .
			'if(v>50)return"alert";' .
			'if(v>30)return"warning";' .
			'return"normal";' .
		'}' .
		'function mvPotassiumStatus(v){' .
			'if(!v)return"";' .

			/*
			 * Hyperkalemia or severe hypokalemia

		 */
			'if(v>=6.0||v<3.0)return"alert";' .
			'if(v>=5.0||v<3.5)return"warning";' .
			'return"normal";' .
		'}' .
		'function mvSodiumStatus(v){' .
			'if(!v)return"";' .
			'if(v<125||v>155)return"alert";' .
			'if(v<130||v>150)return"warning";' .
			'return"normal";' .
		'}' .
		'function mvPhosphorusStatus(v){' .
			'if(!v)return"";' .
			'if(v>6.5||v<1.5)return"alert";' .
			'if(v>4.5||v<2.5)return"warning";' .
			'return"normal";' .
		'}' .
		'function mvAlbuminStatus(v){' .
			'if(!v)return"";' .
			'if(v<2.5)return"alert";' .
			'if(v<3.5)return"warning";' .
			'return"normal";' .
		'}' .

		'function mvStatusLabel(s){' .
			'if(s==="alert")return"' . esc_js( __( 'Alert', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(s==="warning")return"' . esc_js( __( 'Monitor', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(s==="normal")return"' . esc_js( __( 'Normal', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'return"";' .
		'}' .

		/*
		 * ── Tab switcher ──

		*/
		'window.mvTab=function(tab,btn){' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(p){p.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(b){b.classList.remove("tma-active");});' .
			'var pane=document.getElementById("mv-tab-"+tab);if(pane)pane.classList.add("tma-active");' .
			'if(btn)btn.classList.add("tma-active");' .
			'tmaHaptic("light");' .
			'if(tab==="dashboard")mvRefresh();' .
			'if(tab==="log")mvRenderLogHistory();' .
			'if(tab==="trends")mvRenderTrends();' .
			'if(tab==="dosage"){mvRenderMeds();mvFetchPrescriptions();}' .
			'if(tab==="settings")mvRenderSettings();' .
		'};' .

		/*
		 * ── Settings tab ──

		*/
		'window.mvRenderSettings=function(){' .

			/*
			 * Update data counts

		 */
			'var readingCountEl=document.getElementById("mv-settings-reading-count");' .
			'if(readingCountEl)readingCountEl.textContent=mvLoadReadings().length;' .
			'var medCountEl=document.getElementById("mv-settings-med-count");' .
			'if(medCountEl)medCountEl.textContent=mvLoadMeds().length;' .

			/*
			 * Restore display settings UI

		 */
			'mvRestoreDisplayUI();' .

			/*
			 * Fetch members and render inline member selector

		 */
			'var list=document.getElementById("mv-settings-member-list");' .
			'if(!list)return;' .
			'list.innerHTML=\'<div class="tma-member-msg">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"list_members",arguments:{per_page:50}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .

				/*
				Auth failure – keep the Loading… placeholder rather than showing
				 * 'No members yet' when the list may actually be non-empty.
				 */
				'if(!d){return;}' .
				'var members=d.result&&d.result.members?d.result.members:[];' .
				'if(!members.length){' .
					'list.innerHTML=\'<div class="tma-member-msg">' . esc_js( __( 'No members yet. Use "+ Add New Member" below to create one.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
					'return;' .
				'}' .
				'list.innerHTML=members.map(function(m){' .
					'var icon=m.type==="pet"?"&#128062;":"&#128100;";' .
					'var sel=m.id===MEMBER_ID?" selected":"";' .
					'return\'<div class="mv-settings-member-row\'+sel+\'" onclick="mvSettingsSelectMember(\'+m.id+\',\'+JSON.stringify(m.name)+\')">\'' .
						'+\'<div class="tma-member-card-icon">\'+icon+\'</div>\'' .
						'+\'<div><div class="tma-member-card-name">\'+escH(m.name)+\'</div>\'' .
						'+\'<div class="tma-member-card-type">\'+escH(m.type||"person")+\'</div></div>\'' .
						'+\'<span class="mv-settings-member-check">&#10003;</span>\'' .
						'+\'</div>\';' .
				'}).join("");' .
			'})' .
			'.catch(function(){' .
				'if(list)list.innerHTML=\'<div class="tma-member-msg">' . esc_js( __( 'Unable to load members. Please check your connection.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'});' .
		'};' .

		/*
		 * Select a member as default directly from the Settings tab

		*/
		'window.mvSettingsSelectMember=function(id,name){' .
			'MEMBER_ID=id;MEMBER_NAME=name;' .
			'try{localStorage.setItem("mv_member_id",JSON.stringify({id:id,name:name}));}catch(e){}' .

			/*
			 * Update header label

		 */
			'var lbl=document.getElementById("tma-mv-member-label");' .
			'if(lbl)lbl.textContent=name;' .

			/*
			 * Clear cached readings so fresh data is loaded for the new default member

		 */
			'try{localStorage.removeItem(SK_READINGS);}catch(e){}' .
			'tmaHaptic("success");' .

			/*
			 * Re-render the settings list to show the new selection, then sync

		 */
			'mvRenderSettings();' .
			'mvSyncFromServer();' .
			'mvFetchPrescriptions();' .
		'};' .

		/*
		 * Open new-member form from the Settings tab

		*/
		'window.mvSettingsAddMember=function(){' .
			'mvNewMemberFromSettings=true;' .
			'mvHideNewMemberForm();' .
			'mvShowMemberPicker();' .
			'mvShowNewMemberForm();' .
		'};' .

		'window.mvSettingsSyncServer=function(){' .
			'if(!MEMBER_ID){window.alert("' . esc_js( __( 'Please select a member first.', 'mcp-ai-wpoos-pro' ) ) . '");return;}' .
			'mvSyncFromServer();' .
			'tmaHaptic("success");' .
		'};' .

		'window.mvSettingsClearData=function(){' .
			'if(!window.confirm("' . esc_js( __( 'Clear all locally stored readings and medications? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '"))return;' .
			'try{localStorage.removeItem(SK_READINGS);localStorage.removeItem(SK_MEDS);}catch(e){}' .
			'tmaHaptic("medium");' .
			'mvRenderSettings();' .
		'};' .

		/*
		 * ── Display / font size settings ──

		*/
		'function mvApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=localStorage.getItem("mv_font_size")||"medium";' .
				'shell.classList.remove("mv-font-small","mv-font-large");' .
				'if(size==="small")shell.classList.add("mv-font-small");' .
				'else if(size==="large")shell.classList.add("mv-font-large");' .
				'var compact=localStorage.getItem("mv_compact")==="true";' .
				'if(compact)shell.classList.add("mv-compact");' .
				'else shell.classList.remove("mv-compact");' .
			'}catch(e){}' .
		'}' .

		'window.mvSetFontSize=function(size,btn){' .
			'try{localStorage.setItem("mv_font_size",size);}catch(e){}' .
			'document.querySelectorAll(".mv-font-btn").forEach(function(b){b.classList.remove("active");});' .
			'if(btn)btn.classList.add("active");' .
			'mvApplyDisplaySettings();' .
			'tmaHaptic("light");' .
		'};' .

		'window.mvToggleCompact=function(checked){' .
			'try{localStorage.setItem("mv_compact",checked?"true":"false");}catch(e){}' .
			'mvApplyDisplaySettings();' .
			'tmaHaptic("light");' .
		'};' .

		/*
		 * Restore display settings on render

		*/
		'function mvRestoreDisplayUI(){' .
			'try{' .
				'var size=localStorage.getItem("mv_font_size")||"medium";' .
				'document.querySelectorAll(".mv-font-btn").forEach(function(b){' .
					'b.classList.toggle("active",b.getAttribute("data-size")===size);' .
				'});' .
				'var compact=localStorage.getItem("mv_compact")==="true";' .
				'var toggle=document.getElementById("mv-compact-toggle");' .
				'if(toggle)toggle.checked=compact;' .
			'}catch(e){}' .
		'}' .

		/*
		 * ── Prescription fetching (Dosage tab) ──

		*/
		'window.mvFetchPrescriptions=function(){' .
			'var list=document.getElementById("mv-rx-list");if(!list)return;' .
			'if(!MEMBER_ID){list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'Select a member to view prescriptions.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'Loading prescriptions…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"list_prescriptions",arguments:{member_id:MEMBER_ID,per_page:50}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(!d||!d.result){list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'Unable to load prescriptions.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'var rxs=d.result.prescriptions||[];' .
				'if(!rxs.length){list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'No prescriptions found for this member.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'list.innerHTML=rxs.map(function(rx){' .
					'var st=rx.status||"active";' .
					'var stColor=st==="active"?"#2e7d32":st==="completed"?"#1565c0":st==="discontinued"?"#e65100":"#c62828";' .
					'return \'<div class="mv-rx-card" style="--mv-rx-color:\'+stColor+\'">\'+' .
						'\'<div class="mv-rx-header">\'+' .
							'\'<div class="mv-rx-icon">&#128138;</div>\'+' .
							'\'<div class="mv-rx-name">\'+escH(rx.medication_name||"")+\'</div>\'+' .
							'\'<span class="mv-rx-badge \'+st+\'">\'+escH(st)+\'</span>\'+' .
						'\'</div>\'+' .
						'(rx.dosage?\'<div class="mv-rx-detail">&#128137; ' . esc_js( __( 'Dosage:', 'mcp-ai-wpoos-pro' ) ) . ' \'+escH(rx.dosage)+\'</div>\':"")+' .
						'(rx.frequency?\'<div class="mv-rx-detail">&#128337; ' . esc_js( __( 'Frequency:', 'mcp-ai-wpoos-pro' ) ) . ' \'+escH(rx.frequency)+\'</div>\':"")+' .
						'(rx.prescribing_doctor?\'<div class="mv-rx-detail">&#129658; ' . esc_js( __( 'Doctor:', 'mcp-ai-wpoos-pro' ) ) . ' \'+escH(rx.prescribing_doctor)+\'</div>\':"")+' .
						'(rx.start_date?\'<div class="mv-rx-detail">&#128197; \'+escH(rx.start_date)+(rx.end_date?" \u2013 "+escH(rx.end_date):"")+\'</div>\':"")+' .
						'(rx.refills_remaining?\'<div class="mv-rx-detail">&#128260; ' . esc_js( __( 'Refills:', 'mcp-ai-wpoos-pro' ) ) . ' \'+escH(rx.refills_remaining)+\'</div>\':"")+' .
					'\'</div>\';' .
				'}).join("");' .
			'})' .
			'.catch(function(){' .
				'if(list)list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'Could not load prescriptions. Check your connection.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'});' .
		'};' .

		/*
		 * ── Log tab: history view ──

		*/
		'window.mvShowLogForm=function(){' .
			'var hist=document.getElementById("mv-log-history-view");' .
			'var form=document.getElementById("mv-log-form-view");' .
			'if(hist)hist.style.display="none";' .
			'if(form)form.style.display="block";' .
		'};' .
		'window.mvShowLogHistory=function(){' .
			'var hist=document.getElementById("mv-log-history-view");' .
			'var form=document.getElementById("mv-log-form-view");' .
			'if(form)form.style.display="none";' .
			'if(hist)hist.style.display="block";' .
			'mvRenderLogHistory();' .
		'};' .

		'window.mvRenderLogHistory=function(){' .
			'var list=document.getElementById("mv-log-history-list");if(!list)return;' .
			'var readings=mvLoadReadings();' .

			/*
			 * Group readings by date, only include dates that have real vital data

		 */
			'var byDay={};' .
			'readings.forEach(function(r){' .
				'var k=r.ts?r.ts.slice(0,10):"";if(!k)return;' .

				/*
				 * Check if this reading has at least one non-zero vital value

			 */
				'var hasData=r.bp_sys||r.bp_dia||r.hr||r.spo2||r.temp||r.glucose||' .
					'r.egfr||r.creatinine||r.bun||r.potassium||r.sodium||r.phosphorus||r.albumin||r.hemoglobin;' .
				'if(!hasData)return;' .
				'if(!byDay[k])byDay[k]=[];' .
				'byDay[k].push(r);' .
			'});' .
			'var dates=Object.keys(byDay).sort().reverse();' .
			'if(!dates.length){' .
				'list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'No vitals recorded yet. Tap "+ New Entry" to log your first reading.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
				'return;' .
			'}' .
			'list.innerHTML=dates.map(function(dk){' .
				'var entries=byDay[dk];' .
				'var r=entries[0];' . /* Use first entry for summary chips */

				/*
				 * Collect non-zero vital labels for chips

			 */
				'var chips=[];' .
				'entries.forEach(function(e){' .
					'if(e.bp_sys&&e.bp_dia)chips.push("BP "+e.bp_sys+"/"+e.bp_dia);' .
					'if(e.hr)chips.push("HR "+e.hr);' .
					'if(e.spo2)chips.push("SpO\u2082 "+e.spo2+"%");' .
					'if(e.temp)chips.push(e.temp+"\u00b0F");' .
					'if(e.glucose)chips.push("Gluc "+e.glucose);' .
					'if(e.egfr)chips.push("eGFR "+e.egfr);' .
					'if(e.creatinine)chips.push("Cr "+e.creatinine);' .
					'if(e.hemoglobin)chips.push("Hgb "+e.hemoglobin);' .
				'});' .

				/*
				 * Deduplicate chips

			 */
				'var seen={};chips=chips.filter(function(c){if(seen[c])return false;seen[c]=true;return true;});' .

				/*
				 * Format date for display

			 */
				'var dd=new Date(dk+"T12:00:00");' .
				'var dayStr=dd.toLocaleDateString(undefined,{weekday:"short",month:"short",day:"numeric"});' .

				/*
				 * Build detail section from all entries for that day

			 */
				'var detailRows=[];' .
				'entries.forEach(function(e){' .
					'if(e.bp_sys&&e.bp_dia)detailRows.push(["' . esc_js( __( 'Blood Pressure', 'mcp-ai-wpoos-pro' ) ) . '",e.bp_sys+"/"+e.bp_dia+" mmHg"]);' .
					'if(e.hr)detailRows.push(["' . esc_js( __( 'Heart Rate', 'mcp-ai-wpoos-pro' ) ) . '",e.hr+" bpm"]);' .
					'if(e.spo2)detailRows.push(["SpO\u2082",e.spo2+"%"]);' .
					'if(e.temp)detailRows.push(["' . esc_js( __( 'Temperature', 'mcp-ai-wpoos-pro' ) ) . '",e.temp+"\u00b0F"]);' .
					'if(e.glucose)detailRows.push(["' . esc_js( __( 'Glucose', 'mcp-ai-wpoos-pro' ) ) . '",e.glucose+" mg/dL"]);' .
					'if(e.egfr)detailRows.push(["eGFR",e.egfr+" mL/min"]);' .
					'if(e.creatinine)detailRows.push(["' . esc_js( __( 'Creatinine', 'mcp-ai-wpoos-pro' ) ) . '",e.creatinine+" mg/dL"]);' .
					'if(e.bun)detailRows.push(["BUN",e.bun+" mg/dL"]);' .
					'if(e.potassium)detailRows.push(["K\u207a",e.potassium+" mEq/L"]);' .
					'if(e.sodium)detailRows.push(["Na\u207a",e.sodium+" mEq/L"]);' .
					'if(e.phosphorus)detailRows.push(["' . esc_js( __( 'Phosphorus', 'mcp-ai-wpoos-pro' ) ) . '",e.phosphorus+" mg/dL"]);' .
					'if(e.albumin)detailRows.push(["' . esc_js( __( 'Albumin', 'mcp-ai-wpoos-pro' ) ) . '",e.albumin+" g/dL"]);' .
					'if(e.hemoglobin)detailRows.push(["' . esc_js( __( 'Hemoglobin', 'mcp-ai-wpoos-pro' ) ) . '",e.hemoglobin+" g/dL"]);' .
					'if(e.notes)detailRows.push(["' . esc_js( __( 'Notes', 'mcp-ai-wpoos-pro' ) ) . '",e.notes]);' .
				'});' .

				/*
				 * Deduplicate detail rows

			 */
				'var dSeen={};detailRows=detailRows.filter(function(dr){var key=dr[0]+":"+dr[1];if(dSeen[key])return false;dSeen[key]=true;return true;});' .
				'var detailHtml=detailRows.map(function(dr){' .
					'return\'<div class="mv-hist-detail-row"><span class="mv-hist-detail-label">\'+escH(dr[0])+\'</span><span class="mv-hist-detail-val">\'+escH(dr[1])+\'</span></div>\';' .
				'}).join("");' .
				'var cardId="mv-hist-"+dk;' .
				'return\'<div class="mv-hist-card" onclick="mvToggleHistDetail(\\\'\'+cardId+\'\\\')">\'+' .
					'\'<div class="mv-hist-date"><span class="mv-hist-date-icon">&#128197;</span>\'+escH(dayStr)+\'</div>\'+' .
					'\'<div class="mv-hist-chips">\'+chips.slice(0,5).map(function(c){return\'<span class="mv-hist-chip">\'+escH(c)+\'</span>\';}).join("")+\'</div>\'+' .
					'\'<div class="mv-hist-detail" id="\'+cardId+\'">\'+detailHtml+\'</div>\'+' .
				'\'</div>\';' .
			'}).join("");' .
		'};' .

		'window.mvToggleHistDetail=function(id){' .
			'var el=document.getElementById(id);if(!el)return;' .
			'el.classList.toggle("open");' .
			'tmaHaptic("light");' .
		'};' .

		/*
		 * ── Dashboard ──

		*/
		'window.mvRefresh=function(){' .
			'var readings=mvLoadReadings();' .
			'var latest=readings.length?readings[0]:null;' .

			/*
			 * Update last-read time

		 */
			'var lt=document.getElementById("mv-last-time");' .
			'if(lt){if(latest&&latest.ts){var d=new Date(latest.ts);lt.textContent="' . esc_js( __( 'Last reading: ', 'mcp-ai-wpoos-pro' ) ) . '"+d.toLocaleString();}else{lt.textContent="' . esc_js( __( 'No readings yet', 'mcp-ai-wpoos-pro' ) ) . '";}}' .

			/*
			Scan all readings to find the most recent non-zero value for each
			 * metric.  Records are often stored as separate rows per measurement
			 * type (e.g. one row for BP/HR/SpO2 and another for renal labs), so
			 * the chronologically last entry cannot reliably represent all KPIs
			 * on its own — matching the admin dashboard latestFor() pattern.
			 */
			'function mvLatestFor(field){' .
				'for(var i=0;i<readings.length;i++){' .
					'var v=parseFloat(readings[i][field]);' .
					'if(v>0)return v;' .
				'}' .
				'return 0;' .
			'}' .

			/*
			 * KPI cards

		 */
			'var g=document.getElementById("mv-kpi-grid");if(!g)return;' .
			'if(!readings.length){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'No readings yet. Log a reading to see your vitals here.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'var bpSys=mvLatestFor("bp_sys");var bpDia=mvLatestFor("bp_dia");' .
			'var hr=mvLatestFor("hr");var spo2=mvLatestFor("spo2");var temp=mvLatestFor("temp");var glucose=mvLatestFor("glucose");' .
			'var bpSt=mvBpStatus(bpSys,bpDia);' .
			'var hrSt=mvHrStatus(hr);' .
			'var spo2St=mvSpo2Status(spo2);' .
			'var tempSt=mvTempStatus(temp);' .
			'var glucoseSt=mvGlucoseStatus(glucose);' .
			'function kpiCard(color,icon,label,val,unit,status){' .
				'var sl=mvStatusLabel(status);' .
				'var badge=sl?\'<div class="mv-kpi-status mv-badge-\'+status+\'">\'+escH(sl)+\'</div>\':\'\'  ;' .
				'return \'<div class="mv-kpi" style="--mv-kpi-color:\'+color+\'">\'+' .
					'\'<div class="mv-kpi-icon">\'+icon+\'</div>\'+' .
					'\'<div class="mv-kpi-label">\'+escH(label)+\'</div>\'+' .
					'\'<div class="mv-kpi-val">\'+escH(val)+\'<span class="mv-kpi-unit"> \'+escH(unit)+\'</span></div>\'+' .
					'badge+' .
				'\'</div>\';' .
			'}' .
			'var bpColor=bpSt==="alert"?"#c62828":bpSt==="warning"?"#e65100":"#1565c0";' .
			'var hrColor=hrSt==="alert"?"#c62828":hrSt==="warning"?"#e65100":"#e53935";' .
			'var spo2Color=spo2St==="alert"?"#c62828":spo2St==="warning"?"#e65100":"#0277bd";' .
			'var tempColor=tempSt==="alert"?"#c62828":tempSt==="warning"?"#e65100":"#00796b";' .
			'var glucoseColor=glucoseSt==="alert"?"#c62828":glucoseSt==="warning"?"#e65100":"#6a1b9a";' .
			'var bpVal=bpSys&&bpDia?bpSys+"/"+bpDia:"--";' .
			'g.innerHTML=' .
				'kpiCard(bpColor,"&#129728;","' . esc_js( __( 'Blood Pressure', 'mcp-ai-wpoos-pro' ) ) . '",bpVal,"mmHg",bpSt)+' .
				'kpiCard(hrColor,"&#10084;","' . esc_js( __( 'Heart Rate', 'mcp-ai-wpoos-pro' ) ) . '",hr||"--","bpm",hrSt)+' .
				'kpiCard(spo2Color,"&#128164;","SpO\u2082",spo2||"--","%",spo2St)+' .
				'kpiCard(tempColor,"&#127777;","' . esc_js( __( 'Temperature', 'mcp-ai-wpoos-pro' ) ) . '",temp||"--","\u00b0F",tempSt)+' .
				'kpiCard(glucoseColor,"&#128137;","' . esc_js( __( 'Glucose', 'mcp-ai-wpoos-pro' ) ) . '",glucose||"--","mg/dL",glucoseSt);' .

			/*
			 * Kidney KPI section

		 */
			'var kg=document.getElementById("mv-kidney-kpi-grid");' .
			'if(kg){' .
				'var egfr=mvLatestFor("egfr");var creat=mvLatestFor("creatinine");var bun=mvLatestFor("bun");' .
				'var kpot=mvLatestFor("potassium");var kna=mvLatestFor("sodium");var phos=mvLatestFor("phosphorus");var alb=mvLatestFor("albumin");' .
				'var hgb=mvLatestFor("hemoglobin");' .
				'var hasKidney=egfr||creat||bun||kpot||kna||phos||alb||hgb;' .
				'if(!hasKidney){' .
					'kg.innerHTML=\'<div class="tma-empty" style="grid-column:span 2;padding:10px 0">' . esc_js( __( 'No lab values logged yet.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
				'}else{' .
					'var egfrSt=mvEgfrStatus(egfr);var creatSt=mvCreatinineStatus(creat);' .
					'var bunSt=mvBunStatus(bun);var potSt=mvPotassiumStatus(kpot);' .
					'var naSt=mvSodiumStatus(kna);var phosSt=mvPhosphorusStatus(phos);var albSt=mvAlbuminStatus(alb);' .
					'var hgbSt=hgb>=12?"normal":hgb>=11?"warning":"alert";' .
					'var egfrColor=egfrSt==="alert"?"#c62828":egfrSt==="warning"?"#e65100":"#1565c0";' .
					'var creatColor=creatSt==="alert"?"#c62828":creatSt==="warning"?"#e65100":"#00796b";' .
					'var bunColor=bunSt==="alert"?"#c62828":bunSt==="warning"?"#e65100":"#00796b";' .
					'var potColor=potSt==="alert"?"#c62828":potSt==="warning"?"#e65100":"#5e35b1";' .
					'var naColor=naSt==="alert"?"#c62828":naSt==="warning"?"#e65100":"#0277bd";' .
					'var phosColor=phosSt==="alert"?"#c62828":phosSt==="warning"?"#e65100":"#558b2f";' .
					'var albColor=albSt==="alert"?"#c62828":albSt==="warning"?"#e65100":"#4527a0";' .
					'var hgbColor=hgbSt==="alert"?"#c62828":hgbSt==="warning"?"#e65100":"#b71c1c";' .
					'var egfrLabel=egfr?mvEgfrStageLabel(egfr):"--";' .
					'kg.innerHTML=' .
						'(egfr?kpiCard(egfrColor,"&#129506;","eGFR",egfr,"mL/min",egfrSt)+\'<div style="grid-column:span 2;margin:-8px 0 4px;font-size:11px;color:\'+egfrColor+\';font-weight:600;padding-left:4px">&#9679; \'+escH(egfrLabel)+\'</div>\':"")  +' .
						'(creat?kpiCard(creatColor,"&#129514;","' . esc_js( __( 'Creatinine', 'mcp-ai-wpoos-pro' ) ) . '",creat,"mg/dL",creatSt):"")  +' .
						'(bun?kpiCard(bunColor,"&#129514;","BUN",bun,"mg/dL",bunSt):"")  +' .
						'(kpot?kpiCard(potColor,"&#9889;","K\u207a ' . esc_js( __( 'Potassium', 'mcp-ai-wpoos-pro' ) ) . '",kpot,"mEq/L",potSt):"")  +' .
						'(kna?kpiCard(naColor,"&#9889;","Na\u207a ' . esc_js( __( 'Sodium', 'mcp-ai-wpoos-pro' ) ) . '",kna,"mEq/L",naSt):"")  +' .
						'(phos?kpiCard(phosColor,"&#129514;","' . esc_js( __( 'Phosphorus', 'mcp-ai-wpoos-pro' ) ) . '",phos,"mg/dL",phosSt):"")  +' .
						'(alb?kpiCard(albColor,"&#129514;","' . esc_js( __( 'Albumin', 'mcp-ai-wpoos-pro' ) ) . '",alb,"g/dL",albSt):"")  +' .
						'(hgb?kpiCard(hgbColor,"&#129978;","' . esc_js( __( 'Hemoglobin', 'mcp-ai-wpoos-pro' ) ) . '",hgb,"g/dL",hgbSt):"")  ;' .
				'}' .
			'}' .

			/*
			 * Mini sparkline chart

		 */
			'mvLoadDashChart();' .
		'};' .

		/*
		 * Dash chart – systolic BP sparkline

		*/
		'var dashChartInst=null;' .
		'var mvDashChartDays=7;' .
		'function mvLoadDashChart(){' .
			'if(window.Chart){mvRenderDashChart();return;}' .
			'if(!CHART_JS_URL)return;' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;' .
			's.onload=function(){mvRenderDashChart();};document.head.appendChild(s);' .
		'}' .

		'function mvRenderDashChart(){' .
			'var cw=document.getElementById("mv-dash-chart");if(!cw)return;' .
			'var hist=mvLoadHistory(mvDashChartDays);' .
			'var labels=hist.map(function(h){return h.date?h.date.slice(5):"";});' .
			'var sysData=hist.map(function(h){return h.bp_sys||null;});' .
			'var diaData=hist.map(function(h){return h.bp_dia||null;});' .
			'cw.innerHTML=\'<div class="mv-chart-card"><div class="mv-chart-title">&#129728; ' . esc_js( __( 'Blood Pressure', 'mcp-ai-wpoos-pro' ) ) . ' \u2014 \'+mvDashChartDays+\' ' . esc_js( __( 'Days', 'mcp-ai-wpoos-pro' ) ) . '<span class="mv-chart-range">' . esc_js( __( 'Normal <120/80', 'mcp-ai-wpoos-pro' ) ) . '</span></div><canvas id="mv-dash-bp-canvas" height="130"></canvas></div>\';' .
			'if(!window.Chart)return;' .
			'var c=document.getElementById("mv-dash-bp-canvas");if(!c)return;' .
			'if(dashChartInst)dashChartInst.destroy();' .
			'dashChartInst=new Chart(c,{type:"line",data:{labels:labels,datasets:[' .
				'{label:"' . esc_js( __( 'Systolic', 'mcp-ai-wpoos-pro' ) ) . '",data:sysData,borderColor:"#e53935",backgroundColor:"#e5393522",tension:.4,fill:false,pointRadius:3,spanGaps:true},' .
				'{label:"' . esc_js( __( 'Diastolic', 'mcp-ai-wpoos-pro' ) ) . '",data:diaData,borderColor:"#1565c0",backgroundColor:"#1565c022",tension:.4,fill:false,pointRadius:3,spanGaps:true}' .
			']},options:{responsive:true,plugins:{legend:{labels:{font:{size:11},boxWidth:10}}},' .
				'scales:{x:{ticks:{font:{size:10},color:"#999"}},y:{ticks:{font:{size:10},color:"#999"},beginAtZero:false,suggestedMin:60,suggestedMax:180,grid:{color:"rgba(0,0,0,.06)"}}}' .
			'}});' .
		'}' .

		/*
		 * Dashboard chart range selector

		*/
		'window.mvSetDashChartRange=function(days,btn){' .
			'mvDashChartDays=days;' .
			'document.querySelectorAll("#mv-dash-range-bar .mv-range-btn").forEach(function(b){b.classList.remove("active");b.setAttribute("aria-pressed","false");});' .
			'if(btn){btn.classList.add("active");btn.setAttribute("aria-pressed","true");}' .
			'tmaHaptic("light");' .
			'mvRenderDashChart();' .
		'};' .

		/*
		 * ── Log tab ──

		*/
		'window.mvSaveReading=function(){' .
			'var sys=parseInt((document.getElementById("mv-bp-sys")||{}).value||"",10)||0;' .
			'var dia=parseInt((document.getElementById("mv-bp-dia")||{}).value||"",10)||0;' .
			'var hr=parseInt((document.getElementById("mv-hr")||{}).value||"",10)||0;' .
			'var spo2=parseInt((document.getElementById("mv-spo2")||{}).value||"",10)||0;' .
			'var temp=parseFloat((document.getElementById("mv-temp")||{}).value||"")||0;' .
			'var glucose=parseInt((document.getElementById("mv-glucose")||{}).value||"",10)||0;' .

			/*
			 * Kidney fields

		 */
			'var egfr=parseFloat((document.getElementById("mv-egfr")||{}).value||"")||0;' .
			'var creatinine=parseFloat((document.getElementById("mv-creatinine")||{}).value||"")||0;' .
			'var bun=parseFloat((document.getElementById("mv-bun")||{}).value||"")||0;' .
			'var potassium=parseFloat((document.getElementById("mv-potassium")||{}).value||"")||0;' .
			'var sodium=parseFloat((document.getElementById("mv-sodium")||{}).value||"")||0;' .
			'var phosphorus=parseFloat((document.getElementById("mv-phosphorus")||{}).value||"")||0;' .
			'var albumin=parseFloat((document.getElementById("mv-albumin")||{}).value||"")||0;' .
			'var hemoglobin=parseFloat((document.getElementById("mv-hemoglobin")||{}).value||"")||0;' .
			'var notes=((document.getElementById("mv-notes")||{}).value||"").trim();' .

			/*
			 * Require at least one field

		 */
			'if(!sys&&!hr&&!spo2&&!temp&&!glucose&&!egfr&&!creatinine&&!bun&&!potassium&&!sodium&&!phosphorus&&!albumin&&!hemoglobin)return;' .
			'var reading={ts:new Date().toISOString(),bp_sys:sys,bp_dia:dia,hr:hr,spo2:spo2,temp:temp,glucose:glucose,' .
				'egfr:egfr,creatinine:creatinine,bun:bun,potassium:potassium,sodium:sodium,phosphorus:phosphorus,albumin:albumin,' .
				'hemoglobin:hemoglobin,notes:notes};' .
			'var arr=mvLoadReadings();arr.unshift(reading);if(arr.length>200)arr=arr.slice(0,200);' .
			'mvStoreReadings(arr);tmaHaptic("success");' .

			/*
			 * Clear fields

		 */
			'["mv-bp-sys","mv-bp-dia","mv-hr","mv-spo2","mv-temp","mv-glucose","mv-egfr","mv-creatinine","mv-bun","mv-potassium","mv-sodium","mv-phosphorus","mv-albumin","mv-hemoglobin","mv-notes"].forEach(function(id){var el=document.getElementById(id);if(el)el.value="";});' .

			/*
			 * Show saved message, then switch back to history view

		 */
			'var msg=document.getElementById("mv-log-saved");if(msg){msg.style.display="block";setTimeout(function(){msg.style.display="none";mvShowLogHistory();},3000);}' .

			/*
			 * Server sync via log_vital_signs tool when a member is resolved

		 */
			'if(TOOLS_EXEC&&MEMBER_ID>0){' .
				'var sArgs={action:"log",member_id:MEMBER_ID,source:"tma",' .
					'measurement_date:reading.ts?reading.ts.slice(0,10):"",' .
					'measurement_time:reading.ts?reading.ts.slice(11,16):""};' .
				'if(reading.bp_sys&&reading.bp_dia){sArgs.blood_pressure_systolic=reading.bp_sys;sArgs.blood_pressure_diastolic=reading.bp_dia;}' .
				'if(reading.hr)sArgs.heart_rate=reading.hr;' .
				'if(reading.spo2)sArgs.oxygen_saturation=reading.spo2;' .
				'if(reading.temp)sArgs.temperature=reading.temp;' .
				'if(reading.glucose)sArgs.blood_glucose=reading.glucose;' .
				'if(reading.egfr)sArgs.egfr=reading.egfr;' .
				'if(reading.creatinine)sArgs.creatinine=reading.creatinine;' .
				'if(reading.bun)sArgs.bun=reading.bun;' .
				'if(reading.potassium)sArgs.potassium=reading.potassium;' .
				'if(reading.sodium)sArgs.sodium=reading.sodium;' .
				'if(reading.phosphorus)sArgs.phosphorus=reading.phosphorus;' .
				'if(reading.albumin)sArgs.albumin=reading.albumin;' .
				'if(reading.hemoglobin)sArgs.hemoglobin=reading.hemoglobin;' .
				'if(reading.notes)sArgs.notes=reading.notes;' .
				'fetch(TOOLS_EXEC,{method:"POST",' .
					'headers:tmaToolHeaders(),' .
					'body:JSON.stringify({slug:"log_vital_signs",arguments:sArgs})' .
				'}).catch(function(){});' .
			'}' .
		'};' .

		/*
		── Pull server history into localStorage on first load ──

		*/

		/*
		Normalise a server row to the local schema.
		 * Handles both flat CCT format (measurement_date, bp_systolic …)
		 * and nested options format (date, measurements.blood_pressure.systolic …).
		 */
		'function mvNormaliseRow(row){' .
			'function pf(v){var n=parseFloat(v);return isNaN(n)?0:n;}' .
			'var m=row.measurements||{};var bp=m.blood_pressure||{};' .
			'return{ts:(row.measurement_date||row.date||"")+"T"+String(row.measurement_time||row.time||"00:00").slice(0,5)+":00.000Z",' .
				'bp_sys:pf(row.bp_systolic)||pf(bp.systolic),' .
				'bp_dia:pf(row.bp_diastolic)||pf(bp.diastolic),' .
				'hr:pf(row.heart_rate)||pf(m.heart_rate&&m.heart_rate.value),' .
				'spo2:pf(row.oxygen_saturation)||pf(m.oxygen_saturation&&m.oxygen_saturation.value),' .
				'temp:pf(row.temperature)||pf(m.temperature&&m.temperature.value),' .
				'glucose:pf(row.blood_glucose)||pf(m.blood_glucose&&m.blood_glucose.value),' .
				'egfr:pf(row.egfr)||pf(m.egfr&&m.egfr.value),' .
				'creatinine:pf(row.creatinine)||pf(m.creatinine&&m.creatinine.value),' .
				'bun:pf(row.bun)||pf(m.bun&&m.bun.value),' .
				'potassium:pf(row.potassium)||pf(m.potassium&&m.potassium.value),' .
				'sodium:pf(row.sodium)||pf(m.sodium&&m.sodium.value),' .
				'phosphorus:pf(row.phosphorus)||pf(m.phosphorus&&m.phosphorus.value),' .
				'albumin:pf(row.albumin)||pf(m.albumin&&m.albumin.value),' .
				'hemoglobin:pf(row.hemoglobin)||pf(m.hemoglobin&&m.hemoglobin.value),' .
				'notes:row.notes||""};' .
		'}' .

		'function mvSyncFromServer(){' .
			'if(!TOOLS_EXEC||!MEMBER_ID)return;' .

			/*
			 * Fetch 90-day history for trends/charts

		 */
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"log_vital_signs",arguments:{action:"get_history",member_id:MEMBER_ID,days_back:90}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(!d||!d.result||!d.result.history||!d.result.history.length)return;' .
				'var serverRows=d.result.history.map(mvNormaliseRow);' .

				/*
				 * Merge: server rows keyed by day, local-only days preserved

			 */
				'var local=mvLoadReadings();' .
				'var byDay={};' .
				'serverRows.forEach(function(r){var k=r.ts?r.ts.slice(0,10):"";if(k)byDay[k]=r;});' .
				'local.forEach(function(r){var k=r.ts?r.ts.slice(0,10):"";if(k&&!byDay[k])byDay[k]=r;});' .
				'var merged=Object.keys(byDay).sort().reverse().map(function(k){return byDay[k];});' .
				'mvStoreReadings(merged);' .
				'mvRefresh();' .
			'})' .
			'.catch(function(){});' .

			/*
			Fetch the single most-recent reading with NO time restriction
			 * so the dashboard always shows the last record even if it is
			 * older than 90 days.
			 */
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:tmaToolHeaders(),' .
				'body:JSON.stringify({slug:"log_vital_signs",arguments:{action:"get_latest",member_id:MEMBER_ID}})' .
			'})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){' .
				'if(!d||!d.result||!d.result.latest)return;' .
				'var entry=mvNormaliseRow(d.result.latest);' .
				'var k=entry.ts?entry.ts.slice(0,10):"";' .
				'if(!k)return;' .

				/*
				 * Merge into localStorage only if that day is not already present

			 */
				'var local=mvLoadReadings();' .
				'var exists=local.some(function(r){return r.ts&&r.ts.slice(0,10)===k;});' .
				'if(!exists){' .
					'local.push(entry);' .
					'local.sort(function(a,b){return(b.ts||"").localeCompare(a.ts||"");});' .
					'mvStoreReadings(local);' .
					'mvRefresh();' .
				'}' .
			'})' .
			'.catch(function(){});' .
		'}' .

		/*
		 * ── Trends tab ──

		*/
		'var trendsChartInsts=[];' .
		'var mvTrendsDays=7;' .

		'window.mvSetTrendRange=function(days,btn){' .
			'mvTrendsDays=days;' .
			'document.querySelectorAll("#mv-tab-trends .mv-range-btn").forEach(function(b){b.classList.remove("active");b.setAttribute("aria-pressed","false");});' .
			'if(btn){btn.classList.add("active");btn.setAttribute("aria-pressed","true");}' .
			'var tt=document.getElementById("mv-trends-title");' .
			'if(tt)tt.textContent=days+"' . esc_js( __( '-Day Trends', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'tmaHaptic("light");' .
			'mvRenderTrends();' .
		'};' .

		'function mvRenderTrends(){' .
			'if(window.Chart){mvBuildTrendCharts();return;}' .
			'if(!CHART_JS_URL){mvBuildTrendCharts();return;}' .
			'var s=document.createElement("script");s.src=CHART_JS_URL;' .
			's.onload=function(){mvBuildTrendCharts();};document.head.appendChild(s);' .
		'}' .

		'function mvBuildTrendCharts(){' .
			'trendsChartInsts.forEach(function(c){if(c)c.destroy();});trendsChartInsts=[];' .
			'var hist=mvLoadHistory(mvTrendsDays);' .
			'var labels=hist.map(function(h){return h.date?h.date.slice(5):"";});' .
			'var cw=document.getElementById("mv-trends-content");if(!cw)return;' .

			'var charts=[' .
				'{id:"mv-tc-bp",icon:"&#129728;",title:"' . esc_js( __( 'Blood Pressure', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( 'Normal <120/80', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[' .
						'{label:"' . esc_js( __( 'Systolic', 'mcp-ai-wpoos-pro' ) ) . '",data:hist.map(function(h){return h.bp_sys||null;}),color:"#e53935"},' .
						'{label:"' . esc_js( __( 'Diastolic', 'mcp-ai-wpoos-pro' ) ) . '",data:hist.map(function(h){return h.bp_dia||null;}),color:"#1565c0"}' .
					']},' .
				'{id:"mv-tc-hr",icon:"&#10084;",title:"' . esc_js( __( 'Heart Rate', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( '60–100 bpm', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"bpm",data:hist.map(function(h){return h.hr||null;}),color:"#e53935"}]},' .
				'{id:"mv-tc-spo2",icon:"&#128164;",title:"SpO\u2082",range:"' . esc_js( __( 'Normal ≥95%', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"%",data:hist.map(function(h){return h.spo2||null;}),color:"#0277bd"}]},' .
				'{id:"mv-tc-temp",icon:"&#127777;",title:"' . esc_js( __( 'Temperature', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( '97–99 °F', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"\u00b0F",data:hist.map(function(h){return h.temp||null;}),color:"#00796b"}]},' .
				'{id:"mv-tc-glucose",icon:"&#128137;",title:"' . esc_js( __( 'Glucose', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( 'Fasting 70–99 mg/dL', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"mg/dL",data:hist.map(function(h){return h.glucose||null;}),color:"#6a1b9a"}]},' .

				/*
				 * ── Kidney charts ──

			 */
				'{id:"mv-tc-egfr",icon:"&#129506;",title:"eGFR",range:"' . esc_js( __( 'Normal ≥60 mL/min', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"mL/min",data:hist.map(function(h){return h.egfr||null;}),color:"#1565c0"}]},' .
				'{id:"mv-tc-creat",icon:"&#129514;",title:"' . esc_js( __( 'Creatinine', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( 'Normal 0.6–1.2 mg/dL', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"mg/dL",data:hist.map(function(h){return h.creatinine||null;}),color:"#00796b"}]},' .
				'{id:"mv-tc-bun",icon:"&#129514;",title:"BUN",range:"' . esc_js( __( 'Normal 7–20 mg/dL', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"mg/dL",data:hist.map(function(h){return h.bun||null;}),color:"#558b2f"}]},' .
				'{id:"mv-tc-electrolytes",icon:"&#9889;",title:"' . esc_js( __( 'Electrolytes', 'mcp-ai-wpoos-pro' ) ) . '",range:"K\u207a 3.5\u20135.0 | Na\u207a 136\u2013145",' .
					'datasets:[' .
						'{label:"K\u207a (mEq/L)",data:hist.map(function(h){return h.potassium||null;}),color:"#5e35b1"},' .
						'{label:"Na\u207a \xf710 (mEq/L)",data:hist.map(function(h){return h.sodium?h.sodium/10:null;}),color:"#0288d1"}' .
					']},' .
				'{id:"mv-tc-phos",icon:"&#129514;",title:"' . esc_js( __( 'Phosphorus', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( 'Normal 2.5–4.5 mg/dL', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"mg/dL",data:hist.map(function(h){return h.phosphorus||null;}),color:"#ef6c00"}]},' .
				'{id:"mv-tc-alb",icon:"&#129514;",title:"' . esc_js( __( 'Albumin', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( 'Normal 3.5–5.0 g/dL', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"g/dL",data:hist.map(function(h){return h.albumin||null;}),color:"#4527a0"}]},' .
				'{id:"mv-tc-hgb",icon:"&#129978;",title:"' . esc_js( __( 'Hemoglobin', 'mcp-ai-wpoos-pro' ) ) . '",range:"' . esc_js( __( 'Normal ≥12 g/dL', 'mcp-ai-wpoos-pro' ) ) . '",' .
					'datasets:[{label:"g/dL",data:hist.map(function(h){return h.hemoglobin||null;}),color:"#b71c1c"}]}' .
			'];' .

			'cw.innerHTML=charts.map(function(ch){' .
				'return \'<div class="mv-chart-card"><div class="mv-chart-title">\'+ch.icon+" "+escH(ch.title)+\'<span class="mv-chart-range">\'+escH(ch.range)+\'</span></div><canvas id="\'+ch.id+\'" height="120"></canvas></div>\';' .
			'}).join("");' .

			'if(!window.Chart)return;' .

			'var pRadius=mvTrendsDays>30?1:mvTrendsDays>14?2:3;' .
			'var maxTicks=mvTrendsDays>30?10:mvTrendsDays>14?14:undefined;' .

			'charts.forEach(function(ch){' .
				'var el=document.getElementById(ch.id);if(!el)return;' .
				'var inst=new Chart(el,{type:"line",data:{labels:labels,datasets:ch.datasets.map(function(ds){' .
					'return{label:ds.label,data:ds.data,borderColor:ds.color,backgroundColor:ds.color+"22",tension:.4,fill:false,pointRadius:pRadius,spanGaps:true};' .
				'})},options:{responsive:true,' .
					'plugins:{legend:{display:ch.datasets.length>1,labels:{font:{size:10},boxWidth:8}}},' .
					'scales:{x:{ticks:{font:{size:10},color:"#999",maxTicksLimit:maxTicks,maxRotation:45}},y:{ticks:{font:{size:10},color:"#999"},beginAtZero:false,grid:{color:"rgba(0,0,0,.06)"}}}' .
				'}});' .
				'trendsChartInsts.push(inst);' .
			'});' .
		'}' .

		/*
		 * ── Dosage tab ──

		*/
		'window.mvToggleAddMed=function(){' .
			'var f=document.getElementById("mv-add-med-form");' .
			'if(f){f.style.display=f.style.display==="block"?"none":"block";}' .
			'tmaHaptic("light");' .
		'};' .

		'window.mvAddMed=function(){' .
			'var name=((document.getElementById("mv-med-name")||{}).value||"").trim();' .
			'if(!name)return;' .
			'var dose=((document.getElementById("mv-med-dose")||{}).value||"").trim();' .
			'var freq=((document.getElementById("mv-med-freq")||{}).value||"").trim();' .
			'var notes=((document.getElementById("mv-med-notes")||{}).value||"").trim();' .
			'var meds=mvLoadMeds();' .
			'meds.push({id:Date.now(),name:name,dose:dose,freq:freq,notes:notes,taken_today:false,taken_ts:""});' .
			'mvStoreMeds(meds);tmaHaptic("success");' .
			'["mv-med-name","mv-med-dose","mv-med-freq","mv-med-notes"].forEach(function(id){var el=document.getElementById(id);if(el)el.value="";});' .
			'var f=document.getElementById("mv-add-med-form");if(f)f.style.display="none";' .
			'mvRenderMeds();' .
		'};' .

		'window.mvToggleTaken=function(id){' .
			'var meds=mvLoadMeds();' .
			'var todayKey=mvTodayKey();' .
			'meds=meds.map(function(m){' .
				'if(m.id===id){' .
					'var alreadyTaken=m.taken_ts&&m.taken_ts.slice(0,10)===todayKey;' .
					'return Object.assign({},m,{taken_today:!alreadyTaken,taken_ts:alreadyTaken?"":new Date().toISOString()});' .
				'}return m;' .
			'});' .
			'mvStoreMeds(meds);tmaHaptic("light");' .

			/*
			 * Optional server sync

		 */
			'var med=meds.find(function(m){return m.id===id;});' .
			'if(med){fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),' .
				'body:JSON.stringify({tool:"log_medication_taken",arguments:{medication:med.name,dose:med.dose,taken:med.taken_today,ts:med.taken_ts}})' .
			'}).catch(function(){});}' .
			'mvRenderMeds();' .
		'};' .

		'window.mvDeleteMed=function(id){' .
			'var meds=mvLoadMeds().filter(function(m){return m.id!==id;});' .
			'mvStoreMeds(meds);tmaHaptic("light");mvRenderMeds();' .
		'};' .

		'window.mvRenderMeds=function(){' .
			'var meds=mvLoadMeds();' .
			'var list=document.getElementById("mv-med-list");if(!list)return;' .
			'var todayKey=mvTodayKey();' .
			'if(!meds.length){list.innerHTML=\'<div class="mv-empty">' . esc_js( __( 'No medications added yet.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'list.innerHTML=meds.map(function(m){' .
				'var takenToday=m.taken_ts&&m.taken_ts.slice(0,10)===todayKey;' .
				'var takenLabel=takenToday?"' . esc_js( __( '✓ Taken', 'mcp-ai-wpoos-pro' ) ) . '":"' . esc_js( __( 'Mark Taken', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'var takenClass=takenToday?" taken":"";' .
				'return \'<div class="mv-med-card">\'+' .
					'\'<div class="mv-med-header"><div class="mv-med-icon">&#128138;</div>\'+' .
					'\'<div><div class="mv-med-name">\'+escH(m.name)+\'</div><div class="mv-med-dose">\'+escH(m.dose)+\'</div></div></div>\'+' .
					'(m.freq?\'<div class="mv-med-schedule">&#128337; \'+escH(m.freq)+\'</div>\':"")+' .
					'(m.notes?\'<div class="mv-med-schedule">&#8505; \'+escH(m.notes)+\'</div>\':"")+' .
					'\'<div class="mv-med-actions">\'+' .
						'\'<button class="mv-med-btn\'+takenClass+\'" onclick="mvToggleTaken(\'+m.id+\')">\'+escH(takenLabel)+\'</button>\'+' .
						'\'<button class="mv-med-btn" style="color:#c62828;border-color:#c62828" onclick="mvDeleteMed(\'+m.id+\')">' . esc_js( __( 'Remove', 'mcp-ai-wpoos-pro' ) ) . '</button>\'+' .
					'\'</div></div>\';' .
			'}).join("");' .
		'};' .

		/*
		 * ── Doctor tab ──

		*/
		'window.mvDoctorSend=function(){' .
			'var inp=document.getElementById("mv-doctor-input");if(!inp)return;' .
			'var msg=inp.value.trim();if(!msg)return;inp.value="";tmaHaptic("medium");' .
			'var msgs=document.getElementById("mv-doctor-msgs");' .
			'if(msgs){var um=document.createElement("div");um.className="mv-doctor-msg user";um.textContent=msg;msgs.appendChild(um);msgs.scrollTop=msgs.scrollHeight;}' .
			'var loadEl=null;' .
			'if(msgs){loadEl=document.createElement("div");loadEl.className="mv-doctor-msg bot";loadEl.textContent="' . esc_js( __( '…', 'mcp-ai-wpoos-pro' ) ) . '";msgs.appendChild(loadEl);msgs.scrollTop=msgs.scrollHeight;}' .

			/*
			 * Prepend patient vitals context as first turn when history is empty.

		 */
			'var readings=mvLoadReadings();var latest=readings.length?readings[0]:{};' .
			'if(!doctorHist.length&&latest&&Object.keys(latest).length){' .
				'var vitCtx="[Patient vitals context]"' .
				'+" BP: "+(latest.bp_sys||"?")+"/"+(latest.bp_dia||"?")+" mmHg"' .
				'+", HR: "+(latest.hr||"?")+" bpm"' .
				'+", SpO2: "+(latest.spo2||"?")+"%"' .
				'+", Temp: "+(latest.temp||"?")+"\u00b0"' .
				'+", Glucose: "+(latest.glucose||"?")+" mg/dL"' .
				'+", eGFR: "+(latest.egfr||"?")+" mL/min"' .
				'+", Creatinine: "+(latest.creatinine||"?")+" mg/dL"' .
				'+", BUN: "+(latest.bun||"?")+" mg/dL"' .
				'+", K+: "+(latest.potassium||"?")+" mEq/L"' .
				'+", Na+: "+(latest.sodium||"?")+" mEq/L"' .
				'+", Phosphorus: "+(latest.phosphorus||"?")+" mg/dL"' .
				'+", Albumin: "+(latest.albumin||"?")+" g/dL"' .
				'+", Hemoglobin: "+(latest.hemoglobin||"?")+" g/dL"' .
				'+(latest.notes?". Notes: "+latest.notes:"")+".";' .
				'doctorHist.push({role:"user",content:vitCtx});' .
				'doctorHist.push({role:"assistant",content:"' . esc_js( __( 'I have your latest vitals on file. How can I help you today?', 'mcp-ai-wpoos-pro' ) ) . '"});' .
			'}' .
			'doctorHist.push({role:"user",content:msg});' .
			'var body={messages:doctorHist.slice(-20)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var hdrs={"Content-Type":"application/json","X-WP-Nonce":NONCE};' .
			'if(TMA_TOKEN){hdrs["X-WP-MCP-AI-TMA-Token"]=TMA_TOKEN;}' .
			'mvLoadMarkdown(function(){' .
			'fetch(CHAT_URL,{method:"POST",' .
				'headers:hdrs,' .
				'body:JSON.stringify(body)' .
			'})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data?d.data:{};' .
				'var reply=(data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)' .
					'||(data.content)||(data.response)||"' . esc_js( __( 'I\'m unable to retrieve a response right now. Please consult your healthcare provider for medical advice.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'mvRenderReply(loadEl,reply);' .
				'doctorHist.push({role:"assistant",content:reply});' .
			'}).catch(function(){' .
				'var tips=["' . esc_js( __( 'Monitor your blood pressure regularly and note any significant changes. 📊', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Stay consistent with your medication schedule for best treatment outcomes. 💊', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Track your vitals at the same time each day for more accurate trends. ⏰', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Always consult your doctor before adjusting any treatment plan. 🩺', 'mcp-ai-wpoos-pro' ) ) . '"];' .
				'mvRenderReply(loadEl,tips[Math.floor(Math.random()*tips.length)]);' .
			'}).finally(function(){if(msgs)msgs.scrollTop=msgs.scrollHeight;});' .
			'});' .
		'};' .

		/*
		── Init ──

		*/

		/*
		Helper: hide the member picker overlay and update the header label.

		*/

		/*
		 * Called from both the localStorage branch and the server-ID branch.

		*/
		'function mvActivateMember(){' .
			'mvHideMemberPicker();' .
			'var lbl=document.getElementById("tma-mv-member-label");' .
			'if(lbl&&MEMBER_NAME)lbl.textContent=MEMBER_NAME;' .
		'}' .

		/*
		Priority order for member selection:

		*/

		/*
			1. localStorage (fastest – avoids any flicker)

		 */

		/*
			2. SERVER_MEMBER_ID (server resolved the WP user's linked member)

		 */

		/*
		 *  3. Show member picker (user must choose or create)

		*/
		'mvLoadSavedMember();' .
		'if(MEMBER_ID){' .
			'mvActivateMember();' .
		'}else if(SERVER_MEMBER_ID){' .

			/*
			Server already knows which member belongs to this user – auto-select
			 * without showing the picker so vitals data loads immediately.
			 */
			'MEMBER_ID=SERVER_MEMBER_ID;MEMBER_NAME=SERVER_MEMBER_NAME;' .
			'try{localStorage.setItem("mv_member_id",JSON.stringify({id:MEMBER_ID,name:MEMBER_NAME}));}catch(e){}' .
			'mvActivateMember();' .
		'}else{' .
			'mvShowMemberPicker();' .
		'}' .
		'mvApplyDisplaySettings();' .
		'mvInitSession();mvRefresh();mvRenderMeds();' .
		'if(MEMBER_ID){mvSyncFromServer();mvFetchPrescriptions();}' .
		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/*
==========================================================================
	WooCommerce Shop React SPA Template
	==========================================================================

 */

/**
 * WooCommerce Shop Mini App template – React SPA.
 *
 * Renders an HTML shell that mounts the compiled React SPA
 * (addons/pro/build/tma-woo-shop/tma-woo-shop.js) into a root div.
 *
 * The PHP context is serialised into `window.wpTmaWooConfig` so the React
 * app can reach the plugin's REST endpoints and knows which WooCommerce data
 * source to use:
 *
 *   wooSource         – 'local' | 'remote'
 *   wooConnectionId   – remote connection ID (only when wooSource === 'remote')
 *
 * When wooSource is 'local', the React app calls the built-in local WooCommerce
 * tool endpoints (get_woo_products, get_woo_recent_orders).
 * When wooSource is 'remote', every fetch goes through the remote_wp_connection
 * tool with the supplied connection ID.
 *
 * @since 1.1.5
 */
class WP_MCP_AI_TMA_Template_Woo_Shop extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'woo_shop';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'WooCommerce Shop (React)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Full-featured React SPA with product catalog, filters, product pages, cart, checkout and AI shopping assistant. Connect to local WooCommerce or any configured remote store.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🛍️';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#7c3aed';
	}

	/**
	 * Render the body HTML for the WooCommerce Shop React SPA template.
	 *
	 * Injects a `window.wpTmaWooConfig` JS object with all URLs, IDs, and
	 * extended context the React app needs.  The config includes:
	 *
	 *   - Core endpoints: validateUrl, toolsUrl, chatUrl, analyticsUrl
	 *   - Auth:           nonce (initial), assistantId
	 *   - Site meta:      siteName, siteUrl
	 *   - WooCommerce:    wooSource ('local'|'remote'), wooConnectionId
	 *   - Charts:         chartJsUrl (CDN URL for lazy Chart.js loading)
	 *   - Member context: memberId, memberName (from session when available)
	 *
	 * @since 1.1.5
	 *
	 * @param  array $ctx Context variables injected by the TMA controller.
	 * @return string     HTML body fragment.
	 */
	public function render_html( array $ctx ) {
		$js_url  = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL . 'build/tma-woo-shop/tma-woo-shop.js' : '';
		$css_url = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL . 'build/tma-woo-shop/tma-woo-shop.css' : '';

		// Serialise all values the React app needs.  wp_json_encode escapes
		// characters that could break out of a <script> tag.
		$config = wp_json_encode(
			array(
				'validateUrl'     => $ctx['validate_url'] ?? '',
				'toolsUrl'        => $ctx['tools_url'] ?? '',
				'chatUrl'         => $ctx['chat_url'] ?? '',
				'analyticsUrl'    => $ctx['analytics_url'] ?? '',
				'nonce'           => $ctx['nonce'] ?? '',
				'assistantId'     => $ctx['assistant_id'] ?? '',
				'siteName'        => $ctx['site_name'] ?? get_bloginfo( 'name' ),
				'siteUrl'         => home_url(),
				'wooSource'       => $ctx['woo_source'] ?? 'local',
				'wooConnectionId' => $ctx['woo_connection_id'] ?? '',
				'chartJsUrl'      => $ctx['chart_js_url'] ?? '',
				'memberId'        => $ctx['member_id'] ?? '',
				'memberName'      => $ctx['member_name'] ?? '',
			)
		);

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- Static HTML structure; dynamic values escaped individually.
		return '<body class="wp-mcp-ai-telegram-mini-app tma-woo-shop-template">' .
			'<div id="tma-woo-shop-root"></div>' .
			'<script>window.wpTmaWooConfig=' . $config . ';</script>' .
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			( $css_url ? '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' : '' ) .
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			( $js_url ? '<script src="' . esc_url( $js_url ) . '"></script>' : '' ) .
			'</body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/*
==========================================================================
	TEMPLATE: Shopify Jewelry Shop
	==========================================================================

 */

/**
 * Jewelry Shop Telegram Mini App template (Shopify-powered).
 *
 * Gold-themed React SPA for jewelry retailers connected to a Shopify store via
 * the plugin's Remote Sites / Shopify tools infrastructure. Provides:
 *
 *  - Product catalog with debounced search
 *  - Product detail page with add-to-cart
 *  - Shopping cart with quantity controls
 *  - Checkout / order enquiry form
 *  - Shopify order history
 *  - AI jewelry concierge chat
 *
 * The compiled React bundle lives at:
 *   addons/pro/build/tma-shopify-jewelry/tma-shopify-jewelry.js
 *   addons/pro/build/tma-shopify-jewelry/tma-shopify-jewelry.css
 *
 * Build with: npm run build:tma-shopify-jewelry
 *
 * @since 1.2.0
 */
class WP_MCP_AI_TMA_Template_Shopify_Jewelry extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'jewelry_shop';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Jewelry Shop (Shopify)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Gold-themed React SPA for jewelry retailers. Connects to any Shopify store via Remote Sites. Includes product catalog, cart, checkout and an AI jewelry concierge.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '💍';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#c9a227';
	}

	/**
	 * Render the body HTML for this template.
	 *
	 * Injects a `window.wpTmaJewelryConfig` JS object with all URLs and IDs
	 * the React SPA needs, then loads the compiled bundle from the pro addon's
	 * build directory.
	 *
	 * Context keys used:
	 *   validate_url          – POST endpoint to verify Telegram initData and receive a fresh nonce/token.
	 *   tools_url             – Base URL for the tool-execution endpoint.
	 *   chat_url              – TMA-aware chat endpoint.
	 *   analytics_url         – Analytics endpoint for order/revenue data.
	 *   nonce                 – Initial WordPress nonce.
	 *   assistant_id          – Resolved Mini App assistant ID.
	 *   site_name             – Site display name.
	 *   shopify_connection_id – Shopify Remote Sites connection ID (optional; falls back to global option).
	 *   chart_js_url          – CDN URL for Chart.js lazy loading.
	 *   member_id             – Active member ID (from TMA session, if available).
	 *   member_name           – Active member display name (from TMA session, if available).
	 *
	 * @param  array $ctx Context variables injected by the TMA controller.
	 * @return string     HTML body fragment.
	 */
	public function render_html( array $ctx ) {
		$js_url  = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL . 'build/tma-shopify-jewelry/tma-shopify-jewelry.js' : '';
		$css_url = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL . 'build/tma-shopify-jewelry/tma-shopify-jewelry.css' : '';

		// Resolve the Shopify connection ID: per-context value, then global option.
		$connection_id = '';
		if ( ! empty( $ctx['shopify_connection_id'] ) ) {
			$connection_id = sanitize_key( $ctx['shopify_connection_id'] );
		} else {
			$connection_id = sanitize_key( get_option( 'wp_mcp_ai_shopify_jewelry_connection_id', '' ) );
		}

		// wp_json_encode() uses JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
		// ensuring the output is safe for inline <script> embedding.
		$config = wp_json_encode(
			array(
				'validateUrl'  => $ctx['validate_url'] ?? '',
				'toolsUrl'     => $ctx['tools_url'] ?? '',
				'chatUrl'      => $ctx['chat_url'] ?? '',
				'analyticsUrl' => $ctx['analytics_url'] ?? '',
				'nonce'        => $ctx['nonce'] ?? '',
				'assistantId'  => $ctx['assistant_id'] ?? '',
				'siteName'     => $ctx['site_name'] ?? get_bloginfo( 'name' ),
				'siteUrl'      => home_url(),
				'connectionId' => $connection_id,
				'chartJsUrl'   => $ctx['chart_js_url'] ?? '',
				'memberId'     => $ctx['member_id'] ?? '',
				'memberName'   => $ctx['member_name'] ?? '',
			)
		);

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $config produced by wp_json_encode (HTML-safe); CSS/JS URLs escaped with esc_url().
		return '<body class="wp-mcp-ai-telegram-mini-app tma-jw-template">' .
			'<div id="tma-shopify-jewelry-root"></div>' .
			'<script>window.wpTmaJewelryConfig=' . $config . ';</script>' .
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			( $css_url ? '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' : '' ) .
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			( $js_url ? '<script src="' . esc_url( $js_url ) . '"></script>' : '' ) .
			'</body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/*
==========================================================================
	TEMPLATE: Shopify Shop (General Purpose)
	==========================================================================

 */

/**
 * General-purpose Shopify Store Telegram Mini App template.
 *
 * Modern React SPA for any Shopify store. Connects via the plugin's Remote
 * Sites / Shopify tools infrastructure. Features:
 *
 *  - Product catalog with collection filters and search
 *  - Product detail with variant selector and image gallery
 *  - Shopping cart with quantity controls
 *  - Checkout via AI assistant
 *  - Shopify order history with status badges
 *  - AI shopping assistant chat
 *  - Pull-to-refresh and skeleton loading states
 *  - Share product via Telegram
 *
 * The compiled React bundle lives at:
 *   addons/pro/build/tma-shopify-shop/tma-shopify-shop.js
 *   addons/pro/build/tma-shopify-shop/tma-shopify-shop.css
 *
 * Build with: npm run build:tma-shopify-shop
 *
 * @since 1.2.0
 */
class WP_MCP_AI_TMA_Template_Shopify_Shop extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'shopify_shop';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Shopify Shop (React)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Full-featured React SPA for Shopify stores. Product catalog with collection filters, variant selector, cart, checkout, order history, and AI shopping assistant. Connect to any Shopify store via Remote Sites.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🛒';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#5c6ac4';
	}

	/**
	 * Render the body HTML for this template.
	 *
	 * Injects a `window.wpTmaShopifyConfig` JS object with all URLs and IDs
	 * the React SPA needs, then loads the compiled bundle from the pro addon's
	 * build directory.
	 *
	 * Context keys used:
	 *   validate_url            – POST endpoint to verify Telegram initData and receive a fresh nonce/token.
	 *   tools_url               – Base URL for the tool-execution endpoint.
	 *   chat_url                – TMA-aware chat endpoint.
	 *   analytics_url           – Analytics endpoint for data visualisation.
	 *   nonce                   – Initial WordPress nonce.
	 *   assistant_id            – Resolved Mini App assistant ID.
	 *   site_name               – Site display name.
	 *   shopify_connection_id   – Shopify Remote Sites connection ID (optional; falls back to global option).
	 *   chart_js_url            – CDN URL for Chart.js lazy loading.
	 *   member_id               – Active member ID (from TMA session, if available).
	 *   member_name             – Active member display name (from TMA session, if available).
	 *
	 * @param  array $ctx Context variables injected by the TMA controller.
	 * @return string     HTML body fragment.
	 */
	public function render_html( array $ctx ) {
		$js_url  = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL . 'build/tma-shopify-shop/tma-shopify-shop.js' : '';
		$css_url = defined( 'WP_MCP_AI_PRO_URL' ) ? WP_MCP_AI_PRO_URL . 'build/tma-shopify-shop/tma-shopify-shop.css' : '';

		// Resolve the Shopify connection ID: per-context value, then global option.
		$connection_id = '';
		if ( ! empty( $ctx['shopify_connection_id'] ) ) {
			$connection_id = sanitize_key( $ctx['shopify_connection_id'] );
		} else {
			$connection_id = sanitize_key( get_option( 'wp_mcp_ai_shopify_shop_connection_id', '' ) );
		}

		// wp_json_encode() uses JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP
		// ensuring the output is safe for inline <script> embedding.
		$config = wp_json_encode(
			array(
				'validateUrl'  => $ctx['validate_url'] ?? '',
				'toolsUrl'     => $ctx['tools_url'] ?? '',
				'chatUrl'      => $ctx['chat_url'] ?? '',
				'analyticsUrl' => $ctx['analytics_url'] ?? '',
				'nonce'        => $ctx['nonce'] ?? '',
				'assistantId'  => $ctx['assistant_id'] ?? '',
				'siteName'     => $ctx['site_name'] ?? get_bloginfo( 'name' ),
				'siteUrl'      => home_url(),
				'connectionId' => $connection_id,
				'chartJsUrl'   => $ctx['chart_js_url'] ?? '',
				'memberId'     => $ctx['member_id'] ?? '',
				'memberName'   => $ctx['member_name'] ?? '',
			)
		);

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- $config produced by wp_json_encode (HTML-safe); CSS/JS URLs escaped with esc_url().
		return '<body class="wp-mcp-ai-telegram-mini-app tma-shopify-shop-template">' .
			'<div id="tma-shopify-shop-root"></div>' .
			'<script>window.wpTmaShopifyConfig=' . $config . ';</script>' .
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet
			( $css_url ? '<link rel="stylesheet" href="' . esc_url( $css_url ) . '">' : '' ) .
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			// phpcs:disable WordPress.WP.EnqueuedResources.NonEnqueuedScript
			( $js_url ? '<script src="' . esc_url( $js_url ) . '"></script>' : '' ) .
			'</body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/*
==========================================================================
	TEMPLATE: Flowhub E-Commerce Store (Inline)
	==========================================================================

 */

/**
 * Flowhub E-Commerce Store Telegram Mini App template (inline HTML/CSS/JS).
 *
 * A Flowhub-powered storefront for cannabis dispensaries and retail businesses
 * using the Flowhub POS platform.  Uses the `flowhub_get_products` tool via
 * the Remote Sites infrastructure.  Three-tab layout (Shop, AI, Settings)
 * rendered entirely inline – no React build step required.
 *
 * Features:
 *  - Product catalog with debounced search
 *  - Category filter chips (flower, concentrate, edible, accessories)
 *  - Product cards with THC/CBD percentages, strain type, and brand
 *  - AI shopping assistant chat
 *  - Settings: font size, compact mode, connection info, data management
 *
 * @since 1.2.0
 */
class WP_MCP_AI_TMA_Template_Flowhub_Ecommerce extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'flowhub_ecommerce';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'E-Commerce Store (Flowhub)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Shop assistant with product search, category filters, and AI-powered recommendations. Designed for Flowhub stores connected via Remote Sites.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🌿';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#00a32a';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$chat_url     = $ctx['chat_url'];
		$validate_url = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id = $ctx['assistant_id'];

		// Resolve Flowhub connection ID: per-context value → global option.
		$connection_id = '';
		if ( ! empty( $ctx['flowhub_connection_id'] ) ) {
			$connection_id = sanitize_key( $ctx['flowhub_connection_id'] );
		} else {
			$connection_id = sanitize_key( get_option( 'wp_mcp_ai_flowhub_ecommerce_connection_id', '' ) );
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-flowhub-ec-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables (Flowhub green) ──

		*/
		':root{--tma-btn:#00a32a;--tma-accent:#00a32a;--tma-secondary-bg:#f2faf4;' .
			'--fec-base:14px;--fec-label:12px;--fec-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.fec-font-small{--fec-base:12px;--fec-label:10px;--fec-heading:14px}' .
		'.fec-font-large{--fec-base:16px;--fec-label:14px;--fec-heading:18px}' .
		'.fec-compact .tma-product-grid{gap:6px;padding:6px 8px}' .
		'.fec-compact .tma-product-body{padding:4px 6px}' .

		/*
		 * ── Search bar ──

		*/
		'.tma-search-bar{padding:10px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border)}' .
		'.tma-search-wrap{display:flex;align-items:center;gap:8px;background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:10px;padding:0 12px}' .
		'.tma-search-wrap input{flex:1;border:none;outline:none;font-size:var(--fec-base);padding:10px 0;background:transparent;color:var(--tma-text)}' .

		/*
		 * ── Category filter chips ──

		*/
		'.fec-category-bar{display:flex;gap:6px;padding:8px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--tma-bg)}' .
		'.fec-category-bar::-webkit-scrollbar{display:none}' .
		'.fec-chip{flex:0 0 auto;padding:6px 14px;border:1px solid var(--tma-border);border-radius:20px;' .
			'font-size:var(--fec-label);cursor:pointer;background:var(--tma-bg);color:var(--tma-text);white-space:nowrap}' .
		'.fec-chip.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.fec-chip:active{opacity:.7}' .

		/*
		 * ── Product grid ──

		*/
		'.tma-product-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px 12px}' .
		'.tma-product-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);overflow:hidden;cursor:pointer;position:relative}' .
		'.tma-product-card:active{opacity:.8}' .
		'.tma-product-img{width:100%;aspect-ratio:1;object-fit:cover;background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;font-size:32px}' .
		'.tma-product-img img{width:100%;height:100%;object-fit:cover}' .
		'.tma-product-body{padding:8px 10px}' .
		'.tma-product-name{font-size:var(--fec-label);font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-product-price{font-size:var(--fec-base);color:var(--tma-btn);font-weight:700}' .
		'.fec-product-meta{font-size:var(--fec-label);color:var(--tma-hint);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.fec-strain-badge{position:absolute;top:6px;right:6px;font-size:10px;padding:2px 6px;border-radius:8px;font-weight:600}' .
		'.fec-strain-sativa{background:#fff3e0;color:#e65100}' .
		'.fec-strain-indica{background:#e8eaf6;color:#283593}' .
		'.fec-strain-hybrid{background:#e8f5e9;color:#2e7d32}' .
		'.fec-strain-cbd{background:#e3f2fd;color:#1565c0}' .
		'.fec-strain-default{background:#f5f5f5;color:#616161}' .

		/*
		 * ── AI Chat ──

		*/
		'.fec-chat-container{display:flex;flex-direction:column;height:100%}' .
		'.fec-chat-messages{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.fec-msg{max-width:85%;padding:10px 14px;border-radius:16px;font-size:var(--fec-base);line-height:1.5;word-wrap:break-word}' .
		'.fec-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.fec-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.fec-msg.bot p{margin:0 0 6px}' .
		'.fec-msg.bot p:last-child{margin-bottom:0}' .
		'.fec-msg.bot ul,.fec-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.fec-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.fec-chat-input-row{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg)}' .
		'.fec-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 14px;font-size:var(--fec-base);' .
			'background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.fec-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;' .
			'cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.fec-send-btn:active{opacity:.8}' .

		/*
		 * ── Settings ──

		*/
		'.fec-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.fec-settings-title{font-size:var(--fec-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.fec-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.fec-settings-row:last-child{border-bottom:none}' .
		'.fec-settings-label{font-size:var(--fec-base);color:var(--tma-text)}' .
		'.fec-settings-value{font-size:var(--fec-base);color:var(--tma-hint)}' .
		'.fec-font-btns{display:flex;gap:4px}' .
		'.fec-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);' .
			'color:var(--tma-text);font-size:var(--fec-label);cursor:pointer}' .
		'.fec-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.fec-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.fec-toggle.on{background:var(--tma-btn)}' .
		'.fec-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.fec-toggle.on::after{transform:translateX(20px)}' .
		'.fec-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);' .
			'background:var(--tma-bg);color:var(--tma-text);font-size:var(--fec-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.fec-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.fec-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .
		'.fec-connection-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}' .
		'.fec-connection-dot.online{background:#2e7d32}' .
		'.fec-connection-dot.offline{background:#c62828}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">🌿</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="fec-header-status">' . esc_html__( 'Flowhub Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .

			/*
			 * ── Search bar (visible on Products tab) ──

		 */
			'<div class="tma-search-bar" id="fec-search-bar">' .
				'<div class="tma-search-wrap">' .
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
					'<input type="search" id="fec-search-input" placeholder="' . esc_attr__( 'Search products…', 'mcp-ai-wpoos-pro' ) . '" />' .
				'</div>' .
			'</div>' .

			/*
			 * ── Category filter chips ──

		 */
			'<div class="fec-category-bar" id="fec-category-bar">' .
				'<button class="fec-chip active" onclick="fecFilterCategory(\'\')">' . esc_html__( 'All', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'<button class="fec-chip" onclick="fecFilterCategory(\'flower\')">' . esc_html__( 'Flower', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'<button class="fec-chip" onclick="fecFilterCategory(\'concentrate\')">' . esc_html__( 'Concentrates', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'<button class="fec-chip" onclick="fecFilterCategory(\'edible\')">' . esc_html__( 'Edibles', 'mcp-ai-wpoos-pro' ) . '</button>' .
				'<button class="fec-chip" onclick="fecFilterCategory(\'accessories\')">' . esc_html__( 'Accessories', 'mcp-ai-wpoos-pro' ) . '</button>' .
			'</div>' .

			/*
			 * ── Content panes ──

		 */
			'<div class="tma-content">' .

				/*
				 * Tab 1: Products

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-products">' .
					'<div class="tma-section-title">' . esc_html__( 'Featured Products', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div class="tma-product-grid" id="fec-product-grid">' .
						'<div class="tma-empty" style="grid-column:span 2">' . esc_html__( 'Loading products…', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 2: AI Assistant

			 */
				'<div class="tma-tab-pane" id="tma-tab-assistant">' .
					'<div class="fec-chat-container">' .
						'<div class="fec-chat-messages" id="fec-chat-messages"></div>' .
						'<div class="fec-chat-input-row">' .
							'<input type="text" id="fec-chat-input" class="fec-chat-input"' .
								' placeholder="' . esc_attr__( 'Ask about products…', 'mcp-ai-wpoos-pro' ) . '" />' .
							'<button class="fec-send-btn" id="fec-send-btn" onclick="fecChatSend()">' .
								'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
							'</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 3: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div class="tma-section-title">' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</div>' .

					/*
					 * Display section

				 */
					'<div class="fec-settings-section">' .
						'<div class="fec-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="fec-settings-row">' .
							'<span class="fec-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="fec-font-btns" id="fec-font-btns">' .
								'<button data-size="small" onclick="fecSetFontSize(\'small\')">A-</button>' .
								'<button data-size="medium" class="active" onclick="fecSetFontSize(\'medium\')">A</button>' .
								'<button data-size="large" onclick="fecSetFontSize(\'large\')">A+</button>' .
							'</div>' .
						'</div>' .
						'<div class="fec-settings-row">' .
							'<span class="fec-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="fec-toggle" id="fec-compact-toggle" onclick="fecToggleCompact()"></button>' .
						'</div>' .
					'</div>' .

					/*
					 * Store section

				 */
					'<div class="fec-settings-section">' .
						'<div class="fec-settings-title">' . esc_html__( 'Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="fec-settings-row">' .
							'<span class="fec-settings-label">' . esc_html__( 'Connection', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="fec-settings-value" id="fec-connection-info"></span>' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="fec-settings-section">' .
						'<div class="fec-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="fec-settings-row">' .
							'<span class="fec-settings-label" id="fec-data-summary"></span>' .
						'</div>' .
						'<button class="fec-settings-btn" onclick="fecSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="fec-settings-btn danger" onclick="fecClearData()">' .
							esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (3 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-products" onclick="fecSwitch(\'products\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' .
					'<span>' . esc_html__( 'Shop', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-assistant" onclick="fecSwitch(\'assistant\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="fecSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var FLOWHUB_CONNECTION_ID=' . wp_json_encode( $connection_id ) . ';' .
		'var SITE_NAME=' . wp_json_encode( $ctx['site_name'] ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="products";' .
		'var chatHist=[];' .
		'var productsCache=[];' .
		'var activeCategory="";' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .

		/*
		 * Simple markdown-like renderer for bot messages

		*/
		'function fecRenderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── localStorage helpers ──

		*/
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		══════════════════════════════════════════════════════════
			Flowhub data extraction helpers
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Normalize tool response: controller returns {success,result} or {data}

		*/
		'function fhExtract(raw,key){' .
			'return (raw&&raw.result&&raw.result[key])||(raw&&raw.data&&raw.data[key])||(raw&&raw[key])||' .
				'(raw&&raw.result)||(raw&&raw.data)||null;' .
		'}' .

		/*
		 * ── Flowhub tool call wrapper ──

		*/
		'function fecToolCall(slug,args,cb){' .
			'if(FLOWHUB_CONNECTION_ID)args.connection_id=FLOWHUB_CONNECTION_ID;' .
			'var body={slug:slug,arguments:args};' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){' .
				'if(!r.ok){' .
					'return r.json().catch(function(){return {};}).then(function(errBody){' .
						'var msg=errBody&&errBody.message?errBody.message:"HTTP "+r.status;' .
						'console.error("[FEC] Tool "+slug+" HTTP "+r.status+":",msg);' .
						'throw new Error(msg);' .
					'});' .
				'}' .
				'return r.json();' .
			'})' .
			'.then(function(d){' .
				'if(d&&d.result&&!d.data){d.data=d.result;}' .
				'cb(null,d);' .
			'})' .
			'.catch(function(e){console.error("[FEC] Tool "+slug+" error:",e);cb(e,null);});' .
		'}' .

		/*
		 * ── Session init ──

		*/
		'function fecInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp){fecBootstrap();return;}' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData){fecBootstrap();return;}' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d){fecBootstrap();return;}if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}fecBootstrap();})' .
			'.catch(function(){fecBootstrap();});' .
		'}' .

		'function fecBootstrap(){' .
			'fecLoadProducts();' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function fecApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("fec_font_size","medium");' .
				'shell.classList.remove("fec-font-small","fec-font-large");' .
				'if(size==="small")shell.classList.add("fec-font-small");' .
				'else if(size==="large")shell.classList.add("fec-font-large");' .
				'var compact=lsGet("fec_compact",false);' .
				'if(compact)shell.classList.add("fec-compact");' .
				'else shell.classList.remove("fec-compact");' .
				'var btns=document.querySelectorAll("#fec-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("fec-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
			'}catch(e){}' .
		'}' .
		'window.fecSetFontSize=function(s){lsSet("fec_font_size",s);tmaHaptic("selectionChanged");fecApplyDisplaySettings();};' .
		'window.fecToggleCompact=function(){var c=!lsGet("fec_compact",false);lsSet("fec_compact",c);tmaHaptic("selectionChanged");fecApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.fecSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'var sb=document.getElementById("fec-search-bar");if(sb)sb.style.display=tab==="products"?"":"none";' .
			'var cb=document.getElementById("fec-category-bar");if(cb)cb.style.display=tab==="products"?"flex":"none";' .
			'activeTab=tab;' .
			'if(tab==="assistant"&&!chatHist.length)fecChatInit();' .
			'if(tab==="settings")fecRenderSettings();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Category filtering
			══════════════════════════════════════════════════════════

		*/
		'window.fecFilterCategory=function(cat){' .
			'activeCategory=cat;tmaHaptic("selectionChanged");' .
			'var btns=document.querySelectorAll("#fec-category-bar .fec-chip");' .
			'btns.forEach(function(b){' .
				'var bCat=b.getAttribute("onclick")||"";' .
				'var match=bCat.match(/fecFilterCategory\\(\'(.*)\'\\)/);' .
				'var c=match?match[1]:"";' .
				'b.classList.toggle("active",c===cat);' .
			'});' .
			'fecLoadProducts(cat);' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Products
			══════════════════════════════════════════════════════════

		*/
		'function fecLoadProducts(cat){' .
			'var g=document.getElementById("fec-product-grid");if(!g)return;' .
			'if(!cat&&productsCache.length){fecRenderProducts(productsCache);}' .
			'if(!cat&&!productsCache.length)g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'var args={limit:20,offset:0};' .
			'if(cat)args.category=cat;' .
			'fecToolCall("flowhub_get_products",args,function(err,d){' .
				'if(err){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Could not load products.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'var ps=fhExtract(d,"products");' .
				'if(!ps||!Array.isArray(ps)){' .
					'ps=fhExtract(d,"data");' .
					'if(!ps||!Array.isArray(ps))ps=[];' .
				'}' .
				'if(!cat){productsCache=ps;lsSet("fec_products_cache",ps);}' .
				'fecRenderProducts(ps);' .
			'});' .
		'}' .

		'function fecRenderProducts(ps){' .
			'var g=document.getElementById("fec-product-grid");if(!g)return;' .
			'if(!ps.length){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'No products found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'g.innerHTML=ps.map(function(p){' .
				'var name=p.name||p.title||"' . esc_js( __( 'Product', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'var price=parseFloat(p.price||0);' .
				'var brand=p.brand||"";' .
				'var strain=(p.strain_type||p.strainType||"").toLowerCase();' .
				'var thc=p.thc_percent||p.thcPercent||"";' .
				'var cbd=p.cbd_percent||p.cbdPercent||"";' .

				/*
				 * Strain badge

			 */
				'var strainCls="fec-strain-default";' .
				'if(strain==="sativa")strainCls="fec-strain-sativa";' .
				'else if(strain==="indica")strainCls="fec-strain-indica";' .
				'else if(strain==="hybrid")strainCls="fec-strain-hybrid";' .
				'else if(strain==="cbd")strainCls="fec-strain-cbd";' .
				'var strainBadge=strain?"<span class=\\"fec-strain-badge "+strainCls+"\\">"+escH(strain)+"</span>":"";' .

				/*
				 * Product image

			 */
				'var imgUrl="";' .
				'if(p.images&&Array.isArray(p.images)&&p.images[0])imgUrl=p.images[0].url||p.images[0].src||p.images[0];' .
				'else if(p.image)imgUrl=p.image.url||p.image.src||p.image;' .
				'else if(p.image_url)imgUrl=p.image_url;' .
				'var img=imgUrl?"<img src=\\""+escH(imgUrl)+"\\" alt=\\"\\"/>":"🌿";' .

				/*
				 * Meta line: THC / CBD / Brand

			 */
				'var metaParts=[];' .
				'if(thc)metaParts.push("THC "+escH(thc)+"%");' .
				'if(cbd)metaParts.push("CBD "+escH(cbd)+"%");' .
				'if(brand)metaParts.push(escH(brand));' .
				'var metaHtml=metaParts.length?"<div class=\\"fec-product-meta\\">"+metaParts.join(" · ")+"</div>":"";' .

				'return \'<div class="tma-product-card">' .
					'\'+strainBadge+\'' .
					'<div class="tma-product-img">\'+img+\'</div>' .
					'<div class="tma-product-body">' .
						'<div class="tma-product-name">\'+escH(name)+\'</div>' .
						'<div class="tma-product-price">$\'+price.toFixed(2)+\'</div>' .
						'\'+metaHtml+\'' .
					'</div></div>\';' .
			'}).join("");' .
		'}' .

		/*
		 * Debounced search

		*/
		'var searchTimer=null;' .
		'document.getElementById("fec-search-input").addEventListener("input",function(e){' .
			'clearTimeout(searchTimer);var q=e.target.value.trim();' .
			'searchTimer=setTimeout(function(){' .
				'activeCategory="";' .
				'var btns=document.querySelectorAll("#fec-category-bar .fec-chip");' .
				'btns.forEach(function(b,i){b.classList.toggle("active",i===0);});' .
				'if(q){' .

					/*
					 * Filter cached products locally by name match.

				 */
					'var filtered=productsCache.filter(function(p){' .
						'return (p.name||p.title||"").toLowerCase().indexOf(q.toLowerCase())!==-1;' .
					'});' .
					'fecRenderProducts(filtered);' .
				'}else{fecRenderProducts(productsCache);}' .
			'},400);' .
		'});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – AI Assistant
			══════════════════════════════════════════════════════════

		*/
		'function fecChatInit(){' .
			'chatHist=lsGet("fec_chat_hist",[]);' .
			'var m=document.getElementById("fec-chat-messages");if(!m)return;m.innerHTML="";' .
			'if(chatHist.length){' .
				'chatHist.forEach(function(msg){fecAppendMsg(msg.role==="user"?"user":"bot",msg.content,true);});' .
			'}else{' .
				'var ctx="[' . esc_js( __( 'Flowhub store context', 'mcp-ai-wpoos-pro' ) ) . '] ' . esc_js( __( 'Site', 'mcp-ai-wpoos-pro' ) ) . ': "+SITE_NAME+", ' .
					esc_js( __( 'Cached products', 'mcp-ai-wpoos-pro' ) ) . ': "+productsCache.length;' .
				'chatHist.push({role:"system",content:ctx});' .
				'fecAppendMsg("bot","' . esc_js( __( 'Hi! I\'m your Flowhub shopping assistant. I can help you find products and answer questions about our store.', 'mcp-ai-wpoos-pro' ) ) . '",false);' .
			'}' .
		'}' .

		'function fecAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="fec-msg "+role;' .
			'if(role==="bot"){el.innerHTML=fecRenderMd(text);}' .
			'else{el.textContent=text;}' .
			'var m=document.getElementById("fec-chat-messages");' .
			'if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;' .
		'}' .

		'window.fecChatSend=function(){' .
			'var inp=document.getElementById("fec-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'chatHist.push({role:"user",content:txt});fecAppendMsg("user",txt,false);' .
			'lsSet("fec_chat_hist",chatHist.slice(-50));' .
			'var el=fecAppendMsg("bot","\\u2026",false);' .
			'var body={messages:chatHist.filter(function(m){return m.role!=="system";}).slice(-12)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var sys=chatHist.find(function(m){return m.role==="system";});' .
			'if(sys)body.messages.unshift(sys);' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data;' .
				'var rep=(data&&data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)||' .
					'(data&&data.content)||(data&&data.response)||"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.innerHTML=fecRenderMd(rep);chatHist.push({role:"assistant",content:rep});' .
				'lsSet("fec_chat_hist",chatHist.slice(-50));' .
			'})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .

		/*
		 * Enter to send

		*/
		'document.getElementById("fec-chat-input").addEventListener("keydown",function(e){if(e.key==="Enter")fecChatSend();});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function fecRenderSettings(){' .
			'var ci=document.getElementById("fec-connection-info");' .
			'if(ci){' .
				'if(FLOWHUB_CONNECTION_ID){' .
					'ci.innerHTML=\'<span class="fec-connection-dot online"></span>' . esc_js( __( 'Flowhub Store', 'mcp-ai-wpoos-pro' ) ) . '\';' .
				'}else{' .
					'ci.innerHTML=\'<span class="fec-connection-dot offline"></span>' . esc_js( __( 'Not Connected', 'mcp-ai-wpoos-pro' ) ) . '\';' .
				'}' .
			'}' .
			'var ds=document.getElementById("fec-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Cached products', 'mcp-ai-wpoos-pro' ) ) . ': "+productsCache.length+", ' .
				esc_js( __( 'Chat messages', 'mcp-ai-wpoos-pro' ) ) . ': "+chatHist.length;' .
		'}' .

		'window.fecSyncFromServer=function(){' .
			'tmaHaptic("medium");fecLoadProducts();' .
		'};' .

		'window.fecClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all local data? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)fecDoClear();});' .
			'}else if(confirm(msg)){fecDoClear();}' .
		'};' .

		'function fecDoClear(){' .
			'try{' .
				'localStorage.removeItem("fec_products_cache");' .
				'localStorage.removeItem("fec_chat_hist");' .
				'localStorage.removeItem("fec_font_size");' .
				'localStorage.removeItem("fec_compact");' .
			'}catch(e){}' .
			'productsCache=[];chatHist=[];activeCategory="";' .
			'fecRenderSettings();tmaHaptic("notificationSuccess");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/
		'productsCache=lsGet("fec_products_cache",[]);' .
		'fecApplyDisplaySettings();' .

		'if(productsCache.length)fecRenderProducts(productsCache);' .

		'fecInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}

/*
==========================================================================
	TEMPLATE: Shopify E-Commerce Store (Inline)
	==========================================================================

 */

/**
 * Shopify E-Commerce Store Telegram Mini App template (inline HTML/CSS/JS).
 *
 * A Shopify-powered storefront using the `shopify_products` (and optionally
 * `shopify_orders`) tools via the Remote Sites infrastructure.  Supports both
 * Admin API and Catalog API modes.  Three-tab layout (Shop, AI, Settings)
 * rendered entirely inline – no React build step required.
 *
 * Features:
 *  - Product catalog with debounced search
 *  - Collection filter chips (built dynamically from product types)
 *  - Product cards with images, prices, and variants
 *  - AI shopping assistant chat
 *  - Settings: font size, compact mode, connection info, data management
 *  - Catalog API mode: limits results to 10, prices in minor units normalised
 *
 * @since 1.2.0
 */
class WP_MCP_AI_TMA_Template_Shopify_Ecommerce extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/**
	 * Get the template slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'shopify_ecommerce';
	}

	/**
	 * Get the template name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'E-Commerce Store (Shopify)', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the template description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Shop assistant with product search, collection filters, and AI-powered recommendations. Designed for Shopify stores connected via Remote Sites. Supports both Admin API and Catalog API.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get the target toolkit slug.
	 *
	 * @return string
	 */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/**
	 * Get the template icon.
	 *
	 * @return string
	 */
	public function get_icon() {
		return '🛍️';
	}

	/**
	 * Get the template accent color.
	 *
	 * @return string
	 */
	public function get_accent_color() {
		return '#96bf48';
	}

	/**
	 * Render the template HTML output.
	 *
	 * @param array $ctx Context array.
	 * @return string
	 */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$chat_url     = $ctx['chat_url'];
		$validate_url = isset( $ctx['validate_url'] ) ? $ctx['validate_url'] : '';
		$assistant_id = $ctx['assistant_id'];

		// Resolve Shopify connection ID: per-context value → global option.
		$connection_id = '';
		if ( ! empty( $ctx['shopify_connection_id'] ) ) {
			$connection_id = sanitize_key( $ctx['shopify_connection_id'] );
		} else {
			$connection_id = sanitize_key( get_option( 'wp_mcp_ai_shopify_ecommerce_connection_id', '' ) );
		}

		// Resolve the Shopify API mode from the connection.
		$shopify_api_mode = 'admin_api';
		if ( $connection_id && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$shopify_conn = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $shopify_conn && ! empty( $shopify_conn['shopify_api_mode'] ) ) {
				$shopify_api_mode = in_array( $shopify_conn['shopify_api_mode'], array( 'admin_api', 'catalog_api' ), true )
					? $shopify_conn['shopify_api_mode']
					: 'admin_api';
			}
		}

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-shopify-ec-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .

		/*
		 * ── Theme variables (Shopify green) ──

		*/
		':root{--tma-btn:#96bf48;--tma-accent:#96bf48;--tma-secondary-bg:#f4f9ed;' .
			'--sec-base:14px;--sec-label:12px;--sec-heading:16px;}' .

		/*
		 * ── Font-size & compact mode ──

		*/
		'.sec-font-small{--sec-base:12px;--sec-label:10px;--sec-heading:14px}' .
		'.sec-font-large{--sec-base:16px;--sec-label:14px;--sec-heading:18px}' .
		'.sec-compact .tma-product-grid{gap:6px;padding:6px 8px}' .
		'.sec-compact .tma-product-body{padding:4px 6px}' .

		/*
		 * ── Search bar ──

		*/
		'.tma-search-bar{padding:10px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border)}' .
		'.tma-search-wrap{display:flex;align-items:center;gap:8px;background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:10px;padding:0 12px}' .
		'.tma-search-wrap input{flex:1;border:none;outline:none;font-size:var(--sec-base);padding:10px 0;background:transparent;color:var(--tma-text)}' .

		/*
		 * ── Collection filter chips ──

		*/
		'.sec-collection-bar{display:flex;gap:6px;padding:8px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch;background:var(--tma-bg)}' .
		'.sec-collection-bar::-webkit-scrollbar{display:none}' .
		'.sec-chip{flex:0 0 auto;padding:6px 14px;border:1px solid var(--tma-border);border-radius:20px;' .
			'font-size:var(--sec-label);cursor:pointer;background:var(--tma-bg);color:var(--tma-text);white-space:nowrap}' .
		'.sec-chip.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.sec-chip:active{opacity:.7}' .

		/*
		 * ── Product grid ──

		*/
		'.tma-product-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px 12px}' .
		'.tma-product-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);overflow:hidden;cursor:pointer;position:relative}' .
		'.tma-product-card:active{opacity:.8}' .
		'.tma-product-img{width:100%;aspect-ratio:1;object-fit:cover;background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;font-size:32px}' .
		'.tma-product-img img{width:100%;height:100%;object-fit:cover}' .
		'.tma-product-body{padding:8px 10px}' .
		'.tma-product-name{font-size:var(--sec-label);font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-product-price{font-size:var(--sec-base);color:var(--tma-btn);font-weight:700}' .
		'.sec-product-meta{font-size:var(--sec-label);color:var(--tma-hint);margin-top:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.sec-vendor-badge{position:absolute;top:6px;right:6px;font-size:10px;padding:2px 6px;border-radius:8px;font-weight:600;background:#e8f5e9;color:#2e7d32}' .

		/*
		 * ── AI Chat ──

		*/
		'.sec-chat-container{display:flex;flex-direction:column;height:100%}' .
		'.sec-chat-messages{flex:1;overflow-y:auto;padding:10px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.sec-msg{max-width:85%;padding:10px 14px;border-radius:16px;font-size:var(--sec-base);line-height:1.5;word-wrap:break-word}' .
		'.sec-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.sec-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.sec-msg.bot p{margin:0 0 6px}' .
		'.sec-msg.bot p:last-child{margin-bottom:0}' .
		'.sec-msg.bot ul,.sec-msg.bot ol{margin:4px 0;padding-left:18px}' .
		'.sec-msg.bot code{background:rgba(0,0,0,.06);padding:1px 4px;border-radius:3px;font-size:90%}' .
		'.sec-chat-input-row{display:flex;gap:8px;padding:10px 12px;border-top:1px solid var(--tma-border);background:var(--tma-bg)}' .
		'.sec-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 14px;font-size:var(--sec-base);' .
			'background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.sec-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;' .
			'cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'.sec-send-btn:active{opacity:.8}' .

		/*
		 * ── Settings ──

		*/
		'.sec-settings-section{margin:0 12px 12px;padding:14px;background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius)}' .
		'.sec-settings-title{font-size:var(--sec-label);font-weight:600;color:var(--tma-hint);margin-bottom:8px;text-transform:uppercase;letter-spacing:.5px}' .
		'.sec-settings-row{display:flex;justify-content:space-between;align-items:center;padding:8px 0;border-bottom:1px solid var(--tma-border)}' .
		'.sec-settings-row:last-child{border-bottom:none}' .
		'.sec-settings-label{font-size:var(--sec-base);color:var(--tma-text)}' .
		'.sec-settings-value{font-size:var(--sec-base);color:var(--tma-hint)}' .
		'.sec-font-btns{display:flex;gap:4px}' .
		'.sec-font-btns button{padding:6px 12px;border:1px solid var(--tma-border);border-radius:6px;background:var(--tma-bg);' .
			'color:var(--tma-text);font-size:var(--sec-label);cursor:pointer}' .
		'.sec-font-btns button.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'.sec-toggle{position:relative;width:44px;height:24px;background:var(--tma-border);border-radius:12px;border:none;cursor:pointer;transition:background .2s}' .
		'.sec-toggle.on{background:var(--tma-btn)}' .
		'.sec-toggle::after{content:"";position:absolute;top:2px;left:2px;width:20px;height:20px;background:#fff;border-radius:50%;transition:transform .2s}' .
		'.sec-toggle.on::after{transform:translateX(20px)}' .
		'.sec-settings-btn{display:block;width:100%;padding:12px;border:1px solid var(--tma-border);border-radius:var(--tma-radius);' .
			'background:var(--tma-bg);color:var(--tma-text);font-size:var(--sec-base);cursor:pointer;text-align:center;margin-top:6px}' .
		'.sec-settings-btn:active{background:var(--tma-secondary-bg)}' .
		'.sec-settings-btn.danger{color:#c62828;border-color:#ef9a9a}' .
		'.sec-connection-dot{display:inline-block;width:8px;height:8px;border-radius:50%;margin-right:6px}' .
		'.sec-connection-dot.online{background:#2e7d32}' .
		'.sec-connection-dot.offline{background:#c62828}' .

		'</style>' .

		/*
		 * ═══ HTML Shell ═══

		*/
		'<div class="tma-shell" id="tma-shell">' .

			/*
			 * ── Header ──

		 */
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">🛍️</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="sec-header-status">' . esc_html__( 'Shopify Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .

			/*
			 * ── Search bar (visible on Products tab) ──

		 */
			'<div class="tma-search-bar" id="sec-search-bar">' .
				'<div class="tma-search-wrap">' .
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
					'<input type="search" id="sec-search-input" placeholder="' . esc_attr__( 'Search products…', 'mcp-ai-wpoos-pro' ) . '" />' .
				'</div>' .
			'</div>' .

			/*
			 * ── Collection filter chips ──

		 */
			'<div class="sec-collection-bar" id="sec-collection-bar">' .
				'<button class="sec-chip active" data-collection="" onclick="secFilterCollection(\'\')">' . esc_html__( 'All', 'mcp-ai-wpoos-pro' ) . '</button>' .
			'</div>' .

			/*
			 * ── Content panes ──

		 */
			'<div class="tma-content">' .

				/*
				 * Tab 1: Products

			 */
				'<div class="tma-tab-pane tma-active" id="tma-tab-products">' .
					'<div class="tma-section-title">' . esc_html__( 'Featured Products', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div class="tma-product-grid" id="sec-product-grid">' .
						'<div class="tma-empty" style="grid-column:span 2">' . esc_html__( 'Loading products…', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 2: AI Assistant

			 */
				'<div class="tma-tab-pane" id="tma-tab-assistant">' .
					'<div class="sec-chat-container">' .
						'<div class="sec-chat-messages" id="sec-chat-messages"></div>' .
						'<div class="sec-chat-input-row">' .
							'<input type="text" id="sec-chat-input" class="sec-chat-input"' .
								' placeholder="' . esc_attr__( 'Ask about products…', 'mcp-ai-wpoos-pro' ) . '" />' .
							'<button class="sec-send-btn" id="sec-send-btn" onclick="secChatSend()">' .
								'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
							'</button>' .
						'</div>' .
					'</div>' .
				'</div>' .

				/*
				 * Tab 3: Settings

			 */
				'<div class="tma-tab-pane" id="tma-tab-settings">' .
					'<div class="tma-section-title">' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</div>' .

					/*
					 * Display section

				 */
					'<div class="sec-settings-section">' .
						'<div class="sec-settings-title">' . esc_html__( 'Display', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="sec-settings-row">' .
							'<span class="sec-settings-label">' . esc_html__( 'Font Size', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<div class="sec-font-btns" id="sec-font-btns">' .
								'<button data-size="small" onclick="secSetFontSize(\'small\')">A-</button>' .
								'<button data-size="medium" class="active" onclick="secSetFontSize(\'medium\')">A</button>' .
								'<button data-size="large" onclick="secSetFontSize(\'large\')">A+</button>' .
							'</div>' .
						'</div>' .
						'<div class="sec-settings-row">' .
							'<span class="sec-settings-label">' . esc_html__( 'Compact Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<button class="sec-toggle" id="sec-compact-toggle" onclick="secToggleCompact()"></button>' .
						'</div>' .
					'</div>' .

					/*
					 * Store section

				 */
					'<div class="sec-settings-section">' .
						'<div class="sec-settings-title">' . esc_html__( 'Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="sec-settings-row">' .
							'<span class="sec-settings-label">' . esc_html__( 'Connection', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="sec-settings-value" id="sec-connection-info"></span>' .
						'</div>' .
						'<div class="sec-settings-row">' .
							'<span class="sec-settings-label">' . esc_html__( 'API Mode', 'mcp-ai-wpoos-pro' ) . '</span>' .
							'<span class="sec-settings-value" id="sec-api-mode-info"></span>' .
						'</div>' .
					'</div>' .

					/*
					 * Data section

				 */
					'<div class="sec-settings-section">' .
						'<div class="sec-settings-title">' . esc_html__( 'Data', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div class="sec-settings-row">' .
							'<span class="sec-settings-label" id="sec-data-summary"></span>' .
						'</div>' .
						'<button class="sec-settings-btn" onclick="secSyncFromServer()">' .
							esc_html__( 'Sync from Server', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
						'<button class="sec-settings-btn danger" onclick="secClearData()">' .
							esc_html__( 'Clear Local Data', 'mcp-ai-wpoos-pro' ) .
						'</button>' .
					'</div>' .
				'</div>' .

			'</div>' . /* End .tma-content */

			/*
			 * ── Bottom navigation (3 tabs) ──

		 */
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-products" onclick="secSwitch(\'products\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' .
					'<span>' . esc_html__( 'Shop', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-assistant" onclick="secSwitch(\'assistant\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
					'<span>' . esc_html__( 'AI', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-settings" onclick="secSwitch(\'settings\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 1 1-2.83 2.83l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 1 1-4 0v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 1 1-2.83-2.83l.06-.06A1.65 1.65 0 0 0 4.68 15a1.65 1.65 0 0 0-1.51-1H3a2 2 0 1 1 0-4h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 1 1 2.83-2.83l.06.06A1.65 1.65 0 0 0 9 4.68a1.65 1.65 0 0 0 1-1.51V3a2 2 0 1 1 4 0v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 1 1 2.83 2.83l-.06.06A1.65 1.65 0 0 0 19.4 9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 1 1 0 4h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>' .
					'<span>' . esc_html__( 'Settings', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' . /* End .tma-shell */

		/*
		 * ═══ JavaScript ═══

		*/
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/*
		 * ── Config variables ──

		*/
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var TMA_TOKEN="";' .
		'var VALIDATE_URL=' . wp_json_encode( $validate_url ) . ';' .
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var CHAT_URL=' . wp_json_encode( $chat_url ) . ';' .
		'var ASSISTANT_ID=' . wp_json_encode( $assistant_id ) . ';' .
		'var SHOPIFY_CONNECTION_ID=' . wp_json_encode( $connection_id ) . ';' .
		'var SHOPIFY_API_MODE=' . wp_json_encode( $shopify_api_mode ) . ';' .
		'var SITE_NAME=' . wp_json_encode( $ctx['site_name'] ) . ';' .

		/*
		 * ── State ──

		*/
		'var activeTab="products";' .
		'var chatHist=[];' .
		'var productsCache=[];' .
		'var activeCollection="";' .

		/*
		 * ── Helpers ──

		*/
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .

		/*
		 * Simple markdown-like renderer for bot messages

		*/
		'function secRenderMd(t){' .
			'var lines=String(t).split("\\n");var out="";var inUl=false;var inOl=false;' .
			'lines.forEach(function(ln){' .
				'function escLn(s){return escH(s).replace(/\\*\\*(.+?)\\*\\*/g,"<strong>$1</strong>").replace(/\\*(.+?)\\*/g,"<em>$1</em>").replace(/`([^`]+)`/g,"<code>$1</code>");}' .
				'if(/^- /.test(ln)){if(!inUl){if(inOl){out+="</ol>";inOl=false;}out+="<ul>";inUl=true;}out+="<li>"+escLn(ln.substring(2))+"</li>";}' .
				'else if(/^\\d+\\. /.test(ln)){if(!inOl){if(inUl){out+="</ul>";inUl=false;}out+="<ol>";inOl=true;}out+="<li>"+escLn(ln.replace(/^\\d+\\.\\s*/,""))+"</li>";}' .
				'else{if(inUl){out+="</ul>";inUl=false;}if(inOl){out+="</ol>";inOl=false;}' .
					'if(ln===""){out+="<br>";}else{out+="<p>"+escLn(ln)+"</p>";}}' .
			'});' .
			'if(inUl)out+="</ul>";if(inOl)out+="</ol>";' .
			'return out;' .
		'}' .

		/*
		 * ── localStorage helpers ──

		*/
		'function lsGet(k,fb){try{var v=localStorage.getItem(k);return v?JSON.parse(v):fb;}catch(e){return fb;}}' .
		'function lsSet(k,v){try{localStorage.setItem(k,JSON.stringify(v));}catch(e){}}' .

		/*
		══════════════════════════════════════════════════════════
			Shopify data extraction helpers
			══════════════════════════════════════════════════════════

		*/

		/*
		 * Normalize tool response: controller returns {success,result} or {data}

		*/
		'function spExtract(raw,key){' .
			'return (raw&&raw.result&&raw.result[key])||(raw&&raw.data&&raw.data[key])||(raw&&raw[key])||' .
				'(raw&&raw.result)||(raw&&raw.data)||null;' .
		'}' .

		/*
		 * Currency formatting helper

		*/
		'function spCurrency(p){' .
			'if(!p)return "$0.00";' .

			/*
			 * Admin API normalized format

		 */
			'if(p.price_range&&p.price_range.minVariantPrice){' .
				'var amt=parseFloat(p.price_range.minVariantPrice.amount||0);' .
				'var cur=p.price_range.minVariantPrice.currencyCode||"USD";' .
				'try{return new Intl.NumberFormat("en-US",{style:"currency",currency:cur}).format(amt);}catch(e){return "$"+amt.toFixed(2);}' .
			'}' .

			/*
			 * Catalog API normalized format (lowercase)

		 */
			'if(p.pricerange&&p.pricerange.minvariantprice){' .
				'var raw=p.pricerange.minvariantprice;' .
				'var cAmt=parseFloat(raw.amount||0);' .
				'if(cAmt>1000)cAmt=cAmt/100;' . /* Catalog API prices are in cents */
				'var cCur=raw.currencycode||raw.currencyCode||"USD";' .
				'try{return new Intl.NumberFormat("en-US",{style:"currency",currency:cCur}).format(cAmt);}catch(e){return "$"+cAmt.toFixed(2);}' .
			'}' .

			/*
			 * Simple price field fallback

		 */
			'if(typeof p.price==="number"||typeof p.price==="string"){return "$"+parseFloat(p.price||0).toFixed(2);}' .
			'return "$0.00";' .
		'}' .

		/*
		 * ── Shopify tool call wrapper ──

		*/
		'function secToolCall(slug,args,cb){' .
			'if(SHOPIFY_CONNECTION_ID)args.connection_id=SHOPIFY_CONNECTION_ID;' .
			'var body={slug:slug,arguments:args};' .
			'fetch(TOOLS_EXEC,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){' .
				'if(!r.ok){' .
					'return r.json().catch(function(){return {};}).then(function(errBody){' .
						'var msg=errBody&&errBody.message?errBody.message:"HTTP "+r.status;' .
						'console.error("[SEC] Tool "+slug+" HTTP "+r.status+":",msg);' .
						'throw new Error(msg);' .
					'});' .
				'}' .
				'return r.json();' .
			'})' .
			'.then(function(d){' .
				'if(d&&d.result&&!d.data){d.data=d.result;}' .
				'cb(null,d);' .
			'})' .
			'.catch(function(e){console.error("[SEC] Tool "+slug+" error:",e);cb(e,null);});' .
		'}' .

		/*
		 * ── Session init ──

		*/
		'function secInitSession(){' .
			'if(!VALIDATE_URL||!window.Telegram||!window.Telegram.WebApp){secBootstrap();return;}' .
			'var initData=window.Telegram.WebApp.initData;' .
			'if(!initData){secBootstrap();return;}' .
			'fetch(VALIDATE_URL,{method:"POST",headers:{"Content-Type":"application/json"},body:JSON.stringify({init_data:initData})})' .
			'.then(function(r){return r.ok?r.json():null;})' .
			'.then(function(d){if(!d){secBootstrap();return;}if(d.wp_nonce){NONCE=d.wp_nonce;}if(d.tma_token){TMA_TOKEN=d.tma_token;}secBootstrap();})' .
			'.catch(function(){secBootstrap();});' .
		'}' .

		'function secBootstrap(){' .
			'secLoadProducts();' .
		'}' .

		/*
		 * ── Display settings ──

		*/
		'function secApplyDisplaySettings(){' .
			'var shell=document.getElementById("tma-shell");if(!shell)return;' .
			'try{' .
				'var size=lsGet("sec_font_size","medium");' .
				'shell.classList.remove("sec-font-small","sec-font-large");' .
				'if(size==="small")shell.classList.add("sec-font-small");' .
				'else if(size==="large")shell.classList.add("sec-font-large");' .
				'var compact=lsGet("sec_compact",false);' .
				'if(compact)shell.classList.add("sec-compact");' .
				'else shell.classList.remove("sec-compact");' .
				'var btns=document.querySelectorAll("#sec-font-btns button");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-size")===size);});' .
				'var tog=document.getElementById("sec-compact-toggle");' .
				'if(tog)tog.classList.toggle("on",!!compact);' .
			'}catch(e){}' .
		'}' .
		'window.secSetFontSize=function(s){lsSet("sec_font_size",s);tmaHaptic("selectionChanged");secApplyDisplaySettings();};' .
		'window.secToggleCompact=function(){var c=!lsGet("sec_compact",false);lsSet("sec_compact",c);tmaHaptic("selectionChanged");secApplyDisplaySettings();};' .

		/*
		 * ── Tab switching ──

		*/
		'window.secSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'var sb=document.getElementById("sec-search-bar");if(sb)sb.style.display=tab==="products"?"":"none";' .
			'var cb=document.getElementById("sec-collection-bar");if(cb)cb.style.display=tab==="products"?"flex":"none";' .
			'activeTab=tab;' .
			'if(tab==="assistant"&&!chatHist.length)secChatInit();' .
			'if(tab==="settings")secRenderSettings();' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Collection filtering
			══════════════════════════════════════════════════════════

		*/
		'window.secFilterCollection=function(col){' .
			'activeCollection=col;tmaHaptic("selectionChanged");' .
			'var btns=document.querySelectorAll("#sec-collection-bar .sec-chip");' .
			'btns.forEach(function(b){' .
				'var bCol=b.getAttribute("data-collection")||"";' .
				'b.classList.toggle("active",bCol===col);' .
			'});' .
			'if(col){' .

				/*
				 * Filter locally from cache

			 */
				'var filtered=productsCache.filter(function(p){' .
					'var pType=(p.product_type||p.productType||p.producttype||"").toLowerCase();' .
					'var pVendor=(p.vendor||"").toLowerCase();' .
					'var tags=Array.isArray(p.tags)?p.tags.join(" ").toLowerCase():"";' .
					'var q=col.toLowerCase();' .
					'return pType.indexOf(q)!==-1||pVendor.indexOf(q)!==-1||tags.indexOf(q)!==-1;' .
				'});' .
				'secRenderProducts(filtered);' .
			'}else{secRenderProducts(productsCache);}' .
		'};' .

		/*
		══════════════════════════════════════════════════════════
			Tab 1 – Products
			══════════════════════════════════════════════════════════

		*/
		'function secLoadProducts(){' .
			'var g=document.getElementById("sec-product-grid");if(!g)return;' .
			'if(!productsCache.length)g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'else{secRenderProducts(productsCache);}' .
			'var limit=SHOPIFY_API_MODE==="catalog_api"?10:20;' . /* Catalog API max is 10 per request */
			'secToolCall("shopify_products",{action:"list",first:limit},function(err,d){' .
				'if(err){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Could not load products.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'var ps=spExtract(d,"products");' .
				'if(!ps||!Array.isArray(ps)){' .

					/*
					 * Catalog API wraps in raw key

				 */
					'if(d&&d.result&&d.result.raw&&Array.isArray(d.result.raw))ps=d.result.raw;' .
					'else if(d&&d.data&&d.data.raw&&Array.isArray(d.data.raw))ps=d.data.raw;' .
					'else if(d&&d.raw&&Array.isArray(d.raw))ps=d.raw;' .
					'else ps=[];' .
				'}' .
				'productsCache=ps;lsSet("sec_products_cache",ps);' .
				'secRenderProducts(ps);' .
				'secBuildCollectionChips(ps);' .
			'});' .
		'}' .

		/*
		 * Build collection chips from product types

		*/
		'function secBuildCollectionChips(ps){' .
			'var bar=document.getElementById("sec-collection-bar");if(!bar)return;' .
			'var types={};' .
			'ps.forEach(function(p){' .
				'var t=p.product_type||p.productType||p.producttype||"";' .
				'if(t)types[t]=(types[t]||0)+1;' .
			'});' .
			'var html=\'<button class="sec-chip active" data-collection="" onclick="secFilterCollection(\\\'\\\')">' . esc_js( __( 'All', 'mcp-ai-wpoos-pro' ) ) . '</button>\';' .
			'Object.keys(types).sort().forEach(function(t){' .
				'html+=\'<button class="sec-chip" data-collection="\'+escH(t)+\'" onclick="secFilterCollection(\\\'\'+escH(t)+\'\\\')">\'+(escH(t))+\' (\'+types[t]+\')</button>\';' .
			'});' .
			'bar.innerHTML=html;' .
		'}' .

		'function secRenderProducts(ps){' .
			'var g=document.getElementById("sec-product-grid");if(!g)return;' .
			'if(!ps.length){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'No products found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
			'g.innerHTML=ps.map(function(p){' .

				/*
				 * Title: Admin API uses title, Catalog API uses displayname

			 */
				'var name=p.title||p.displayname||p.name||"' . esc_js( __( 'Product', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'var priceStr=spCurrency(p);' .
				'var vendor=p.vendor||"";' .

				/*
				 * Vendor badge

			 */
				'var vendorBadge=vendor?"<span class=\\"sec-vendor-badge\\">"+escH(vendor)+"</span>":"";' .

				/*
				 * Product image

			 */
				'var imgUrl="";' .
				'if(p.images&&Array.isArray(p.images)&&p.images[0])imgUrl=p.images[0].url||p.images[0].src||p.images[0];' .
				'else if(p.image)imgUrl=p.image.url||p.image.src||p.image;' .
				'else if(p.image_url)imgUrl=p.image_url;' .

				/*
				 * Catalog API uses media array

			 */
				'else if(p.media&&Array.isArray(p.media)&&p.media[0])imgUrl=p.media[0].url||p.media[0].src||p.media[0];' .
				'var img=imgUrl?"<img src=\\""+escH(imgUrl)+"\\" alt=\\"\\"/>":"🛍️";' .

				/*
				 * Meta line: product type and variant count

			 */
				'var metaParts=[];' .
				'var pType=p.product_type||p.productType||p.producttype||"";' .
				'if(pType)metaParts.push(escH(pType));' .
				'if(p.variants&&Array.isArray(p.variants)&&p.variants.length>1)metaParts.push(p.variants.length+" variants");' .
				'if(p.availableforsale===false)metaParts.push("Sold out");' .
				'var metaHtml=metaParts.length?"<div class=\\"sec-product-meta\\">"+metaParts.join(" · ")+"</div>":"";' .

				'return \'<div class="tma-product-card">' .
					'\'+vendorBadge+\'' .
					'<div class="tma-product-img">\'+img+\'</div>' .
					'<div class="tma-product-body">' .
						'<div class="tma-product-name">\'+escH(name)+\'</div>' .
						'<div class="tma-product-price">\'+priceStr+\'</div>' .
						'\'+metaHtml+\'' .
					'</div></div>\';' .
			'}).join("");' .
		'}' .

		/*
		 * Debounced search

		*/
		'var searchTimer=null;' .
		'document.getElementById("sec-search-input").addEventListener("input",function(e){' .
			'clearTimeout(searchTimer);var q=e.target.value.trim();' .
			'searchTimer=setTimeout(function(){' .
				'activeCollection="";' .
				'var btns=document.querySelectorAll("#sec-collection-bar .sec-chip");' .
				'btns.forEach(function(b){b.classList.toggle("active",b.getAttribute("data-collection")==="");});' .
				'if(q){' .

					/*
					 * Filter cached products locally by name match.

				 */
					'var filtered=productsCache.filter(function(p){' .
						'var name=(p.title||p.displayname||p.name||"").toLowerCase();' .
						'return name.indexOf(q.toLowerCase())!==-1;' .
					'});' .
					'secRenderProducts(filtered);' .
				'}else{secRenderProducts(productsCache);}' .
			'},400);' .
		'});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 2 – AI Assistant
			══════════════════════════════════════════════════════════

		*/
		'function secChatInit(){' .
			'chatHist=lsGet("sec_chat_hist",[]);' .
			'var m=document.getElementById("sec-chat-messages");if(!m)return;m.innerHTML="";' .
			'if(chatHist.length){' .
				'chatHist.forEach(function(msg){secAppendMsg(msg.role==="user"?"user":"bot",msg.content,true);});' .
			'}else{' .
				'var ctx="[' . esc_js( __( 'Shopify store context', 'mcp-ai-wpoos-pro' ) ) . '] ' . esc_js( __( 'Site', 'mcp-ai-wpoos-pro' ) ) . ': "+SITE_NAME+", ' .
					esc_js( __( 'Cached products', 'mcp-ai-wpoos-pro' ) ) . ': "+productsCache.length+", API: "+SHOPIFY_API_MODE;' .
				'chatHist.push({role:"system",content:ctx});' .
				'secAppendMsg("bot","' . esc_js( __( 'Hi! I\'m your Shopify shopping assistant. I can help you find products and answer questions about our store.', 'mcp-ai-wpoos-pro' ) ) . '",false);' .
			'}' .
		'}' .

		'function secAppendMsg(role,text,isRestore){' .
			'var el=document.createElement("div");el.className="sec-msg "+role;' .
			'if(role==="bot"){el.innerHTML=secRenderMd(text);}' .
			'else{el.textContent=text;}' .
			'var m=document.getElementById("sec-chat-messages");' .
			'if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;' .
		'}' .

		'window.secChatSend=function(){' .
			'var inp=document.getElementById("sec-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'chatHist.push({role:"user",content:txt});secAppendMsg("user",txt,false);' .
			'lsSet("sec_chat_hist",chatHist.slice(-50));' .
			'var el=secAppendMsg("bot","\\u2026",false);' .
			'var body={messages:chatHist.filter(function(m){return m.role!=="system";}).slice(-12)};' .
			'if(ASSISTANT_ID)body.assistant_id=ASSISTANT_ID;' .
			'var sys=chatHist.find(function(m){return m.role==="system";});' .
			'if(sys)body.messages.unshift(sys);' .
			'fetch(CHAT_URL,{method:"POST",headers:tmaToolHeaders(),body:JSON.stringify(body)})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var data=d&&d.data;' .
				'var rep=(data&&data.choices&&data.choices[0]&&data.choices[0].message&&data.choices[0].message.content)||' .
					'(data&&data.content)||(data&&data.response)||"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.innerHTML=secRenderMd(rep);chatHist.push({role:"assistant",content:rep});' .
				'lsSet("sec_chat_hist",chatHist.slice(-50));' .
			'})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .

		/*
		 * Enter to send

		*/
		'document.getElementById("sec-chat-input").addEventListener("keydown",function(e){if(e.key==="Enter")secChatSend();});' .

		/*
		══════════════════════════════════════════════════════════
			Tab 3 – Settings
			══════════════════════════════════════════════════════════

		*/
		'function secRenderSettings(){' .
			'var ci=document.getElementById("sec-connection-info");' .
			'if(ci){' .
				'if(SHOPIFY_CONNECTION_ID){' .
					'ci.innerHTML=\'<span class="sec-connection-dot online"></span>' . esc_js( __( 'Shopify Store', 'mcp-ai-wpoos-pro' ) ) . '\';' .
				'}else{' .
					'ci.innerHTML=\'<span class="sec-connection-dot offline"></span>' . esc_js( __( 'Not Connected', 'mcp-ai-wpoos-pro' ) ) . '\';' .
				'}' .
			'}' .
			'var am=document.getElementById("sec-api-mode-info");' .
			'if(am)am.textContent=SHOPIFY_API_MODE==="catalog_api"?"Catalog API":"Admin API";' .
			'var ds=document.getElementById("sec-data-summary");' .
			'if(ds)ds.textContent="' . esc_js( __( 'Cached products', 'mcp-ai-wpoos-pro' ) ) . ': "+productsCache.length+", ' .
				esc_js( __( 'Chat messages', 'mcp-ai-wpoos-pro' ) ) . ': "+chatHist.length;' .
		'}' .

		'window.secSyncFromServer=function(){' .
			'tmaHaptic("medium");secLoadProducts();' .
		'};' .

		'window.secClearData=function(){' .
			'var msg="' . esc_js( __( 'Clear all local data? This cannot be undone.', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'if(window.Telegram&&window.Telegram.WebApp){' .
				'window.Telegram.WebApp.showConfirm(msg,function(ok){if(ok)secDoClear();});' .
			'}else if(confirm(msg)){secDoClear();}' .
		'};' .

		'function secDoClear(){' .
			'try{' .
				'localStorage.removeItem("sec_products_cache");' .
				'localStorage.removeItem("sec_chat_hist");' .
				'localStorage.removeItem("sec_font_size");' .
				'localStorage.removeItem("sec_compact");' .
			'}catch(e){}' .
			'productsCache=[];chatHist=[];activeCollection="";' .
			'secRenderSettings();tmaHaptic("notificationSuccess");' .
		'}' .

		/*
		══════════════════════════════════════════════════════════
			Init
			══════════════════════════════════════════════════════════

		*/
		'productsCache=lsGet("sec_products_cache",[]);' .
		'secApplyDisplaySettings();' .

		'if(productsCache.length)secRenderProducts(productsCache);' .

		'secInitSession();' .

		'})();</script></body>';
		// phpcs:enable WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
