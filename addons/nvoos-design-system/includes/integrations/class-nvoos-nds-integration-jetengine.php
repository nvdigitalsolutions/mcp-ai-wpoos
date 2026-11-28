<?php
/**
 * NV oOS Design System — JetEngine Integration
 *
 * Injects .nds-card and .nds-grid classes into listing grids and items,
 * and registers NDS listing templates via JetEngine's native template
 * import mechanism.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * JetEngine integration layer.
 *
 * Hooks:
 *   - jet-engine/listing/grid/wrapper-classes   → inject .nds-grid
 *   - jet-engine/listing/grid/item-classes        → inject .nds-card
 *   - jet-engine/listing/templates                → register NDS templates
 *
 * When legacy aliases are enabled, the previous `.cds-*` classes are also
 * injected so existing custom CSS keeps matching (transition release).
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_Integration_JetEngine {

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

		// Register NDS listing templates in JetEngine's template selector.
		add_filter(
			'jet-engine/listing/templates',
			array( __CLASS__, 'register_templates' )
		);

		// Enqueue NDS listing token bridge CSS through the stylesheet pipeline.
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_listing_styles' ), 26 );

		// Ensure NDS component CSS is enqueued when JetEngine listings are present.
		add_action( 'wp_enqueue_scripts', array( 'NV_oOS_Design_System_Assets', 'enqueue_components' ), 25 );
	}

	/**
	 * Inject .nds-grid into the listing grid wrapper classes.
	 *
	 * @param array  $classes Existing CSS classes.
	 * @param object $listing JetEngine listing instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_grid_class( $classes, $listing ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'nds-grid', $classes, true ) ) {
			$classes[] = 'nds-grid';
		}

		if ( NV_oOS_Design_System_Plugin::is_legacy_aliases_enabled() && ! in_array( 'cds-grid', $classes, true ) ) {
			$classes[] = 'cds-grid';
		}

		return $classes;
	}

	/**
	 * Inject .nds-card into each listing item's class list.
	 *
	 * @param array  $classes Existing CSS classes.
	 * @param object $listing JetEngine listing instance.
	 * @return array Modified CSS classes.
	 */
	public static function add_card_class( $classes, $listing ) {
		if ( ! is_array( $classes ) ) {
			$classes = array();
		}

		if ( ! in_array( 'nds-card', $classes, true ) ) {
			$classes[] = 'nds-card';
		}

		if ( NV_oOS_Design_System_Plugin::is_legacy_aliases_enabled() && ! in_array( 'cds-card', $classes, true ) ) {
			$classes[] = 'cds-card';
		}

		return $classes;
	}

	/**
	 * Register NDS listing templates in JetEngine's template selector.
	 *
	 * @param array $templates Existing template listings.
	 * @return array Modified template listings.
	 */
	public static function register_templates( $templates ) {
		if ( ! is_array( $templates ) ) {
			$templates = array();
		}

		// NDS Product Card template.
		$templates[] = array(
			'id'          => 'nds-product-card',
			'name'        => __( 'NDS — Product Card', 'nvoos-design-system' ),
			'source'      => 'nds',
			'description' => __( 'Product listing card with NDS token-driven styling. Includes image, category label, product name, location, quantity badge, and price.', 'nvoos-design-system' ),
		);

		// NDS Compact Row template.
		$templates[] = array(
			'id'          => 'nds-compact-row',
			'name'        => __( 'NDS — Compact Row', 'nvoos-design-system' ),
			'source'      => 'nds',
			'description' => __( 'Compact inline row for table-style listings. All styling driven by NDS tokens.', 'nvoos-design-system' ),
		);

		return $templates;
	}

	/**
	 * Enqueue inline styles that bridge NDS tokens to JetEngine's listing grid.
	 *
	 * @return void
	 */
	public static function enqueue_listing_styles() {
		$registry = NV_oOS_Design_System_Plugin::token_registry();
		$values   = $registry->get_values_map();

		$css  = '.nds-grid{';
		$css .= 'gap:' . ( isset( $values['gap_grid'] ) ? $values['gap_grid'] : '20px' ) . ';';
		$css .= '}';
		$css .= '.nds-card .jet-listing-dynamic-image img{';
		$css .= 'height:' . ( isset( $values['card_image_height'] ) ? $values['card_image_height'] : '200px' ) . ';';
		$css .= 'object-fit:cover;';
		$css .= '}';

		NV_oOS_Design_System_Assets::register();
		wp_enqueue_style( 'nvoos-nds-components' );
		wp_add_inline_style( 'nvoos-nds-components', $css );
	}
}
