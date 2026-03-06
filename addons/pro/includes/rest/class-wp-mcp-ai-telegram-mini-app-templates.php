<?php
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
 */

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
	 *                    login_url, chart_js_url, site_name, nonce, page_title.
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
	 * Each item contains: slug, name, description, icon, accent_color, toolkit.
	 *
	 * @since 1.1.3
	 *
	 * @return array<int,array<string,string>>
	 */
	public static function get_all_meta() {
		$meta = array();
		foreach ( self::instance()->all() as $tpl ) {
			$meta[] = array(
				'slug'         => $tpl->get_slug(),
				'name'         => $tpl->get_name(),
				'description'  => $tpl->get_description(),
				'icon'         => $tpl->get_icon(),
				'accent_color' => $tpl->get_accent_color(),
				'toolkit'      => $tpl->get_toolkit(),
			);
		}
		return $meta;
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
		$this->register( new WP_MCP_AI_TMA_Template_CRM() );
		$this->register( new WP_MCP_AI_TMA_Template_Analytics() );
		$this->register( new WP_MCP_AI_TMA_Template_Booking() );
		$this->register( new WP_MCP_AI_TMA_Template_Health_Wellness() );

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

/* ==========================================================================
   Shared helpers used by non-default templates
   ========================================================================== */

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
	'function tmaHaptic(t){if(twa&&twa.HapticFeedback)twa.HapticFeedback.impactOccurred(t||"light");}' .
	'tmaApplyTheme();tmaUpdateVH();' .
	'if(twa){twa.onEvent("viewportChanged",tmaUpdateVH);twa.onEvent("themeChanged",tmaApplyTheme);twa.ready();}' .
	'window.addEventListener("resize",tmaUpdateVH);';
}

/* ==========================================================================
   Built-in Template Classes
   ========================================================================== */

/**
 * Default template – delegates to the controller's existing built-in render.
 */
class WP_MCP_AI_TMA_Template_Default extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'default';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'Content Manager (Default)', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Full-featured CMS template with analytics dashboard, content editor, tools executor, media library, shop, and settings.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_icon() {
		return '📋';
	}

	/** @inheritdoc */
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

	/** @inheritdoc */
	public function get_slug() {
		return 'ai_chat';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'AI Chat', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Clean conversational interface powered by the AI assistant. Perfect for customer-facing chatbots.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'chat_channels';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '💬';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#4CAF50';
	}

	/** @inheritdoc */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$chat_api_url = rest_url( 'mcp-ai/v1/chat' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped -- standalone HTML document; all values escaped inline.
		return '<body class="wp-mcp-ai-telegram-mini-app tma-ai-chat-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .
		'.tma-chat-messages{flex:1;overflow-y:auto;-webkit-overflow-scrolling:touch;padding:16px 12px;display:flex;flex-direction:column;gap:8px}' .
		'.tma-chat-welcome{text-align:center;padding:40px 20px;color:var(--tma-hint)}' .
		'.tma-welcome-icon{font-size:48px;margin-bottom:12px}' .
		'.tma-msg{max-width:80%;padding:10px 14px;border-radius:18px;font-size:14px;line-height:1.5;word-wrap:break-word}' .
		'.tma-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text);border-bottom-right-radius:4px}' .
		'.tma-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text);border-bottom-left-radius:4px}' .
		'.tma-msg.loading::after{content:"...";animation:tma-dots 1s steps(3,end) infinite}' .
		'@keyframes tma-dots{0%,33%{content:"."}33%,66%{content:".."}66%,100%{content:"..."}}' .
		'.tma-chat-input-wrap{display:flex;align-items:flex-end;gap:8px;padding:8px 12px;background:var(--tma-secondary-bg);border-top:1px solid var(--tma-border);flex-shrink:0}' .
		'.tma-chat-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:10px 16px;font-size:15px;background:var(--tma-bg);color:var(--tma-text);resize:none;outline:none;max-height:120px;overflow-y:auto;font-family:inherit;line-height:1.4}' .
		'.tma-send-btn{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:40px;height:40px;min-width:40px;cursor:pointer;display:flex;align-items:center;justify-content:center;flex-shrink:0;-webkit-tap-highlight-color:transparent}' .
		'.tma-send-btn:active{opacity:.7}' .
		'</style>' .
		'<div class="tma-shell" id="tma-shell">' .
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">💬</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="tma-status-text">' . esc_html__( 'AI Assistant', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="tma-header-actions">' .
					'<button class="tma-icon-btn" title="' . esc_attr__( 'Clear chat', 'mcp-ai-wpoos-pro' ) . '" onclick="tmaClearChat()">' .
						'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6l-1 14a2 2 0 0 1-2 2H8a2 2 0 0 1-2-2L5 6"/><line x1="10" y1="11" x2="10" y2="17"/><line x1="14" y1="11" x2="14" y2="17"/></svg>' .
					'</button>' .
				'</div>' .
			'</header>' .
			'<div class="tma-chat-messages" id="tma-messages">' .
				'<div class="tma-chat-welcome"><div class="tma-welcome-icon">🤖</div>' .
				'<p>' . esc_html__( 'Hello! How can I assist you today?', 'mcp-ai-wpoos-pro' ) . '</p></div>' .
			'</div>' .
			'<div class="tma-chat-input-wrap">' .
				'<textarea id="tma-chat-input" class="tma-chat-input" rows="1"' .
					' placeholder="' . esc_attr__( 'Type a message…', 'mcp-ai-wpoos-pro' ) . '"' .
					' onkeydown="tmaChatKeydown(event)" oninput="tmaChatAutoResize(this)"></textarea>' .
				'<button class="tma-send-btn" onclick="tmaSendMessage()">' .
					'<svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
				'</button>' .
			'</div>' .
		'</div>' .
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .
		'var chatUrl=' . wp_json_encode( $chat_api_url ) . ';' .
		'var nonce=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var sk="wp_mcp_ai_tma_ai_chat";var hist=[];var busy=false;' .
		'try{var s=localStorage.getItem(sk);if(s)hist=JSON.parse(s)||[];}catch(e){}' .
		'if(hist.length){var m=document.getElementById("tma-messages");if(m){m.innerHTML="";hist.forEach(function(h){appendMsg(h.role,h.content);});}}' .
		'function save(){try{localStorage.setItem(sk,JSON.stringify(hist.slice(-50)));}catch(e){}}' .
		'function appendMsg(role,content){' .
			'var el=document.createElement("div");el.className="tma-msg "+(role==="user"?"user":"bot");el.textContent=content;' .
			'var m=document.getElementById("tma-messages");' .
			'if(m){var w=m.querySelector(".tma-chat-welcome");if(w)w.remove();m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'return el;}' .
		'window.tmaSendMessage=function(){' .
			'if(busy)return;' .
			'var inp=document.getElementById("tma-chat-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;' .
			'inp.value="";inp.style.height="";tmaHaptic("light");' .
			'appendMsg("user",txt);hist.push({role:"user",content:txt});save();' .
			'busy=true;var el=appendMsg("bot","");el.classList.add("loading");' .
			'var st=document.getElementById("tma-status-text");if(st)st.textContent="Thinking\u2026";' .
			'fetch(chatUrl,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({message:txt,history:hist.slice(-10)})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){var rep=(d&&d.response)?d.response:"Sorry, I could not process that.";' .
				'el.classList.remove("loading");el.textContent=rep;hist.push({role:"assistant",content:rep});save();})' .
			'.catch(function(){el.classList.remove("loading");el.textContent="Connection error. Please try again.";})' .
			'.finally(function(){busy=false;if(st)st.textContent="AI Assistant";' .
				'var m=document.getElementById("tma-messages");if(m)m.scrollTop=m.scrollHeight;});' .
		'};' .
		'window.tmaClearChat=function(){hist=[];save();tmaHaptic("medium");' .
			'var m=document.getElementById("tma-messages");' .
			'if(m)m.innerHTML=\'<div class="tma-chat-welcome"><div class="tma-welcome-icon">🤖</div><p>' . esc_js( __( 'Hello! How can I assist you today?', 'mcp-ai-wpoos-pro' ) ) . '</p></div>\';};' .
		'window.tmaChatKeydown=function(e){if(e.key==="Enter"&&!e.shiftKey){e.preventDefault();tmaSendMessage();}};' .
		'window.tmaChatAutoResize=function(el){el.style.height="";el.style.height=Math.min(el.scrollHeight,120)+"px";};' .
		'})();</script>' .
		'</body>';
		// phpcs:enable
	}
}

/**
 * E-commerce template – WooCommerce shop assistant.
 */
class WP_MCP_AI_TMA_Template_Ecommerce extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'ecommerce';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'E-Commerce Store', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Shop assistant with product search, order tracking, and AI-powered recommendations. Designed for WooCommerce stores.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'ecommerce';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '🛒';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#9c27b0';
	}

	/** @inheritdoc */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$chat_api_url = rest_url( 'mcp-ai/v1/chat' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-ecommerce-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .
		':root{--tma-btn:#9c27b0;--tma-accent:#9c27b0;--tma-secondary-bg:#f8f4fb;}' .
		'.tma-search-bar{padding:10px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border)}' .
		'.tma-search-wrap{display:flex;align-items:center;gap:8px;background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:10px;padding:0 12px}' .
		'.tma-search-wrap input{flex:1;border:none;outline:none;font-size:14px;padding:10px 0;background:transparent;color:var(--tma-text)}' .
		'.tma-product-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;padding:10px 12px}' .
		'.tma-product-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);overflow:hidden;cursor:pointer}' .
		'.tma-product-card:active{opacity:.8}' .
		'.tma-product-img{width:100%;aspect-ratio:1;object-fit:cover;background:var(--tma-secondary-bg);display:flex;align-items:center;justify-content:center;font-size:32px}' .
		'.tma-product-body{padding:8px 10px}' .
		'.tma-product-name{font-size:13px;font-weight:600;margin-bottom:2px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-product-price{font-size:14px;color:var(--tma-btn);font-weight:700}' .
		'.tma-order-item{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);margin:0 12px 8px;padding:14px}' .
		'.tma-order-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:6px}' .
		'.tma-order-id{font-weight:700;font-size:14px}' .
		'.tma-order-badge{font-size:11px;padding:2px 8px;border-radius:10px;background:var(--tma-secondary-bg);color:var(--tma-hint);font-weight:600}' .
		'.tma-order-meta{font-size:12px;color:var(--tma-hint)}' .
		'.tma-chat-fab{position:fixed;bottom:calc(var(--tma-nav-height)+16px);right:16px;width:48px;height:48px;' .
			'background:var(--tma-btn);color:var(--tma-btn-text);border-radius:50%;border:none;' .
			'display:flex;align-items:center;justify-content:center;cursor:pointer;' .
			'box-shadow:0 4px 12px rgba(0,0,0,.2);z-index:50;-webkit-tap-highlight-color:transparent}' .
		'.tma-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:100;display:none}' .
		'.tma-overlay.open{display:flex;align-items:flex-end}' .
		'.tma-drawer{background:var(--tma-bg);border-radius:20px 20px 0 0;width:100%;padding:20px;max-height:70vh;overflow-y:auto}' .
		'.tma-drawer-handle{width:36px;height:4px;background:var(--tma-border);border-radius:2px;margin:0 auto 16px}' .
		'.tma-drawer-messages{height:200px;overflow-y:auto;display:flex;flex-direction:column;gap:6px;margin-bottom:10px}' .
		'.tma-msg{max-width:85%;padding:8px 12px;border-radius:14px;font-size:13px;line-height:1.4}' .
		'.tma-msg.user{align-self:flex-end;background:var(--tma-btn);color:var(--tma-btn-text)}' .
		'.tma-msg.bot{align-self:flex-start;background:var(--tma-secondary-bg);color:var(--tma-text)}' .
		'.tma-drawer-input-row{display:flex;gap:8px}' .
		'.tma-drawer-input{flex:1;border:1px solid var(--tma-border);border-radius:20px;padding:8px 14px;font-size:14px;background:var(--tma-bg);color:var(--tma-text);outline:none}' .
		'.tma-send-btn-sm{background:var(--tma-btn);color:var(--tma-btn-text);border:none;border-radius:50%;width:36px;height:36px;min-width:36px;cursor:pointer;display:flex;align-items:center;justify-content:center}' .
		'</style>' .
		'<div class="tma-shell" id="tma-shell">' .
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">🛒</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status">' . esc_html__( 'Online Store', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .
			'<div class="tma-search-bar">' .
				'<div class="tma-search-wrap">' .
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
					'<input type="search" placeholder="' . esc_attr__( 'Search products…', 'mcp-ai-wpoos-pro' ) . '" oninput="tmaSearchProducts(this.value)" />' .
				'</div>' .
			'</div>' .
			'<div class="tma-content">' .
				'<div class="tma-tab-pane tma-active" id="tma-tab-shop">' .
					'<div class="tma-section-title">' . esc_html__( 'Featured Products', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div class="tma-product-grid" id="tma-product-grid">' .
						'<div class="tma-empty" style="grid-column:span 2">' . esc_html__( 'Loading products…', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'</div>' .
				'</div>' .
				'<div class="tma-tab-pane" id="tma-tab-orders">' .
					'<div class="tma-section-title">' . esc_html__( 'My Orders', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'<div id="tma-orders-list"><div class="tma-empty">' . esc_html__( 'Loading orders…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .
			'</div>' .
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-shop" onclick="tmaSwitch(\'shop\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M6 2L3 6v14a2 2 0 0 0 2 2h14a2 2 0 0 0 2-2V6l-3-4z"/><line x1="3" y1="6" x2="21" y2="6"/><path d="M16 10a4 4 0 0 1-8 0"/></svg>' .
					'<span>' . esc_html__( 'Shop', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-orders" onclick="tmaSwitch(\'orders\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/><line x1="16" y1="13" x2="8" y2="13"/><line x1="16" y1="17" x2="8" y2="17"/></svg>' .
					'<span>' . esc_html__( 'Orders', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' .
		'<button class="tma-chat-fab" onclick="tmaOpenDrawer()">' .
			'<svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg>' .
		'</button>' .
		'<div class="tma-overlay" id="tma-overlay" onclick="tmaCloseDrawer()">' .
			'<div class="tma-drawer" onclick="event.stopPropagation()">' .
				'<div class="tma-drawer-handle"></div>' .
				'<div style="font-weight:700;font-size:16px;margin-bottom:12px">' . esc_html__( 'AI Shopping Assistant', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-drawer-messages" id="tma-drawer-msgs"></div>' .
				'<div class="tma-drawer-input-row">' .
					'<input type="text" id="tma-drawer-input" class="tma-drawer-input"' .
						' placeholder="' . esc_attr__( 'Ask about products…', 'mcp-ai-wpoos-pro' ) . '"' .
						' onkeydown="if(event.key===\'Enter\')tmaDrawerSend()" />' .
					'<button class="tma-send-btn-sm" onclick="tmaDrawerSend()">' .
						'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="22" y1="2" x2="11" y2="13"/><polygon points="22 2 15 22 11 13 2 9 22 2"/></svg>' .
					'</button>' .
				'</div>' .
			'</div>' .
		'</div>' .
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .
		'var toolsExec=' . wp_json_encode( $tools_exec ) . ';' .
		'var chatUrl=' . wp_json_encode( $chat_api_url ) . ';' .
		'var nonce=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var activeTab="shop";var chatHist=[];' .
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'window.tmaSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'activeTab=tab;if(tab==="orders")loadOrders();' .
		'};' .
		'function loadProducts(q){' .
			'var g=document.getElementById("tma-product-grid");if(!g)return;' .
			'g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(toolsExec,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({tool:"search_woocommerce_products",arguments:{search:q||"",per_page:10}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var ps=(d&&d.data&&d.data.products)?d.data.products:[];' .
				'if(!ps.length){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'No products found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'g.innerHTML=ps.map(function(p){return \'<div class="tma-product-card"><div class="tma-product-img">🛍️</div><div class="tma-product-body"><div class="tma-product-name">\'+escH(p.name||"Product")+\'</div><div class="tma-product-price">\'+escH(p.price_html||p.price||"")+\'</div></div></div>\';}).join("");' .
			'}).catch(function(){g.innerHTML=\'<div class="tma-empty" style="grid-column:span 2">' . esc_js( __( 'Could not load products.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .
		'var st=null;window.tmaSearchProducts=function(q){clearTimeout(st);st=setTimeout(function(){loadProducts(q);},400);};' .
		'function loadOrders(){' .
			'var l=document.getElementById("tma-orders-list");if(!l)return;' .
			'l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading orders…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(toolsExec,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({tool:"get_woocommerce_orders",arguments:{per_page:10}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var os=(d&&d.data&&d.data.orders)?d.data.orders:[];' .
				'if(!os.length){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No orders found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'l.innerHTML=os.map(function(o){var s=o.status||"processing";' .
					'return \'<div class="tma-order-item"><div class="tma-order-header"><span class="tma-order-id">#\'+escH(o.id||"")+\'</span><span class="tma-order-badge">\'+escH(s)+\'</span></div><div class="tma-order-meta">\'+escH(o.date_created||"")+\'</div></div>\';' .
				'}).join("");' .
			'}).catch(function(){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load orders.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .
		'window.tmaOpenDrawer=function(){tmaHaptic("light");document.getElementById("tma-overlay").classList.add("open");' .
			'if(!chatHist.length){appendDrawer("bot","' . esc_js( __( 'Hi! I can help you find products or check your orders. What can I do for you?', 'mcp-ai-wpoos-pro' ) ) . '");}};' .
		'window.tmaCloseDrawer=function(){document.getElementById("tma-overlay").classList.remove("open");};' .
		'function appendDrawer(role,text){var el=document.createElement("div");el.className="tma-msg "+role;el.textContent=text;var m=document.getElementById("tma-drawer-msgs");if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}}' .
		'window.tmaDrawerSend=function(){' .
			'var inp=document.getElementById("tma-drawer-input");if(!inp)return;' .
			'var txt=(inp.value||"").trim();if(!txt)return;inp.value="";tmaHaptic("light");' .
			'appendDrawer("user",txt);chatHist.push({role:"user",content:txt});' .
			'var el=document.createElement("div");el.className="tma-msg bot";el.textContent="\u2026";' .
			'var m=document.getElementById("tma-drawer-msgs");if(m){m.appendChild(el);m.scrollTop=m.scrollHeight;}' .
			'fetch(chatUrl,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({message:txt,history:chatHist.slice(-6)})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){var rep=(d&&d.response)?d.response:"' . esc_js( __( 'Sorry, please try again.', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'el.textContent=rep;chatHist.push({role:"assistant",content:rep});})' .
			'.catch(function(){el.textContent="' . esc_js( __( 'Connection error.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .
		'loadProducts();' .
		'})();</script></body>';
		// phpcs:enable
	}
}

/**
 * CRM template – customer relationship management interface.
 */
class WP_MCP_AI_TMA_Template_CRM extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'crm';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'CRM Assistant', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Customer management interface with contact lookup, lead pipeline, and AI-powered follow-up drafts.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'crm';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '👥';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#e65100';
	}

	/** @inheritdoc */
	public function render_html( array $ctx ) {
		$site_name  = esc_html( $ctx['site_name'] );
		$tools_exec = $ctx['tools_url'] . '/execute';
		$chat_url   = rest_url( 'mcp-ai/v1/chat' );
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-crm-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .
		':root{--tma-btn:#e65100;--tma-accent:#e65100;--tma-secondary-bg:#fff3ee;}' .
		'.tma-search-bar{padding:10px 12px;background:var(--tma-secondary-bg);border-bottom:1px solid var(--tma-border)}' .
		'.tma-search-wrap{display:flex;align-items:center;gap:8px;background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:10px;padding:0 12px}' .
		'.tma-search-wrap input{flex:1;border:none;outline:none;font-size:14px;padding:10px 0;background:transparent;color:var(--tma-text)}' .
		'.tma-contact-row{display:flex;align-items:center;gap:12px;padding:12px 16px;border-bottom:1px solid var(--tma-border);cursor:pointer}' .
		'.tma-contact-row:active{background:var(--tma-secondary-bg)}' .
		'.tma-contact-avatar{width:40px;height:40px;border-radius:50%;background:var(--tma-btn);color:var(--tma-btn-text);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:16px;flex-shrink:0}' .
		'.tma-contact-info{flex:1;min-width:0}' .
		'.tma-contact-name{font-weight:600;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-contact-sub{font-size:12px;color:var(--tma-hint);white-space:nowrap;overflow:hidden;text-overflow:ellipsis}' .
		'.tma-pipeline{display:flex;gap:10px;padding:10px 12px;overflow-x:auto;-webkit-overflow-scrolling:touch}' .
		'.tma-pipeline-col{min-width:160px;background:var(--tma-secondary-bg);border-radius:var(--tma-radius);padding:10px;flex-shrink:0}' .
		'.tma-pipeline-label{font-size:11px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:var(--tma-hint);margin-bottom:8px}' .
		'.tma-pipeline-card{background:var(--tma-bg);border:1px solid var(--tma-border);border-radius:8px;padding:10px;margin-bottom:8px;font-size:13px}' .
		'</style>' .
		'<div class="tma-shell" id="tma-shell">' .
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">👥</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status">' . esc_html__( 'CRM', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .
			'<div class="tma-search-bar">' .
				'<div class="tma-search-wrap">' .
					'<svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>' .
					'<input type="search" placeholder="' . esc_attr__( 'Search contacts…', 'mcp-ai-wpoos-pro' ) . '" oninput="tmaSearchContacts(this.value)" />' .
				'</div>' .
			'</div>' .
			'<div class="tma-content">' .
				'<div class="tma-tab-pane tma-active" id="tma-tab-contacts">' .
					'<div id="tma-contact-list"><div class="tma-empty">' . esc_html__( 'Loading contacts…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .
				'<div class="tma-tab-pane" id="tma-tab-pipeline">' .
					'<div class="tma-pipeline" id="tma-pipeline"><div class="tma-empty">' . esc_html__( 'Loading pipeline…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'</div>' .
				'<div class="tma-tab-pane" id="tma-tab-compose">' .
					'<div style="padding:16px">' .
						'<div class="tma-section-title" style="padding:0 0 8px">' . esc_html__( 'AI Follow-up Draft', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<textarea id="tma-compose-ctx" class="tma-input" rows="3" style="margin-bottom:8px;resize:none" placeholder="' . esc_attr__( 'Describe the customer or situation…', 'mcp-ai-wpoos-pro' ) . '"></textarea>' .
						'<button class="tma-btn tma-btn-primary" style="width:100%;margin-bottom:12px" onclick="tmaGenerateDraft()">' . esc_html__( '✨ Generate Draft', 'mcp-ai-wpoos-pro' ) . '</button>' .
						'<textarea id="tma-compose-draft" class="tma-input" rows="6" style="resize:none" placeholder="' . esc_attr__( 'Your AI-generated message will appear here…', 'mcp-ai-wpoos-pro' ) . '"></textarea>' .
					'</div>' .
				'</div>' .
			'</div>' .
			'<nav class="tma-nav">' .
				'<button class="tma-nav-btn tma-active" id="tma-nav-contacts" onclick="tmaSwitch(\'contacts\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>' .
					'<span>' . esc_html__( 'Contacts', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-pipeline" onclick="tmaSwitch(\'pipeline\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>' .
					'<span>' . esc_html__( 'Pipeline', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
				'<button class="tma-nav-btn" id="tma-nav-compose" onclick="tmaSwitch(\'compose\')">' .
					'<svg class="tma-nav-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>' .
					'<span>' . esc_html__( 'Compose', 'mcp-ai-wpoos-pro' ) . '</span>' .
				'</button>' .
			'</nav>' .
		'</div>' .
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .
		'var toolsExec=' . wp_json_encode( $tools_exec ) . ';' .
		'var chatUrl=' . wp_json_encode( $chat_url ) . ';' .
		'var nonce=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var activeTab="contacts";' .
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'window.tmaSwitch=function(tab){' .
			'if(tab===activeTab)return;tmaHaptic("selectionChanged");' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(el){el.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-tab-"+tab);var btn=document.getElementById("tma-nav-"+tab);' .
			'if(pane)pane.classList.add("tma-active");if(btn)btn.classList.add("tma-active");' .
			'activeTab=tab;if(tab==="contacts")loadContacts();if(tab==="pipeline")loadPipeline();' .
		'};' .
		'function loadContacts(q){' .
			'var l=document.getElementById("tma-contact-list");if(!l)return;' .
			'l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(toolsExec,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({tool:"list_crm_contacts",arguments:{search:q||"",per_page:20}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var cs=(d&&d.data&&d.data.contacts)?d.data.contacts:[];' .
				'if(!cs.length){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'No contacts found.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';return;}' .
				'l.innerHTML=cs.map(function(c){var init=(c.name||"?").charAt(0).toUpperCase();' .
					'return \'<div class="tma-contact-row"><div class="tma-contact-avatar">\'+escH(init)+\'</div><div class="tma-contact-info"><div class="tma-contact-name">\'+escH(c.name||"Unknown")+\'</div><div class="tma-contact-sub">\'+escH(c.email||c.phone||"")+\'</div></div></div>\';' .
				'}).join("");' .
			'}).catch(function(){l.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load contacts.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .
		'var st=null;window.tmaSearchContacts=function(q){clearTimeout(st);st=setTimeout(function(){loadContacts(q);},400);};' .
		'function loadPipeline(){' .
			'var w=document.getElementById("tma-pipeline");if(!w)return;' .
			'w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(toolsExec,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({tool:"get_crm_pipeline",arguments:{}})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var stages=(d&&d.data&&d.data.stages)?d.data.stages:[{label:"' . esc_js( __( 'Lead', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]},{label:"' . esc_js( __( 'Qualified', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]},{label:"' . esc_js( __( 'Won', 'mcp-ai-wpoos-pro' ) ) . '",contacts:[]}];' .
				'w.innerHTML=stages.map(function(s){' .
					'var cards=(s.contacts||[]).map(function(c){return \'<div class="tma-pipeline-card"><div style="font-weight:600">\'+escH(c.name||"Contact")+\'</div><div style="font-size:11px;color:var(--tma-hint)">\'+escH(c.value||"")+\'</div></div>\';}).join("");' .
					'return \'<div class="tma-pipeline-col"><div class="tma-pipeline-label">\'+escH(s.label)+\'</div>\'+cards+\'</div>\';' .
				'}).join("");' .
			'}).catch(function(){w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Pipeline unavailable.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'}' .
		'window.tmaGenerateDraft=function(){' .
			'var ctx=(document.getElementById("tma-compose-ctx")||{}).value||"";' .
			'var draft=document.getElementById("tma-compose-draft");' .
			'if(!ctx.trim()){if(draft)draft.value="' . esc_js( __( 'Please describe the customer or situation first.', 'mcp-ai-wpoos-pro' ) ) . '";return;}' .
			'if(draft)draft.value="' . esc_js( __( 'Generating…', 'mcp-ai-wpoos-pro' ) ) . '";' .
			'fetch(chatUrl,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({message:"Write a professional follow-up message for: "+ctx})})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){if(draft)draft.value=(d&&d.response)?d.response:"' . esc_js( __( 'Could not generate draft.', 'mcp-ai-wpoos-pro' ) ) . '";})' .
			'.catch(function(){if(draft)draft.value="' . esc_js( __( 'Error generating draft.', 'mcp-ai-wpoos-pro' ) ) . '";});' .
		'};' .
		'loadContacts();' .
		'})();</script></body>';
		// phpcs:enable
	}
}

/**
 * Analytics template – data dashboard with Chart.js visualisations.
 */
class WP_MCP_AI_TMA_Template_Analytics extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'analytics';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'Analytics Dashboard', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Real-time site analytics with Chart.js visualisations, KPI cards, and AI-powered insights.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'analytics';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '📊';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#00796b';
	}

	/** @inheritdoc */
	public function render_html( array $ctx ) {
		$site_name     = esc_html( $ctx['site_name'] );
		$analytics_url = $ctx['analytics_url'];
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-analytics-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .
		':root{--tma-btn:#00796b;--tma-accent:#00796b;--tma-secondary-bg:#e0f2f1;}' .
		'.tma-analytics-wrap{padding:12px;overflow-y:auto;height:100%}' .
		'.tma-kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin-bottom:14px}' .
		'.tma-kpi-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:14px;text-align:center}' .
		'.tma-kpi-value{font-size:28px;font-weight:700;color:var(--tma-btn);line-height:1.1;margin-bottom:4px}' .
		'.tma-kpi-label{font-size:11px;text-transform:uppercase;letter-spacing:.5px;color:var(--tma-hint)}' .
		'.tma-chart-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);border-radius:var(--tma-radius);padding:14px;margin-bottom:14px}' .
		'.tma-chart-title{font-size:13px;font-weight:600;margin-bottom:10px}' .
		'.tma-period-bar{display:flex;gap:8px;margin-bottom:14px}' .
		'.tma-period-btn{flex:1;padding:7px;border:1px solid var(--tma-border);border-radius:8px;background:var(--tma-secondary-bg);color:var(--tma-hint);font-size:12px;font-weight:600;cursor:pointer}' .
		'.tma-period-btn.active{background:var(--tma-btn);color:var(--tma-btn-text);border-color:var(--tma-btn)}' .
		'</style>' .
		'<div class="tma-shell" id="tma-shell">' .
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">📊</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status" id="tma-update-time">' . esc_html__( 'Analytics', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
				'<div class="tma-header-actions">' .
					'<button class="tma-icon-btn" title="' . esc_attr__( 'Refresh', 'mcp-ai-wpoos-pro' ) . '" onclick="loadAnalytics()">' .
						'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>' .
					'</button>' .
				'</div>' .
			'</header>' .
			'<div class="tma-content">' .
				'<div class="tma-tab-pane tma-active" id="tma-tab-dashboard">' .
					'<div class="tma-analytics-wrap" id="tma-analytics-wrap">' .
						'<div class="tma-empty">' . esc_html__( 'Loading analytics…', 'mcp-ai-wpoos-pro' ) . '</div>' .
					'</div>' .
				'</div>' .
			'</div>' .
		'</div>' .
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .
		'var analyticsUrl=' . wp_json_encode( $analytics_url ) . ';' .
		'var nonce=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var days=7;var chartInst=null;' .
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'window.loadAnalytics=function(){' .
			'var w=document.getElementById("tma-analytics-wrap");if(!w)return;' .
			'w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Loading…', 'mcp-ai-wpoos-pro' ) ) . '</div>\';' .
			'fetch(analyticsUrl+"?days="+days,{headers:{"X-WP-Nonce":nonce}})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){renderDash(d,w);var st=document.getElementById("tma-update-time");if(st)st.textContent="' . esc_js( __( 'Updated', 'mcp-ai-wpoos-pro' ) ) . ' "+(new Date()).toLocaleTimeString();})' .
			'.catch(function(){w.innerHTML=\'<div class="tma-empty">' . esc_js( __( 'Could not load analytics.', 'mcp-ai-wpoos-pro' ) ) . '</div>\';});' .
		'};' .
		'function renderDash(d,w){' .
			'var kpis=[{l:"' . esc_js( __( 'Views', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_views||0},{l:"' . esc_js( __( 'Posts', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_posts||0},{l:"' . esc_js( __( 'Comments', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_comments||0},{l:"' . esc_js( __( 'Users', 'mcp-ai-wpoos-pro' ) ) . '",v:d.total_users||0}];' .
			'var pb=[7,14,30].map(function(n){return \'<button class="tma-period-btn\'+(n===days?" active":"")+\'" onclick="setDays(\'+n+\')">\'+(n)+" ' . esc_js( __( 'd', 'mcp-ai-wpoos-pro' ) ) . '"+"</button>";}).join("");' .
			'var kh=kpis.map(function(k){return \'<div class="tma-kpi-card"><div class="tma-kpi-value">\'+escH(k.v)+\'</div><div class="tma-kpi-label">\'+escH(k.l)+\'</div></div>\';}).join("");' .
			'w.innerHTML=\'<div class="tma-period-bar">\'+pb+\'</div><div class="tma-kpi-grid">\'+kh+\'</div><div class="tma-chart-card"><div class="tma-chart-title">' . esc_js( __( 'Activity Over Time', 'mcp-ai-wpoos-pro' ) ) . '</div><canvas id="tma-chart" height="180"></canvas></div>\';' .
			'if(window.Chart){' .
				'var canvas=document.getElementById("tma-chart");' .
				'if(canvas){if(chartInst)chartInst.destroy();' .
					'var lbls=(d.daily||[]).map(function(r){return r.date||"";});' .
					'var vals=(d.daily||[]).map(function(r){return r.views||0;});' .
					'var bc=getComputedStyle(document.documentElement).getPropertyValue("--tma-btn").trim()||"#00796b";' .
					'chartInst=new Chart(canvas,{type:"line",data:{labels:lbls,datasets:[{label:"' . esc_js( __( 'Views', 'mcp-ai-wpoos-pro' ) ) . '",data:vals,borderColor:bc,backgroundColor:bc+"22",tension:.3,fill:true,pointRadius:3}]},options:{responsive:true,plugins:{legend:{display:false}},scales:{x:{ticks:{maxTicksLimit:6,color:"#999"}},y:{ticks:{color:"#999"},beginAtZero:true}}}});' .
				'}' .
			'}' .
		'}' .
		'window.setDays=function(n){days=n;tmaHaptic("light");loadAnalytics();};' .
		'loadAnalytics();' .
		'})();</script></body>';
		// phpcs:enable
	}
}

/**
 * Booking template – calendar appointment scheduling.
 */
class WP_MCP_AI_TMA_Template_Booking extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'booking';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'Calendar Booking', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Appointment scheduling interface with availability calendar, booking form, and confirmation flow.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'calendar_booking';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '📅';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#1565c0';
	}

	/** @inheritdoc */
	public function render_html( array $ctx ) {
		$site_name  = esc_html( $ctx['site_name'] );
		$tools_exec = $ctx['tools_url'] . '/execute';
		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-booking-template">' .
		'<style>' . wp_mcp_ai_tma_base_css() .
		':root{--tma-btn:#1565c0;--tma-accent:#1565c0;--tma-secondary-bg:#e8eaf6;}' .
		'.tma-calendar{padding:12px}' .
		'.tma-cal-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:10px}' .
		'.tma-cal-month{font-size:16px;font-weight:700}' .
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
		'.tma-slots-title{font-size:13px;font-weight:600;color:var(--tma-section-header);text-transform:uppercase;letter-spacing:.5px;margin-bottom:8px}' .
		'.tma-slot-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px}' .
		'.tma-slot{padding:10px;border:1px solid var(--tma-border);border-radius:8px;text-align:center;font-size:13px;font-weight:600;cursor:pointer;background:var(--tma-bg);color:var(--tma-text);-webkit-tap-highlight-color:transparent}' .
		'.tma-slot.selected{background:var(--tma-btn);color:#fff;border-color:var(--tma-btn)}' .
		'.tma-slot:active{opacity:.7}' .
		'.tma-booking-form{padding:16px}' .
		'.tma-form-label{font-size:12px;font-weight:600;color:var(--tma-hint);text-transform:uppercase;letter-spacing:.4px;margin-bottom:4px;display:block}' .
		'.tma-confirm-wrap{text-align:center;padding:40px 20px}' .
		'.tma-confirm-icon{font-size:64px;margin-bottom:16px}' .
		'</style>' .
		'<div class="tma-shell" id="tma-shell">' .
			'<header class="tma-header">' .
				'<div class="tma-avatar-wrap"><div class="tma-avatar-initials">📅</div></div>' .
				'<div class="tma-header-info">' .
					'<div class="tma-header-name">' . $site_name . '</div>' .
					'<div class="tma-header-status">' . esc_html__( 'Book Appointment', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'</div>' .
			'</header>' .
			'<div class="tma-content">' .
				'<div class="tma-tab-pane tma-active" id="tma-step-calendar">' .
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
				'<div class="tma-tab-pane" id="tma-step-form">' .
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
				'<div class="tma-tab-pane" id="tma-step-confirm">' .
					'<div class="tma-confirm-wrap">' .
						'<div class="tma-confirm-icon">&#9989;</div>' .
						'<div style="font-size:20px;font-weight:700;margin-bottom:8px">' . esc_html__( 'Booking Confirmed!', 'mcp-ai-wpoos-pro' ) . '</div>' .
						'<div id="tma-confirm-details" style="font-size:14px;color:var(--tma-hint);line-height:1.6"></div>' .
						'<button class="tma-btn tma-btn-primary" style="margin-top:24px" onclick="tmaReset()">' . esc_html__( 'Book Another', 'mcp-ai-wpoos-pro' ) . '</button>' .
					'</div>' .
				'</div>' .
			'</div>' .
		'</div>' .
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .
		'var toolsExec=' . wp_json_encode( $tools_exec ) . ';' .
		'var nonce=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var today=new Date();var vy=today.getFullYear();var vm=today.getMonth();' .
		'var selDate=null;var selSlot=null;' .
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .
		'function showStep(id){document.querySelectorAll(".tma-tab-pane").forEach(function(el){el.classList.remove("tma-active");});var el=document.getElementById(id);if(el)el.classList.add("tma-active");}' .
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
			'fetch(toolsExec,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({tool:"get_available_slots",arguments:{date:ds}})})' .
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
		'window.tmaGoToForm=function(){if(!selDate||!selSlot)return;tmaHaptic("medium");showStep("tma-step-form");};' .
		'window.tmaGoToCalendar=function(){showStep("tma-step-calendar");};' .
		'window.tmaConfirm=function(){' .
			'var name=(document.getElementById("tma-book-name")||{}).value||"";' .
			'var email=(document.getElementById("tma-book-email")||{}).value||"";' .
			'var notes=(document.getElementById("tma-book-notes")||{}).value||"";' .
			'var err=document.getElementById("tma-book-error");' .
			'if(!name.trim()||!email.trim()){if(err){err.style.display="block";err.textContent="' . esc_js( __( 'Name and email are required.', 'mcp-ai-wpoos-pro' ) ) . '";}return;}' .
			'if(err)err.style.display="none";tmaHaptic("medium");' .
			'fetch(toolsExec,{method:"POST",headers:{"Content-Type":"application/json","X-WP-Nonce":nonce},' .
				'body:JSON.stringify({tool:"create_booking",arguments:{date:selDate,time:selSlot,name:name,email:email,notes:notes}})})' .
			'.then(function(){}).catch(function(){}).finally(function(){' .
				'tmaHaptic("success");' .
				'var det=document.getElementById("tma-confirm-details");' .
				'if(det)det.innerHTML="<strong>"+escH(selDate)+" "+escH(selSlot)+"</strong><br>"+escH(name)+"<br>"+escH(email);' .
				'showStep("tma-step-confirm");' .
			'});' .
		'};' .
		'window.tmaReset=function(){' .
			'selDate=null;selSlot=null;' .
			'var sw=document.getElementById("tma-slots");if(sw)sw.style.display="none";' .
			'var nw=document.getElementById("tma-next-wrap");if(nw)nw.style.display="none";' .
			'showStep("tma-step-calendar");renderCal();' .
		'};' .
		'renderCal();' .
		'})();</script></body>';
		// phpcs:enable
	}
}

/**
 * Health & Wellness template – personal health dashboard.
 *
 * Features (industry-standard 2025):
 *  - Daily metric tracking: steps, calories, hydration, sleep, mood.
 *  - Chart.js doughnut (calorie macro breakdown) and line chart (7-day steps).
 *  - Streak counter and achievement badges (gamification layer).
 *  - Weekly goal progress bars.
 *  - AI Wellness Coach powered by the MCP tool execution endpoint.
 *  - Persistent offline-first data via localStorage with optional server sync.
 *
 * @since 1.1.4
 */
class WP_MCP_AI_TMA_Template_Health_Wellness extends WP_MCP_AI_Telegram_Mini_App_Template_Base {

	/** @inheritdoc */
	public function get_slug() {
		return 'health_wellness';
	}

	/** @inheritdoc */
	public function get_name() {
		return __( 'Health & Wellness', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_description() {
		return __( 'Personal wellness dashboard with daily metric tracking, Chart.js activity charts, streak gamification, weekly goal progress, and an AI coaching tab.', 'mcp-ai-wpoos-pro' );
	}

	/** @inheritdoc */
	public function get_toolkit() {
		return 'health_wellness';
	}

	/** @inheritdoc */
	public function get_icon() {
		return '🏃';
	}

	/** @inheritdoc */
	public function get_accent_color() {
		return '#2e7d32';
	}

	/** @inheritdoc */
	public function render_html( array $ctx ) {
		$site_name    = esc_html( $ctx['site_name'] );
		$tools_exec   = $ctx['tools_url'] . '/execute';
		$chart_js_url = $ctx['chart_js_url'];

		// phpcs:disable WordPress.Security.EscapeOutput.OutputNotEscaped
		return '<body class="wp-mcp-ai-telegram-mini-app tma-health-wellness-template">' .

		/* ── Styles ─────────────────────────────────────────────────────────── */
		'<style>' . wp_mcp_ai_tma_base_css() .

		/* Theme overrides */
		':root{--tma-btn:#2e7d32;--tma-accent:#2e7d32;--tma-secondary-bg:#e8f5e9;}' .

		/* Streak banner */
		'.tma-hw-streak{display:flex;align-items:center;justify-content:center;gap:8px;' .
			'background:linear-gradient(135deg,#2e7d32,#66bb6a);color:#fff;' .
			'padding:10px 16px;margin:8px 12px;border-radius:var(--tma-radius);font-weight:700}' .
		'.tma-hw-streak-fire{font-size:22px;line-height:1}' .
		'.tma-hw-streak-count{font-size:22px;line-height:1}' .
		'.tma-hw-streak-label{font-size:12px;opacity:.9}' .

		/* Dashboard scroll wrapper */
		'.tma-hw-wrap{padding:8px 12px;overflow-y:auto;height:calc(100% - 8px)}' .

		/* KPI grid */
		'.tma-hw-kpi-grid{display:grid;grid-template-columns:repeat(2,1fr);gap:10px;margin:10px 0}' .
		'.tma-hw-kpi{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;display:flex;flex-direction:column;' .
			'align-items:center;gap:4px;position:relative;overflow:hidden}' .
		'.tma-hw-kpi::before{content:"";position:absolute;top:0;left:0;right:0;height:3px;background:var(--hw-kpi-color,var(--tma-btn))}' .
		'.tma-hw-kpi-icon{font-size:22px;line-height:1}' .
		'.tma-hw-kpi-value{font-size:24px;font-weight:700;color:var(--tma-text);line-height:1}' .
		'.tma-hw-kpi-label{font-size:11px;color:var(--tma-hint);text-transform:uppercase;letter-spacing:.4px}' .
		'.tma-hw-kpi-pct{font-size:10px;color:var(--tma-hint);margin-top:2px}' .

		/* Chart cards */
		'.tma-hw-chart-card{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:14px;margin:10px 0}' .
		'.tma-hw-chart-title{font-size:13px;font-weight:600;color:var(--tma-text);margin-bottom:10px;' .
			'display:flex;justify-content:space-between;align-items:center}' .
		'.tma-hw-chart-sub{font-size:11px;color:var(--tma-hint);font-weight:400}' .
		'.tma-hw-donut-row{display:flex;align-items:center;gap:14px}' .
		'.tma-hw-donut-wrap{flex-shrink:0;width:110px;height:110px}' .
		'.tma-hw-donut-legend{display:flex;flex-direction:column;gap:5px;font-size:12px;flex:1}' .
		'.tma-hw-legend-dot{width:10px;height:10px;border-radius:50%;display:inline-block;margin-right:5px}' .

		/* Log form */
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

		/* Goals */
		'.tma-hw-goals-wrap{padding:12px}' .
		'.tma-hw-goal-item{background:var(--tma-section-bg);border:1px solid var(--tma-border);' .
			'border-radius:var(--tma-radius);padding:12px;margin-bottom:10px}' .
		'.tma-hw-goal-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px}' .
		'.tma-hw-goal-name{font-size:14px;font-weight:600;color:var(--tma-text)}' .
		'.tma-hw-goal-pct{font-size:13px;font-weight:700;color:var(--tma-btn)}' .
		'.tma-hw-progress-track{height:8px;background:var(--tma-secondary-bg);border-radius:4px;overflow:hidden}' .
		'.tma-hw-progress-fill{height:100%;border-radius:4px;background:var(--tma-btn);transition:width .4s}' .
		'.tma-hw-goal-detail{font-size:11px;color:var(--tma-hint);margin-top:4px}' .

		/* Badges */
		'.tma-hw-badges-title{font-size:13px;font-weight:600;color:var(--tma-section-header);' .
			'text-transform:uppercase;letter-spacing:.5px;padding:4px 0 8px}' .
		'.tma-hw-badge-row{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:14px}' .
		'.tma-hw-badge{display:flex;flex-direction:column;align-items:center;gap:4px;' .
			'padding:10px 12px;border-radius:var(--tma-radius);border:1px solid var(--tma-border);' .
			'background:var(--tma-section-bg);min-width:72px;text-align:center;font-size:10px;color:var(--tma-hint)}' .
		'.tma-hw-badge.earned{border-color:var(--tma-btn);background:var(--tma-secondary-bg);color:var(--tma-btn)}' .
		'.tma-hw-badge-icon{font-size:26px;line-height:1}' .

		/* AI Coach */
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
		'</style>' .

		/* ── Shell ─────────────────────────────────────────────────────────── */
		'<div class="tma-shell" id="tma-shell">' .

		/* Header */
		'<header class="tma-header">' .
			'<div class="tma-avatar-wrap"><div class="tma-avatar-initials" style="background:var(--tma-btn)">🏃</div></div>' .
			'<div class="tma-header-info">' .
				'<div class="tma-header-name">' . $site_name . '</div>' .
				'<div class="tma-header-status">' . esc_html__( 'Health & Wellness', 'mcp-ai-wpoos-pro' ) . '</div>' .
			'</div>' .
			'<div class="tma-header-actions">' .
				'<button class="tma-icon-btn" title="' . esc_attr__( 'Refresh', 'mcp-ai-wpoos-pro' ) . '" onclick="hwRefresh()">' .
					'<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="23 4 23 10 17 10"/><polyline points="1 20 1 14 7 14"/><path d="M3.51 9a9 9 0 0 1 14.85-3.36L23 10M1 14l4.64 4.36A9 9 0 0 0 20.49 15"/></svg>' .
				'</button>' .
			'</div>' .
		'</header>' .

		/* Tab panes */
		'<div class="tma-content">' .

		/* ── Dashboard ── */
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

		/* ── Log ── */
		'<div class="tma-tab-pane" id="tma-hw-tab-log">' .
			'<div class="tma-hw-log-wrap">' .
				'<div class="tma-section-title" style="padding:0 0 8px">' . esc_html__( 'Log Today\'s Activity', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-hw-log-saved" id="tma-hw-log-saved">&#10003; ' . esc_html__( 'Logged successfully!', 'mcp-ai-wpoos-pro' ) . '</div>' .

				/* Steps */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128099; ' . esc_html__( 'Steps', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'steps\',-500)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-steps-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'steps\',500)">+</button>' .
						'<input type="number" id="hw-steps-input" class="tma-input" style="flex:1;font-size:14px;padding:8px 10px" min="0" max="99999" placeholder="' . esc_attr__( 'or type', 'mcp-ai-wpoos-pro' ) . '" oninput="hwFromInput(\'steps\',this.value)" />' .
					'</div>' .
				'</div>' .

				/* Water */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128167; ' . esc_html__( 'Water (glasses)', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'water\',-1)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-water-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'water\',1)">+</button>' .
					'</div>' .
				'</div>' .

				/* Sleep */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128164; ' . esc_html__( 'Sleep (hours)', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'sleep\',-0.5)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-sleep-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'sleep\',0.5)">+</button>' .
					'</div>' .
				'</div>' .

				/* Calories */
				'<div class="tma-hw-log-section">' .
					'<label class="tma-hw-log-label">&#128293; ' . esc_html__( 'Calories (kcal)', 'mcp-ai-wpoos-pro' ) . '</label>' .
					'<div class="tma-hw-counter">' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'calories\',-50)">&#8722;</button>' .
						'<div class="tma-hw-counter-val" id="hw-calories-val">0</div>' .
						'<button class="tma-hw-counter-btn" onclick="hwCount(\'calories\',50)">+</button>' .
						'<input type="number" id="hw-calories-input" class="tma-input" style="flex:1;font-size:14px;padding:8px 10px" min="0" placeholder="' . esc_attr__( 'or type', 'mcp-ai-wpoos-pro' ) . '" oninput="hwFromInput(\'calories\',this.value)" />' .
					'</div>' .
				'</div>' .

				/* Mood */
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

		/* ── Goals ── */
		'<div class="tma-tab-pane" id="tma-hw-tab-goals">' .
			'<div class="tma-hw-goals-wrap">' .
				'<div class="tma-section-title" style="padding:0 0 8px">' . esc_html__( 'Weekly Goals', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div id="tma-hw-goals-list"><div class="tma-empty">' . esc_html__( 'Loading…', 'mcp-ai-wpoos-pro' ) . '</div></div>' .
				'<div class="tma-hw-badges-title">' . esc_html__( 'Achievements', 'mcp-ai-wpoos-pro' ) . '</div>' .
				'<div class="tma-hw-badge-row" id="tma-hw-badges"></div>' .
			'</div>' .
		'</div>' .

		/* ── Coach ── */
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

		/* Bottom navigation */
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

		/* ── JavaScript ─────────────────────────────────────────────────────── */
		'<script>(function(){"use strict";' .
		wp_mcp_ai_tma_base_js() .

		/* Config injected from PHP */
		'var TOOLS_EXEC=' . wp_json_encode( $tools_exec ) . ';' .
		'var NONCE=' . wp_json_encode( $ctx['nonce'] ) . ';' .
		'var CHART_JS_URL=' . wp_json_encode( $chart_js_url ) . ';' .

		/* ── Storage helpers ── */
		'var SK_PREFIX="hw_";' .
		'function hwTodayKey(){return new Date().toISOString().slice(0,10);}' .
		'function hwLoadLog(){try{var v=localStorage.getItem(SK_PREFIX+hwTodayKey());return v?JSON.parse(v):{steps:0,water:0,sleep:0,calories:0,mood:0};}catch(e){return{steps:0,water:0,sleep:0,calories:0,mood:0};}}' .
		'function hwStoreLog(l){try{localStorage.setItem(SK_PREFIX+hwTodayKey(),JSON.stringify(l));}catch(e){}}' .
		'function hwLoadHistory(){' .
			'var hist=[];var base=new Date();' .
			'for(var i=6;i>=0;i--){' .
				'var dd=new Date(base);dd.setDate(dd.getDate()-i);' .
				'var dk=dd.toISOString().slice(0,10);' .
				'var raw=null;try{raw=localStorage.getItem(SK_PREFIX+dk);}catch(e){}' .
				'var entry=raw?JSON.parse(raw):{steps:0,water:0,sleep:0,calories:0,mood:0};' .
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

		/* State */
		'var LOG=hwLoadLog();' .
		'var lineChartInst=null;var donutChartInst=null;' .

		/* HTML-escape helper */
		'function escH(s){var d=document.createElement("div");d.appendChild(document.createTextNode(String(s)));return d.innerHTML;}' .

		/* ── Tab switcher ── */
		'window.hwTab=function(tab,btn){' .
			'document.querySelectorAll(".tma-tab-pane").forEach(function(p){p.classList.remove("tma-active");});' .
			'document.querySelectorAll(".tma-nav-btn").forEach(function(b){b.classList.remove("tma-active");});' .
			'var pane=document.getElementById("tma-hw-tab-"+tab);if(pane)pane.classList.add("tma-active");' .
			'if(btn)btn.classList.add("tma-active");' .
			'tmaHaptic("light");' .
			'if(tab==="dashboard")hwRefresh();' .
			'if(tab==="goals")hwRenderGoals();' .
		'};' .

		/* ── Dashboard ── */
		'window.hwRefresh=function(){hwRenderStreak();hwRenderKPIs();hwLoadCharts();};' .

		'function hwRenderStreak(){' .
			'var el=document.getElementById("tma-hw-streak-count");if(el)el.textContent=hwCalcStreak();' .
		'}' .

		'function hwRenderKPIs(){' .
			'var log=hwLoadLog();' .
			'var kpis=[' .
				'{icon:"&#128099;",label:"' . esc_js( __( 'Steps', 'mcp-ai-wpoos-pro' ) ) . '",val:log.steps,goal:10000,unit:"",color:"#2e7d32"},' .
				'{icon:"&#128293;",label:"' . esc_js( __( 'Calories', 'mcp-ai-wpoos-pro' ) ) . '",val:log.calories,goal:2000,unit:"kcal",color:"#e65100"},' .
				'{icon:"&#128167;",label:"' . esc_js( __( 'Water', 'mcp-ai-wpoos-pro' ) ) . '",val:log.water,goal:8,unit:"gl",color:"#0277bd"},' .
				'{icon:"&#128164;",label:"' . esc_js( __( 'Sleep', 'mcp-ai-wpoos-pro' ) ) . '",val:log.sleep,goal:8,unit:"h",color:"#6a1b9a"}' .
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

		/* Chart.js loader */
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
			/* Calorie macro split (demo: 40 % carbs, 30 % protein, 30 % fat) */
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
			/* Doughnut */
			'var dc=document.getElementById("tma-hw-donut");' .
			'if(dc){if(donutChartInst)donutChartInst.destroy();' .
				'donutChartInst=new Chart(dc,{type:"doughnut",data:{labels:["' . esc_js( __( 'Carbs', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Protein', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Fat', 'mcp-ai-wpoos-pro' ) ) . '"],datasets:[{data:[carbs,protein,fat],backgroundColor:["#4caf50","#ff9800","#ef5350"],borderWidth:0}]},options:{cutout:"72%",plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.label+": "+c.raw+"kcal";}}}},animation:{animateScale:true}}});' .
			'}' .
			/* Line */
			'var lc=document.getElementById("tma-hw-line");' .
			'if(lc){if(lineChartInst)lineChartInst.destroy();' .
				'lineChartInst=new Chart(lc,{type:"line",data:{labels:labels,datasets:[{label:"' . esc_js( __( 'Steps', 'mcp-ai-wpoos-pro' ) ) . '",data:stepData,borderColor:accent,backgroundColor:accent+"22",tension:.4,fill:true,pointRadius:4,pointBackgroundColor:accent}]},options:{responsive:true,plugins:{legend:{display:false},tooltip:{callbacks:{label:function(c){return c.raw+" ' . esc_js( __( 'steps', 'mcp-ai-wpoos-pro' ) ) . '";}}}},' .
					'scales:{x:{ticks:{maxTicksLimit:7,color:"#999",font:{size:10}}},y:{ticks:{color:"#999",font:{size:10}},beginAtZero:true,suggestedMax:12000,grid:{color:"rgba(0,0,0,.06)"}}}' .
				'}});' .
			'}' .
		'}' .

		/* ── Log tab ── */
		'function hwSyncUI(){' .
			'var sv=document.getElementById("hw-steps-val");if(sv)sv.textContent=LOG.steps;' .
			'var wv=document.getElementById("hw-water-val");if(wv)wv.textContent=LOG.water;' .
			'var slv=document.getElementById("hw-sleep-val");if(slv)slv.textContent=LOG.sleep;' .
			'var cv=document.getElementById("hw-calories-val");if(cv)cv.textContent=LOG.calories;' .
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
			/* Optional server-side persistence — silent on error */
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:{"Content-Type":"application/json","X-WP-Nonce":NONCE},' .
				'body:JSON.stringify({tool:"log_health_metrics",arguments:{date:hwTodayKey(),' .
					'steps:LOG.steps,water:LOG.water,sleep:LOG.sleep,calories:LOG.calories,mood:LOG.mood}})' .
			'}).catch(function(){});' .
			'var msg=document.getElementById("tma-hw-log-saved");' .
			'if(msg){msg.style.display="block";setTimeout(function(){msg.style.display="none";},2500);}' .
		'};' .

		/* ── Goals tab ── */
		'window.hwRenderGoals=function(){' .
			'var hist=hwLoadHistory();' .
			'var tot={steps:0,water:0,sleep:0,calories:0};' .
			'hist.forEach(function(h){tot.steps+=h.steps||0;tot.water+=h.water||0;tot.sleep+=h.sleep||0;tot.calories+=h.calories||0;});' .
			'var goals=[' .
				'{icon:"&#128099;",label:"' . esc_js( __( 'Steps this week', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.steps,goal:70000},' .
				'{icon:"&#128167;",label:"' . esc_js( __( 'Water (glasses)', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.water,goal:56},' .
				'{icon:"&#128164;",label:"' . esc_js( __( 'Sleep total (hrs)', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.sleep,goal:56},' .
				'{icon:"&#128293;",label:"' . esc_js( __( 'Calories (kcal)', 'mcp-ai-wpoos-pro' ) ) . '",val:tot.calories,goal:14000}' .
			'];' .
			'var streak=hwCalcStreak();' .
			'var gl=document.getElementById("tma-hw-goals-list");' .
			'if(gl)gl.innerHTML=goals.map(function(g){' .
				'var pct=g.goal?Math.min(100,Math.round((g.val/g.goal)*100)):0;' .
				'return \'<div class="tma-hw-goal-item">\'+' .
					'\'<div class="tma-hw-goal-header">\'+' .
						'\'<div class="tma-hw-goal-name">\'+g.icon+" "+escH(g.label)+\'</div>\'+' .
						'\'<div class="tma-hw-goal-pct">\'+pct+"% </div>"+' .
					'"</div>"+' .
					'\'<div class="tma-hw-progress-track"><div class="tma-hw-progress-fill" style="width:\'+pct+\'%"></div></div>\'+' .
					'\'<div class="tma-hw-goal-detail">\'+escH(g.val)+" / "+escH(g.goal)+\'</div></div>\';' .
			'}).join("");' .
			'var log=hwLoadLog();' .
			'var badges=[' .
				'{icon:"&#128293;",label:"' . esc_js( __( '3-Day Streak', 'mcp-ai-wpoos-pro' ) ) . '",earned:streak>=3},' .
				'{icon:"&#127885;",label:"' . esc_js( __( '7-Day Streak', 'mcp-ai-wpoos-pro' ) ) . '",earned:streak>=7},' .
				'{icon:"&#128640;",label:"' . esc_js( __( '10k Steps', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.steps>=10000},' .
				'{icon:"&#128167;",label:"' . esc_js( __( 'Hydration Hero', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.water>=8},' .
				'{icon:"&#128164;",label:"' . esc_js( __( 'Sleep Champion', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.sleep>=8},' .
				'{icon:"&#127775;",label:"' . esc_js( __( 'Perfect Day', 'mcp-ai-wpoos-pro' ) ) . '",earned:log.steps>=10000&&log.water>=8&&log.sleep>=8}' .
			'];' .
			'var br=document.getElementById("tma-hw-badges");' .
			'if(br)br.innerHTML=badges.map(function(b){' .
				'return \'<div class="tma-hw-badge\'+( b.earned?" earned":"")+\'">\'+' .
					'\'<div class="tma-hw-badge-icon">\'+b.icon+\'</div><div>\'+escH(b.label)+\'</div></div>\';' .
			'}).join("");' .
		'};' .

		/* ── AI Coach ── */
		'window.hwCoachSend=function(){' .
			'var inp=document.getElementById("tma-hw-coach-input");if(!inp)return;' .
			'var msg=inp.value.trim();if(!msg)return;inp.value="";tmaHaptic("medium");' .
			'var msgs=document.getElementById("tma-hw-coach-msgs");' .
			'if(msgs){var um=document.createElement("div");um.className="tma-hw-coach-msg user";um.textContent=msg;msgs.appendChild(um);msgs.scrollTop=msgs.scrollHeight;}' .
			'var loadEl=null;' .
			'if(msgs){loadEl=document.createElement("div");loadEl.className="tma-hw-coach-msg bot";loadEl.textContent="' . esc_js( __( '…', 'mcp-ai-wpoos-pro' ) ) . '";msgs.appendChild(loadEl);msgs.scrollTop=msgs.scrollHeight;}' .
			'var log=hwLoadLog();' .
			'fetch(TOOLS_EXEC,{method:"POST",' .
				'headers:{"Content-Type":"application/json","X-WP-Nonce":NONCE},' .
				'body:JSON.stringify({tool:"ai_health_coach",arguments:{message:msg,steps:log.steps,water:log.water,sleep:log.sleep,calories:log.calories,mood:log.mood}})' .
			'})' .
			'.then(function(r){return r.json();})' .
			'.then(function(d){' .
				'var reply=(d&&d.data&&d.data.reply)?d.data.reply:"' . esc_js( __( 'Keep up the great work! Stay consistent with your goals, hydrate well, and aim for 7-9 hours of sleep. 💪', 'mcp-ai-wpoos-pro' ) ) . '";' .
				'if(loadEl)loadEl.textContent=reply;' .
			'}).catch(function(){' .
				'var tips=["' . esc_js( __( 'Stay hydrated! Aim for 8 glasses of water today. 💧', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'A 20-minute walk can boost your mood and energy. 🚶', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Quality sleep is foundational to health. Aim for 7-9 hours tonight. 😴', 'mcp-ai-wpoos-pro' ) ) . '","' . esc_js( __( 'Consistency is the key to long-term wellness. 🌟', 'mcp-ai-wpoos-pro' ) ) . '"];' .
				'if(loadEl)loadEl.textContent=tips[Math.floor(Math.random()*tips.length)];' .
			'}).finally(function(){if(msgs)msgs.scrollTop=msgs.scrollHeight;});' .
		'};' .

		/* ── Init ── */
		'LOG=hwLoadLog();hwSyncUI();hwRefresh();' .
		/* Restore saved mood selection */
		'if(LOG.mood){var mb=document.querySelector(".tma-hw-mood-btn[data-mood=\'"+LOG.mood+"\']");if(mb)mb.classList.add("selected");}' .
		'})();</script></body>';
		// phpcs:enable
	}
}
