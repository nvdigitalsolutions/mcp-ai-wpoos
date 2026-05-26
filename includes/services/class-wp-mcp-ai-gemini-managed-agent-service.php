<?php
/**
 * Gemini Managed Agent / Antigravity Service
 *
 * Provides an interface for running agentic tasks using Gemini's Managed
 * Agents (Antigravity) via the Interactions API. A single API call spins
 * up a full agent inside an isolated Linux container with:
 *
 * - Code execution (Python, JavaScript, Bash)
 * - Filesystem (persistent across environment reuse)
 * - Web access (Google Search, URL fetching)
 * - Streaming SSE responses
 * - Multi-turn conversations (previous_interaction_id)
 * - Environment reuse (persist files/packages across calls)
 * - Context compaction (automatic at ~135k tokens)
 *
 * This service wraps the real Interactions API:
 *   POST /v1beta/interactions
 *   GET  /v1beta/files/environment-{id}:download
 *   POST /v1beta/agents (custom managed agents)
 *
 * The Antigravity agent is a self-contained agent with its own tool loop
 * (plan → act → observe → repeat). It uses built-in tools (code_execution,
 * google_search, url_context, filesystem) — it does NOT yet support
 * external function calling or MCP. This is distinct from the existing
 * run_with_tools() loop which orchestrates NV oOS WordPress tools.
 *
 * @since 1.2.0 — original managed agent service (speculative pre-release API)
 * @since 2.x   — rewritten for the actual Antigravity Interactions API (May 2026)
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Gemini Managed Agent / Antigravity Service.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Gemini_Managed_Agent_Service {

	/**
	 * Interactions API endpoint.
	 *
	 * @var string
	 */
	const INTERACTIONS_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/interactions';

	/**
	 * Managed Agents endpoint (create/save custom agents).
	 *
	 * @var string
	 */
	const AGENTS_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/agents';

	/**
	 * Environment file download endpoint template.
	 *
	 * @var string
	 */
	const ENV_DOWNLOAD_ENDPOINT = 'https://generativelanguage.googleapis.com/v1beta/files/environment-%s:download';

	/**
	 * Current Antigravity agent ID.
	 *
	 * @var string
	 */
	const ANTIGRAVITY_AGENT_ID = 'antigravity-preview-05-2026';

	/**
	 * Required API revision header value.
	 *
	 * @var string
	 */
	const API_REVISION = '2026-05-20';

	/**
	 * Default model for managed agents.
	 *
	 * @var string
	 */
	const DEFAULT_MODEL = 'gemini-3.5-flash';

	/**
	 * Default timeout for agent operations in seconds.
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 300;

	/**
	 * Maximum agent timeout in seconds.
	 *
	 * @var int
	 */
	const MAX_TIMEOUT = 3600;

	/**
	 * Transient prefix for agent session storage (environment tracking).
	 *
	 * @var string
	 */
	const SESSION_PREFIX = 'wp_mcp_ai_agent_session_';

	/**
	 * Maximum session age in seconds (24 hours).
	 *
	 * @var int
	 */
	const MAX_SESSION_AGE = 86400;

	/**
	 * Available built-in tools for the Antigravity agent.
	 *
	 * These are the agent's native tools — NOT WordPress function-calling tools.
	 *
	 * @var array
	 */
	const BUILTIN_TOOLS = array(
		'code_execution',
		'google_search',
		'url_context',
		'filesystem', // Auto-enabled via environment parameter.
	);

	/**
	 * Check if Managed Agents / Antigravity API is available.
	 *
	 * The Interactions API is now live (since May 2026). This gate remains
	 * for sites that wish to explicitly opt-in or for environments where
	 * the API key may not support the Interactions endpoint yet.
	 *
	 * @return bool True if Managed Agents API is available.
	 */
	public static function is_managed_agents_available() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		if ( ! empty( $settings['enable_managed_agents'] ) ) {
			return true;
		}

		/**
		 * Filter: wp_mcp_ai_managed_agents_available
		 *
		 * Override to force-enable or force-disable managed agents
		 * regardless of the admin setting.
		 *
		 * @since 1.2.0
		 * @param bool $available Whether Managed Agents API is available.
		 */
		return apply_filters( 'wp_mcp_ai_managed_agents_available', false );
	}

	/**
	 * Get the API key.
	 *
	 * @return string API key or empty string.
	 */
	protected function get_api_key() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return isset( $settings['gemini_api_key'] ) ? $settings['gemini_api_key'] : '';
	}

	/**
	 * Send a prompt to the Antigravity agent via the Interactions API.
	 *
	 * A single call provisions a sandbox, runs the agent loop (plan → act →
	 * observe → repeat), and returns the result. Pass an environment ID to
	 * reuse files/packages from a previous interaction.
	 *
	 * @param array $args {
	 *     Interaction arguments.
	 *
	 *     @type string       $input                    The task for the agent (text or multimodal array).
	 *     @type string       $environment              "remote" for fresh, an env ID to reuse, or a config array.
	 *     @type string       $previous_interaction_id   Continue a conversation (preserves chat history).
	 *     @type string       $agent                    Agent ID (default: antigravity-preview-05-2026).
	 *     @type string       $system_instruction        System instructions for the agent.
	 *     @type array        $tools                    Built-in tools to enable. If empty, all defaults enabled.
	 *                                                   Valid: "code_execution", "google_search", "url_context".
	 *     @type array        $input_parts               Multimodal input parts (each with type + data/text/mime_type).
	 *     @type bool         $stream                    Whether to stream the response (default: false).
	 *     @type int          $timeout                   HTTP timeout in seconds (default: 300).
	 *     @type callable     $on_stream_event           Callback for stream events (only when stream=true).
	 * }
	 * @return array|WP_Error Interaction result or error.
	 */
	public function send_interaction( array $args ) {
		if ( ! self::is_managed_agents_available() ) {
			return new WP_Error(
				'wp_mcp_ai_managed_agents_unavailable',
				__( 'Managed Agents are not enabled. Enable them in Settings → NV oOS → Providers → Gemini.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Resolve input.
		$input                = isset( $args['input'] ) ? $args['input'] : '';
		$has_multimodal_input = ! empty( $args['input_parts'] ) && is_array( $args['input_parts'] );

		if ( ! $has_multimodal_input && empty( $input ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'An input prompt (text or multimodal parts) is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Resolve environment.
		$environment = isset( $args['environment'] ) ? $args['environment'] : 'remote';

		// Resolve agent.
		$agent = isset( $args['agent'] ) ? sanitize_text_field( $args['agent'] ) : self::ANTIGRAVITY_AGENT_ID;

		// Resolve tools.
		$tool_overrides = isset( $args['tools'] ) ? (array) $args['tools'] : null;

		// Resolve timeout.
		$timeout = isset( $args['timeout'] ) ? absint( $args['timeout'] ) : self::DEFAULT_TIMEOUT;
		if ( $timeout > self::MAX_TIMEOUT ) {
			$timeout = self::MAX_TIMEOUT;
		}

		// Resolve streaming.
		$stream = isset( $args['stream'] ) && $args['stream'];

		// Build the interaction payload.
		$payload = array(
			'agent'       => $agent,
			'environment' => $this->normalise_environment( $environment ),
		);

		// Build input — text or multimodal.
		if ( $has_multimodal_input ) {
			$payload['input'] = $this->build_multimodal_input( $args['input_parts'] );
		} else {
			$payload['input'] = array(
				array(
					'type' => 'text',
					'text' => sanitize_textarea_field( $input ),
				),
			);
		}

		// Add previous interaction for multi-turn.
		if ( ! empty( $args['previous_interaction_id'] ) ) {
			$payload['previous_interaction_id'] = sanitize_text_field( $args['previous_interaction_id'] );
		}

		// Add system instruction.
		if ( ! empty( $args['system_instruction'] ) ) {
			$payload['system_instruction'] = sanitize_textarea_field( $args['system_instruction'] );
		}

		// Add tool restrictions.
		if ( is_array( $tool_overrides ) && ! empty( $tool_overrides ) ) {
			$payload['tools'] = $this->build_tool_list( $tool_overrides );
		}

		// Add streaming flag.
		if ( $stream ) {
			$payload['stream'] = true;
		}

		/**
		 * Filter: wp_mcp_ai_managed_agent_interaction_payload
		 *
		 * @since 2.x
		 * @param array $payload The interaction payload.
		 * @param array $args    Original arguments.
		 */
		$payload = apply_filters( 'wp_mcp_ai_managed_agent_interaction_payload', $payload, $args );

		// Log the interaction start.
		$input_preview = $has_multimodal_input
			? __( '[multimodal input]', 'mcp-ai-wpoos' )
			: substr( $input, 0, 120 );

		WP_MCP_AI_Logger::log_event(
			'managed_agent_interaction_start',
			'Starting Antigravity agent interaction',
			array(
				'agent'       => $agent,
				'input'       => $input_preview,
				'environment' => is_array( $environment ) ? 'custom' : $environment,
				'stream'      => $stream,
			)
		);

		// Send the request.
		if ( $stream ) {
			$on_event = isset( $args['on_stream_event'] ) ? $args['on_stream_event'] : null;
			$result   = $this->api_request_stream( $payload, $timeout, $on_event );
		} else {
			$result = $this->api_request( 'POST', self::INTERACTIONS_ENDPOINT, $payload, $timeout );
		}

		if ( is_wp_error( $result ) ) {
			WP_MCP_AI_Logger::log_error(
				'Managed agent interaction failed',
				array(
					'input' => $input_preview,
					'error' => $result->get_error_message(),
				)
			);
			return $result;
		}

		// Track the environment for later reuse.
		if ( ! empty( $result['environment_id'] ) ) {
			$this->track_environment( $result['environment_id'], $result );
		}

		WP_MCP_AI_Logger::log_event(
			'managed_agent_interaction_complete',
			'Antigravity agent interaction completed',
			array(
				'interaction_id' => isset( $result['id'] ) ? $result['id'] : '',
				'environment_id' => isset( $result['environment_id'] ) ? $result['environment_id'] : '',
			)
		);

		return $this->normalise_interaction_result( $result );
	}

	/**
	 * Continue a previous interaction (multi-turn conversation).
	 *
	 * Carries forward both conversation context and the sandbox environment
	 * (files, packages) from a previous interaction.
	 *
	 * @param string $previous_interaction_id The previous interaction ID.
	 * @param string $input                   The follow-up task.
	 * @param array  $args                    Additional args (see send_interaction).
	 * @return array|WP_Error
	 */
	public function continue_interaction( $previous_interaction_id, $input, array $args = array() ) {
		$args['previous_interaction_id'] = $previous_interaction_id;

		// If no explicit environment, try to reuse from tracked sessions.
		if ( empty( $args['environment'] ) ) {
			$env_id = $this->get_environment_for_interaction( $previous_interaction_id );
			if ( ! empty( $env_id ) ) {
				$args['environment'] = $env_id;
			}
		}

		$args['input'] = $input;

		return $this->send_interaction( $args );
	}

	/**
	 * Download files from an agent environment sandbox.
	 *
	 * @param string $environment_id The environment ID.
	 * @param string $save_path      Optional local path to save the tar file.
	 * @return array|WP_Error {
	 *     On success: array with 'tar_data' (raw bytes), 'save_path' (if saved).
	 * }
	 */
	public function download_environment_files( $environment_id, $save_path = '' ) {
		if ( ! self::is_managed_agents_available() ) {
			return new WP_Error(
				'wp_mcp_ai_managed_agents_unavailable',
				__( 'Managed Agents are not enabled.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$endpoint = sprintf( self::ENV_DOWNLOAD_ENDPOINT, rawurlencode( $environment_id ) );
		$full_url = add_query_arg( 'alt', 'media', $endpoint );

		$response = wp_remote_get(
			$full_url,
			array(
				'headers'     => $this->build_headers( $api_key ),
				'timeout'     => 120,
				'redirection' => 5,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );

		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_env_download_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Failed to download environment files (HTTP %d).', 'mcp-ai-wpoos' ),
					$code
				),
				array( 'status' => $code )
			);
		}

		$tar_data = wp_remote_retrieve_body( $response );
		$result   = array(
			'tar_data' => $tar_data,
			'size'     => strlen( $tar_data ),
		);

		// Optionally save to disk.
		if ( ! empty( $save_path ) ) {
			$upload_dir = wp_upload_dir();
			$full_save  = trailingslashit( $upload_dir['basedir'] ) . ltrim( $save_path, '/' );

			// Ensure directory exists.
			$save_dir = dirname( $full_save );
			if ( ! is_dir( $save_dir ) ) {
				wp_mkdir_p( $save_dir );
			}

			// Write the tar file.
			$written = file_put_contents( $full_save, $tar_data ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents

			if ( false === $written ) {
				return new WP_Error(
					'wp_mcp_ai_env_save_failed',
					__( 'Failed to save environment archive to disk.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			$result['save_path'] = $full_save;
			$result['save_url']  = trailingslashit( $upload_dir['baseurl'] ) . ltrim( $save_path, '/' );
		}

		return $result;
	}

	/**
	 * List tracked environment sessions.
	 *
	 * @return array List of environment summaries.
	 */
	public function list_environments() {
		global $wpdb;

		$prefix = self::SESSION_PREFIX . 'env_';
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$transients = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT option_name, option_value FROM {$wpdb->options} WHERE option_name LIKE %s",
				$wpdb->esc_like( '_transient_' . $prefix ) . '%'
			)
		);

		$environments = array();

		foreach ( $transients as $row ) {
			$name = str_replace( '_transient_', '', $row->option_name );
			$data = maybe_unserialize( $row->option_value );

			if ( ! is_array( $data ) ) {
				continue;
			}

			$environments[] = array(
				'environment_id'    => $data['environment_id'] ?? '',
				'interaction_id'    => $data['last_interaction_id'] ?? '',
				'created_at'        => isset( $data['created_at'] ) ? gmdate( 'c', $data['created_at'] ) : null,
				'last_used_at'      => isset( $data['last_used_at'] ) ? gmdate( 'c', $data['last_used_at'] ) : null,
				'interaction_count' => $data['interaction_count'] ?? 0,
			);
		}

		return $environments;
	}

	/**
	 * Forget a tracked environment (does not delete server-side).
	 *
	 * @param string $environment_id Environment ID to forget locally.
	 * @return bool True if deleted, false if not found.
	 */
	public function forget_environment( $environment_id ) {
		return delete_transient( self::SESSION_PREFIX . 'env_' . $environment_id );
	}

	/**
	 * Create (save) a custom managed agent.
	 *
	 * Extends the Antigravity base agent with your own system instructions
	 * and environment sources (inline content, GitHub repos, GCS buckets).
	 * Once saved, invoke it via send_interaction() using the returned agent ID.
	 *
	 * @param array $args {
	 *     Agent definition.
	 *
	 *     @type string $id                 Unique agent ID (e.g. "my-data-analyst").
	 *     @type string $system_instruction System instructions for the agent.
	 *     @type string $display_name       Human-readable display name.
	 *     @type string $description        Agent description.
	 *     @type array  $base_environment    Environment config with optional sources.
	 *     @type string $base_agent         Base agent ID (default: antigravity-preview-05-2026).
	 * }
	 * @return array|WP_Error Saved agent info or error.
	 */
	public function create_managed_agent( array $args ) {
		if ( ! self::is_managed_agents_available() ) {
			return new WP_Error(
				'wp_mcp_ai_managed_agents_unavailable',
				__( 'Managed Agents are not enabled.', 'mcp-ai-wpoos' ),
				array( 'status' => 503 )
			);
		}

		$api_key = $this->get_api_key();

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_api_key',
				__( 'Gemini API key is not configured.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$agent_id = isset( $args['id'] ) ? sanitize_key( $args['id'] ) : '';

		if ( empty( $agent_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_agent_id',
				__( 'A unique agent ID is required (e.g. "my-data-analyst").', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$payload = array(
			'id'         => $agent_id,
			'base_agent' => isset( $args['base_agent'] )
				? sanitize_text_field( $args['base_agent'] )
				: self::ANTIGRAVITY_AGENT_ID,
		);

		if ( ! empty( $args['system_instruction'] ) ) {
			$payload['system_instruction'] = sanitize_textarea_field( $args['system_instruction'] );
		}

		if ( ! empty( $args['display_name'] ) ) {
			$payload['display_name'] = sanitize_text_field( $args['display_name'] );
		}

		if ( ! empty( $args['description'] ) ) {
			$payload['description'] = sanitize_textarea_field( $args['description'] );
		}

		// Build base_environment with optional sources.
		if ( ! empty( $args['base_environment'] ) && is_array( $args['base_environment'] ) ) {
			$payload['base_environment'] = $this->build_agent_environment( $args['base_environment'] );
		}

		$payload = apply_filters( 'wp_mcp_ai_managed_agent_create_payload', $payload, $args );

		WP_MCP_AI_Logger::log_event(
			'managed_agent_create',
			'Creating custom managed agent',
			array( 'agent_id' => $agent_id )
		);

		$result = $this->api_request( 'POST', self::AGENTS_ENDPOINT, $payload );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return array(
			'id'           => isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : $agent_id,
			'display_name' => isset( $result['display_name'] ) ? sanitize_text_field( $result['display_name'] ) : '',
			'base_agent'   => $payload['base_agent'],
			'created_at'   => gmdate( 'c' ),
		);
	}

	/**
	 * Build a base_environment config for agent creation.
	 *
	 * @param array $env Environment spec.
	 * @return array Environment config.
	 */
	protected function build_agent_environment( array $env ) {
		$config = array(
			'type' => isset( $env['type'] ) ? sanitize_text_field( $env['type'] ) : 'remote',
		);

		if ( ! empty( $env['sources'] ) && is_array( $env['sources'] ) ) {
			$config['sources'] = array();

			foreach ( $env['sources'] as $source ) {
				if ( ! isset( $source['type'], $source['target'] ) ) {
					continue;
				}

				$source_type = sanitize_text_field( $source['type'] );
				$target      = sanitize_text_field( $source['target'] );

				$source_obj = array(
					'type'   => $source_type,
					'target' => $target,
				);

				switch ( $source_type ) {
					case 'inline':
						$source_obj['content'] = isset( $source['content'] )
							? sanitize_textarea_field( $source['content'] )
							: '';
						break;

					case 'repository':
						$source_obj['source'] = isset( $source['source'] )
							? esc_url_raw( $source['source'] )
							: '';
						break;

					case 'gcs':
						$source_obj['source'] = isset( $source['source'] )
							? sanitize_text_field( $source['source'] )
							: '';
						break;
				}

				$config['sources'][] = $source_obj;
			}
		}

		return $config;
	}

	// -------------------------------------------------------------------------
	// Builders
	// -------------------------------------------------------------------------

	/**
	 * Normalise the environment parameter to the API's expected format.
	 *
	 * Accepts:
	 * - "remote" string → fresh sandbox
	 * - "env_abc123" string → reuse existing environment
	 * - array → passed through as EnvironmentConfig
	 *
	 * @param string|array $environment Environment spec.
	 * @return string|array
	 */
	protected function normalise_environment( $environment ) {
		if ( is_array( $environment ) ) {
			return $environment;
		}

		$env = trim( (string) $environment );

		if ( '' === $env ) {
			return 'remote';
		}

		return $env;
	}

	/**
	 * Build the tools array for the API payload.
	 *
	 * @param array $tool_slugs Array of tool type strings.
	 * @return array Array of { "type": "..." } objects.
	 */
	protected function build_tool_list( array $tool_slugs ) {
		$tools = array();

		foreach ( $tool_slugs as $slug ) {
			$slug = sanitize_key( $slug );

			if ( ! in_array( $slug, self::BUILTIN_TOOLS, true ) ) {
				continue;
			}

			// filesystem is auto-enabled via environment, don't pass explicitly.
			if ( 'filesystem' === $slug ) {
				continue;
			}

			$tools[] = array( 'type' => $slug );
		}

		return $tools;
	}

	/**
	 * Build multimodal input from parts.
	 *
	 * @param array $parts Array of part arrays.
	 *                     Text:  { type: "text", text: "..." }.
	 *                     Image: { type: "image", data: "<base64>", mime_type: "image/png" }.
	 * @return array Formatted input array.
	 */
	protected function build_multimodal_input( array $parts ) {
		$input = array();

		foreach ( $parts as $part ) {
			if ( ! isset( $part['type'] ) ) {
				continue;
			}

			$type = sanitize_text_field( $part['type'] );

			if ( 'text' === $type && isset( $part['text'] ) ) {
				$input[] = array(
					'type' => 'text',
					'text' => sanitize_textarea_field( $part['text'] ),
				);
			} elseif ( 'image' === $type && isset( $part['data'] ) ) {
				$input[] = array(
					'type'      => 'image',
					'data'      => $part['data'], // base64 string — already encoded by caller.
					'mime_type' => isset( $part['mime_type'] ) ? sanitize_text_field( $part['mime_type'] ) : 'image/png',
				);
			}
		}

		return $input;
	}

	/**
	 * Build API request headers including Api-Revision.
	 *
	 * @param string $api_key      The Gemini API key.
	 * @param string $content_type Content-Type header value.
	 * @return array
	 */
	protected function build_headers( $api_key, $content_type = 'application/json' ) {
		return array(
			'Content-Type'   => $content_type,
			'x-goog-api-key' => $api_key,
			'Api-Revision'   => self::API_REVISION,
		);
	}

	// -------------------------------------------------------------------------
	// API Communication
	// -------------------------------------------------------------------------

	/**
	 * Make a synchronous API request.
	 *
	 * @param string $method   HTTP method.
	 * @param string $endpoint API endpoint URL.
	 * @param array  $payload  Request payload.
	 * @param int    $timeout  Request timeout in seconds.
	 * @return array|WP_Error Response data or error.
	 */
	protected function api_request( $method, $endpoint, $payload = array(), $timeout = 60 ) {
		$api_key = $this->get_api_key();

		$request_args = array(
			'headers' => $this->build_headers( $api_key ),
			'timeout' => $timeout,
		);

		if ( ! empty( $payload ) ) {
			$request_args['body'] = wp_json_encode( $payload );
		}

		$response = 'GET' === $method
			? wp_remote_get( $endpoint, $request_args )
			: wp_remote_post( $endpoint, $request_args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$code = wp_remote_retrieve_response_code( $response );
		$body = wp_remote_retrieve_body( $response );
		$data = json_decode( $body, true );

		if ( $code < 200 || $code >= 300 ) {
			return $this->parse_api_error( $code, $data, $body );
		}

		return $data;
	}

	/**
	 * Make a streaming API request (SSE).
	 *
	 * Reads the response body line by line, parsing SSE events.
	 * Calls the optional callback for each parsed event.
	 *
	 * Note: Uses cURL directly because WordPress HTTP API does not
	 * natively support SSE streaming with incremental reads.
	 *
	 * @param array    $payload  Request payload.
	 * @param int      $timeout  Request timeout in seconds.
	 * @param callable $on_event Optional callback( array $event ).
	 * @return array|WP_Error Aggregated result or error.
	 */
	protected function api_request_stream( $payload, $timeout = 300, $on_event = null ) {
		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt_array,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_close
		$api_key = $this->get_api_key();

		$payload['stream'] = true;

		$request_args = array(
			'headers' => $this->build_headers( $api_key ),
			'timeout' => $timeout,
			'body'    => wp_json_encode( $payload ),
			'stream'  => true,
		);

		// WordPress HTTP API does not natively support SSE streaming.
		// Use a direct cURL / PHP stream approach for reliability.
		$url = self::INTERACTIONS_ENDPOINT;

		$ch = curl_init();

		curl_setopt_array(
			$ch,
			array(
				CURLOPT_URL            => $url,
				CURLOPT_POST           => true,
				CURLOPT_POSTFIELDS     => wp_json_encode( $payload ),
				CURLOPT_HTTPHEADER     => array(
					'Content-Type: application/json',
					'x-goog-api-key: ' . $api_key,
					'Api-Revision: ' . self::API_REVISION,
				),
				CURLOPT_RETURNTRANSFER => false,
				CURLOPT_TIMEOUT        => $timeout,
				CURLOPT_WRITEFUNCTION  => function ( $ch, $data ) use ( &$buffer, &$aggregated, $on_event ) {
					$buffer .= $data;

					// Process complete SSE events (delimited by double newline).
					while ( false !== strpos( $buffer, "\n\n" ) ) {
						$pos        = strpos( $buffer, "\n\n" );
						$chunk      = substr( $buffer, 0, $pos );
						$buffer     = substr( $buffer, $pos + 2 );

						$lines = explode( "\n", trim( $chunk ) );
						$event = array();

						foreach ( $lines as $line ) {
							if ( 0 === strpos( $line, 'data: ' ) ) {
								$data_json = substr( $line, 6 );
								$parsed    = json_decode( $data_json, true );

								if ( is_array( $parsed ) ) {
									$event = array_merge( $event, $parsed );
								}
							}
						}

						if ( ! empty( $event ) ) {
							$aggregated[] = $event;

							if ( is_callable( $on_event ) ) {
								call_user_func( $on_event, $event );
							}
						}
					}

					return strlen( $data );
				},
			)
		);

		$buffer     = '';
		$aggregated = array();

		curl_exec( $ch );
		$http_code  = curl_getinfo( $ch, CURLINFO_HTTP_CODE );
		$curl_error = curl_error( $ch );
		curl_close( $ch );

		if ( $curl_error ) {
			return new WP_Error(
				'wp_mcp_ai_stream_failed',
				$curl_error,
				array( 'status' => 500 )
			);
		}

		if ( $http_code < 200 || $http_code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_interaction_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Antigravity agent interaction failed (HTTP %d).', 'mcp-ai-wpoos' ),
					$http_code
				),
				array( 'status' => $http_code )
			);
		}

		// Build a result from the aggregated events.
		return $this->aggregate_stream_events( $aggregated );
	}
	// phpcs:enable

	// -------------------------------------------------------------------------
	// API Response Parsing
	// -------------------------------------------------------------------------

	/**
	 * Parse an API error response.
	 *
	 * @param int        $code HTTP status code.
	 * @param array|null $data Decoded JSON body.
	 * @param string     $body Raw response body (reserved for future use).
	 * @return WP_Error
	 */
	protected function parse_api_error( $code, $data, $body ) {
		// $body is reserved for future error-body diagnostics.
		unset( $body );
		$error_message = __( 'Antigravity agent request failed.', 'mcp-ai-wpoos' );
		$error_code    = 'wp_mcp_ai_agent_request_failed';

		if ( isset( $data['error']['message'] ) ) {
			$api_error     = $data['error']['message'];
			$error_message = $api_error;

			if ( false !== stripos( $api_error, 'not found' ) ) {
				$error_code    = 'wp_mcp_ai_managed_agents_unavailable';
				$error_message = __( 'The Antigravity agent endpoint was not found. Verify your API key has access to the Interactions API and the Api-Revision header is correct.', 'mcp-ai-wpoos' );
			} elseif ( false !== stripos( $api_error, 'quota' ) ) {
				$error_code = 'wp_mcp_ai_quota_exceeded';
			} elseif ( false !== stripos( $api_error, 'api key' ) || false !== stripos( $api_error, 'API key' ) ) {
				$error_code = 'wp_mcp_ai_invalid_api_key';
			} elseif ( false !== stripos( $api_error, 'temperature' ) || false !== stripos( $api_error, 'top_p' ) || false !== stripos( $api_error, 'generation' ) ) {
				$error_message = $api_error . ' ' . __( '(Note: The Antigravity agent does not support generation config parameters like temperature, top_p, or max_output_tokens.)', 'mcp-ai-wpoos' );
			}
		}

		return new WP_Error( $error_code, $error_message, array( 'status' => $code ) );
	}

	/**
	 * Normalise an interaction result into a consistent format.
	 *
	 * @param array $result Raw API response.
	 * @return array Normalised result.
	 */
	protected function normalise_interaction_result( $result ) {
		$output = array(
			'interaction_id' => isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '',
			'environment_id' => isset( $result['environment_id'] ) ? sanitize_text_field( $result['environment_id'] ) : '',
			'output_text'    => isset( $result['output_text'] ) ? wp_kses_post( $result['output_text'] ) : '',
			'steps'          => isset( $result['steps'] ) ? $result['steps'] : array(),
			'finish_reason'  => isset( $result['finish_reason'] ) ? sanitize_text_field( $result['finish_reason'] ) : '',
			'usage'          => isset( $result['usage'] ) ? $result['usage'] : null,
		);

		// Count tool calls if steps are present.
		if ( ! empty( $output['steps'] ) ) {
			$tool_calls = array();
			foreach ( $output['steps'] as $step ) {
				if ( isset( $step['tool_calls'] ) && is_array( $step['tool_calls'] ) ) {
					foreach ( $step['tool_calls'] as $call ) {
						$tool_calls[] = array(
							'tool' => isset( $call['name'] ) ? sanitize_text_field( $call['name'] ) : '',
							'args' => isset( $call['args'] ) ? $call['args'] : array(),
						);
					}
				}
			}
			$output['tool_calls']      = $tool_calls;
			$output['tool_call_count'] = count( $tool_calls );
			$output['step_count']      = count( $output['steps'] );
		}

		return $output;
	}

	/**
	 * Aggregate SSE stream events into a single result.
	 *
	 * @param array $events Raw stream events.
	 * @return array Aggregated result.
	 */
	protected function aggregate_stream_events( array $events ) {
		$result = array(
			'interaction_id' => '',
			'environment_id' => '',
			'output_text'    => '',
			'steps'          => array(),
			'finish_reason'  => '',
			'usage'          => null,
			'stream_events'  => $events,
			'event_count'    => count( $events ),
		);

		foreach ( $events as $event ) {
			if ( isset( $event['id'] ) && '' === $result['interaction_id'] ) {
				$result['interaction_id'] = sanitize_text_field( $event['id'] );
			}

			if ( isset( $event['environment_id'] ) && '' === $result['environment_id'] ) {
				$result['environment_id'] = sanitize_text_field( $event['environment_id'] );
			}

			if ( isset( $event['finish_reason'] ) ) {
				$result['finish_reason'] = sanitize_text_field( $event['finish_reason'] );
			}

			if ( isset( $event['usage'] ) ) {
				$result['usage'] = $event['usage'];
			}

			// Collect output text deltas.
			if ( isset( $event['delta']['text'] ) ) {
				$result['output_text'] .= $event['delta']['text'];
			}

			// Track steps.
			if ( isset( $event['step'] ) ) {
				$result['steps'][] = $event['step'];
			}
		}

		$result['output_text'] = wp_kses_post( $result['output_text'] );

		return $result;
	}

	// -------------------------------------------------------------------------
	// Local State Tracking
	// -------------------------------------------------------------------------

	/**
	 * Track an environment ID so it can be reused later.
	 *
	 * @param string $environment_id The environment ID.
	 * @param array  $result         The interaction result.
	 */
	protected function track_environment( $environment_id, $result ) {
		$interaction_id = isset( $result['id'] ) ? sanitize_text_field( $result['id'] ) : '';

		$existing = get_transient( self::SESSION_PREFIX . 'env_' . $environment_id );

		if ( is_array( $existing ) ) {
			$existing['last_interaction_id'] = $interaction_id;
			$existing['last_used_at']        = time();
			$existing['interaction_count']   = ( $existing['interaction_count'] ?? 0 ) + 1;
			set_transient( self::SESSION_PREFIX . 'env_' . $environment_id, $existing, self::MAX_SESSION_AGE );
		} else {
			set_transient(
				self::SESSION_PREFIX . 'env_' . $environment_id,
				array(
					'environment_id'      => $environment_id,
					'last_interaction_id' => $interaction_id,
					'created_at'          => time(),
					'last_used_at'        => time(),
					'interaction_count'   => 1,
				),
				self::MAX_SESSION_AGE
			);
		}

		// Also track interaction → environment mapping for continue_interaction().
		if ( ! empty( $interaction_id ) ) {
			set_transient(
				self::SESSION_PREFIX . 'int_' . $interaction_id,
				array(
					'environment_id' => $environment_id,
					'created_at'     => time(),
				),
				self::MAX_SESSION_AGE
			);
		}
	}

	/**
	 * Get the environment ID associated with a previous interaction.
	 *
	 * @param string $interaction_id The interaction ID.
	 * @return string Environment ID or empty string.
	 */
	protected function get_environment_for_interaction( $interaction_id ) {
		$data = get_transient( self::SESSION_PREFIX . 'int_' . $interaction_id );

		if ( is_array( $data ) && ! empty( $data['environment_id'] ) ) {
			return $data['environment_id'];
		}

		return '';
	}
}
