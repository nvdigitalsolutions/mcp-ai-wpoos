<?php
/**
 * Toolkit MCP Server — Abstract Base
 *
 * Reusable base for per-toolkit MCP servers. Provides:
 *  - Default configuration storage (option `wp_mcp_ai_toolkit_mcp_server_{slug}`).
 *  - Auto-discovery of native ingestion surfaces from the page-class registry.
 *  - Helpers for building tools/list, resources/list, and prompts/list payloads
 *    that respect the per-server admin allowlist.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/interface-wp-mcp-ai-toolkit-server.php';

/**
 * Abstract base implementing common server behaviour.
 */
abstract class WP_MCP_AI_Toolkit_Server_Base implements WP_MCP_AI_Toolkit_Server_Interface {

	/**
	 * Option name prefix for per-server configuration.
	 */
	const OPTION_PREFIX = 'wp_mcp_ai_toolkit_mcp_server_';

	/**
	 * Returns the per-server configuration array.
	 *
	 * Shape:
	 * - 'enabled'              : bool — server master switch (default: true).
	 * - 'tools_allowlist'      : string[] — explicit tool-slug allowlist.
	 *                            Empty array means "all candidate tools allowed".
	 * - 'disabled_surfaces'    : string[] — page slugs of native surfaces to hide.
	 * - 'disabled_mounts'      : string[] — '{source_slug}::{page_slug}' of
	 *                            mounted surfaces to suppress.
	 *
	 * @return array<string,mixed>
	 */
	public function get_configuration() {
		$option   = get_option( self::OPTION_PREFIX . $this->get_slug(), array() );
		$defaults = array(
			'enabled'             => true,
			'tools_allowlist'     => array(),
			'disabled_surfaces'   => array(),
			'disabled_mounts'     => array(),
			// Phase 3c — per-server limits. `0` means "no override; use global".
			'requests_per_minute' => 0,
			'max_payload_bytes'   => 0,
			'max_iterations'      => 0,
		);
		if ( ! is_array( $option ) ) {
			$option = array();
		}
		return array_merge( $defaults, $option );
	}

	/**
	 * Persists per-server configuration. Sanitizes input shape.
	 *
	 * @param array<string,mixed> $config Config to persist.
	 * @return bool
	 */
	public function update_configuration( $config ) {
		$sanitized = array(
			'enabled'             => ! empty( $config['enabled'] ),
			'tools_allowlist'     => isset( $config['tools_allowlist'] ) && is_array( $config['tools_allowlist'] )
				? array_values( array_unique( array_map( 'sanitize_key', $config['tools_allowlist'] ) ) )
				: array(),
			'disabled_surfaces'   => isset( $config['disabled_surfaces'] ) && is_array( $config['disabled_surfaces'] )
				? array_values( array_unique( array_map( 'sanitize_key', $config['disabled_surfaces'] ) ) )
				: array(),
			'disabled_mounts'     => isset( $config['disabled_mounts'] ) && is_array( $config['disabled_mounts'] )
				? array_values( array_unique( array_filter( array_map( array( $this, 'sanitize_mount_key' ), $config['disabled_mounts'] ) ) ) )
				: array(),
			'requests_per_minute' => isset( $config['requests_per_minute'] ) ? max( 0, (int) $config['requests_per_minute'] ) : 0,
			'max_payload_bytes'   => isset( $config['max_payload_bytes'] ) ? max( 0, (int) $config['max_payload_bytes'] ) : 0,
			'max_iterations'      => isset( $config['max_iterations'] ) ? max( 0, (int) $config['max_iterations'] ) : 0,
		);
		return update_option( self::OPTION_PREFIX . $this->get_slug(), $sanitized );
	}

	/**
	 * Whether a tool slug is permitted to be invoked through this server.
	 *
	 * A slug is allowed when it appears in `effective_tool_slugs()`, which
	 * already accounts for the candidate list, the admin allowlist, and any
	 * subclass overrides.
	 *
	 * @since 1.3.0
	 *
	 * @param string $slug Tool slug.
	 * @return bool
	 */
	public function tool_is_allowed( $slug ) {
		$slug = sanitize_key( (string) $slug );
		if ( '' === $slug ) {
			return false;
		}
		return in_array( $slug, $this->effective_tool_slugs(), true );
	}

	/**
	 * Get the effective limits (after admin overrides AND filter).
	 *
	 * @since 1.3.0
	 *
	 * @return array{requests_per_minute:int, max_payload_bytes:int, max_iterations:int}
	 */
	public function effective_limits() {
		$config = $this->get_configuration();
		$limits = array(
			'requests_per_minute' => isset( $config['requests_per_minute'] ) ? (int) $config['requests_per_minute'] : 0,
			'max_payload_bytes'   => isset( $config['max_payload_bytes'] ) ? (int) $config['max_payload_bytes'] : 0,
			'max_iterations'      => isset( $config['max_iterations'] ) ? (int) $config['max_iterations'] : 0,
		);

		/**
		 * Filter the effective per-server limits.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $limits Limits with `requests_per_minute`, `max_payload_bytes`, `max_iterations` (0 = no override).
		 * @param string $slug   Server slug.
		 */
		$limits = apply_filters( 'wp_mcp_ai_toolkit_mcp_server_limits', $limits, $this->get_slug() );

		return array(
			'requests_per_minute' => max( 0, (int) ( $limits['requests_per_minute'] ?? 0 ) ),
			'max_payload_bytes'   => max( 0, (int) ( $limits['max_payload_bytes'] ?? 0 ) ),
			'max_iterations'      => max( 0, (int) ( $limits['max_iterations'] ?? 0 ) ),
		);
	}

	/**
	 * Resolve a mounted-surface descriptor by its mount key (`{source}::{page}`).
	 *
	 * Returns null when the mount is not declared, suppressed by the consumer,
	 * or the source toolkit has revoked it.
	 *
	 * @since 1.3.0
	 *
	 * @param string $source_slug Source toolkit slug.
	 * @param string $page_slug   Page slug.
	 * @return array<string,mixed>|null
	 */
	public function find_mounted_surface( $source_slug, $page_slug ) {
		$source_slug = sanitize_key( (string) $source_slug );
		$page_slug   = sanitize_key( (string) $page_slug );
		if ( '' === $source_slug || '' === $page_slug ) {
			return null;
		}
		foreach ( $this->effective_mounted_surfaces() as $surface ) {
			if (
				isset( $surface['source_toolkit_slug'], $surface['page_slug'] )
				&& $surface['source_toolkit_slug'] === $source_slug
				&& $surface['page_slug'] === $page_slug
			) {
				return $surface;
			}
		}
		return null;
	}

	/**
	 * Resolve a native ingestion-surface descriptor by page slug.
	 *
	 * @since 1.3.0
	 *
	 * @param string $page_slug Page slug.
	 * @return array<string,mixed>|null
	 */
	public function find_native_surface( $page_slug ) {
		$page_slug = sanitize_key( (string) $page_slug );
		if ( '' === $page_slug ) {
			return null;
		}
		foreach ( $this->effective_ingestion_surfaces() as $surface ) {
			if ( isset( $surface['page_slug'] ) && $surface['page_slug'] === $page_slug ) {
				return $surface;
			}
		}
		return null;
	}

	/**
	 * Mount keys are `{source_toolkit_slug}::{page_slug}`. Filter to that shape.
	 *
	 * @param string $key Raw key.
	 * @return string Sanitized key, or '' if invalid.
	 */
	protected function sanitize_mount_key( $key ) {
		$key = (string) $key;
		if ( false === strpos( $key, '::' ) ) {
			return '';
		}
		list( $source, $page ) = explode( '::', $key, 2 );
		$source                = sanitize_key( $source );
		$page                  = sanitize_key( $page );
		if ( '' === $source || '' === $page ) {
			return '';
		}
		return $source . '::' . $page;
	}

	/**
	 * Default `is_enabled` reads from the configuration option.
	 *
	 * @return bool
	 */
	public function is_enabled() {
		$config = $this->get_configuration();
		return ! empty( $config['enabled'] );
	}

	/**
	 * Default version. Subclasses may override.
	 *
	 * @return string
	 */
	public function get_version() {
		return '1.0.0';
	}

	/**
	 * Default mounted_surfaces() returns no foreign mounts. Subclasses override.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function mounted_surfaces() {
		return array();
	}

	/**
	 * Default candidate tool slugs returns an empty list. Subclasses override.
	 *
	 * @return string[]
	 */
	public function candidate_tool_slugs() {
		return array();
	}

	/**
	 * Get the effective set of native ingestion surfaces (after admin disable).
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function effective_ingestion_surfaces() {
		$config   = $this->get_configuration();
		$disabled = isset( $config['disabled_surfaces'] ) ? (array) $config['disabled_surfaces'] : array();
		$out      = array();
		foreach ( $this->ingestion_surfaces() as $surface ) {
			if ( ! is_array( $surface ) || empty( $surface['page_slug'] ) ) {
				continue;
			}
			if ( in_array( $surface['page_slug'], $disabled, true ) ) {
				continue;
			}
			$out[] = $surface;
		}
		return $out;
	}

	/**
	 * Get the effective set of mounted surfaces (after admin disable AND source-toolkit gates).
	 *
	 * Mounted surfaces are suppressed when:
	 *  - the consumer admin has disabled the mount, OR
	 *  - the source toolkit's server is itself disabled, OR
	 *  - the source toolkit has disabled the underlying native surface.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function effective_mounted_surfaces() {
		$config          = $this->get_configuration();
		$disabled_mounts = isset( $config['disabled_mounts'] ) ? (array) $config['disabled_mounts'] : array();
		$registry        = WP_MCP_AI_Toolkit_Server_Registry::get_instance();
		$out             = array();

		foreach ( $this->mounted_surfaces() as $surface ) {
			if ( ! is_array( $surface ) || empty( $surface['page_slug'] ) || empty( $surface['source_toolkit_slug'] ) ) {
				continue;
			}
			$mount_key = $surface['source_toolkit_slug'] . '::' . $surface['page_slug'];
			if ( in_array( $mount_key, $disabled_mounts, true ) ) {
				continue;
			}

			// Check the source toolkit's gates.
			$source_server = $registry->get( $surface['source_toolkit_slug'] );
			if ( $source_server && ! $source_server->is_enabled() ) {
				continue;
			}
			if ( $source_server ) {
				$source_disabled = $source_server->get_configuration();
				$source_disabled = isset( $source_disabled['disabled_surfaces'] )
					? (array) $source_disabled['disabled_surfaces']
					: array();
				if ( in_array( $surface['page_slug'], $source_disabled, true ) ) {
					continue;
				}
			}

			// Force read_only flag on mounted surfaces.
			$surface['read_only'] = true;
			$out[]                = $surface;
		}
		return $out;
	}

	/**
	 * Build the descriptor payload for this server (used by /mcp endpoint).
	 *
	 * @return array<string,mixed>
	 */
	public function get_descriptor() {
		$native  = $this->effective_ingestion_surfaces();
		$mounted = $this->effective_mounted_surfaces();
		$limits  = $this->effective_limits();
		$defaults = $this->get_default_limits();

		// Merge configured limits with defaults so the descriptor always
		// surfaces explicit values (never 0 = unlimited).
		if ( empty( $limits['requests_per_minute'] ) && ! empty( $defaults['requests_per_minute'] ) ) {
			$limits['requests_per_minute'] = $defaults['requests_per_minute'];
		}
		if ( empty( $limits['max_payload_bytes'] ) && ! empty( $defaults['max_payload_bytes'] ) ) {
			$limits['max_payload_bytes'] = $defaults['max_payload_bytes'];
		}
		if ( empty( $limits['max_iterations'] ) && ! empty( $defaults['max_iterations'] ) ) {
			$limits['max_iterations'] = $defaults['max_iterations'];
		}

		return array(
			'slug'             => $this->get_slug(),
			'name'             => $this->get_name(),
			'description'      => $this->get_description(),
			'version'          => $this->get_version(),
			'enabled'          => $this->is_enabled(),
			'protocolVersion'  => '2025-06-18',
			'capabilities'     => array(
				'tools'     => (object) array(),
				'resources' => (object) array(
					'subscribe'   => false,
					'listChanged' => false,
				),
				'prompts'   => (object) array( 'listChanged' => false ),
			),
			'native_surfaces'  => array_values( $native ),
			'mounted_surfaces' => array_values( $mounted ),
			'tool_count'       => count( $this->effective_tool_slugs() ),
			'limits'           => $limits,
			'annotations'      => array(
				'tool_scopes' => $this->compute_tool_scopes(),
			),
			'endpoints'        => array(
				'jsonrpc' => rest_url( WP_MCP_AI_Toolkit_MCP_REST_Controller::REST_NAMESPACE . '/mcp/' . $this->get_slug() ),
			),
		);
	}

	/**
	 * Effective tool slug list — intersection of candidates and the allowlist
	 * (or all candidates when the allowlist is empty).
	 *
	 * @return string[]
	 */
	public function effective_tool_slugs() {
		$candidates = $this->candidate_tool_slugs();
		$config     = $this->get_configuration();
		$allowlist  = isset( $config['tools_allowlist'] ) ? (array) $config['tools_allowlist'] : array();
		if ( empty( $allowlist ) ) {
			return $candidates;
		}
		return array_values( array_intersect( $candidates, $allowlist ) );
	}

	/**
	 * Build prompts/list entries for this server, namespacing mounted entries
	 * under '_mounted/'.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_prompts() {
		$out = array();

		foreach ( $this->effective_ingestion_surfaces() as $surface ) {
			$entity_short = isset( $surface['entity_type'] ) ? sanitize_key( str_replace( 'mcp_ai_', '', $surface['entity_type'] ) ) : 'entity';
			$type_short   = ( 'research_add' === $surface['type'] ) ? 'research_add' : 'consolidate_add';
			$out[]        = array(
				'name'        => $this->get_slug() . '.' . $type_short . '.' . $entity_short,
				'description' => isset( $surface['label'] ) ? $surface['label'] : '',
				'arguments'   => array(),
				'metadata'    => array(
					'page_slug'   => $surface['page_slug'],
					'entity_type' => isset( $surface['entity_type'] ) ? $surface['entity_type'] : '',
				),
			);
		}

		foreach ( $this->effective_mounted_surfaces() as $surface ) {
			$entity_short = isset( $surface['entity_type'] ) ? sanitize_key( str_replace( 'mcp_ai_', '', $surface['entity_type'] ) ) : 'entity';
			$type_short   = ( 'research_add' === $surface['type'] ) ? 'research_add' : 'consolidate_add';
			$source       = isset( $surface['source_toolkit_slug'] ) ? $surface['source_toolkit_slug'] : 'unknown';
			$out[]        = array(
				'name'        => '_mounted/' . $source . '.' . $type_short . '.' . $entity_short,
				'description' => isset( $surface['label'] ) ? $surface['label'] : '',
				'arguments'   => array(),
				'metadata'    => array(
					'page_slug'   => $surface['page_slug'],
					'entity_type' => isset( $surface['entity_type'] ) ? $surface['entity_type'] : '',
					'mounted'     => true,
					'read_only'   => true,
					'source'      => $source,
				),
			);
		}

		return $out;
	}

	/**
	 * Build resources/list entries: one per entity_type from native + mounted surfaces.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_resources() {
		$entries = array();
		$seen    = array();

		foreach ( $this->effective_ingestion_surfaces() as $surface ) {
			$entity = isset( $surface['entity_type'] ) ? sanitize_key( $surface['entity_type'] ) : '';
			if ( '' === $entity ) {
				continue;
			}
			$key = 'native:' . $entity;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$entries[]    = array(
				'uri'         => 'nvoos://' . $this->get_slug() . '/' . $entity,
				'name'        => $entity,
				'description' => isset( $surface['label'] ) ? $surface['label'] : '',
				'mimeType'    => 'application/vnd.nvoos.entity-collection+json',
			);
		}

		foreach ( $this->effective_mounted_surfaces() as $surface ) {
			$entity = isset( $surface['entity_type'] ) ? sanitize_key( $surface['entity_type'] ) : '';
			$source = isset( $surface['source_toolkit_slug'] ) ? sanitize_key( $surface['source_toolkit_slug'] ) : '';
			if ( '' === $entity || '' === $source ) {
				continue;
			}
			$key = 'mounted:' . $source . ':' . $entity;
			if ( isset( $seen[ $key ] ) ) {
				continue;
			}
			$seen[ $key ] = true;
			$entries[]    = array(
				'uri'         => 'nvoos://' . $this->get_slug() . '/_mounted/' . $source . '/' . $entity,
				'name'        => $source . '/' . $entity,
				'description' => isset( $surface['label'] ) ? $surface['label'] : '',
				'mimeType'    => 'application/vnd.nvoos.entity-collection+json',
				'annotations' => array( 'readOnly' => true ),
			);
		}

		return $entries;
	}

	/**
	 * Build tools/list entries by resolving the effective tool slugs against the
	 * core tool registry.
	 *
	 * @return array<int,array<string,mixed>>
	 */
	public function get_tools() {
		$slugs = $this->effective_tool_slugs();
		if ( empty( $slugs ) || ! class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			return array();
		}

		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$out      = array();

		foreach ( $slugs as $slug ) {
			$tool = method_exists( $registry, 'get_tool' ) ? $registry->get_tool( $slug ) : null;
			if ( ! $tool || ! is_object( $tool ) ) {
				continue;
			}
			$definition = method_exists( $tool, 'get_definition' ) ? $tool->get_definition() : array();
			if ( ! is_array( $definition ) ) {
				continue;
			}
			$out[] = array(
				'name'        => $slug,
				'description' => isset( $definition['description'] ) ? $definition['description'] : ( isset( $definition['name'] ) ? $definition['name'] : $slug ),
				'inputSchema' => $this->resolve_input_schema( $definition, $tool ),
			);
		}
		return $out;
	}

	/**
	 * Resolve the inputSchema for a tool, preferring the definition's
	 * parameters key and falling back to the tool's get_parameters_schema().
	 *
	 * Uses stdClass for empty properties so json_encode produces {} not [].
	 *
	 * @param array  $definition Tool definition from get_definition().
	 * @param object $tool       The tool instance.
	 * @return array
	 */
	protected function resolve_input_schema( array $definition, $tool ) {
		if ( isset( $definition['parameters'] ) && is_array( $definition['parameters'] ) ) {
			return $definition['parameters'];
		}

		if ( method_exists( $tool, 'get_parameters_schema' ) ) {
			$schema = $tool->get_parameters_schema();
			if ( is_array( $schema ) ) {
				return $schema;
			}
		}

		// Minimal fallback: empty object schema with properties as stdClass
		// so json_encode outputs {} instead of [].
		return array(
			'type'       => 'object',
			'properties' => new \stdClass(),
		);
	}

	/**
	 * Compute per-tool scope annotations (read_only vs read_write).
	 *
	 * Default implementation marks every tool as 'read_only'.  Subclasses
	 * and traits (e.g. ScheduledToolkitServerTrait) may override to provide
	 * domain-specific scoping.
	 *
	 * @since 1.5.0
	 *
	 * @return array<string, string> Map of tool slug => 'read_only' | 'read_write'.
	 */
	public function compute_tool_scopes() {
		$scopes = array();
		foreach ( $this->candidate_tool_slugs() as $slug ) {
			$scopes[ $slug ] = 'read_only';
		}
		return $scopes;
	}

	/**
	 * Default limits for this server category.
	 *
	 * Subclasses override to provide sensible domain-specific defaults
	 * that appear in the MCP descriptor even when the admin has not
	 * configured explicit overrides.
	 *
	 * @since 1.5.0
	 *
	 * @return array{requests_per_minute: int, max_payload_bytes: int, max_iterations: int}
	 */
	public function get_default_limits() {
		return array(
			'requests_per_minute' => 30,
			'max_payload_bytes'   => 65536, // 64 KB.
			'max_iterations'      => 5,
		);
	}
}
