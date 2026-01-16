<?php
/**
 * Media Collection Custom Post Type for managing grouped media items.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Media Collection custom post type.
 */
class WP_MCP_AI_Media_Collection_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_media_coll';

	/**
	 * Taxonomy for collection categories.
	 *
	 * @var string
	 */
	const TAXONOMY_CATEGORY = 'mcp_ai_coll_category';

	/**
	 * Metabox instances.
	 *
	 * @var array
	 */
	protected static $metaboxes = array();

	/**
	 * Initialize the class.
	 */
	public static function init() {
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		// When Pro addon is active (WP_MCP_AI_PRO_VERSION defined), features should work even in base mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			// Still show notice if accessing media collection pages.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if media toolkit is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			// Show notice if trying to access media collection pages when disabled.
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_collection_meta' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when media toolkit is disabled but user tries to access collection pages.
	 */
	public static function show_disabled_notice() {
		// Only show on media collection-related pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Check if we're on a media collection post type page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type   = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_collection_page = ( $post_type === self::POST_TYPE );
		if ( ! $is_collection_page && $screen->post_type !== self::POST_TYPE ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Media Collections Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'Media Collections are a <strong>Full Version</strong> feature and not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use Media Collections, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
							'<code>define( \'WP_MCP_AI_BASE_VERSION\', true );</code>'
						)
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Media Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'Media Collections require the Media Toolkit to be enabled.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable Media Collections, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Media Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
	 * Load metabox classes.
	 */
	protected static function load_metabox_classes() {
		// Load base metabox class (reuse template base).
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-template-metabox-base.php';

		// Load metabox implementations.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-collection-metabox-items.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-collection-metabox-operations.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-collection-metabox-stats.php';

		// Initialize metabox instances.
		self::$metaboxes['items']      = new WP_MCP_AI_Media_Collection_Metabox_Items();
		self::$metaboxes['operations'] = new WP_MCP_AI_Media_Collection_Metabox_Operations();
		self::$metaboxes['stats']      = new WP_MCP_AI_Media_Collection_Metabox_Stats();
	}

	/**
	 * Register meta boxes for collection editing.
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on collection edit screen.
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
	 * Save collection meta data from metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_collection_meta( $post_id, $post ) {
		// Check if this is an autosave.
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		// Check post type.
		if ( self::POST_TYPE !== $post->post_type ) {
			return;
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		// Call save method on each metabox.
		foreach ( self::$metaboxes as $metabox ) {
			$metabox->save( $post_id, $post );
		}
	}

	/**
	 * Show informational notice on collection edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on collection edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info media-collection-info-notice">
			<p>
				<strong><?php esc_html_e( 'Media Collection Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Media collections let you group related images together and apply operations or templates to all items at once. Perfect for batch processing social media images, product photos, or blog post graphics.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Use Cases:</strong> Social media campaigns, product photo editing, event galleries, seasonal promotions, brand asset management', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> Use <code>apply_collection_template</code>, <code>process_collection</code>, and <code>list_collections</code> tools to work with collections via chat.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Media Collection custom post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Media Collections', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Media Collection', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Collections', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Media Collection', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'media collection', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Collection', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Collection', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Collection', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Collection', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Collections', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Collections', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Collections:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No collections found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No collections found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Grouped media items for batch processing with templates.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'upload.php',
				'menu_icon'          => 'dashicons-images-alt2',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author', 'thumbnail' ),
				'show_in_rest'       => false,
			)
		);
	}

	/**
	 * Register taxonomy for collection categories.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY_CATEGORY,
			array( self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'              => _x( 'Collection Categories', 'taxonomy general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'     => _x( 'Category', 'taxonomy singular name', 'mcp-ai-wpoos-pro' ),
					'search_items'      => __( 'Search Categories', 'mcp-ai-wpoos-pro' ),
					'all_items'         => __( 'All Categories', 'mcp-ai-wpoos-pro' ),
					'parent_item'       => __( 'Parent Category', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon' => __( 'Parent Category:', 'mcp-ai-wpoos-pro' ),
					'edit_item'         => __( 'Edit Category', 'mcp-ai-wpoos-pro' ),
					'update_item'       => __( 'Update Category', 'mcp-ai-wpoos-pro' ),
					'add_new_item'      => __( 'Add New Category', 'mcp-ai-wpoos-pro' ),
					'new_item_name'     => __( 'New Category Name', 'mcp-ai-wpoos-pro' ),
					'menu_name'         => __( 'Categories', 'mcp-ai-wpoos-pro' ),
				),
				'hierarchical'      => true,
				'show_ui'           => true,
				'show_admin_column' => true,
				'query_var'         => false,
				'rewrite'           => false,
				'show_in_rest'      => false,
			)
		);
	}

	/**
	 * Add custom admin columns.
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		$new_columns = array();

		foreach ( $columns as $key => $label ) {
			$new_columns[ $key ] = $label;

			if ( 'title' === $key ) {
				$new_columns['item_count']   = __( 'Items', 'mcp-ai-wpoos-pro' );
				$new_columns['templates']    = __( 'Templates', 'mcp-ai-wpoos-pro' );
				$new_columns['last_processed'] = __( 'Last Processed', 'mcp-ai-wpoos-pro' );
			}
		}

		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'item_count':
				$items = get_post_meta( $post_id, '_mcp_ai_collection_items', true );
				$count = is_array( $items ) ? count( $items ) : 0;
				echo '<strong>' . esc_html( number_format_i18n( $count ) ) . '</strong>';
				break;

			case 'templates':
				$templates = get_post_meta( $post_id, '_mcp_ai_collection_templates', true );
				if ( is_array( $templates ) && ! empty( $templates ) ) {
					echo '<span class="dashicons dashicons-yes-alt" style="color: #00a32a;"></span> ';
					echo esc_html( number_format_i18n( count( $templates ) ) );
				} else {
					echo '<span style="color: #646970;">—</span>';
				}
				break;

			case 'last_processed':
				$last_processed = get_post_meta( $post_id, '_mcp_ai_collection_last_processed', true );
				if ( ! empty( $last_processed ) ) {
					$timestamp = is_numeric( $last_processed ) ? absint( $last_processed ) : strtotime( $last_processed );
					echo esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' ) );
				} else {
					echo '<em>' . esc_html__( 'Never', 'mcp-ai-wpoos-pro' ) . '</em>';
				}
				break;
		}
	}
}
