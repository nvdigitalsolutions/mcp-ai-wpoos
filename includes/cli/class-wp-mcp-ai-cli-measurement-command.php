<?php
/**
 * Measurement WP-CLI commands (PR 11).
 *
 * Surfaces the eval-suite runner and the regression detector at the
 * command line so CI/CD can run measurement work without standing up
 * the web runtime. Subcommands:
 *
 *   wp mcp-ai measurement run <suite>
 *       Runs a registered eval suite using a generator callable that
 *       site code provides via the `wp_mcp_ai_cli_measurement_generator`
 *       filter. Persists the run summary, emits stock metrics
 *       (`eval.suite.pass_rate`), and prints the summary.
 *
 *   wp mcp-ai measurement alert-check <suite>
 *       Compares the most-recent run for `<suite>` against the
 *       trailing N runs and emits a non-zero exit if the
 *       regression detector flags any rule. Optionally POSTs an
 *       alert payload to a webhook URL.
 *
 *   wp mcp-ai measurement list-runs <suite>
 *       Prints stored run summaries for `<suite>`.
 *
 * The command has no inline business logic for regression decisions
 * beyond payload formatting — every threshold lives in
 * `WP_MCP_AI_Eval_Regression_Detector`, which is unit-tested
 * separately.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Measurement subsystem WP-CLI commands.
 */
class WP_MCP_AI_CLI_Measurement_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Default trailing-window size for `alert-check`.
	 */
	const DEFAULT_BASELINE_WINDOW = 10;

	/**
	 * Run a registered eval suite.
	 *
	 * ## OPTIONS
	 *
	 * <suite>
	 * : Suite slug to run.
	 *
	 * [--format=<format>]
	 * : Render the summary in this format. Default `table`.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--rewards=<csv>]
	 * : Comma-separated reward slugs to evaluate per case.
	 *
	 * [--no-persist]
	 * : Skip persisting the run summary. Useful for ad-hoc smoke runs
	 *   that should not influence later `alert-check` baselines.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai measurement run my-suite --format=json
	 *
	 * @param array<int,string>    $args        Positional args: [ suite-slug ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function run( $args, $assoc_args ) {
		list( $suite, $registry ) = $this->resolve_suite( $args );
		$format                   = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		$persist                  = ! WP_CLI\Utils\get_flag_value( $assoc_args, 'no-persist', false );
		$rewards                  = $this->parse_csv( $assoc_args, 'rewards' );

		$generator = $this->resolve_generator( $suite->get_slug() );
		if ( ! is_callable( $generator ) ) {
			WP_CLI::error(
				sprintf(
				/* translators: %s suite slug */
					__( 'No generator callable was registered for suite "%s". Hook `wp_mcp_ai_cli_measurement_generator` and return a callable.', 'mcp-ai-wpoos' ),
					$suite->get_slug()
				)
			);
		}

		$runner = $this->build_runner();
		$report = $runner->run( $suite, $generator, array( 'rewards' => $rewards ) );

		if ( ! empty( $report['error'] ) ) {
			WP_CLI::error(
				sprintf(
				/* translators: %s eval-runner error code */
					__( 'Eval runner refused to start: %s', 'mcp-ai-wpoos' ),
					(string) $report['error']
				)
			);
		}

		$summary = isset( $report['summary'] ) && is_array( $report['summary'] ) ? $report['summary'] : array();

		if ( $persist ) {
			$store = WP_MCP_AI_Eval_Run_Store::get_instance();
			$store->record( $suite->get_slug(), $summary, isset( $report['started_at'] ) ? (int) $report['started_at'] : null );

			// Emit the pass-rate gauge so dashboards see the run too.
			if ( class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
				WP_MCP_AI_Metric_Collector::get_instance()->record(
					WP_MCP_AI_Stock_Metrics::EVAL_SUITE_PASS_RATE,
					isset( $summary['pass_rate'] ) ? (float) $summary['pass_rate'] : 0.0,
					array( 'suite' => $suite->get_slug() )
				);
			}
		}

		$this->render_summary_row( $summary, $suite->get_slug(), $format );
		unset( $registry ); // keep static analysers quiet — registry was used for resolution.
	}

	/**
	 * Compare the most recent run against the trailing baseline window
	 * and exit non-zero when a regression rule triggers.
	 *
	 * ## OPTIONS
	 *
	 * <suite>
	 * : Suite slug.
	 *
	 * [--window=<n>]
	 * : Baseline window size. Default 10.
	 *
	 * [--pass-rate-drop=<float>]
	 * : Override pass-rate-drop threshold (absolute, 0..1).
	 *
	 * [--error-rate-rise=<float>]
	 * : Override error-rate-rise threshold.
	 *
	 * [--abstention-rate-rise=<float>]
	 * : Override abstention-rate-rise threshold.
	 *
	 * [--webhook=<url>]
	 * : Optional URL to POST a JSON alert payload to when a regression
	 *   triggers. Network failures are reported but never mask the
	 *   regression exit code.
	 *
	 * [--format=<format>]
	 * : Render output in `table` (default) or `json` (machine-friendly).
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai measurement alert-check my-suite --window=5 --pass-rate-drop=0.02
	 *
	 * @param array<int,string>    $args        Positional: [ suite-slug ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function alert_check( $args, $assoc_args ) {
		list( $suite, $registry ) = $this->resolve_suite( $args );
		unset( $registry );

		$window = isset( $assoc_args['window'] ) ? max( 1, (int) $assoc_args['window'] ) : self::DEFAULT_BASELINE_WINDOW;
		$store  = WP_MCP_AI_Eval_Run_Store::get_instance();
		$recent = $store->get_recent( $suite->get_slug(), $window + 1 ); // newest-first.

		if ( empty( $recent ) ) {
			WP_CLI::warning(
				sprintf(
				/* translators: 1: suite slug, 2: suite slug repeated */
					__( 'No persisted runs found for suite "%1$s". Run `wp mcp-ai measurement run %2$s` first.', 'mcp-ai-wpoos' ),
					$suite->get_slug(),
					$suite->get_slug()
				)
			);
			return;
		}

		$current_envelope = array_shift( $recent );
		$baseline         = $recent;
		$current_summary  = isset( $current_envelope['summary'] ) && is_array( $current_envelope['summary'] ) ? $current_envelope['summary'] : array();

		$config = array();
		foreach ( array(
			'pass-rate-drop'       => 'pass_rate_drop',
			'error-rate-rise'      => 'error_rate_rise',
			'abstention-rate-rise' => 'abstention_rate_rise',
		) as $cli_key => $config_key ) {
			if ( isset( $assoc_args[ $cli_key ] ) && is_numeric( $assoc_args[ $cli_key ] ) ) {
				$config[ $config_key ] = (float) $assoc_args[ $cli_key ];
			}
		}

		$baseline_summaries = array();
		foreach ( $baseline as $env ) {
			if ( isset( $env['summary'] ) && is_array( $env['summary'] ) ) {
				$baseline_summaries[] = $env['summary'];
			}
		}

		$report  = WP_MCP_AI_Eval_Regression_Detector::detect( $current_summary, $baseline_summaries, $config );
		$payload = array(
			'suite'              => $suite->get_slug(),
			'window'             => $window,
			'baseline_size'      => (int) $report['baseline_size'],
			'is_regression'      => (bool) $report['is_regression'],
			'reasons'            => $report['reasons'],
			'thresholds'         => $report['thresholds'],
			'baseline_means'     => $report['baseline_means'],
			'current_summary'    => $current_summary,
			'current_started_at' => isset( $current_envelope['started_at'] ) ? (int) $current_envelope['started_at'] : 0,
		);

		if ( $report['is_regression'] && class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			$collector = WP_MCP_AI_Metric_Collector::get_instance();
			foreach ( $report['reasons'] as $reason ) {
				$collector->record(
					WP_MCP_AI_Stock_Metrics::EVAL_SUITE_REGRESSION_COUNT,
					1,
					array(
						'suite'  => $suite->get_slug(),
						'metric' => (string) $reason['metric'],
					)
				);
			}
		}

		$webhook = isset( $assoc_args['webhook'] ) ? (string) $assoc_args['webhook'] : '';
		if ( '' !== $webhook && $report['is_regression'] ) {
			$this->dispatch_webhook( $webhook, $payload );
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		if ( 'json' === $format ) {
			WP_CLI::print_value( $payload, array( 'format' => 'json' ) );
		} else {
			$this->render_alert_table( $payload );
		}

		if ( $report['is_regression'] ) {
			WP_CLI::halt( 2 );
		}
	}

	/**
	 * List persisted runs for a suite.
	 *
	 * ## OPTIONS
	 *
	 * <suite>
	 * : Suite slug.
	 *
	 * [--format=<format>]
	 * : Render output in `table` (default), `json`, `yaml`, or `csv`.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * @param array<int,string>    $args        Positional: [ suite-slug ].
	 * @param array<string,string> $assoc_args Associative args.
	 * @return void
	 */
	public function list_runs( $args, $assoc_args ) {
		list( $suite, $registry ) = $this->resolve_suite( $args );
		unset( $registry );
		$store = WP_MCP_AI_Eval_Run_Store::get_instance();
		$runs  = $store->get_all( $suite->get_slug() );

		if ( empty( $runs ) ) {
			WP_CLI::log(
				sprintf(
				/* translators: %s suite slug */
					__( 'No persisted runs for suite "%s".', 'mcp-ai-wpoos' ),
					$suite->get_slug()
				)
			);
			return;
		}

		$rows = array();
		foreach ( $runs as $run ) {
			$summary = isset( $run['summary'] ) && is_array( $run['summary'] ) ? $run['summary'] : array();
			$rows[]  = array(
				'started_at'      => isset( $run['started_at'] ) ? gmdate( 'Y-m-d H:i:s', (int) $run['started_at'] ) . ' UTC' : '',
				'total'           => isset( $summary['total'] ) ? (int) $summary['total'] : 0,
				'pass_rate'       => isset( $summary['pass_rate'] ) ? sprintf( '%.4f', (float) $summary['pass_rate'] ) : '0.0000',
				'error_rate'      => isset( $summary['error_rate'] ) ? sprintf( '%.4f', (float) $summary['error_rate'] ) : '0.0000',
				'abstention_rate' => isset( $summary['abstention_rate'] ) ? sprintf( '%.4f', (float) $summary['abstention_rate'] ) : '0.0000',
				'mean_score'      => isset( $summary['mean_score'] ) ? sprintf( '%.4f', (float) $summary['mean_score'] ) : '0.0000',
			);
		}

		$format = isset( $assoc_args['format'] ) ? (string) $assoc_args['format'] : 'table';
		WP_CLI\Utils\format_items( $format, $rows, array( 'started_at', 'total', 'pass_rate', 'error_rate', 'abstention_rate', 'mean_score' ) );
	}

	/**
	 * Resolve the suite slug positional arg into an Eval_Suite instance.
	 *
	 * @param array<int,string> $args Positional args.
	 * @return array{0:WP_MCP_AI_Eval_Suite,1:WP_MCP_AI_Eval_Suite_Registry}
	 */
	private function resolve_suite( $args ) {
		if ( empty( $args[0] ) ) {
			WP_CLI::error( __( 'Suite slug is required.', 'mcp-ai-wpoos' ) );
		}
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			WP_CLI::error( __( 'Eval suite registry is unavailable on this site.', 'mcp-ai-wpoos' ) );
		}
		$registry = WP_MCP_AI_Eval_Suite_Registry::get_instance();
		$registry->boot();
		$suite = $registry->get( (string) $args[0] );
		if ( ! $suite instanceof WP_MCP_AI_Eval_Suite ) {
			WP_CLI::error(
				sprintf(
				/* translators: %s suite slug */
					__( 'No eval suite registered with slug "%s".', 'mcp-ai-wpoos' ),
					(string) $args[0]
				)
			);
		}
		return array( $suite, $registry );
	}

	/**
	 * Resolve the generator callable for a given suite slug.
	 *
	 * Sites declare their generator(s) via the
	 * `wp_mcp_ai_cli_measurement_generator` filter — keeping the
	 * callable out of the suite definition lets the same suite be
	 * reused with multiple generators (live model, replay fixture, etc).
	 *
	 * @param string $slug Suite slug.
	 * @return callable|null
	 */
	private function resolve_generator( $slug ) {
		/**
		 * Filter the generator callable used by the measurement CLI.
		 *
		 * Return either a callable, or `null` to leave it unresolved
		 * (the command will then error with a clear message).
		 *
		 * @since 1.3.0
		 *
		 * @param callable|null $generator Resolved generator (default `null`).
		 * @param string        $slug      Suite slug being requested.
		 */
		$generator = apply_filters( 'wp_mcp_ai_cli_measurement_generator', null, (string) $slug );
		return is_callable( $generator ) ? $generator : null;
	}

	/**
	 * Build an Eval_Runner wired to the live registries.
	 *
	 * @return WP_MCP_AI_Eval_Runner
	 */
	private function build_runner() {
		return new WP_MCP_AI_Eval_Runner(
			WP_MCP_AI_Verifier_Registry::get_instance(),
			WP_MCP_AI_Reward_Function_Registry::get_instance(),
			WP_MCP_AI_Metric_Collector::get_instance()
		);
	}

	/**
	 * Parse a comma-separated assoc-arg into an array of trimmed slugs.
	 *
	 * @param array<string,string> $assoc_args Assoc args.
	 * @param string               $key        Argument name.
	 * @return array<int,string>
	 */
	private function parse_csv( $assoc_args, $key ) {
		if ( empty( $assoc_args[ $key ] ) ) {
			return array();
		}
		$parts = array_map( 'trim', explode( ',', (string) $assoc_args[ $key ] ) );
		return array_values( array_filter( $parts, 'strlen' ) );
	}

	/**
	 * Render a single suite-run summary as a table or structured doc.
	 *
	 * @param array  $summary Summary.
	 * @param string $slug    Suite slug.
	 * @param string $format  `table`, `json`, or `yaml`.
	 * @return void
	 */
	private function render_summary_row( array $summary, $slug, $format ) {
		$row = array(
			'suite'           => $slug,
			'total'           => isset( $summary['total'] ) ? (int) $summary['total'] : 0,
			'passed'          => isset( $summary['passed'] ) ? (int) $summary['passed'] : 0,
			'pass_rate'       => isset( $summary['pass_rate'] ) ? sprintf( '%.4f', (float) $summary['pass_rate'] ) : '0.0000',
			'error_rate'      => isset( $summary['error_rate'] ) ? sprintf( '%.4f', (float) $summary['error_rate'] ) : '0.0000',
			'abstention_rate' => isset( $summary['abstention_rate'] ) ? sprintf( '%.4f', (float) $summary['abstention_rate'] ) : '0.0000',
			'mean_score'      => isset( $summary['mean_score'] ) ? sprintf( '%.4f', (float) $summary['mean_score'] ) : '0.0000',
		);
		WP_CLI\Utils\format_items( $format, array( $row ), array_keys( $row ) );
	}

	/**
	 * Pretty-print the alert payload.
	 *
	 * @param array $payload Alert payload.
	 * @return void
	 */
	private function render_alert_table( array $payload ) {
		WP_CLI::log(
			sprintf(
			/* translators: 1: suite slug, 2: window size, 3: baseline size, 4: yes/no */
				__( 'Suite: %1$s   Window: %2$d   Baseline samples: %3$d   Regression: %4$s', 'mcp-ai-wpoos' ),
				(string) $payload['suite'],
				(int) $payload['window'],
				(int) $payload['baseline_size'],
				$payload['is_regression'] ? __( 'YES', 'mcp-ai-wpoos' ) : __( 'no', 'mcp-ai-wpoos' )
			)
		);
		if ( ! empty( $payload['reasons'] ) ) {
			$rows = array();
			foreach ( $payload['reasons'] as $reason ) {
				$rows[] = array(
					'metric'    => isset( $reason['metric'] ) ? (string) $reason['metric'] : '',
					'baseline'  => isset( $reason['baseline'] ) ? sprintf( '%.4f', (float) $reason['baseline'] ) : '',
					'current'   => isset( $reason['current'] ) ? sprintf( '%.4f', (float) $reason['current'] ) : '',
					'delta'     => isset( $reason['delta'] ) ? sprintf( '%.4f', (float) $reason['delta'] ) : '',
					'threshold' => isset( $reason['threshold'] ) ? sprintf( '%.4f', (float) $reason['threshold'] ) : '',
				);
			}
			WP_CLI\Utils\format_items( 'table', $rows, array( 'metric', 'baseline', 'current', 'delta', 'threshold' ) );
		}
	}

	/**
	 * POST an alert payload to a webhook URL. Network failures emit a
	 * `WP_CLI::warning` but never override the regression exit code.
	 *
	 * @param string $url     Webhook URL.
	 * @param array  $payload JSON payload.
	 * @return void
	 */
	private function dispatch_webhook( $url, array $payload ) {
		$url = esc_url_raw( $url );
		if ( '' === $url ) {
			WP_CLI::warning( __( 'Webhook URL was empty after sanitization; not dispatching alert.', 'mcp-ai-wpoos' ) );
			return;
		}
		$response = wp_remote_post(
			$url,
			array(
				'timeout' => 10,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'body'    => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			WP_CLI::warning(
				sprintf(
				/* translators: %s: webhook error message */
					__( 'Webhook dispatch failed: %s', 'mcp-ai-wpoos' ),
					$response->get_error_message()
				)
			);
			return;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			WP_CLI::warning(
				sprintf(
				/* translators: %d: HTTP response code */
					__( 'Webhook dispatch returned HTTP %d.', 'mcp-ai-wpoos' ),
					$code
				)
			);
		}
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai measurement', 'WP_MCP_AI_CLI_Measurement_Command' );
}
