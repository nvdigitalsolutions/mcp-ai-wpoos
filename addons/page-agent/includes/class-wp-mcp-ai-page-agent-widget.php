<?php
/**
 * Page Agent Widget & Shortcode
 *
 * Registers the [mcp_ai_page_agent] shortcode and provides
 * Elementor widget integration for the Page Agent UI.
 *
 * @package NV_oOS_Page_Agent
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Widget and shortcode handler for Page Agent.
 *
 * @since 0.1.0
 */
class WP_MCP_AI_Page_Agent_Widget {

	/**
	 * Shortcode tag.
	 *
	 * @since 0.1.0
	 * @var string
	 */
	const SHORTCODE = 'mcp_ai_page_agent';

	/**
	 * Constructor — registers hooks.
	 *
	 * @since 0.1.0
	 */
	public function __construct() {
		add_shortcode( self::SHORTCODE, array( $this, 'render_shortcode' ) );

		// Elementor widget registration (if Elementor is active).
		add_action( 'elementor/widgets/register', array( $this, 'register_elementor_widget' ) );
	}

	/**
	 * Render the [mcp_ai_page_agent] shortcode.
	 *
	 * Outputs a container div for the Page Agent floating UI panel.
	 * The actual agent initialization is handled by the bridge script.
	 *
	 * @since 0.1.0
	 *
	 * @param array  $atts    Shortcode attributes.
	 * @param string $content Enclosed content (unused).
	 * @return string HTML output.
	 */
	public function render_shortcode( $atts = array(), $content = '' ) {
		$settings = WP_MCP_AI_Page_Agent::get_settings();

		if ( ! $settings['enabled'] ) {
			return '';
		}

		$atts = shortcode_atts(
			array(
				'model'      => null,
				'language'   => null,
				'max_steps'  => null,
				'position'   => 'bottom-right',
				'show_toggle' => 'true',
			),
			$atts,
			self::SHORTCODE
		);

		$data_attrs = array(
			'position' => $atts['position'],
		);

		if ( $atts['model'] ) {
			$data_attrs['model'] = $atts['model'];
		}
		if ( $atts['language'] ) {
			$data_attrs['language'] = $atts['language'];
		}
		if ( $atts['max_steps'] ) {
			$data_attrs['max-steps'] = absint( $atts['max_steps'] );
		}
		$data_attrs['show-toggle'] = $atts['show_toggle'];

		$attr_string = '';
		foreach ( $data_attrs as $key => $value ) {
			$attr_string .= sprintf( ' data-%s="%s"', esc_attr( $key ), esc_attr( $value ) );
		}

		return sprintf(
			'<div class="nvoos-page-agent-container"%s></div>',
			$attr_string
		);
	}

	/**
	 * Register the Elementor widget.
	 *
	 * @since 0.1.0
	 *
	 * @param \Elementor\Widgets_Manager $widgets_manager Elementor widgets manager.
	 * @return void
	 */
	public function register_elementor_widget( $widgets_manager ) {
		if ( ! class_exists( '\Elementor\Widget_Base' ) ) {
			return;
		}

		// Include widget class inline for simplicity.
		$widget_class = $this->get_elementor_widget_class();

		if ( class_exists( $widget_class ) ) {
			return; // Already registered.
		}

		// Define the widget class dynamically.
		eval( $widget_class ); // phpcs:ignore Squiz.PHP.Eval.Discouraged -- Elementor widget registration pattern.

		$widgets_manager->register( new WP_MCP_AI_Page_Agent_Elementor_Widget() );
	}

	/**
	 * Get the Elementor widget class definition as a string.
	 *
	 * We define it as a string to avoid parse errors when Elementor is not active.
	 *
	 * @since 0.1.0
	 *
	 * @return string
	 */
	private function get_elementor_widget_class() {
		return '
		class WP_MCP_AI_Page_Agent_Elementor_Widget extends \Elementor\Widget_Base {

			public function get_name() {
				return "nvoos_page_agent";
			}

			public function get_title() {
				return __( "NV oOS Page Agent", "nvoos-page-agent" );
			}

			public function get_icon() {
				return "eicon-ai";
			}

			public function get_categories() {
				return array( "nvoos" );
			}

			public function get_keywords() {
				return array( "ai", "agent", "page", "browser", "automation" );
			}

			protected function register_controls() {
				$this->start_controls_section(
					"content_section",
					array(
						"label" => __( "Page Agent Settings", "nvoos-page-agent" ),
						"tab"   => \Elementor\Controls_Manager::TAB_CONTENT,
					)
				);

				$this->add_control(
					"position",
					array(
						"label"   => __( "Panel Position", "nvoos-page-agent" ),
						"type"    => \Elementor\Controls_Manager::SELECT,
						"default" => "bottom-right",
						"options" => array(
							"bottom-right" => __( "Bottom Right", "nvoos-page-agent" ),
							"bottom-left"  => __( "Bottom Left", "nvoos-page-agent" ),
							"top-right"    => __( "Top Right", "nvoos-page-agent" ),
							"top-left"     => __( "Top Left", "nvoos-page-agent" ),
						),
					)
				);

				$this->add_control(
					"show_toggle",
					array(
						"label"        => __( "Show Toggle Button", "nvoos-page-agent" ),
						"type"         => \Elementor\Controls_Manager::SWITCHER,
						"label_on"     => __( "Yes", "nvoos-page-agent" ),
						"label_off"    => __( "No", "nvoos-page-agent" ),
						"return_value" => "true",
						"default"      => "true",
					)
				);

				$this->end_controls_section();
			}

			protected function render() {
				$settings = $this->get_settings_for_display();

				$atts = array(
					"position"    => $settings["position"],
					"show_toggle" => $settings["show_toggle"],
				);

				$widget = new WP_MCP_AI_Page_Agent_Widget();
				echo $widget->render_shortcode( $atts ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- Shortcode renders escaped output.
			}
		}';
	}
}
