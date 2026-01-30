<?php
/**
 * Base class for WP-CLI commands with enhanced functionality.
 *
 * Provides common utilities for batch processing, progress bars,
 * error handling, and consistent output formatting.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

/**
 * Abstract base class for WP-CLI commands.
 *
 * @since 1.0.0
 */
abstract class WP_MCP_AI_CLI_Base_Command extends WP_CLI_Command {

	/**
	 * Progress bar instance.
	 *
	 * @var \cli\progress\Bar|null
	 */
	protected $progress_bar = null;

	/**
	 * Error count during batch processing.
	 *
	 * @var int
	 */
	protected $error_count = 0;

	/**
	 * Success count during batch processing.
	 *
	 * @var int
	 */
	protected $success_count = 0;

	/**
	 * Start time for performance tracking.
	 *
	 * @var float
	 */
	protected $start_time = 0;

	/**
	 * Create and display a progress bar.
	 *
	 * @param string $message Progress bar message.
	 * @param int    $total   Total number of items to process.
	 * @return \cli\progress\Bar Progress bar instance.
	 */
	protected function create_progress_bar( $message, $total ) {
		$this->progress_bar = WP_CLI\Utils\make_progress_bar( $message, $total );
		return $this->progress_bar;
	}

	/**
	 * Tick the progress bar (advance by one).
	 *
	 * @return void
	 */
	protected function tick_progress() {
		if ( $this->progress_bar ) {
			$this->progress_bar->tick();
		}
	}

	/**
	 * Finish and close the progress bar.
	 *
	 * @return void
	 */
	protected function finish_progress() {
		if ( $this->progress_bar ) {
			$this->progress_bar->finish();
			$this->progress_bar = null;
		}
	}

	/**
	 * Process items in batches with progress tracking.
	 *
	 * @param array    $items    Items to process.
	 * @param callable $callback Callback function to process each item.
	 * @param array    $options  Processing options.
	 * @return array Results with success_count, error_count, errors.
	 */
	protected function batch_process( $items, $callback, $options = array() ) {
		$defaults = array(
			'batch_size'     => 50,
			'progress_label' => __( 'Processing items', 'mcp-ai-wpoos' ),
			'dry_run'        => false,
			'stop_on_error'  => false,
		);

		$options = wp_parse_args( $options, $defaults );

		$this->error_count   = 0;
		$this->success_count = 0;
		$errors              = array();
		$total               = count( $items );

		// Create progress bar.
		$progress = $this->create_progress_bar( $options['progress_label'], $total );

		// Process in batches to manage memory.
		$chunks = array_chunk( $items, $options['batch_size'] );

		foreach ( $chunks as $chunk ) {
			foreach ( $chunk as $item ) {
				try {
					if ( ! $options['dry_run'] ) {
						$result = call_user_func( $callback, $item );

						if ( is_wp_error( $result ) ) {
							++$this->error_count;
							$errors[] = array(
								'item'    => $item,
								'message' => $result->get_error_message(),
							);

							if ( $options['stop_on_error'] ) {
								break 2; // Break out of both loops.
							}
						} else {
							++$this->success_count;
						}
					} else {
						// Dry run mode - just count.
						++$this->success_count;
					}
				} catch ( Exception $e ) {
					++$this->error_count;
					$errors[] = array(
						'item'    => $item,
						'message' => $e->getMessage(),
					);

					if ( $options['stop_on_error'] ) {
						break 2;
					}
				}

				$this->tick_progress();

				// Clear object cache periodically to prevent memory issues.
				if ( 0 === ( $this->success_count + $this->error_count ) % $options['batch_size'] ) {
					$this->clear_local_cache();
				}
			}
		}

		$this->finish_progress();

		return array(
			'success_count' => $this->success_count,
			'error_count'   => $this->error_count,
			'errors'        => $errors,
			'total'         => $total,
		);
	}

	/**
	 * Display success message.
	 *
	 * @param string $message Success message.
	 * @return void
	 */
	protected function success( $message ) {
		WP_CLI::success( $message );
	}

	/**
	 * Display error message.
	 *
	 * @param string $message Error message.
	 * @param bool   $exit    Whether to exit after displaying error.
	 * @return void
	 */
	protected function error( $message, $exit = true ) {
		WP_CLI::error( $message, $exit );
	}

	/**
	 * Display warning message.
	 *
	 * @param string $message Warning message.
	 * @return void
	 */
	protected function warning( $message ) {
		WP_CLI::warning( $message );
	}

	/**
	 * Display info message.
	 *
	 * @param string $message Info message.
	 * @return void
	 */
	protected function info( $message ) {
		WP_CLI::log( $message );
	}

	/**
	 * Display debug message (only if --debug flag is set).
	 *
	 * @param string $message Debug message.
	 * @return void
	 */
	protected function debug( $message ) {
		WP_CLI::debug( $message );
	}

	/**
	 * Ask user for confirmation.
	 *
	 * @param string $question Confirmation question.
	 * @param array  $assoc_args Associative arguments.
	 * @return bool True if confirmed.
	 */
	protected function confirm( $question, $assoc_args = array() ) {
		// Use WP-CLI utility to check for yes flag or prompt user.
		return WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ) ||
			WP_CLI\Utils\get_flag_value( $assoc_args, 'y', false );
	}

	/**
	 * Start performance timer.
	 *
	 * @return void
	 */
	protected function start_timer() {
		$this->start_time = microtime( true );
	}

	/**
	 * Get elapsed time since timer started.
	 *
	 * @return float Elapsed time in seconds.
	 */
	protected function get_elapsed_time() {
		if ( 0 === $this->start_time ) {
			return 0;
		}

		return microtime( true ) - $this->start_time;
	}

	/**
	 * Display execution summary.
	 *
	 * @param array $results Processing results.
	 * @return void
	 */
	protected function display_summary( $results ) {
		$elapsed = $this->get_elapsed_time();

		WP_CLI::log( '' );
		WP_CLI::log( WP_CLI::colorize( '%G' . __( 'Summary:', 'mcp-ai-wpoos' ) . '%n' ) );
		WP_CLI::log(
			sprintf(
			/* translators: %d: number of items */
				__( '  Total items: %d', 'mcp-ai-wpoos' ),
				$results['total']
			)
		);
		WP_CLI::log(
			sprintf(
			/* translators: %d: number of items */
				__( '  Successful: %d', 'mcp-ai-wpoos' ),
				$results['success_count']
			)
		);

		if ( $results['error_count'] > 0 ) {
			WP_CLI::log(
				WP_CLI::colorize(
					sprintf(
						/* translators: %d: number of items */
						'%%R  Failed: %d%%n',
						$results['error_count']
					)
				)
			);
		}

		if ( $elapsed > 0 ) {
			WP_CLI::log(
				sprintf(
				/* translators: %s: elapsed time */
					__( '  Time: %s seconds', 'mcp-ai-wpoos' ),
					number_format( $elapsed, 2 )
				)
			);

			if ( $results['total'] > 0 ) {
				$rate = $results['total'] / $elapsed;
				WP_CLI::log(
					sprintf(
					/* translators: %s: processing rate */
						__( '  Rate: %s items/second', 'mcp-ai-wpoos' ),
						number_format( $rate, 2 )
					)
				);
			}
		}

		// Display errors if any.
		if ( ! empty( $results['errors'] ) ) {
			WP_CLI::log( '' );
			WP_CLI::log( WP_CLI::colorize( '%R' . __( 'Errors:', 'mcp-ai-wpoos' ) . '%n' ) );

			foreach ( array_slice( $results['errors'], 0, 10 ) as $error ) {
				WP_CLI::log(
					sprintf(
						'  - %s',
						$error['message']
					)
				);
			}

			if ( count( $results['errors'] ) > 10 ) {
				WP_CLI::log(
					sprintf(
						/* translators: %d: number of errors */
						__( '  ... and %d more errors', 'mcp-ai-wpoos' ),
						count( $results['errors'] ) - 10
					)
				);
			}
		}
	}

	/**
	 * Clear local object cache to prevent memory issues.
	 *
	 * @return void
	 */
	protected function clear_local_cache() {
		global $wpdb, $wp_object_cache;

		if ( $wpdb ) {
			$wpdb->queries = array();
		}

		if ( is_object( $wp_object_cache ) ) {
			$wp_object_cache->group_ops    = array();
			$wp_object_cache->stats        = array();
			$wp_object_cache->cache        = array();
			$wp_object_cache->cache_hits   = 0;
			$wp_object_cache->cache_misses = 0;
		}
	}

	/**
	 * Parse and validate common arguments.
	 *
	 * @param array $assoc_args Associative arguments from command.
	 * @return array Parsed arguments.
	 */
	protected function parse_common_args( $assoc_args ) {
		return array(
			'dry_run' => WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false ),
			'yes'     => WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false ),
			'format'  => WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' ),
			'limit'   => isset( $assoc_args['limit'] ) ? absint( $assoc_args['limit'] ) : 0,
			'offset'  => isset( $assoc_args['offset'] ) ? absint( $assoc_args['offset'] ) : 0,
		);
	}

	/**
	 * Format data for output.
	 *
	 * @param mixed  $data   Data to format.
	 * @param string $format Output format (table, json, yaml, csv).
	 * @param array  $fields Fields to include in output.
	 * @return void
	 */
	protected function format_output( $data, $format = 'table', $fields = array() ) {
		if ( empty( $data ) ) {
			WP_CLI::log( __( 'No data to display.', 'mcp-ai-wpoos' ) );
			return;
		}

		$formatter = new WP_CLI\Formatter(
			array(
				'format' => $format,
				'fields' => $fields,
			)
		);

		$formatter->display_items( $data );
	}

	/**
	 * Check if dry run mode is enabled.
	 *
	 * @param array $assoc_args Associative arguments.
	 * @return bool True if dry run mode.
	 */
	protected function is_dry_run( $assoc_args ) {
		return WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
	}

	/**
	 * Display dry run notice.
	 *
	 * @return void
	 */
	protected function dry_run_notice() {
		WP_CLI::log( WP_CLI::colorize( '%Y' . __( 'DRY RUN MODE - No changes will be made', 'mcp-ai-wpoos' ) . '%n' ) );
		WP_CLI::log( '' );
	}
}
