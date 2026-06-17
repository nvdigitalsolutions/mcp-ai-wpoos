<?php
/**
 * Global Chat Bubble Frontend
 *
 * Automatically injects a floating chat bubble into the frontend
 * when enabled via the Chat Client > Chat Bubble admin settings.
 * No widget or block placement is required.
 *
 * The rendering pipeline mirrors the Elementor chat bubble widget
 * (class-wp-mcp-ai-elementor-chat-bubble-widget.php) exactly:
 *   1. Enqueue bubble assets
 *   2. Build shortcode via key="value" attributes
 *   3. Capture config before shortcode, run do_shortcode(), diff configs
 *   4. Apply deferred attribute rename for lazy chat init
 *   5. Output inline <script> with chat instance configs
 *   6. Re-trigger bubble + chat init
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles automatic injection of the global chat bubble on the frontend.
 */
class WP_MCP_AI_Chat_Bubble_Frontend {

	/**
	 * Default panel width in pixels.
	 *
	 * @var int
	 */
	const DEFAULT_PANEL_WIDTH = 400;

	/**
	 * Default panel height in pixels.
	 *
	 * @var int
	 */
	const DEFAULT_PANEL_HEIGHT = 550;

	/**
	 * Default bubble color.
	 *
	 * @var string
	 */
	const DEFAULT_BUBBLE_COLOR = '#4f46e5';

	/**
	 * Whether the bubble has already been rendered for this request.
	 *
	 * @var bool
	 */
	private static $rendered = false;

	/**
	 * Initialize hooks.
	 */
	public function init() {
		// Only run on the frontend (not admin, REST, AJAX, or CLI).
		if ( is_admin() || wp_doing_ajax() || wp_doing_cron() || ( defined( 'REST_REQUEST' ) && REST_REQUEST ) ) {
			return;
		}

		add_action( 'wp_footer', array( $this, 'maybe_render_bubble' ), 4 );
	}

	/**
	 * Conditionally render the global chat bubble in wp_footer.
	 */
	public function maybe_render_bubble() {
		// Prevent double-rendering.
		if ( self::$rendered ) {
			return;
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		// Check if the global chat bubble is enabled.
		if ( empty( $settings['chat_bubble_enabled'] ) ) {
			return;
		}

		// Check exclude pages list.
		$exclude_pages = isset( $settings['chat_bubble_exclude_pages'] ) ? trim( $settings['chat_bubble_exclude_pages'] ) : '';
		if ( '' !== $exclude_pages ) {
			$exclude_ids = array_map( 'absint', array_filter( array_map( 'trim', explode( ',', $exclude_pages ) ) ) );
			$current_id  = get_queried_object_id();
			if ( $current_id && in_array( $current_id, $exclude_ids, true ) ) {
				return;
			}
		}

		self::$rendered = true;

		$this->render_bubble( $settings );
	}

	/**
	 * Build the shortcode string.
	 *
	 * Uses the same attribute-building approach as the Elementor chat
	 * bubble widget build_shortcode() method for consistency.
	 *
	 * @param array $settings Plugin settings array.
	 * @return string Complete shortcode string.
	 */
	private function build_shortcode( $settings ) {
		$attributes = array();

		$assistant_id = isset( $settings['chat_bubble_assistant_id'] ) ? absint( $settings['chat_bubble_assistant_id'] ) : 0;
		if ( $assistant_id ) {
			$attributes['assistant'] = (string) $assistant_id;
		}

		$allow_guests               = ! empty( $settings['chat_bubble_allow_guests'] );
		$attributes['allow_guests'] = $allow_guests ? 'true' : 'false';

		$save_transcript = isset( $settings['chat_bubble_save_transcript'] ) ? (bool) $settings['chat_bubble_save_transcript'] : true;
		if ( ! $save_transcript ) {
			$attributes['save_transcript'] = 'false';
		}

		$enable_streaming               = isset( $settings['chat_bubble_enable_streaming'] ) ? (bool) $settings['chat_bubble_enable_streaming'] : true;
		$attributes['enable_streaming'] = $enable_streaming ? 'true' : 'false';

		$allow_sensitive_tools = ! empty( $settings['chat_bubble_allow_sensitive_tools'] );
		if ( $allow_sensitive_tools ) {
			$attributes['allow_sensitive_tools'] = 'true';
		}

		$template = isset( $settings['chat_bubble_template'] ) ? sanitize_key( $settings['chat_bubble_template'] ) : 'compact';
		if ( 'classic' !== $template ) {
			$attributes['template'] = $template;
		}

		$shortcode = '[' . WP_MCP_AI_Shortcode::SHORTCODE;

		foreach ( $attributes as $key => $value ) {
			$shortcode .= ' ' . $key . '="' . esc_attr( $value ) . '"';
		}

		$shortcode .= ']';

		return $shortcode;
	}

	/**
	 * Build CSS custom properties string.
	 *
	 * Mirrors the Elementor widget build_css_variables() approach.
	 *
	 * @param array $settings Plugin settings array.
	 * @return string Semicolon-separated CSS custom properties.
	 */
	private function build_css_variables( $settings ) {
		$vars = array();

		$bubble_color = isset( $settings['chat_bubble_color'] ) ? sanitize_hex_color( $settings['chat_bubble_color'] ) : '';
		if ( ! empty( $bubble_color ) ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-color:' . $bubble_color;
		}

		$bubble_text_color = isset( $settings['chat_bubble_text_color'] ) ? sanitize_hex_color( $settings['chat_bubble_text_color'] ) : '';
		if ( ! empty( $bubble_text_color ) ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-text-color:' . $bubble_text_color;
		}

		$header_background = isset( $settings['chat_bubble_header_background'] ) ? sanitize_hex_color( $settings['chat_bubble_header_background'] ) : '';
		if ( ! empty( $header_background ) ) {
			$header_bg = $header_background;
		} elseif ( ! empty( $bubble_color ) ) {
			$header_bg = $bubble_color;
		} else {
			$header_bg = self::DEFAULT_BUBBLE_COLOR;
		}
		$vars[] = '--wp-mcp-ai-chat-bubble-header-background:' . $header_bg;

		$header_text_color = isset( $settings['chat_bubble_header_text_color'] ) ? sanitize_hex_color( $settings['chat_bubble_header_text_color'] ) : '';
		if ( ! empty( $header_text_color ) ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-header-text-color:' . $header_text_color;
		}

		$panel_width = isset( $settings['chat_bubble_panel_width'] ) ? absint( $settings['chat_bubble_panel_width'] ) : self::DEFAULT_PANEL_WIDTH;
		if ( self::DEFAULT_PANEL_WIDTH !== $panel_width && $panel_width > 0 ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-panel-width:' . $panel_width . 'px';
		}

		$panel_height = isset( $settings['chat_bubble_panel_height'] ) ? absint( $settings['chat_bubble_panel_height'] ) : self::DEFAULT_PANEL_HEIGHT;
		if ( self::DEFAULT_PANEL_HEIGHT !== $panel_height && $panel_height > 0 ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-panel-height:' . $panel_height . 'px';
		}

		return implode( ';', $vars );
	}

	/**
	 * Build data-attribute string for the bubble container.
	 *
	 * Mirrors the Elementor widget build_data_attributes() approach.
	 *
	 * @param string $bubble_id Unique bubble identifier.
	 * @param array  $settings  Plugin settings array.
	 * @return string Pre-escaped data-attribute string.
	 */
	private function build_data_attributes( $bubble_id, $settings ) {
		$attrs = array();

		$attrs[] = 'data-bubble-id="' . esc_attr( $bubble_id ) . '"';

		$auto_open_delay = isset( $settings['chat_bubble_auto_open_delay'] ) ? absint( $settings['chat_bubble_auto_open_delay'] ) : 0;
		$attrs[]         = 'data-auto-open-delay="' . esc_attr( (string) $auto_open_delay ) . '"';

		$remember_state = ! empty( $settings['chat_bubble_remember_state'] );
		$attrs[]        = 'data-remember-state="' . esc_attr( $remember_state ? 'true' : 'false' ) . '"';

		$notification_badge = ! empty( $settings['chat_bubble_notification_badge'] );
		$attrs[]            = 'data-notification-badge="' . esc_attr( $notification_badge ? 'true' : 'false' ) . '"';

		return implode( ' ', $attrs );
	}

	/**
	 * Render the chat bubble HTML and enqueue assets.
	 *
	 * Follows the same rendering pipeline as the Elementor chat bubble
	 * widget render() + render_bubble_html() methods.
	 *
	 * @param array $settings Plugin settings array.
	 */
	private function render_bubble( $settings ) {
		// Enqueue bubble assets (same as Elementor widget and block).
		wp_enqueue_script( 'wp-mcp-ai-chat-bubble' );
		wp_enqueue_style( 'wp-mcp-ai-chat-bubble-style' );

		$bubble_id = sanitize_key( 'wp-mcp-ai-bubble-global-' . wp_unique_id() );

		$bubble_position  = isset( $settings['chat_bubble_position'] ) ? sanitize_key( $settings['chat_bubble_position'] ) : 'bottom-right';
		$bubble_size      = isset( $settings['chat_bubble_size'] ) ? sanitize_key( $settings['chat_bubble_size'] ) : 'medium';
		$bubble_animation = isset( $settings['chat_bubble_animation'] ) ? sanitize_key( $settings['chat_bubble_animation'] ) : 'bounce';

		$css_vars = $this->build_css_variables( $settings );

		$classes  = 'wp-mcp-ai-chat-bubble';
		$classes .= ' wp-mcp-ai-chat-bubble--' . $bubble_position;
		$classes .= ' wp-mcp-ai-chat-bubble--' . $bubble_size;
		if ( 'none' !== $bubble_animation ) {
			$classes .= ' wp-mcp-ai-chat-bubble--' . $bubble_animation;
		}

		$data_attrs = $this->build_data_attributes( $bubble_id, $settings );
		$shortcode  = $this->build_shortcode( $settings );

		$panel_title = isset( $settings['chat_bubble_panel_title'] ) ? sanitize_text_field( $settings['chat_bubble_panel_title'] ) : __( 'Chat with AI', 'mcp-ai-wpoos' );
		$tooltip     = isset( $settings['chat_bubble_tooltip'] ) ? trim( sanitize_text_field( $settings['chat_bubble_tooltip'] ) ) : '';

		/*
		 * Capture configs registered before the shortcode runs so we can
		 * identify the new entry created by this specific do_shortcode() call.
		 */
		$configs_before = isset( $GLOBALS['wp_mcp_ai_chat_configs'] )
			? array_keys( $GLOBALS['wp_mcp_ai_chat_configs'] )
			: array();

		/*
		 * Process the shortcode now so that scripts and inline config
		 * are enqueued at the normal time.
		 */
		$shortcode_html = do_shortcode( $shortcode );

		/*
		 * Identify the new chat instance config added by the shortcode.
		 *
		 * The shortcode stores its configuration in $GLOBALS['wp_mcp_ai_chat_configs']
		 * AND calls wp_add_inline_script() to inject it before the chat JS.
		 * However, script deferral and caching plugins can prevent
		 * wp_add_inline_script() from actually printing the config.
		 * By extracting the config here we can output it as a reliable
		 * inline <script> tag directly in the bubble markup - the same
		 * approach used by the Elementor widget and Gutenberg block.
		 */
		$inline_configs = array();
		if ( isset( $GLOBALS['wp_mcp_ai_chat_configs'] ) ) {
			foreach ( $GLOBALS['wp_mcp_ai_chat_configs'] as $id => $cfg ) {
				if ( ! in_array( $id, $configs_before, true ) ) {
					$inline_configs[ $id ] = $cfg;
				}
			}
		}

		/*
		 * Render the bubble HTML - mirrors render_bubble_html() from the
		 * Elementor widget exactly.
		 */
		$this->render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html, $bubble_id, $inline_configs );
	}

	/**
	 * Output the bubble HTML structure.
	 *
	 * This method mirrors the Elementor widget's render_bubble_html() method
	 * to ensure identical markup and behavior across all bubble surfaces.
	 *
	 * @param string $classes        CSS class string.
	 * @param string $data_attrs     Pre-escaped data-attribute string.
	 * @param string $css_vars       Inline CSS custom properties string.
	 * @param string $panel_title    Panel dialog title.
	 * @param string $tooltip        Optional bubble tooltip text.
	 * @param array  $settings       Plugin settings array.
	 * @param string $shortcode_html Pre-rendered shortcode output.
	 * @param string $bubble_id      Unique bubble identifier.
	 * @param array  $inline_configs Chat instance configs to output inline.
	 */
	private function render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html, $bubble_id, $inline_configs = array() ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_attrs built with esc_attr() in build_data_attributes().
		echo '<div class="' . esc_attr( $classes ) . '" ' . $data_attrs . ' style="' . esc_attr( $css_vars ) . '">';

		echo '<button class="wp-mcp-ai-chat-bubble__trigger" aria-expanded="false" aria-label="' . esc_attr__( 'Open chat', 'mcp-ai-wpoos' ) . '">';
		echo '<span class="wp-mcp-ai-chat-bubble__trigger-icon">';
		// Chat SVG icon (same as Elementor widget).
		echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		echo '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" fill="currentColor"/>';
		echo '</svg>';
		echo '</span>';
		echo '<span class="wp-mcp-ai-chat-bubble__trigger-close-icon">';
		// Close SVG icon (same as Elementor widget).
		echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		echo '<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
		echo '</svg>';
		echo '</span>';

		$notification_badge = ! empty( $settings['chat_bubble_notification_badge'] );
		echo '<span class="wp-mcp-ai-chat-bubble__badge" aria-hidden="true"' . ( $notification_badge ? '' : ' hidden' ) . '></span>';

		echo '</button>';

		if ( '' !== $tooltip ) {
			echo '<span class="wp-mcp-ai-chat-bubble__tooltip">' . esc_html( $tooltip ) . '</span>';
		}

		echo '<div class="wp-mcp-ai-chat-bubble__panel" role="dialog" aria-label="' . esc_attr( $panel_title ) . '" aria-hidden="true" inert>';

		echo '<div class="wp-mcp-ai-chat-bubble__panel-header">';
		echo '<span class="wp-mcp-ai-chat-bubble__panel-title">' . esc_html( $panel_title ) . '</span>';
		echo '<button class="wp-mcp-ai-chat-bubble__panel-close" aria-label="' . esc_attr__( 'Close chat', 'mcp-ai-wpoos' ) . '">';
		echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		echo '<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
		echo '</svg>';
		echo '</button>';
		echo '</div>';

		echo '<div class="wp-mcp-ai-chat-bubble__panel-body">';

		/*
		 * Defer chat initialisation: replace the discovery attribute with a
		 * deferred variant so chat.js does not initialise the container on
		 * its DOMContentLoaded pass (when the panel is still hidden).
		 * The companion chat-bubble.js _lazyInitChat() renames the attribute
		 * back to data-wp-mcp-ai-chat right before calling init(), ensuring
		 * the chat is only bootstrapped once the bubble panel is opened.
		 *
		 * The regex uses a negative look-ahead to avoid matching
		 * data-wp-mcp-ai-chat-initialized or similar longer attributes.
		 */
		$safe_html = $shortcode_html;
		$deferred  = preg_replace( '/data-wp-mcp-ai-chat(?![-\w])/', 'data-wp-mcp-ai-chat-deferred', $safe_html );
		$safe_html = null !== $deferred ? $deferred : $safe_html;
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is generated by WP_MCP_AI_Shortcode::render_shortcode() which individually escapes all values with esc_attr()/esc_html(). wp_kses_post() strips data-* attributes, SVGs, form elements, and <script type="application/json"> config blocks that the chat UI requires. The regex only renames a data attribute for lazy init.
		echo $safe_html;

		echo '</div>'; // .panel-body

		echo '</div>'; // .panel

		/*
		 * Output the chat instance config as an inline <script> tag.
		 *
		 * This guarantees the config is available in window.wpMcpAiChatInstances
		 * regardless of whether wp_add_inline_script() (used inside the
		 * shortcode) actually prints - script deferral and caching plugins
		 * can all prevent the WordPress inline-script queue from executing.
		 *
		 * This is the same approach used by the Elementor widget and
		 * Gutenberg block.
		 */
		if ( ! empty( $inline_configs ) ) {
			// JSON_HEX_TAG prevents </script> breakout; JSON_HEX_AMP prevents HTML entity injection.
			$json_flags = JSON_HEX_TAG | JSON_HEX_AMP;
			$js         = 'window.wpMcpAiChatInstances=window.wpMcpAiChatInstances||{};';
			foreach ( $inline_configs as $id => $cfg ) {
				$js .= 'window.wpMcpAiChatInstances[' . wp_json_encode( $id, $json_flags ) . ']=' . wp_json_encode( $cfg, $json_flags ) . ';';
			}
			// Plugin requires WP 6.0+; wp_print_inline_script_tag() (added in WP 5.7) is always available.
			wp_print_inline_script_tag( $js );
		}

		echo '</div>'; // .wp-mcp-ai-chat-bubble

		/*
		 * Re-trigger init for this bubble instance only, so existing chat-client
		 * widgets elsewhere on the page are not re-initialized.
		 *
		 * chat-bubble.js exposes window.wpMcpAiChatBubble.init(scope).
		 * chat.js exposes window.wpMcpAiChatInit.init(scope).
		 * wp_get_inline_script_tag() (WP 5.7+) adds the CSP nonce automatically.
		 */
		$reinit_js = '(function(){'
			. 'var bubbleId=' . wp_json_encode( $bubble_id ) . ';'
			. 'if(!bubbleId){return;}'
			. 'var roots=document.querySelectorAll(".wp-mcp-ai-chat-bubble[data-bubble-id]");'
			. 'var root=null;'
			. 'for(var i=0;i<roots.length;i++){'
			. 'if(roots[i].getAttribute("data-bubble-id")===bubbleId){root=roots[i];break;}'
			. '}'
			. 'if(!root){return;}'
			. 'if(window.wpMcpAiChatBubble&&typeof window.wpMcpAiChatBubble.init==="function"){window.wpMcpAiChatBubble.init(root);}'
			. 'if(window.wpMcpAiChatInit&&typeof window.wpMcpAiChatInit.init==="function"){'
			. 'var panel=root.querySelector(".wp-mcp-ai-chat-bubble__panel");'
			. 'if(panel){window.wpMcpAiChatInit.init(panel);}'
			. '}'
			. '})();';

		// Plugin requires WP 6.0+; wp_get_inline_script_tag() (added in WP 5.7) is always available.
		echo wp_get_inline_script_tag( $reinit_js ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- wp_get_inline_script_tag() escapes and adds CSP nonce.
	}
}
