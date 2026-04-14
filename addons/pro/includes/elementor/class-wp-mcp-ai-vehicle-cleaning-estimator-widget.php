<?php
/**
 * Vehicle Cleaning Estimator Elementor Widget
 *
 * PWA-style interactive car-wash / detailing quote widget.
 * Accepts vehicle photos, a package selection, optional add-ons,
 * and a freeform message, then returns a full line-item AI estimate.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Vehicle Cleaning Estimator Widget Class.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Vehicle_Cleaning_Estimator_Widget extends \Elementor\Widget_Base {

	/**
	 * Get widget name.
	 *
	 * @since 1.1.0
	 * @return string Widget name.
	 */
	public function get_name() {
		return 'wp_mcp_ai_vehicle_cleaning_estimator';
	}

	/**
	 * Get widget title.
	 *
	 * @since 1.1.0
	 * @return string Widget title.
	 */
	public function get_title() {
		return __( 'Vehicle Cleaning Estimator', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get widget icon.
	 *
	 * @since 1.1.0
	 * @return string Widget icon.
	 */
	public function get_icon() {
		return 'eicon-price-list';
	}

	/**
	 * Get widget categories.
	 *
	 * @since 1.1.0
	 * @return array Widget categories.
	 */
	public function get_categories() {
		return array( 'mcp-ai-toolkits' );
	}

	/**
	 * Get widget keywords.
	 *
	 * @since 1.1.0
	 * @return array Widget keywords.
	 */
	public function get_keywords() {
		return array( 'vehicle', 'car wash', 'cleaning', 'estimate', 'quote', 'detailing', 'ai', 'mcp' );
	}

	/**
	 * Register widget controls.
	 *
	 * @since 1.1.0
	 */
	protected function register_controls() {

		// ── Content Section ──────────────────────────────────────────────────
		$this->start_controls_section(
			'content_section',
			array(
				'label' => __( 'Content', 'mcp-ai-wpoos-pro' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'assistant_id',
			array(
				'label'       => __( 'Assistant ID', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'e.g. 123', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'ID of the NV oOS assistant configured for vehicle cleaning estimates. Leave empty to use the site default.', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'show_package_selector',
			array(
				'label'        => __( 'Show Package Selector', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_addon_selector',
			array(
				'label'        => __( 'Show Add-ons Selector', 'mcp-ai-wpoos-pro' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'mcp-ai-wpoos-pro' ),
				'label_off'    => __( 'No', 'mcp-ai-wpoos-pro' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'condition'    => array( 'show_package_selector' => 'yes' ),
			)
		);

		$this->add_control(
			'cta_label',
			array(
				'label'       => __( 'CTA Button Label', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'placeholder' => __( 'Get My Estimate', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Label for the submit / estimate button.', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->add_control(
			'placeholder_text',
			array(
				'label'       => __( 'Drop-zone Tip Text', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::TEXTAREA,
				'rows'        => 3,
				'placeholder' => __( 'Drag vehicle photos here or tap to upload', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Helper text shown beneath the image drop zone.', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->end_controls_section();

		// ── Pricing Section ──────────────────────────────────────────────────
		$this->start_controls_section(
			'pricing_section',
			array(
				'label' => __( 'Pricing', 'mcp-ai-wpoos-pro' ),
				'tab'   => \Elementor\Controls_Manager::TAB_CONTENT,
			)
		);

		$this->add_control(
			'currency',
			array(
				'label'   => __( 'Currency', 'mcp-ai-wpoos-pro' ),
				'type'    => \Elementor\Controls_Manager::SELECT,
				'default' => 'CAD',
				'options' => array(
					'CAD' => __( 'Canadian Dollar (CAD)', 'mcp-ai-wpoos-pro' ),
					'USD' => __( 'US Dollar (USD)', 'mcp-ai-wpoos-pro' ),
					'GBP' => __( 'British Pound (GBP)', 'mcp-ai-wpoos-pro' ),
					'EUR' => __( 'Euro (EUR)', 'mcp-ai-wpoos-pro' ),
					'AUD' => __( 'Australian Dollar (AUD)', 'mcp-ai-wpoos-pro' ),
				),
			)
		);

		$this->add_control(
			'tax_rate',
			array(
				'label'       => __( 'Tax Rate (%)', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::NUMBER,
				'default'     => 0,
				'min'         => 0,
				'max'         => 50,
				'step'        => 0.5,
				'description' => __( 'Enter the tax percentage to apply to the estimate total (e.g. 13 for 13% HST).', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->end_controls_section();

		// ── Style Section ────────────────────────────────────────────────────
		$this->start_controls_section(
			'style_section',
			array(
				'label' => __( 'Style', 'mcp-ai-wpoos-pro' ),
				'tab'   => \Elementor\Controls_Manager::TAB_STYLE,
			)
		);

		$this->add_control(
			'primary_color',
			array(
				'label'       => __( 'Primary / Accent Colour', 'mcp-ai-wpoos-pro' ),
				'type'        => \Elementor\Controls_Manager::COLOR,
				'description' => __( 'Used for the header, buttons, selected states, and total price.', 'mcp-ai-wpoos-pro' ),
			)
		);

		$this->end_controls_section();
	}

	/**
	 * Render widget output on the frontend.
	 *
	 * Delegates to the [mcp_vehicle_cleaning_estimator] shortcode so that
	 * the Gutenberg block, Elementor widget, and plain shortcode all share
	 * the same rendering logic.
	 *
	 * @since 1.1.0
	 */
	protected function render() {
		$settings = $this->get_settings_for_display();

		$attributes = array();

		if ( ! empty( $settings['assistant_id'] ) ) {
			$attributes[] = 'assistant_id="' . esc_attr( $settings['assistant_id'] ) . '"';
		}

		if ( ! empty( $settings['primary_color'] ) ) {
			$attributes[] = 'primary_color="' . esc_attr( $settings['primary_color'] ) . '"';
		}

		if ( isset( $settings['show_package_selector'] ) && 'yes' !== $settings['show_package_selector'] ) {
			$attributes[] = 'show_package_selector="no"';
		}

		if ( isset( $settings['show_addon_selector'] ) && 'yes' !== $settings['show_addon_selector'] ) {
			$attributes[] = 'show_addon_selector="no"';
		}

		if ( ! empty( $settings['currency'] ) ) {
			$attributes[] = 'currency="' . esc_attr( $settings['currency'] ) . '"';
		}

		if ( ! empty( $settings['tax_rate'] ) ) {
			$attributes[] = 'tax_rate="' . floatval( $settings['tax_rate'] ) . '"';
		}

		if ( ! empty( $settings['placeholder_text'] ) ) {
			$attributes[] = 'placeholder_text="' . esc_attr( $settings['placeholder_text'] ) . '"';
		}

		if ( ! empty( $settings['cta_label'] ) ) {
			$attributes[] = 'cta_label="' . esc_attr( $settings['cta_label'] ) . '"';
		}

		$shortcode = '[mcp_vehicle_cleaning_estimator ' . implode( ' ', $attributes ) . ']';
		echo do_shortcode( $shortcode ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- escaping is handled within render_vehicle_cleaning_estimator() in class-wp-mcp-ai-pro-toolkit-shortcodes.php.
	}
}
