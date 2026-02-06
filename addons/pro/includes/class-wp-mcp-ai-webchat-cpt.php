<?php
/**
 * WebChat Room Custom Post Type for managing P2P chat rooms.
 *
 * Integrates with WebChat browser extension for decentralized,
 * serverless peer-to-peer chat on WordPress sites.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and manages the WebChat Room custom post type.
 */
class WP_MCP_AI_WebChat_CPT {
	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_webchat';

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
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		// Only initialize if WebChat integration is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_webchat_integration'] ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'show_disabled_notice' ) );
			return;
		}

		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_room_meta' ), 5, 2 );
		add_action( 'admin_notices', array( __CLASS__, 'show_info_notice' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_admin_assets' ) );

		// Load metabox classes.
		self::load_metabox_classes();
	}

	/**
	 * Show admin notice when WebChat is disabled.
	 */
	public static function show_disabled_notice() {
		$screen = get_current_screen();
		if ( ! $screen ) {
			return;
		}

		// Only show on WebChat-related pages.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Just checking URL parameter for display logic.
		$post_type       = isset( $_GET['post_type'] ) ? sanitize_key( $_GET['post_type'] ) : '';
		$is_webchat_page = ( $post_type === self::POST_TYPE );
		if ( ! $is_webchat_page ) {
			return;
		}

		// Check if in Base Version without Pro addon.
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() && ! defined( 'WP_MCP_AI_PRO_VERSION' ) ) {
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'WebChat Integration Not Available', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						__( 'WebChat Integration is a <strong>Full Version</strong> feature and is not available in Base Version mode.', 'mcp-ai-wpoos-pro' )
					);
					?>
				</p>
			</div>
			<?php
			return;
		}

		// Check if feature is disabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_webchat_integration'] ) ) {
			$settings_url = admin_url( 'admin.php?page=wp_mcp_ai_settings&tab=tools' );
			?>
			<div class="notice notice-warning">
				<p>
					<strong><?php esc_html_e( 'WebChat Integration Disabled', 'mcp-ai-wpoos-pro' ); ?></strong>
				</p>
				<p>
					<?php esc_html_e( 'WebChat Integration is currently disabled. Enable it to create and manage P2P chat rooms.', 'mcp-ai-wpoos-pro' ); ?>
				</p>
				<p>
					<?php
					echo wp_kses_post(
						sprintf(
							/* translators: %s: Link to settings page */
							__( 'To enable WebChat Integration, go to <a href="%s">Settings &rarr; NV oOS &rarr; Tools &amp; Features</a>, click the <strong>Features</strong> tab, check <strong>"Enable WebChat Integration"</strong>, and save your changes.', 'mcp-ai-wpoos-pro' ),
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
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-base.php';

		// Load metabox implementations.
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-details.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-participants.php';
		require_once WP_MCP_AI_PRO_PATH . 'includes/metaboxes/class-wp-mcp-ai-webchat-metabox-assistant.php';

		// Initialize metabox instances.
		self::$metaboxes['details']      = new WP_MCP_AI_WebChat_Metabox_Details();
		self::$metaboxes['participants'] = new WP_MCP_AI_WebChat_Metabox_Participants();
		self::$metaboxes['assistant']    = new WP_MCP_AI_WebChat_Metabox_Assistant();
	}

	/**
	 * Register meta boxes for WebChat room editing.
	 */
	public static function register_meta_boxes() {
		$screen = get_current_screen();

		// Only add metaboxes on WebChat room edit screen.
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
	 * Save WebChat room meta data from metaboxes.
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_room_meta( $post_id, $post ) {
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
	 * Show informational notice on WebChat room edit screen.
	 */
	public static function show_info_notice() {
		$screen = get_current_screen();

		// Only show on WebChat room edit screens.
		if ( ! $screen || ! in_array( $screen->id, array( self::POST_TYPE, 'edit-' . self::POST_TYPE ), true ) ) {
			return;
		}

		// Don't show if feature is disabled (other notice will show).
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_webchat_integration'] ) ) {
			return;
		}
		?>
		<div class="notice notice-info webchat-info-notice">
			<p>
				<strong><?php esc_html_e( 'WebChat P2P Rooms', 'mcp-ai-wpoos-pro' ); ?></strong>
			</p>
			<p>
				<?php esc_html_e( 'WebChat enables decentralized, serverless peer-to-peer chat on your website using WebRTC technology.', 'mcp-ai-wpoos-pro' ); ?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>How it works:</strong> Visitors with the WebChat browser extension can join P2P chat rooms on your site. Messages are encrypted and sent peer-to-peer, not stored on servers.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>AI Integration:</strong> AI assistants can send messages to WebChat rooms using the <code>send_webchat_message</code> tool, enabling AI-powered moderation and automated responses.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					sprintf(
						/* translators: %s: WebChat GitHub URL */
						__( '<strong>Browser Extension:</strong> Users need the <a href="%s" target="_blank" rel="noopener noreferrer">WebChat browser extension</a> to participate in P2P chat rooms.', 'mcp-ai-wpoos-pro' ),
						'https://github.com/molvqingtai/WebChat'
					)
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Browser Requirements:</strong> WebRTC support is required. Supported browsers include Chrome/Edge (version 79+), Firefox (version 78+), Safari (version 14+), and Opera (version 66+).', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
			<p>
				<?php
				echo wp_kses_post(
					__( '<strong>Note about Console Errors:</strong> If you see WebRTC errors in the browser console, this is typically from the browser extension attempting to initialize WebRTC. These errors are harmless if you\'re not actively using the WebChat feature.', 'mcp-ai-wpoos-pro' )
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Enqueue admin assets for WebChat management.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_admin_assets( $hook ) {
		// Only enqueue on WebChat edit screens.
		if ( ! in_array( $hook, array( 'post.php', 'post-new.php' ), true ) ) {
			return;
		}

		$screen = get_current_screen();
		if ( ! $screen || self::POST_TYPE !== $screen->post_type ) {
			return;
		}

		// Enqueue admin styles if available.
		$css_file = WP_MCP_AI_PRO_PATH . 'assets/css/admin-webchat.css';
		if ( file_exists( $css_file ) ) {
			wp_enqueue_style(
				'wp-mcp-ai-webchat-admin',
				WP_MCP_AI_PRO_URL . 'assets/css/admin-webchat.css',
				array(),
				WP_MCP_AI_PRO_VERSION
			);
		}
	}

	/**
	 * Register WebChat Room custom post type.
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'             => array(
					'name'               => _x( 'WebChat Rooms', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name'      => _x( 'WebChat Room', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'menu_name'          => _x( 'WebChat Rooms', 'admin menu', 'mcp-ai-wpoos-pro' ),
					'name_admin_bar'     => _x( 'WebChat Room', 'add new on admin bar', 'mcp-ai-wpoos-pro' ),
					'add_new'            => _x( 'Add New', 'webchat room', 'mcp-ai-wpoos-pro' ),
					'add_new_item'       => __( 'Add New WebChat Room', 'mcp-ai-wpoos-pro' ),
					'new_item'           => __( 'New WebChat Room', 'mcp-ai-wpoos-pro' ),
					'edit_item'          => __( 'Edit WebChat Room', 'mcp-ai-wpoos-pro' ),
					'view_item'          => __( 'View WebChat Room', 'mcp-ai-wpoos-pro' ),
					'all_items'          => __( 'All Rooms', 'mcp-ai-wpoos-pro' ),
					'search_items'       => __( 'Search Rooms', 'mcp-ai-wpoos-pro' ),
					'parent_item_colon'  => __( 'Parent Rooms:', 'mcp-ai-wpoos-pro' ),
					'not_found'          => __( 'No rooms found.', 'mcp-ai-wpoos-pro' ),
					'not_found_in_trash' => __( 'No rooms found in Trash.', 'mcp-ai-wpoos-pro' ),
				),
				'description'        => __( 'WebChat P2P rooms for decentralized chat.', 'mcp-ai-wpoos-pro' ),
				'public'             => false,
				'publicly_queryable' => false,
				'show_ui'            => true,
				'show_in_menu'       => true,
				'menu_icon'          => 'dashicons-format-chat',
				'query_var'          => false,
				'rewrite'            => false,
				'capability_type'    => 'post',
				'has_archive'        => false,
				'hierarchical'       => false,
				'menu_position'      => 58, // After Comments.
				'supports'           => array( 'title', 'editor', 'author' ),
				'show_in_rest'       => false,
			)
		);
	}

	/**
	 * Get room ID from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return string Room ID.
	 */
	public static function get_room_id( $post_id ) {
		return get_post_meta( $post_id, '_webchat_room_id', true );
	}

	/**
	 * Get room status from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return string Room status (active, inactive, archived).
	 */
	public static function get_room_status( $post_id ) {
		$status = get_post_meta( $post_id, '_webchat_room_status', true );
		return $status ? $status : 'active';
	}

	/**
	 * Get active participants count.
	 *
	 * @param int $post_id Post ID.
	 * @return int Number of active participants.
	 */
	public static function get_participants_count( $post_id ) {
		$participants = get_post_meta( $post_id, '_webchat_participants', true );
		return is_array( $participants ) ? count( $participants ) : 0;
	}

	/**
	 * Get assigned assistant ID from post meta.
	 *
	 * @param int $post_id Post ID.
	 * @return int Assistant post ID, or 0 if none assigned.
	 */
	public static function get_assigned_assistant( $post_id ) {
		return absint( get_post_meta( $post_id, '_mcp_ai_webchat_assigned_assistant', true ) );
	}
}
