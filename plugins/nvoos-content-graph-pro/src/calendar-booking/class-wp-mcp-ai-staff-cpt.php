<?php
/**
 * Calendar Booking data layer (ecosystem port — Wave F2, calendar-booking data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/calendar-booking/class-wp-mcp-ai-staff-cpt.php` for the standalone `nvoos-content-graph-pro`
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
 * Registers and manages the Staff custom post type.
 *
 * @since 2.6.0
 */
class WP_MCP_AI_Staff_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_staff';

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
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_staff_meta' ), 5, 2 );

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Register the Staff post type.
	 *
	 * @since 2.6.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Staff', 'Post Type General Name', 'nvoos-content-graph-pro' ),
			'singular_name'         => _x( 'Staff Member', 'Post Type Singular Name', 'nvoos-content-graph-pro' ),
			'menu_name'             => __( 'Staff', 'nvoos-content-graph-pro' ),
			'name_admin_bar'        => __( 'Staff Member', 'nvoos-content-graph-pro' ),
			'archives'              => __( 'Staff Archives', 'nvoos-content-graph-pro' ),
			'attributes'            => __( 'Staff Attributes', 'nvoos-content-graph-pro' ),
			'parent_item_colon'     => __( 'Parent Staff:', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Staff', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Staff Member', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add New', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Staff Member', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Staff Member', 'nvoos-content-graph-pro' ),
			'update_item'           => __( 'Update Staff Member', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Staff Member', 'nvoos-content-graph-pro' ),
			'view_items'            => __( 'View Staff', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Staff', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'Not found', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'Not found in Trash', 'nvoos-content-graph-pro' ),
			'featured_image'        => __( 'Staff Photo', 'nvoos-content-graph-pro' ),
			'set_featured_image'    => __( 'Set staff photo', 'nvoos-content-graph-pro' ),
			'remove_featured_image' => __( 'Remove staff photo', 'nvoos-content-graph-pro' ),
			'use_featured_image'    => __( 'Use as staff photo', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => __( 'Insert into staff profile', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => __( 'Uploaded to this staff member', 'nvoos-content-graph-pro' ),
			'items_list'            => __( 'Staff list', 'nvoos-content-graph-pro' ),
			'items_list_navigation' => __( 'Staff list navigation', 'nvoos-content-graph-pro' ),
			'filter_items_list'     => __( 'Filter staff list', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'label'               => __( 'Staff Member', 'nvoos-content-graph-pro' ),
			'description'         => __( 'Staff members and resources for appointment booking', 'nvoos-content-graph-pro' ),
			'labels'              => $labels,
			'supports'            => array( 'title', 'editor', 'thumbnail', 'revisions' ),
			'hierarchical'        => false,
			'public'              => true,
			'show_ui'             => true,
			'show_in_menu'        => 'edit.php?post_type=mcp_appointment',
			'menu_position'       => 26,
			'menu_icon'           => 'dashicons-groups',
			'show_in_admin_bar'   => true,
			'show_in_nav_menus'   => true,
			'can_export'          => true,
			'has_archive'         => true,
			'exclude_from_search' => false,
			'publicly_queryable'  => true,
			'capability_type'     => 'post',
			'show_in_rest'        => true,
			'rest_base'           => 'staff',
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Load metabox classes.
	 *
	 * @since 2.6.0
	 */
	protected static function load_metabox_classes() {
		// Load base metabox class.
		$base_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-staff-metabox-base.php';
		if ( file_exists( $base_file ) ) {
			require_once $base_file;
		}

		// Load metabox implementations.
		$details_file = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/metaboxes/class-wp-mcp-ai-staff-metabox-details.php';
		if ( file_exists( $details_file ) ) {
			require_once $details_file;
			self::$metaboxes['details'] = new WP_MCP_AI_Staff_Metabox_Details();
		}
	}

	/**
	 * Register meta boxes for staff editing.
	 *
	 * @since 2.6.0
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on staff edit screen.
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
	 * Save staff meta data from metaboxes.
	 *
	 * @since 2.6.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_staff_meta( $post_id, $post ) {
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
				$new_columns['role']         = __( 'Role', 'nvoos-content-graph-pro' );
				$new_columns['services']     = __( 'Services', 'nvoos-content-graph-pro' );
				$new_columns['availability'] = __( 'Availability', 'nvoos-content-graph-pro' );
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
			case 'role':
				$role = get_post_meta( $post_id, '_staff_role', true );
				if ( $role ) {
					echo esc_html( $role );
				} else {
					echo '<span class="na">—</span>';
				}
				break;

			case 'services':
				$services = get_post_meta( $post_id, '_staff_services', true );
				if ( ! empty( $services ) && is_array( $services ) ) {
					$service_names = array();
					foreach ( $services as $service_id ) {
						$service = get_post( $service_id );
						if ( $service ) {
							$service_names[] = $service->post_title;
						}
					}
					if ( ! empty( $service_names ) ) {
						echo esc_html( implode( ', ', $service_names ) );
					} else {
						echo '<span class="na">—</span>';
					}
				} else {
					echo '<span class="na">—</span>';
				}
				break;

			case 'availability':
				$available = get_post_meta( $post_id, '_staff_available', true );
				if ( '1' === $available || 1 === $available ) {
					echo '<span style="color: green;">●</span> ' . esc_html__( 'Available', 'nvoos-content-graph-pro' );
				} else {
					echo '<span style="color: red;">●</span> ' . esc_html__( 'Unavailable', 'nvoos-content-graph-pro' );
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
		$columns['role'] = 'role';
		return $columns;
	}
}

// Initialize the Staff CPT.
WP_MCP_AI_Staff_CPT::init();
