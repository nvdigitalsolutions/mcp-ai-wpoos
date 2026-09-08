<?php
/**
 * Calendar Booking data layer (ecosystem port — Wave F2, calendar-booking data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/calendar-booking/class-wp-mcp-ai-service-cpt.php` for the standalone `nvoos-content-graph-pro`
 * addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs — the addon's autoloader skips its copy when
 * `NVOOS_CONTENT_GRAPH_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; the metabox requires resolve from the
 * addon's `src/metaboxes/` copies (same batch).
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);


if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Service custom post type.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Service_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_service';

	/**
	 * Taxonomy slug for service categories.
	 *
	 * @var string
	 */
	const TAXONOMY = 'mcp_service_category';

	/**
	 * Metabox instances.
	 *
	 * @var array
	 */
	protected static $metaboxes = array();

	/**
	 * Initialize the class.
	 *
	 * @since 2.6.0
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'NVOOS_CONTENT_GRAPH_PRO_VERSION' ) ) {
			return;
		}

		// Only initialize if calendar booking toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_service_meta' ), 5, 2 );

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Register the Service post type.
	 *
	 * @since 2.6.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Services', 'Post Type General Name', 'nvoos-content-graph-pro' ),
			'singular_name'         => _x( 'Service', 'Post Type Singular Name', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Services', 'nvoos-content-graph-pro' ),
			'name_admin_bar'        => __( 'Service', 'nvoos-content-graph-pro' ),
			'archives'              => __( 'Service Archives', 'nvoos-content-graph-pro' ),
			'attributes'            => __( 'Service Attributes', 'nvoos-content-graph-pro' ),
			'parent_item_colon'     => __( 'Parent Service:', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Services', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Service', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add New', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Service', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Service', 'nvoos-content-graph-pro' ),
			'update_item'           => __( 'Update Service', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Service', 'nvoos-content-graph-pro' ),
			'view_items'            => __( 'View Services', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Service', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'Not found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'nvoos-content-graph-pro' ),
			'featured_image'        => __( 'Service Image', 'nvoos-content-graph-pro' ),
			'set_featured_image'    => __( 'Set service image', 'nvoos-content-graph-pro' ),
			'remove_featured_image' => __( 'Remove service image', 'nvoos-content-graph-pro' ),
			'use_featured_image'    => __( 'Use as service image', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Insert into service', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this service', 'nvoos-content-graph-pro' ),
			'items_list'            => __( 'Services list', 'nvoos-content-graph-pro' ),
			'items_list_navigation' => __( 'Services list navigation', 'nvoos-content-graph-pro' ),
			'filter_items_list'     => __( 'Filter services list', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'label'               => __( 'Service', 'nvoos-content-graph-pro' ),
			'description'         => __( 'Bookable services for appointments', 'nvoos-content-graph-pro' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions' ),
			'taxonomies'          => array( self::TAXONOMY ),
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=mcp_appointment',
			'menu_position'       => 25,
			'menu_icon'           => 'dashicons-admin-tools',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'show_in_rest'        => true,
			'rest_base'           => 'services',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register the Service Category taxonomy.
	 *
	 * @since 2.6.0
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'                       => _x( 'Service Categories', 'Taxonomy General Name', 'nvoos-content-graph-pro' ),
			'singular_name'              => _x( 'Service Category', 'Taxonomy Singular Name', 'nvoos-content-graph-pro' ),
			'menu_name'                  => __( 'Categories', 'nvoos-content-graph-pro' ),
			'all_items'                  => __( 'All Categories', 'nvoos-content-graph-pro' ),
			'parent_item'                => __( 'Parent Category', 'nvoos-content-graph-pro' ),
			'parent_item_colon'          => __( 'Parent Category:', 'nvoos-content-graph-pro' ),
			'new_item_name'              => __( 'New Category Name', 'nvoos-content-graph-pro' ),
			'add_new_item'               => __( 'Add New Category', 'nvoos-content-graph-pro' ),
			'edit_item'                  => __( 'Edit Category', 'nvoos-content-graph-pro' ),
			'update_item'                => __( 'Update Category', 'nvoos-content-graph-pro' ),
			'view_item'                  => __( 'View Category', 'nvoos-content-graph-pro' ),
			'separate_items_with_commas' => __( 'Separate categories with commas', 'nvoos-content-graph-pro' ),
			'add_or_remove_items'        => __( 'Add or remove categories', 'nvoos-content-graph-pro' ),
			'choose_from_most_used'      => __( 'Choose from the most used', 'nvoos-content-graph-pro' ),
			'popular_items'              => __( 'Popular Categories', 'nvoos-content-graph-pro' ),
			'search_items'               => __( 'Search Categories', 'nvoos-content-graph-pro' ),
			'not_found'                  => __( 'Not Found', 'nvoos-content-graph-pro' ),
			'no_terms'                   => __( 'No categories', 'nvoos-content-graph-pro' ),
			'items_list'                 => __( 'Categories list', 'nvoos-content-graph-pro' ),
			'items_list_navigation'      => __( 'Categories list navigation', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'labels'            => $labels,
			'hierarchical'      => true,
			'public'            => true,
			'show_ui'           => true,
			'show_admin_column' => true,
			'show_in_nav_menus' => true,
			'show_tagcloud'     => true,
			'show_in_rest'      => true,
		);

		register_taxonomy( self::TAXONOMY, array( self::POST_TYPE ), $args );
	}

	/**
	 * Load metabox classes.
	 *
	 * @since 2.6.0
	 */
	protected static function load_metabox_classes() {
		// Load base metabox class.
		$base_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-service-metabox-base.php';
		if ( file_exists( $base_file ) ) {
			require_once $base_file;
		}

		// Load metabox implementations.
		$details_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-service-metabox-details.php';
		if ( file_exists( $details_file ) ) {
			require_once $details_file;
			self::$metaboxes['details'] = new WP_MCP_AI_Service_Metabox_Details();
		}
	}

	/**
	 * Register meta boxes for service editing.
	 *
	 * @since 2.6.0
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on service edit screen.
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Register each metabox.
		foreach ( self::$metaboxes as $metabox ) {
			add_meta_box(
				$metabox->get_id(),
				$metabox->get_title(),
				array( $metabox, 'render' ),
				self::POST_TYPE,
				$metabox->get_context(),
				$metabox->get_priority()
			);
		}
	}

	/**
	 * Save service meta data from metaboxes.
	 *
	 * @since 2.6.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_service_meta( $post_id, $post ) {
		// Check if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check user permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save data from each metabox.
		foreach ( self::$metaboxes as $metabox ) {
			$metabox->save( $post_id, $post );
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.6.0
	 * @param array $columns Admin columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $title ) {
			$new_columns[ $key ] = $title;

			// Add custom columns after title.
			if ( 'title' === $key ) {
				$new_columns['duration'] = __( 'Duration', 'nvoos-content-graph-pro' );
				$new_columns['price']    = __( 'Price', 'nvoos-content-graph-pro' );
				$new_columns['buffer']   = __( 'Buffer Time', 'nvoos-content-graph-pro' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom admin column content.
	 *
	 * @since 2.6.0
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'duration':
				$duration = get_post_meta( $post_id, '_service_duration', true );
				if ( $duration ) {
					echo esc_html( $duration . ' ' . __( 'minutes', 'nvoos-content-graph-pro' ) );
				} else {
					echo '<span class="na">—</span>';
				}
				break;

			case 'price':
				$price = get_post_meta( $post_id, '_service_price', true );
				if ( $price ) {
					echo esc_html( '$' . number_format( (float) $price, 2 ) );
				} else {
					echo '<span class="na">—</span>';
				}
				break;

			case 'buffer':
				$buffer = get_post_meta( $post_id, '_service_buffer_time', true );
				if ( $buffer ) {
					echo esc_html( $buffer . ' ' . __( 'minutes', 'nvoos-content-graph-pro' ) );
				} else {
					echo '<span class="na">—</span>';
				}
				break;
		}
	}

	/**
	 * Make admin columns sortable.
	 *
	 * @since 2.6.0
	 * @param array $columns Sortable columns.
	 * @return array Modified columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['duration'] = 'duration';
		$columns['price']    = 'price';
		return $columns;
	}
}

// Initialize the Service CPT.
WP_MCP_AI_Service_CPT::init();
