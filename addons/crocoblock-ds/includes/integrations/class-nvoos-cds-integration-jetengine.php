<?php
/**
 * NV oOS Crocoblock DS — JetEngine Integration
 *
 * Injects .cds-card and .cds-grid classes into listing grids and items,
 * and registers CDS listing templates via JetEngine's native template
 * import mechanism.
 *
 * @package NV_oOS_Crocoblock_DS
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine integration layer.
 *
 * Hooks:
 *   - jet-engine/listing/grid/wrapper-classes   → inject .cds-grid
 *   - jet-engine/listing/grid/item-classes        → inject .cds-card
 *   - jet-engine/listing/grid/columns              → respect CDS grid values
 *
 * Also registers CDS listing templates via a filter that adds them to the
 * JetEngine template library selector.
 *
 * @since 0.1.0
 */
class NV_oOS_Crocoblock_DS_Integration_JetEngine {

	/**
	 * Whether hooks have been registered.
	 *
	 * @var bool
	 */
	private static $registered = false;

	/**
	 * Register hooks if JetEngine is active.
	 *
	 * @return void
	 */
	public static function init() {
		if ( self::$registered ) {
			return;
		}

		if ( ! class_exists( 'Jet_Engine' ) ) {
			return;
		}

		self::$registered = true;

		add_filter(
			'jet-engine/listing/grid/wrapper-classes',
			array( __CLASS__, 'add_grid_class' ),
			10,
			2
		);

		add_filter(
			'jet-engine/listing/grid/item-classes',
			array( __CLASS__, 'add_card_class' ),
			10,
			2
		);

		// Register CDS listing templates in JetEngine's template selector.
		add_filter(
			'jet-engine/listing/templates',
			array( __CLASS__, 'register_templates' )
		);

		// Inline CDS token values for JetEngine's dynamic CSS.
		add_action( 'wp_head', array( __CLASS__, 'output_listing_dynamic_styles' ), 99 );

		// Ensure CDS component CSS is enqueued when JetEngine listings are present.
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Crocoblock_DS_Assets', 'enqueue_components' ), 25 );
	}

	/**
	 * Inject .cds-grid into the listing grid wrapper classes.
	 *
	 * @param array  $classes Existing CSS classes.
	 * @param object $listing JetEngine listing instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_grid_class( $classes, $listing ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'cds-grid', $classes, true ) ) {
			$classes[] = 'cds-grid';
		}

		return $classes;
	}

	/**
	 * Inject .cds-card into each listing item's class list.
	 *
	 * @param array  $classes Existing CSS classes.
	 * @param object $listing JetEngine listing instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_card_class( $classes, $listing ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'cds-card', $classes, true ) ) {
			$classes[] = 'cds-card';
		}

		return $classes;
	}

	/**
	 * Register CDS listing templates in JetEngine's template selector.
	 *
	 * @param array $templates Existing template listings.
	 * @return array Modified template listings.
	 */
	public static function register_templates( $templates ) {
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		// CDS Product Card template.
		$templates[] = array(
			'id'          => 'cds-product-card',
			'name'        => __( 'CDS — Product Card', 'nvoos-crocoblock-ds' ),
			'source'      => 'cds',
			'description' => __( 'Product listing card with CDS token-driven styling. Includes image, category label, product name, location, quantity badge, and price.', 'nvoos-crocoblock-ds' ),
		);

		// CDS Compact Row template.
		$templates[] = array(
			'id'          => 'cds-compact-row',
			'name'        => __( 'CDS — Compact Row', 'nvoos-crocoblock-ds' ),
			'source'      => 'cds',
			'description' => __( 'Compact inline row for table-style listings. All styling driven by CDS tokens.', 'nvoos-crocoblock-ds' ),
		);

		return $templates;
	}

	/**
	 * Output inline styles that bridge CDS tokens to JetEngine's
	 * dynamic listing grid CSS.
	 *
	 * @return void
	 */
	public static function output_listing_dynamic_styles() {
		$registry = NV_oOS_Crocoblock_DS_Plugin::token_registry();
		$values   = $registry->get_values_map();

		$css  = '<style id="cds-jetengine-styles">';
		$css .= '.cds-grid{';
		$css .= 'gap:' . esc_html( isset( $values['gap_grid'] ) ? $values['gap_grid'] : '20px' ) . ';';
		$css .= '}';
		$css .= '.cds-card .jet-listing-dynamic-image img{';
		$css .= 'height:' . esc_html( isset( $values['card_image_height'] ) ? $values['card_image_height'] : '200px' ) . ';';
		$css .= 'object-fit:cover;';
		$css .= '}';
		$css .= '</style>';

		// phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped — CSS values are esc_html'd above.
		echo $css; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
	}
}
