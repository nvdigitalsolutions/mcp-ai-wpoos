<?php
/**
 * WP_MCP_AI_Regulatory_Registration_CPT (ecosystem port - Wave F2, regulatory-registration data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-regulatory-registration-cpt.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants, so no path swaps.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Regulatory_Registration
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Regulatory Registration custom post types.
 */
class WP_MCP_AI_Regulatory_Registration_CPT {
	/**
	 * Product post type slug.
	 *
	 * @var string
	 */
	const PRODUCT_POST_TYPE = 'mcp_ai_reg_product';

	/**
	 * Registration post type slug.
	 *
	 * @var string
	 */
	const REGISTRATION_POST_TYPE = 'mcp_ai_registration';

	/**
	 * Document post type slug.
	 *
	 * @var string
	 */
	const DOCUMENT_POST_TYPE = 'mcp_ai_reg_document';

	/**
	 * Country post type slug.
	 *
	 * @var string
	 */
	const COUNTRY_POST_TYPE = 'mcp_ai_reg_country';

	/**
	 * Requirement post type slug.
	 *
	 * @var string
	 */
	const REQUIREMENT_POST_TYPE = 'mcp_ai_requirement';

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if regulatory registration toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_regulatory_registration_toolkit'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_types' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomies' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
	}

	/**
	 * Show admin notice when regulatory registration toolkit is disabled.
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_reg_page = in_array(
			$post_type,
			array(
				self::PRODUCT_POST_TYPE,
				self::REGISTRATION_POST_TYPE,
				self::DOCUMENT_POST_TYPE,
				self::COUNTRY_POST_TYPE,
				self::REQUIREMENT_POST_TYPE,
			),
			true
		);

		if ( ! $is_reg_page && ! in_array( $screen->post_type, array( self::PRODUCT_POST_TYPE, self::REGISTRATION_POST_TYPE, self::DOCUMENT_POST_TYPE, self::COUNTRY_POST_TYPE, self::REQUIREMENT_POST_TYPE ), true ) ) {
			return;
		}

		$is_base    = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();
		$settings   = get_option( 'wp_mcp_ai_settings', array() );
		$is_enabled = ! empty( $settings['enable_regulatory_registration_toolkit'] );

		if ( $is_base && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			echo '<div class="notice notice-warning"><p>';
			echo esc_html__( 'Regulatory Registration Toolkit is only available in the Full Version of WP MCP AI.', 'nvoos-content-graph-pro' );
			echo '</p></div>';
		} elseif ( ! $is_enabled ) {
			echo '<div class="notice notice-warning"><p>';
			echo wp_kses_post(
				sprintf(
					// translators: %s: Settings page URL.
					__( 'Regulatory Registration Toolkit is currently disabled. <a href="%s">Enable it in settings</a>.', 'nvoos-content-graph-pro' ),
					esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings' ) )
				)
			);
			echo '</p></div>';
		}
	}

	/**
	 * Show info notice on regulatory registration pages.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only show on product management pages.
		if ( self::PRODUCT_POST_TYPE !== $screen->post_type ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		if ( isset( $_GET['post'] ) ) {
			return; // Don't show on edit screen.
		}

		echo '<div class="notice notice-info"><p>';
		echo esc_html__( 'Manage product registrations across multiple countries and regulatory authorities. Track documents, monitor compliance, and manage submissions.', 'nvoos-content-graph-pro' );
		echo '</p></div>';
	}

	/**
	 * Register custom post types.
	 */
	public static function register_post_types() {
		// Register Product CPT.
		register_post_type(
			self::PRODUCT_POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Products', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Product', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Regulatory Registration', 'admin menu', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add Product', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Product', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Product', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Product', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Product', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Products', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No products found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No products found in Trash', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Products', 'nvoos-content-graph-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => true,
				'menu_icon'       => 'dashicons-shield-alt',
				'menu_position'   => 31,
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'show_in_rest'    => true,
			)
		);

		// Register Registration CPT.
		register_post_type(
			self::REGISTRATION_POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Registrations', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Registration', 'nvoos-content-graph-pro' ),
					'menu_name'          => __( 'Registrations', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add Registration', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Registration', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Registration', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Registration', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Registration', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Registrations', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No registrations found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No registrations found in Trash', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Registrations', 'nvoos-content-graph-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::PRODUCT_POST_TYPE,
				'menu_icon'       => 'dashicons-clipboard',
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'show_in_rest'    => true,
			)
		);

		// Register Document CPT.
		register_post_type(
			self::DOCUMENT_POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Documents', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Document', 'nvoos-content-graph-pro' ),
					'menu_name'          => __( 'Documents', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add Document', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Document', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Document', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Document', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Document', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Documents', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No documents found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No documents found in Trash', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Documents', 'nvoos-content-graph-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::PRODUCT_POST_TYPE,
				'menu_icon'       => 'dashicons-media-document',
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'show_in_rest'    => true,
			)
		);

		// Register Country CPT.
		register_post_type(
			self::COUNTRY_POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Countries', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Country', 'nvoos-content-graph-pro' ),
					'menu_name'          => __( 'Countries', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add Country', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Country', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Country', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Country', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Country', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Countries', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No countries found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No countries found in Trash', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Countries', 'nvoos-content-graph-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::PRODUCT_POST_TYPE,
				'menu_icon'       => 'dashicons-admin-site',
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'show_in_rest'    => true,
			)
		);

		// Register Requirement CPT.
		register_post_type(
			self::REQUIREMENT_POST_TYPE,
			array(
				'labels'          => array(
					'name'               => __( 'Requirements', 'nvoos-content-graph-pro' ),
					'singular_name'      => __( 'Requirement', 'nvoos-content-graph-pro' ),
					'menu_name'          => __( 'Requirements', 'nvoos-content-graph-pro' ),
					'add_new'            => __( 'Add Requirement', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Requirement', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Requirement', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Requirement', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Requirement', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Requirements', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No requirements found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No requirements found in Trash', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Requirements', 'nvoos-content-graph-pro' ),
				),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'edit.php?post_type=' . self::PRODUCT_POST_TYPE,
				'menu_icon'       => 'dashicons-list-view',
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'capability_type' => 'post',
				'map_meta_cap'    => true,
				'show_in_rest'    => true,
			)
		);
	}

	/**
	 * Register taxonomies.
	 */
	public static function register_taxonomies() {
		// Product Category taxonomy (skincare, haircare, makeup, perfumes).
		register_taxonomy(
			'mcp_ai_reg_category',
			array( self::PRODUCT_POST_TYPE ),
			array(
				'labels'            => array(
					'name'              => __( 'Product Categories', 'nvoos-content-graph-pro' ),
					'singular_name'     => __( 'Product Category', 'nvoos-content-graph-pro' ),
					'search_items'      => __( 'Search Categories', 'nvoos-content-graph-pro' ),
					'all_items'         => __( 'All Categories', 'nvoos-content-graph-pro' ),
					'parent_item'       => __( 'Parent Category', 'nvoos-content-graph-pro' ),
					'parent_item_colon' => __( 'Parent Category:', 'nvoos-content-graph-pro' ),
					'edit_item'         => __( 'Edit Category', 'nvoos-content-graph-pro' ),
					'update_item'       => __( 'Update Category', 'nvoos-content-graph-pro' ),
					'add_new_item'      => __( 'Add New Category', 'nvoos-content-graph-pro' ),
					'new_item_name'     => __( 'New Category Name', 'nvoos-content-graph-pro' ),
					'menu_name'         => __( 'Categories', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);

		// Registration Status taxonomy.
		register_taxonomy(
			'mcp_ai_reg_status',
			array( self::REGISTRATION_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Registration Statuses', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Registration Status', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Statuses', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Statuses', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Status', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Status', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Status', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Status Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Statuses', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);

		// Document Type taxonomy.
		register_taxonomy(
			'mcp_ai_doc_type',
			array( self::DOCUMENT_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Document Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Document Type', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Types', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Types', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Type', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Type', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Type', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Type Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);

		// Brand taxonomy.
		register_taxonomy(
			'mcp_ai_reg_brand',
			array( self::PRODUCT_POST_TYPE ),
			array(
				'labels'            => array(
					'name'          => __( 'Brands', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Brand', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Brands', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Brands', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Brand', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Brand', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Brand', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Brand Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Brands', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Get all product categories.
	 *
	 * @return array Array of category terms.
	 */
	public static function get_product_categories() {
		return get_terms(
			array(
				'taxonomy'   => 'mcp_ai_reg_category',
				'hide_empty' => false,
			)
		);
	}

	/**
	 * Get all registration statuses.
	 *
	 * @return array Array of status terms.
	 */
	public static function get_registration_statuses() {
		return get_terms(
			array(
				'taxonomy'   => 'mcp_ai_reg_status',
				'hide_empty' => false,
			)
		);
	}

	/**
	 * Get all document types.
	 *
	 * @return array Array of document type terms.
	 */
	public static function get_document_types() {
		return get_terms(
			array(
				'taxonomy'   => 'mcp_ai_doc_type',
				'hide_empty' => false,
			)
		);
	}

	/**
	 * Get all brands.
	 *
	 * @return array Array of brand terms.
	 */
	public static function get_brands() {
		return get_terms(
			array(
				'taxonomy'   => 'mcp_ai_reg_brand',
				'hide_empty' => false,
			)
		);
	}
}

// Initialize the CPT.
WP_MCP_AI_Regulatory_Registration_CPT::init();
