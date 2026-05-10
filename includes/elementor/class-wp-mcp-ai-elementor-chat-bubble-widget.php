<?php
/**
 * Elementor widget for rendering a floating chat bubble.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor chat bubble widget definition.
 */
class WP_MCP_AI_Elementor_Chat_Bubble_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'wp_mcp_ai_chat_bubble';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'NV oOS Chat Bubble', 'mcp-ai-wpoos' );
	}

	/**
	 * Widget icon for Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-call-to-action';
	}

	/**
	 * Widget categories.
	 *
	 * @return array
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'ai', 'chat', 'bubble', 'floating', 'mcp' );
	}

	/**
	 * Declare script dependencies for this widget.
	 *
	 * @return array List of script handles this widget depends on.
	 */
	public function get_script_depends() {
		return array( WP_MCP_AI_Shortcode::SCRIPT_HANDLE, 'wp-mcp-ai-chat-bubble' );
	}

	/**
	 * Declare style dependencies for this widget.
	 *
	 * @return array List of style handles this widget depends on.
	 */
	public function get_style_depends() {
		return array( WP_MCP_AI_Shortcode::STYLE_HANDLE, 'wp-mcp-ai-chat-bubble-style' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->register_chat_settings_controls();
		$this->register_bubble_settings_controls();
		$this->register_panel_settings_controls();
		$this->register_bubble_style_controls();
		$this->register_panel_style_controls();
	}

	/**
	 * Register the Chat Settings controls section.
	 */
	protected function register_chat_settings_controls() {
		$this->start_controls_section(
			'section_chat_settings',
			array(
				'label' => __( 'Chat Settings', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'assistant',
			array(
				'label'       => __( 'Assistant', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Select the assistant to use. Leave empty to use the default assistant configured in the plugin settings.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'allow_guests',
			array(
				'label'        => __( 'Allow Guests', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Enable guest access using temporary tokens when the assistant allows it.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'save_transcript',
			array(
				'label'        => __( 'Save transcripts to JetEngine', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Store chat requests and responses in the ai_chat_transcripts Custom Content Type.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'enable_streaming',
			array(
				'label'        => __( 'Enable SSE Streaming', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Enable Server-Sent Events (SSE) streaming for faster perceived response times.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'allow_sensitive_tools',
			array(
				'label'        => __( 'Allow Sensitive Tools', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Allow the assistant to use sensitive tools that may modify site content or settings.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'template',
			array(
				'label'       => __( 'Chat Template', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'classic'        => __( 'Classic', 'mcp-ai-wpoos' ),
					'speech-bubbles' => __( 'Speech Bubbles', 'mcp-ai-wpoos' ),
					'compact'        => __( 'Compact', 'mcp-ai-wpoos' ),
					'sidebar'        => __( 'Sidebar', 'mcp-ai-wpoos' ),
				),
				'default'     => 'compact',
				'label_block' => true,
				'description' => __( 'Select the visual template for the chat interface.', 'mcp-ai-wpoos' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the Bubble Settings controls section.
	 */
	protected function register_bubble_settings_controls() {
		$this->start_controls_section(
			'section_bubble_settings',
			array(
				'label' => __( 'Bubble Settings', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'bubble_position',
			array(
				'label'       => __( 'Position', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'bottom-right' => __( 'Bottom Right', 'mcp-ai-wpoos' ),
					'bottom-left'  => __( 'Bottom Left', 'mcp-ai-wpoos' ),
					'top-right'    => __( 'Top Right', 'mcp-ai-wpoos' ),
					'top-left'     => __( 'Top Left', 'mcp-ai-wpoos' ),
				),
				'default'     => 'bottom-right',
				'label_block' => true,
			)
		);

		$this->add_control(
			'bubble_size',
			array(
				'label'       => __( 'Size', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'small'  => __( 'Small', 'mcp-ai-wpoos' ),
					'medium' => __( 'Medium', 'mcp-ai-wpoos' ),
					'large'  => __( 'Large', 'mcp-ai-wpoos' ),
				),
				'default'     => 'medium',
				'label_block' => true,
			)
		);

		$this->add_control(
			'bubble_icon',
			array(
				'label'   => __( 'Icon', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::ICONS,
				'default' => array(
					'value'   => 'eicon-chat',
					'library' => 'eicons',
				),
			)
		);

		$this->add_control(
			'bubble_tooltip',
			array(
				'label'       => __( 'Tooltip Text', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'e.g. Need help?', 'mcp-ai-wpoos' ),
				'label_block' => true,
				'description' => __( 'Optional tooltip shown near the bubble.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'bubble_animation',
			array(
				'label'       => __( 'Animation', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'bounce' => __( 'Bounce', 'mcp-ai-wpoos' ),
					'pulse'  => __( 'Pulse', 'mcp-ai-wpoos' ),
					'none'   => __( 'None', 'mcp-ai-wpoos' ),
				),
				'default'     => 'bounce',
				'label_block' => true,
			)
		);

		$this->add_control(
			'notification_badge',
			array(
				'label'        => __( 'Notification Badge', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Show a notification badge on the bubble.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'auto_open_delay',
			array(
				'label'       => __( 'Auto-Open Delay (seconds)', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'max'         => 60,
				'description' => __( 'Seconds before the panel auto-opens. Set to 0 to disable.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'remember_state',
			array(
				'label'        => __( 'Remember State', 'mcp-ai-wpoos' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Remember whether the panel was open or closed.', 'mcp-ai-wpoos' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the Panel Settings controls section.
	 */
	protected function register_panel_settings_controls() {
		$this->start_controls_section(
			'section_panel_settings',
			array(
				'label' => __( 'Panel Settings', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'panel_title',
			array(
				'label'       => __( 'Panel Title', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Chat with AI', 'mcp-ai-wpoos' ),
				'label_block' => true,
			)
		);

		$this->add_responsive_control(
			'panel_width',
			array(
				'label'      => __( 'Panel Width', 'mcp-ai-wpoos' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 300,
						'max' => 600,
					),
				),
				'default'    => array(
					'size' => 400,
					'unit' => 'px',
				),
				'devices'    => array( 'desktop' ),
			)
		);

		$this->add_responsive_control(
			'panel_height',
			array(
				'label'      => __( 'Panel Height', 'mcp-ai-wpoos' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 400,
						'max' => 800,
					),
				),
				'default'    => array(
					'size' => 550,
					'unit' => 'px',
				),
				'devices'    => array( 'desktop' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the Bubble Style controls section.
	 */
	protected function register_bubble_style_controls() {
		$this->start_controls_section(
			'section_style_bubble',
			array(
				'label' => __( 'Bubble Style', 'mcp-ai-wpoos' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'bubble_color',
			array(
				'label'   => __( 'Bubble Color', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#4f46e5',
			)
		);

		$this->add_control(
			'bubble_text_color',
			array(
				'label'   => __( 'Bubble Text Color', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ffffff',
			)
		);

		$this->add_control(
			'bubble_hover_color',
			array(
				'label'   => __( 'Bubble Hover Color', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#4338ca',
			)
		);

		$this->add_control(
			'badge_color',
			array(
				'label'   => __( 'Badge Color', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ef4444',
			)
		);

		$this->add_control(
			'badge_text_color',
			array(
				'label'   => __( 'Badge Text Color', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ffffff',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Register the Panel Style controls section.
	 */
	protected function register_panel_style_controls() {
		$this->start_controls_section(
			'section_style_panel',
			array(
				'label' => __( 'Panel Style', 'mcp-ai-wpoos' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'panel_background',
			array(
				'label'   => __( 'Panel Background', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ffffff',
			)
		);

		$this->add_control(
			'panel_border_radius',
			array(
				'label'      => __( 'Panel Border Radius', 'mcp-ai-wpoos' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 0,
						'max' => 30,
					),
				),
				'default'    => array(
					'size' => 16,
					'unit' => 'px',
				),
			)
		);

		$this->add_control(
			'header_background',
			array(
				'label'       => __( 'Header Background', 'mcp-ai-wpoos' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '',
				'description' => __( 'Defaults to the bubble color when empty.', 'mcp-ai-wpoos' ),
			)
		);

		$this->add_control(
			'header_text_color',
			array(
				'label'   => __( 'Header Text Color', 'mcp-ai-wpoos' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ffffff',
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Retrieve assistant options for the assistant dropdown control.
	 *
	 * @return array Associative array of assistant ID => title.
	 */
	protected function get_assistant_options() {
		if ( class_exists( 'WP_MCP_AI_Cache_Helper' ) && WP_MCP_AI_Cache_Helper::is_caching_enabled() ) {
			return WP_MCP_AI_Cache_Helper::get_elementor_options( array( $this, 'build_assistant_options' ) );
		}

		return $this->build_assistant_options();
	}

	/**
	 * Build assistant options array (extracted for caching).
	 *
	 * @return array Assistant options for dropdown.
	 */
	public function build_assistant_options() {
		$options = array( '' => __( 'Default Assistant', 'mcp-ai-wpoos' ) );

		if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return $options;
		}

		// Check if the post type is registered before querying.
		// During Elementor AJAX requests, the post type may not be registered yet.
		if ( ! post_type_exists( WP_MCP_AI_Assistant_CPT::POST_TYPE ) ) {
			return $options;
		}

		$assistants = get_posts(
			array(
				'post_type'              => WP_MCP_AI_Assistant_CPT::POST_TYPE,
				'post_status'            => 'publish',
				'numberposts'            => -1,
				'orderby'                => 'title',
				'order'                  => 'ASC',
				'suppress_filters'       => true,
				'fields'                 => 'ids',
				'no_found_rows'          => true,
				'update_post_term_cache' => false,
			)
		);

		if ( ! is_array( $assistants ) || empty( $assistants ) ) {
			return $options;
		}

		foreach ( $assistants as $assistant_id ) {
			$title = get_the_title( $assistant_id );
			if ( $title && ! is_wp_error( $title ) ) {
				$options[ (string) $assistant_id ] = $title;
			}
		}

		return $options;
	}

	/**
	 * Build CSS custom property declarations from widget settings.
	 *
	 * @param array $settings Widget settings from get_settings_for_display().
	 *
	 * @return string Inline style string of CSS custom properties.
	 */
	protected function build_css_variables( $settings ) {
		$vars = array();

		$color_map = array(
			'bubble_color'       => '--wp-mcp-ai-chat-bubble-color',
			'bubble_text_color'  => '--wp-mcp-ai-chat-bubble-text-color',
			'bubble_hover_color' => '--wp-mcp-ai-chat-bubble-hover-color',
			'badge_color'        => '--wp-mcp-ai-chat-bubble-badge-color',
			'badge_text_color'   => '--wp-mcp-ai-chat-bubble-badge-text-color',
			'panel_background'   => '--wp-mcp-ai-chat-bubble-panel-background',
			'header_text_color'  => '--wp-mcp-ai-chat-bubble-header-text-color',
		);

		foreach ( $color_map as $setting_key => $css_var ) {
			if ( ! empty( $settings[ $setting_key ] ) ) {
				$vars[] = $css_var . ':' . sanitize_hex_color( $settings[ $setting_key ] );
			}
		}

		$header_bg = ! empty( $settings['header_background'] )
			? $settings['header_background']
			: ( ! empty( $settings['bubble_color'] ) ? $settings['bubble_color'] : '#4f46e5' );
		$vars[]    = '--wp-mcp-ai-chat-bubble-header-background:' . sanitize_hex_color( $header_bg );

		if ( ! empty( $settings['panel_border_radius']['size'] ) ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-panel-border-radius:' . absint( $settings['panel_border_radius']['size'] ) . 'px';
		}

		if ( ! empty( $settings['panel_width']['size'] ) ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-panel-width:' . absint( $settings['panel_width']['size'] ) . 'px';
		}

		if ( ! empty( $settings['panel_height']['size'] ) ) {
			$vars[] = '--wp-mcp-ai-chat-bubble-panel-height:' . absint( $settings['panel_height']['size'] ) . 'px';
		}

		return implode( ';', $vars );
	}

	/**
	 * Build shortcode string from widget settings.
	 *
	 * @param array $settings Widget settings from get_settings_for_display().
	 *
	 * @return string Shortcode string.
	 */
	protected function build_shortcode( $settings ) {
		$attributes = array();

		$assistant_setting = isset( $settings['assistant'] ) ? $settings['assistant'] : '';
		if ( '' !== $assistant_setting ) {
			$attributes['assistant'] = (string) absint( $assistant_setting );
		}

		$allow_guests               = ! empty( $settings['allow_guests'] ) && 'yes' === $settings['allow_guests'];
		$attributes['allow_guests'] = $allow_guests ? 'true' : 'false';

		$save_transcript = empty( $settings['save_transcript'] ) || 'yes' === $settings['save_transcript'];
		if ( ! $save_transcript ) {
			$attributes['save_transcript'] = 'false';
		}

		$enable_streaming               = ! empty( $settings['enable_streaming'] ) && 'yes' === $settings['enable_streaming'];
		$attributes['enable_streaming'] = $enable_streaming ? 'true' : 'false';

		$allow_sensitive_tools = ! empty( $settings['allow_sensitive_tools'] ) && 'yes' === $settings['allow_sensitive_tools'];
		if ( $allow_sensitive_tools ) {
			$attributes['allow_sensitive_tools'] = 'true';
		}

		$template = isset( $settings['template'] ) ? sanitize_key( $settings['template'] ) : 'compact';
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
	 * Build data attributes for the bubble container.
	 *
	 * @param string $bubble_id Unique bubble identifier.
	 * @param array  $settings  Widget settings from get_settings_for_display().
	 *
	 * @return string HTML data attributes string.
	 */
	protected function build_data_attributes( $bubble_id, $settings ) {
		$attrs = array();

		$attrs[] = 'data-bubble-id="' . esc_attr( $bubble_id ) . '"';

		$auto_open_delay = isset( $settings['auto_open_delay'] ) ? absint( $settings['auto_open_delay'] ) : 0;
		$attrs[]         = 'data-auto-open-delay="' . esc_attr( (string) $auto_open_delay ) . '"';

		$remember_state = ! empty( $settings['remember_state'] ) && 'yes' === $settings['remember_state'];
		$attrs[]        = 'data-remember-state="' . esc_attr( $remember_state ? 'true' : 'false' ) . '"';

		$notification_badge = ! empty( $settings['notification_badge'] ) && 'yes' === $settings['notification_badge'];
		$attrs[]            = 'data-notification-badge="' . esc_attr( $notification_badge ? 'true' : 'false' ) . '"';

		return implode( ' ', $attrs );
	}

	/**
	 * Render the widget on the front-end.
	 *
	 * The bubble is always rendered inline so that:
	 *
	 *  1. Elementor can detect the widget output and fire the
	 *     `frontend/element_ready` hook for JavaScript init.
	 *  2. There are no timing issues with wp_footer priorities when
	 *     the widget is placed in header, footer, or popup templates.
	 *  3. Script and style enqueue calls take effect at the normal
	 *     template-rendering stage.
	 *
	 * The companion JavaScript (`chat-bubble.js`) automatically promotes
	 * the bubble element to `document.body` via `_promoteToBody()` on
	 * the live front-end, escaping ancestor stacking-context traps
	 * (overflow:hidden, transforms, z-index) created by Elementor
	 * sections, columns, and header/footer templates.
	 *
	 * **Placement guidance — Elementor Theme Builder:**
	 *
	 *  - **Site-Wide Footer** (recommended): Place the widget in a
	 *    footer template with display conditions set to "Entire Site."
	 *    The footer renders on every page and the widget is promoted
	 *    to `<body>` by JavaScript.  The footer location renders
	 *    before `wp_footer`, so all scripts and inline configs are
	 *    guaranteed to be available.
	 *
	 *  - **Site-Wide Header**: Also works well.  The header renders
	 *    even earlier than the footer, so there are no timing issues.
	 *
	 *  - **Individual pages / Single templates**: Works for page-
	 *    specific chat bubbles.  The widget appears only on pages
	 *    matching the template's display conditions.
	 *
	 *  - **Popups**: Supported but not recommended.  Popups render
	 *    dynamically and the fixed-position bubble may conflict with
	 *    the popup's own positioning.
	 *
	 * @see assets/js/chat-bubble.js BubbleInstance._promoteToBody()
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		/*
		 * Explicitly enqueue bubble assets so they are loaded regardless of
		 * Elementor's Improved Asset Loading optimization.  The Gutenberg
		 * chat-bubble block already does this (see blocks/chat-bubble/render.php);
		 * without these calls the bubble JS/CSS may never reach the page when
		 * Elementor skips get_script_depends() handles during its optimization pass.
		 *
		 * The main chat script (WP_MCP_AI_Shortcode::SCRIPT_HANDLE) and its
		 * localization are enqueued inside the shortcode's render_shortcode()
		 * method via do_shortcode() below, so we only need the bubble pair here.
		 */
		wp_enqueue_script( 'wp-mcp-ai-chat-bubble' );
		wp_enqueue_style( 'wp-mcp-ai-chat-bubble-style' );

		$bubble_id = 'wp-mcp-ai-bubble-' . $this->get_id();
		$position  = sanitize_key( isset( $settings['bubble_position'] ) ? $settings['bubble_position'] : 'bottom-right' );
		$size      = sanitize_key( isset( $settings['bubble_size'] ) ? $settings['bubble_size'] : 'medium' );
		$animation = sanitize_key( isset( $settings['bubble_animation'] ) ? $settings['bubble_animation'] : 'bounce' );

		$css_vars = $this->build_css_variables( $settings );

		$classes  = 'wp-mcp-ai-chat-bubble';
		$classes .= ' wp-mcp-ai-chat-bubble--' . $position;
		$classes .= ' wp-mcp-ai-chat-bubble--' . $size;
		if ( 'none' !== $animation ) {
			$classes .= ' wp-mcp-ai-chat-bubble--' . $animation;
		}

		$data_attrs = $this->build_data_attributes( $bubble_id, $settings );
		$shortcode  = $this->build_shortcode( $settings );

		$panel_title = isset( $settings['panel_title'] ) ? $settings['panel_title'] : __( 'Chat with AI', 'mcp-ai-wpoos' );
		$tooltip     = isset( $settings['bubble_tooltip'] ) ? trim( $settings['bubble_tooltip'] ) : '';

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
		 * However, Elementor's Improved Asset Loading, script deferral, and
		 * dynamic widget re-rendering can prevent wp_add_inline_script() from
		 * actually printing the config.  By extracting the config here we can
		 * output it as a reliable inline <script> tag directly in the widget
		 * markup — the same approach used by the admin "Test Assistant" page.
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
		 * Render bubble HTML inline.  On the live front-end the JS
		 * _promoteToBody() call moves it to document.body automatically,
		 * escaping any ancestor stacking-context traps.
		 */
		$this->render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html, $inline_configs );
	}

	/**
	 * Output the bubble HTML structure.
	 *
	 * Extracted from render() so both the inline (editor) and footer
	 * (front-end) code-paths share the same markup.
	 *
	 * @param string $classes        CSS class string.
	 * @param string $data_attrs     Pre-escaped data-attribute string.
	 * @param string $css_vars       Inline CSS custom properties string.
	 * @param string $panel_title    Panel dialog title.
	 * @param string $tooltip        Optional bubble tooltip text.
	 * @param array  $settings       Widget settings.
	 * @param string $shortcode_html Pre-rendered shortcode output.
	 * @param array  $inline_configs Chat instance configs to output inline.
	 */
	protected function render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html, $inline_configs = array() ) {
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_attrs built with esc_attr().
		echo '<div class="' . esc_attr( $classes ) . '" ' . $data_attrs . ' style="' . esc_attr( $css_vars ) . '">';

		echo '<button class="wp-mcp-ai-chat-bubble__trigger" aria-expanded="false" aria-label="' . esc_attr__( 'Open chat', 'mcp-ai-wpoos' ) . '">';
		echo '<span class="wp-mcp-ai-chat-bubble__trigger-icon">';
		// Chat SVG icon.
		echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		echo '<path d="M21 11.5a8.38 8.38 0 0 1-.9 3.8 8.5 8.5 0 0 1-7.6 4.7 8.38 8.38 0 0 1-3.8-.9L3 21l1.9-5.7a8.38 8.38 0 0 1-.9-3.8 8.5 8.5 0 0 1 4.7-7.6 8.38 8.38 0 0 1 3.8-.9h.5a8.48 8.48 0 0 1 8 8v.5z" fill="currentColor"/>';
		echo '</svg>';
		echo '</span>';
		echo '<span class="wp-mcp-ai-chat-bubble__trigger-close-icon">';
		// Close SVG icon.
		echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		echo '<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
		echo '</svg>';
		echo '</span>';

		$show_badge = ! empty( $settings['notification_badge'] ) && 'yes' === $settings['notification_badge'];
		echo '<span class="wp-mcp-ai-chat-bubble__badge" aria-hidden="true"' . ( $show_badge ? '' : ' hidden' ) . '></span>';

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
		$safe_html = preg_replace( '/data-wp-mcp-ai-chat(?![-\w])/', 'data-wp-mcp-ai-chat-deferred', $safe_html );
		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode output is generated by WP_MCP_AI_Shortcode::render_shortcode() which individually escapes all values with esc_attr()/esc_html(). wp_kses_post() strips data-* attributes, SVGs, form elements, and <script type="application/json"> config blocks that the chat UI requires. The regex only renames a data attribute for lazy init.
		echo $safe_html;

		echo '</div>';

		echo '</div>';

		/*
		 * Output the chat instance config as an inline <script> tag.
		 *
		 * This guarantees the config is available in window.wpMcpAiChatInstances
		 * regardless of whether wp_add_inline_script() (used inside the
		 * shortcode) actually prints — Elementor's Improved Asset Loading,
		 * script deferral, and dynamic widget re-rendering can all prevent
		 * the WordPress inline-script queue from executing.
		 *
		 * This is the same approach used by the admin "Test Assistant" page
		 * (assets/js/admin-test-assistant.js) which builds the config inline.
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

		echo '</div>';
	}
}
