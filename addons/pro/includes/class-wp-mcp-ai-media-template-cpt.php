<?php
/**
 * Media Template Custom Post Type for managing graphic editor templates.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the Media Template custom post type.
 */
class WP_MCP_AI_Media_Template_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_media_tpl';

	/**
	 * Taxonomy for template categories.
	 *
	 * @var string
	 */
	const TAXONOMY_CATEGORY = 'mcp_ai_tpl_category';

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
		// Always register post type and show notices, so admin pages are visible.
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'init', array( __CLASS__, 'register_taxonomy' ) );
		add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );

		// Check if feature is available and enabled before initializing full functionality.
		// Only available in Full Version (not Base Version), unless Pro addon is active.
		// When Pro addon is active (WP_MCP_AI_PRO_VERSION defined), features should work even in base mode.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			// Base version without Pro - only show notices, don't initialize functionality.
			return;
		}

		// Check if media toolkit is enabled in settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			// Feature disabled - only show notices, don't initialize functionality.
			return;
		}

		// Feature is available and enabled - initialize full functionality.
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_template_meta' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );

		// Phase 4: Bulk actions and admin enhancements.
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_action_duplicate_media_template', array( __CLASS__, 'handle_duplicate_template' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );
		add_action( 'wp_ajax_mcp_ai_preview_template', array( __CLASS__, 'ajax_preview_template' ) );
		add_action( 'wp_ajax_mcp_ai_quick_apply_template', array( __CLASS__, 'ajax_quick_apply_template' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when media toolkit is disabled but user tries to access template pages.
	 */
	public static function show_disabled_notice() {
		// Only show on media template-related pages.
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Check if we're on a media template post type page.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type        = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_template_page = ( self::POST_TYPE === $post_type );
		if ( ! $is_template_page && self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'Media Toolkit Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'The Media Toolkit is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Code snippet */
							__( 'To use the Media Toolkit, remove or set to <code>false</code> the following constant in your <code>wp-config.php</code>: %s', 'mcp-ai-wpoos-pro' ),
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
					<?php esc_html_e( 'The Media Toolkit is currently disabled. Enable it to create and manage media templates.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable the Media Toolkit, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable Media Toolkit"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
		// Load base metabox class.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-template-metabox-base.php';

		// Load metabox implementations.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-template-metabox-operation.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-media-template-metabox-stats.php';

		// Initialize metabox instances.
		self::$metaboxes['operation'] = new WP_MCP_AI_Media_Template_Metabox_Operation();
		self::$metaboxes['stats']     = new WP_MCP_AI_Media_Template_Metabox_Stats();
	}

	/**
	 * Register meta boxes for template editing.
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on template edit screen.
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
	 * Save template meta data from metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_template_meta( $post_id, $post ) {
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
	 * Show informational notice on template edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on template edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_media_toolkit'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info media-template-info-notice">
			<p>
				<strong><?php esc_html_e( 'Media Template Management', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'Media templates allow you to save reusable configurations for the Graphic Editor Plus tool. Create templates here and apply them to media via AI assistants or the media library.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Available Operations:</strong> Add Logo, Resize Graphic, AI Enhance, AI Style Transfer, AI Background Modification, AI Retouch', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Tools:</strong> Use <code>apply_media_template</code>, <code>list_media_templates</code>, and <code>create_media_template</code> tools to work with templates via chat.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Register Media Template custom post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'Media Templates', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'Media Template', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'Media Templates', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'Media Template', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'media template', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New Template', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New Template', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit Template', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View Template', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Templates', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Templates', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Templates:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No templates found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No templates found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'Reusable templates for Graphic Editor Plus operations.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => 'upload.php',
				'menu_icon'          => 'dashicons-admin-customizer',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => null,
				'supports'           => array( 'title', 'editor', 'author' ),
				'show_in_rest'       => false,
			)
		);
	}

	/**
	 * Register taxonomy for template categories.
	 */
	public static function register_taxonomy() {
		register_taxonomy(
			self::TAXONOMY_CATEGORY,
			array( self::POST_TYPE ),
			array(
				'labels'            => array(
					'name'              => _x( 'Template Categories', 'taxonomy general name', 'mcp-ai-wpoos-pro' ),
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
				$new_columns['operation']   = __( 'Operation', 'mcp-ai-wpoos-pro' );
				$new_columns['usage_count'] = __( 'Usage', 'mcp-ai-wpoos-pro' );
				$new_columns['last_used']   = __( 'Last Used', 'mcp-ai-wpoos-pro' );
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
			case 'operation':
				$operation = get_post_meta( $post_id, '_mcp_ai_template_operation', true );
				if ( ! empty( $operation ) ) {
					$operations = array(
						'add_logo'       => __( 'Add Logo', 'mcp-ai-wpoos-pro' ),
						'resize_graphic' => __( 'Resize', 'mcp-ai-wpoos-pro' ),
						'expand_scene'   => __( 'Expand Scene', 'mcp-ai-wpoos-pro' ),
						'ai_enhance'     => __( 'AI Enhance', 'mcp-ai-wpoos-pro' ),
						'ai_style'       => __( 'AI Style', 'mcp-ai-wpoos-pro' ),
						'ai_background'  => __( 'AI Background', 'mcp-ai-wpoos-pro' ),
						'ai_retouch'     => __( 'AI Retouch', 'mcp-ai-wpoos-pro' ),
					);
					echo esc_html( isset( $operations[ $operation ] ) ? $operations[ $operation ] : $operation );
				} else {
					echo '<em>' . esc_html__( 'Not set', 'mcp-ai-wpoos-pro' ) . '</em>';
				}
				break;

			case 'usage_count':
				$usage_count = get_post_meta( $post_id, '_mcp_ai_template_usage_count', true );
				echo esc_html( number_format_i18n( $usage_count ? absint( $usage_count ) : 0 ) );
				break;

			case 'last_used':
				$last_used = get_post_meta( $post_id, '_mcp_ai_template_last_used', true );
				if ( ! empty( $last_used ) ) {
					$timestamp = is_numeric( $last_used ) ? absint( $last_used ) : strtotime( $last_used );
					echo esc_html( human_time_diff( $timestamp, current_time( 'timestamp' ) ) . ' ' . __( 'ago', 'mcp-ai-wpoos-pro' ) );
				} else {
					echo '<em>' . esc_html__( 'Never', 'mcp-ai-wpoos-pro' ) . '</em>';
				}
				break;
		}
	}

	/**
	 * Add custom row actions.
	 *
	 * @param array   $actions Existing actions.
	 * @param WP_Post $post    Post object.
	 * @return array Modified actions.
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE === $post->post_type ) {
			$actions['duplicate'] = sprintf(
				'<a href="%s">%s</a>',
				wp_nonce_url(
					add_query_arg(
						array(
							'action'  => 'duplicate_media_template',
							'post_id' => $post->ID,
						),
						admin_url( 'admin.php' )
					),
					'duplicate_media_template_' . $post->ID
				),
				__( 'Duplicate', 'mcp-ai-wpoos-pro' )
			);
		}

		return $actions;
	}

	/**
	 * Add bulk actions for media templates (Phase 4).
	 *
	 * @param array $actions Existing bulk actions.
	 * @return array Modified bulk actions.
	 */
	public static function add_bulk_actions( $actions ) {
		$actions['duplicate_templates'] = __( 'Duplicate', 'mcp-ai-wpoos-pro' );
		$actions['export_templates']    = __( 'Export', 'mcp-ai-wpoos-pro' );
		return $actions;
	}

	/**
	 * Handle bulk actions for media templates (Phase 4).
	 *
	 * @param string $redirect_to Redirect URL.
	 * @param string $doaction    Action being taken.
	 * @param array  $post_ids    Array of post IDs.
	 * @return string Modified redirect URL.
	 */
	public static function handle_bulk_actions( $redirect_to, $doaction, $post_ids ) {
		if ( 'duplicate_templates' === $doaction ) {
			$duplicated = 0;
			foreach ( $post_ids as $post_id ) {
				if ( self::duplicate_template( $post_id ) ) {
					++$duplicated;
				}
			}
			$redirect_to = add_query_arg( 'duplicated_templates', $duplicated, $redirect_to );
		} elseif ( 'export_templates' === $doaction ) {
			// Export templates as JSON.
			$templates = array();
			foreach ( $post_ids as $post_id ) {
				$post = get_post( $post_id );
				if ( $post && self::POST_TYPE === $post->post_type ) {
					$templates[] = array(
						'title'       => $post->post_title,
						'description' => $post->post_content,
						'operation'   => get_post_meta( $post_id, '_mcp_ai_template_operation', true ),
						'parameters'  => get_post_meta( $post_id, '_mcp_ai_template_parameters', true ),
						'categories'  => wp_get_object_terms( $post_id, self::TAXONOMY_CATEGORY, array( 'fields' => 'names' ) ),
					);
				}
			}

			if ( ! empty( $templates ) ) {
				// Store in transient for download.
				$transient_key = 'mcp_ai_template_export_' . get_current_user_id();
				set_transient( $transient_key, wp_json_encode( $templates, JSON_PRETTY_PRINT ), HOUR_IN_SECONDS );
				$redirect_to = add_query_arg( 'exported_templates', count( $templates ), $redirect_to );
				$redirect_to = add_query_arg( 'export_key', $transient_key, $redirect_to );
			}
		}

		return $redirect_to;
	}

	/**
	 * Handle duplicate template admin action (Phase 4).
	 */
	public static function handle_duplicate_template() {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce checked below.
		if ( ! isset( $_GET['post_id'] ) ) {
			wp_die( esc_html__( 'No template ID specified.', 'mcp-ai-wpoos-pro' ) );
		}

		$post_id = absint( $_GET['post_id'] );

		// Verify nonce.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Checking nonce here.
		if ( ! isset( $_GET['_wpnonce'] ) || ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_GET['_wpnonce'] ) ), 'duplicate_media_template_' . $post_id ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos-pro' ) );
		}

		// Check permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to duplicate templates.', 'mcp-ai-wpoos-pro' ) );
		}

		$new_id = self::duplicate_template( $post_id );

		if ( $new_id ) {
			wp_safe_redirect( admin_url( 'post.php?action=edit&post=' . $new_id ) );
			exit;
		} else {
			wp_die( esc_html__( 'Failed to duplicate template.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Duplicate a template (Phase 4).
	 *
	 * @param int $post_id Template post ID.
	 * @return int|false New template ID or false on failure.
	 */
	protected static function duplicate_template( $post_id ) {
		$post = get_post( $post_id );

		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return false;
		}

		// Create duplicate.
		$new_post = array(
			'post_type'    => self::POST_TYPE,
			'post_title'   => $post->post_title . ' ' . __( '(Copy)', 'mcp-ai-wpoos-pro' ),
			'post_content' => $post->post_content,
			'post_status'  => 'draft',
			'post_author'  => get_current_user_id(),
		);

		$new_id = wp_insert_post( $new_post );

		if ( is_wp_error( $new_id ) || ! $new_id ) {
			return false;
		}

		// Copy meta.
		$meta_keys = array(
			'_mcp_ai_template_operation',
			'_mcp_ai_template_parameters',
		);

		foreach ( $meta_keys as $meta_key ) {
			$value = get_post_meta( $post_id, $meta_key, true );
			if ( ! empty( $value ) ) {
				update_post_meta( $new_id, $meta_key, $value );
			}
		}

		// Initialize usage stats.
		update_post_meta( $new_id, '_mcp_ai_template_usage_count', 0 );
		update_post_meta( $new_id, '_mcp_ai_template_last_used', '' );

		// Copy taxonomy terms.
		$terms = wp_get_object_terms( $post_id, self::TAXONOMY_CATEGORY, array( 'fields' => 'ids' ) );
		if ( ! empty( $terms ) && ! is_wp_error( $terms ) ) {
			wp_set_object_terms( $new_id, $terms, self::TAXONOMY_CATEGORY );
		}

		return $new_id;
	}

	/**
	 * Enqueue admin assets (Phase 4).
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		$screen = get_current_screen();
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Enqueue admin CSS.
		wp_enqueue_style(
			'mcp-ai-media-template-admin',
			WP_MCP_AI_PRO_URL . 'assets/css/media-template-admin.css',
			array(),
			WP_MCP_AI_PRO_VERSION
		);

		// Enqueue admin JS.
		wp_enqueue_script(
			'mcp-ai-media-template-admin',
			WP_MCP_AI_PRO_URL . 'assets/js/media-template-admin.js',
			array( 'jquery' ),
			WP_MCP_AI_PRO_VERSION,
			true
		);

		// Localize script.
		wp_localize_script(
			'mcp-ai-media-template-admin',
			'mcpAiMediaTemplate',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'mcp_ai_media_template_admin' ),
				'i18n'    => array(
					'previewError'     => __( 'Failed to generate preview.', 'mcp-ai-wpoos-pro' ),
					'applySuccess'     => __( 'Template applied successfully!', 'mcp-ai-wpoos-pro' ),
					'applyError'       => __( 'Failed to apply template.', 'mcp-ai-wpoos-pro' ),
					'selectImage'      => __( 'Select an image to apply this template.', 'mcp-ai-wpoos-pro' ),
					'processing'       => __( 'Processing...', 'mcp-ai-wpoos-pro' ),
					'confirmDuplicate' => __( 'Are you sure you want to duplicate this template?', 'mcp-ai-wpoos-pro' ),
				),
			)
		);
	}

	/**
	 * AJAX handler for template preview (Phase 4).
	 */
	public static function ajax_preview_template() {
		// Check nonce.
		check_ajax_referer( 'mcp_ai_media_template_admin', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get template ID.
		$template_id = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		if ( ! $template_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid template ID.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get template data.
		$post = get_post( $template_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			wp_send_json_error( array( 'message' => __( 'Template not found.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$operation  = get_post_meta( $template_id, '_mcp_ai_template_operation', true );
		$parameters = get_post_meta( $template_id, '_mcp_ai_template_parameters', true );

		// Build preview data.
		$preview = array(
			'title'      => $post->post_title,
			'operation'  => $operation,
			'parameters' => json_decode( $parameters, true ),
			'summary'    => self::generate_template_summary( $operation, $parameters ),
		);

		wp_send_json_success( $preview );
	}

	/**
	 * AJAX handler for quick apply template (Phase 4).
	 */
	public static function ajax_quick_apply_template() {
		// Check nonce.
		check_ajax_referer( 'mcp_ai_media_template_admin', 'nonce' );

		// Check permissions.
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Get parameters.
		$template_id   = isset( $_POST['template_id'] ) ? absint( $_POST['template_id'] ) : 0;
		$attachment_id = isset( $_POST['attachment_id'] ) ? absint( $_POST['attachment_id'] ) : 0;

		if ( ! $template_id || ! $attachment_id ) {
			wp_send_json_error( array( 'message' => __( 'Missing required parameters.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Use the apply_media_template tool.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Apply_Media_Template' ) ) {
			require_once WP_MCP_AI_PRO_PATH . 'includes/tools/media/class-wp-mcp-ai-tool-apply-media-template.php';
		}

		$tool   = new WP_MCP_AI_Tool_Apply_Media_Template();
		$result = $tool->execute(
			array(
				'template_id'   => $template_id,
				'attachment_id' => $attachment_id,
			),
			array( 'user_id' => get_current_user_id() )
		);

		if ( ! empty( $result['success'] ) ) {
			wp_send_json_success( $result );
		} else {
			wp_send_json_error( $result );
		}
	}

	/**
	 * Generate human-readable template summary (Phase 4).
	 *
	 * @param string $operation  Operation type.
	 * @param string $parameters JSON parameters.
	 * @return string Summary text.
	 */
	protected static function generate_template_summary( $operation, $parameters ) {
		$params = json_decode( $parameters, true );
		if ( ! is_array( $params ) ) {
			return __( 'No parameters configured', 'mcp-ai-wpoos-pro' );
		}

		$summary = '';

		switch ( $operation ) {
			case 'resize_graphic':
				$width  = isset( $params['target_width'] ) ? $params['target_width'] : '?';
				$height = isset( $params['target_height'] ) ? $params['target_height'] : '?';
				$format = isset( $params['output_format'] ) ? strtoupper( $params['output_format'] ) : 'PNG';
				/* translators: 1: width, 2: height, 3: format */
				$summary = sprintf( __( 'Resize to %1$s × %2$s, Output: %3$s', 'mcp-ai-wpoos-pro' ), $width, $height, $format );
				break;

			case 'add_logo':
				$position = isset( $params['logo_position'] ) ? $params['logo_position'] : 'bottom-right';
				$scale    = isset( $params['logo_scale'] ) ? ( $params['logo_scale'] * 100 ) . '%' : '15%';
				/* translators: 1: position, 2: scale */
				$summary = sprintf( __( 'Add logo at %1$s, Scale: %2$s', 'mcp-ai-wpoos-pro' ), $position, $scale );
				break;

			case 'expand_scene':
				$direction = isset( $params['expand_direction'] ) ? $params['expand_direction'] : 'all';
				$pixels    = isset( $params['expand_pixels'] ) ? $params['expand_pixels'] : '100';
				/* translators: 1: direction, 2: pixels */
				$summary = sprintf( __( 'Expand %1$s by %2$s pixels', 'mcp-ai-wpoos-pro' ), $direction, $pixels );
				break;

			case 'ai_enhance':
			case 'ai_style':
			case 'ai_background':
			case 'ai_retouch':
				$operations = array(
					'ai_enhance'    => __( 'AI-powered photo enhancement', 'mcp-ai-wpoos-pro' ),
					'ai_style'      => __( 'AI style transfer', 'mcp-ai-wpoos-pro' ),
					'ai_background' => __( 'AI background modification', 'mcp-ai-wpoos-pro' ),
					'ai_retouch'    => __( 'AI-powered retouching', 'mcp-ai-wpoos-pro' ),
				);
				$summary    = isset( $operations[ $operation ] ) ? $operations[ $operation ] : __( 'AI operation', 'mcp-ai-wpoos-pro' );
				break;

			default:
				$summary = __( 'Custom operation', 'mcp-ai-wpoos-pro' );
		}

		return $summary;
	}
}
