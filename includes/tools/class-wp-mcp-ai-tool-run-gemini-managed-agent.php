<?php
/**
 * Tool for running tasks with Gemini Managed Agents (Antigravity).
 *
 * Sends prompts to the Antigravity agent via the Gemini Interactions API.
 * The agent operates in an isolated Linux sandbox with code execution,
 * web browsing, and file management capabilities.
 *
 * Unlike NV oOS function-calling tools, the Antigravity agent is a
 * self-contained agent with its own tool loop (plan → act → observe).
 * It uses built-in tools (code_execution, google_search, url_context)
 * and does NOT support external function calling or MCP.
 *
 * @package WP_MCP_AI
 * @since 1.2.0 — original managed agent tool (speculative pre-release)
 * @since 2.x   — updated for the actual Antigravity Interactions API (May 2026)
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-gemini-managed-agent-service.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Run Gemini Managed Agent (Antigravity) Tool.
 */
class WP_MCP_AI_Tool_Run_Gemini_Managed_Agent implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'run_gemini_managed_agent';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Run Gemini Managed Agent (Antigravity)', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sends tasks to the Antigravity managed agent via the Gemini Interactions API. The agent runs in a secure cloud sandbox with code execution (Python, JavaScript, Bash), web browsing (Google Search + URL fetching), and file management. Use "send" to start a new task, "continue" to follow up on a previous interaction, "stream" for real-time SSE streaming, "download" to retrieve sandbox files, and "envs" to list tracked environments. The agent does NOT have access to WordPress tools — it is self-contained with its own built-in toolset.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation'          => array(
					'type'        => 'string',
					'description' => __( 'Operation to perform: "send" a prompt to the Antigravity agent, "continue" a previous interaction, "stream" for real-time response streaming, "download" files from a sandbox environment, or "envs" to list tracked environments.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'send', 'continue', 'stream', 'download', 'envs' ),
				),
				'input'              => array(
					'type'        => 'string',
					'description' => __( 'The task for the agent. Be specific about goals, constraints, and expected outputs. Examples: "Analyze this CSV data and create a summary report", "Research the top 5 competitors and compare their pricing", "Write a Python script to process image files and generate thumbnails". Required for "send", "continue", and "stream" operations.', 'mcp-ai-wpoos' ),
				),
				'interaction_id'     => array(
					'type'        => 'string',
					'description' => __( 'A previous interaction ID to continue. Required for "continue" operation. The agent picks up the conversation where it left off, with access to the same sandbox files.', 'mcp-ai-wpoos' ),
				),
				'environment_id'     => array(
					'type'        => 'string',
					'description' => __( 'An environment ID to reuse a sandbox from a previous interaction. Files and installed packages persist. Required for "download" operation. Optional for "send" and "continue".', 'mcp-ai-wpoos' ),
				),
				'system_instruction' => array(
					'type'        => 'string',
					'description' => __( 'System instructions defining the agent\'s role and constraints. Example: "You are a data analyst. Always cite sources and show your reasoning step by step."', 'mcp-ai-wpoos' ),
				),
				'agent_tools'        => array(
					'type'        => 'array',
					'description' => __( 'Which built-in tools the agent can use. Options: "code_execution" (run Python/JS/Bash), "google_search" (web search), "url_context" (fetch web pages). If empty, all are enabled by default. Filesystem access is always enabled with the environment.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'code_execution', 'google_search', 'url_context' ),
					),
				),
				'agent_id'           => array(
					'type'        => 'string',
					'description' => __( 'Agent ID to use (e.g., a custom saved managed agent). Defaults to the Antigravity preview agent.', 'mcp-ai-wpoos' ),
					'default'     => 'antigravity-preview-05-2026',
				),
				'timeout'            => array(
					'type'        => 'integer',
					'description' => __( 'Timeout in seconds (30-3600). Default: 300 (5 minutes). Complex tasks may need longer timeouts. Note: the Antigravity agent can accumulate many tokens per interaction.', 'mcp-ai-wpoos' ),
					'minimum'     => 30,
					'maximum'     => 3600,
					'default'     => 300,
				),
				'save_path'          => array(
					'type'        => 'string',
					'description' => __( 'For "download" operation: optional relative path within wp-content/uploads to save the environment archive. Example: "antigravity-snapshots/project1.tar".', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'operation' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$operation          = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : '';
		$input              = isset( $arguments['input'] ) ? $arguments['input'] : '';
		$interaction_id     = isset( $arguments['interaction_id'] ) ? sanitize_text_field( $arguments['interaction_id'] ) : '';
		$environment_id     = isset( $arguments['environment_id'] ) ? sanitize_text_field( $arguments['environment_id'] ) : '';
		$system_instruction = isset( $arguments['system_instruction'] ) ? sanitize_textarea_field( $arguments['system_instruction'] ) : '';
		$agent_tools        = isset( $arguments['agent_tools'] ) ? array_map( 'sanitize_key', (array) $arguments['agent_tools'] ) : array();
		$agent_id           = isset( $arguments['agent_id'] ) ? sanitize_text_field( $arguments['agent_id'] ) : '';
		$timeout            = isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 300;
		$save_path          = isset( $arguments['save_path'] ) ? sanitize_text_field( $arguments['save_path'] ) : '';

		$service = new WP_MCP_AI_Gemini_Managed_Agent_Service();

		switch ( $operation ) {
			case 'send':
				return $this->handle_send( $service, $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout );

			case 'continue':
				return $this->handle_continue( $service, $input, $interaction_id, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout );

			case 'stream':
				return $this->handle_stream( $service, $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout );

			case 'download':
				return $this->handle_download( $service, $environment_id, $save_path );

			case 'envs':
				return $this->handle_envs( $service );

			default:
				return new WP_Error(
					'wp_mcp_ai_invalid_operation',
					sprintf(
						/* translators: %s: operation name */
						__( 'Invalid operation: %s. Valid: send, continue, stream, download, envs.', 'mcp-ai-wpoos' ),
						esc_html( $operation )
					),
					array( 'status' => 400 )
				);
		}
	}

	/**
	 * Handle "send" — new interaction.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service            Service instance.
	 * @param string                                 $input              Task input.
	 * @param string                                 $environment_id     Environment ID.
	 * @param string                                 $system_instruction System instruction.
	 * @param array                                  $agent_tools        Agent tool slugs.
	 * @param string                                 $agent_id           Agent ID.
	 * @param int                                    $timeout            Timeout in seconds.
	 * @return array|WP_Error
	 */
	protected function handle_send( $service, $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout ) {
		return $this->handle_stream( $service, $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout );
	}

	/**
	 * Handle "continue" — continue a previous interaction.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service            Service instance.
	 * @param string                                 $input              Task input.
	 * @param string                                 $interaction_id     Previous interaction ID.
	 * @param string                                 $environment_id     Environment ID.
	 * @param string                                 $system_instruction System instruction.
	 * @param array                                  $agent_tools        Agent tool slugs.
	 * @param string                                 $agent_id           Agent ID.
	 * @param int                                    $timeout            Timeout in seconds.
	 * @return array|WP_Error
	 */
	protected function handle_continue( $service, $input, $interaction_id, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout ) {
		if ( empty( $interaction_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_interaction_id',
				__( 'An interaction ID is required for the "continue" operation. Use the ID returned by a previous "send" or "stream" call.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$args   = $this->build_interaction_args( $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout );
		$result = $service->continue_interaction( $interaction_id, $input, $args );

		return $this->format_response( $result );
	}

	/**
	 * Handle "stream" — send with streaming (SSE).
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service            Service instance.
	 * @param string                                 $input              Task input.
	 * @param string                                 $environment_id     Environment ID.
	 * @param string                                 $system_instruction System instruction.
	 * @param array                                  $agent_tools        Agent tool slugs.
	 * @param string                                 $agent_id           Agent ID.
	 * @param int                                    $timeout            Timeout in seconds.
	 * @return array|WP_Error
	 */
	protected function handle_stream( $service, $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout ) {
		if ( empty( $input ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'An input prompt is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$args           = $this->build_interaction_args( $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout );
		$args['stream'] = true;

		$result = $service->send_interaction( $args );

		return $this->format_response( $result );
	}

	/**
	 * Handle "download" — download sandbox files.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service        Service instance.
	 * @param string                                 $environment_id Environment ID.
	 * @param string                                 $save_path      Optional save path.
	 * @return array|WP_Error
	 */
	protected function handle_download( $service, $environment_id, $save_path ) {
		if ( empty( $environment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_environment_id',
				__( 'An environment ID is required for the "download" operation.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$result = $service->download_environment_files( $environment_id, $save_path );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->build_chat_response(
			sprintf(
				/* translators: 1: file size in KB, 2: environment ID */
				__( 'Environment snapshot downloaded (size: %1$s KB) for environment %2$s.', 'mcp-ai-wpoos' ),
				round( $result['size'] / 1024, 1 ),
				esc_html( $environment_id )
			),
			$result
		);
	}

	/**
	 * Handle "envs" — list tracked environments.
	 *
	 * @param WP_MCP_AI_Gemini_Managed_Agent_Service $service Service instance.
	 * @return array
	 */
	protected function handle_envs( $service ) {
		$environments = $service->list_environments();

		if ( empty( $environments ) ) {
			return $this->build_chat_response(
				__( 'No tracked agent environments. Run a "send" operation to create one.', 'mcp-ai-wpoos' ),
				array( 'environments' => array() )
			);
		}

		return $this->build_chat_response(
			sprintf(
				/* translators: %d: number of environments */
				_n(
					'%d tracked agent environment.',
					'%d tracked agent environments.',
					count( $environments ),
					'mcp-ai-wpoos'
				),
				count( $environments )
			),
			array( 'environments' => $environments )
		);
	}

	// -------------------------------------------------------------------------
	// Helpers
	// -------------------------------------------------------------------------

	/**
	 * Build interaction arguments from tool parameters.
	 *
	 * @param string $input              Task input.
	 * @param string $environment_id     Environment ID.
	 * @param string $system_instruction System instruction.
	 * @param array  $agent_tools        Agent tool restrictions.
	 * @param string $agent_id           Agent ID.
	 * @param int    $timeout            Timeout in seconds.
	 * @return array
	 */
	protected function build_interaction_args( $input, $environment_id, $system_instruction, $agent_tools, $agent_id, $timeout ) {
		$args = array(
			'input'   => $input,
			'timeout' => $timeout,
		);

		if ( ! empty( $environment_id ) ) {
			$args['environment'] = $environment_id;
		}

		if ( ! empty( $system_instruction ) ) {
			$args['system_instruction'] = $system_instruction;
		}

		if ( ! empty( $agent_tools ) ) {
			$args['tools'] = $agent_tools;
		}

		if ( ! empty( $agent_id ) ) {
			$args['agent'] = $agent_id;
		}

		return $args;
	}

	/**
	 * Format an interaction result into a chat response.
	 *
	 * @param array|WP_Error $result The interaction result.
	 * @return array|WP_Error
	 */
	protected function format_response( $result ) {
		if ( is_wp_error( $result ) ) {
			// If unavailable, offer guidance.
			if ( 'wp_mcp_ai_managed_agents_unavailable' === $result->get_error_code() ) {
				return $this->build_chat_response(
					$result->get_error_message(),
					array(
						'status'     => 'unavailable',
						'suggestion' => __( 'Enable Managed Agents in Settings → NV oOS → Providers → Gemini to use the Antigravity agent.', 'mcp-ai-wpoos' ),
					)
				);
			}

			return $result;
		}

		// Build the response message.
		$output_text = isset( $result['output_text'] ) ? $result['output_text'] : __( 'Task completed.', 'mcp-ai-wpoos' );

		// Highlight the interaction/environment IDs for reuse.
		$meta = array();
		if ( ! empty( $result['interaction_id'] ) ) {
			$meta[] = sprintf(
				/* translators: %s: interaction ID */
				__( 'Interaction: %s', 'mcp-ai-wpoos' ),
				$result['interaction_id']
			);
		}
		if ( ! empty( $result['environment_id'] ) ) {
			$meta[] = sprintf(
				/* translators: %s: environment ID */
				__( 'Environment: %s', 'mcp-ai-wpoos' ),
				$result['environment_id']
			);
		}

		if ( ! empty( $meta ) ) {
			$output_text .= "\n\n---\n" . implode( "\n", $meta );
		}

		// Append token usage if available.
		if ( ! empty( $result['usage'] ) ) {
			$usage       = $result['usage'];
			$usage_parts = array();
			if ( isset( $usage['input_tokens'] ) ) {
				$usage_parts[] = sprintf(
					/* translators: %d: token count */
					__( 'Input: %d tokens', 'mcp-ai-wpoos' ),
					absint( $usage['input_tokens'] )
				);
			}
			if ( isset( $usage['output_tokens'] ) ) {
				$usage_parts[] = sprintf(
					/* translators: %d: token count */
					__( 'Output: %d tokens', 'mcp-ai-wpoos' ),
					absint( $usage['output_tokens'] )
				);
			}
			if ( ! empty( $usage_parts ) ) {
				$output_text .= "\n" . implode( ' | ', $usage_parts );
			}
		}

		// Merge relevant result data into the response.
		$response_data = array_merge(
			$result,
			array(
				'interaction_id'  => $result['interaction_id'] ?? '',
				'environment_id'  => $result['environment_id'] ?? '',
				'step_count'      => $result['step_count'] ?? 0,
				'tool_call_count' => $result['tool_call_count'] ?? 0,
				'finish_reason'   => $result['finish_reason'] ?? '',
				'event_count'     => $result['event_count'] ?? 0,
			)
		);

		return $this->build_chat_response( $output_text, $response_data );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'background-only'  => true,
			'token_multiplier' => 15.0, // Antigravity can accumulate 3-5M tokens per interaction.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(
			'providers'    => array( 'gemini' ),
			// Note: Antigravity does NOT require function-calling capability.
			// It uses its own built-in tools (code_execution, google_search, url_context).
			'capabilities' => array(),
			'required'     => true,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Strips large stream event arrays and raw environment data from the result
	 * to keep LLM context size manageable. Keeps output_text, interaction IDs,
	 * environment IDs, step/tool call summaries, and usage data.
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Strip raw stream events — they can be enormous.
		if ( isset( $result['stream_events'] ) ) {
			unset( $result['stream_events'] );
		}

		// Strip raw binary tar data from downloads.
		if ( isset( $result['tar_data'] ) ) {
			unset( $result['tar_data'] );
		}

		// Strip raw steps array (keep only summary counts).
		if ( isset( $result['steps'] ) ) {
			unset( $result['steps'] );
		}

		// Strip raw tool calls array (keep only count).
		if ( isset( $result['tool_calls'] ) ) {
			unset( $result['tool_calls'] );
		}

		// Strip any base64-encoded image data.
		if ( isset( $result['data'] ) ) {
			unset( $result['data'] );
		}

		// Keep only essential metadata for LLM reasoning.
		$keep_fields = array(
			'interaction_id',
			'environment_id',
			'output_text',
			'finish_reason',
			'step_count',
			'tool_call_count',
			'event_count',
			'size',
			'save_path',
			'save_url',
			'usage',
		);

		$sanitized = array();

		foreach ( $keep_fields as $field ) {
			if ( isset( $result[ $field ] ) ) {
				$sanitized[ $field ] = $result[ $field ];
			}
		}

		return $sanitized;
	}
}
