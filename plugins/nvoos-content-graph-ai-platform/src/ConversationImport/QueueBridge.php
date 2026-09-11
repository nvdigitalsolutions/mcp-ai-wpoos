<?php
/**
 * Async job queue bridge for conversation imports (Wave E4,
 * sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Queue`: byte-identical
 * `conversation_import` job type, argument sanitization and the
 * `wp_mcp_ai_import_missing_source` / `wp_mcp_ai_import_queue_missing`
 * envelopes, priority-low enqueueing with two retries, manager
 * execution with percent progress callbacks, the JetEngine probe, the
 * WP_Error-to-Exception conversion contract for queue retries, the
 * uploads-scoped source cleanup, and the status shape consumed by UI
 * polling.
 *
 * Documented deviations:
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *  - Class name/namespace — the platform's PSR-4 tree.
 *  - The async queue resolves per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): base
 *    `WP_MCP_AI_Async_Job_Queue` monolith, this package's
 *    `Queues\AsyncJobQueue` standalone (same `queue_job` / `get_job` /
 *    `update_job` / `PRIORITY_LOW` surface).
 *  - The executed manager is this package's `Manager` (which carries
 *    the per-mode collaborator seams).
 *  - `Exception` is fully qualified (`\Exception`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Bridges the import manager and the async job queue.
 *
 * @since 2.1.0
 */
class QueueBridge {

	const JOB_TYPE = 'conversation_import';

	/**
	 * Resolve the async job queue class for this install mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `class_exists()` — because the monorepo classmap resolves the base
	 * queue class standalone, where its storage table does not exist.
	 *
	 * @return string Queue class name, or '' when unavailable.
	 */
	protected static function queue_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			return 'WP_MCP_AI_Async_Job_Queue';
		}

		if ( class_exists( \NvoosContentGraphAiPlatform\Queues\AsyncJobQueue::class ) ) {
			return \NvoosContentGraphAiPlatform\Queues\AsyncJobQueue::class;
		}

		return '';
	}

	/**
	 * Enqueue an import run as a background job.
	 *
	 * @param array $args {
	 *     Import arguments (same shape as {@see Manager::run()},
	 *     minus the manager-only keys).
	 *
	 *     @type string|int $source       File path or media attachment ID.
	 *     @type string     $format       Optional format override.
	 *     @type bool       $dry_run      Preview only.
	 *     @type string     $policy       "skip" or "refresh".
	 *     @type int        $batch_size   Conversations per batch.
	 *     @type int        $limit        Max conversations (0 = all).
	 *     @type int        $user_id      Importing user ID.
	 *     @type int        $estimate     Estimated total conversations.
	 *     @type bool       $sideload_media Sideload referenced export images.
	 *     @type bool       $cleanup_source Whether to delete the source file after success.
	 * }
	 * @return int|\WP_Error Job ID, or a WP_Error.
	 */
	public static function enqueue( array $args ) {
		$queue_class = static::queue_class();
		if ( '' === $queue_class ) {
			return new \WP_Error(
				'wp_mcp_ai_import_queue_missing',
				__( 'The async job queue is unavailable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$job_data = array(
			'source'         => isset( $args['source'] ) ? sanitize_text_field( (string) $args['source'] ) : '',
			'dry_run'        => ! empty( $args['dry_run'] ),
			'policy'         => isset( $args['policy'] ) ? sanitize_key( (string) $args['policy'] ) : 'skip',
			'batch_size'     => isset( $args['batch_size'] ) ? absint( $args['batch_size'] ) : 25,
			'limit'          => isset( $args['limit'] ) ? absint( $args['limit'] ) : 0,
			'user_id'        => isset( $args['user_id'] ) ? absint( $args['user_id'] ) : get_current_user_id(),
			'estimate'       => isset( $args['estimate'] ) ? absint( $args['estimate'] ) : 0,
			'sideload_media' => ! empty( $args['sideload_media'] ),
			'cleanup_source' => ! empty( $args['cleanup_source'] ),
		);

		if ( ! empty( $args['format'] ) ) {
			$job_data['format'] = sanitize_key( (string) $args['format'] );
		}

		if ( '' === $job_data['source'] ) {
			return new \WP_Error(
				'wp_mcp_ai_import_missing_source',
				__( 'Provide an import source to queue.', 'nvoos-content-graph-ai-platform' )
			);
		}

		return $queue_class::queue_job(
			array(
				'job_type'    => self::JOB_TYPE,
				'job_data'    => $job_data,
				'priority'    => $queue_class::PRIORITY_LOW,
				'user_id'     => $job_data['user_id'],
				'max_retries' => 2,
			)
		);
	}

	/**
	 * Execute a queued conversation import job.
	 *
	 * Called by the async queue worker. Returns the import report, which the
	 * queue stores as the job result.
	 *
	 * @param array $job_data Queued job payload.
	 * @param int   $job_id   Async job row ID (for progress updates).
	 * @return array Import report.
	 * @throws \Exception When the import cannot start (queue retries the job).
	 */
	public static function execute( array $job_data, $job_id = 0 ) {
		if ( ! function_exists( 'jet_engine' ) && ! class_exists( 'Jet_Engine' ) ) {
			throw new \Exception( __( 'JetEngine is not active; conversation import cannot run.', 'nvoos-content-graph-ai-platform' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		$queue_class = static::queue_class();

		$manager  = new Manager();
		$estimate = isset( $job_data['estimate'] ) ? absint( $job_data['estimate'] ) : 0;

		if ( $job_id > 0 && '' !== $queue_class ) {
			$manager->set_progress_callback(
				function ( $progress ) use ( $job_id, $estimate, $queue_class ) {
					$percent = 0;
					if ( $estimate > 0 ) {
						$percent = min( 99, (int) floor( $progress['processed'] / $estimate * 100 ) );
					}
					$queue_class::update_job(
						$job_id,
						array( 'progress' => $percent )
					);
				}
			);
		}

		$report = $manager->run(
			array(
				'source'         => isset( $job_data['source'] ) ? $job_data['source'] : '',
				'format'         => isset( $job_data['format'] ) ? $job_data['format'] : '',
				'dry_run'        => ! empty( $job_data['dry_run'] ),
				'policy'         => isset( $job_data['policy'] ) ? $job_data['policy'] : 'skip',
				'batch_size'     => isset( $job_data['batch_size'] ) ? absint( $job_data['batch_size'] ) : 25,
				'limit'          => isset( $job_data['limit'] ) ? absint( $job_data['limit'] ) : 0,
				'user_id'        => isset( $job_data['user_id'] ) ? absint( $job_data['user_id'] ) : 0,
				'estimate'       => $estimate,
				'sideload_media' => ! empty( $job_data['sideload_media'] ),
			)
		);

		if ( is_wp_error( $report ) ) {
			throw new \Exception( $report->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		// Delete the uploaded source file once the run finished successfully.
		if ( ! empty( $job_data['cleanup_source'] ) && is_string( $job_data['source'] ) && ! is_numeric( $job_data['source'] ) ) {
			$source_path = wp_normalize_path( $job_data['source'] );
			$upload_dir  = wp_upload_dir();
			$base        = isset( $upload_dir['basedir'] ) && is_string( $upload_dir['basedir'] )
				? wp_normalize_path( $upload_dir['basedir'] )
				: '';

			if ( '' !== $base && 0 === strpos( $source_path, $base ) && file_exists( $source_path ) ) {
				wp_delete_file( $source_path );
			}
		}

		return $report;
	}

	/**
	 * Retrieve a queued import job's status for UI polling.
	 *
	 * @param int $job_id Async job row ID.
	 * @return array|\WP_Error {
	 *     @type int    $id       Job ID.
	 *     @type string $status   Job status.
	 *     @type int    $progress Progress 0-100.
	 *     @type array  $result   Import report (when completed).
	 *     @type array  $error    Error payload (when failed).
	 * }
	 */
	public static function get_status( $job_id ) {
		$queue_class = static::queue_class();
		if ( '' === $queue_class ) {
			return new \WP_Error(
				'wp_mcp_ai_import_queue_missing',
				__( 'The async job queue is unavailable.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$job = $queue_class::get_job( absint( $job_id ) );
		if ( empty( $job ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_job_not_found',
				__( 'The import job was not found.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$status = array(
			'id'       => absint( $job_id ),
			'status'   => isset( $job['status'] ) ? $job['status'] : 'unknown',
			'progress' => isset( $job['progress'] ) ? absint( $job['progress'] ) : 0,
		);

		if ( ! empty( $job['result'] ) ) {
			$result = json_decode( $job['result'], true );
			if ( is_array( $result ) ) {
				$status['result'] = $result;
			}
		}

		if ( ! empty( $job['error'] ) ) {
			$error = json_decode( $job['error'], true );
			if ( is_array( $error ) ) {
				$status['error'] = $error;
			}
		}

		return $status;
	}
}
