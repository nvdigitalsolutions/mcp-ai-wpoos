<?php
/**
 * Assistant directory REST controller for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's assistant-directory surface
 * (`includes/rest/class-wp-mcp-ai-rest-mcp-controller.php` routes +
 * `includes/class-wp-mcp-ai-rest.php` `handle_assistants_index()` /
 * `summarize_assistant_for_directory()` / `validate_assistant_access()`)
 * (behaviour-preserving; base copies retained permanently — ecosystem
 * port plan D-NOBASE). Route paths, response shapes, error codes,
 * pagination semantics, `_fields` filtering, cache key structure, and
 * every `wp_mcp_ai_rest_assistant_*` filter keep their base names.
 *
 * Decoupling (documented, additive):
 * - Auth is CG-AI's own: list requires `edit_posts`; create/delete
 *   require `manage_options`. Token scoping (`apply_token_assistant_scope`)
 *   returns no scope until CG-AI guest tokens land (D-UI) — in monolith
 *   installs the base hub serves these routes with full token support.
 * - Settings read the base `WP_MCP_AI_Admin_Settings` in monolith
 *   installs and the content-graph settings store standalone
 *   (`default_provider`/`default_model`/`default_gemini_model` map to
 *   `ai_default_provider`/`ai_default_model`/`ai_default_gemini_model`).
 * - Assistant configuration reads the base
 *   `WP_MCP_AI_Assistant_CPT::get_assistant_configuration()` in monolith
 *   installs; standalone reads the same meta keys inline.
 * - Directory caching delegates to the base `WP_MCP_AI_REST_Cache` in
 *   monolith installs; standalone uses a transient with the same key
 *   structure and 30-minute TTL.
 * - The directory response is JSON-only (no SSE event-stream variant)
 *   until the SSE directory path ports.
 * - `registerRoutes()` is called standalone-only by `Plugin.php` — the
 *   base plugin owns the same routes in monolith installs.
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
 * Registers and serves the MCP-compliant assistant directory.
 *
 * @since 1.1.0
 */
class AssistantController {

	/**
	 * REST namespace (byte-identical to the base plugin).
	 */
	const REST_NAMESPACE = 'mcp-ai/v1';

	/**
	 * Assistant post type slug (byte-identical to the base plugin).
	 */
	const POST_TYPE = 'mcp_ai_assistant';

	/**
	 * Directory cache TTL — byte-identical to the base
	 * `WP_MCP_AI_REST_Cache::ASSISTANT_LIST_EXPIRATION` (30 minutes).
	 */
	const CACHE_TTL = 1800;

	/**
	 * Per-request assistant access validation cache.
	 *
	 * @var array<string, WP_Post|WP_Error>
	 */
	private static $assistant_cache = array();

	/**
	 * Register the assistant directory routes.
	 *
	 * @return void
	 */
	public function registerRoutes(): void {
		// /assistants - MCP-compliant assistant directory.
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants',
			array(
				array(
					'methods'             => \WP_REST_Server::READABLE,
					'permission_callback' => array( $this, 'permissions_check_list' ),
					'callback'            => array( $this, 'handle_assistants_index' ),
					'args'                => array(
						'search'   => array(
							'description'       => __( 'Search term to filter assistants by title or content.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'include'  => array(
							'description' => __( 'Limit results to specific assistant IDs.', 'nvoos-content-graph-ai' ),
							'type'        => 'array',
							'required'    => false,
							'items'       => array(
								'type' => 'integer',
							),
						),
						'per_page' => array(
							'description' => __( 'Maximum number of assistants to return. Use -1 (default) to return all.', 'nvoos-content-graph-ai' ),
							'type'        => 'integer',
							'required'    => false,
							'minimum'     => -1,
							'maximum'     => 100,
						),
						'page'     => array(
							'description'       => __( 'Page of results to return when per_page is a positive integer. Defaults to 1.', 'nvoos-content-graph-ai' ),
							'type'              => 'integer',
							'required'          => false,
							'minimum'           => 1,
							'sanitize_callback' => 'absint',
						),
						'_fields'  => array(
							'description'       => __( 'Comma-separated list of assistant fields to include in each item. Always includes id.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
				array(
					'methods'             => \WP_REST_Server::CREATABLE,
					'permission_callback' => array( $this, 'permissions_check_create' ),
					'callback'            => array( $this, 'handle_assistant_create' ),
					'args'                => array(
						'title'         => array(
							'description'       => __( 'The title for the assistant. When omitted the request acts as a connectivity check and returns the directory listing.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'description'   => array(
							'description'       => __( 'The description for the assistant.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'wp_kses_post',
						),
						'provider'      => array(
							'description'       => __( 'AI provider.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'openai', 'gemini', 'ollama', 'anthropic', 'lm_studio', 'huggingface', 'cloudflare', 'nvidia', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' ),
							'sanitize_callback' => 'sanitize_key',
						),
						'model'         => array(
							'description'       => __( 'Model identifier (e.g., gpt-4, gemini-pro).', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'temperature'   => array(
							'description' => __( 'Temperature setting (0.0 to 2.0).', 'nvoos-content-graph-ai' ),
							'type'        => 'number',
							'required'    => false,
							'minimum'     => 0.0,
							'maximum'     => 2.0,
						),
						'system_prompt' => array(
							'description'       => __( 'System prompt for the assistant.', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'sanitize_callback' => 'wp_kses_post',
						),
						'tools'         => array(
							'description' => __( 'Array of tool slugs to enable for this assistant.', 'nvoos-content-graph-ai' ),
							'type'        => 'array',
							'required'    => false,
							'items'       => array(
								'type' => 'string',
							),
						),
						'status'        => array(
							'description'       => __( 'Post status (publish, draft, private).', 'nvoos-content-graph-ai' ),
							'type'              => 'string',
							'required'          => false,
							'enum'              => array( 'publish', 'draft', 'private' ),
							'sanitize_callback' => 'sanitize_key',
						),
					),
				),
			),
			true
		);

		// /assistants/{id} - Individual assistant operations.
		register_rest_route(
			self::REST_NAMESPACE,
			'/assistants/(?P<id>\d+)',
			array(
				array(
					'methods'             => \WP_REST_Server::DELETABLE,
					'permission_callback' => array( $this, 'permissions_check_delete' ),
					'callback'            => array( $this, 'handle_assistant_delete' ),
					'args'                => array(
						'id' => array(
							'description'       => __( 'Unique identifier for the assistant.', 'nvoos-content-graph-ai' ),
							'type'              => 'integer',
							'required'          => true,
							'sanitize_callback' => 'absint',
						),
					),
				),
			),
			true
		);
	}

	// ─── Permission checks (CG-AI auth) ─────────────────────────────

	/**
	 * Permission check for listing assistants.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_list( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! current_user_can( 'edit_posts' ) ) {
			return $this->error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'nvoos-content-graph-ai' ),
				403
			);
		}

		return true;
	}

	/**
	 * Permission check for assistant creation.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_create( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'nvoos-content-graph-ai' ),
				403
			);
		}

		return true;
	}

	/**
	 * Permission check for assistant deletion.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return bool|WP_Error
	 */
	public function permissions_check_delete( \WP_REST_Request $request ) {
		unset( $request );

		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error(
				'rest_forbidden',
				__( 'You do not have permission to perform this action.', 'nvoos-content-graph-ai' ),
				403
			);
		}

		return true;
	}

	// ─── Assistant directory ─────────────────────────────────────────

	/**
	 * Provide a directory of assistants the authenticated client can access.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistants_index( \WP_REST_Request $request ) {
		$settings          = $this->get_settings();
		$default_assistant = isset( $settings['default_assistant'] ) ? absint( $settings['default_assistant'] ) : 0;
		$auth_context      = $this->get_auth_context();

		$scoped_assistant = $this->apply_token_assistant_scope( 0 );
		if ( is_wp_error( $scoped_assistant ) ) {
			return $scoped_assistant;
		}

		// Parse pagination parameters; -1 (or null) means return all (default).
		$per_page = $request->get_param( 'per_page' );
		$per_page = ( null !== $per_page ) ? intval( $per_page ) : -1;
		$page_raw = $request->get_param( 'page' );
		$page     = max( 1, absint( null !== $page_raw ? (int) $page_raw : 1 ) );

		$total_assistants = 0;
		$total_pages      = 1;
		$assistants       = array();

		if ( $scoped_assistant ) {
			// Token-scoped: single assistant, skip caching. (Unreachable
			// until guest tokens land — kept byte-identical to the base.)
			$assistant_post = $this->validate_assistant_access( $scoped_assistant );

			if ( is_wp_error( $assistant_post ) ) {
				return $assistant_post;
			}

			$summary          = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );
			$assistants       = array( $summary );
			$total_assistants = 1;
			$total_pages      = 1;
		} else {
			// Build cache key from query parameters (not _fields — filtered after cache retrieval).
			$cache_params = array_filter(
				array(
					'search'   => $request->get_param( 'search' ),
					'include'  => $request->get_param( 'include' ),
					'per_page' => ( $per_page > 0 ) ? $per_page : null,
					'page'     => ( $per_page > 0 && $page > 1 ) ? $page : null,
				),
				static function ( $v ) {
					return null !== $v;
				}
			);

			$cached_data = $this->get_cached_directory( $cache_params );

			if ( false !== $cached_data && is_array( $cached_data ) ) {
				// Serve from cache.
				$assistants       = $cached_data['assistants'];
				$total_assistants = $cached_data['total'];
				$total_pages      = $cached_data['total_pages'];
			} else {
				// Build the WP_Query arguments.
				$query_args = array(
					'post_type'   => self::POST_TYPE,
					'post_status' => array( 'publish' ),
					'orderby'     => 'title',
					'order'       => 'ASC',
				);

				if ( $per_page > 0 ) {
					$query_args['posts_per_page'] = $per_page;
					$query_args['paged']          = $page;
				} else {
					$query_args['posts_per_page'] = -1;
					$query_args['no_found_rows']  = true; // Skip COUNT query for unlimited requests.
				}

				$search = $request->get_param( 'search' );
				if ( is_string( $search ) && '' !== $search ) {
					$query_args['s'] = sanitize_text_field( $search );
				}

				$include = $request->get_param( 'include' );
				if ( ! empty( $include ) ) {
					$include_ids = array();

					if ( is_string( $include ) ) {
						$include = explode( ',', $include );
					}

					foreach ( (array) $include as $candidate ) {
						$candidate = absint( $candidate );

						if ( $candidate ) {
							$include_ids[] = $candidate;
						}
					}

					if ( ! empty( $include_ids ) ) {
						$query_args['post__in'] = $include_ids;
						$query_args['orderby']  = 'post__in';
					}
				}

				/**
				 * Allow developers to adjust the assistant directory query.
				 *
				 * @param array           $query_args   WP_Query arguments.
				 * @param WP_REST_Request $request      Current REST request.
				 * @param array           $auth_context Authentication context for the caller.
				 */
				$query_args = apply_filters( 'wp_mcp_ai_rest_assistant_query_args', $query_args, $request, $auth_context );

				$query = new \WP_Query( $query_args );
				$posts = $query->posts;

				if ( ! is_array( $posts ) ) {
					$posts = array();
				}

				$filtered = array();
				foreach ( $posts as $post ) {
					if ( ! $post instanceof \WP_Post ) {
						$post = get_post( $post );
					}

					if ( ! $post instanceof \WP_Post ) {
						continue;
					}

					$accessible = $this->validate_assistant_access( $post->ID );

					if ( $accessible instanceof \WP_Post ) {
						$filtered[] = $accessible;
					}
				}

				foreach ( $filtered as $assistant_post ) {
					$summary      = $this->summarize_assistant_for_directory( $assistant_post, $default_assistant, $settings, $request );
					$assistants[] = $summary;
				}

				$assistants = array_values( $assistants );

				// Compute totals.
				if ( $per_page > 0 ) {
					$total_assistants = absint( $query->found_posts );
					$total_pages      = (int) ceil( $total_assistants / $per_page );
				} else {
					$total_assistants = count( $assistants );
					$total_pages      = 1;
				}

				// Cache the unscoped list for future requests.
				$this->set_cached_directory(
					$cache_params,
					array(
						'assistants'  => $assistants,
						'total'       => $total_assistants,
						'total_pages' => $total_pages,
					)
				);
			}
		}

		// Apply _fields filtering to each assistant summary.
		$fields_param = $request->get_param( '_fields' );
		if ( $fields_param && is_string( $fields_param ) ) {
			$allowed_fields = wp_parse_list( $fields_param );
			if ( ! empty( $allowed_fields ) ) {
				// Always include 'id' per REST API convention.
				if ( ! in_array( 'id', $allowed_fields, true ) ) {
					$allowed_fields[] = 'id';
				}
				$allowed_map = array_flip( $allowed_fields );
				$assistants  = array_map(
					static function ( $assistant ) use ( $allowed_map ) {
						return array_intersect_key( $assistant, $allowed_map );
					},
					$assistants
				);
			}
		}

		$directory_default = $scoped_assistant ? $scoped_assistant : $default_assistant;
		if ( ! $directory_default && ! empty( $assistants ) ) {
			$first_assistant = reset( $assistants );
			if ( is_array( $first_assistant ) && isset( $first_assistant['id'] ) ) {
				$directory_default = absint( $first_assistant['id'] );
			}
		}

		$response_data = array(
			'assistants'        => $assistants,
			'default_assistant' => $directory_default,
			'rest'              => array(
				'namespace'     => self::REST_NAMESPACE,
				'base'          => esc_url_raw( $this->normalise_rest_url( rest_url( self::REST_NAMESPACE ) ) ),
				'chat'          => esc_url_raw( $this->normalise_rest_url( rest_url( self::REST_NAMESPACE . '/chat' ) ) ),
				'tools'         => esc_url_raw( $this->normalise_rest_url( rest_url( self::REST_NAMESPACE . '/tools' ) ) ),
				'file_download' => esc_url_raw( $this->normalise_rest_url( rest_url( self::REST_NAMESPACE . '/files' ) ) ),
				'sse'           => esc_url_raw( $this->normalise_rest_url( rest_url( self::REST_NAMESPACE . '/sse' ) ) ),
				'mcp'           => esc_url_raw( $this->normalise_rest_url( rest_url( self::REST_NAMESPACE . '/mcp' ) ) ),
			),
		);

		$capabilities = $this->build_assistant_directory_capabilities( $response_data );
		if ( ! empty( $capabilities ) ) {
			$response_data['capabilities'] = $capabilities;
		}

		$response_data['implementation'] = array(
			'name'    => 'NV oOS',
			'version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : ( defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : 'dev' ),
		);

		// Token scope is omitted — CG-AI has no bearer tokens yet (D-UI).

		/**
		 * Filter the assistant directory response payload before it is returned.
		 *
		 * @param array           $response_data Response payload.
		 * @param WP_REST_Request $request       Current REST request.
		 * @param array           $auth_context  Authentication context for the caller.
		 */
		$response_data = apply_filters( 'wp_mcp_ai_rest_assistant_index', $response_data, $request, $auth_context );

		$response = new \WP_REST_Response( $response_data, 200 );
		$response->header( 'X-WP-Total', $total_assistants );
		$response->header( 'X-WP-TotalPages', $total_pages );

		return $response;
	}

	/**
	 * Convert an assistant post into a safe directory summary.
	 *
	 * @param WP_Post         $assistant_post   Assistant post object.
	 * @param int             $default_assistant Default assistant identifier.
	 * @param array           $settings          Plugin settings array.
	 * @param WP_REST_Request $request           Current REST request.
	 * @return array
	 */
	protected function summarize_assistant_for_directory( \WP_Post $assistant_post, $default_assistant, array $settings, \WP_REST_Request $request ) {
		$assistant_id = absint( $assistant_post->ID );
		$config       = $this->get_assistant_configuration( $assistant_id );

		$provider = isset( $config['provider'] ) ? sanitize_key( $config['provider'] ) : '';
		if ( '' === $provider ) {
			$provider = isset( $settings['default_provider'] ) ? sanitize_key( $settings['default_provider'] ) : 'openai';
		}

		$model = isset( $config['model'] ) ? (string) $config['model'] : '';
		if ( '' === $model ) {
			if ( 'gemini' === $provider ) {
				$model = isset( $settings['default_gemini_model'] ) ? (string) $settings['default_gemini_model'] : '';
			} else {
				$model = isset( $settings['default_model'] ) ? (string) $settings['default_model'] : '';
			}
		}

		$temperature = isset( $config['temperature'] ) ? $config['temperature'] : null;
		if ( null !== $temperature ) {
			$temperature = floatval( $temperature );
		}

		$tools = array();
		if ( isset( $config['tools'] ) && is_array( $config['tools'] ) ) {
			foreach ( $config['tools'] as $tool_slug ) {
				$tool_slug = sanitize_key( $tool_slug );
				if ( '' !== $tool_slug ) {
					$tools[] = $tool_slug;
				}
			}

			$tools = array_values( array_unique( $tools ) );
		}

		$memory_files = 0;
		if ( isset( $config['memory_files'] ) && is_array( $config['memory_files'] ) ) {
			$memory_files = count( array_filter( array_map( 'absint', $config['memory_files'] ) ) );
		}

		$summary = array(
			'id'                  => $assistant_id,
			'title'               => get_the_title( $assistant_post ),
			'slug'                => $assistant_post->post_name,
			'status'              => $assistant_post->post_status,
			'is_default'          => ( absint( $default_assistant ) === $assistant_id ),
			'provider'            => $provider,
			'model'               => $model,
			'temperature'         => ( null === $temperature ? null : $temperature ),
			'tools'               => $tools,
			'tool_count'          => count( $tools ),
			'memory_file_count'   => $memory_files,
			'has_vector_store'    => ( isset( $config['vector_store_id'] ) && '' !== $config['vector_store_id'] ),
			'has_corpus'          => ( isset( $config['corpus_name'] ) && '' !== $config['corpus_name'] ),
			'has_external_action' => ( ! empty( $config['external_action_identifier'] ) ),
			'description'         => $this->get_assistant_directory_description( $assistant_post ),
			'updated_at'          => get_post_modified_time( 'c', true, $assistant_post ),
			'permalink'           => get_permalink( $assistant_post ),
		);

		/**
		 * Filter the assistant summary returned by the directory endpoint.
		 *
		 * @param array           $summary        Assistant summary array.
		 * @param WP_Post         $assistant_post Assistant post object.
		 * @param array           $config         Assistant configuration array.
		 * @param array           $settings       Plugin settings array.
		 * @param WP_REST_Request $request        Current REST request.
		 */
		return apply_filters( 'wp_mcp_ai_rest_assistant_summary', $summary, $assistant_post, $config, $settings, $request );
	}

	/**
	 * Build the capability metadata exposed alongside the assistant directory.
	 *
	 * @param array $response_data Current response payload.
	 * @return array
	 */
	protected function build_assistant_directory_capabilities( array $response_data ) {
		$capabilities = array();

		$capabilities['tools'] = array(
			'listChanged' => false,
		);

		$rest_links = array();
		if ( isset( $response_data['rest'] ) && is_array( $response_data['rest'] ) ) {
			$rest_links = $response_data['rest'];
		}

		$has_sse_route           = isset( $rest_links['sse'] ) && '' !== $rest_links['sse'];
		$has_file_download_route = isset( $rest_links['file_download'] ) && '' !== $rest_links['file_download'];

		if ( $has_sse_route || $has_file_download_route ) {
			$capabilities['resources'] = array(
				'subscribe'   => $has_sse_route,
				'listChanged' => false,
			);
		}

		/**
		 * Filter the capability metadata returned with the assistant directory response.
		 *
		 * @param array $capabilities  Capability metadata.
		 * @param array $response_data Current response payload.
		 */
		$capabilities = apply_filters( 'wp_mcp_ai_rest_assistant_capabilities', $capabilities, $response_data );

		return is_array( $capabilities ) ? $capabilities : array();
	}

	/**
	 * Generate a trimmed description for an assistant directory entry.
	 *
	 * @param WP_Post $assistant_post Assistant post object.
	 * @return string
	 */
	protected function get_assistant_directory_description( \WP_Post $assistant_post ) {
		$excerpt = get_post_field( 'post_excerpt', $assistant_post->ID );

		if ( '' === $excerpt ) {
			$content = get_post_field( 'post_content', $assistant_post->ID );
			$excerpt = wp_trim_words( wp_strip_all_tags( (string) $content ), 30, '&hellip;' );
		}

		$excerpt = wp_strip_all_tags( (string) $excerpt );

		return $excerpt;
	}

	// ─── Assistant create / delete ───────────────────────────────────

	/**
	 * Handle assistant creation.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistant_create( \WP_REST_Request $request ) {
		$title = sanitize_text_field( $request->get_param( 'title' ) );

		if ( empty( $title ) ) {
			return $this->error(
				'wp_mcp_ai_missing_title',
				__( 'Assistant title is required.', 'nvoos-content-graph-ai' ),
				400
			);
		}

		// Validate and sanitize post status.
		$allowed_statuses = array( 'draft', 'publish', 'private' );
		$status           = sanitize_key( $request->get_param( 'status' ) );
		if ( ! in_array( $status, $allowed_statuses, true ) ) {
			$status = 'draft';
		}

		// Create the assistant post.
		$post_data = array(
			'post_type'   => self::POST_TYPE,
			'post_title'  => $title,
			'post_status' => $status,
		);

		$description = $request->get_param( 'description' );
		if ( ! empty( $description ) ) {
			$post_data['post_content'] = wp_kses_post( $description );
		}

		$post_id = wp_insert_post( $post_data, true );
		if ( is_wp_error( $post_id ) ) {
			return $this->error(
				'wp_mcp_ai_create_failed',
				$post_id->get_error_message(),
				500
			);
		}

		// Save meta fields if provided with proper sanitization.
		$provider = $request->get_param( 'provider' );
		if ( null !== $provider ) {
			update_post_meta( $post_id, '_wp_mcp_ai_provider', sanitize_key( $provider ) );
		}

		$model = $request->get_param( 'model' );
		if ( null !== $model ) {
			update_post_meta( $post_id, '_wp_mcp_ai_model', sanitize_text_field( $model ) );
		}

		$temperature = $request->get_param( 'temperature' );
		if ( null !== $temperature ) {
			$temperature = floatval( $temperature );
			// Validate temperature is within acceptable range (0.0 to 2.0).
			$temperature = max( 0.0, min( 2.0, $temperature ) );
			update_post_meta( $post_id, '_wp_mcp_ai_temperature', $temperature );
		}

		$system_prompt = $request->get_param( 'system_prompt' );
		if ( null !== $system_prompt ) {
			update_post_meta( $post_id, '_wp_mcp_ai_system_prompt', wp_kses_post( $system_prompt ) );
		}

		$tools = $request->get_param( 'tools' );
		if ( null !== $tools && is_array( $tools ) ) {
			// Sanitize each tool slug.
			$tools = array_map( 'sanitize_key', $tools );
			update_post_meta( $post_id, '_wp_mcp_ai_tools', $tools );
		}

		return $this->success(
			array(
				'id'     => $post_id,
				'title'  => $title,
				'status' => get_post_status( $post_id ),
			),
			201
		);
	}

	/**
	 * Handle assistant deletion.
	 *
	 * @param WP_REST_Request $request REST request instance.
	 * @return WP_REST_Response|WP_Error
	 */
	public function handle_assistant_delete( \WP_REST_Request $request ) {
		$assistant_id = absint( $request->get_param( 'id' ) );

		if ( ! $assistant_id ) {
			return $this->error(
				'wp_mcp_ai_missing_assistant_id',
				__( 'Assistant ID is required.', 'nvoos-content-graph-ai' ),
				400
			);
		}

		// Check if assistant exists.
		$assistant = get_post( $assistant_id );
		if ( ! $assistant || self::POST_TYPE !== $assistant->post_type ) {
			return $this->error(
				'wp_mcp_ai_assistant_not_found',
				__( 'Assistant not found.', 'nvoos-content-graph-ai' ),
				404
			);
		}

		// Delete the assistant.
		$result = wp_delete_post( $assistant_id, true );
		if ( ! $result ) {
			return $this->error(
				'wp_mcp_ai_delete_failed',
				__( 'Failed to delete assistant.', 'nvoos-content-graph-ai' ),
				500
			);
		}

		return $this->success(
			array(
				'deleted' => true,
				'id'      => $assistant_id,
			)
		);
	}

	// ─── Access validation ───────────────────────────────────────────

	/**
	 * Ensure the current user can access the requested assistant post.
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return WP_Post|WP_Error
	 */
	protected function validate_assistant_access( $assistant_id ) {
		$assistant_id = absint( $assistant_id );

		// Per-request validation cache (mirrors the base hub).
		$cache_enabled = apply_filters(
			'wp_mcp_ai_assistant_access_cache_enabled',
			! defined( 'WP_MCP_AI_DISABLE_CACHE' ) || ! WP_MCP_AI_DISABLE_CACHE
		);

		if ( $cache_enabled ) {
			$cache_key = 'assistant_' . $assistant_id;
			if ( isset( self::$assistant_cache[ $cache_key ] ) ) {
				return self::$assistant_cache[ $cache_key ];
			}
		}

		$assistant_post = $assistant_id ? get_post( $assistant_id ) : null;

		if ( ! $assistant_post || self::POST_TYPE !== $assistant_post->post_type ) {
			$denied = new \WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
			if ( $cache_enabled ) {
				self::$assistant_cache[ 'assistant_' . $assistant_id ] = $denied;
			}
			return $denied;
		}

		if ( 'publish' !== $assistant_post->post_status && ! $this->user_can_access_post( $assistant_id ) ) {
			$denied = new \WP_Error(
				'wp_mcp_ai_assistant_forbidden',
				__( 'You do not have access to this assistant.', 'nvoos-content-graph-ai' ),
				array( 'status' => 403 )
			);
			if ( $cache_enabled ) {
				self::$assistant_cache[ 'assistant_' . $assistant_id ] = $denied;
			}
			return $denied;
		}

		// Cache the successful result.
		if ( $cache_enabled ) {
			self::$assistant_cache[ 'assistant_' . $assistant_id ] = $assistant_post;
		}
		return $assistant_post;
	}

	/**
	 * Check if the authenticated user can read a post.
	 *
	 * @param int $post_id Post identifier to check access for.
	 * @return bool Whether the user has read access to the post.
	 */
	protected function user_can_access_post( $post_id ) {
		// CG-AI has no token-authenticated user mapping yet (D-UI) —
		// always check the current session user.
		return current_user_can( 'read_post', $post_id );
	}

	// ─── Seams (per-install-mode) ────────────────────────────────────

	/**
	 * Read the active settings map (per-install-mode seam).
	 *
	 * @return array
	 */
	protected function get_settings() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			$settings = \WP_MCP_AI_Admin_Settings::get_settings();
			return is_array( $settings ) ? $settings : array();
		}

		// Standalone: CG settings store uses the ai_* key vocabulary —
		// alias the base vocabulary the directory logic reads.
		$all       = CoreBridge::instance()->settings->all();
		$aliased   = array(
			'default_assistant'     => $all['ai_default_assistant'] ?? ( $all['default_assistant'] ?? 0 ),
			'default_provider'      => $all['ai_default_provider'] ?? ( $all['default_provider'] ?? 'openai' ),
			'default_model'         => $all['ai_default_model'] ?? ( $all['default_model'] ?? '' ),
			'default_gemini_model'  => $all['ai_default_gemini_model'] ?? ( $all['default_gemini_model'] ?? '' ),
		);

		return $aliased;
	}

	/**
	 * Resolve the token-scoped assistant for this request.
	 *
	 * CG-AI has no bearer tokens yet — always returns 0 (unscoped). The
	 * base hub keeps full token scoping in monolith installs.
	 *
	 * @param int $assistant_id Requested assistant ID.
	 * @return int|WP_Error
	 */
	protected function apply_token_assistant_scope( $assistant_id ) {
		return 0;
	}

	/**
	 * Build the authentication context for the current request.
	 *
	 * @return array
	 */
	protected function get_auth_context() {
		return array(
			'token_authenticated' => false,
			'token_type'          => '',
		);
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
	 * Standalone assistant configuration reader — same meta keys and
	 * normalisation as the base CPT.
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
			'tools'                      => get_post_meta( $assistant_id, '_wp_mcp_ai_tools', true ),
			'provider'                   => get_post_meta( $assistant_id, '_wp_mcp_ai_provider', true ),
			'model'                      => get_post_meta( $assistant_id, '_wp_mcp_ai_model', true ),
			'temperature'                => get_post_meta( $assistant_id, '_wp_mcp_ai_temperature', true ),
			'system_prompt'              => get_post_meta( $assistant_id, '_wp_mcp_ai_system_prompt', true ),
			'memory_files'               => get_post_meta( $assistant_id, '_wp_mcp_ai_memory_files', true ),
			'vector_store_id'            => get_post_meta( $assistant_id, '_wp_mcp_ai_vector_store_id', true ),
			'corpus_name'                => get_post_meta( $assistant_id, '_wp_mcp_ai_corpus_name', true ),
			'external_action_identifier' => get_post_meta( $assistant_id, '_wp_mcp_ai_external_action_id', true ),
		);

		if ( ! is_array( $config['tools'] ) ) {
			$config['tools'] = array();
		}

		if ( ! is_string( $config['provider'] ) ) {
			$config['provider'] = '';
		} else {
			$provider = sanitize_key( $config['provider'] );

			$allowed_providers = apply_filters( 'wp_mcp_ai_allowed_providers', array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'ollama', 'lm_studio', 'cloudflare', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' ) );
			if ( ! is_array( $allowed_providers ) ) {
				$allowed_providers = array( 'openai', 'anthropic', 'gemini', 'huggingface', 'nvidia', 'ollama', 'lm_studio', 'cloudflare', 'deepseek', 'openrouter', 'digitalocean', 'kimi', 'baseten', 'embedded' );
			}

			$config['provider'] = in_array( $provider, $allowed_providers, true ) ? $provider : '';
		}

		if ( ! is_string( $config['model'] ) ) {
			$config['model'] = '';
		} else {
			$config['model'] = sanitize_text_field( $config['model'] );
		}

		return $config;
	}

	/**
	 * Read the cached assistant directory (per-install-mode seam).
	 *
	 * @param array $params Cache key parameters.
	 * @return array|false
	 */
	protected function get_cached_directory( array $params ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			return \WP_MCP_AI_REST_Cache::get_response( 'assistants', $params );
		}

		$cached = get_transient( 'wp_mcp_ai_rest_assistants_' . md5( (string) wp_json_encode( $params ) ) );
		return is_array( $cached ) ? $cached : false;
	}

	/**
	 * Store the assistant directory cache (per-install-mode seam).
	 *
	 * @param array $params Cache key parameters.
	 * @param array $data   Directory data to cache.
	 * @return void
	 */
	protected function set_cached_directory( array $params, array $data ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_REST_Cache' ) ) {
			\WP_MCP_AI_REST_Cache::set_response( 'assistants', $params, $data, self::CACHE_TTL );
			return;
		}

		set_transient( 'wp_mcp_ai_rest_assistants_' . md5( (string) wp_json_encode( $params ) ), $data, self::CACHE_TTL );
	}

	// ─── Helpers ─────────────────────────────────────────────────────

	/**
	 * Normalise a REST URL so loopback hosts match the current request host.
	 *
	 * Ported from the base `WP_MCP_AI_Request_Context::normalise_rest_url()`.
	 *
	 * @param string $url Absolute REST URL generated by rest_url().
	 * @return string Normalised URL using the current request host when possible.
	 */
	protected function normalise_rest_url( $url ) {
		if ( ! is_string( $url ) || '' === $url ) {
			return $url;
		}

		$parts = wp_parse_url( $url );
		if ( empty( $parts ) || empty( $parts['host'] ) ) {
			return $url;
		}

		if ( ! $this->is_loopback_host( $parts['host'] ) ) {
			return $url;
		}

		$request_host = $this->get_current_request_host();
		if ( empty( $request_host ) || empty( $request_host['host'] ) ) {
			return $url;
		}

		$parts['host'] = $request_host['host'];

		if ( isset( $request_host['port'] ) && null !== $request_host['port'] ) {
			$parts['port'] = $request_host['port'];
		} else {
			unset( $parts['port'] );
		}

		$parts['scheme'] = $this->determine_request_scheme( $parts );

		$normalised = $this->build_url_from_parts( $parts );

		/**
		 * Filter the normalised REST URL before returning it.
		 *
		 * @param string $normalised Normalised REST URL.
		 * @param string $url        Original URL provided to the helper.
		 */
		return apply_filters( 'wp_mcp_ai_normalised_rest_url', $normalised, $url );
	}

	/**
	 * Determine whether a host refers to a loopback address.
	 *
	 * @param string $host Host portion of a URL.
	 * @return bool
	 */
	protected function is_loopback_host( $host ) {
		$host = strtolower( trim( (string) $host ) );

		if ( '' === $host ) {
			return false;
		}

		if ( 'localhost' === $host || '[::1]' === $host ) {
			return true;
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			return in_array( $host, array( '::1', '0:0:0:0:0:0:0:1' ), true );
		}

		if ( filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ) {
			return 0 === strpos( $host, '127.' );
		}

		return false;
	}

	/**
	 * Retrieve the current request host for proxying external REST requests.
	 *
	 * @return array|null Array with `host` and optional `port` or null when unavailable.
	 */
	protected function get_current_request_host() {
		$host_header = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_HOST'] ) ) {
			$forwarded_raw   = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_HOST'] ) );
			$forwarded_hosts = explode( ',', $forwarded_raw );
			$host_header     = trim( reset( $forwarded_hosts ) );
		}

		if ( '' === $host_header && ! empty( $_SERVER['HTTP_HOST'] ) ) {
			$host_header = trim( sanitize_text_field( wp_unslash( $_SERVER['HTTP_HOST'] ) ) );
		}

		if ( '' === $host_header && ! empty( $_SERVER['SERVER_NAME'] ) ) {
			$host_header = trim( sanitize_text_field( wp_unslash( $_SERVER['SERVER_NAME'] ) ) );
		}

		if ( '' === $host_header ) {
			return null;
		}

		$host_header = preg_replace( '#\s+#', '', $host_header );
		if ( '' === $host_header ) {
			return null;
		}

		$parsed = wp_parse_url( 'http://' . $host_header );
		if ( empty( $parsed ) || empty( $parsed['host'] ) ) {
			return null;
		}

		$result = array(
			'host' => strtolower( $parsed['host'] ),
		);

		if ( isset( $parsed['port'] ) ) {
			$result['port'] = absint( $parsed['port'] );
		}

		return $result;
	}

	/**
	 * Determine the best-fit scheme for proxied REST requests.
	 *
	 * @param array $parts Parsed REST URL parts.
	 * @return string
	 */
	protected function determine_request_scheme( array $parts ) {
		$scheme = '';

		if ( ! empty( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) ) {
			$forwarded_proto  = sanitize_text_field( wp_unslash( $_SERVER['HTTP_X_FORWARDED_PROTO'] ) );
			$proto_candidates = explode( ',', $forwarded_proto );
			$proto            = strtolower( trim( reset( $proto_candidates ) ) );

			if ( in_array( $proto, array( 'http', 'https' ), true ) ) {
				$scheme = $proto;
			}
		}

		if ( '' === $scheme && ! empty( $_SERVER['REQUEST_SCHEME'] ) ) {
			$candidate = strtolower( trim( sanitize_text_field( wp_unslash( $_SERVER['REQUEST_SCHEME'] ) ) ) );

			if ( in_array( $candidate, array( 'http', 'https' ), true ) ) {
				$scheme = $candidate;
			}
		}

		if ( '' === $scheme && function_exists( 'is_ssl' ) && is_ssl() ) {
			$scheme = 'https';
		}

		if ( '' === $scheme && isset( $_SERVER['SERVER_PORT'] ) ) {
			$port = absint( wp_unslash( $_SERVER['SERVER_PORT'] ) );

			if ( 443 === $port ) {
				$scheme = 'https';
			} elseif ( 80 === $port ) {
				$scheme = 'http';
			}
		}

		if ( '' === $scheme && isset( $parts['scheme'] ) && '' !== $parts['scheme'] ) {
			$scheme = $parts['scheme'];
		}

		if ( '' === $scheme ) {
			$scheme = 'http';
		}

		return $scheme;
	}

	/**
	 * Assemble a URL string from parsed components.
	 *
	 * @param array $parts Parsed URL components.
	 * @return string
	 */
	protected function build_url_from_parts( array $parts ) {
		$scheme = isset( $parts['scheme'] ) && '' !== $parts['scheme'] ? $parts['scheme'] . '://' : '';
		$host   = isset( $parts['host'] ) ? $parts['host'] : '';

		if ( '' !== $host && filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
			$host = '[' . $host . ']';
		}

		$port = '';
		if ( isset( $parts['port'] ) && $parts['port'] ) {
			$port = ':' . absint( $parts['port'] );
		}

		$path = isset( $parts['path'] ) ? $parts['path'] : '';
		$query = '';
		if ( isset( $parts['query'] ) && '' !== $parts['query'] ) {
			$query = '?' . $parts['query'];
		}

		return $scheme . $host . $port . $path . $query;
	}

	/**
	 * Build a REST error envelope.
	 *
	 * @param string $code    Error code.
	 * @param string $message Error message.
	 * @param int    $status  HTTP status code.
	 * @return WP_Error
	 */
	protected function error( $code, $message, $status = 400 ) {
		return new \WP_Error(
			$code,
			$message,
			array(
				'status' => $status,
			)
		);
	}

	/**
	 * Build a REST success response.
	 *
	 * @param mixed $data   Response data.
	 * @param int   $status HTTP status code.
	 * @return WP_REST_Response
	 */
	protected function success( $data, $status = 200 ) {
		$response = new \WP_REST_Response( $data, $status );

		$response->set_headers(
			array(
				'X-WP-MCP-AI-Version' => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : ( defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : 'dev' ),
			)
		);

		return $response;
	}
}
