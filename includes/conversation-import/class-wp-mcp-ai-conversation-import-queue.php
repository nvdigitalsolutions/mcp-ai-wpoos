<?php
/**
 * Async job queue bridge for conversation imports.
 *
 * Enqueues import runs onto {@see WP_MCP_AI_Async_Job_Queue} so large
 * archives never block a web request, and executes queued import jobs from
 * the queue worker with progress reporting.
 *
 * @package WP_MCP_AI
 * @since   1.1.60
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Bridges the import manager and the async job queue.
 */
class WP_MCP_AI_Conversation_Import_Queue {

	const JOB_TYPE = 'conversation_import';

	/**
	 * Enqueue an import run as a background job.
	 *
	 * @param array $args {
	 *     Import arguments (same shape as {@see WP_MCP_AI_Conversation_Import_Manager::run()},
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
		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_queue_missing',
				__( 'The async job queue is unavailable.', 'mcp-ai-wpoos' )
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
			return new WP_Error(
				'wp_mcp_ai_import_missing_source',
				__( 'Provide an import source to queue.', 'mcp-ai-wpoos' )
			);
		}

		return WP_MCP_AI_Async_Job_Queue::queue_job(
			array(
				'job_type'    => self::JOB_TYPE,
				'job_data'    => $job_data,
				'priority'    => WP_MCP_AI_Async_Job_Queue::PRIORITY_LOW,
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
	 * @throws Exception When the import cannot start (queue retries the job).
	 */
	public static function execute( array $job_data, $job_id = 0 ) {
		if ( ! class_exists( 'WP_MCP_AI_Conversation_Import_Manager' ) ) {
			throw new Exception( __( 'Conversation import manager is unavailable.', 'mcp-ai-wpoos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		if ( ! function_exists( 'jet_engine' ) && ! class_exists( 'Jet_Engine' ) ) {
			throw new Exception( __( 'JetEngine is not active; conversation import cannot run.', 'mcp-ai-wpoos' ) ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
		}

		$manager = new WP_MCP_AI_Conversation_Import_Manager();

		$estimate = isset( $job_data['estimate'] ) ? absint( $job_data['estimate'] ) : 0;

		if ( $job_id > 0 ) {
			$manager->set_progress_callback(
				function ( $progress ) use ( $job_id, $estimate ) {
					$percent = 0;
					if ( $estimate > 0 ) {
						$percent = min( 99, (int) floor( $progress['processed'] / $estimate * 100 ) );
					}
					WP_MCP_AI_Async_Job_Queue::update_job(
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
			throw new Exception( $report->get_error_message() ); // phpcs:ignore WordPress.Security.EscapeOutput.ExceptionNotEscaped -- Exception thrown, not echoed to output.
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
		if ( ! class_exists( 'WP_MCP_AI_Async_Job_Queue' ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_queue_missing',
				__( 'The async job queue is unavailable.', 'mcp-ai-wpoos' )
			);
		}

		$job = WP_MCP_AI_Async_Job_Queue::get_job( absint( $job_id ) );
		if ( empty( $job ) ) {
			return new WP_Error(
				'wp_mcp_ai_import_job_not_found',
				__( 'The import job was not found.', 'mcp-ai-wpoos' )
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
