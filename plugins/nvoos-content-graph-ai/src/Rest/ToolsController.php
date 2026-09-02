<?php
/**
 * Tools listing REST controller for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's tools surface
 * (`includes/rest/class-wp-mcp-ai-rest-tools-controller.php` route +
 * `includes/class-wp-mcp-ai-rest.php` `handle_tools_list()`)
 * (behaviour-preserving; base copies retained permanently — ecosystem
 * port plan D-NOBASE). Route path, args, the `tools` response contract
 * (`name` / `description` / `inputSchema`), cache key structure, and
 * `_fields` filtering keep their base names and semantics.
 *
 * Decoupling (documented, additive):
 * - Auth is CG-AI's own (`edit_posts`). Token scoping stays with the
 *   base hub in monolith installs.
 * - The tool registry delegates to the base
 *   `WP_MCP_AI_Tool_Registry` in monolith installs and the nvoos/core
 *   registry (via `CoreBridge`) standalone — camelCase core tools are
 *   wrapped to the base's snake_case surface.
 * - Assistant configuration + access validation reuse the same seams as
 *   `AssistantController`.
 * - Listing cache delegates to the base `WP_MCP_AI_REST_Cache` in
 *   monolith installs; standalone uses a transient with the same key
 *   structure.
 * - `registerRoutes()` is called standalone-only by `Plugin.php` — the
 *   base plugin owns the same route in monolith installs.
 *
 * @package NvoosContentGraphAi\Rest
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Rest;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Registers and serves the MCP-compliant tools listing.
 *
 * @since 1.1.0
 */
class ToolsController {

	/**
	 * REST namespace (byte-identical to the base plugin).
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Assistant post type slug (byte-identical to the base plugin).
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Register the tools listing route.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		register_rest_route(
			self::REST_NAMESPACE,
			'/tools',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check' ),
					'callback'            => array( $this, 'handle_tools_list' ),
					'args'                => array(
						'assistant_id' => array(
							'description'       => __( 'ID of the assistant to list tools for. Returns all tools if omitted.', 'nvoos-content-graph-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'sanitize_callback' => 'absint',
						),
						'_fields'      => array(
							'description'       => __( 'Comma-separated list of tool fields to include in each item (name, description, inputSchema). Defaults to all fields.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			),
			true
		);
	}

	/**
	 * Permission check for the tools listing.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! current_user_can( 'edit_posts' ) ) {
			return new \WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Handle GET /tools request - List available tools.
	 *
	 * Returns a list of available tools, optionally filtered by assistant.
	 * If no assistant_id is provided, returns all registered tools.
	 *
	 * @param WP_REST_Request $request REST request object.
	 * @return WP_REST_Response|WP_Error Response object.
	 */
	public function handle_tools_list( \WP_REST_Request $request ) {
		$assistant_id = absint( $request->get_param( 'assistant_id' ) );

		// Try to serve from cache (keyed on assistant_id).
		$cache_params = array_filter(
			array( 'assistant_id' => $assistant_id ? $assistant_id : null ),
			static function ( $v ) {
				return null !== $v;
			}
		);

		$cached_tools = $this->get_cached_tools( $cache_params );

		if ( false !== $cached_tools && is_array( $cached_tools ) ) {
			$tools_list = $cached_tools;
		} else {
			if ( ! $assistant_id ) {
				// Return all tools if no assistant specified.
				$tools = $this->get_registry_tools();
			} else {
				// Get tools allowed for this assistant.
				$assistant_post = $this->validate_assistant_access( $assistant_id );

				if ( is_wp_error( $assistant_post ) ) {
					return $assistant_post;
				}

				$assistant_config = $this->get_assistant_configuration( $assistant_id );
				$allowed_tools    = isset( $assistant_config['tools'] ) ? $assistant_config['tools'] : array();

				$tools = array();
				foreach ( $allowed_tools as $tool_slug ) {
					$tool = $this->get_registry_tool( $tool_slug );
					if ( $tool ) {
						$tools[] = $tool;
					}
				}
			}

			// Convert tools to a simple array format.
			$tools_list = array();
			foreach ( $tools as $tool ) {
				try {
					$schema = $tool->get_parameters_schema();

					// Validate that the schema is a valid array.
					if ( ! is_array( $schema ) ) {
						$this->log_event(
							'error',
							'Tool returned invalid schema',
							array(
								'tool_slug'   => $tool->get_slug(),
								'schema_type' => gettype( $schema ),
							)
						);
						continue;
					}

					$tools_list[] = array(
						'name'        => $tool->get_slug(),
						'description' => $tool->get_description(),
						'inputSchema' => $schema,
					);
				} catch ( \Exception $e ) {
					$this->log_event(
						'error',
						'Tool schema generation failed',
						array(
							'tool_slug' => method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : 'unknown',
							'error'     => $e->getMessage(),
						)
					);
					continue;
				} catch ( \Error $e ) {
					$this->log_event(
						'error',
						'Tool schema generation failed with PHP Error',
						array(
							'tool_slug' => method_exists( $tool, 'get_slug' ) ? $tool->get_slug() : 'unknown',
							'error'     => $e->getMessage(),
						)
					);
					continue;
				}
			}

			// Cache the full tools list; _fields filtering is applied after retrieval.
			$this->set_cached_tools( $cache_params, $tools_list );
		}

		// Apply _fields filtering to reduce response payload when requested.
		$fields_param = $request->get_param( '_fields' );
		if ( $fields_param && is_string( $fields_param ) ) {
			$allowed_fields = wp_parse_list( $fields_param );
			// Valid tool fields: name, description, inputSchema. 'name' is always included.
			$valid_fields   = array( 'name', 'description', 'inputSchema' );
			$allowed_fields = array_intersect( $allowed_fields, $valid_fields );
			if ( ! empty( $allowed_fields ) ) {
				if ( ! in_array( 'name', $allowed_fields, true ) ) {
					$allowed_fields[] = 'name';
				}
				$allowed_map = array_flip( $allowed_fields );
				$tools_list  = array_map(
					static function ( $tool ) use ( $allowed_map ) {
						return array_intersect_key( $tool, $allowed_map );
					},
					$tools_list
				);
			}
		}

		return rest_ensure_response(
			array(
				'tools' => $tools_list,
			)
		);
	}

	// ─── Seams (per-install-mode) ────────────────────────────────────

	/**
	 * Resolve all registered tools (per-install-mode seam).
	 *
	 * @return array Tool instances exposing the base snake_case surface.
	 */
	protected function get_registry_tools() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$tools    = $registry->get_tools();
			return is_array( $tools ) ? $tools : array();
		}

		$tools = array();
		foreach ( CoreBridge::instance()->tools->enabled() as $tool ) {
			$tools[] = $this->wrap_tool( $tool );
		}
		return $tools;
	}

	/**
	 * Resolve a single tool by slug (per-install-mode seam).
	 *
	 * @param string $slug Tool slug.
	 * @return object|null
	 */
	protected function get_registry_tool( $slug ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = \WP_MCP_AI_Tool_Registry::get_instance();
			$tool     = $registry->get_tool( $slug );
			return $tool ? $tool : null;
		}

		$tool = CoreBridge::instance()->tools->get( (string) $slug );
		return $tool ? $this->wrap_tool( $tool ) : null;
	}

	/**
	 * Wrap an nvoos/core tool (camelCase) in the base snake_case surface.
	 *
	 * @param object $tool Core tool instance.
	 * @return object
	 */
	protected function wrap_tool( $tool ) {
		return new class( $tool ) {
			private $tool;

			public function __construct( $tool ) {
				$this->tool = $tool;
			}

			public function get_slug() {
				return $this->tool->getSlug();
			}

			public function get_description() {
				return $this->tool->getDescription();
			}

			public function get_parameters_schema() {
				return $this->tool->getParametersSchema();
			}
		};
	}

	/**
	 * Read the assistant configuration (per-install-mode seam).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected function get_assistant_configuration( $assistant_id ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
			return \WP_MCP_AI_Assistant_CPT::get_assistant_configuration( $assistant_id );
		}

		return $this->read_assistant_configuration_standalone( $assistant_id );
	}

	/**
	 * Standalone assistant configuration reader (same meta keys as base).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array
	 */
	protected function read_assistant_configuration_standalone( $assistant_id ) {
		$assistant_id = absint( $assistant_id );
		if ( ! $assistant_id ) {
			return array();
		}

		$config = array(
			'tools' => get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true ),
		);

		if ( ! is_array( $config['tools'] ) ) {
			$config['tools'] = array();
		}

		return $config;
	}

	/**
	 * Ensure the current user can access the requested assistant post.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return WP_Post|WP_Error
	 */
	protected function validate_assistant_access( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		$assistant_post = $assistant_id ? get_post( $assistant_id ) : null;

		if ( ! $assistant_post || self::POST_TYPE !== $assistant_post->post_type ) {
			return new \WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( 'publish' !== $assistant_post->post_status && ! current_user_can( 'read_post', $assistant_id ) ) {
			return new \WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
		}

		return $assistant_post;
	}

	/**
	 * Read the cached tools list (per-install-mode seam).
	 *
	 * @param array $params Cache key parameters.
	 * @return array|false
	 */
	protected function get_cached_tools( array $params ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			return \WP_MCP_AI_REST_Cache::get_response( 'tools', $params );
		}

		$cached = get_transient( 'wp_mcp_ai_rest_tools_' . md5( (string) wp_json_encode( $params ) ) );
		return is_array( $cached ) ? $cached : false;
	}

	/**
	 * Store the tools list cache (per-install-mode seam).
	 *
	 * @param array $params Cache key parameters.
	 * @param array $data   Tools list to cache.
	 * @return void
	 */
	protected function set_cached_tools( array $params, array $data ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			\WP_MCP_AI_REST_Cache::set_response( 'tools', $params, $data );
			return;
		}

		set_transient( 'wp_mcp_ai_rest_tools_' . md5( (string) wp_json_encode( $params ) ), $data, 1800 );
	}

	/**
	 * Forward a log event to the base logger (monolith installs only).
	 *
	 * @param string $event   Event identifier.
	 * @param string $message Human-readable message.
	 * @param array  $data    Structured event data.
	 * @return void
	 */
	protected function log_event( $event, $message, $data = array() ): void {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_event( $event, $message, $data );
		}
	}
}
