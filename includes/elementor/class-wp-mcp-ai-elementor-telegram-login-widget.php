<?php
/**
 * Elementor widget for the Telegram Login button (wraps the [mcp_ai_telegram_login] shortcode).
 *
 * This widget extends / enhances the [mcp_ai_telegram_login] shortcode as part of the
 * Telegram channel connection type, providing a fully visual, drag-and-drop interface
 * for configuring and embedding the Telegram Web Login button via Elementor.
 *
 * Shortcode attributes exposed as Elementor controls:
 *   bot_username   – Telegram bot username without '@'.
 *   redirect_url   – URL Telegram redirects to after login.
 *   button_size    – 'large' (default), 'medium', or 'small'.
 *   corner_radius  – Corner radius in pixels (0–20).
 *   request_access – Request write access ('write' or empty).
 *   show_avatar    – Whether to display the user's avatar ('1'/'0').
 *   lang           – ISO 639-1 language code.
 *
 * @package WP_MCP_AI
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget that wraps the [mcp_ai_telegram_login] shortcode.
 */
class WP_MCP_AI_Elementor_Telegram_Login_Widget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_telegram_login';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'Telegram Login', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon for the Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-telegram';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget inside the Elementor editor.
	 */
	public function get_keywords() {
		return array( 'telegram', 'login', 'auth', 'bot', 'mcp', 'channel' );
	}

	/**
	 * Register Elementor controls for all shortcode attributes.
	 */
	protected function register_controls() {

		// ── Bot Settings ────────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_bot',
			array(
				'label' => __( 'Bot Settings', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'bot_username',
			array(
				'label'       => __( 'Bot Username', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'e.g. MyLoginBot (without @)', 'mcp-ai-wpoos' ),
				'label_block' => true,
				'description' => __( 'Leave blank to use the bot username configured in the active Telegram connection.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'redirect_url',
			array(
				'label'       => __( 'Redirect URL', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'https://example.com/after-login/', 'mcp-ai-wpoos' ),
				'label_block' => true,
				'description' => __( 'URL Telegram redirects the user to after a successful login. Defaults to the plugin REST callback URL.', 'mcp-ai-wpoos' ),
			)
		);

		$this->end_controls_section();

		// ── Widget Appearance ───────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_appearance',
			array(
				'label' => __( 'Widget Appearance', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'button_size',
			array(
				'label'   => __( 'Button Size', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'options' => array(
					'large'  => __( 'Large', 'mcp-ai-wpoos' ),
					'medium' => __( 'Medium', 'mcp-ai-wpoos' ),
					'small'  => __( 'Small', 'mcp-ai-wpoos' ),
				),
				'default' => 'large',
			)
		);

		$this->add_control(
			'corner_radius',
			array(
				'label'       => __( 'Corner Radius (px)', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'min'         => 0,
				'max'         => 20,
				'step'        => 1,
				'default'     => '',
				'placeholder' => __( 'Default', 'mcp-ai-wpoos' ),
				'description' => __( 'Button corner radius in pixels (0–20). Leave empty for the Telegram default.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'show_avatar',
			array(
				'label'        => __( 'Show User Avatar', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => '1',
				'default'      => '1',
			)
		);

		$this->add_control(
			'lang',
			array(
				'label'       => __( 'Language Code', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'e.g. en, de, fr', 'mcp-ai-wpoos' ),
				'description' => __( 'ISO 639-1 language code for the Telegram widget UI. Leave blank to use the browser default.', 'mcp-ai-wpoos' ),
			)
		);

		$this->end_controls_section();

		// ── Access & Permissions ────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_permissions',
			array(
				'label' => __( 'Access & Permissions', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'request_access',
			array(
				'label'        => __( 'Request Write Access', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'write',
				'default'      => '',
				'description'  => __( 'Ask the user\'s permission for the bot to send them messages.', 'mcp-ai-wpoos' ),
			)
		);

		$this->end_controls_section();

		// ── Layout Style ────────────────────────────────────────────────────────────
		$this->start_controls_section(
			'section_style_layout',
			array(
				'label' => __( 'Layout', 'mcp-ai-wpoos' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_responsive_control(
			'alignment',
			array(
				'label'     => __( 'Alignment', 'mcp-ai-wpoos' ),
				'type'      => \Elementor\Controls_Manager::CHOOSE,
				'options'   => array(
					'left'   => array(
						'title' => __( 'Left', 'mcp-ai-wpoos' ),
						'icon'  => 'eicon-text-align-left',
					),
					'center' => array(
						'title' => __( 'Center', 'mcp-ai-wpoos' ),
						'icon'  => 'eicon-text-align-center',
					),
					'right'  => array(
						'title' => __( 'Right', 'mcp-ai-wpoos' ),
						'icon'  => 'eicon-text-align-right',
					),
				),
				'default'   => 'left',
				'selectors' => array(
					// The shortcode already outputs .wp-mcp-ai-telegram-login-widget; apply
					// alignment on Elementor's own wrapper so no extra div is required.
					'{{WRAPPER}}' => 'text-align: {{VALUE}};',
				),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render the widget output on the frontend by delegating to the shortcode.
	 *
	 * Attribute values are passed through to the shortcode which handles all
	 * sanitization internally; here we only escape them for safe inclusion as
	 * HTML attribute values when building the shortcode tag string.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Map Elementor control keys to their raw setting values.
		// The shortcode handler performs its own sanitization on all values.
		$atts = array(
			'bot_username'   => $settings['bot_username'] ?? '',
			'redirect_url'   => $settings['redirect_url'] ?? '',
			'button_size'    => $settings['button_size'] ?? 'large',
			'corner_radius'  => $settings['corner_radius'] ?? '',
			'request_access' => $settings['request_access'] ?? '',
			'show_avatar'    => $settings['show_avatar'] ?? '1',
			'lang'           => $settings['lang'] ?? '',
		);

		// Build the shortcode tag; esc_attr() ensures safe embedding in the attribute string.
		$shortcode_tag = '[mcp_ai_telegram_login';
		foreach ( $atts as $key => $value ) {
			if ( '' !== (string) $value ) {
				$shortcode_tag .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
			}
		}
		$shortcode_tag .= ']';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- do_shortcode output is handled inside the shortcode callback.
		echo do_shortcode( $shortcode_tag );
	}
}
