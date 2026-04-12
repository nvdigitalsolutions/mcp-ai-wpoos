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
	 * On the live front-end the complete bubble HTML is queued for
	 * rendering inside wp_footer via WP_MCP_AI_Shortcode::queue_footer_bubble().
	 * This ensures the bubble is a direct child of <body>, which:
	 *
	 *  1. Escapes ancestor stacking-context / overflow:hidden traps
	 *     created by Elementor header/footer sections.
	 *  2. Avoids wp_kses_post() stripping data-* attributes that
	 *     chat.js relies on (data-wp-mcp-ai-chat, data-template).
	 *  3. Guarantees scripts are loaded after the DOM is present.
	 *
	 * Inside the Elementor visual editor the widget renders inline
	 * so the builder can display and manage it.
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
		 * Process the shortcode now so that scripts and inline config
		 * are enqueued at the normal time.  The HTML output is captured
		 * and included in the footer-rendered bubble below.
		 */
		$shortcode_html = do_shortcode( $shortcode );

		/*
		 * Inside the Elementor editor the widget must render inline
		 * for the visual builder to work correctly.
		 */
		$is_editor = (
			\Elementor\Plugin::$instance->editor->is_edit_mode() ||
			\Elementor\Plugin::$instance->preview->is_preview_mode()
		);

		if ( $is_editor ) {
			$this->render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html );
			return;
		}

		/*
		 * Front-end: buffer the bubble HTML and queue it for wp_footer
		 * so it renders as a direct child of <body>.
		 */
		ob_start();
		$this->render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html );
		$bubble_html = ob_get_clean();

		WP_MCP_AI_Shortcode::queue_footer_bubble( $bubble_html );
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
	 */
	protected function render_bubble_html( $classes, $data_attrs, $css_vars, $panel_title, $tooltip, $settings, $shortcode_html ) {
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

		echo '<div class="wp-mcp-ai-chat-bubble__panel" role="dialog" aria-label="' . esc_attr( $panel_title ) . '" aria-hidden="true">';

		echo '<div class="wp-mcp-ai-chat-bubble__panel-header">';
		echo '<span class="wp-mcp-ai-chat-bubble__panel-title">' . esc_html( $panel_title ) . '</span>';
		echo '<button class="wp-mcp-ai-chat-bubble__panel-close" aria-label="' . esc_attr__( 'Close chat', 'mcp-ai-wpoos' ) . '">';
		echo '<svg width="24" height="24" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">';
		echo '<path d="M18 6L6 18M6 6l12 12" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"/>';
		echo '</svg>';
		echo '</button>';
		echo '</div>';

		echo '<div class="wp-mcp-ai-chat-bubble__panel-body">';
		echo WP_MCP_AI_Shortcode::kses_chat_output( $shortcode_html ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- kses_chat_output() passes HTML through wp_kses_post() with data-* support.
		echo '</div>';

		echo '</div>';

		echo '</div>';
	}
}
