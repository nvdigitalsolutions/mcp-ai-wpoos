<?php
/**
 * Tool for monitoring OpenAI batch jobs with automatic status checking.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

/**
 * Monitors batch processing jobs and triggers actions on completion.
 *
 * This tool sets up WordPress cron monitoring for batch jobs, automatically
 * checking status and processing results when batches complete.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Monitor_Batch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	/**
	 * Option name for storing monitored batches.
	 */
	const MONITORED_BATCHES_OPTION = 'wp_mcp_ai_monitored_batches';

	/**
	 * Cron hook for batch monitoring.
	 */
	const CRON_HOOK = 'wp_mcp_ai_check_batch_status';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'monitor_batch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Monitor Batch Job', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Sets up automatic monitoring for a batch job with WordPress cron. Checks status periodically and triggers actions when completed, failed, or expired. Useful for long-running batches.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'batch_id'           => array(
					'type'        => 'string',
					'description' => __( 'The ID of the batch job to monitor.', 'wp-mcp-ai' ),
				),
				'check_interval'     => array(
					'type'        => 'string',
					'enum'        => array( 'hourly', 'twicedaily', 'daily' ),
					'description' => __( 'How often to check batch status.', 'wp-mcp-ai' ),
					'default'     => 'hourly',
				),
				'callback_hook'      => array(
					'type'        => 'string',
					'description' => __( 'Optional WordPress action hook to trigger when batch completes. Receives batch_id and result as parameters.', 'wp-mcp-ai' ),
				),
				'auto_download'      => array(
					'type'        => 'boolean',
					'description' => __( 'Automatically download results when batch completes.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'metadata'           => array(
					'type'        => 'object',
					'description' => __( 'Custom metadata to associate with this monitoring job.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'batch_id' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',         // Makes external API calls.
			'state-changing',       // Modifies WordPress cron state.
			'background-only',      // Runs in background via cron.
			'requires-credentials', // Requires OpenAI API key.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(); // Batch monitoring doesn't require specific model capabilities.
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions - requires manage_options capability.
		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to monitor batch jobs.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai' ),
				array( 'status' => 403 )
			);
		}

		// Validate required parameters.
		if ( ! isset( $arguments['batch_id'] ) || '' === trim( $arguments['batch_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_batch_id',
				__( 'Batch ID is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$batch_id = sanitize_text_field( $arguments['batch_id'] );

		// Verify the batch exists.
		$client = new WP_MCP_AI_OpenAI_Client();
		$batch  = $client->retrieve_batch( $batch_id );

		if ( is_wp_error( $batch ) ) {
			return $batch;
		}

		// Check if batch is already completed.
		if ( in_array( $batch['status'], array( 'completed', 'failed', 'expired', 'cancelled' ), true ) ) {
			return new WP_Error(
				'wp_mcp_ai_batch_already_finished',
				sprintf(
					/* translators: %s: batch status */
					__( 'Batch is already in final state: %s. No monitoring needed.', 'wp-mcp-ai' ),
					$batch['status']
				),
				array( 'status' => 400 )
			);
		}

		// Get monitoring configuration.
		$check_interval = isset( $arguments['check_interval'] ) ? sanitize_text_field( $arguments['check_interval'] ) : 'hourly';
		if ( ! in_array( $check_interval, array( 'hourly', 'twicedaily', 'daily' ), true ) ) {
			$check_interval = 'hourly';
		}

		$callback_hook  = isset( $arguments['callback_hook'] ) ? sanitize_text_field( $arguments['callback_hook'] ) : '';
		$auto_download  = isset( $arguments['auto_download'] ) ? (bool) $arguments['auto_download'] : false;
		$metadata       = isset( $arguments['metadata'] ) && is_array( $arguments['metadata'] ) ? $arguments['metadata'] : array();

		// Add user info to metadata.
		$metadata['user_id']    = $user_id;
		$metadata['created_at'] = time();

		// Store monitoring configuration.
		$monitored_batches = get_option( self::MONITORED_BATCHES_OPTION, array() );
		$monitored_batches[ $batch_id ] = array(
			'batch_id'        => $batch_id,
			'check_interval'  => $check_interval,
			'callback_hook'   => $callback_hook,
			'auto_download'   => $auto_download,
			'metadata'        => $metadata,
			'added_at'        => time(),
		);
		update_option( self::MONITORED_BATCHES_OPTION, $monitored_batches );

		// Schedule cron if not already scheduled.
		if ( ! wp_next_scheduled( self::CRON_HOOK ) ) {
			wp_schedule_event( time(), $check_interval, self::CRON_HOOK );
		}

		// Return success with monitoring details.
		return array(
			'success'         => true,
			'batch_id'        => $batch_id,
			'monitoring'      => true,
			'check_interval'  => $check_interval,
			'current_status'  => $batch['status'],
			'callback_hook'   => $callback_hook ? $callback_hook : 'none',
			'auto_download'   => $auto_download,
			'summary'         => $this->generate_summary( $batch_id, $check_interval, $callback_hook ),
		);
	}

	/**
	 * Generate a human-readable summary of the monitoring setup.
	 *
	 * @param string $batch_id       Batch job ID.
	 * @param string $check_interval Check interval.
	 * @param string $callback_hook  Callback hook (if any).
	 * @return string Summary message.
	 */
	protected function generate_summary( $batch_id, $check_interval, $callback_hook ) {
		$summary = sprintf(
			/* translators: 1: batch ID, 2: check interval */
			__( 'Monitoring enabled for batch %1$s. Status will be checked %2$s.', 'wp-mcp-ai' ),
			$batch_id,
			$check_interval
		);

		if ( ! empty( $callback_hook ) ) {
			$summary .= "\n\n" . sprintf(
				/* translators: %s: callback hook name */
				__( 'When the batch completes, the action "%s" will be triggered.', 'wp-mcp-ai' ),
				$callback_hook
			);
		}

		$summary .= "\n\n" . __( 'You will be notified when the batch reaches a final state (completed, failed, expired, or cancelled).', 'wp-mcp-ai' );

		return $summary;
	}

	/**
	 * Check status of all monitored batches.
	 *
	 * This is called by WordPress cron.
	 *
	 * @return void
	 */
	public static function check_monitored_batches() {
		$monitored_batches = get_option( self::MONITORED_BATCHES_OPTION, array() );

		if ( empty( $monitored_batches ) ) {
			// No batches to monitor, unschedule the cron.
			wp_clear_scheduled_hook( self::CRON_HOOK );
			return;
		}

		$client = new WP_MCP_AI_OpenAI_Client();

		foreach ( $monitored_batches as $batch_id => $config ) {
			$result = $client->retrieve_batch( $batch_id );

			if ( is_wp_error( $result ) ) {
				// Log error but continue monitoring.
				WP_MCP_AI_Logger::log_error(
					'Batch monitoring error.',
					array(
						'batch_id' => $batch_id,
						'error'    => $result->get_error_message(),
					)
				);
				continue;
			}

			$status = isset( $result['status'] ) ? $result['status'] : '';

			// Check if batch reached a final state.
			if ( in_array( $status, array( 'completed', 'failed', 'expired', 'cancelled' ), true ) ) {
				// Process completed batch.
				self::process_batch_completion( $batch_id, $result, $config );

				// Remove from monitored list.
				unset( $monitored_batches[ $batch_id ] );
			}
		}

		// Update monitored batches list.
		update_option( self::MONITORED_BATCHES_OPTION, $monitored_batches );

		// If no more batches to monitor, unschedule the cron.
		if ( empty( $monitored_batches ) ) {
			wp_clear_scheduled_hook( self::CRON_HOOK );
		}
	}

	/**
	 * Process batch completion.
	 *
	 * @param string $batch_id Batch job ID.
	 * @param array  $result   Batch result from OpenAI.
	 * @param array  $config   Monitoring configuration.
	 * @return void
	 */
	protected static function process_batch_completion( $batch_id, $result, $config ) {
		$status = $result['status'];

		// Log completion.
		WP_MCP_AI_Logger::log_event(
			'batch_monitoring_completed',
			"Batch monitoring completed for $batch_id",
			array(
				'batch_id' => $batch_id,
				'status'   => $status,
			)
		);

		// Download results if requested and status is completed.
		if ( 'completed' === $status && $config['auto_download'] && ! empty( $result['output_file_id'] ) ) {
			$client  = new WP_MCP_AI_OpenAI_Client();
			$content = $client->download_file( $result['output_file_id'] );

			if ( ! is_wp_error( $content ) ) {
				// Store downloaded content in transient for 24 hours.
				set_transient( 'wp_mcp_ai_batch_results_' . $batch_id, $content, DAY_IN_SECONDS );
			}
		}

		// Trigger callback hook if specified.
		if ( ! empty( $config['callback_hook'] ) ) {
			/**
			 * Fires when a monitored batch job completes.
			 *
			 * @param string $batch_id Batch job ID.
			 * @param array  $result   Complete batch result from OpenAI.
			 * @param array  $config   Monitoring configuration.
			 */
			do_action( $config['callback_hook'], $batch_id, $result, $config );
		}

		// Send admin notification if user exists.
		if ( ! empty( $config['metadata']['user_id'] ) ) {
			$user = get_userdata( $config['metadata']['user_id'] );
			if ( $user && ! empty( $user->user_email ) ) {
				self::send_completion_notification( $user->user_email, $batch_id, $status );
			}
		}
	}

	/**
	 * Send email notification about batch completion.
	 *
	 * @param string $email    User email address.
	 * @param string $batch_id Batch job ID.
	 * @param string $status   Final batch status.
	 * @return void
	 */
	protected static function send_completion_notification( $email, $batch_id, $status ) {
		$subject = sprintf(
			/* translators: 1: site name, 2: batch status */
			__( '[%1$s] Batch Job %2$s', 'wp-mcp-ai' ),
			get_bloginfo( 'name' ),
			ucfirst( $status )
		);

		$message = sprintf(
			/* translators: 1: batch ID, 2: status */
			__( 'Your batch job %1$s has reached final status: %2$s', 'wp-mcp-ai' ),
			$batch_id,
			$status
		) . "\n\n";

		if ( 'completed' === $status ) {
			$message .= __( 'The batch has been processed successfully. Use the get_batch_status tool to retrieve output file IDs and download results.', 'wp-mcp-ai' );
		} elseif ( 'failed' === $status ) {
			$message .= __( 'The batch processing failed. Check the error file for details.', 'wp-mcp-ai' );
		} elseif ( 'expired' === $status ) {
			$message .= __( 'The batch expired before completion. You may need to recreate it.', 'wp-mcp-ai' );
		}

		$message .= "\n\n" . sprintf(
			/* translators: %s: admin URL */
			__( 'View details: %s', 'wp-mcp-ai' ),
			admin_url( 'admin.php?page=wp-mcp-ai-settings' )
		);

		wp_mail( $email, $subject, $message );
	}
}

// Hook the monitoring function to WordPress cron.
add_action( WP_MCP_AI_Tool_Monitor_Batch::CRON_HOOK, array( 'WP_MCP_AI_Tool_Monitor_Batch', 'check_monitored_batches' ) );
