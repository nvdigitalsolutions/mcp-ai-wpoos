<?php
/**
 * Site Node Registry (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Node_Registry`:
 * byte-identical singleton lifecycle (lazy `get_instance()`, protected
 * constructor/clone, unserialise exception), idempotent `init()` with
 * the `wp_mcp_ai_register_site_nodes` action, default-node loading via
 * the `wp_mcp_ai_default_site_nodes` filter, last-registered-wins
 * `register_node()`, slug lookups, category filtering, the stable
 * category→name front-end palette serialisation, `execute_node()` with
 * the `wp_mcp_ai_site_node_not_found` envelope, and `has_node()`.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - The built-in node map resolves per install mode via
 *    `default_nodes()`: base `WP_MCP_AI_Site_Node_*` classes with their
 *    `WP_MCP_AI_PATH` file paths monolith (byte-identical), this
 *    package's `SiteBuilder\Nodes\*` classes with their PSR-4 file
 *    paths standalone. A compile-time constant cannot express this.
 *  - `register_node()` drops the base interface type-hint and validates
 *    through the per-mode `is_node_instance()` seam instead — the two
 *    install modes have different interface classes (base
 *    `WP_MCP_AI_Site_Node_Interface` monolith / this package's
 *    `SiteNodeInterface` standalone), so a fixed hint would reject
 *    monolith-loaded base nodes. Non-conforming objects are skipped
 *    fail-soft where the base's hint would TypeError (documented
 *    hardening).
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder;

/**
 * Registry for all registered site-building nodes.
 *
 * Singleton. Call SiteNodeRegistry::get_instance() to obtain the shared
 * instance. Nodes are registered either by hooking into the
 * 'wp_mcp_ai_register_site_nodes' action or through the built-in node
 * map resolved per install mode.
 *
 * @since 2.1.0
 */
class SiteNodeRegistry {

	/**
	 * Singleton instance.
	 *
	 * @var self|null
	 */
	protected static $instance = null;

	/**
	 * Registered nodes keyed by slug.
	 *
	 * @var SiteNodeInterface[]
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
	 *
	 * @throws \Exception Always.
	 */
	public function __wakeup() {
		throw new \Exception( 'Cannot unserialise singleton' );
	}

	/**
	 * Bootstrap the registry — load built-in nodes and fire the
	 * registration hook. Idempotent (safe to call multiple times).
	 *
	 * @since 2.1.0
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
		 * @since 2.1.0
		 *
		 * @param SiteNodeRegistry $registry The registry instance.
		 */
		do_action( 'wp_mcp_ai_register_site_nodes', $this );
	}

	/**
	 * Resolve the built-in site node map per install mode.
	 *
	 * Each entry is 'ClassName' => 'relative/file/path.php'. Monolith
	 * installs load the base plugin's node classes byte-identically;
	 * standalone installs load this package's node classes.
	 *
	 * @since 2.1.0
	 *
	 * @return array<string,string> Class name => absolute file path.
	 */
	protected function default_nodes(): array {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return array(
				// Source nodes.
				'WP_MCP_AI_Site_Node_WP_Query'       => WP_MCP_AI_PATH . 'includes/site-builder/nodes/class-wp-mcp-ai-site-node-wp-query.php',
				// Layout nodes.
				'WP_MCP_AI_Site_Node_Text_Block'     => WP_MCP_AI_PATH . 'includes/site-builder/nodes/class-wp-mcp-ai-site-node-text-block.php',
				'WP_MCP_AI_Site_Node_Flex_Container' => WP_MCP_AI_PATH . 'includes/site-builder/nodes/class-wp-mcp-ai-site-node-flex-container.php',
			);
		}

		return array(
			// Source nodes.
			Nodes\SiteNodeWpQuery::class       => __DIR__ . '/Nodes/SiteNodeWpQuery.php',
			// Layout nodes.
			Nodes\SiteNodeTextBlock::class     => __DIR__ . '/Nodes/SiteNodeTextBlock.php',
			Nodes\SiteNodeFlexContainer::class => __DIR__ . '/Nodes/SiteNodeFlexContainer.php',
		);
	}

	/**
	 * Load the built-in site nodes shipped with the plugin.
	 *
	 * The filter 'wp_mcp_ai_default_site_nodes' allows addons to inject
	 * additional nodes without modifying this file.
	 *
	 * @since 2.1.0
	 * @return void
	 */
	protected function load_default_nodes() {
		$default_nodes = $this->default_nodes();

		/**
		 * Filter the list of default site nodes to load.
		 *
		 * @since 2.1.0
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

			if ( ! $this->is_node_instance( $node ) ) {
				continue;
			}

			$this->register_node( $node );
		}
	}

	/**
	 * Whether an object satisfies the node interface for this install
	 * mode.
	 *
	 * Monolith installs accept the base plugin's node interface
	 * (byte-identical); standalone installs accept this package's
	 * interface. The discriminator is `defined( 'WP_MCP_AI_PATH' )` —
	 * never bare `instanceof` — because the two modes have different
	 * interface classes.
	 *
	 * @param object $candidate Candidate node instance.
	 * @return bool
	 */
	protected function is_node_instance( $candidate ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $candidate instanceof \WP_MCP_AI_Site_Node_Interface;
		}

		return $candidate instanceof SiteNodeInterface;
	}

	/**
	 * Register a single site node.
	 *
	 * Overwrites any existing node with the same slug (last-registered-wins).
	 *
	 * @since 2.1.0
	 *
	 * @param SiteNodeInterface $node Node instance to register.
	 * @return void
	 */
	public function register_node( $node ) {
		if ( ! $this->is_node_instance( $node ) ) {
			return;
		}

		$slug                 = $node->get_slug();
		$this->nodes[ $slug ] = $node;
	}

	/**
	 * Get a registered node by slug.
	 *
	 * @since 2.1.0
	 *
	 * @param string $slug Node slug.
	 * @return SiteNodeInterface|null
	 */
	public function get_node( string $slug ) {
		return $this->nodes[ $slug ] ?? null;
	}

	/**
	 * Get all registered nodes.
	 *
	 * @since 2.1.0
	 *
	 * @return SiteNodeInterface[]
	 */
	public function get_nodes(): array {
		return $this->nodes;
	}

	/**
	 * Get nodes filtered by category.
	 *
	 * @since 2.1.0
	 *
	 * @param string $category One of: source, layout, style, transform, output, integration.
	 * @return SiteNodeInterface[]
	 */
	public function get_nodes_by_category( string $category ): array {
		return array_filter(
			$this->nodes,
			function ( $node ) use ( $category ) {
				return $node->get_category() === $category;
			}
		);
	}

	/**
	 * Get all registered nodes as a JSON-serialisable array suitable
	 * for consumption by the React front-end node palette.
	 *
	 * @since 2.1.0
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
		usort(
			$result,
			function ( $a, $b ) {
				$cat = strcmp( $a['category'], $b['category'] );
				return 0 !== $cat ? $cat : strcmp( $a['name'], $b['name'] );
			}
		);

		return $result;
	}

	/**
	 * Execute a node by slug with the given inputs.
	 *
	 * @since 2.1.0
	 *
	 * @param string $slug   Node slug.
	 * @param array  $inputs Associative array of input values keyed by port name.
	 * @return array|\WP_Error Output values or error.
	 */
	public function execute_node( string $slug, array $inputs ) {
		$node = $this->get_node( $slug );
		if ( ! $node ) {
			return new \WP_Error(
				'wp_mcp_ai_site_node_not_found',
				sprintf(
					/* translators: %s: node slug */
					__( 'Site node "%s" is not registered.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $slug )
				)
			);
		}

		return $node->execute( $inputs );
	}

	/**
	 * Check whether a node slug is registered.
	 *
	 * @since 2.1.0
	 *
	 * @param string $slug Node slug.
	 * @return bool
	 */
	public function has_node( string $slug ): bool {
		return isset( $this->nodes[ $slug ] );
	}
}
