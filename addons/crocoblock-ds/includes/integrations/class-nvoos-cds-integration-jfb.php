<?php
/**
 * NV oOS Crocoblock DS — JetFormBuilder Integration
 *
 * Injects .cds-form CSS class into form containers and applies CDS token
 * values as default styles for JetFormBuilder fields.
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetFormBuilder integration layer.
 *
 * Hooks:
 *   - jet-form-builder/form/container-classes  → inject .cds-form
 *   - jet-form-builder/render/field-template    → wrap fields with CDS classes
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Integration_JFB {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks if JetFormBuilder is active.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		if ( ! class_exists( 'Jet_Form_Builder' ) ) {
			return;
		}

		self::$registered = true;

		add_filter(
			'jet-form-builder/form/container-classes',
			array( __CLASS__, 'add_form_class' ),
			10,
			2
		);

		// Output inline styles that bridge CDS tokens to JFB field defaults.
		add_action( 'wp_head', array( __CLASS__, 'output_form_dynamic_styles' ), 99 );

		// Ensure CDS component CSS is enqueued when JFB is present.
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Crocoblock_DS_Assets', 'enqueue_components' ), 25 );
	}

	/**
	 * Inject .cds-form into the form container's CSS class list.
	 *
	 * @param array  $classes Existing CSS classes.
	 * @param object $form    JetFormBuilder form instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_form_class( $classes, $form ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'cds-form', $classes, true ) ) {
			$classes[] = 'cds-form';
		}

		return $classes;
	}

	/**
	 * Output inline styles that apply CDS token values as JFB form field defaults.
	 *
	 * This runs late in wp_head to ensure CDS tokens are available and to
	 * provide low-specificity defaults that the component CSS can override.
	 *
	 * @return void
	 */
	public static function output_form_dynamic_styles() {
		$registry = NV_oOS_Crocoblock_DS_Plugin::token_registry();
		$values   = $registry->get_values_map();

		$css  = '<style id="cds-jfb-styles">';
		$css .= '.cds-form .jet-form-builder input,';
		$css .= '.cds-form .jet-form-builder select,';
		$css .= '.cds-form .jet-form-builder textarea{';
		$css .= 'font-family:' . esc_html( isset( $values['font_family'] ) ? $values['font_family'] : 'inherit' ) . ';';
		$css .= 'font-size:' . esc_html( isset( $values['font_size_base'] ) ? $values['font_size_base'] : '16px' ) . ';';
		$css .= '}';
		$css .= '.cds-form .jet-form-builder__label,';
		$css .= '.cds-form .jet-form-builder label{';
		$css .= 'font-family:' . esc_html( isset( $values['font_family'] ) ? $values['font_family'] : 'inherit' ) . ';';
		$css .= 'font-size:' . esc_html( isset( $values['font_size_xs'] ) ? $values['font_size_xs'] : '12px' ) . ';';
		$css .= '}';
		$css .= '.cds-form .jet-form-builder button,';
		$css .= '.cds-form .jet-form-builder .jet-form-builder__action-button{';
		$css .= 'font-family:' . esc_html( isset( $values['font_family'] ) ? $values['font_family'] : 'inherit' ) . ';';
		$css .= '}';
		$css .= '</style>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — CSS values are esc_html'd above.
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
