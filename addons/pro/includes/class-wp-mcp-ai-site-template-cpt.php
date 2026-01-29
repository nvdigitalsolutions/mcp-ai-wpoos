<?php
/**
 * Site Template Custom Post Type
 *
 * Manages site templates with taxonomy support for categorization.
 *
 * @package WP_MCP_AI
 * @subpackage Site_Creator_Toolkit
 * @since 1.2.0
 */

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
					<strong><?php esc_html_e( 'Site Creator Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Site Creator Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					printf(
						wp_kses_post(
							/* translators: %s: code constant */
							__( 'To use the Site Creator Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' )
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
					<strong><?php esc_html_e( 'Site Creator Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Site Creator Toolkit is currently disabled. Enable it to create and manage site templates, page templates, and reusable components.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					printf(
						wp_kses_post(
							/* translators: %s: settings page URL */
							__( 'To enable the Site Creator Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Site Creator Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' )
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
			'name'                  => _x( 'Site Templates', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => _x( 'Site Template', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => _x( 'Site Creator', 'admin menu', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar'        => _x( 'Site Template', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
			'add_new'               => _x( 'Add New', 'site template', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Site Template', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Site Template', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Site Template', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Site Template', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Site Templates', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Site Templates', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon'     => __( 'Parent Site Templates:', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No site templates found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No site templates found in Trash.', 'mcp-ai-wpoos-pro' ),
			'featured_image'        => _x( 'Template Preview Image', 'Overrides the "Featured Image" phrase', 'mcp-ai-wpoos-pro' ),
			'set_featured_image'    => _x( 'Set preview image', 'Overrides the "Set featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'remove_featured_image' => _x( 'Remove preview image', 'Overrides the "Remove featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'use_featured_image'    => _x( 'Use as preview image', 'Overrides the "Use as featured image" phrase', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'description'        => __( 'Site templates for automated site creation', 'mcp-ai-wpoos-pro' ),
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

		register_post_type( 'wp_site_template', $args );
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
			'name'              => _x( 'Template Categories', 'taxonomy general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'     => _x( 'Template Category', 'taxonomy singular name', 'mcp-ai-wpoos-pro' ),
			'search_items'      => __( 'Search Template Categories', 'mcp-ai-wpoos-pro' ),
			'all_items'         => __( 'All Template Categories', 'mcp-ai-wpoos-pro' ),
			'parent_item'       => __( 'Parent Template Category', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon' => __( 'Parent Template Category:', 'mcp-ai-wpoos-pro' ),
			'edit_item'         => __( 'Edit Template Category', 'mcp-ai-wpoos-pro' ),
			'update_item'       => __( 'Update Template Category', 'mcp-ai-wpoos-pro' ),
			'add_new_item'      => __( 'Add New Template Category', 'mcp-ai-wpoos-pro' ),
			'new_item_name'     => __( 'New Template Category Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'         => __( 'Categories', 'mcp-ai-wpoos-pro' ),
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
			'name'              => _x( 'Template Styles', 'taxonomy general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'     => _x( 'Template Style', 'taxonomy singular name', 'mcp-ai-wpoos-pro' ),
			'search_items'      => __( 'Search Template Styles', 'mcp-ai-wpoos-pro' ),
			'all_items'         => __( 'All Template Styles', 'mcp-ai-wpoos-pro' ),
			'edit_item'         => __( 'Edit Template Style', 'mcp-ai-wpoos-pro' ),
			'update_item'       => __( 'Update Template Style', 'mcp-ai-wpoos-pro' ),
			'add_new_item'      => __( 'Add New Template Style', 'mcp-ai-wpoos-pro' ),
			'new_item_name'     => __( 'New Template Style Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'         => __( 'Styles', 'mcp-ai-wpoos-pro' ),
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
			'name'              => _x( 'Template Purposes', 'taxonomy general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'     => _x( 'Template Purpose', 'taxonomy singular name', 'mcp-ai-wpoos-pro' ),
			'search_items'      => __( 'Search Template Purposes', 'mcp-ai-wpoos-pro' ),
			'all_items'         => __( 'All Template Purposes', 'mcp-ai-wpoos-pro' ),
			'edit_item'         => __( 'Edit Template Purpose', 'mcp-ai-wpoos-pro' ),
			'update_item'       => __( 'Update Template Purpose', 'mcp-ai-wpoos-pro' ),
			'add_new_item'      => __( 'Add New Template Purpose', 'mcp-ai-wpoos-pro' ),
			'new_item_name'     => __( 'New Template Purpose Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'         => __( 'Purposes', 'mcp-ai-wpoos-pro' ),
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
				$new_columns['template_type'] = __( 'Type', 'mcp-ai-wpoos-pro' );
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
					echo esc_html__( 'N/A', 'mcp-ai-wpoos-pro' );
				}
				break;
		}
	}
}
