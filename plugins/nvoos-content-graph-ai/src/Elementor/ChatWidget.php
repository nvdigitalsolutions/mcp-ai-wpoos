<?php
/**
 * Elementor chat widget for the Content Graph AI addon.
 *
 * Aligned port of the base plugin's Elementor chat widget: a thin
 * wrapper that renders the ecosystem chat widget
 * (`[nvoos_content_graph_chat]`, Wave D-UI-1b) with controls mapping
 * the shortcode's attribute set. The base widget's full chat.js
 * configuration surface (voice/WebRTC/Pro services/templates) has no CG
 * counterpart yet and is not ported (documented deviation).
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

use NvoosContentGraphAi\Frontend\ChatShortcode;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
	return;
}

/**
 * `nvoos_cg_chat` Elementor widget.
 *
 * @since 1.1.0
 */
class ChatWidget extends \Elementor\Widget_Base {

	/**
	 * Widget slug.
	 *
	 * @return string
	 */
	public function get_name() {
		return 'nvoos_cg_chat';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 *
	 * @return string
	 */
	public function get_title() {
		return __( 'NV oOS CG AI Chat', 'nvoos-content-graph-ai' );
	}

	/**
	 * Widget icon for the Elementor panel.
	 *
	 * @return string
	 */
	public function get_icon() {
		return 'eicon-comments';
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
		return array( 'ai', 'chat', 'assistant', 'graph', 'conversation' );
	}

	/**
	 * Script dependencies for this widget.
	 *
	 * The shortcode enqueues its own assets at render time; declaring
	 * them here keeps Elementor's asset optimisation honest.
	 *
	 * @return array
	 */
	public function get_script_depends() {
		return array( ChatShortcode::SCRIPT_HANDLE );
	}

	/**
	 * Style dependencies for this widget.
	 *
	 * @return array
	 */
	public function get_style_depends() {
		return array( ChatShortcode::STYLE_HANDLE );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
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

		$this->add_control(
			'height',
			array(
				'label'       => __( 'Height', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '500px',
				'label_block' => true,
				'description' => __( 'Widget height as a CSS value, e.g. 500px or 60vh.', 'nvoos-content-graph-ai' ),
			)
		);

		$this->add_control(
			'show_cost',
			array(
				'label'        => __( 'Show Cost', 'nvoos-content-graph-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'nvoos-content-graph-ai' ),
				'label_off'    => __( 'No', 'nvoos-content-graph-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'placeholder',
			array(
				'label'       => __( 'Placeholder', 'nvoos-content-graph-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => '',
				'label_block' => true,
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
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$shortcode_atts = array();

		$assistant_id = isset( $settings['assistant'] ) ? absint( $settings['assistant'] ) : 0;
		if ( $assistant_id ) {
			$shortcode_atts[] = 'assistant="' . $assistant_id . '"';
		}

		if ( ! empty( $settings['allow_guests'] ) && 'yes' === $settings['allow_guests'] ) {
			$shortcode_atts[] = 'allow_guests="true"';
		}

		if ( ! empty( $settings['provider'] ) ) {
			$shortcode_atts[] = 'provider="' . esc_attr( sanitize_text_field( (string) $settings['provider'] ) ) . '"';
		}

		if ( ! empty( $settings['model'] ) ) {
			$shortcode_atts[] = 'model="' . esc_attr( sanitize_text_field( (string) $settings['model'] ) ) . '"';
		}

		if ( ! empty( $settings['height'] ) ) {
			$shortcode_atts[] = 'height="' . esc_attr( (string) $settings['height'] ) . '"';
		}

		if ( isset( $settings['show_cost'] ) && 'yes' !== $settings['show_cost'] ) {
			$shortcode_atts[] = 'show_cost="0"';
		}

		if ( ! empty( $settings['placeholder'] ) ) {
			$shortcode_atts[] = 'placeholder="' . esc_attr( sanitize_text_field( (string) $settings['placeholder'] ) ) . '"';
		}

		$shortcode = '[nvoos_content_graph_chat ' . implode( ' ', $shortcode_atts ) . ']';

		echo '<div class="nvoos-cg-elementor-chat">'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Static wrapper markup.
		echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Widget output escapes every value internally.
		echo '</div>';
	}
}
