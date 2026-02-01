<?php
/**
 * Image Template Custom Post Type for managing AI image generation templates.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Image_Production_Toolkit
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Image Template custom post type.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Image_Template_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_image_tpl';

	/**
	 * Taxonomy for template categories.
	 *
	 * @var string
	 */
	const TAXONOMY_CATEGORY = 'mcp_ai_img_tpl_cat';

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

		// Check if image production toolkit is enabled in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_image_production_toolkit'] ) ) {
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
			'name'                  => _x( 'Image Templates', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => _x( 'Image Template', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => _x( 'Image Templates', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar'        => _x( 'Image Template', 'Add New on Toolbar', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Image Template', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Image Template', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Image Template', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Image Template', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Image Templates', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Image Templates', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon'     => __( 'Parent Image Templates:', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No image templates found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No image templates found in Trash.', 'mcp-ai-wpoos-pro' ),
			'featured_image'        => _x( 'Template Preview Image', 'Overrides the "Featured Image" phrase', 'mcp-ai-wpoos-pro' ),
			'set_featured_image'    => _x( 'Set preview image', 'Overrides the "Set featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'remove_featured_image' => _x( 'Remove preview image', 'Overrides the "Remove featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'use_featured_image'    => _x( 'Use as preview image', 'Overrides the "Use as featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'archives'              => _x( 'Image Template archives', 'The post type archive label', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => _x( 'Insert into template', 'Overrides the "Insert into post" phrase', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this template', 'Overrides the "Uploaded to this post" phrase', 'mcp-ai-wpoos-pro' ),
			'filter_items_list'     => _x( 'Filter image templates list', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
			'items_list_navigation' => _x( 'Image Templates list navigation', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
			'items_list'            => _x( 'Image Templates list', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => 'upload.php',
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'image-template' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => null,
			'menu_icon'          => 'dashicons-format-image',
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ),
			'show_in_rest'       => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register taxonomy for image template categories.
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
			'menu_name'         => __( 'Template Categories', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'image-template-category' ),
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
			'product-photos' => __( 'Product Photos', 'mcp-ai-wpoos-pro' ),
			'social-media'   => __( 'Social Media', 'mcp-ai-wpoos-pro' ),
			'marketing'      => __( 'Marketing Materials', 'mcp-ai-wpoos-pro' ),
			'backgrounds'    => __( 'Backgrounds', 'mcp-ai-wpoos-pro' ),
			'illustrations'  => __( 'Illustrations', 'mcp-ai-wpoos-pro' ),
			'photography'    => __( 'Photography', 'mcp-ai-wpoos-pro' ),
			'abstract'       => __( 'Abstract Art', 'mcp-ai-wpoos-pro' ),
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
	 * Show admin notice when image production toolkit is disabled.
	 *
	 * @since 1.1.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type     = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_image_page = ( $post_type === self::POST_TYPE );
		if ( ! $is_image_page && $screen->post_type !== self::POST_TYPE ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Image Production Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Image Production Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_image_production_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Image Production Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Image Production Toolkit is currently disabled. Enable it to create and manage image templates.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Image Production Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a> and check <strong>"Enable Image Production Toolkit"</strong>.', 'mcp-ai-wpoos-pro' ),
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
			'image_template_config',
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
		wp_nonce_field( 'image_template_meta', 'image_template_meta_nonce' );

		$ai_provider   = get_post_meta( $post->ID, '_ai_provider', true );
		$prompt        = get_post_meta( $post->ID, '_generation_prompt', true );
		$dimensions    = get_post_meta( $post->ID, '_image_dimensions', true );
		$style         = get_post_meta( $post->ID, '_art_style', true );
		$output_format = get_post_meta( $post->ID, '_output_format', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="ai_provider"><?php esc_html_e( 'AI Provider', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="ai_provider" id="ai_provider" class="regular-text">
						<option value=""><?php esc_html_e( '-- Select Provider --', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="dalle" <?php selected( $ai_provider, 'dalle' ); ?>>DALL-E</option>
						<option value="midjourney" <?php selected( $ai_provider, 'midjourney' ); ?>>Midjourney</option>
						<option value="stable_diffusion" <?php selected( $ai_provider, 'stable_diffusion' ); ?>>Stable Diffusion</option>
					</select>
					<p class="description"><?php esc_html_e( 'Choose the AI service for image generation', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="generation_prompt"><?php esc_html_e( 'Generation Prompt', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<textarea name="generation_prompt" id="generation_prompt" rows="4" class="large-text"><?php echo esc_textarea( $prompt ); ?></textarea>
					<p class="description"><?php esc_html_e( 'The prompt used to generate images with this template', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="image_dimensions"><?php esc_html_e( 'Dimensions', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="image_dimensions" id="image_dimensions" class="regular-text">
						<option value="1024x1024" <?php selected( $dimensions, '1024x1024' ); ?>>1024×1024 (Square)</option>
						<option value="1024x1792" <?php selected( $dimensions, '1024x1792' ); ?>>1024×1792 (Portrait)</option>
						<option value="1792x1024" <?php selected( $dimensions, '1792x1024' ); ?>>1792×1024 (Landscape)</option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="art_style"><?php esc_html_e( 'Art Style', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="art_style" id="art_style" value="<?php echo esc_attr( $style ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'e.g., photorealistic, oil painting, digital art', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="output_format"><?php esc_html_e( 'Output Format', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="output_format" id="output_format">
						<option value="png" <?php selected( $output_format, 'png' ); ?>>PNG</option>
						<option value="jpg" <?php selected( $output_format, 'jpg' ); ?>>JPEG</option>
						<option value="webp" <?php selected( $output_format, 'webp' ); ?>>WebP</option>
					</select>
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
		if ( ! isset( $_POST['image_template_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['image_template_meta_nonce'] ) ), 'image_template_meta' ) ) {
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
			'ai_provider'       => 'sanitize_text_field',
			'generation_prompt' => 'sanitize_textarea_field',
			'image_dimensions'  => 'sanitize_text_field',
			'art_style'         => 'sanitize_text_field',
			'output_format'     => 'sanitize_text_field',
		);

		foreach ( $fields as $field => $sanitize_callback ) {
			if ( isset( $_POST[ $field ] ) ) {
				update_post_meta( $post_id, '_' . $field, call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field ] ) ) );
			}
		}
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
				$new_columns['preview']     = __( 'Preview', 'mcp-ai-wpoos-pro' );
				$new_columns['ai_provider'] = __( 'AI Provider', 'mcp-ai-wpoos-pro' );
				$new_columns['dimensions']  = __( 'Dimensions', 'mcp-ai-wpoos-pro' );
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
			case 'preview':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 60, 60 ) );
				} else {
					echo '<span class="dashicons dashicons-format-image" style="font-size: 40px; color: #ccc;"></span>';
				}
				break;
			case 'ai_provider':
				$provider = get_post_meta( $post_id, '_ai_provider', true );
				echo $provider ? esc_html( ucfirst( str_replace( '_', ' ', $provider ) ) ) : '—';
				break;
			case 'dimensions':
				$dimensions = get_post_meta( $post_id, '_image_dimensions', true );
				echo $dimensions ? esc_html( $dimensions ) : '—';
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
				__( 'Generate Image', 'mcp-ai-wpoos-pro' )
			);
		}
		return $actions;
	}
}
