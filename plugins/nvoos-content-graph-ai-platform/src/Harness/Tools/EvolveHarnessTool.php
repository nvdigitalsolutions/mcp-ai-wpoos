<?php
/**
 * Evolve Harness tool (D8 Cluster 3 port of the base plugin's
 * WP_MCP_AI_Tool_Evolve_Harness — byte-identical slug, schema, error
 * codes, envelopes, pluralised messages, evolution-log option key, and
 * LLM sanitisation; per-mode evolver/bootstrap seams).
 *
 * Monolith installs resolve WP_MCP_AI_Agent_Harness_Evolver /
 * WP_MCP_AI_Agent_Harness_Bootstrap from the base plugin; standalone
 * installs use the platform-ported AgentHarnessEvolver /
 * AgentHarnessBootstrap (extraction Wave C).
 *
 * @package NvoosContentGraphAiPlatform\Harness\Tools
 * @since   2.0.0
 * @reference Karten, S., Agrawal, S., Buddharaju, D., et al. (2026).
 *   "Continual Harness: A Continual Learning System for General-purpose
 *   AI Agent Self-Improvement." arXiv:2603.04586.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness\Tools;

use NvoosContentGraphAi\Tools\AbstractAiTool;
use NvoosContentGraphAiPlatform\Agents\AgentHarnessBootstrap;
use NvoosContentGraphAiPlatform\Agents\AgentHarnessEvolver;

/**
 * Agent self-improvement via Continual Harness.
 */
class EvolveHarnessTool extends AbstractAiTool {

	/**
	 * Valid operations (base-identical).
	 *
	 * @var array<int,string>
	 */
	private const VALID_OPERATIONS = array( 'analyze', 'evolve', 'status', 'bootstrap' );

	/**
	 * Valid harness components (base-identical).
	 *
	 * @var array<int,string>
	 */
	private const VALID_COMPONENTS = array( 'all', 'prompt', 'roles', 'skills', 'memory' );

	/**
	 * Evolution log option key prefix (base-identical).
	 *
	 * @var string
	 */
	private const LOG_OPTION_PREFIX = 'wp_mcp_ai_evolve_harness_log_';

	/**
	 * Maximum evolution log entries per assistant (base-identical).
	 *
	 * @var int
	 */
	private const MAX_LOG_ENTRIES = 100;

	public function getSlug(): string {
		return 'evolve_harness';
	}

	public function getName(): string {
		return __( 'Evolve Harness', 'nvoos-content-graph-ai-platform' );
	}

	public function getDescription(): string {
		return __(
			'Analyse your recent performance and improve your own prompt, skills, memory, and sub-agent roles. Based on Continual Harness (Karten et al., 2026) — a continual learning framework where AI agents refine their own scaffolding over successive interactions. Use "analyze" to detect failure patterns, "evolve" to apply improvements, "status" to review the evolution log, or "bootstrap" to load a previously saved evolved harness.',
			'nvoos-content-graph-ai-platform'
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'     => array(
					'type'        => 'string',
					'description' => __( 'Operation to perform. "analyze" runs failure detection only and returns a summary. "evolve" runs the full harness evolution. "status" retrieves the evolution log. "bootstrap" loads a prior session\'s evolved harness.', 'nvoos-content-graph-ai-platform' ),
					'enum'        => self::VALID_OPERATIONS,
					'default'     => 'evolve',
				),
				'component'     => array(
					'type'        => 'string',
					'description' => __( 'Which harness component to evolve. "all" evolves every component. Individual components: "prompt" (system instructions), "roles" (sub-agent role dispositions), "skills" (tool selection preferences), "memory" (retrieval and scoping strategies).', 'nvoos-content-graph-ai-platform' ),
					'enum'        => self::VALID_COMPONENTS,
					'default'     => 'all',
				),
				'window_length' => array(
					'type'        => 'integer',
					'description' => __( 'How many recent steps to analyse (10-200). Larger windows capture more context but increase processing time. The default of 50 balances recency with statistical stability.', 'nvoos-content-graph-ai-platform' ),
					'minimum'     => 10,
					'maximum'     => 200,
					'default'     => 50,
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => __( 'If true, returns the Refiner\'s suggestions without applying them. Useful for previewing changes before committing. The analysis and proposed improvements are shown but no state is modified.', 'nvoos-content-graph-ai-platform' ),
					'default'     => false,
				),
				'bundle_id'     => array(
					'type'        => 'string',
					'description' => __( 'Bundle ID for the "bootstrap" operation. If omitted for bootstrap, the latest saved bundle is used.', 'nvoos-content-graph-ai-platform' ),
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	public function getCapabilityFlags(): array {
		return array(
			'background-only'  => true,
			'token_multiplier' => 5.0,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
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
			return new \WP_Error(
				'wp_mcp_ai_invalid_operation',
				sprintf(
					/* translators: %s: invalid operation name */
					__( 'Invalid operation "%s". Valid operations: analyze, evolve, status, bootstrap.', 'nvoos-content-graph-ai-platform' ),
					esc_html( $operation )
				),
				array( 'status' => 400 )
			);
		}

		// Validate component.
		if ( ! in_array( $component, self::VALID_COMPONENTS, true ) ) {
			return new \WP_Error(
				'wp_mcp_ai_invalid_component',
				sprintf(
					/* translators: %s: invalid component name */
					__( 'Invalid component "%s". Valid components: all, prompt, roles, skills, memory.', 'nvoos-content-graph-ai-platform' ),
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
				return new \WP_Error(
					'wp_mcp_ai_unknown_operation',
					__( 'Unknown operation.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 500 )
				);
		}
	}

	/**
	 * Handle the "analyze" operation — run failure detection only.
	 *
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $session_id    Current session identifier.
	 * @param string $component     Component to analyse.
	 * @param int    $window_length Number of recent steps to analyse.
	 * @return array|\WP_Error Analysis summary or error.
	 */
	private function handle_analyze( $assistant_id, $session_id, $component, $window_length ) {
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
	 * @param int    $assistant_id  Assistant post ID.
	 * @param string $session_id    Current session identifier.
	 * @param string $component     Component to evolve.
	 * @param int    $window_length Number of recent steps to analyse.
	 * @param bool   $dry_run       If true, return suggestions without applying.
	 * @return array|\WP_Error Evolution result or error.
	 */
	private function handle_evolve( $assistant_id, $session_id, $component, $window_length, $dry_run ) {
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
	 * @param int $assistant_id Assistant post ID.
	 * @return array Evolution log entries.
	 */
	private function handle_status( $assistant_id ) {
		$log = $this->get_evolution_log( $assistant_id );

		if ( empty( $log ) ) {
			return $this->build_chat_response(
				__( 'No evolution events recorded for this assistant.', 'nvoos-content-graph-ai-platform' ),
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
					'nvoos-content-graph-ai-platform'
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
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $bundle_id    Optional bundle ID. If empty, loads the latest.
	 * @return array|\WP_Error Bootstrap result or error.
	 */
	private function handle_bootstrap( $assistant_id, $bundle_id ) {
		$bootstrap_class = $this->get_bootstrap_class();

		if ( null === $bootstrap_class ) {
			return new \WP_Error(
				'wp_mcp_ai_bootstrap_unavailable',
				__( 'Harness bootstrap system is not available. The bootstrap module could not be loaded.', 'nvoos-content-graph-ai-platform' ),
				array( 'status' => 501 )
			);
		}

		if ( ! empty( $bundle_id ) ) {
			$result = $bootstrap_class::load_state( $assistant_id, $bundle_id );
		} else {
			$latest = $bootstrap_class::get_latest_bundle( $assistant_id );

			if ( empty( $latest ) ) {
				return new \WP_Error(
					'wp_mcp_ai_no_bundle_found',
					__( 'No saved bootstrap bundles found for this assistant.', 'nvoos-content-graph-ai-platform' ),
					array( 'status' => 404 )
				);
			}

			$result = $bootstrap_class::load_state( $assistant_id, $latest['bundle_id'] );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		return $this->build_chat_response(
			__( 'Evolved harness loaded from bootstrap bundle.', 'nvoos-content-graph-ai-platform' ),
			array(
				'operation' => 'bootstrap',
				'restored'  => $result,
			)
		);
	}

	/**
	 * Get or create the harness evolver instance (per-mode seam).
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $session_id   Current session identifier.
	 * @return object|\WP_Error Evolver instance or error if unavailable.
	 */
	private function get_evolver_instance( $assistant_id, $session_id ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			return new \WP_MCP_AI_Agent_Harness_Evolver( $session_id, $assistant_id );
		}

		if ( class_exists( AgentHarnessEvolver::class ) ) {
			return new AgentHarnessEvolver( $session_id, $assistant_id );
		}

		return new \WP_Error(
			'wp_mcp_ai_evolver_unavailable',
			__( 'The harness evolver module is not currently loaded. Ensure the harness subsystem is active.', 'nvoos-content-graph-ai-platform' ),
			array( 'status' => 501 )
		);
	}

	/**
	 * Resolve the bootstrap class (per-mode seam).
	 *
	 * @return string|null Fully qualified class name or null if unavailable.
	 */
	private function get_bootstrap_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Agent_Harness_Bootstrap' ) ) {
			return 'WP_MCP_AI_Agent_Harness_Bootstrap';
		}

		if ( class_exists( AgentHarnessBootstrap::class ) ) {
			return AgentHarnessBootstrap::class;
		}

		return null;
	}

	/**
	 * Build a human-readable message from the analysis result (base-identical).
	 *
	 * @param array  $analysis  Analysis data from the evolver.
	 * @param string $component Component that was analysed.
	 * @return string Formatted message.
	 */
	private function build_analysis_message( $analysis, $component ) {
		$failures = isset( $analysis['failures_detected'] ) ? absint( $analysis['failures_detected'] ) : 0;

		if ( 0 === $failures ) {
			return sprintf(
				/* translators: %s: component name */
				__( 'No failure patterns detected in %s component. Performance appears stable across the analysis window.', 'nvoos-content-graph-ai-platform' ),
				esc_html( $component )
			);
		}

		return sprintf(
			/* translators: 1: number of failures, 2: component name */
			_n(
				'Detected %1$d failure pattern in %2$s component. Run "evolve" to apply suggested improvements.',
				'Detected %1$d failure patterns in %2$s component. Run "evolve" to apply suggested improvements.',
				$failures,
				'nvoos-content-graph-ai-platform'
			),
			$failures,
			esc_html( $component )
		);
	}

	/**
	 * Build a human-readable message from the evolution result (base-identical).
	 *
	 * @param array  $result    Evolution result data from the evolver.
	 * @param string $component Component that was evolved.
	 * @param bool   $dry_run   Whether this was a dry run.
	 * @return string Formatted message.
	 */
	private function build_evolution_message( $result, $component, $dry_run ) {
		$changes = isset( $result['changes_applied'] ) ? absint( $result['changes_applied'] ) : 0;

		if ( $dry_run ) {
			return sprintf(
				/* translators: 1: number of suggested changes, 2: component name */
				_n(
					'Dry run complete: %1$d suggested improvement identified for %2$s. No changes were applied. Review the suggestions and re-run without dry_run to apply.',
					'Dry run complete: %1$d suggested improvements identified for %2$s. No changes were applied. Review the suggestions and re-run without dry_run to apply.',
					$changes,
					'nvoos-content-graph-ai-platform'
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
				'nvoos-content-graph-ai-platform'
			),
			$changes,
			esc_html( $component )
		);
	}

	/**
	 * Log an evolution event for the assistant (base-identical option key).
	 *
	 * @param int    $assistant_id Assistant post ID.
	 * @param string $session_id   Session identifier.
	 * @param string $component    Evolved component.
	 * @param bool   $dry_run      Whether this was a dry run.
	 * @param array  $result       Evolution result.
	 * @return void
	 */
	private function log_evolution( $assistant_id, $session_id, $component, $dry_run, $result ) {
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
	 * Get the evolution log for an assistant (base-identical).
	 *
	 * @param int $assistant_id Assistant post ID.
	 * @return array Evolution log entries, most recent first.
	 */
	private function get_evolution_log( $assistant_id ) {
		$option_key = self::LOG_OPTION_PREFIX . $assistant_id;
		$log        = get_option( $option_key, array() );

		if ( ! is_array( $log ) ) {
			return array();
		}

		return $log;
	}

	/**
	 * Format a chat response with proper message and data ordering
	 * (base-identical envelope: message at top level, data nested).
	 *
	 * @param string $message User-facing message string.
	 * @param array  $data    Response data to include.
	 * @return array Formatted chat response.
	 */
	private function build_chat_response( $message, $data = array() ) {
		return array(
			'message' => trim( $message ),
			'data'    => $data,
		);
	}

	/**
	 * Sanitize the evolution result for LLM context consumption
	 * (base-identical LLM sanitizer behaviour).
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
