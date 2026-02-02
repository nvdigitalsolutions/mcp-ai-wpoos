<?php
/**
 * Document Template Custom Post Type for managing document generation templates.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation_Toolkit
 * @since 1.1.0
 */

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
			'name'                  => _x( 'Document Templates', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => _x( 'Document Template', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => _x( 'Document Templates', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar'        => _x( 'Document Template', 'Add New on Toolbar', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Document Template', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Document Template', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Document Template', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Document Template', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Templates', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Document Templates', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon'     => __( 'Parent Document Templates:', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No document templates found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No document templates found in Trash.', 'mcp-ai-wpoos-pro' ),
			'featured_image'        => _x( 'Template Preview', 'Overrides the "Featured Image" phrase', 'mcp-ai-wpoos-pro' ),
			'set_featured_image'    => _x( 'Set preview', 'Overrides the "Set featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'remove_featured_image' => _x( 'Remove preview', 'Overrides the "Remove featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'use_featured_image'    => _x( 'Use as preview', 'Overrides the "Use as featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'archives'              => _x( 'Document Template archives', 'The post type archive label', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => _x( 'Insert into template', 'Overrides the "Insert into post" phrase', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this template', 'Overrides the "Uploaded to this post" phrase', 'mcp-ai-wpoos-pro' ),
			'filter_items_list'     => _x( 'Filter document templates list', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
			'items_list_navigation' => _x( 'Document Templates list navigation', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
			'items_list'            => _x( 'Document Templates list', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
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
			'invoices'      => __( 'Invoices', 'mcp-ai-wpoos-pro' ),
			'reports'       => __( 'Reports', 'mcp-ai-wpoos-pro' ),
			'contracts'     => __( 'Contracts', 'mcp-ai-wpoos-pro' ),
			'receipts'      => __( 'Receipts', 'mcp-ai-wpoos-pro' ),
			'proposals'     => __( 'Proposals', 'mcp-ai-wpoos-pro' ),
			'spreadsheets'  => __( 'Spreadsheets', 'mcp-ai-wpoos-pro' ),
			'presentations' => __( 'Presentations', 'mcp-ai-wpoos-pro' ),
			'certificates'  => __( 'Certificates', 'mcp-ai-wpoos-pro' ),
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
		$is_doc_page = ( $post_type === self::POST_TYPE );
		if ( ! $is_doc_page && $screen->post_type !== self::POST_TYPE ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Document Generation Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Document Generation Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
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
					<strong><?php esc_html_e( 'Document Generation Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Document Generation Toolkit is currently disabled. Enable it to create and manage document templates.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Document Generation Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a> and check <strong>"Enable Document Generation Toolkit"</strong>.', 'mcp-ai-wpoos-pro' ),
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
			__( 'Template Configuration', 'mcp-ai-wpoos-pro' ),
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
				<th><label for="document_type"><?php esc_html_e( 'Document Type', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="document_type" id="document_type" class="regular-text">
						<option value=""><?php esc_html_e( '-- Select Type --', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="pdf" <?php selected( $doc_type, 'pdf' ); ?>>PDF</option>
						<option value="docx" <?php selected( $doc_type, 'docx' ); ?>>Word Document (.docx)</option>
						<option value="xlsx" <?php selected( $doc_type, 'xlsx' ); ?>>Excel Spreadsheet (.xlsx)</option>
					</select>
					<p class="description"><?php esc_html_e( 'Type of document this template generates', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="output_format"><?php esc_html_e( 'Output Format', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="output_format" id="output_format" class="regular-text">
						<option value="download" <?php selected( $output_format, 'download' ); ?>><?php esc_html_e( 'Download', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="attach" <?php selected( $output_format, 'attach' ); ?>><?php esc_html_e( 'Attach to Media Library', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="email" <?php selected( $output_format, 'email' ); ?>><?php esc_html_e( 'Send via Email', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="page_size"><?php esc_html_e( 'Page Size', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="page_size" id="page_size">
						<option value="a4" <?php selected( $page_size, 'a4' ); ?>>A4</option>
						<option value="letter" <?php selected( $page_size, 'letter' ); ?>>Letter</option>
						<option value="legal" <?php selected( $page_size, 'legal' ); ?>>Legal</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="orientation"><?php esc_html_e( 'Orientation', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="orientation" id="orientation">
						<option value="portrait" <?php selected( $orientation, 'portrait' ); ?>><?php esc_html_e( 'Portrait', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="landscape" <?php selected( $orientation, 'landscape' ); ?>><?php esc_html_e( 'Landscape', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="enable_branding"><?php esc_html_e( 'Enable Branding', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<label>
						<input type="checkbox" name="enable_branding" id="enable_branding" value="1" <?php checked( $enable_branding, '1' ); ?> />
						<?php esc_html_e( 'Include logo, watermark, and custom branding', 'mcp-ai-wpoos-pro' ); ?>
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
				$new_columns['doc_type']    = __( 'Type', 'mcp-ai-wpoos-pro' );
				$new_columns['page_size']   = __( 'Page Size', 'mcp-ai-wpoos-pro' );
				$new_columns['orientation'] = __( 'Orientation', 'mcp-ai-wpoos-pro' );
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
				__( 'Generate Document', 'mcp-ai-wpoos-pro' )
			);
		}
		return $actions;
	}
}
