<?php
/**
 * WP_MCP_AI_Document_Template_CPT (ecosystem port - Wave F2, document-generation data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-document-template-cpt.php` for the standalone
 * `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is
 * defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain
 * `nvoos-content-graph-pro`; no path constants, so no path swaps.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Document Template custom post type.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Document_Template_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_doc_tpl';

	/**
	 * Taxonomy for template categories.
	 *
	 * @var string
	 */
	const TAXONOMY_CATEGORY = 'mcp_ai_doc_tpl_cat';

	/**
	 * Initialize the class.
	 *
	 * @since 1.1.0
	 */
	public static function init() {
		// Always register post type and show notices, so admin pages are visible.
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );

		// Check if feature is available and enabled before initializing full functionality.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Check if document generation toolkit is enabled in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			return;
		}

		// Feature is available and enabled - initialize full functionality.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_template_meta' ), 5, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 *
	 * @since 1.1.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Document Templates', 'Post type general name', 'nvoos-content-graph-pro' ),
			'singular_name'         => _x( 'Document Template', 'Post type singular name', 'nvoos-content-graph-pro' ),
			'menu_name'             => _x( 'Document Templates', 'Admin Menu text', 'nvoos-content-graph-pro' ),
			'name_admin_bar'        => _x( 'Document Template', 'Add New on Toolbar', 'nvoos-content-graph-pro' ),
			'add_new'               => __( 'Add New', 'nvoos-content-graph-pro' ),
			'add_new_item'          => __( 'Add New Document Template', 'nvoos-content-graph-pro' ),
			'new_item'              => __( 'New Document Template', 'nvoos-content-graph-pro' ),
			'edit_item'             => __( 'Edit Document Template', 'nvoos-content-graph-pro' ),
			'view_item'             => __( 'View Document Template', 'nvoos-content-graph-pro' ),
			'all_items'             => __( 'All Templates', 'nvoos-content-graph-pro' ),
			'search_items'          => __( 'Search Document Templates', 'nvoos-content-graph-pro' ),
			'parent_item_colon'     => __( 'Parent Document Templates:', 'nvoos-content-graph-pro' ),
			'not_found'             => __( 'No document templates found.', 'nvoos-content-graph-pro' ),
			'not_found_in_trash'    => __( 'No document templates found in Trash.', 'nvoos-content-graph-pro' ),
			'featured_image'        => _x( 'Template Preview', 'Overrides the "Featured Image" phrase', 'nvoos-content-graph-pro' ),
			'set_featured_image'    => _x( 'Set preview', 'Overrides the "Set featured image" phrase', 'nvoos-content-graph-pro' ),
			'remove_featured_image' => _x( 'Remove preview', 'Overrides the "Remove featured image" phrase', 'nvoos-content-graph-pro' ),
			'use_featured_image'    => _x( 'Use as preview', 'Overrides the "Use as featured image" phrase', 'nvoos-content-graph-pro' ),
			'archives'              => _x( 'Document Template archives', 'The post type archive label', 'nvoos-content-graph-pro' ),
			'insert_into_item'      => _x( 'Insert into template', 'Overrides the "Insert into post" phrase', 'nvoos-content-graph-pro' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this template', 'Overrides the "Uploaded to this post" phrase', 'nvoos-content-graph-pro' ),
			'filter_items_list'     => _x( 'Filter document templates list', 'Screen reader text', 'nvoos-content-graph-pro' ),
			'items_list_navigation' => _x( 'Document Templates list navigation', 'Screen reader text', 'nvoos-content-graph-pro' ),
			'items_list'            => _x( 'Document Templates list', 'Screen reader text', 'nvoos-content-graph-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'document-template' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 25,
			'menu_icon'          => 'dashicons-media-document',
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ),
			'show_in_rest'       => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register taxonomy for document template categories.
	 *
	 * @since 1.1.0
	 */
	public static function register_taxonomy() {
		$labels = array(
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

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'document-template-category' ),
			'show_in_rest'      => true,
		);

		register_taxonomy( self::TAXONOMY_CATEGORY, array( self::POST_TYPE ), $args );

		// Create default categories.
		self::create_default_categories();
	}

	/**
	 * Create default template categories.
	 *
	 * @since 1.1.0
	 */
	protected static function create_default_categories() {
		$categories = array(
			'invoices'      => __( 'Invoices', 'nvoos-content-graph-pro' ),
			'reports'       => __( 'Reports', 'nvoos-content-graph-pro' ),
			'contracts'     => __( 'Contracts', 'nvoos-content-graph-pro' ),
			'receipts'      => __( 'Receipts', 'nvoos-content-graph-pro' ),
			'proposals'     => __( 'Proposals', 'nvoos-content-graph-pro' ),
			'spreadsheets'  => __( 'Spreadsheets', 'nvoos-content-graph-pro' ),
			'presentations' => __( 'Presentations', 'nvoos-content-graph-pro' ),
			'certificates'  => __( 'Certificates', 'nvoos-content-graph-pro' ),
		);

		foreach ( $categories as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY_CATEGORY ) ) {
				wp_insert_term(
					$name,
					self::TAXONOMY_CATEGORY,
					array( 'slug' => $slug )
				);
			}
		}
	}

	/**
	 * Show admin notice when document generation toolkit is disabled.
	 *
	 * @since 1.1.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_doc_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_doc_page && self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Document Generation Toolkit Not Available', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Document Generation Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'nvoos-content-graph-pro' )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_document_generation_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Document Generation Toolkit Disabled', 'nvoos-content-graph-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Document Generation Toolkit is currently disabled. Enable it to create and manage document templates.', 'nvoos-content-graph-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Document Generation Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a> and check <strong>"Enable Document Generation Toolkit"</strong>.', 'nvoos-content-graph-pro' ),
							esc_url( $settings_url )
						)
					);
					?>
				</p>
			</div>
			<?php
		}
	}

	/**
	 * Register meta boxes.
	 *
	 * @since 1.1.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'document_template_config',
			__( 'Template Configuration', 'nvoos-content-graph-pro' ),
			array( __CLASS__, 'render_config_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render configuration metabox.
	 *
	 * @since 1.1.0
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_config_metabox( $post ) {
		wp_nonce_field( 'document_template_meta', 'document_template_meta_nonce' );

		$doc_type        = get_post_meta( $post->ID, '_document_type', true );
		$output_format   = get_post_meta( $post->ID, '_output_format', true );
		$page_size       = get_post_meta( $post->ID, '_page_size', true );
		$orientation     = get_post_meta( $post->ID, '_orientation', true );
		$enable_branding = get_post_meta( $post->ID, '_enable_branding', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="document_type"><?php esc_html_e( 'Document Type', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select name="document_type" id="document_type" class="regular-text">
						<option value=""><?php esc_html_e( '-- Select Type --', 'nvoos-content-graph-pro' ); ?></option>
						<option value="pdf" <?php selected( $doc_type, 'pdf' ); ?>>PDF</option>
						<option value="docx" <?php selected( $doc_type, 'docx' ); ?>>Word Document (.docx)</option>
						<option value="xlsx" <?php selected( $doc_type, 'xlsx' ); ?>>Excel Spreadsheet (.xlsx)</option>
					</select>
					<p class="description"><?php esc_html_e( 'Type of document this template generates', 'nvoos-content-graph-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="output_format"><?php esc_html_e( 'Output Format', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select name="output_format" id="output_format" class="regular-text">
						<option value="download" <?php selected( $output_format, 'download' ); ?>><?php esc_html_e( 'Download', 'nvoos-content-graph-pro' ); ?></option>
						<option value="attach" <?php selected( $output_format, 'attach' ); ?>><?php esc_html_e( 'Attach to Media Library', 'nvoos-content-graph-pro' ); ?></option>
						<option value="email" <?php selected( $output_format, 'email' ); ?>><?php esc_html_e( 'Send via Email', 'nvoos-content-graph-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="page_size"><?php esc_html_e( 'Page Size', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select name="page_size" id="page_size">
						<option value="a4" <?php selected( $page_size, 'a4' ); ?>>A4</option>
						<option value="letter" <?php selected( $page_size, 'letter' ); ?>>Letter</option>
						<option value="legal" <?php selected( $page_size, 'legal' ); ?>>Legal</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="orientation"><?php esc_html_e( 'Orientation', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<select name="orientation" id="orientation">
						<option value="portrait" <?php selected( $orientation, 'portrait' ); ?>><?php esc_html_e( 'Portrait', 'nvoos-content-graph-pro' ); ?></option>
						<option value="landscape" <?php selected( $orientation, 'landscape' ); ?>><?php esc_html_e( 'Landscape', 'nvoos-content-graph-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="enable_branding"><?php esc_html_e( 'Enable Branding', 'nvoos-content-graph-pro' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="enable_branding" id="enable_branding" value="1" <?php checked( $enable_branding, '1' ); ?> />
						<?php esc_html_e( 'Include logo, watermark, and custom branding', 'nvoos-content-graph-pro' ); ?>
					</label>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save template metadata.
	 *
	 * @since 1.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_template_meta( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['document_template_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['document_template_meta_nonce'] ) ), 'document_template_meta' ) ) {
			return;
		}

		// Check autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Save metadata.
		$fields = array(
			'document_type' => 'sanitize_text_field',
			'output_format' => 'sanitize_text_field',
			'page_size'     => 'sanitize_text_field',
			'orientation'   => 'sanitize_text_field',
		);

		foreach ( $fields as $field => $sanitize_callback ) {
			if ( isset( $_POST[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via call_user_func() with the mapped callback.
				update_post_meta( $post_id, '_' . $field, call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field ] ) ) );
			}
		}

		// Handle checkbox separately.
		$enable_branding = isset( $_POST['enable_branding'] ) ? '1' : '0';
		update_post_meta( $post_id, '_enable_branding', $enable_branding );
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 1.1.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['doc_type']    = __( 'Type', 'nvoos-content-graph-pro' );
				$new_columns['page_size']   = __( 'Page Size', 'nvoos-content-graph-pro' );
				$new_columns['orientation'] = __( 'Orientation', 'nvoos-content-graph-pro' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 1.1.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'doc_type':
				$doc_type = get_post_meta( $post_id, '_document_type', true );
				echo $doc_type ? esc_html( strtoupper( $doc_type ) ) : '—';
				break;
			case 'page_size':
				$page_size = get_post_meta( $post_id, '_page_size', true );
				echo $page_size ? esc_html( strtoupper( $page_size ) ) : '—';
				break;
			case 'orientation':
				$orientation = get_post_meta( $post_id, '_orientation', true );
				echo $orientation ? esc_html( ucfirst( $orientation ) ) : '—';
				break;
		}
	}

	/**
	 * Add custom row actions.
	 *
	 * @since 1.1.0
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array Modified actions.
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			$actions['generate'] = sprintf(
				'<a href="#" data-template-id="%d">%s</a>',
				$post->ID,
				__( 'Generate Document', 'nvoos-content-graph-pro' )
			);
		}
		return $actions;
	}
}
