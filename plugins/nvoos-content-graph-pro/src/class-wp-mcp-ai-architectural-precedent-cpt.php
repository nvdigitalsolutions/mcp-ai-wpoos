<?php
/**
 * Architectural Precedent CPT (ecosystem port - Wave F2, architectural-design data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/` directory for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the
 * plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` swaps with the `src/` root.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architectural_Design
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Architectural Precedent custom post type.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Architectural_Precedent_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_arch_prec';

	/**
	 * Country taxonomy slug.
	 */
	const TAX_COUNTRY = 'mcp_ai_arch_prec_country';

	/**
	 * Building-type taxonomy slug.
	 */
	const TAX_BUILDING_TYPE = 'mcp_ai_arch_prec_btype';

	/**
	 * Initialize the class.
	 *
	 * @since 1.5.0
	 */
	public static function init() {
		// Only available in Full / Pro version.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Only initialize if architectural design toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_architectural_design_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'init', array( __CLASS__, 'register_meta' ) );
	}

	/**
	 * Register the precedent custom post type.
	 *
	 * @return void
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'               => _x( 'Precedents', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Precedent', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Precedents', 'admin menu', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Precedent', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Precedent', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Precedent', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'All Precedents', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Precedents', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No precedents found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No precedents found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=mcp_ai_arch_proj',
				'show_in_rest'    => true,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'excerpt', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
			)
		);
	}

	/**
	 * Register precedent post-meta keys.
	 *
	 * @return void
	 */
	public static function register_meta() {
		$string_meta = array(
			'_arch_prec_country_code'          => 'ISO 3166-1 alpha-2 country code (e.g. LK, JM, US).',
			'_arch_prec_climate_zone'          => 'ASHRAE / Köppen climate zone classification.',
			'_arch_prec_sustainability_rating' => 'Sustainability rating (e.g. EDGE Certified, LEED Silver).',
			'_arch_prec_references_url'        => 'External references URL.',
			'_arch_prec_architect'             => 'Lead architect or design firm.',
		);
		foreach ( $string_meta as $key => $description ) {
			$sanitize = ( '_arch_prec_references_url' === $key ) ? 'esc_url_raw' : 'sanitize_text_field';
			register_post_meta(
				self::POST_TYPE,
				$key,
				array(
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => $sanitize,
				)
			);
		}

		register_post_meta(
			self::POST_TYPE,
			'_arch_prec_building_type',
			array(
				'type'              => 'string',
				'description'       => 'Building type (residential, commercial, healthcare, education, mixed_use, civic, industrial, hospitality).',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_key',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_arch_prec_year_completed',
			array(
				'type'              => 'integer',
				'description'       => 'Year the project was completed (or expected completion).',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_arch_prec_area_m2',
			array(
				'type'              => 'number',
				'description'       => 'Gross floor area in square metres.',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => array( __CLASS__, 'sanitize_float' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_arch_prec_key_features',
			array(
				'type'              => 'array',
				'description'       => 'Key design features as a JSON array of strings.',
				'single'            => true,
				'show_in_rest'      => array(
					'schema' => array(
						'type'  => 'array',
						'items' => array( 'type' => 'string' ),
					),
				),
				'sanitize_callback' => array( __CLASS__, 'sanitize_string_array' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_arch_prec_embedding',
			array(
				'type'              => 'array',
				'description'       => 'Cached OpenAI embedding vector for semantic search (regenerated on save).',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => array( __CLASS__, 'sanitize_float_array' ),
			)
		);

		register_post_meta(
			self::POST_TYPE,
			'_arch_prec_embedding_model',
			array(
				'type'              => 'string',
				'description'       => 'Embedding model used to generate the cached vector.',
				'single'            => true,
				'show_in_rest'      => false,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);
	}

	/**
	 * Register country and building-type taxonomies.
	 *
	 * @return void
	 */
	public static function register_taxonomies() {
		register_taxonomy(
			self::TAX_COUNTRY,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Countries', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Country', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);

		register_taxonomy(
			self::TAX_BUILDING_TYPE,
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Building Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Building Type', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => false,
				'rewrite'           => false,
			)
		);

		// Seed default country and building-type terms.
		$countries = array(
			'lk' => __( 'Sri Lanka', 'nvoos-content-graph-pro' ),
			'jm' => __( 'Jamaica', 'nvoos-content-graph-pro' ),
			'us' => __( 'United States', 'nvoos-content-graph-pro' ),
		);
		foreach ( $countries as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX_COUNTRY ) ) {
				wp_insert_term( $name, self::TAX_COUNTRY, array( 'slug' => $slug ) );
			}
		}

		$btypes = array(
			'residential' => __( 'Residential', 'nvoos-content-graph-pro' ),
			'commercial'  => __( 'Commercial', 'nvoos-content-graph-pro' ),
			'healthcare'  => __( 'Healthcare', 'nvoos-content-graph-pro' ),
			'education'   => __( 'Education', 'nvoos-content-graph-pro' ),
			'mixed-use'   => __( 'Mixed-Use', 'nvoos-content-graph-pro' ),
			'civic'       => __( 'Civic', 'nvoos-content-graph-pro' ),
			'industrial'  => __( 'Industrial', 'nvoos-content-graph-pro' ),
			'hospitality' => __( 'Hospitality', 'nvoos-content-graph-pro' ),
		);
		foreach ( $btypes as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAX_BUILDING_TYPE ) ) {
				wp_insert_term( $name, self::TAX_BUILDING_TYPE, array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Sanitize a float-or-numeric-string value.
	 *
	 * @param mixed $value Raw value.
	 * @return float
	 */
	public static function sanitize_float( $value ) {
		return is_numeric( $value ) ? (float) $value : 0.0;
	}

	/**
	 * Sanitize an array of strings.
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize_string_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			$out[] = sanitize_text_field( (string) $v );
		}
		return $out;
	}

	/**
	 * Sanitize an array of floats (embedding vector).
	 *
	 * @param mixed $value Raw value.
	 * @return array
	 */
	public static function sanitize_float_array( $value ) {
		if ( ! is_array( $value ) ) {
			return array();
		}
		$out = array();
		foreach ( $value as $v ) {
			$out[] = is_numeric( $v ) ? (float) $v : 0.0;
		}
		return $out;
	}
}
