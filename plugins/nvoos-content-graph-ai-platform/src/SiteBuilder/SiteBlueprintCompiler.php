<?php
/**
 * Site Blueprint Compiler (Wave E4, sub-cluster 5).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Site_Blueprint_Compiler`:
 * byte-identical blueprint JSON contract (slug/name/internalGraph), the
 * `wp_mcp_ai_site_blueprint_directories` filter, sanitised-slug loading
 * with the sanitise-both-sides comparison quirk, required-key
 * validation, the slug listing + summary serialisation (with the
 * {node,port} output formatting), `{placeholder}` substitution with
 * defaults-over-provided input resolution, `{slug}__` internal node-ID
 * prefixing, the `wp_mcp_ai_site_blueprint_empty` envelope, the
 * in-memory blueprint cache, and `clear_cache()`.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - The base's `DEFAULT_DIR` class constant becomes the static
 *    `default_dir()` method resolving per install mode (base
 *    `WP_MCP_AI_PATH . 'config/site-blueprints/'` monolith, the
 *    platform addon's byte-identical blueprint copies standalone) — a
 *    compile-time constant cannot reference a mode-dependent path.
 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\SiteBuilder
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\SiteBuilder;

/**
 * Compiles sub-blueprint JSON into executable pipeline graphs.
 *
 * Usage:
 *   $compiler  = new SiteBlueprintCompiler();
 *   $blueprint = $compiler->load( 'hero-with-cta' );
 *   $graph     = $compiler->compile( $blueprint, [ 'heading' => 'Hello World' ] );
 *   $executor  = new SitePipelineExecutor();
 *   $result    = $executor->execute( $graph, 'hero-with-cta' );
 *
 * @since 2.1.0
 */
class SiteBlueprintCompiler {

	/**
	 * Loaded blueprints keyed by slug (in-memory cache).
	 *
	 * @since 2.1.0
	 * @var array<string,array>
	 */
	protected $blueprints = array();

	/**
	 * Registered blueprint directories.
	 *
	 * @since 2.1.0
	 * @var string[]|null
	 */
	protected $directories = null;

	/**
	 * Resolve the default blueprint directory per install mode.
	 *
	 * Monolith installs read the base plugin's blueprint tree
	 * (byte-identical); standalone installs read the platform addon's
	 * byte-identical blueprint copies.
	 *
	 * @since 2.1.0
	 *
	 * @return string Absolute path with trailing slash.
	 */
	protected static function default_dir(): string {
		return defined( 'WP_MCP_AI_PATH' )
			? WP_MCP_AI_PATH . 'config/site-blueprints/'
			: NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'config/site-blueprints/';
	}

	/**
	 * Get all registered blueprint directories.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public function get_directories(): array {
		if ( null === $this->directories ) {
			$dirs = array( self::default_dir() );

			/**
			 * Filter the list of directories to scan for site blueprint JSON files.
			 *
			 * Pro addons and third-party plugins can add their own directories.
			 *
			 * @since 2.1.0
			 *
			 * @param string[] $directories Absolute paths to blueprint directories.
			 */
			$dirs = apply_filters( 'wp_mcp_ai_site_blueprint_directories', $dirs );

			$this->directories = array_unique( array_filter( $dirs, 'is_dir' ) );
		}
		return $this->directories;
	}

	/**
	 * Load a blueprint by slug from registered directories.
	 *
	 * @since 2.1.0
	 *
	 * @param string $slug Blueprint slug (matches filename without .json).
	 * @return array|null Blueprint data or null if not found / invalid.
	 */
	public function load( string $slug ) {
		// Return cached copy if already loaded.
		if ( isset( $this->blueprints[ $slug ] ) ) {
			return $this->blueprints[ $slug ];
		}

		$slug = sanitize_file_name( $slug );

		foreach ( $this->get_directories() as $dir ) {
			$file = trailingslashit( $dir ) . $slug . '.json';
			if ( ! file_exists( $file ) ) {
				continue;
			}

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
			$json = file_get_contents( $file );
			if ( false === $json ) {
				continue;
			}

			$data = json_decode( $json, true );
			if ( JSON_ERROR_NONE !== json_last_error() || ! is_array( $data ) ) {
				continue;
			}

			// Validate required top-level keys.
			if ( ! $this->validate_blueprint_data( $data ) ) {
				continue;
			}

			// Normalise slug comparison: sanitize_file_name() can strip special
			// characters from the input slug (e.g. 'My-__Blueprint' → 'my-__blueprint'),
			// so compare sanitised versions of both sides to avoid false mismatches.
			if ( sanitize_file_name( $data['slug'] ) !== $slug ) {
				continue;
			}

			$this->blueprints[ $slug ] = $data;
			return $data;
		}

		return null;
	}

	/**
	 * Validate that a decoded blueprint has all required top-level keys.
	 *
	 * @since 2.1.0
	 *
	 * @param array $data Decoded blueprint JSON.
	 * @return bool True if valid, false otherwise.
	 */
	private function validate_blueprint_data( array $data ): bool {
		$required = array( 'slug', 'name', 'internalGraph' );
		foreach ( $required as $key ) {
			if ( ! isset( $data[ $key ] ) ) {
				return false;
			}
		}
		return true;
	}

	/**
	 * List all available blueprint slugs.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public function list_all(): array {
		$slugs = array();
		foreach ( $this->get_directories() as $dir ) {
			$files = glob( trailingslashit( $dir ) . '*.json' );
			if ( ! is_array( $files ) ) {
				continue;
			}
			foreach ( $files as $file ) {
				$slug = basename( $file, '.json' );
				// Only include if the file loads successfully.
				if ( null !== $this->load( $slug ) ) {
					$slugs[] = $slug;
				}
			}
		}
		sort( $slugs );
		return array_unique( $slugs );
	}

	/**
	 * Compile a loaded blueprint into an executable pipeline graph.
	 *
	 * Substitutes {placeholder} references in node inputs with the provided
	 * values (or blueprint defaults), then prefixes internal node IDs with
	 * the blueprint slug to avoid collisions when multiple blueprints are
	 * composed together.
	 *
	 * @since 2.1.0
	 *
	 * @param array $blueprint   Loaded blueprint data (from load()).
	 * @param array $input_values Associative array of input values keyed by port name.
	 * @return array|\WP_Error Pipeline graph ready for SitePipelineExecutor,
	 *                        or WP_Error on failure.
	 */
	public function compile( array $blueprint, array $input_values = array() ) {
		$slug     = $blueprint['slug'] ?? 'unknown';
		$internal = $blueprint['internalGraph'] ?? array();

		if ( empty( $internal['nodes'] ) ) {
			return new \WP_Error(
				'wp_mcp_ai_site_blueprint_empty',
				sprintf(
					/* translators: %s: blueprint slug */
					__( 'Blueprint "%s" contains no internal nodes.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $slug )
				)
			);
		}

		// Resolve input values: provided values × blueprint defaults.
		$resolved_inputs = $this->resolve_blueprint_inputs( $blueprint, $input_values );

		// Prefix internal node IDs with the blueprint slug to avoid collisions.
		$prefix = $slug . '__';

		// Compile nodes — substitute placeholders and prefix IDs.
		$compiled_nodes = array();
		foreach ( $internal['nodes'] as $node_id => $node_config ) {
			$prefixed_id = $prefix . $node_id;

			$compiled_inputs = array();
			if ( isset( $node_config['inputs'] ) && is_array( $node_config['inputs'] ) ) {
				foreach ( $node_config['inputs'] as $port => $value ) {
					if ( is_string( $value ) && '{' === substr( $value, 0, 1 ) ) {
						// Substitute {placeholder} with resolved input value.
						$placeholder              = trim( $value, '{}' );
						$compiled_inputs[ $port ] = $resolved_inputs[ $placeholder ] ?? $value;
					} else {
						$compiled_inputs[ $port ] = $value;
					}
				}
			}

			$compiled_nodes[ $prefixed_id ] = array(
				'slug'   => $node_config['slug'] ?? '',
				'inputs' => $compiled_inputs,
			);
		}

		// Compile edges — prefix source and target node IDs.
		$compiled_edges = array();
		if ( isset( $internal['edges'] ) && is_array( $internal['edges'] ) ) {
			foreach ( $internal['edges'] as $edge ) {
				$compiled_edges[] = array(
					'source'     => $prefix . ( $edge['source'] ?? '' ),
					'sourcePort' => $edge['sourcePort'] ?? '',
					'target'     => $prefix . ( $edge['target'] ?? '' ),
					'targetPort' => $edge['targetPort'] ?? '',
				);
			}
		}

		return array(
			'blueprint_slug' => $slug,
			'blueprint_name' => $blueprint['name'] ?? $slug,
			'nodes'          => $compiled_nodes,
			'edges'          => $compiled_edges,
		);
	}

	/**
	 * Resolve blueprint input values: merge provided values over defaults.
	 *
	 * @since 2.1.0
	 *
	 * @param array $blueprint    Loaded blueprint data.
	 * @param array $input_values User-provided input values.
	 * @return array Resolved inputs keyed by port name.
	 */
	protected function resolve_blueprint_inputs( array $blueprint, array $input_values ): array {
		$defaults = array();
		if ( isset( $blueprint['inputs'] ) && is_array( $blueprint['inputs'] ) ) {
			foreach ( $blueprint['inputs'] as $name => $config ) {
				$defaults[ $name ] = $config['default'] ?? '';
			}
		}

		return array_merge( $defaults, $input_values );
	}

	/**
	 * Get a summary of a blueprint suitable for the front-end palette.
	 *
	 * @since 2.1.0
	 *
	 * @param string $slug Blueprint slug.
	 * @return array|null Summary array or null if not found.
	 */
	public function get_summary( string $slug ) {
		$bp = $this->load( $slug );
		if ( null === $bp ) {
			return null;
		}

		return array(
			'slug'        => $bp['slug'],
			'name'        => $bp['name'] ?? $slug,
			'description' => $bp['description'] ?? '',
			'version'     => $bp['version'] ?? '0.0.0',
			'inputs'      => $this->format_ports_for_frontend( $bp['inputs'] ?? array() ),
			'outputs'     => $this->format_ports_for_frontend( $bp['outputs'] ?? array() ),
		);
	}

	/**
	 * Format blueprint I/O ports for the front-end palette (same shape as site node ports).
	 *
	 * @since 2.1.0
	 *
	 * @param array $ports Raw blueprint port definitions.
	 * @return array[]
	 */
	protected function format_ports_for_frontend( array $ports ): array {
		$formatted = array();
		foreach ( $ports as $name => $config ) {
			// Blueprint outputs use {node, port} references; simplify for the UI.
			if ( isset( $config['node'] ) ) {
				$formatted[] = array(
					'name'  => $name,
					'type'  => $config['type'] ?? 'html',
					'label' => $config['label'] ?? $name,
				);
			} else {
				$formatted[] = array(
					'name'     => $name,
					'type'     => $config['type'] ?? 'string',
					'label'    => $config['label'] ?? $name,
					'required' => false,
					'default'  => $config['default'] ?? '',
				);
			}
		}
		return $formatted;
	}

	/**
	 * List all blueprints as front-end-ready summaries.
	 *
	 * @since 2.1.0
	 *
	 * @return array[]
	 */
	public function list_all_summaries(): array {
		$result = array();
		foreach ( $this->list_all() as $slug ) {
			$summary = $this->get_summary( $slug );
			if ( null !== $summary ) {
				$result[] = $summary;
			}
		}
		return $result;
	}

	/**
	 * Clear the in-memory blueprint cache (useful for testing or when
	 * blueprint files are updated at runtime).
	 *
	 * @since 2.1.0
	 *
	 * @return void
	 */
	public function clear_cache() {
		$this->blueprints  = array();
		$this->directories = null;
	}
}
