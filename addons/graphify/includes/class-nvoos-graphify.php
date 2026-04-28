<?php
/**
 * NV oOS Graphify — Core Singleton
 *
 * Handles plugin lifecycle: activation, deactivation, settings management,
 * tool registration, auto-rebuild hooks (save_post / WP Cron / manual),
 * shortcode, Gutenberg block, Schema.org JSON-LD injection, and
 * related-content widget.
 *
 * @package NV_oOS_Graphify
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Core singleton for the NV oOS Graphify addon.
 *
 * @since 0.5.0
 */
class NV_oOS_Graphify {

	/**
	 * WordPress option key for addon settings.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'nvoos_graphify_settings';

	/**
	 * Cron hook for scheduled full builds.
	 *
	 * @var string
	 */
	const CRON_BUILD_HOOK = 'nvoos_graphify_cron_build';

	/**
	 * Cron hook for scheduled remote enrichment.
	 *
	 * @var string
	 */
	const CRON_ENRICH_HOOK = 'nvoos_graphify_cron_enrich';

	// -------------------------------------------------------------------------
	// Boot
	// -------------------------------------------------------------------------

	/**
	 * Register all WordPress hooks.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function init() {
		add_action( 'admin_notices', array( __CLASS__, 'admin_notices' ) );
		add_action( 'plugins_loaded', array( __CLASS__, 'on_plugins_loaded' ) );
		add_action( 'init', array( __CLASS__, 'register_shortcodes' ) );
		add_action( 'init', array( __CLASS__, 'register_block' ) );
		add_action( 'wp_enqueue_scripts', array( __CLASS__, 'enqueue_frontend_assets' ) );
		add_action( self::CRON_BUILD_HOOK, array( __CLASS__, 'run_scheduled_build' ) );
		add_action( self::CRON_ENRICH_HOOK, array( __CLASS__, 'run_scheduled_enrich' ) );
		add_action( 'nvoos_graphify_cron_semantic_extract', array( 'NV_oOS_Graphify_Semantic_Extractor', 'handle_cron_batch' ) );
	}

	/**
	 * Fired on plugins_loaded — verify base plugin and register tools.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function on_plugins_loaded() {
		if ( ! nvoos_graphify_is_base_active() ) {
			return;
		}

		// Upgrade DB schema if needed.
		$installed_ver = get_option( 'nvoos_graphify_db_version', '0' );
		if ( $installed_ver !== NVOOS_GRAPHIFY_DB_VERSION ) {
			NV_oOS_Graphify_DB::upgrade();
		}

		// Register default remote source drivers.
		add_action( 'nvoos_graphify_register_remote_sources', array( __CLASS__, 'register_default_drivers' ) );

		// Register REST controller.
		NV_oOS_Graphify_REST::init();

		// Register tools with the oOS tool registry.
		add_action( 'wp_mcp_ai_register_tools', array( __CLASS__, 'register_tools' ) );
		add_action( 'wp_mcp_ai_load_pro_tools', array( __CLASS__, 'load_tools' ) );

		// Auto-rebuild on post save.
		$settings = self::get_settings();
		if ( ! empty( $settings['auto_rebuild'] ) ) {
			add_action( 'save_post', array( __CLASS__, 'on_save_post' ), 20 );
		}

		// Schema.org JSON-LD injection.
		if ( ! empty( $settings['schema_injection'] ) ) {
			add_action( 'wp_head', array( __CLASS__, 'inject_schema_org' ) );
		}

		// Related content widget.
		if ( ! empty( $settings['related_content'] ) ) {
			add_filter( 'the_content', array( __CLASS__, 'append_related_content' ) );
		}

		// Admin settings class.
		if ( is_admin() ) {
			NV_oOS_Graphify_Settings::init();
			NV_oOS_Graphify_Remote_Admin::init();
		}
	}

	/**
	 * Run the scheduled remote enrichment.
	 *
	 * @since 0.6.0
	 *
	 * @return void
	 */
	public static function run_scheduled_enrich() {
		$enricher = new NV_oOS_Graphify_Remote_Enricher();
		$enricher->enrich_all( false );
	}

	/**
	 * Register default remote source drivers with the registry.
	 *
	 * @since 0.6.0
	 *
	 * @param NV_oOS_Graphify_Remote_Registry $registry Driver registry.
	 * @return void
	 */
	public static function register_default_drivers( $registry ) {
		$registry->register_driver( new NV_oOS_Graphify_Remote_Wikidata() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_OOS_Federation() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_Generic_REST() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_RSS_Sitemap() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_SPARQL() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_WooCommerce() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_CSV() );
		$registry->register_driver( new NV_oOS_Graphify_Remote_Webhook() );
	}

	// -------------------------------------------------------------------------
	// Activation / deactivation
	// -------------------------------------------------------------------------

	/**
	 * Run on plugin activation: install DB schema and schedule cron.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function activate() {
		NV_oOS_Graphify_DB::install();
		self::schedule_cron();
		set_transient( 'nvoos_graphify_activated', true, 30 );
	}

	/**
	 * Run on plugin deactivation: clear cron schedule.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function deactivate() {
		$timestamp = wp_next_scheduled( self::CRON_BUILD_HOOK );
		if ( $timestamp ) {
			wp_unschedule_event( $timestamp, self::CRON_BUILD_HOOK );
		}
	}

	/**
	 * Schedule the periodic rebuild cron event.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	private static function schedule_cron() {
		if ( ! wp_next_scheduled( self::CRON_BUILD_HOOK ) ) {
			$settings = self::get_settings();
			$interval = ! empty( $settings['rebuild_schedule'] ) ? sanitize_key( $settings['rebuild_schedule'] ) : 'daily';
			wp_schedule_event( time(), $interval, self::CRON_BUILD_HOOK );
		}
	}

	/**
	 * Run the scheduled full rebuild.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function run_scheduled_build() {
		$settings = self::get_settings();
		NV_oOS_Graphify_Builder::build(
			array(
				'incremental'    => ! empty( $settings['incremental_builds'] ),
				'semantic'       => ! empty( $settings['semantic_extraction'] ),
				'async_semantic' => true,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Settings
	// -------------------------------------------------------------------------

	/**
	 * Check whether the addon is enabled.
	 *
	 * @since 0.5.0
	 *
	 * @return bool
	 */
	public static function is_enabled() {
		$settings = get_option( self::OPTION_KEY, array() );
		return ! isset( $settings['enabled'] ) || ! empty( $settings['enabled'] );
	}

	/**
	 * Return addon settings merged with defaults.
	 *
	 * @since 0.5.0
	 *
	 * @return array
	 */
	public static function get_settings() {
		return wp_parse_args(
			get_option( self::OPTION_KEY, array() ),
			array(
				'enabled'              => true,
				'post_types'           => NV_oOS_Graphify_Detector::get_default_post_types(),
				'semantic_extraction'  => true,
				'incremental_builds'   => true,
				'auto_rebuild'         => false,
				'rebuild_schedule'     => 'daily',
				'schema_injection'     => true,
				'related_content'      => true,
				'max_related'          => 5,
				'openai_api_key'       => '',
				'cytoscape_height'     => '600px',
				'max_display_nodes'    => 300,
				'remote_enrich_enabled' => false,
				'remote_enrich_budget' => 50,
				'embeddings_enabled'   => false,
				'embeddings_model'     => 'text-embedding-3-small',
				'remote_enrich_async'  => true,
			)
		);
	}

	// -------------------------------------------------------------------------
	// Auto-rebuild on post save
	// -------------------------------------------------------------------------

	/**
	 * Trigger an incremental rebuild when a post is saved.
	 *
	 * Runs via WP Cron to avoid slowing down the save request.
	 *
	 * @since 0.5.0
	 *
	 * @param int $post_id Saved post ID.
	 * @return void
	 */
	public static function on_save_post( $post_id ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( wp_is_post_revision( $post_id ) ) {
			return;
		}
		$post = get_post( $post_id );
		if ( ! $post || 'publish' !== $post->post_status ) {
			return;
		}
		wp_schedule_single_event( time() + 5, self::CRON_BUILD_HOOK );
	}

	// -------------------------------------------------------------------------
	// Tool registration
	// -------------------------------------------------------------------------

	/**
	 * Register Graphify tools with the oOS tool registry.
	 *
	 * @since 0.5.0
	 *
	 * @param object $registry WP_MCP_AI_Tool_Registry instance.
	 * @return void
	 */
	public static function register_tools( $registry ) {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::load_tool_files();
		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Register tools via the Pro-style lazy loading hook.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function load_tools() {
		if ( ! self::is_enabled() ) {
			return;
		}
		self::load_tool_files();
		$registry = function_exists( 'wp_mcp_ai_get_tool_registry' ) ? wp_mcp_ai_get_tool_registry() : null;
		if ( ! $registry ) {
			return;
		}
		foreach ( self::get_tool_classes() as $class ) {
			if ( class_exists( $class ) ) {
				$registry->register_tool( new $class() );
			}
		}
	}

	/**
	 * Return the list of tool class names.
	 *
	 * @since 0.5.0
	 *
	 * @return string[]
	 */
	private static function get_tool_classes() {
		return array(
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
			'NV_oOS_Graphify_Tool_Retrieve_Context',
			'NV_oOS_Graphify_Tool_Resolve_External',
			'NV_oOS_Graphify_Tool_Sync_Remote_Source',
			'NV_oOS_Graphify_Tool_List_Remote_Sources',
		);
	}

	/**
	 * Require all tool class files (idempotent).
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	private static function load_tool_files() {
		static $loaded = false;
		if ( $loaded ) {
			return;
		}
		$loaded = true;
		$dir    = NVOOS_GRAPHIFY_PATH . 'includes/tools/';
		$files  = array(
			'class-nvoos-graphify-tool-build-graph.php',
			'class-nvoos-graphify-tool-graph-stats.php',
			'class-nvoos-graphify-tool-query-graph.php',
			'class-nvoos-graphify-tool-get-node.php',
			'class-nvoos-graphify-tool-get-neighbors.php',
			'class-nvoos-graphify-tool-get-community.php',
			'class-nvoos-graphify-tool-god-nodes.php',
			'class-nvoos-graphify-tool-shortest-path.php',
			'class-nvoos-graphify-tool-suggest-links.php',
			'class-nvoos-graphify-tool-content-gaps.php',
			'class-nvoos-graphify-tool-retrieve-context.php',
			'class-nvoos-graphify-tool-resolve-external.php',
			'class-nvoos-graphify-tool-sync-remote-source.php',
			'class-nvoos-graphify-tool-list-remote-sources.php',
		);
		foreach ( $files as $file ) {
			require_once $dir . $file;
		}
	}

	// -------------------------------------------------------------------------
	// Shortcode
	// -------------------------------------------------------------------------

	/**
	 * Register the [nvoos_graphify] shortcode.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function register_shortcodes() {
		add_shortcode( 'nvoos_graphify', array( __CLASS__, 'render_shortcode' ) );
	}

	/**
	 * Render the graph shortcode.
	 *
	 * Attributes:
	 *   mode         = full|community|ego  (default: full)
	 *   community_id = community slug      (for mode=community)
	 *   post_id      = int                 (for mode=ego)
	 *   height       = CSS height string   (default: 600px)
	 *   max_nodes    = int                 (default: 300)
	 *
	 * @since 0.5.0
	 *
	 * @param array $atts Shortcode attributes.
	 * @return string HTML output.
	 */
	public static function render_shortcode( $atts ) {
		$atts = shortcode_atts(
			array(
				'mode'         => 'full',
				'community_id' => '',
				'post_id'      => 0,
				'height'       => '600px',
				'max_nodes'    => 300,
			),
			$atts,
			'nvoos_graphify'
		);

		$mode         = sanitize_key( $atts['mode'] );
		$community_id = sanitize_text_field( $atts['community_id'] );
		$post_id      = absint( $atts['post_id'] );
		$height       = sanitize_text_field( $atts['height'] );
		$max_nodes    = max( 10, min( 2000, absint( $atts['max_nodes'] ) ) );

		$container_id = 'nvoos-graphify-' . wp_unique_id();

		// Cytoscape.js + fcose layout (bundled locally — see assets/vendor/).
		// Load order: layout-base → cose-base → cytoscape → cytoscape-fcose.
		wp_enqueue_script(
			'layout-base',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/layout-base/layout-base.js',
			array(),
			'2.0.1',
			true
		);
		wp_enqueue_script(
			'cose-base',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/cose-base/cose-base.js',
			array( 'layout-base' ),
			'2.2.0',
			true
		);
		wp_enqueue_script(
			'cytoscape',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/cytoscape/cytoscape.min.js',
			array(),
			'3.28.1',
			true
		);
		wp_enqueue_script(
			'cytoscape-fcose',
			NVOOS_GRAPHIFY_URL . 'assets/vendor/cytoscape-fcose/cytoscape-fcose.js',
			array( 'cytoscape', 'cose-base' ),
			'2.2.0',
			true
		);

		wp_enqueue_script(
			'nvoos-graphify-frontend',
			NVOOS_GRAPHIFY_URL . 'assets/js/graphify-frontend.js',
			array( 'jquery', 'cytoscape', 'cytoscape-fcose' ),
			NVOOS_GRAPHIFY_VERSION,
			true
		);
		wp_enqueue_style(
			'nvoos-graphify-frontend',
			NVOOS_GRAPHIFY_URL . 'assets/css/graphify-frontend.css',
			array(),
			NVOOS_GRAPHIFY_VERSION
		);

		wp_localize_script(
			'nvoos-graphify-frontend',
			'nvoosGraphifyData_' . str_replace( '-', '_', $container_id ),
			array(
				'container'    => $container_id,
				'mode'         => $mode,
				'community_id' => $community_id,
				'post_id'      => $post_id,
				'max_nodes'    => $max_nodes,
				'rest_url'     => esc_url_raw( rest_url( 'nvoos-graphify/v1' ) ),
				'nonce'        => wp_create_nonce( 'wp_rest' ),
			)
		);

		return '<div id="' . esc_attr( $container_id ) . '" class="nvoos-graphify-embed" style="height:' . esc_attr( $height ) . ';"></div>';
	}

	// -------------------------------------------------------------------------
	// Gutenberg block
	// -------------------------------------------------------------------------

	/**
	 * Register the nvoos-graphify/graph Gutenberg block (server-side rendered).
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function register_block() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}
		register_block_type(
			'nvoos-graphify/graph',
			array(
				'attributes'      => array(
					'mode'         => array( 'type' => 'string', 'default' => 'full' ),
					'community_id' => array( 'type' => 'string', 'default' => '' ),
					'post_id'      => array( 'type' => 'integer', 'default' => 0 ),
					'height'       => array( 'type' => 'string', 'default' => '600px' ),
					'max_nodes'    => array( 'type' => 'integer', 'default' => 300 ),
				),
				'render_callback' => array( __CLASS__, 'render_block' ),
			)
		);
	}

	/**
	 * Server-side render callback for the Gutenberg block.
	 *
	 * @since 0.5.0
	 *
	 * @param array $attributes Block attributes.
	 * @return string HTML output.
	 */
	public static function render_block( $attributes ) {
		return self::render_shortcode( $attributes );
	}

	// -------------------------------------------------------------------------
	// Frontend assets
	// -------------------------------------------------------------------------

	/**
	 * Enqueue frontend assets when the shortcode or block is active.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function enqueue_frontend_assets() {
		// Assets are enqueued lazily inside render_shortcode(); nothing global needed.
	}

	// -------------------------------------------------------------------------
	// Schema.org JSON-LD injection
	// -------------------------------------------------------------------------

	/**
	 * Inject Schema.org JSON-LD for the current singular view using graph data.
	 *
	 * Adds:
	 *   about       → taxonomy terms linked to this post
	 *   relatedLink → posts linked from this post in the graph
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function inject_schema_org() {
		if ( ! is_singular() ) {
			return;
		}
		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return;
		}

		$node = NV_oOS_Graphify_DB::get_node_by_post_id( $post_id );
		if ( ! $node ) {
			return;
		}

		$edges = NV_oOS_Graphify_DB::get_edges_for_node( $node->node_id );

		$about        = array();
		$related_links = array();

		foreach ( $edges as $edge ) {
			// Taxonomy terms → about.
			if ( in_array( $edge->relation, array( 'CATEGORIZED_BY', 'TAGGED_WITH' ), true ) ) {
				$target_node = NV_oOS_Graphify_DB::get_node( $edge->target_node_id );
				if ( $target_node ) {
					$about[] = array(
						'@type' => 'Thing',
						'name'  => esc_html( $target_node->label ),
						'url'   => esc_url( $target_node->url ),
					);
				}
			}
			// Internal links → relatedLink.
			if ( 'LINKS_TO' === $edge->relation && $edge->source_node_id === $node->node_id ) {
				$target_node = NV_oOS_Graphify_DB::get_node( $edge->target_node_id );
				if ( $target_node && $target_node->url ) {
					$related_links[] = esc_url( $target_node->url );
				}
			}
		}

		if ( empty( $about ) && empty( $related_links ) ) {
			return;
		}

		$schema = array(
			'@context' => 'https://schema.org',
			'@type'    => 'WebPage',
			'url'      => esc_url( get_permalink( $post_id ) ),
		);
		if ( $about ) {
			$schema['about'] = $about;
		}
		if ( $related_links ) {
			$schema['relatedLink'] = $related_links;
		}

		echo '<script type="application/ld+json">' . wp_json_encode( $schema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) . "</script>\n"; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON-LD is intentionally unescaped for valid JSON syntax; all values are individually escaped above.
	}

	// -------------------------------------------------------------------------
	// Related content widget
	// -------------------------------------------------------------------------

	/**
	 * Append top-5 graph-neighbor posts to singular content.
	 *
	 * @since 0.5.0
	 *
	 * @param string $content Post content.
	 * @return string Modified content.
	 */
	public static function append_related_content( $content ) {
		if ( ! is_singular() || ! in_the_loop() || ! is_main_query() ) {
			return $content;
		}

		$post_id = get_the_ID();
		if ( ! $post_id ) {
			return $content;
		}

		$node = NV_oOS_Graphify_DB::get_node_by_post_id( $post_id );
		if ( ! $node ) {
			return $content;
		}

		$settings    = self::get_settings();
		$max_related = max( 1, min( 10, absint( $settings['max_related'] ) ) );

		$neighbor_ids = NV_oOS_Graphify_DB::get_neighbor_ids( $node->node_id );
		if ( empty( $neighbor_ids ) ) {
			return $content;
		}

		$neighbors  = array_slice( $neighbor_ids, 0, $max_related );
		$post_nodes = array();

		foreach ( $neighbors as $nid ) {
			$n = NV_oOS_Graphify_DB::get_node( $nid );
			if ( $n && $n->post_id && $n->url && in_array( $n->type, array( 'post', 'page' ), true ) ) {
				$post_nodes[] = $n;
			}
		}

		if ( empty( $post_nodes ) ) {
			return $content;
		}

		$widget  = '<div class="nvoos-graphify-related">';
		$widget .= '<h3>' . esc_html__( 'Related Content', 'nvoos-graphify' ) . '</h3><ul>';
		foreach ( $post_nodes as $n ) {
			$widget .= '<li><a href="' . esc_url( $n->url ) . '">' . esc_html( $n->label ) . '</a></li>';
		}
		$widget .= '</ul></div>';

		return $content . $widget;
	}

	// -------------------------------------------------------------------------
	// Admin notices
	// -------------------------------------------------------------------------

	/**
	 * Display admin notices.
	 *
	 * @since 0.5.0
	 *
	 * @return void
	 */
	public static function admin_notices() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		if ( get_transient( 'nvoos_graphify_activated' ) ) {
			delete_transient( 'nvoos_graphify_activated' );
			echo '<div class="notice notice-success is-dismissible"><p>';
			esc_html_e( 'NV oOS Graphify activated. Visit the Knowledge Graph settings to build your first graph.', 'nvoos-graphify' );
			echo '</p></div>';
			return;
		}

		if ( ! nvoos_graphify_is_base_active() ) {
			echo '<div class="notice notice-warning is-dismissible"><p>';
			esc_html_e( 'NV oOS Graphify requires the NV oOS base plugin to be installed and active.', 'nvoos-graphify' );
			echo '</p></div>';
		}
	}
}
