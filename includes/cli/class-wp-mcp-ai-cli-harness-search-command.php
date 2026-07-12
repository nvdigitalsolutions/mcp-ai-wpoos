<?php
/**
 * Harness Search WP-CLI commands.
 *
 * Provides WP-CLI commands for running harness profile search,
 * inspecting populations, and browsing trace artifacts.
 *
 * @package WP_MCP_AI
 * @since 1.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_CLI_Base_Command' ) && file_exists( __DIR__ . '/class-wp-mcp-ai-cli-base-command.php' ) ) {
	require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';
}

/**
 * Harness Search CLI commands.
 */
class WP_MCP_AI_CLI_Harness_Search_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Start a harness profile search for an assistant.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID to optimize.
	 *
	 * [--suites=<csv>]
	 * : Comma-separated eval suite slugs to use as the search set.
	 *
	 * [--iterations=<number>]
	 * : Number of search iterations (default: 20, max: 100).
	 * ---
	 * default: 20
	 * ---
	 *
	 * [--k=<number>]
	 * : Candidates per iteration (default: 2, max: 10).
	 * ---
	 * default: 2
	 * ---
	 *
	 * [--sync]
	 * : Run synchronously (blocking). Without this flag, each iteration
	 *   must be triggered manually via `wp mcp-ai harness search step`.
	 *
	 * ## EXAMPLES
	 *
	 *     # Start an async search with default settings.
	 *     wp mcp-ai harness search start 42 --suites=qa_accuracy,code_review
	 *
	 *     # Run a full sync search with 10 iterations.
	 *     wp mcp-ai harness search start 42 --suites=qa_accuracy --iterations=10 --sync
	 *
	 * @param array<int,string>    $args        Positional args: [ assistant-id ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function start( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		$suites_raw = isset( $assoc_args['suites'] ) ? (string) $assoc_args['suites'] : '';
		$suites     = array_filter( array_map( 'trim', explode( ',', $suites_raw ) ) );

		if ( empty( $suites ) ) {
			WP_CLI::error( __( 'At least one eval suite slug is required via --suites.', 'mcp-ai-wpoos' ) );
		}

		// Use the current harness profile as the seed.
		$current_profile = WP_MCP_AI_Harness_Profile::get( $assistant_id );
		$seed_profiles   = array( $current_profile );

		// Also add the defaults as a second seed for diversity.
		$defaults            = WP_MCP_AI_Harness_Profile::defaults();
		$defaults['enabled'] = true;
		$seed_profiles[]     = $defaults;

		$sync       = WP_CLI\Utils\get_flag_value( $assoc_args, 'sync', false );
		$iterations = isset( $assoc_args['iterations'] ) ? (int) $assoc_args['iterations'] : WP_MCP_AI_Harness_Search_Engine::DEFAULT_ITERATIONS;
		$k          = isset( $assoc_args['k'] ) ? (int) $assoc_args['k'] : WP_MCP_AI_Harness_Search_Engine::DEFAULT_CANDIDATES_PER_ITERATION;

		if ( $sync ) {
			WP_CLI::log(
				sprintf(
				/* translators: 1: assistant ID, 2: iterations, 3: k, 4: suite list */
					__( 'Starting sync search for assistant %1$d (%2$d iterations, %3$d candidates/iter). Suites: %4$s', 'mcp-ai-wpoos' ),
					$assistant_id,
					$iterations,
					$k,
					implode( ', ', $suites )
				)
			);

			$result = WP_MCP_AI_Harness_Search_Engine::run_search(
				$assistant_id,
				$seed_profiles,
				$suites,
				array(
					'iterations' => $iterations,
					'k'          => $k,
				)
			);

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$this->render_search_results( $result );
		} else {
			$started = WP_MCP_AI_Harness_Search_Engine::start_search(
				$assistant_id,
				$seed_profiles,
				$suites,
				array(
					'iterations' => $iterations,
					'k'          => $k,
				)
			);

			if ( is_wp_error( $started ) ) {
				WP_CLI::error( $started->get_error_message() );
			}

			WP_CLI::success(
				sprintf(
				/* translators: 1: assistant ID, 2: iterations */
					__( 'Async search started for assistant %1$d (%2$d iterations). Use `wp mcp-ai harness search step %1$d` to advance.', 'mcp-ai-wpoos' ),
					$assistant_id,
					$iterations
				)
			);
		}
	}

	/**
	 * Advance an active search by one step (evaluate + propose).
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID with an active search.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness search step 42
	 *
	 * @param array<int,string> $args Positional args: [ assistant-id ].
	 * @return void
	 */
	public function step( $args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		$result = WP_MCP_AI_Harness_Search_Engine::step_search( $assistant_id );

		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$status = $result['status'];
		if ( 'completed' === $status ) {
			WP_CLI::success( $result['message'] );
		} else {
			WP_CLI::log( $result['message'] );
			WP_CLI::log(
				sprintf(
				/* translators: %s: next step command hint */
					__( 'Status: %s. Run the same command again to continue.', 'mcp-ai-wpoos' ),
					$status
				)
			);
		}
	}

	/**
	 * Show the status of an active or completed search.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness search status 42
	 *
	 * @param array<int,string>    $args        Positional args: [ assistant-id ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function status( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		$st = WP_MCP_AI_Harness_Search_Engine::get_search_status( $assistant_id );

		if ( null === $st ) {
			WP_CLI::log( __( 'No search run found for this assistant.', 'mcp-ai-wpoos' ) );
			return;
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$rows   = array( $st );

		$fields = array( 'assistant_id', 'status', 'current_iter', 'evaluated', 'total', 'pareto_size' );
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Display the results of the most recent completed search.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--pareto-only]
	 * : Show only the Pareto frontier.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness search results 42 --format=json
	 *
	 * @param array<int,string>    $args        Positional args: [ assistant-id ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function results( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$format       = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$pareto_only  = WP_CLI\Utils\get_flag_value( $assoc_args, 'pareto-only', false );

		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		$population = WP_MCP_AI_Harness_Search_Engine::get_population( $assistant_id );
		$frontier   = WP_MCP_AI_Harness_Search_Engine::get_pareto_frontier( $assistant_id );

		if ( empty( $population ) ) {
			WP_CLI::log( __( 'No population data found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$entries = $pareto_only ? $frontier : array_values( $population );

		$rows = array();
		foreach ( $entries as $entry ) {
			$eval   = $entry['eval'];
			$rows[] = array(
				'hash'      => substr( $entry['hash'], 0, 8 ),
				'iteration' => isset( $entry['iteration'] ) ? (int) $entry['iteration'] : 0,
				'score'     => null !== $eval && ! isset( $eval['error'] ) && isset( $eval['aggregate']['score'] )
					? round( (float) $eval['aggregate']['score'], 4 )
					: '—',
				'cases'     => null !== $eval && ! isset( $eval['error'] ) && isset( $eval['aggregate']['total_cases'] )
					? (int) $eval['aggregate']['total_cases']
					: '—',
				'passed'    => null !== $eval && ! isset( $eval['error'] ) && isset( $eval['aggregate']['total_passed'] )
					? (int) $eval['aggregate']['total_passed']
					: '—',
			);
		}

		$fields = array( 'hash', 'iteration', 'score', 'cases', 'passed' );
		WP_CLI\Utils\format_items( $format, $rows, $fields );

		WP_CLI::log(
			sprintf(
			/* translators: 1: population size, 2: Pareto size */
				__( 'Population: %1$d entries | Pareto frontier: %2$d entries', 'mcp-ai-wpoos' ),
				count( $population ),
				count( $frontier )
			)
		);
	}

	/**
	 * Cancel an active search run.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness search cancel 42
	 *
	 * @param array<int,string> $args Positional args: [ assistant-id ].
	 * @return void
	 */
	public function cancel( $args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		WP_MCP_AI_Harness_Search_Engine::cancel_search( $assistant_id );
		WP_CLI::success( __( 'Search cancelled.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * List candidate profiles in the population.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - csv
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum entries to show (default: 20).
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness population list 42
	 *
	 * @param array<int,string>    $args        Positional args: [ assistant-id ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function population_list( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$format       = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$limit        = isset( $assoc_args['limit'] ) ? max( 1, min( 100, (int) $assoc_args['limit'] ) ) : 20;

		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		$population = WP_MCP_AI_Harness_Search_Engine::get_population( $assistant_id );

		if ( empty( $population ) ) {
			WP_CLI::log( __( 'No population data found.', 'mcp-ai-wpoos' ) );
			return;
		}

		// Sort by iteration desc, then score desc.
		$entries = array_values( $population );
		usort(
			$entries,
			static function ( $a, $b ) {
				$a_iter = isset( $a['iteration'] ) ? (int) $a['iteration'] : 0;
				$b_iter = isset( $b['iteration'] ) ? (int) $b['iteration'] : 0;
				if ( $a_iter !== $b_iter ) {
					return $b_iter - $a_iter;
				}
				$a_score = isset( $a['eval']['aggregate']['score'] ) ? (float) $a['eval']['aggregate']['score'] : 0.0;
				$b_score = isset( $b['eval']['aggregate']['score'] ) ? (float) $b['eval']['aggregate']['score'] : 0.0;
				return $b_score <=> $a_score;
			}
		);

		$entries = array_slice( $entries, 0, $limit );

		$rows = array();
		foreach ( $entries as $entry ) {
			$eval   = $entry['eval'];
			$rows[] = array(
				'hash'      => substr( $entry['hash'], 0, 8 ),
				'iteration' => isset( $entry['iteration'] ) ? (int) $entry['iteration'] : 0,
				'score'     => null !== $eval && ! isset( $eval['error'] ) && isset( $eval['aggregate']['score'] )
					? round( (float) $eval['aggregate']['score'], 4 )
					: ( null === $eval ? 'pending' : 'error' ),
			);
		}

		$fields = array( 'hash', 'iteration', 'score' );
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Diff two profiles from the population.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID.
	 *
	 * <hash-a>
	 * : First profile hash (or prefix).
	 *
	 * <hash-b>
	 * : Second profile hash (or prefix).
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness population diff 42 abc123 def456
	 *
	 * @param array<int,string>    $args        Positional args: [ assistant-id, hash-a, hash-b ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function population_diff( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$hash_a       = isset( $args[1] ) ? (string) $args[1] : '';
		$hash_b       = isset( $args[2] ) ? (string) $args[2] : '';
		$format       = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';

		if ( $assistant_id <= 0 || '' === $hash_a || '' === $hash_b ) {
			WP_CLI::error( __( 'Usage: wp mcp-ai harness population diff <assistant-id> <hash-a> <hash-b>', 'mcp-ai-wpoos' ) );
		}

		// Resolve hash prefixes.
		$population = WP_MCP_AI_Harness_Search_Engine::get_population( $assistant_id );
		$full_a     = self::resolve_hash( $population, $hash_a );
		$full_b     = self::resolve_hash( $population, $hash_b );

		if ( null === $full_a ) {
			/* translators: %s: profile hash prefix */
			WP_CLI::error( sprintf( __( 'Profile with hash prefix "%s" not found.', 'mcp-ai-wpoos' ), $hash_a ) );
		}
		if ( null === $full_b ) {
			/* translators: %s: profile hash prefix */
			WP_CLI::error( sprintf( __( 'Profile with hash prefix "%s" not found.', 'mcp-ai-wpoos' ), $hash_b ) );
		}

		$diff = WP_MCP_AI_Harness_Search_Engine::diff_profiles( $assistant_id, $full_a, $full_b );

		if ( is_wp_error( $diff ) ) {
			WP_CLI::error( $diff->get_error_message() );
		}

		if ( empty( $diff ) ) {
			WP_CLI::log( __( 'Profiles are identical.', 'mcp-ai-wpoos' ) );
			return;
		}

		$rows = array();
		foreach ( $diff as $entry ) {
			$rows[] = array(
				'path'    => $entry['path'],
				'value_a' => is_scalar( $entry['value_a'] ) ? (string) $entry['value_a'] : wp_json_encode( $entry['value_a'] ),
				'value_b' => is_scalar( $entry['value_b'] ) ? (string) $entry['value_b'] : wp_json_encode( $entry['value_b'] ),
			);
		}

		$fields = array( 'path', 'value_a', 'value_b' );
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * List trace runs for an assistant.
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * [--limit=<number>]
	 * : Maximum entries to show.
	 * ---
	 * default: 20
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness trace list 42
	 *
	 * @param array<int,string>    $args        Positional args: [ assistant-id ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function trace_list( $args, $assoc_args ) {
		$assistant_id = isset( $args[0] ) ? (int) $args[0] : 0;
		$format       = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$limit        = isset( $assoc_args['limit'] ) ? max( 1, min( 100, (int) $assoc_args['limit'] ) ) : 20;

		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'A valid assistant ID is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			WP_CLI::error( __( 'Trace store is not available.', 'mcp-ai-wpoos' ) );
		}

		$runs = WP_MCP_AI_Harness_Trace_Store::list_runs( $assistant_id, $limit );

		if ( empty( $runs ) ) {
			WP_CLI::log( __( 'No trace runs found.', 'mcp-ai-wpoos' ) );
			return;
		}

		$rows = array();
		foreach ( $runs as $run ) {
			$rows[] = array(
				'run_id'      => isset( $run['run_id'] ) ? $run['run_id'] : '—',
				'model'       => isset( $run['model'] ) ? $run['model'] : '—',
				'task_class'  => isset( $run['task_class'] ) ? $run['task_class'] : '—',
				'duration_ms' => isset( $run['duration_ms'] ) ? (int) $run['duration_ms'] : 0,
				'started_at'  => isset( $run['started_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $run['started_at'] ) : '—',
			);
		}

		$fields = array( 'run_id', 'model', 'task_class', 'duration_ms', 'started_at' );
		WP_CLI\Utils\format_items( $format, $rows, $fields );
	}

	/**
	 * Show a specific trace run artifact.
	 *
	 * ## OPTIONS
	 *
	 * <run-id>
	 * : Trace run ID (from `wp mcp-ai harness trace list`).
	 *
	 * [--artifact=<name>]
	 * : Artifact to display (meta, profile, score, cost, reasoning_trace,
	 *   retrieval, tool_calls, self_refine, model_response). Default: meta.
	 * ---
	 * default: meta
	 * ---
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - table
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai harness trace show assistant_42_run_1750000000_a1b2 --artifact=score
	 *
	 * @param array<int,string>    $args        Positional args: [ run-id ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function trace_show( $args, $assoc_args ) {
		$run_id   = isset( $args[0] ) ? (string) $args[0] : '';
		$artifact = isset( $assoc_args['artifact'] ) ? (string) $assoc_args['artifact'] : 'meta';
		$format   = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'json';

		if ( '' === $run_id ) {
			WP_CLI::error( __( 'A run ID is required.', 'mcp-ai-wpoos' ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Harness_Trace_Store' ) ) {
			WP_CLI::error( __( 'Trace store is not available.', 'mcp-ai-wpoos' ) );
		}

		// Map artifact names to filenames.
		$file_map = array(
			'meta'            => 'meta.json',
			'profile'         => 'profile.json',
			'score'           => 'score.json',
			'cost'            => 'cost.json',
			'reasoning_trace' => 'reasoning_trace.json',
			'retrieval'       => 'retrieval.json',
			'tool_calls'      => 'tool_calls.jsonl',
			'self_refine'     => 'self_refine.json',
			'model_response'  => 'model_response.txt',
		);

		$filename = isset( $file_map[ $artifact ] ) ? $file_map[ $artifact ] : $artifact;

		$content = WP_MCP_AI_Harness_Trace_Store::read_artifact( $run_id, $filename );

		if ( null === $content ) {
			/* translators: %s: artifact filename */
			WP_CLI::error( sprintf( __( 'Artifact "%s" not found for run.', 'mcp-ai-wpoos' ), $artifact ) );
		}

		if ( 'json' === $format ) {
			if ( is_array( $content ) ) {
				WP_CLI::log( wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
			} else {
				WP_CLI::log( (string) $content );
			}
		} elseif ( 'table' === $format && is_array( $content ) ) {
			// Flatten for table display.
			$rows = array();
			foreach ( $content as $key => $value ) {
				$rows[] = array(
					'key'   => (string) $key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'key', 'value' ) );
		} else {
			WP_CLI::log( is_string( $content ) ? $content : wp_json_encode( $content, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
		}
	}

	// -------------------------------------------------------------------------
	// Internal helpers
	// -------------------------------------------------------------------------

	/**
	 * Render the search results to the CLI.
	 *
	 * @param array $result Search result from run_search().
	 * @return void
	 */
	private function render_search_results( array $result ) {
		$stats = isset( $result['stats'] ) ? $result['stats'] : array();

		WP_CLI::success( __( 'Search complete.', 'mcp-ai-wpoos' ) );

		$summary = array(
			array(
				'candidates'  => isset( $stats['candidates'] ) ? (int) $stats['candidates'] : 0,
				'evaluated'   => isset( $stats['evaluated'] ) ? (int) $stats['evaluated'] : 0,
				'pareto_size' => isset( $stats['pareto_size'] ) ? (int) $stats['pareto_size'] : 0,
				'best_score'  => isset( $stats['best_score'] ) ? round( (float) $stats['best_score'], 4 ) : '—',
				'duration_ms' => isset( $stats['duration_ms'] ) ? (int) $stats['duration_ms'] : 0,
			),
		);

		WP_CLI\Utils\format_items( 'table', $summary, array( 'candidates', 'evaluated', 'pareto_size', 'best_score', 'duration_ms' ) );

		// Show Pareto frontier.
		$frontier = isset( $result['pareto_frontier'] ) ? $result['pareto_frontier'] : array();
		if ( ! empty( $frontier ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( __( 'Pareto frontier:', 'mcp-ai-wpoos' ) );
			$rows = array();
			foreach ( $frontier as $entry ) {
				$eval   = $entry['eval'];
				$rows[] = array(
					'hash'  => substr( $entry['hash'], 0, 8 ),
					'score' => null !== $eval && isset( $eval['aggregate']['score'] )
						? round( (float) $eval['aggregate']['score'], 4 )
						: '—',
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'hash', 'score' ) );
		}
	}

	/**
	 * Resolve a hash prefix to a full hash from the population.
	 *
	 * @param array  $population Population array.
	 * @param string $prefix     Hash prefix.
	 * @return string|null Full hash or null.
	 */
	private static function resolve_hash( array $population, $prefix ) {
		foreach ( $population as $hash => $entry ) {
			if ( 0 === strpos( $hash, $prefix ) ) {
				return $hash;
			}
		}
		return null;
	}
}
