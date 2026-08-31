<?php
/**
 * Artifact Failure Replay — eval cases distilled from failed tool calls.
 *
 * Reads the assistant's Meta-Harness trace runs (`tool_calls.jsonl`), keeps
 * the failed calls, PII-scrubs them, and rebuilds them as eval cases a
 * prompt candidate can be scored against. This is the "train set" of the
 * Darwinian loop: the exact failures the mutator was shown, replayed as a
 * cheap verification gate before any full evaluation.
 *
 * The produced cases are plain `WP_MCP_AI_Eval_Case` argument arrays using
 * the deterministic `artifact_replay` verifier by default; sites can swap in
 * the LLM-judge verifier (`llm_judge`) or supply per-case rules via the
 * `wp_mcp_ai_artifact_replay_case_rules` filter for stronger discrimination.
 *
 * @package WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/harness
 * @since   1.9.0
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * @see ArtifactVerificationGate Consumer of these cases.
 * @see WP_MCP_AI_Artifact_Replay_Verifier Default verifier.
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Harness;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Artifact Failure Replay class.
 *
 * @since 1.9.0
 */
class ArtifactFailureReplay {

	/**
	 * Default number of recent trace runs to inspect.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_MAX_RUNS = 5;

	/**
	 * Default maximum number of replay cases produced.
	 *
	 * @since 1.9.0
	 * @var   int
	 */
	const DEFAULT_MAX_CASES = 20;

	/**
	 * Collect failed tool-call records from the assistant's recent traces.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $options      Options: `max_runs` (default 5), `slugs`
	 *                            (tool whitelist, empty = all), `max_failures`
	 *                            (default 20).
	 * @return array|WP_Error Failure records, or WP_Error when none exist.
	 */
	public static function collect_failures( $assistant_id, $options = array() ) {
		$assistant_id = (int) $assistant_id;
		if ( $assistant_id <= 0 ) {
			return new \WP_Error(
				'wp_mcp_ai_failure_replay_invalid_assistant',
				__( 'A valid assistant ID is required for failure replay.', 'nvoos-content-graph-ai-platform' )
			);
		}

		if ( ! class_exists( __NAMESPACE__ . '\\HarnessTraceStore' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_failure_replay_no_trace_store',
				__( 'The harness trace store is not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$max_runs     = isset( $options['max_runs'] ) ? max( 1, min( 50, (int) $options['max_runs'] ) ) : self::DEFAULT_MAX_RUNS;
		$max_failures = isset( $options['max_failures'] ) ? max( 1, min( 200, (int) $options['max_failures'] ) ) : self::DEFAULT_MAX_CASES;
		$slugs        = isset( $options['slugs'] ) && is_array( $options['slugs'] )
			? array_values( array_filter( array_map( 'sanitize_key', $options['slugs'] ) ) )
			: array();

		$runs = HarnessTraceStore::list_runs( $assistant_id, $max_runs );
		if ( empty( $runs ) ) {
			return new \WP_Error(
				'wp_mcp_ai_failure_replay_no_cases',
				__( 'No harness trace runs found for this assistant.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$failures = array();
		$seen     = array();

		foreach ( $runs as $run ) {
			if ( empty( $run['run_id'] ) ) {
				continue;
			}

			$records = HarnessTraceStore::read_artifact( $run['run_id'], 'tool_calls.jsonl', $assistant_id );
			if ( ! is_array( $records ) ) {
				continue;
			}

			foreach ( $records as $record ) {
				// Keep only failed calls; skip successful ones and malformed rows.
				if ( ! is_array( $record ) || ! isset( $record['result_success'] ) || ! empty( $record['result_success'] ) ) {
					continue;
				}

				$tool_slug = isset( $record['slug'] ) ? sanitize_key( (string) $record['slug'] ) : '';
				if ( '' === $tool_slug || ( ! empty( $slugs ) && ! in_array( $tool_slug, $slugs, true ) ) ) {
					continue;
				}

				// Dedupe by tool slug + arguments hash.
				$args_summary = isset( $record['args_summary'] ) ? (string) $record['args_summary'] : '';
				$dedupe_key   = $tool_slug . '|' . md5( $args_summary );
				if ( isset( $seen[ $dedupe_key ] ) ) {
					continue;
				}
				$seen[ $dedupe_key ] = true;

				$failures[] = array(
					'run_id'       => sanitize_key( (string) $run['run_id'] ),
					'seq'          => isset( $record['seq'] ) ? (int) $record['seq'] : 0,
					'tool_slug'    => $tool_slug,
					'args_summary' => self::scrub( $args_summary ),
					'args'         => self::scrub_array( self::decode_args( $args_summary ) ),
					'error'        => self::scrub( isset( $record['result_summary'] ) ? (string) $record['result_summary'] : '' ),
					'timestamp'    => isset( $record['timestamp'] ) ? (int) $record['timestamp'] : 0,
				);

				if ( count( $failures ) >= $max_failures ) {
					break 2;
				}
			}
		}

		if ( empty( $failures ) ) {
			return new \WP_Error(
				'wp_mcp_ai_failure_replay_no_cases',
				__( 'No failed tool calls found in the recent harness traces.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $failures;
	}

	/**
	 * Build eval-case argument arrays from the assistant's failures.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $options      Collection options (see collect_failures()),
	 *                            plus `verifier_slug` (default `artifact_replay`)
	 *                            and `artifact_type` (default `prompt`).
	 * @return array|WP_Error Case arg arrays, or WP_Error.
	 */
	public static function build_cases( $assistant_id, $options = array() ) {
		$failures = self::collect_failures( $assistant_id, $options );
		if ( is_wp_error( $failures ) ) {
			return $failures;
		}

		$verifier_slug = isset( $options['verifier_slug'] ) ? sanitize_key( (string) $options['verifier_slug'] ) : self::default_verifier_slug();
		if ( '' === $verifier_slug ) {
			$verifier_slug = self::default_verifier_slug();
		}
		$artifact_type = isset( $options['artifact_type'] ) ? sanitize_key( (string) $options['artifact_type'] ) : 'prompt';
		if ( '' === $artifact_type ) {
			$artifact_type = 'prompt';
		}

		$cases = array();
		foreach ( $failures as $failure ) {
			$slug = sanitize_key( $failure['run_id'] . '-' . $failure['seq'] . '-' . $failure['tool_slug'] );
			if ( '' === $slug ) {
				$slug = sanitize_key( 'replay-' . md5( wp_json_encode( $failure ) ) );
			}

			/**
			 * Filters the per-case rules for a replay case.
			 *
			 * Return an array of rule definitions for the `rule_verifier`
			 * shape. The default (empty array) applies the baseline
			 * non-empty-output rule inside the replay verifier.
			 *
			 * @since 1.9.0
			 *
			 * @param array $rules        Rule definitions (empty = baseline).
			 * @param array $failure      Failure record the case replays.
			 * @param int   $assistant_id Assistant post ID.
			 */
			$rules = (array) apply_filters( 'wp_mcp_ai_artifact_replay_case_rules', array(), $failure, (int) $assistant_id );

			$cases[] = array(
				'slug'          => $slug,
				'label'         => sprintf(
					/* translators: 1: tool slug, 2: run id */
					__( 'Replay: %1$s failure (run %2$s)', 'nvoos-content-graph-ai-platform' ),
					$failure['tool_slug'],
					$failure['run_id']
				),
				'input'         => array(
					'prompt'    => self::build_replay_prompt( $failure ),
					'tool_slug' => $failure['tool_slug'],
					'arguments' => $failure['args'],
					'error'     => $failure['error'],
					'run_id'    => $failure['run_id'],
				),
				'expected'      => array(
					'success' => true,
					'rules'   => $rules,
				),
				'verifier_slug' => $verifier_slug,
				'verifier_args' => array(),
				'metadata'      => array(
					'source'        => 'trace_replay',
					'tool_slug'     => $failure['tool_slug'],
					'run_id'        => $failure['run_id'],
					'artifact_type' => $artifact_type,
				),
			);
		}

		return $cases;
	}

	/**
	 * Build an artifact-scoped replay suite for the assistant.
	 *
	 * @since 1.9.0
	 *
	 * @param int   $assistant_id Assistant post ID.
	 * @param array $options      Options (see build_cases()).
	 * @return WP_MCP_AI_Eval_Suite|WP_Error Suite, or WP_Error when no cases exist.
	 */
	public static function build_suite( $assistant_id, $options = array() ) {
		if ( ! defined( 'WP_MCP_AI_PATH' ) || ! class_exists( 'WP_MCP_AI_Eval_Suite' ) ) {
			return new \WP_Error(
				'wp_mcp_ai_failure_replay_no_eval',
				__( 'The eval suite subsystem is not available.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$cases = self::build_cases( $assistant_id, $options );
		if ( is_wp_error( $cases ) ) {
			return $cases;
		}

		$artifact_type = isset( $options['artifact_type'] ) ? sanitize_key( (string) $options['artifact_type'] ) : 'prompt';
		if ( '' === $artifact_type ) {
			$artifact_type = 'prompt';
		}

		try {
			$suite = new \WP_MCP_AI_Eval_Suite(
				array(
					'slug'              => 'artifact-replay-' . (int) $assistant_id,
					'label'             => sprintf(
						/* translators: %d: assistant ID */
						__( 'Failure replay for assistant %d', 'nvoos-content-graph-ai-platform' ),
						(int) $assistant_id
					),
					'description'       => __( 'Auto-generated replay cases from failed tool calls in harness traces.', 'nvoos-content-graph-ai-platform' ),
					'cases'             => $cases,
					'artifact_type'     => $artifact_type,
					'artifact_id'       => (string) (int) $assistant_id,
					'tags'              => array( 'trace_replay', $artifact_type ),
					'generator_context' => array(
						'mode' => 'failure_replay',
					),
				)
			);
		} catch ( \InvalidArgumentException $e ) {
			return new \WP_Error( 'wp_mcp_ai_failure_replay_suite_invalid', $e->getMessage() );
		}

		return $suite;
	}

	/**
	 * Build the human-readable replay prompt for a failure record.
	 *
	 * @since 1.9.0
	 *
	 * @param array $failure Failure record.
	 * @return string Replay prompt.
	 */
	private static function build_replay_prompt( $failure ) {
		return sprintf(
			/* translators: 1: tool slug, 2: error summary, 3: args summary */
			__( 'A previous attempt to use the tool "%1$s" failed with error: %2$s. The call arguments were: %3$s. Produce a corrected response or plan that avoids this failure.', 'nvoos-content-graph-ai-platform' ),
			$failure['tool_slug'],
			'' !== $failure['error'] ? $failure['error'] : __( '(unknown error)', 'nvoos-content-graph-ai-platform' ),
			'' !== $failure['args_summary'] ? $failure['args_summary'] : '{}'
		);
	}

	/**
	 * Decode a JSON args summary into an array (best effort).
	 *
	 * @since 1.9.0
	 *
	 * @param string $args_summary JSON-encoded args summary.
	 * @return array Decoded args.
	 */
	private static function decode_args( $args_summary ) {
		$decoded = json_decode( $args_summary, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * PII-scrub a trace string (graceful when the filter is absent).
	 *
	 * @since 1.9.0
	 *
	 * @param string $text Raw trace text.
	 * @return string Scrubbed text.
	 */
	private static function scrub( $text ) {
		$text = (string) $text;

		if ( '' === $text || ! class_exists( __NAMESPACE__ . '\\PiiFilter' ) ) {
			return $text;
		}

		$scrubbed = PiiFilter::scrub( $text );
		if ( is_array( $scrubbed ) && isset( $scrubbed['text'] ) && is_string( $scrubbed['text'] ) ) {
			return $scrubbed['text'];
		}

		return $text;
	}

	/**
	 * PII-scrub an arbitrary array by round-tripping through JSON.
	 *
	 * @since 1.9.0
	 *
	 * @param array $data Data to scrub.
	 * @return array Scrubbed data.
	 */
	private static function scrub_array( $data ) {
		if ( ! is_array( $data ) || empty( $data ) ) {
			return is_array( $data ) ? $data : array();
		}

		$encoded = wp_json_encode( $data );
		if ( false === $encoded ) {
			return $data;
		}

		$decoded = json_decode( self::scrub( $encoded ), true );

		return is_array( $decoded ) ? $decoded : $data;
	}
	/**
	 * Resolve the default replay verifier slug.
	 *
	 * The canonical verifier lives in the base plugin's measurement
	 * subsystem; in standalone mode the constant value is used
	 * directly so callers keep producing well-formed eval cases.
	 *
	 * @since 2.0.0
	 *
	 * @return string
	 */
	private static function default_verifier_slug() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Artifact_Replay_Verifier' ) ) {
			return \WP_MCP_AI_Artifact_Replay_Verifier::SLUG;
		}
		return 'artifact_replay';
	}
}
