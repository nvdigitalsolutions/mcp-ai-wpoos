<?php
/**
 * NV oOS Graphify Addon — Core Class
 *
 * Handles activation checks, admin notices, tool registration,
 * and hook wiring for the knowledge graph addon.
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
	 * Fired on plugins_loaded — verify base plugin and register tools/hooks.
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

		// Also support the Pro-style lazy loading hook.
		add_action( 'wp_mcp_ai_load_pro_tools', array( __CLASS__, 'load_tools' ) );

		// Auto-build triggers.
		add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20, 2 );
		add_action( 'delete_post', array( __CLASS__, 'on_delete_post' ) );
		add_action( 'set_object_terms', array( __CLASS__, 'on_set_object_terms' ), 10, 4 );

		// Scheduled rebuild.
		add_action( 'nvoos_graphify_scheduled_rebuild', array( __CLASS__, 'run_scheduled_rebuild' ) );
		add_action( 'nvoos_graphify_incremental_build', array( __CLASS__, 'run_incremental_build' ) );

		// Schedule cron if configured.
		self::maybe_schedule_cron();
	}

	/**
	 * Check whether the addon is enabled in settings.
	 *
	 * @since 0.1.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Get addon settings with defaults.
	 *
	 * @since 0.1.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'enabled'              => true,
				'content_types'        => array( 'post', 'page' ),
				'include_taxonomies'   => true,
				'include_users'        => true,
				'include_media'        => false,
				'auto_rebuild'         => 'manual',
				'scheduled_frequency'  => 'weekly',
				'visualization_lib'    => 'cytoscape',
				'max_vis_nodes'        => 2000,
				'community_algorithm'  => 'louvain',
				'include_semantic'     => false,
				'semantic_batch_size'  => 10,
			)
		);
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
			'NV_oOS_Graphify_Tool_Graph_Stats',
			'NV_oOS_Graphify_Tool_Query_Graph',
			'NV_oOS_Graphify_Tool_Get_Node',
			'NV_oOS_Graphify_Tool_Get_Neighbors',
			'NV_oOS_Graphify_Tool_Get_Community',
			'NV_oOS_Graphify_Tool_God_Nodes',
			'NV_oOS_Graphify_Tool_Shortest_Path',
			'NV_oOS_Graphify_Tool_Suggest_Links',
			'NV_oOS_Graphify_Tool_Content_Gaps',
		);

		foreach ( $tools as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Load tools via the Pro-style lazy loading hook.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function load_tools() {
		if ( ! self::is_enabled() ) {
			return;
		}

		self::load_tool_files();

		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' )
			? wp_mcp_ai_get_tool_registry()
			: null;

		if ( ! $registry ) {
			return;
		}

		$tools = array(
			'NV_oOS_Graphify_Tool_Build_Graph',
			'NV_oOS_Graphify_Tool_Graph_Stats',
			'NV_oOS_Graphify_Tool_Query_Graph',
			'NV_oOS_Graphify_Tool_Get_Node',
			'NV_oOS_Graphify_Tool_Get_Neighbors',
			'NV_oOS_Graphify_Tool_Get_Community',
			'NV_oOS_Graphify_Tool_God_Nodes',
			'NV_oOS_Graphify_Tool_Shortest_Path',
			'NV_oOS_Graphify_Tool_Suggest_Links',
			'NV_oOS_Graphify_Tool_Content_Gaps',
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

		require_once $dir . 'class-nvoos-graphify-tool-build-graph.php';
		require_once $dir . 'class-nvoos-graphify-tool-graph-stats.php';
		require_once $dir . 'class-nvoos-graphify-tool-query-graph.php';
		require_once $dir . 'class-nvoos-graphify-tool-get-node.php';
		require_once $dir . 'class-nvoos-graphify-tool-get-neighbors.php';
		require_once $dir . 'class-nvoos-graphify-tool-get-community.php';
		require_once $dir . 'class-nvoos-graphify-tool-god-nodes.php';
		require_once $dir . 'class-nvoos-graphify-tool-shortest-path.php';
		require_once $dir . 'class-nvoos-graphify-tool-suggest-links.php';
		require_once $dir . 'class-nvoos-graphify-tool-content-gaps.php';
	}

	/**
	 * Handle post save — queue incremental extraction.
	 *
	 * @since 0.1.0
	 *
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 * @return void
	 */
	public static function on_save_post( $post_id, $post ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		if ( wp_is_post_revision( $post_id ) || wp_is_post_autosave( $post_id ) ) {
			return;
		}

		$settings      = self::get_settings();
		$content_types = $settings['content_types'];
		if ( ! is_array( $content_types ) ) {
			$content_types = array( 'post', 'page' );
		}

		if ( ! in_array( $post->post_type, $content_types, true ) ) {
			return;
		}

		if ( 'publish' !== $post->post_status ) {
			return;
		}

		if ( 'on_save' === $settings['auto_rebuild'] ) {
			if ( ! wp_next_scheduled( 'nvoos_graphify_incremental_build', array( $post_id ) ) ) {
				wp_schedule_single_event( time() + 30, 'nvoos_graphify_incremental_build', array( $post_id ) );
			}
		}
	}

	/**
	 * Handle post deletion — remove node and edges.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function on_delete_post( $post_id ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		global $wpdb;
		$table_nodes = $wpdb->prefix . 'nvoos_graph_nodes';
		$table_edges = $wpdb->prefix . 'nvoos_graph_edges';

		// Find the node for this post.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$node = $wpdb->get_row(
			$wpdb->prepare(
				"SELECT node_id FROM %i WHERE source_id = %d AND source_type = 'post'",
				$table_nodes,
				$post_id
			)
		);

		if ( $node ) {
			// Remove edges connected to this node.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->query(
				$wpdb->prepare(
					"DELETE FROM %i WHERE source_node_id = %s OR target_node_id = %s",
					$table_edges,
					$node->node_id,
					$node->node_id
				)
			);

			// Remove the node.
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->delete(
				$table_nodes,
				array( 'node_id' => $node->node_id ),
				array( '%s' )
			);
		}
	}

	/**
	 * Handle taxonomy term changes.
	 *
	 * @since 0.1.0
	 *
	 * @param int    $object_id  Object ID.
	 * @param array  $terms      Array of term IDs.
	 * @param array  $tt_ids     Array of term taxonomy IDs.
	 * @param string $taxonomy   Taxonomy slug.
	 * @return void
	 */
	public static function on_set_object_terms( $object_id, $terms, $tt_ids, $taxonomy ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$settings = self::get_settings();
		if ( 'on_save' === $settings['auto_rebuild'] ) {
			if ( ! wp_next_scheduled( 'nvoos_graphify_incremental_build', array( $object_id ) ) ) {
				wp_schedule_single_event( time() + 30, 'nvoos_graphify_incremental_build', array( $object_id ) );
			}
		}
	}

	/**
	 * Maybe schedule the recurring cron job.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	private static function maybe_schedule_cron() {
		$settings = self::get_settings();

		if ( 'scheduled' !== $settings['auto_rebuild'] ) {
			wp_clear_scheduled_hook( 'nvoos_graphify_scheduled_rebuild' );
			return;
		}

		if ( ! wp_next_scheduled( 'nvoos_graphify_scheduled_rebuild' ) ) {
			$frequency = $settings['scheduled_frequency'];
			if ( ! in_array( $frequency, array( 'daily', 'weekly' ), true ) ) {
				$frequency = 'weekly';
			}
			wp_schedule_event( time() + HOUR_IN_SECONDS, $frequency, 'nvoos_graphify_scheduled_rebuild' );
		}
	}

	/**
	 * Run a full scheduled rebuild.
	 *
	 * @since 0.1.0
	 *
	 * @return void
	 */
	public static function run_scheduled_rebuild() {
		if ( ! self::is_enabled() ) {
			return;
		}

		$builder = new NV_oOS_Graphify_Builder();
		$builder->build_full();
	}

	/**
	 * Run an incremental build for a specific post.
	 *
	 * @since 0.1.0
	 *
	 * @param int $post_id Post ID.
	 * @return void
	 */
	public static function run_incremental_build( $post_id = 0 ) {
		if ( ! self::is_enabled() ) {
			return;
		}

		$builder = new NV_oOS_Graphify_Builder();
		if ( $post_id > 0 ) {
			$builder->build_incremental( $post_id );
		} else {
			$builder->build_full();
		}
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
