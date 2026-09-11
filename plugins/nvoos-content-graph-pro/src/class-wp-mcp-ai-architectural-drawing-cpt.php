<?php
/**
 * Architectural Drawing CPT (ecosystem port - Wave F2, architectural-design data layer).
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
 * Registers and manages the Architectural Drawing custom post type.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Architectural_Drawing_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_arch_draw';

	/**
	 * Initialize the class.
	 *
	 * @since 2.10.0
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
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

		// Admin columns.
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'manage_edit-' . self::POST_TYPE . '_sortable_columns', array( __CLASS__, 'sortable_columns' ) );
	}

	/**
	 * Register architectural drawing custom post type.
	 *
	 * @since 2.10.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Drawings', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Drawing', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Drawings', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Drawing', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'drawing', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Drawing', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Drawing', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Drawing', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Drawing', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Drawings', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Drawings', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No drawings found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No drawings found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=mcp_ai_arch_proj',
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'thumbnail', 'author' ),
				'menu_icon'          => 'dashicons-media-spreadsheet',
			)
		);
	}

	/**
	 * Register post meta fields.
	 *
	 * @since 2.10.0
	 */
	public static function register_meta() {
		// Text fields with descriptions.
		$text_fields = array(
			'_arch_drawing_number' => 'AIA standard drawing number (e.g., A-101)',
			'_arch_scale'          => 'Drawing scale notation (e.g., 1/4" = 1\'-0" or 1:100)',
			'_arch_revision'       => 'Revision letter or number',
			'_arch_file_url'       => 'URL to the drawing file',
			'_arch_file_format'    => 'File format (pdf, dwg, ifc, svg, png, jpg)',
		);

		foreach ( $text_fields as $meta_key => $description ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'sanitize_text_field',
				)
			);
		}

		// Integer field for project ID.
		register_post_meta(
			self::POST_TYPE,
			'_arch_project_id',
			array(
				'type'              => 'integer',
				'description'       => 'Parent architectural design project ID',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'absint',
			)
		);
	}

	/**
	 * Register taxonomies for architectural drawings.
	 *
	 * Based on AIA/NCS standard drawing types.
	 *
	 * @since 2.10.0
	 */
	public static function register_taxonomies() {
		// Register Drawing Type taxonomy (based on AIA/NCS standards).
		register_taxonomy(
			'mcp_ai_arch_draw_type',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Drawing Types', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Drawing Type', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Drawing Types', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Drawing Types', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Drawing Type', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Drawing Type', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Drawing Type', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Drawing Type Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Drawing Types', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default drawing types based on AIA/NCS standards.
		$default_drawing_types = array(
			'floor-plan'   => __( 'Floor Plan (A-FLOR)', 'nvoos-content-graph-pro' ),
			'elevation'    => __( 'Elevation (A-ELEV)', 'nvoos-content-graph-pro' ),
			'section'      => __( 'Section (A-SECT)', 'nvoos-content-graph-pro' ),
			'detail'       => __( 'Detail (A-DETL)', 'nvoos-content-graph-pro' ),
			'ceiling-plan' => __( 'Reflected Ceiling Plan (A-RCPN)', 'nvoos-content-graph-pro' ),
			'site-plan'    => __( 'Site Plan (A-SITE)', 'nvoos-content-graph-pro' ),
			'3d-rendering' => __( '3D Rendering', 'nvoos-content-graph-pro' ),
			'schedule'     => __( 'Schedule (Door/Window/Finish)', 'nvoos-content-graph-pro' ),
			'structural'   => __( 'Structural Drawing', 'nvoos-content-graph-pro' ),
			'mechanical'   => __( 'Mechanical (HVAC)', 'nvoos-content-graph-pro' ),
			'electrical'   => __( 'Electrical', 'nvoos-content-graph-pro' ),
			'plumbing'     => __( 'Plumbing', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_drawing_types as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_arch_draw_type' ) ) {
				wp_insert_term( $name, 'mcp_ai_arch_draw_type', array( 'slug' => $slug ) );
			}
		}

		// Register Drawing Status taxonomy.
		register_taxonomy(
			'mcp_ai_arch_draw_status',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'Drawing Status', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'Status', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search Statuses', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All Statuses', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit Status', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update Status', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New Status', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New Status Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'Status', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => false,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register default drawing statuses.
		$default_statuses = array(
			'draft'       => __( 'Draft', 'nvoos-content-graph-pro' ),
			'in-progress' => __( 'In Progress', 'nvoos-content-graph-pro' ),
			'review'      => __( 'Under Review', 'nvoos-content-graph-pro' ),
			'approved'    => __( 'Approved', 'nvoos-content-graph-pro' ),
			'issued'      => __( 'Issued for Construction', 'nvoos-content-graph-pro' ),
			'as-built'    => __( 'As-Built', 'nvoos-content-graph-pro' ),
			'superseded'  => __( 'Superseded', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_statuses as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_arch_draw_status' ) ) {
				wp_insert_term( $name, 'mcp_ai_arch_draw_status', array( 'slug' => $slug ) );
			}
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.10.0
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		// Insert after title column.
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['drawing_number'] = __( 'Number', 'nvoos-content-graph-pro' );
				$new_columns['drawing_type']   = __( 'Type', 'nvoos-content-graph-pro' );
				$new_columns['drawing_status'] = __( 'Status', 'nvoos-content-graph-pro' );
				$new_columns['project']        = __( 'Project', 'nvoos-content-graph-pro' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.10.0
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'drawing_number':
				$number = get_post_meta( $post_id, '_arch_drawing_number', true );
				echo esc_html( $number ? $number : '—' );
				break;

			case 'drawing_type':
				$types = get_the_terms( $post_id, 'mcp_ai_arch_draw_type' );
				if ( $types && ! is_wp_error( $types ) ) {
					$type_names = array_map(
						function ( $term ) {
							return $term->name;
						},
						$types
					);
					echo esc_html( implode( ', ', $type_names ) );
				} else {
					echo '—';
				}
				break;

			case 'drawing_status':
				$statuses = get_the_terms( $post_id, 'mcp_ai_arch_draw_status' );
				if ( $statuses && ! is_wp_error( $statuses ) ) {
					$status_names = array_map(
						function ( $term ) {
							return $term->name;
						},
						$statuses
					);
					echo esc_html( implode( ', ', $status_names ) );
				} else {
					echo '—';
				}
				break;

			case 'project':
				$project_id = get_post_meta( $post_id, '_arch_project_id', true );
				if ( $project_id ) {
					$project = get_post( $project_id );
					if ( $project ) {
						$edit_link = get_edit_post_link( $project_id );
						echo '<a href="' . esc_url( $edit_link ) . '">' . esc_html( $project->post_title ) . '</a>';
					} else {
						echo '—';
					}
				} else {
					echo '—';
				}
				break;
		}
	}

	/**
	 * Make columns sortable.
	 *
	 * @since 2.10.0
	 * @param array $columns Existing sortable columns.
	 * @return array Modified sortable columns.
	 */
	public static function sortable_columns( $columns ) {
		$columns['drawing_number'] = 'drawing_number';
		$columns['drawing_type']   = 'drawing_type';
		$columns['drawing_status'] = 'drawing_status';
		return $columns;
	}
}

// Initialize the Architectural Drawing CPT.
WP_MCP_AI_Architectural_Drawing_CPT::init();
