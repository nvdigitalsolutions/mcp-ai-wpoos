<?php
/**
 * Site Node Registry — auto-discovers / registers / executes site-building nodes.
 *
 * This is the site-builder analogue of WP_MCP_AI_Tool_Registry.
 * Nodes implementing WP_MCP_AI_Site_Node_Interface are auto-discovered
 * from registered directories (ComfyUI-style custom_nodes/ scanning)
 * and the 'wp_mcp_ai_register_site_nodes' action hook.
 *
 * @package    WP_MCP_AI
 * @subpackage Site_Builder
 * @since      1.2.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Site_Node_Registry' ) ) :

	/**
	 * Registry for all registered site-building nodes.
	 *
	 * Singleton. Call WP_MCP_AI_Site_Node_Registry::get_instance() to obtain
	 * the shared instance. Nodes are registered either by hooking into the
	 * 'wp_mcp_ai_register_site_nodes' action or by placing PHP class files
	 * in the `includes/site-builder/nodes/` directory.
	 */
	class WP_MCP_AI_Site_Node_Registry {

		/**
		 * Singleton instance.
		 *
		 * @var self|null
		 */
		protected static $instance = null;

		/**
		 * Registered nodes keyed by slug.
		 *
		 * @var WP_MCP_AI_Site_Node_Interface[]
		 */
		protected $nodes = array();

		/**
		 * Whether the registry has been bootstrapped.
		 *
		 * @var bool
		 */
		protected $bootstrapped = false;

		/**
		 * Get the singleton instance (lazy init).
		 *
		 * @return self
		 */
		public static function get_instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Prevent direct construction.
		 */
		protected function __construct() {}

		/**
		 * Prevent cloning.
		 */
		protected function __clone() {}

		/**
		 * Prevent unserialisation.
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialise singleton' );
		}

		/**
		 * Bootstrap the registry — load built-in nodes and fire the
		 * registration hook. Idempotent (safe to call multiple times).
		 *
		 * @since 1.2.0
		 * @return void
		 */
		public function init() {
			if ( $this->bootstrapped ) {
				return;
			}
			$this->bootstrapped = true;

			$this->load_default_nodes();

			/**
			 * Allow third-party plugins and addons to register additional
			 * site-building nodes. This is the ComfyUI custom_nodes/ equivalent.
			 *
			 * @since 1.2.0
			 *
			 * @param WP_MCP_AI_Site_Node_Registry $registry The registry instance.
			 */
			do_action( 'wp_mcp_ai_register_site_nodes', $this );
		}

		/**
		 * Load the built-in site nodes shipped with the plugin.
		 *
		 * Each entry is 'ClassName' => 'relative/file/path.php'.
		 * The filter 'wp_mcp_ai_default_site_nodes' allows addons to inject
		 * additional nodes without modifying this file.
		 *
		 * @since 1.2.0
		 * @return void
		 */
		protected function load_default_nodes() {
			$default_nodes = array(
				// Source nodes
				'WP_MCP_AI_Site_Node_WP_Query'      => WP_MCP_AI_PATH . 'includes/site-builder/nodes/class-wp-mcp-ai-site-node-wp-query.php',
				// Layout nodes
				'WP_MCP_AI_Site_Node_Text_Block'     => WP_MCP_AI_PATH . 'includes/site-builder/nodes/class-wp-mcp-ai-site-node-text-block.php',
				'WP_MCP_AI_Site_Node_Flex_Container' => WP_MCP_AI_PATH . 'includes/site-builder/nodes/class-wp-mcp-ai-site-node-flex-container.php',
			);

			/**
			 * Filter the list of default site nodes to load.
			 *
			 * @since 1.2.0
			 *
			 * @param array $default_nodes Associative array of class names => file paths.
			 */
			$default_nodes = apply_filters( 'wp_mcp_ai_default_site_nodes', $default_nodes );

			foreach ( $default_nodes as $class_name => $file_path ) {
				if ( ! file_exists( $file_path ) ) {
					continue;
				}

				require_once $file_path;

				if ( ! class_exists( $class_name ) ) {
					continue;
				}

				$node = new $class_name();

				if ( ! $node instanceof WP_MCP_AI_Site_Node_Interface ) {
					continue;
				}

				$this->register_node( $node );
			}
		}

		/**
		 * Register a single site node.
		 *
		 * Overwrites any existing node with the same slug (last-registered-wins).
		 *
		 * @since 1.2.0
		 *
		 * @param WP_MCP_AI_Site_Node_Interface $node Node instance to register.
		 * @return void
		 */
		public function register_node( WP_MCP_AI_Site_Node_Interface $node ) {
			$slug = $node->get_slug();
			$this->nodes[ $slug ] = $node;
		}

		/**
		 * Get a registered node by slug.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug Node slug.
		 * @return WP_MCP_AI_Site_Node_Interface|null
		 */
		public function get_node( string $slug ) {
			return $this->nodes[ $slug ] ?? null;
		}

		/**
		 * Get all registered nodes.
		 *
		 * @since 1.2.0
		 *
		 * @return WP_MCP_AI_Site_Node_Interface[]
		 */
		public function get_nodes(): array {
			return $this->nodes;
		}

		/**
		 * Get nodes filtered by category.
		 *
		 * @since 1.2.0
		 *
		 * @param string $category One of: source, layout, style, transform, output, integration.
		 * @return WP_MCP_AI_Site_Node_Interface[]
		 */
		public function get_nodes_by_category( string $category ): array {
			return array_filter( $this->nodes, function ( $node ) use ( $category ) {
				return $node->get_category() === $category;
			} );
		}

		/**
		 * Get all registered nodes as a JSON-serialisable array suitable
		 * for consumption by the React front-end node palette.
		 *
		 * @since 1.2.0
		 *
		 * @return array[]
		 */
		public function get_nodes_for_frontend(): array {
			$result = array();
			foreach ( $this->nodes as $slug => $node ) {
				$result[] = array(
					'slug'        => $slug,
					'name'        => $node->get_name(),
					'description' => $node->get_description(),
					'category'    => $node->get_category(),
					'inputs'      => $node->get_inputs(),
					'outputs'     => $node->get_outputs(),
				);
			}

			// Stable ordering for the UI: sort by category then name.
			usort( $result, function ( $a, $b ) {
				$cat = strcmp( $a['category'], $b['category'] );
				return 0 !== $cat ? $cat : strcmp( $a['name'], $b['name'] );
			} );

			return $result;
		}

		/**
		 * Execute a node by slug with the given inputs.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug   Node slug.
		 * @param array  $inputs Associative array of input values keyed by port name.
		 * @return array|WP_Error Output values or error.
		 */
		public function execute_node( string $slug, array $inputs ) {
			$node = $this->get_node( $slug );
			if ( ! $node ) {
				return new WP_Error(
					'wp_mcp_ai_site_node_not_found',
					sprintf(
						/* translators: %s: node slug */
						__( 'Site node "%s" is not registered.', 'mcp-ai-wpoos' ),
						esc_html( $slug )
					)
				);
			}

			return $node->execute( $inputs );
		}

		/**
		 * Check whether a node slug is registered.
		 *
		 * @since 1.2.0
		 *
		 * @param string $slug Node slug.
		 * @return bool
		 */
		public function has_node( string $slug ): bool {
			return isset( $this->nodes[ $slug ] );
		}
	}

endif;
