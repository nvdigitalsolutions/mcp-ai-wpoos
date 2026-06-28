<?php
/**
 * Product Brand Taxonomy for Extended Cognition Vision Recognition
 *
 * Registers a non-public `mcp_product_brand` taxonomy attached to the
 * `ext_cog_session` CPT.  Site admins populate it with luxury/fashion/retail
 * brands (Paco Rabanne, Givenchy, Dior, etc.) as taxonomy terms.  The
 * Extended Cognition vision tools read this taxonomy as their zero-shot
 * classification label catalogue when the user does not supply explicit
 * labels at call time.
 *
 * @package   WP_MCP_AI_Pro
 * @since     1.8.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Product Brand Taxonomy registrar.
 *
 * @since 1.8.0
 */
class WP_MCP_AI_Product_Brand_Taxonomy {

	/**
	 * Taxonomy slug.
	 *
	 * @var string
	 */
	const TAXONOMY = 'mcp_product_brand';

	/**
	 * Register hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ), 15 );

		// Only seed defaults when the Extended Cognition toolkit is enabled.
		if ( wp_mcp_ai_ext_cog_is_enabled() ) {
			add_action( 'init', array( __CLASS__, 'seed_default_terms' ), 20 );
		}
	}

	/**
	 * Register the taxonomy.
	 *
	 * Non-public (no front-end query var, no UI for subscribers), but
	 * show_ui for admin so site owners can manage the catalogue.
	 *
	 * @return void
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Product Brands', 'Taxonomy General Name', 'mcp-ai-wpoos-pro' ),
			'singular_name'     => _x( 'Product Brand', 'Taxonomy Singular Name', 'mcp-ai-wpoos-pro' ),
			'search_items'      => __( 'Search Brands', 'mcp-ai-wpoos-pro' ),
			'all_items'         => __( 'All Brands', 'mcp-ai-wpoos-pro' ),
			'parent_item'       => __( 'Parent Brand', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon' => __( 'Parent Brand:', 'mcp-ai-wpoos-pro' ),
			'edit_item'         => __( 'Edit Brand', 'mcp-ai-wpoos-pro' ),
			'update_item'       => __( 'Update Brand', 'mcp-ai-wpoos-pro' ),
			'add_new_item'      => __( 'Add New Brand', 'mcp-ai-wpoos-pro' ),
			'new_item_name'     => __( 'New Brand Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'         => __( 'Product Brands', 'mcp-ai-wpoos-pro' ),
			'not_found'         => __( 'No brands found. Brands are used by the Extended Cognition visual recognition tools.', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => false,
			'public'            => false,
			'show_ui'           => true,
			'show_admin_column' => false,
			'show_in_nav_menus' => false,
			'show_tagcloud'     => false,
			'show_in_rest'      => true,
			'rest_base'         => 'mcp-product-brands',
			'capabilities'      => array(
				'manage_terms' => 'manage_options',
				'edit_terms'   => 'manage_options',
				'delete_terms' => 'manage_options',
				'assign_terms' => 'edit_posts',
			),
			'rewrite'           => false,
			'query_var'         => false,
		);

		register_taxonomy( self::TAXONOMY, array( 'mcp_ai_cog_session' ), $args );
	}

	/**
	 * Seed a curated default brand catalogue if the taxonomy is empty.
	 *
	 * Covers major luxury fashion, fragrance, accessories, and beauty brands.
	 * Terms are only inserted once; existing terms are never overwritten.
	 *
	 * @return void
	 */
	public static function seed_default_terms() {
		// Short-circuit early if terms already exist.
		$existing = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => 1,
				'fields'     => 'ids',
			)
		);

		if ( ! empty( $existing ) && ! is_wp_error( $existing ) ) {
			return;
		}

		$default_brands = array(
			// Fragrance / Beauty.
			'Paco Rabanne',
			'Givenchy',
			'Dior',
			'Chanel',
			'Gucci',
			'Yves Saint Laurent',
			'Hermès',
			'Tom Ford',
			'Versace',
			'Jean Paul Gaultier',
			'Lancôme',
			'Estée Lauder',
			'Clinique',
			'Jo Malone',
			'Calvin Klein',
			'Burberry',
			'Prada',
			'Giorgio Armani',
			'Valentino',
			'Dolce & Gabbana',
			// Luxury Fashion.
			'Louis Vuitton',
			'Balenciaga',
			'Fendi',
			'Celine',
			'Loewe',
			'Bottega Veneta',
			'Saint Laurent',
			'Alexander McQueen',
			'Balmain',
			'Off-White',
			// Accessories / Eyewear.
			'Ray-Ban',
			'Cartier',
			'Tiffany & Co.',
			'Bvlgari',
			// Sportswear / Premium.
			'Nike',
			'Adidas',
			'New Balance',
			// Watches.
			'Rolex',
			'Omega',
			'Tag Heuer',
			// Cosmetics.
			"L'Oréal",
			'MAC',
			'Fenty Beauty',
		);

		foreach ( $default_brands as $brand ) {
			$existing_term = term_exists( $brand, self::TAXONOMY );

			if ( ! $existing_term ) {
				wp_insert_term( $brand, self::TAXONOMY );
			}
		}
	}

	/**
	 * Get all brand names as a flat array for use as zero-shot classification labels.
	 *
	 * @param int $limit Maximum number of terms to return (0 = all).
	 * @return string[]
	 */
	public static function get_brand_labels( $limit = 0 ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'number'     => $limit > 0 ? absint( $limit ) : 0,
				'fields'     => 'names',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'sanitize_text_field', $terms ) );
	}

	/**
	 * Get brand names filtered by a search string (prefix matching).
	 *
	 * @param string $search Search string.
	 * @param int    $limit  Maximum results.
	 * @return string[]
	 */
	public static function search_brand_labels( $search, $limit = 20 ) {
		$terms = get_terms(
			array(
				'taxonomy'   => self::TAXONOMY,
				'hide_empty' => false,
				'name__like' => sanitize_text_field( $search ),
				'number'     => absint( $limit ),
				'fields'     => 'names',
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		return array_values( array_map( 'sanitize_text_field', $terms ) );
	}
}
