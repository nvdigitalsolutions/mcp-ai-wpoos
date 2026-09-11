<?php
/**
 * Architectural Specification CPT (ecosystem port - Wave F2, architectural-design data layer).
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
 * Registers and manages the Architectural Specification custom post type.
 *
 * @since 2.10.0
 */
class WP_MCP_AI_Architectural_Specification_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_arch_spec';

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
	 * Register architectural specification custom post type.
	 *
	 * @since 2.10.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Specifications', 'post type general name', 'nvoos-content-graph-pro' ),
					'singular_name'      => _x( 'Specification', 'post type singular name', 'nvoos-content-graph-pro' ),
					'menu_name'          => _x( 'Specifications', 'admin menu', 'nvoos-content-graph-pro' ),
					'name_admin_bar'     => _x( 'Specification', 'add new on admin bar', 'nvoos-content-graph-pro' ),
					'add_new'            => _x( 'Add New', 'specification', 'nvoos-content-graph-pro' ),
					'add_new_item'       => __( 'Add New Specification', 'nvoos-content-graph-pro' ),
					'new_item'           => __( 'New Specification', 'nvoos-content-graph-pro' ),
					'edit_item'          => __( 'Edit Specification', 'nvoos-content-graph-pro' ),
					'view_item'          => __( 'View Specification', 'nvoos-content-graph-pro' ),
					'all_items'          => __( 'Specifications', 'nvoos-content-graph-pro' ),
					'search_items'       => __( 'Search Specifications', 'nvoos-content-graph-pro' ),
					'not_found'          => __( 'No specifications found', 'nvoos-content-graph-pro' ),
					'not_found_in_trash' => __( 'No specifications found in trash', 'nvoos-content-graph-pro' ),
				),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=mcp_ai_arch_proj',
				'show_in_rest'       => true,
				'has_archive'        => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'supports'           => array( 'title', 'editor', 'author', 'excerpt' ),
				'menu_icon'          => 'dashicons-media-document',
			)
		);
	}

	/**
	 * Register post meta fields.
	 *
	 * @since 2.10.0
	 */
	public static function register_meta() {
		// Text field for spec number.
		register_post_meta(
			self::POST_TYPE,
			'_arch_spec_number',
			array(
				'type'              => 'string',
				'description'       => 'CSI MasterFormat specification number (e.g., 03 30 00)',
				'single'            => true,
				'show_in_rest'      => true,
				'sanitize_callback' => 'sanitize_text_field',
			)
		);

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

		// Three-part specification format fields.
		$spec_parts = array(
			'_arch_spec_part_1' => 'Part 1 - General: Administrative and procedural requirements',
			'_arch_spec_part_2' => 'Part 2 - Products: Material and product specifications',
			'_arch_spec_part_3' => 'Part 3 - Execution: Installation and workmanship requirements',
		);

		foreach ( $spec_parts as $meta_key => $description ) {
			register_post_meta(
				self::POST_TYPE,
				$meta_key,
				array(
					'type'              => 'string',
					'description'       => $description,
					'single'            => true,
					'show_in_rest'      => true,
					'sanitize_callback' => 'wp_kses_post',
				)
			);
		}
	}

	/**
	 * Register taxonomies for architectural specifications.
	 *
	 * Based on CSI MasterFormat divisions.
	 *
	 * @since 2.10.0
	 */
	public static function register_taxonomies() {
		// Register Specification Division taxonomy (based on CSI MasterFormat).
		register_taxonomy(
			'mcp_ai_arch_spec_div',
			self::POST_TYPE,
			array(
				'labels'            => array(
					'name'          => __( 'CSI Divisions', 'nvoos-content-graph-pro' ),
					'singular_name' => __( 'CSI Division', 'nvoos-content-graph-pro' ),
					'search_items'  => __( 'Search CSI Divisions', 'nvoos-content-graph-pro' ),
					'all_items'     => __( 'All CSI Divisions', 'nvoos-content-graph-pro' ),
					'edit_item'     => __( 'Edit CSI Division', 'nvoos-content-graph-pro' ),
					'update_item'   => __( 'Update CSI Division', 'nvoos-content-graph-pro' ),
					'add_new_item'  => __( 'Add New CSI Division', 'nvoos-content-graph-pro' ),
					'new_item_name' => __( 'New CSI Division Name', 'nvoos-content-graph-pro' ),
					'menu_name'     => __( 'CSI Divisions', 'nvoos-content-graph-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'show_in_rest'      => true,
				'query_var'         => true,
				'rewrite'           => false,
			)
		);

		// Register major CSI MasterFormat divisions.
		$default_divisions = array(
			'division-00' => __( 'Division 00 - Procurement and Contracting', 'nvoos-content-graph-pro' ),
			'division-01' => __( 'Division 01 - General Requirements', 'nvoos-content-graph-pro' ),
			'division-02' => __( 'Division 02 - Existing Conditions', 'nvoos-content-graph-pro' ),
			'division-03' => __( 'Division 03 - Concrete', 'nvoos-content-graph-pro' ),
			'division-04' => __( 'Division 04 - Masonry', 'nvoos-content-graph-pro' ),
			'division-05' => __( 'Division 05 - Metals', 'nvoos-content-graph-pro' ),
			'division-06' => __( 'Division 06 - Wood, Plastics, and Composites', 'nvoos-content-graph-pro' ),
			'division-07' => __( 'Division 07 - Thermal and Moisture Protection', 'nvoos-content-graph-pro' ),
			'division-08' => __( 'Division 08 - Openings', 'nvoos-content-graph-pro' ),
			'division-09' => __( 'Division 09 - Finishes', 'nvoos-content-graph-pro' ),
			'division-10' => __( 'Division 10 - Specialties', 'nvoos-content-graph-pro' ),
			'division-11' => __( 'Division 11 - Equipment', 'nvoos-content-graph-pro' ),
			'division-12' => __( 'Division 12 - Furnishings', 'nvoos-content-graph-pro' ),
			'division-13' => __( 'Division 13 - Special Construction', 'nvoos-content-graph-pro' ),
			'division-14' => __( 'Division 14 - Conveying Equipment', 'nvoos-content-graph-pro' ),
			'division-21' => __( 'Division 21 - Fire Suppression', 'nvoos-content-graph-pro' ),
			'division-22' => __( 'Division 22 - Plumbing', 'nvoos-content-graph-pro' ),
			'division-23' => __( 'Division 23 - HVAC', 'nvoos-content-graph-pro' ),
			'division-25' => __( 'Division 25 - Integrated Automation', 'nvoos-content-graph-pro' ),
			'division-26' => __( 'Division 26 - Electrical', 'nvoos-content-graph-pro' ),
			'division-27' => __( 'Division 27 - Communications', 'nvoos-content-graph-pro' ),
			'division-28' => __( 'Division 28 - Electronic Safety and Security', 'nvoos-content-graph-pro' ),
			'division-31' => __( 'Division 31 - Earthwork', 'nvoos-content-graph-pro' ),
			'division-32' => __( 'Division 32 - Exterior Improvements', 'nvoos-content-graph-pro' ),
			'division-33' => __( 'Division 33 - Utilities', 'nvoos-content-graph-pro' ),
		);

		foreach ( $default_divisions as $slug => $name ) {
			if ( ! term_exists( $slug, 'mcp_ai_arch_spec_div' ) ) {
				wp_insert_term( $name, 'mcp_ai_arch_spec_div', array( 'slug' => $slug ) );
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
				$new_columns['spec_number']  = __( 'Number', 'nvoos-content-graph-pro' );
				$new_columns['csi_division'] = __( 'CSI Division', 'nvoos-content-graph-pro' );
				$new_columns['project']      = __( 'Project', 'nvoos-content-graph-pro' );
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
			case 'spec_number':
				$number = get_post_meta( $post_id, '_arch_spec_number', true );
				echo esc_html( $number ? $number : '—' );
				break;

			case 'csi_division':
				$divisions = get_the_terms( $post_id, 'mcp_ai_arch_spec_div' );
				if ( $divisions && ! is_wp_error( $divisions ) ) {
					$division_names = array_map(
						function ( $term ) {
							return $term->name;
						},
						$divisions
					);
					echo esc_html( implode( ', ', $division_names ) );
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
		$columns['spec_number']  = 'spec_number';
		$columns['csi_division'] = 'csi_division';
		return $columns;
	}
}

// Initialize the Architectural Specification CPT.
WP_MCP_AI_Architectural_Specification_CPT::init();
