<?php
/**
 * Comic Custom Post Type for managing AI-generated and uploaded comics.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Comic_Creation_Toolkit
 * @since 2.0.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Comic custom post type.
 *
 * @since 2.0.0
 */
class WP_MCP_AI_Comic_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_comic';

	/**
	 * Taxonomy for comic styles (Manga, American, Webtoon, etc.).
	 *
	 * @var string
	 */
	const TAXONOMY_STYLE = 'mcp_ai_comic_style';

	/**
	 * Initialize the class.
	 *
	 * @since 2.0.0
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );

		// Check if feature is available and enabled before initializing full functionality.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			return;
		}

		// Check if comic creation toolkit is enabled in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_comic_creation_toolkit'] ) ) {
			return;
		}

		// Feature is available and enabled - initialize full functionality.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_comic_meta' ), 5, 2 );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
	}

	/**
	 * Register the custom post type.
	 *
	 * @since 2.0.0
	 */
	public static function register_post_type() {
		$labels = array(
			'name'                  => _x( 'Comics', 'Post type general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'         => _x( 'Comic', 'Post type singular name', 'mcp-ai-wpoos-pro' ),
			'menu_name'             => _x( 'Comics', 'Admin Menu text', 'mcp-ai-wpoos-pro' ),
			'name_admin_bar'        => _x( 'Comic', 'Add New on Toolbar', 'mcp-ai-wpoos-pro' ),
			'add_new'               => __( 'Add New', 'mcp-ai-wpoos-pro' ),
			'add_new_item'          => __( 'Add New Comic', 'mcp-ai-wpoos-pro' ),
			'new_item'              => __( 'New Comic', 'mcp-ai-wpoos-pro' ),
			'edit_item'             => __( 'Edit Comic', 'mcp-ai-wpoos-pro' ),
			'view_item'             => __( 'View Comic', 'mcp-ai-wpoos-pro' ),
			'all_items'             => __( 'All Comics', 'mcp-ai-wpoos-pro' ),
			'search_items'          => __( 'Search Comics', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon'     => __( 'Parent Comics:', 'mcp-ai-wpoos-pro' ),
			'not_found'             => __( 'No comics found.', 'mcp-ai-wpoos-pro' ),
			'not_found_in_trash'    => __( 'No comics found in Trash.', 'mcp-ai-wpoos-pro' ),
			'featured_image'        => _x( 'Cover Image', 'Overrides the "Featured Image" phrase', 'mcp-ai-wpoos-pro' ),
			'set_featured_image'    => _x( 'Set cover image', 'Overrides the "Set featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'remove_featured_image' => _x( 'Remove cover image', 'Overrides the "Remove featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'use_featured_image'    => _x( 'Use as cover image', 'Overrides the "Use as featured image" phrase', 'mcp-ai-wpoos-pro' ),
			'archives'              => _x( 'Comic archives', 'The post type archive label', 'mcp-ai-wpoos-pro' ),
			'insert_into_item'      => _x( 'Insert into comic', 'Overrides the "Insert into post" phrase', 'mcp-ai-wpoos-pro' ),
			'uploaded_to_this_item' => _x( 'Uploaded to this comic', 'Overrides the "Uploaded to this post" phrase', 'mcp-ai-wpoos-pro' ),
			'filter_items_list'     => _x( 'Filter comics list', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
			'items_list_navigation' => _x( 'Comics list navigation', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
			'items_list'            => _x( 'Comics list', 'Screen reader text', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'labels'             => $labels,
			'public'             => false,
			'publicly_queryable' => false,
			'show_ui'            => true,
			'show_in_menu'       => true,
			'query_var'          => true,
			'rewrite'            => array( 'slug' => 'comic' ),
			'capability_type'    => 'post',
			'has_archive'        => false,
			'hierarchical'       => false,
			'menu_position'      => 27,
			'menu_icon'          => 'dashicons-book',
			'supports'           => array( 'title', 'editor', 'thumbnail', 'excerpt', 'author', 'custom-fields' ),
			'show_in_rest'       => true,
		);

		register_post_type( self::POST_TYPE, $args );
	}

	/**
	 * Register taxonomy for comic styles.
	 *
	 * @since 2.0.0
	 */
	public static function register_taxonomy() {
		$labels = array(
			'name'              => _x( 'Comic Styles', 'taxonomy general name', 'mcp-ai-wpoos-pro' ),
			'singular_name'     => _x( 'Comic Style', 'taxonomy singular name', 'mcp-ai-wpoos-pro' ),
			'search_items'      => __( 'Search Styles', 'mcp-ai-wpoos-pro' ),
			'all_items'         => __( 'All Styles', 'mcp-ai-wpoos-pro' ),
			'parent_item'       => __( 'Parent Style', 'mcp-ai-wpoos-pro' ),
			'parent_item_colon' => __( 'Parent Style:', 'mcp-ai-wpoos-pro' ),
			'edit_item'         => __( 'Edit Style', 'mcp-ai-wpoos-pro' ),
			'update_item'       => __( 'Update Style', 'mcp-ai-wpoos-pro' ),
			'add_new_item'      => __( 'Add New Style', 'mcp-ai-wpoos-pro' ),
			'new_item_name'     => __( 'New Style Name', 'mcp-ai-wpoos-pro' ),
			'menu_name'         => __( 'Styles', 'mcp-ai-wpoos-pro' ),
		);

		$args = array(
			'hierarchical'      => true,
			'labels'            => $labels,
			'show_ui'           => true,
			'show_admin_column' => true,
			'query_var'         => true,
			'rewrite'           => array( 'slug' => 'comic-style' ),
			'show_in_rest'      => true,
		);

		register_taxonomy( self::TAXONOMY_STYLE, array( self::POST_TYPE ), $args );

		// Create default style terms.
		self::create_default_styles();
	}

	/**
	 * Create default comic style terms.
	 *
	 * @since 2.0.0
	 */
	protected static function create_default_styles() {
		$styles = array(
			'manga'           => __( 'Manga', 'mcp-ai-wpoos-pro' ),
			'american-comic'  => __( 'American Comic', 'mcp-ai-wpoos-pro' ),
			'webtoon'         => __( 'Webtoon', 'mcp-ai-wpoos-pro' ),
			'graphic-novel'   => __( 'Graphic Novel', 'mcp-ai-wpoos-pro' ),
			'comic-strip'     => __( 'Comic Strip', 'mcp-ai-wpoos-pro' ),
			'noir'            => __( 'Noir', 'mcp-ai-wpoos-pro' ),
			'silver-age'      => __( 'Silver Age', 'mcp-ai-wpoos-pro' ),
			'euro-comic'      => __( 'European Comic', 'mcp-ai-wpoos-pro' ),
		);

		foreach ( $styles as $slug => $name ) {
			if ( ! term_exists( $slug, self::TAXONOMY_STYLE ) ) {
				wp_insert_term(
					$name,
					self::TAXONOMY_STYLE,
					array( 'slug' => $slug )
				);
			}
		}
	}

	/**
	 * Show admin notice when comic creation toolkit is disabled.
	 *
	 * @since 2.0.0
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type     = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_comic_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_comic_page && self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Comic Creation Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Comic Creation Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_comic_creation_toolkit'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp-mcp-ai-settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Comic Creation Toolkit Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'The Comic Creation Toolkit is currently disabled. Enable it to create and manage comics with AI.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Comic Creation Toolkit, go to <a href="%s">Settings → NV oOS → Tools &amp; Features</a> and check <strong>"Enable Comic Creation Toolkit"</strong>.', 'mcp-ai-wpoos-pro' ),
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
	 * @since 2.0.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'comic_config',
			__( 'Comic Configuration', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_config_metabox' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render configuration metabox.
	 *
	 * @since 2.0.0
	 *
	 * @param WP_Post $post Current post object.
	 */
	public static function render_config_metabox( $post ) {
		wp_nonce_field( 'comic_meta', 'comic_meta_nonce' );

		$reading_direction = get_post_meta( $post->ID, '_reading_direction', true );
		$page_layout       = get_post_meta( $post->ID, '_page_layout', true );
		$art_style         = get_post_meta( $post->ID, '_art_style', true );
		$series_name       = get_post_meta( $post->ID, '_series_name', true );
		$issue_number      = get_post_meta( $post->ID, '_issue_number', true );
		$script_id         = get_post_meta( $post->ID, '_script_id', true );
		?>
		<table class="form-table">
			<tr>
				<th><label for="series_name"><?php esc_html_e( 'Series Name', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="series_name" id="series_name" value="<?php echo esc_attr( $series_name ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'The series this comic belongs to (e.g., "Cosmic Guardians")', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="issue_number"><?php esc_html_e( 'Issue Number', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="number" name="issue_number" id="issue_number" value="<?php echo esc_attr( $issue_number ); ?>" min="0" step="1" class="small-text" />
					<p class="description"><?php esc_html_e( 'Issue number within the series', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="reading_direction"><?php esc_html_e( 'Reading Direction', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="reading_direction" id="reading_direction" class="regular-text">
						<option value="ltr" <?php selected( $reading_direction, 'ltr' ); ?>><?php esc_html_e( 'Left-to-Right (Western)', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="rtl" <?php selected( $reading_direction, 'rtl' ); ?>><?php esc_html_e( 'Right-to-Left (Manga)', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="page_layout"><?php esc_html_e( 'Page Layout', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<select name="page_layout" id="page_layout" class="regular-text">
						<option value="single" <?php selected( $page_layout, 'single' ); ?>><?php esc_html_e( 'Single Page', 'mcp-ai-wpoos-pro' ); ?></option>
						<option value="double" <?php selected( $page_layout, 'double' ); ?>><?php esc_html_e( 'Double Page Spread', 'mcp-ai-wpoos-pro' ); ?></option>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="art_style"><?php esc_html_e( 'Art Style Override', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<input type="text" name="art_style" id="art_style" value="<?php echo esc_attr( $art_style ); ?>" class="regular-text" />
					<p class="description"><?php esc_html_e( 'Override the taxonomy-based style with a custom description (e.g., "watercolor, hand-drawn, muted palette")', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="script_id"><?php esc_html_e( 'Linked Script', 'mcp-ai-wpoos-pro' ); ?></label></th>
				<td>
					<?php
					$scripts = get_posts(
						array(
							'post_type'      => 'mcp_ai_comic_script',
							'post_status'    => 'any',
							'posts_per_page' => -1,
							'orderby'        => 'title',
							'order'          => 'ASC',
						)
					);
					?>
					<select name="script_id" id="script_id" class="regular-text">
						<option value=""><?php esc_html_e( '-- None --', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $scripts as $script ) : ?>
							<option value="<?php echo esc_attr( $script->ID ); ?>" <?php selected( $script_id, $script->ID ); ?>>
								<?php echo esc_html( $script->post_title ); ?>
							</option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Link a script to this comic for AI panel generation', 'mcp-ai-wpoos-pro' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	/**
	 * Save comic metadata.
	 *
	 * @since 2.0.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_comic_meta( $post_id, $post ) {
		// Verify nonce.
		if ( ! isset( $_POST['comic_meta_nonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['comic_meta_nonce'] ) ), 'comic_meta' ) ) {
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
			'reading_direction' => 'sanitize_text_field',
			'page_layout'       => 'sanitize_text_field',
			'art_style'         => 'sanitize_text_field',
			'series_name'       => 'sanitize_text_field',
			'issue_number'      => 'absint',
			'script_id'         => 'absint',
		);

		foreach ( $fields as $field => $sanitize_callback ) {
			if ( isset( $_POST[ $field ] ) ) {
				// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitized via call_user_func() with the mapped callback.
				update_post_meta( $post_id, '_' . $field, call_user_func( $sanitize_callback, wp_unslash( $_POST[ $field ] ) ) );
			}
		}
	}

	/**
	 * Add custom admin columns.
	 *
	 * @since 2.0.0
	 *
	 * @param array $columns Existing columns.
	 * @return array Modified columns.
	 */
	public static function add_admin_columns( $columns ) {
		$new_columns = array();
		foreach ( $columns as $key => $value ) {
			$new_columns[ $key ] = $value;
			if ( 'title' === $key ) {
				$new_columns['cover']      = __( 'Cover', 'mcp-ai-wpoos-pro' );
				$new_columns['series']     = __( 'Series', 'mcp-ai-wpoos-pro' );
				$new_columns['direction']  = __( 'Direction', 'mcp-ai-wpoos-pro' );
				$new_columns['panels']     = __( 'Panels', 'mcp-ai-wpoos-pro' );
			}
		}
		return $new_columns;
	}

	/**
	 * Render custom admin columns.
	 *
	 * @since 2.0.0
	 *
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'cover':
				if ( has_post_thumbnail( $post_id ) ) {
					echo get_the_post_thumbnail( $post_id, array( 60, 80 ) );
				} else {
					echo '<span class="dashicons dashicons-book" style="font-size: 40px; color: #ccc;"></span>';
				}
				break;
			case 'series':
				$series = get_post_meta( $post_id, '_series_name', true );
				if ( $series ) {
					$issue = get_post_meta( $post_id, '_issue_number', true );
					echo esc_html( $series );
					if ( $issue ) {
						echo ' #' . esc_html( $issue );
					}
				} else {
					echo '—';
				}
				break;
			case 'direction':
				$dir = get_post_meta( $post_id, '_reading_direction', true );
				echo 'rtl' === $dir ? '← RTL' : 'LTR →';
				break;
			case 'panels':
				$panels = get_posts(
					array(
						'post_type'      => 'mcp_ai_comic_panel',
						'post_status'    => 'any',
						'posts_per_page' => -1,
						'meta_key'       => '_comic_id',
						'meta_value'     => $post_id,
						'fields'         => 'ids',
					)
				);
				echo count( $panels );
				break;
		}
	}

	/**
	 * Add custom row actions.
	 *
	 * @since 2.0.0
	 *
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array Modified actions.
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			$actions['generate_panels'] = sprintf(
				'<a href="#" data-comic-id="%d" class="generate-comic-panels">%s</a>',
				$post->ID,
				__( 'Generate Panels', 'mcp-ai-wpoos-pro' )
			);
		}
		return $actions;
	}
}
