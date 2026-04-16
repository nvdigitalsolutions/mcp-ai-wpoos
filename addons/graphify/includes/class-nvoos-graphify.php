<?php
/**
 * NV oOS Graphify Addon — Core Class
 *
 * Handles activation checks, admin notices, tool registration,
 * settings management, and auto-rebuild scheduling for the
 * WordPress knowledge graph addon.
 *
 * @package NV_oOS_Graphify
 * @since   0.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Graphify Addon.
 *
 * @since 0.1.0
 */
class NV_oOS_Graphify {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_graphify_settings';

	/**
	 * WordPress option key for the database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION_OPTION = 'nvoos_graphify_db_version';

	/**
	 * Current database schema version.
	 *
	 * @var string
	 */
	const DB_VERSION = '1.0.0';

	/**
	 * WP-Cron hook name for scheduled graph rebuilds.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'nvoos_graphify_scheduled_rebuild';

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
	}

	/**
	 * Fired on plugins_loaded — verify base plugin, register tools and REST routes.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! nvoos_graphify_is_base_active() ) {
			return;
		}

		// Register graphify tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_tools' ) );

		// Register REST API routes.
		add_action( 'rest_api_init', array( __CLASS__, 'register_rest_routes' ) );

		// Initialize database version checks.
		NV_oOS_Graphify_Database::init();

		// Set up auto-rebuild hooks based on settings.
		self::setup_auto_rebuild();
	}

	/**
	 * Register REST API routes for the graphify addon.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function register_rest_routes() {
		// Placeholder for future REST controller registration.
	}

	/**
	 * Check whether the graphify addon is enabled in settings.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = self::get_settings();
		return ! empty( $settings['enabled'] );
	}

	/**
	 * Get addon settings merged with defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			self::get_default_settings()
		);
	}

	/**
	 * Get default addon settings.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function get_default_settings() {
		return array(
			'enabled'              => false,
			'post_types'           => array( 'post', 'page' ),
			'include_taxonomies'   => true,
			'include_users'        => true,
			'include_media'        => false,
			'auto_rebuild'         => 'manual',
			'rebuild_schedule'     => 'weekly',
			'max_nodes_display'    => 2000,
		);
	}

	/**
	 * Get the current site's graph ID.
	 *
	 * On multisite installations this returns the current blog ID.
	 * On single-site installations it always returns 1.
	 *
	 * @since 0.1.0
	 *
	 * @return int
	 */
	public static function get_graph_id() {
		if ( is_multisite() ) {
			return get_current_blog_id();
		}

		return 1;
	}

	/**
	 * Register graphify tools with the oOS tool registry.
	 *
	 * @since 0.1.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_tools( $registry ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$tools = array(
			'NV_oOS_Graphify_Tool_Build_Graph',
			'NV_oOS_Graphify_Tool_Query_Graph',
			'NV_oOS_Graphify_Tool_Graph_Status',
		);

		foreach ( $tools as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Require all tool class files.
	 *
	 * Uses a static guard to prevent double-loading.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function load_tool_files() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;

		$dir = NVOOS_GRAPHIFY_PATH . 'includes/tools/';

		if ( file_exists( $dir . 'class-nvoos-graphify-tool-build-graph.php' ) ) {
			require_once $dir . 'class-nvoos-graphify-tool-build-graph.php';
		}

		if ( file_exists( $dir . 'class-nvoos-graphify-tool-query-graph.php' ) ) {
			require_once $dir . 'class-nvoos-graphify-tool-query-graph.php';
		}

		if ( file_exists( $dir . 'class-nvoos-graphify-tool-graph-status.php' ) ) {
			require_once $dir . 'class-nvoos-graphify-tool-graph-status.php';
		}
	}

	/**
	 * Set up auto-rebuild hooks based on current settings.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function setup_auto_rebuild() {
		$settings = self::get_settings();

		if ( ! self::is_enabled() ) {
			return;
		}

		$auto_rebuild = isset( $settings['auto_rebuild'] ) ? $settings['auto_rebuild'] : 'manual';

		switch ( $auto_rebuild ) {
			case 'save_post':
				add_action( 'save_post', array( __CLASS__, 'queue_incremental_extraction' ), 20, 2 );
				break;

			case 'scheduled':
				add_action( self::CRON_HOOK, array( __CLASS__, 'run_scheduled_rebuild' ) );
				if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
					$recurrence = isset( $settings['rebuild_schedule'] ) ? $settings['rebuild_schedule'] : 'weekly';
					wp_schedule_event( time(), $recurrence, self::CRON_HOOK );
				}
				break;

			case 'manual':
			default:
				// No automatic hooks needed.
				break;
		}
	}

	/**
	 * Queue an incremental graph extraction when a post is saved.
	 *
	 * Only processes posts whose post type is included in the addon settings.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function queue_incremental_extraction( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$settings   = self::get_settings();
		$post_types = isset( $settings['post_types'] ) ? (array) $settings['post_types'] : array( 'post', 'page' );

		if ( ! in_array( $post->post_type, $post_types, true ) ) {
			return;
		}

		/**
		 * Fires when a post should be incrementally extracted into the knowledge graph.
		 *
		 * @since 0.1.0
		 *
		 * @param int     $post_id Post ID.
		 * @param WP_Post $post    Post object.
		 */
		do_action( 'nvoos_graphify_queue_extraction', $post_id, $post );
	}

	/**
	 * Run a full scheduled graph rebuild.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function run_scheduled_rebuild() {
		/**
		 * Fires when a scheduled full graph rebuild should be executed.
		 *
		 * @since 0.1.0
		 */
		do_action( 'nvoos_graphify_scheduled_rebuild' );
	}

	/**
	 * Display admin notices about addon status.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Show activation notice.
		if ( get_transient( 'nvoos_graphify_activated' ) ) {
			delete_transient( 'nvoos_graphify_activated' );
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'NV oOS Graphify Addon activated — knowledge graph tools are now available in the oOS chat interface.', 'nvoos-graphify' );
			echo '</p></div>';
		}

		// Warn if base plugin is missing.
		if ( ! nvoos_graphify_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Graphify Addon requires the NV oOS base plugin to be installed and active.', 'nvoos-graphify' );
			echo '</p></div>';
		}
	}
}
