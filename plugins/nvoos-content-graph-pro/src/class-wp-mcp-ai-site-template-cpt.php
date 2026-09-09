<?php
/**
 * WP_MCP_AI_Site_Template_CPT (ecosystem port - Wave F2, site-creator data layer).
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
 * @subpackage Site_Creator
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Site Template CPT Class
 *
 * Registers and manages the Site Template custom post type for storing
 * complete site templates, page templates, and reusable components.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Site_Template_CPT {

	/**
	 * Constructor.
	 */
	public function __construct() {
		// Check if toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Only initialize if site creator toolkit is enabled.
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			add_action( 'admin_notices', array( $this, 'show_disabled_notice' ) );
			return;
		}

		// Register CPT and taxonomies.
		add_action( 'init', array( $this, 'register_post_type' ) );
		add_action( 'init', array( $this, 'register_taxonomies' ) );

		// Add custom columns.
		add_filter( 'manage_wp_site_template_posts_columns', array( $this, 'add_custom_columns' ) );
		add_action( 'manage_wp_site_template_posts_custom_column', array( $this, 'render_custom_columns' ), 10, 2 );
	}

	/**
	 * Show admin notice when site creator toolkit is disabled.
	 *
	 * @since 1.2.0
	 */
	public function show_disabled_notice() {
		$screen = get_current_screen();

		if ( ! $screen || 'wp_site_template' !== $screen->post_type ) {
			return;
		}

		// Check if we're in base version mode.
		$is_base = function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version();

		if ( $is_base ) {
			?>
			<div class="notice notice-warning is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Site Creator Toolkit Not Available', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Site Creator Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'nvoos-content-graph-pro' )
					);
					?>
				</p>
				<p>
					<?php
					printf(
						wp_kses_post(
							/* translators: %s: code constant */
							__( 'To use the Site Creator Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'nvoos-content-graph-pro' )
						),
						'<code>define( \'WP_MCP_AI_BASE_VERSION\', true );</code>'
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Toolkit is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			?>
			<div class="notice notice-info is-dismissible">
				<p>
					<strong><?php esc_html_e( 'Site Creator Toolkit Disabled', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Site Creator Toolkit is currently disabled. Enable it to create and manage site templates, page templates, and reusable components.', 'nvoos-content-graph-pro' ); ?>
				</p>
				<p>
					<?php
					printf(
						wp_kses_post(
							/* translators: %s: settings page URL */
							__( 'To enable the Site Creator Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Site Creator Toolkit"</strong>, and save your changes.', 'nvoos-content-graph-pro' )
						),
						esc_url( admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' ) )
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Register site template custom post type.
	 *
	 * @since 1.2.0
	 */
	public function register_post_type() {
		// Check if enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return;
		}

		$labels = array(
			'name'                  => _x( 'Site Templates', 'Post type general name', 'nvoos-content-graph-pro' ),
			'singular_name'         => _x( 'Site Template', 'Post type singular name', 'nvoos-content-graph-pro' ),
			'menu_name'             => _x( 'Site Creator', 'admin menu', 'nvoos-content-graph-pro' ),
			'name_admin_bar'        => _x( 'Site Template', 'add new on admin bar', 'nvoos-content-graph-pro' ),
			'add_new'               => _x( 'Add New', 'site template', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Site Template', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Site Template', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Site Template', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Site Template', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Site Templates', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Site Templates', 'nvoos-content-graph-pro' ),
			'parent_item_colon'     => __( 'Parent Site Templates:', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No site templates found.', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No site templates found in Trash.', 'nvoos-content-graph-pro' ),
			'featured_image'        => _x( 'Template Preview Image', 'Overrides the "Featured Image" phrase', 'nvoos-content-graph-pro' ),
			'set_featured_image'    => _x( 'Set preview image', 'Overrides the "Set featured image" phrase', 'nvoos-content-graph-pro' ),
			'remove_featured_image' => _x( 'Remove preview image', 'Overrides the "Remove featured image" phrase', 'nvoos-content-graph-pro' ),
			'use_featured_image'    => _x( 'Use as preview image', 'Overrides the "Use as featured image" phrase', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Site templates for automated site creation', 'nvoos-content-graph-pro' ),
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'menu_icon'          => 'dashicons-layout',
			'menu_position'      => 25,
			'query_var'          => true,
			'rewrite'            => false,
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'supports'           => array( 'title', 'editor', 'thumbnail', 'custom-fields', 'revisions' ),
			'show_in_rest'       => true,
		);

		register_post_type( 'mcp_site_template', $args );
	}

	/**
	 * Register taxonomies for site templates.
	 *
	 * @since 1.2.0
	 */
	public function register_taxonomies() {
		// Check if enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_site_creator_toolkit'] ) ) {
			return;
		}

		// Template Category taxonomy.
		$category_labels = array(
			'name'              => _x( 'Template Categories', 'taxonomy general name', 'nvoos-content-graph-pro' ),
			'singular_name'     => _x( 'Template Category', 'taxonomy singular name', 'nvoos-content-graph-pro' ),
			'search_items'      => __( 'Search Template Categories', 'nvoos-content-graph-pro' ),
			'all_items'         => __( 'All Template Categories', 'nvoos-content-graph-pro' ),
			'parent_item'       => __( 'Parent Template Category', 'nvoos-content-graph-pro' ),
			'parent_item_colon' => __( 'Parent Template Category:', 'nvoos-content-graph-pro' ),
			'edit_item'         => __( 'Edit Template Category', 'nvoos-content-graph-pro' ),
			'update_item'       => __( 'Update Template Category', 'nvoos-content-graph-pro' ),
			'add_new_item'      => __( 'Add New Template Category', 'nvoos-content-graph-pro' ),
			'new_item_name'     => __( 'New Template Category Name', 'nvoos-content-graph-pro' ),
			'menu_name'         => __( 'Categories', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'template_category',
			array( 'wp_site_template' ),
			array(
				'hierarchical'      => true,
				'labels'            => $category_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);

		// Template Style taxonomy.
		$style_labels = array(
			'name'          => _x( 'Template Styles', 'taxonomy general name', 'nvoos-content-graph-pro' ),
			'singular_name' => _x( 'Template Style', 'taxonomy singular name', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Template Styles', 'nvoos-content-graph-pro' ),
			'all_items'     => __( 'All Template Styles', 'nvoos-content-graph-pro' ),
			'edit_item'     => __( 'Edit Template Style', 'nvoos-content-graph-pro' ),
			'update_item'   => __( 'Update Template Style', 'nvoos-content-graph-pro' ),
			'add_new_item'  => __( 'Add New Template Style', 'nvoos-content-graph-pro' ),
			'new_item_name' => __( 'New Template Style Name', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Styles', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'template_style',
			array( 'wp_site_template' ),
			array(
				'hierarchical'      => false,
				'labels'            => $style_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);

		// Template Purpose taxonomy.
		$purpose_labels = array(
			'name'          => _x( 'Template Purposes', 'taxonomy general name', 'nvoos-content-graph-pro' ),
			'singular_name' => _x( 'Template Purpose', 'taxonomy singular name', 'nvoos-content-graph-pro' ),
			'search_items'  => __( 'Search Template Purposes', 'nvoos-content-graph-pro' ),
			'all_items'     => __( 'All Template Purposes', 'nvoos-content-graph-pro' ),
			'edit_item'     => __( 'Edit Template Purpose', 'nvoos-content-graph-pro' ),
			'update_item'   => __( 'Update Template Purpose', 'nvoos-content-graph-pro' ),
			'add_new_item'  => __( 'Add New Template Purpose', 'nvoos-content-graph-pro' ),
			'new_item_name' => __( 'New Template Purpose Name', 'nvoos-content-graph-pro' ),
			'menu_name'     => __( 'Purposes', 'nvoos-content-graph-pro' ),
		);

		register_taxonomy(
			'template_purpose',
			array( 'wp_site_template' ),
			array(
				'hierarchical'      => false,
				'labels'            => $purpose_labels,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => true,
				'rewrite'           => false,
				'show_in_rest'      => true,
			)
		);
	}

	/**
	 * Add custom columns to site template list.
	 *
	 * @since 1.2.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public function add_custom_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			// Add custom columns after title.
			if ( 'title' === $key ) {
				$new_columns['template_type'] = __( 'Type', 'nvoos-content-graph-pro' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom columns.
	 *
	 * @since 1.2.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public function render_custom_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'template_type':
				$template_type = get_post_meta( $post_id, '_template_type', true );
				if ( $template_type ) {
					echo esc_html( ucfirst( $template_type ) );
				} else {
					echo esc_html__( 'N/A', 'nvoos-content-graph-pro' );
				}
				break;
		}
	}
}
