<?php
/**
 * Tool to evolve the agent's own harness mid-session.
 *
 * Exposes the WP_MCP_AI_Agent_Harness_Evolver to the agent, enabling
 * self-improvement through Continual Harness (Karten et al., 2026).
 * The agent can analyse its recent performance, identify failure modes,
 * and evolve its prompt, roles, skills, and memory components autonomously.
 *
 * @package WP_MCP_AI
 * @since   1.2.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license  GPL-3.0-or-later
 *
 * @see WP_MCP_AI_Agent_Harness_Evolver  Core evolution engine.
 * @see WP_MCP_AI_Agent_Harness_Bootstrap Session persistence for evolved harnesses.
 *
 * @reference Karten, S., Agrawal, S., Buddharaju, D., et al. (2026).
 *   "Continual Harness: A Continual Learning System for General-purpose
 *   AI Agent Self-Improvement." arXiv:2603.04586.
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool-llm-sanitizer.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

// Load harness evolver and bootstrap when available.
if ( file_exists( WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-agent-harness-evolver.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/harness/class-wp-mcp-ai-agent-harness-evolver.php';
}
if ( file_exists( WP_MCP_AI_PATH . 'includes/agents/class-wp-mcp-ai-agent-harness-bootstrap.php' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/agents/class-wp-mcp-ai-agent-harness-bootstrap.php';
}

/**
 * Tool: evolve_harness — Agent self-improvement via Continual Harness.
 *
 * Enables the AI agent to trigger its own harness evolution mid-session.
 * The underlying WP_MCP_AI_Agent_Harness_Evolver analyses recent performance
 * traces, detects failure patterns, and proposes (or applies) improvements
 * to the agent's system prompt, role dispositions, skill tool-sets, and
 * memory strategies.
 *
 * Based on Continual Harness (Karten et al., 2026), a continual learning
 * framework where agents refine their own scaffolding over successive
 * interactions without external retraining.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Evolve_Harness implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {

	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Valid operations.
	 *
	 * @since 1.2.0
	 * @var   array<int,string>
	 */
	const VALID_OPERATIONS = array( 'analyze', 'evolve', 'status', 'bootstrap' );

	/**
	 * Valid harness components.
	 *
	 * @since 1.2.0
	 * @var   array<int,string>
	 */
	const VALID_COMPONENTS = array( 'all', 'prompt', 'roles', 'skills', 'memory' );

	/**
	 * Evolution log option key prefix.
	 *
	 * @since 1.2.0
	 * @var   string
	 */
	const LOG_OPTION_PREFIX = 'wp_mcp_ai_evolve_harness_log_';

	/**
	 * Maximum evolution log entries per assistant.
	 *
	 * @since 1.2.0
	 * @var   int
	 */
	const MAX_LOG_ENTRIES = 100;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'evolve_harness';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Evolve Harness', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __(
			'Analyse your recent performance and improve your own prompt, skills, memory, and sub-agent roles. Based on Continual Harness (Karten et al., 2026) — a continual learning framework where AI agents refine their own scaffolding over successive interactions. Use "analyze" to detect failure patterns, "evolve" to apply improvements, "status" to review the evolution log, or "bootstrap" to load a previously saved evolved harness.',
			'mcp-ai-wpoos'
		);
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
			'type'                 => 'object',
			'properties'           => array(
				'operation'     => array(
					'type'        => 'string',
					'description' => __( 'Operation to perform. "analyze" runs failure detection only and returns a summary. "evolve" runs the full harness evolution. "status" retrieves the evolution log. "bootstrap" loads a prior session\'s evolved harness.', 'mcp-ai-wpoos' ),
					'enum'        => self::VALID_OPERATIONS,
					'default'     => 'evolve',
				),
				'component'     => array(
					'type'        => 'string',
					'description' => __( 'Which harness component to evolve. "all" evolves every component. Individual components: "prompt" (system instructions), "roles" (sub-agent role dispositions), "skills" (tool selection preferences), "memory" (retrieval and scoping strategies).', 'mcp-ai-wpoos' ),
					'enum'        => self::VALID_COMPONENTS,
					'default'     => 'all',
				),
				'window_length' => array(
					'type'        => 'integer',
					'description' => __( 'How many recent steps to analyse (10-200). Larger windows capture more context but increase processing time. The default of 50 balances recency with statistical stability.', 'mcp-ai-wpoos' ),
					'minimum'     => 10,
					'maximum'     => 200,
					'default'     => 50,
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'If true, returns the Refiner\'s suggestions without applying them. Useful for previewing changes before committing. The analysis and proposed improvements are shown but no state is modified.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
				'bundle_id'     => array(
					'type'        => 'string',
					'description' => __( 'Bundle ID for the "bootstrap" operation. If omitted for bootstrap, the latest saved bundle is used.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$operation     = isset( $arguments['operation'] ) ? sanitize_text_field( $arguments['operation'] ) : 'evolve';
		$component     = isset( $arguments['component'] ) ? sanitize_text_field( $arguments['component'] ) : 'all';
		$window_length = isset( $arguments['window_length'] ) ? absint( $arguments['window_length'] ) : 50;
		$dry_run       = ! empty( $arguments['dry_run'] );
		$bundle_id     = isset( $arguments['bundle_id'] ) ? sanitize_text_field( $arguments['bundle_id'] ) : '';

		// Clamp window_length to valid range.
		if ( $window_length < 10 ) {
			$window_length = 10;
		} elseif ( $window_length > 200 ) {
			$window_length = 200;
		}

		// Validate operation.
		if ( ! in_array( $operation, self::VALID_OPERATIONS, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_operation',
				sprintf(
					/* translators: %s: invalid operation name */
					__( 'Invalid operation "%s". Valid operations: analyze, evolve, status, bootstrap.', 'mcp-ai-wpoos' ),
					esc_html( $operation )
				),
				array( 'status' => 400 )
			);
		}

		// Validate component.
		if ( ! in_array( $component, self::VALID_COMPONENTS, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_component',
				sprintf(
					/* translators: %s: invalid component name */
					__( 'Invalid component "%s". Valid components: all, prompt, roles, skills, memory.', 'mcp-ai-wpoos' ),
					esc_html( $component )
				),
				array( 'status' => 400 )
			);
		}

		$assistant_id = isset( $context['assistant_id'] ) ? absint( $context['assistant_id'] ) : 0;
		$session_id   = isset( $context['session_id'] ) ? sanitize_text_field( $context['session_id'] ) : '';

		switch ( $operation ) {
			case 'analyze':
				return $this->handle_analyze( $assistant_id, $session_id, $component, $window_length );

			case 'evolve':
				return $this->handle_evolve( $assistant_id, $session_id, $component, $window_length, $dry_run );

			case 'status':
				return $this->handle_status( $assistant_id );

			case 'bootstrap':
				return $this->handle_bootstrap( $assistant_id, $bundle_id );

			default:
				// Already validated above; this is a safety net.
				return new WP_Error(
					'wp_mcp_ai_unknown_operation',
					__( 'Unknown operation.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
		}
	}

	/**
	 * Handle the "analyze" operation — run failure detection only.
	 *
	 * Analyses recent audit trail events to detect failure patterns
	 * without modifying any assistant state.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $session_id    Current session identifier.
	 * @param string $component     Component to analyse.
	 * @param int    $window_length Number of recent steps to analyse.
	 * @return array|WP_Error Analysis summary or error.
	 */
	protected function handle_analyze( $assistant_id, $session_id, $component, $window_length ) {
		$evolver = $this->get_evolver_instance( $assistant_id, $session_id );

		if ( is_wp_error( $evolver ) ) {
			return $evolver;
		}

		$analysis = $evolver->analyze_failures( $component, $window_length );

		if ( is_wp_error( $analysis ) ) {
			return $analysis;
		}

		$message = $this->build_analysis_message( $analysis, $component );

		return $this->build_chat_response(
			$message,
			array(
				'operation'     => 'analyze',
				'component'     => $component,
				'window_length' => $window_length,
				'analysis'      => $analysis,
			)
		);
	}

	/**
	 * Handle the "evolve" operation — run full harness evolution.
	 *
	 * Analyses recent performance, proposes improvements via the Refiner,
	 * and applies them unless dry_run is true.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $session_id    Current session identifier.
	 * @param string $component     Component to evolve.
	 * @param int    $window_length Number of recent steps to analyse.
	 * @param bool   $dry_run       If true, return suggestions without applying.
	 * @return array|WP_Error Evolution result or error.
	 */
	protected function handle_evolve( $assistant_id, $session_id, $component, $window_length, $dry_run ) {
		$evolver = $this->get_evolver_instance( $assistant_id, $session_id );

		if ( is_wp_error( $evolver ) ) {
			return $evolver;
		}

		$result = $evolver->evolve( $component, $window_length, $dry_run );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Log the evolution event.
		$this->log_evolution( $assistant_id, $session_id, $component, $dry_run, $result );

		$message = $this->build_evolution_message( $result, $component, $dry_run );

		return $this->build_chat_response(
			$message,
			array(
				'operation'     => 'evolve',
				'component'     => $component,
				'window_length' => $window_length,
				'dry_run'       => $dry_run,
				'result'        => $result,
			)
		);
	}

	/**
	 * Handle the "status" operation — retrieve the evolution log.
	 *
	 * Returns the chronological log of all harness evolution events
	 * for the given assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Evolution log entries.
	 */
	protected function handle_status( $assistant_id ) {
		$log = $this->get_evolution_log( $assistant_id );

		if ( empty( $log ) ) {
			return $this->build_chat_response(
				__( 'No evolution events recorded for this assistant.', 'mcp-ai-wpoos' ),
				array(
					'operation'    => 'status',
					'assistant_id' => $assistant_id,
					'entries'      => array(),
					'count'        => 0,
				)
			);
		}

		$count = count( $log );

		return $this->build_chat_response(
			sprintf(
				/* translators: %d: number of evolution events */
				_n(
					'%d evolution event recorded.',
					'%d evolution events recorded.',
					$count,
					'mcp-ai-wpoos'
				),
				$count
			),
			array(
				'operation'    => 'status',
				'assistant_id' => $assistant_id,
				'entries'      => $log,
				'count'        => $count,
			)
		);
	}

	/**
	 * Handle the "bootstrap" operation — load a prior session's evolved harness.
	 *
	 * Restores a previously saved bootstrap bundle, applying the evolved
	 * prompt, roles, skills, and memory configuration to the assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $bundle_id    Optional bundle ID. If empty, loads the latest.
	 * @return array|WP_Error Bootstrap result or error.
	 */
	protected function handle_bootstrap( $assistant_id, $bundle_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Bootstrap' ) ) {
			return new WP_Error(
				'wp_mcp_ai_bootstrap_unavailable',
				__( 'Harness bootstrap system is not available. The bootstrap module could not be loaded.', 'mcp-ai-wpoos' ),
				array( 'status' => 501 )
			);
		}

		if ( ! empty( $bundle_id ) ) {
			$result = WP_MCP_AI_Agent_Harness_Bootstrap::load_state( $assistant_id, $bundle_id );
		} else {
			$latest = WP_MCP_AI_Agent_Harness_Bootstrap::get_latest_bundle( $assistant_id );

			if ( empty( $latest ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_bundle_found',
					__( 'No saved bootstrap bundles found for this assistant.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			$result = WP_MCP_AI_Agent_Harness_Bootstrap::load_state( $assistant_id, $latest['bundle_id'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->build_chat_response(
			__( 'Evolved harness loaded from bootstrap bundle.', 'mcp-ai-wpoos' ),
			array(
				'operation' => 'bootstrap',
				'restored'  => $result,
			)
		);
	}

	/**
	 * Get or create the harness evolver instance.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $session_id   Current session identifier.
	 * @return object|WP_Error Evolver instance or error if unavailable.
	 */
	protected function get_evolver_instance( $assistant_id, $session_id ) {
		if ( ! class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			return new WP_Error(
				'wp_mcp_ai_evolver_unavailable',
				__( 'The harness evolver module is not currently loaded. Ensure the harness subsystem is active.', 'mcp-ai-wpoos' ),
				array( 'status' => 501 )
			);
		}

		return new WP_MCP_AI_Agent_Harness_Evolver( $session_id, $assistant_id );
	}

	/**
	 * Build a human-readable message from the analysis result.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $analysis  Analysis data from the evolver.
	 * @param string $component Component that was analysed.
	 * @return string Formatted message.
	 */
	protected function build_analysis_message( $analysis, $component ) {
		$failures = isset( $analysis['failures_detected'] ) ? absint( $analysis['failures_detected'] ) : 0;

		if ( 0 === $failures ) {
			return sprintf(
				/* translators: %s: component name */
				__( 'No failure patterns detected in %s component. Performance appears stable across the analysis window.', 'mcp-ai-wpoos' ),
				esc_html( $component )
			);
		}

		return sprintf(
			/* translators: 1: number of failures, 2: component name */
			_n(
				'Detected %1$d failure pattern in %2$s component. Run "evolve" to apply suggested improvements.',
				'Detected %1$d failure patterns in %2$s component. Run "evolve" to apply suggested improvements.',
				$failures,
				'mcp-ai-wpoos'
			),
			$failures,
			esc_html( $component )
		);
	}

	/**
	 * Build a human-readable message from the evolution result.
	 *
	 * @since 1.2.0
	 *
	 * @param array  $result    Evolution result data from the evolver.
	 * @param string $component Component that was evolved.
	 * @param bool   $dry_run   Whether this was a dry run.
	 * @return string Formatted message.
	 */
	protected function build_evolution_message( $result, $component, $dry_run ) {
		$changes = isset( $result['changes_applied'] ) ? absint( $result['changes_applied'] ) : 0;

		if ( $dry_run ) {
			return sprintf(
				/* translators: 1: number of suggested changes, 2: component name */
				_n(
					'Dry run complete: %1$d suggested improvement identified for %2$s. No changes were applied. Review the suggestions and re-run without dry_run to apply.',
					'Dry run complete: %1$d suggested improvements identified for %2$s. No changes were applied. Review the suggestions and re-run without dry_run to apply.',
					$changes,
					'mcp-ai-wpoos'
				),
				$changes,
				esc_html( $component )
			);
		}

		return sprintf(
			/* translators: 1: number of applied changes, 2: component name */
			_n(
				'Harness evolution complete: %1$d improvement applied to %2$s. The agent\'s scaffolding has been refined based on recent performance data.',
				'Harness evolution complete: %1$d improvements applied to %2$s. The agent\'s scaffolding has been refined based on recent performance data.',
				$changes,
				'mcp-ai-wpoos'
			),
			$changes,
			esc_html( $component )
		);
	}

	/**
	 * Log an evolution event for the assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $session_id   Session identifier.
	 * @param string $component    Evolved component.
	 * @param bool   $dry_run      Whether this was a dry run.
	 * @param array  $result       Evolution result.
	 */
	protected function log_evolution( $assistant_id, $session_id, $component, $dry_run, $result ) {
		$option_key = self::LOG_OPTION_PREFIX . $assistant_id;
		$log        = get_option( $option_key, array() );

		if ( ! is_array( $log ) ) {
			$log = array();
		}

		$entry = array(
			'timestamp'       => current_time( 'mysql', true ),
			'session_id'      => $session_id,
			'component'       => $component,
			'dry_run'         => $dry_run,
			'changes_applied' => isset( $result['changes_applied'] ) ? absint( $result['changes_applied'] ) : 0,
			'summary'         => isset( $result['summary'] ) ? sanitize_text_field( $result['summary'] ) : '',
		);

		// Prepend to keep most recent first.
		array_unshift( $log, $entry );

		// Prune to max entries.
		if ( count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = array_slice( $log, 0, self::MAX_LOG_ENTRIES );
		}

		update_option( $option_key, $log, false );
	}

	/**
	 * Get the evolution log for an assistant.
	 *
	 * @since 1.2.0
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Evolution log entries, most recent first.
	 */
	protected function get_evolution_log( $assistant_id ) {
		$option_key = self::LOG_OPTION_PREFIX . $assistant_id;
		$log        = get_option( $option_key, array() );

		if ( ! is_array( $log ) ) {
			return array();
		}

		return $log;
	}

	/**
	 * Format a chat response with proper message and data ordering.
	 *
	 * Convenience wrapper around {@see WP_MCP_AI_Tool_Chat_Response::format_chat_response()}
	 * that swaps the argument order to (message, data) for more intuitive call-site
	 * usage in this tool.
	 *
	 * @since 1.2.0
	 *
	 * @param string $message User-facing message string.
	 * @param array  $data    Response data to include.
	 * @return array Formatted chat response.
	 */
	protected function build_chat_response( $message, $data = array() ) {
		return $this->format_chat_response(
			$data,
			$message,
			array(
				'include_data' => true,
				'data_key'     => 'data',
			)
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Capability flags for evolve_harness:
	 * - 'background-only': May take significant time to evolve; run in background.
	 * - 'token_multiplier' => 5.0: Evolution consumes additional tokens for
	 *   analysis and refinement.
	 *
	 * @since 1.2.0
	 *
	 * @return array<string,mixed> Capability flags.
	 */
	public function get_capability_flags() {
		return array(
			'background-only'  => true,
			'token_multiplier' => 5.0,
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * Sanitize the evolution result for LLM context consumption.
	 * Strips verbose trace data and raw audit trail excerpts to keep
	 * context windows lean while preserving the actionable summary.
	 *
	 * @since 1.2.0
	 *
	 * @param mixed $result Raw tool execution result.
	 * @return mixed Sanitized result safe for LLM context.
	 */
	public function sanitize_for_llm( $result ) {
		if ( ! is_array( $result ) ) {
			return $result;
		}

		// Remove verbose trace data that bloats context.
		$strip_keys = array(
			'raw_audit_trail',
			'full_trace',
			'detailed_logs',
			'internal_state',
		);

		foreach ( $strip_keys as $key ) {
			unset( $result[ $key ] );
		}

		// Recursively strip from nested data.
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $strip_keys as $key ) {
				unset( $result['data'][ $key ] );
			}

			// Strip raw suggestions detail unless it's a dry run.
			if ( isset( $result['data']['result']['refiner_suggestions'] )
				&& is_array( $result['data']['result']['refiner_suggestions'] )
			) {
				// Keep only the top-level summary of each suggestion.
				$suggestions = $result['data']['result']['refiner_suggestions'];
				$summarized  = array();
				foreach ( $suggestions as $suggestion ) {
					if ( is_array( $suggestion ) ) {
						$summarized[] = array(
							'area'    => isset( $suggestion['area'] ) ? sanitize_text_field( $suggestion['area'] ) : 'unknown',
							'finding' => isset( $suggestion['finding'] ) ? wp_trim_words( sanitize_text_field( $suggestion['finding'] ), 30, '...' ) : '',
							'action'  => isset( $suggestion['action'] ) ? sanitize_text_field( $suggestion['action'] ) : 'review',
						);
					}
				}
				$result['data']['result']['refiner_suggestions'] = $summarized;
			}
		}

		return $result;
	}
}
