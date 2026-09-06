<?php
/**
 * Elementor chat bubble widget for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's Elementor chat bubble widget: a
 * floating trigger button + hidden chat panel shell (position / size /
 * animation / tooltip / panel title / colours as CSS custom properties)
 * embedding the ecosystem chat widget (`[nvoos_content_graph_chat]`,
 * Wave D-UI-1b). Class names use the ecosystem's `nvoos-cg-bubble`
 * prefix so monolith installs can run both bubbles side by side without
 * style collisions (documented deviation).
 *
 * The markup intentionally mirrors `Blocks\ChatBubbleBlock::render()`;
 * the widget only differs in the bubble id source (Elementor widget id
 * instead of `wp_unique_id()`), the settings vocabulary (snake_case
 * Elementor controls), and the `get_*_depends()` handles. The base
 * widget's extra controls (bubble icon, hover/badge colours, panel
 * background/radius) are deferred until the CG bubble styles grow
 * counterparts (documented deviation).
 *
 * The file bails out when Elementor is absent so the plugin never
 * hard-depends on it.
 *
 * @package NvoosContentGraphAi\Elementor
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Elementor;

use NvoosContentGraphAi\Blocks\Blocks;
use NvoosContentGraphAi\Frontend\ChatShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * `nvoos_cg_chat_bubble` Elementor widget.
 *
 * @since 1.1.0
 */
class ChatBubbleWidget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'nvoos_cg_chat_bubble';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'NV oOS CG Chat Bubble', 'nvoos-content-graph-ai' );
	}

	/**
	 * Widget icon for the Elementor panel.
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
		return array( ElementorHub::CATEGORY );
	}

	/**
	 * Keywords to help search for the widget.
	 *
	 * @return array
	 */
	public function get_keywords() {
		return array( 'ai', 'chat', 'bubble', 'floating', 'graph' );
	}

	/**
	 * Script dependencies for this widget.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( ChatShortcode::SCRIPT_HANDLE, Blocks::BUBBLE_SCRIPT_HANDLE );
	}

	/**
	 * Style dependencies for this widget.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( ChatShortcode::STYLE_HANDLE, Blocks::BUBBLE_STYLE_HANDLE );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->register_chat_settings_controls();
		$this->register_bubble_settings_controls();
		$this->register_panel_settings_controls();
		$this->register_bubble_style_controls();
	}

	/**
	 * Register the Chat Settings controls section.
	 */
	protected function register_chat_settings_controls() {
		$this->start_controls_section(
			'section_chat_settings',
			array(
				'label' => __( 'Chat Settings', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'assistant',
			array(
				'label'       => __( 'Assistant', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => $this->get_assistant_options(),
				'default'     => '',
				'label_block' => true,
				'description' => __( 'Select the assistant to use. Leave empty to use the default assistant configured in the plugin settings.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'allow_guests',
			array(
				'label'        => __( 'Allow Guests', 'nvoos-content-graph-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'nvoos-content-graph-ai' ),
				'label_off'    => __( 'No', 'nvoos-content-graph-ai' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Enable guest access using temporary tokens when the assistant allows it.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'provider',
			array(
				'label'       => __( 'Provider (optional)', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
			)
		);

		$this->add_control(
			'model',
			array(
				'label'       => __( 'Model (optional)', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
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
				'label' => __( 'Bubble Settings', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'bubble_position',
			array(
				'label'       => __( 'Position', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'bottom-right' => __( 'Bottom Right', 'nvoos-content-graph-ai' ),
					'bottom-left'  => __( 'Bottom Left', 'nvoos-content-graph-ai' ),
					'top-right'    => __( 'Top Right', 'nvoos-content-graph-ai' ),
					'top-left'     => __( 'Top Left', 'nvoos-content-graph-ai' ),
				),
				'default'     => 'bottom-right',
				'label_block' => true,
			)
		);

		$this->add_control(
			'bubble_size',
			array(
				'label'       => __( 'Size', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'small'  => __( 'Small', 'nvoos-content-graph-ai' ),
					'medium' => __( 'Medium', 'nvoos-content-graph-ai' ),
					'large'  => __( 'Large', 'nvoos-content-graph-ai' ),
				),
				'default'     => 'medium',
				'label_block' => true,
			)
		);

		$this->add_control(
			'bubble_tooltip',
			array(
				'label'       => __( 'Tooltip Text', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'placeholder' => __( 'e.g. Need help?', 'nvoos-content-graph-ai' ),
				'label_block' => true,
				'description' => __( 'Optional tooltip shown near the bubble.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'bubble_animation',
			array(
				'label'       => __( 'Animation', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'bounce' => __( 'Bounce', 'nvoos-content-graph-ai' ),
					'pulse'  => __( 'Pulse', 'nvoos-content-graph-ai' ),
					'none'   => __( 'None', 'nvoos-content-graph-ai' ),
				),
				'default'     => 'bounce',
				'label_block' => true,
			)
		);

		$this->add_control(
			'notification_badge',
			array(
				'label'        => __( 'Notification Badge', 'nvoos-content-graph-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'nvoos-content-graph-ai' ),
				'label_off'    => __( 'No', 'nvoos-content-graph-ai' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Show a notification badge on the bubble.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'auto_open_delay',
			array(
				'label'       => __( 'Auto-Open Delay (seconds)', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'max'         => 60,
				'description' => __( 'Seconds before the panel auto-opens. Set to 0 to disable.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'remember_state',
			array(
				'label'        => __( 'Remember State', 'nvoos-content-graph-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'nvoos-content-graph-ai' ),
				'label_off'    => __( 'No', 'nvoos-content-graph-ai' ),
				'return_value' => 'yes',
				'default'      => '',
				'description'  => __( 'Remember whether the panel was open or closed.', 'nvoos-content-graph-ai' ),
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
				'label' => __( 'Panel Settings', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'panel_title',
			array(
				'label'       => __( 'Panel Title', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Chat with AI', 'nvoos-content-graph-ai' ),
				'label_block' => true,
			)
		);

		$this->add_responsive_control(
			'panel_width',
			array(
				'label'      => __( 'Panel Width', 'nvoos-content-graph-ai' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 260,
						'max' => 800,
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
				'label'      => __( 'Panel Height', 'nvoos-content-graph-ai' ),
				'type'       => \Elementor\Controls_Manager::SLIDER,
				'size_units' => array( 'px' ),
				'range'      => array(
					'px' => array(
						'min' => 320,
						'max' => 900,
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
				'label' => __( 'Bubble Style', 'nvoos-content-graph-ai' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'bubble_color',
			array(
				'label'   => __( 'Bubble Color', 'nvoos-content-graph-ai' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#4f46e5',
			)
		);

		$this->add_control(
			'bubble_text_color',
			array(
				'label'   => __( 'Bubble Text Color', 'nvoos-content-graph-ai' ),
				'type'    => \Elementor\Controls_Manager::COLOR,
				'default' => '#ffffff',
			)
		);

		$this->add_control(
			'header_background',
			array(
				'label'       => __( 'Header Background', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'default'     => '',
				'description' => __( 'Defaults to the bubble color when empty.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'header_text_color',
			array(
				'label'   => __( 'Header Text Color', 'nvoos-content-graph-ai' ),
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
		$options = array( '' => __( 'Default Assistant', 'nvoos-content-graph-ai' ) );

		if ( ! post_type_exists( 'mcp_ai_assistant' ) ) {
			return $options;
		}

		$assistants = get_posts(
			array(
				'post_type'              => 'mcp_ai_assistant',
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
	 * Render the widget on the front-end.
	 *
	 * The bubble is rendered inline (no wp_footer promotion dance) so
	 * Elementor can fire `frontend/element_ready` for the companion
	 * `content-graph-ai-chat-bubble.js` initialisation — the same
	 * approach as the D-UI-2 bubble block. The bubble styles carry
	 * `position: fixed`, so placement inside header/footer templates
	 * works like the block.
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		// Chat attributes.
		$assistant_id = isset( $settings['assistant'] ) ? absint( $settings['assistant'] ) : 0;
		$allow_guests = ! empty( $settings['allow_guests'] ) && 'yes' === $settings['allow_guests'];
		$provider     = isset( $settings['provider'] ) ? sanitize_text_field( (string) $settings['provider'] ) : '';
		$model        = isset( $settings['model'] ) ? sanitize_text_field( (string) $settings['model'] ) : '';

		// Bubble appearance.
		$bubble_position  = isset( $settings['bubble_position'] ) ? sanitize_key( $settings['bubble_position'] ) : 'bottom-right';
		$bubble_size      = isset( $settings['bubble_size'] ) ? sanitize_key( $settings['bubble_size'] ) : 'medium';
		$bubble_animation = isset( $settings['bubble_animation'] ) ? sanitize_key( $settings['bubble_animation'] ) : 'bounce';
		$bubble_tooltip   = isset( $settings['bubble_tooltip'] ) ? trim( sanitize_text_field( (string) $settings['bubble_tooltip'] ) ) : '';

		// Panel settings.
		$panel_title  = isset( $settings['panel_title'] ) ? sanitize_text_field( (string) $settings['panel_title'] ) : __( 'Chat with AI', 'nvoos-content-graph-ai' );
		$panel_width  = isset( $settings['panel_width']['size'] ) ? absint( $settings['panel_width']['size'] ) : 400;
		$panel_height = isset( $settings['panel_height']['size'] ) ? absint( $settings['panel_height']['size'] ) : 550;

		// Behavior.
		$auto_open_delay    = isset( $settings['auto_open_delay'] ) ? absint( $settings['auto_open_delay'] ) : 0;
		$remember_state     = ! empty( $settings['remember_state'] ) && 'yes' === $settings['remember_state'];
		$notification_badge = ! empty( $settings['notification_badge'] ) && 'yes' === $settings['notification_badge'];

		// Colors.
		$bubble_color      = isset( $settings['bubble_color'] ) ? sanitize_hex_color( $settings['bubble_color'] ) : '#4f46e5';
		$bubble_text_color = isset( $settings['bubble_text_color'] ) ? sanitize_hex_color( $settings['bubble_text_color'] ) : '#ffffff';
		$header_background = isset( $settings['header_background'] ) ? sanitize_hex_color( $settings['header_background'] ) : '';
		$header_text_color = isset( $settings['header_text_color'] ) ? sanitize_hex_color( $settings['header_text_color'] ) : '#ffffff';

		wp_enqueue_script( Blocks::BUBBLE_SCRIPT_HANDLE );
		wp_enqueue_style( Blocks::BUBBLE_STYLE_HANDLE );

		// Shortcode attributes for the embedded widget.
		$shortcode_atts = array();
		if ( $assistant_id ) {
			$shortcode_atts[] = 'assistant="' . $assistant_id . '"';
		}
		if ( $allow_guests ) {
			$shortcode_atts[] = 'allow_guests="true"';
		}
		if ( '' !== $provider ) {
			$shortcode_atts[] = 'provider="' . esc_attr( $provider ) . '"';
		}
		if ( '' !== $model ) {
			$shortcode_atts[] = 'model="' . esc_attr( $model ) . '"';
		}
		$shortcode = '[nvoos_content_graph_chat ' . implode( ' ', $shortcode_atts ) . ']';

		// CSS custom properties (mirrors ChatBubbleBlock::render()).
		$css_vars = array();

		if ( '#4f46e5' !== $bubble_color && '' !== $bubble_color ) {
			$css_vars[] = '--nvoos-cg-bubble-color:' . $bubble_color;
		}
		if ( '#ffffff' !== $bubble_text_color && '' !== $bubble_text_color ) {
			$css_vars[] = '--nvoos-cg-bubble-text-color:' . $bubble_text_color;
		}
		if ( '' !== $header_background ) {
			$css_vars[] = '--nvoos-cg-bubble-header-background:' . $header_background;
		}
		if ( '#ffffff' !== $header_text_color && '' !== $header_text_color ) {
			$css_vars[] = '--nvoos-cg-bubble-header-text-color:' . $header_text_color;
		}
		if ( 400 !== $panel_width ) {
			$css_vars[] = '--nvoos-cg-bubble-panel-width:' . $panel_width . 'px';
		}
		if ( 550 !== $panel_height ) {
			$css_vars[] = '--nvoos-cg-bubble-panel-height:' . $panel_height . 'px';
		}

		$css_vars_string = implode( ';', $css_vars );

		// Classes + data attributes (mirrors ChatBubbleBlock::render()).
		$bubble_id = 'nvoos-cg-bubble-' . $this->get_id();

		$classes  = 'nvoos-cg-bubble';
		$classes .= ' nvoos-cg-bubble--' . $bubble_position;
		$classes .= ' nvoos-cg-bubble--' . $bubble_size;
		if ( 'none' !== $bubble_animation ) {
			$classes .= ' nvoos-cg-bubble--' . $bubble_animation;
		}

		$data_attrs  = 'data-bubble-id="' . esc_attr( $bubble_id ) . '"';
		$data_attrs .= ' data-auto-open-delay="' . esc_attr( (string) $auto_open_delay ) . '"';
		$data_attrs .= ' data-remember-state="' . esc_attr( $remember_state ? 'true' : 'false' ) . '"';
		$data_attrs .= ' data-notification-badge="' . esc_attr( $notification_badge ? 'true' : 'false' ) . '"';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- $data_attrs built with esc_attr(); $css_vars_string built from sanitize_hex_color() + absint().
		echo '<div class="' . esc_attr( $classes ) . '" ' . $data_attrs . ' style="' . esc_attr( $css_vars_string ) . '">';

		echo '<button class="nvoos-cg-bubble__trigger" aria-expanded="false" aria-label="' . esc_attr__( 'Open chat', 'nvoos-content-graph-ai' ) . '">';
		echo '<span class="nvoos-cg-bubble__trigger-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M21 15a2 2 0 0 1-2 2H7l-4 4V5a2 2 0 0 1 2-2h14a2 2 0 0 1 2 2z"/></svg></span>';
		echo '<span class="nvoos-cg-bubble__trigger-close-icon"><svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg></span>';
		echo '<span class="nvoos-cg-bubble__badge" aria-hidden="true"' . ( $notification_badge ? '' : ' hidden' ) . '></span>';
		echo '</button>';

		if ( '' !== $bubble_tooltip ) {
			echo '<span class="nvoos-cg-bubble__tooltip">' . esc_html( $bubble_tooltip ) . '</span>';
		}

		echo '<div class="nvoos-cg-bubble__panel" role="dialog" aria-label="' . esc_attr( $panel_title ) . '" aria-hidden="true" inert>';
		echo '<div class="nvoos-cg-bubble__panel-header">';
		echo '<span class="nvoos-cg-bubble__panel-title">' . esc_html( $panel_title ) . '</span>';
		echo '<button class="nvoos-cg-bubble__panel-close" aria-label="' . esc_attr__( 'Close chat', 'nvoos-content-graph-ai' ) . '">';
		echo '<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><line x1="18" y1="6" x2="6" y2="18"/><line x1="6" y1="6" x2="18" y2="18"/></svg>';
		echo '</button>';
		echo '</div>';

		echo '<div class="nvoos-cg-bubble__panel-body">';
		echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget output escapes every value internally.
		echo '</div>';

		echo '</div>'; // Panel.
		echo '</div>'; // Bubble.
	}
}
