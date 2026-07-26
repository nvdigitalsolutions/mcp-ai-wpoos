<?php
/**
 * WordPress-specific tool for AI agent self-improvement via Continual Harness.
 *
 * Lives in the WordPress adapter because it depends on
 * WP_MCP_AI_Agent_Harness_Evolver, WP_MCP_AI_Agent_Harness_Bootstrap,
 * and WordPress option APIs for evolution logging.
 *
 * Based on Continual Harness (Karten et al., 2026).
 *
 * @package Nvoos\WordPress
 * @since   1.0.0
 * @license GPL-3.0-or-later
 * @reference Karten, S., et al. (2026). "Continual Harness." arXiv:2603.04586.
 */

declare(strict_types=1);

namespace Nvoos\WordPress\Tool;

use Nvoos\Core\Tool\AbstractTool;

/**
 * Enables the AI agent to trigger its own harness evolution mid-session.
 *
 * Analyses recent performance traces, detects failure patterns,
 * and proposes or applies improvements to the agent's system prompt,
 * role dispositions, skill tool-sets, and memory strategies.
 */
class EvolveHarnessTool extends AbstractTool {

	private const VALID_OPERATIONS = array( 'analyze', 'evolve', 'status', 'bootstrap' );
	private const VALID_COMPONENTS = array( 'all', 'prompt', 'roles', 'skills', 'memory' );
	private const LOG_OPTION_PREFIX = 'wp_mcp_ai_evolve_harness_log_';
	private const MAX_LOG_ENTRIES    = 100;

	public function getSlug(): string {
		return 'evolve_harness';
	}

	public function getName(): string {
		return 'Evolve Harness';
	}

	public function getDescription(): string {
		return 'Analyse your recent performance and improve your own prompt, skills, memory, and sub-agent roles. Based on Continual Harness — a continual learning framework where AI agents refine their own scaffolding. Use "analyze" to detect failure patterns, "evolve" to apply improvements, "status" to review the evolution log, or "bootstrap" to load a previously saved evolved harness.';
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
					'description' => 'Operation: analyze (failure detection only), evolve (full evolution), status (evolution log), bootstrap (load saved harness).',
					'enum'        => self::VALID_OPERATIONS,
					'default'     => 'evolve',
				),
				'component'     => array(
					'type'        => 'string',
					'description' => 'Component to evolve: all, prompt, roles, skills, memory.',
					'enum'        => self::VALID_COMPONENTS,
					'default'     => 'all',
				),
				'window_length' => array(
					'type'        => 'integer',
					'description' => 'Recent steps to analyse (10-200).',
					'minimum'     => 10,
					'maximum'     => 200,
					'default'     => 50,
				),
				'dry_run'       => array(
					'type'        => 'boolean',
					'description' => 'If true, return suggestions without applying them.',
					'default'     => false,
				),
				'bundle_id'     => array(
					'type'        => 'string',
					'description' => 'Bundle ID for bootstrap operation.',
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$operation     = \sanitize_text_field( $arguments['operation'] ?? 'evolve' );
		$component     = \sanitize_text_field( $arguments['component'] ?? 'all' );
		$window_length = \absint( $arguments['window_length'] ?? 50 );
		$dry_run       = ! empty( $arguments['dry_run'] );
		$bundle_id     = \sanitize_text_field( $arguments['bundle_id'] ?? '' );

		$window_length = \max( 10, \min( 200, $window_length ) );

		if ( ! \in_array( $operation, self::VALID_OPERATIONS, true ) ) {
			return $this->errors->validationFailed(
				\sprintf( 'Invalid operation "%s". Valid: analyze, evolve, status, bootstrap.', \esc_html( $operation ) ),
				array( 'operation' => array( 'Invalid value.' ) ),
			);
		}

		if ( ! \in_array( $component, self::VALID_COMPONENTS, true ) ) {
			return $this->errors->validationFailed(
				\sprintf( 'Invalid component "%s". Valid: all, prompt, roles, skills, memory.', \esc_html( $component ) ),
				array( 'component' => array( 'Invalid value.' ) ),
			);
		}

		$assistant_id = \absint( $context['assistant_id'] ?? 0 );
		$session_id   = \sanitize_text_field( $context['session_id'] ?? '' );

		return match ( $operation ) {
			'analyze'   => $this->handleAnalyze( $assistant_id, $session_id, $component, $window_length ),
			'evolve'    => $this->handleEvolve( $assistant_id, $session_id, $component, $window_length, $dry_run ),
			'status'    => $this->handleStatus( $assistant_id ),
			'bootstrap' => $this->handleBootstrap( $assistant_id, $bundle_id ),
			default     => $this->errors->create( 'wp_mcp_ai_unknown_operation', 'Unknown operation.' ),
		};
	}

	// ─── Operation handlers ────────────────────────────────────────────

	private function handleAnalyze( int $assistant_id, string $session_id, string $component, int $window_length ): mixed {
		$evolver = $this->getEvolver( $assistant_id, $session_id );
		if ( \is_wp_error( $evolver ) ) {
			return $this->errors->create( $evolver->get_error_code(), $evolver->get_error_message() );
		}

		$analysis = $evolver->analyze_failures( $component, $window_length );
		if ( \is_wp_error( $analysis ) ) {
			return $this->errors->create( $analysis->get_error_code(), $analysis->get_error_message() );
		}

		$failures = \absint( $analysis['failures_detected'] ?? 0 );
		$message  = 0 === $failures
			? \sprintf( 'No failure patterns detected in %s component. Performance appears stable.', \esc_html( $component ) )
			: \sprintf( 'Detected %d failure pattern(s) in %s component. Run "evolve" to apply improvements.', $failures, \esc_html( $component ) );

		return $this->success( $message, array(
			'operation'     => 'analyze',
			'component'     => $component,
			'window_length' => $window_length,
			'analysis'      => $analysis,
		) );
	}

	private function handleEvolve( int $assistant_id, string $session_id, string $component, int $window_length, bool $dry_run ): mixed {
		$evolver = $this->getEvolver( $assistant_id, $session_id );
		if ( \is_wp_error( $evolver ) ) {
			return $this->errors->create( $evolver->get_error_code(), $evolver->get_error_message() );
		}

		$result = $evolver->evolve( $component, $window_length, $dry_run );
		if ( \is_wp_error( $result ) ) {
			return $this->errors->create( $result->get_error_code(), $result->get_error_message() );
		}

		$this->logEvolution( $assistant_id, $session_id, $component, $dry_run, $result );

		$changes = \absint( $result['changes_applied'] ?? 0 );
		$message = $dry_run
			? \sprintf( 'Dry run: %d suggestion(s) identified for %s. No changes applied.', $changes, \esc_html( $component ) )
			: \sprintf( 'Harness evolution: %d improvement(s) applied to %s.', $changes, \esc_html( $component ) );

		return $this->success( $message, array(
			'operation'     => 'evolve',
			'component'     => $component,
			'window_length' => $window_length,
			'dry_run'       => $dry_run,
			'result'        => $result,
		) );
	}

	private function handleStatus( int $assistant_id ): array {
		$log = $this->getEvolutionLog( $assistant_id );
		$count = \count( $log );

		return $this->success(
			$count > 0
				? \sprintf( '%d evolution event(s) recorded.', $count )
				: 'No evolution events recorded for this assistant.',
			array( 'operation' => 'status', 'assistant_id' => $assistant_id, 'entries' => $log, 'count' => $count )
		);
	}

	private function handleBootstrap( int $assistant_id, string $bundle_id ): mixed {
		if ( ! \class_exists( 'WP_MCP_AI_Agent_Harness_Bootstrap' ) ) {
			return $this->errors->create( 'wp_mcp_ai_bootstrap_unavailable', 'Harness bootstrap system is not available.' );
		}

		if ( '' !== $bundle_id ) {
			$result = \WP_MCP_AI_Agent_Harness_Bootstrap::load_state( $assistant_id, $bundle_id );
		} else {
			$latest = \WP_MCP_AI_Agent_Harness_Bootstrap::get_latest_bundle( $assistant_id );
			if ( empty( $latest ) ) {
				return $this->errors->create( 'wp_mcp_ai_no_bundle_found', 'No saved bootstrap bundles found for this assistant.' );
			}
			$result = \WP_MCP_AI_Agent_Harness_Bootstrap::load_state( $assistant_id, $latest['bundle_id'] );
		}

		if ( \is_wp_error( $result ) ) {
			return $this->errors->create( $result->get_error_code(), $result->get_error_message() );
		}

		return $this->success( 'Evolved harness loaded from bootstrap bundle.', array(
			'operation' => 'bootstrap',
			'restored'  => $result,
		) );
	}

	// ─── Helpers ──────────────────────────────────────────────────────

	private function getEvolver( int $assistant_id, string $session_id ) {
		if ( ! \class_exists( 'WP_MCP_AI_Agent_Harness_Evolver' ) ) {
			return new \WP_Error( 'wp_mcp_ai_evolver_unavailable', 'Harness evolver module is not currently loaded.' );
		}
		return new \WP_MCP_AI_Agent_Harness_Evolver( $assistant_id, $session_id );
	}

	private function logEvolution( int $assistant_id, string $session_id, string $component, bool $dry_run, array $result ): void {
		$key = self::LOG_OPTION_PREFIX . $assistant_id;
		$log = \get_option( $key, array() );
		if ( ! \is_array( $log ) ) {
			$log = array();
		}

		\array_unshift( $log, array(
			'timestamp'       => \current_time( 'mysql', true ),
			'session_id'      => $session_id,
			'component'       => $component,
			'dry_run'         => $dry_run,
			'changes_applied' => \absint( $result['changes_applied'] ?? 0 ),
			'summary'         => \sanitize_text_field( $result['summary'] ?? '' ),
		) );

		if ( \count( $log ) > self::MAX_LOG_ENTRIES ) {
			$log = \array_slice( $log, 0, self::MAX_LOG_ENTRIES );
		}

		\update_option( $key, $log, false );
	}

	private function getEvolutionLog( int $assistant_id ): array {
		$key = self::LOG_OPTION_PREFIX . $assistant_id;
		$log = \get_option( $key, array() );
		return \is_array( $log ) ? $log : array();
	}
}
