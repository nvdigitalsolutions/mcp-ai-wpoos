<?php
/**
 * Tool for retrieving OpenAI batch processing job status.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

/**
 * Retrieves the status and details of a batch processing job.
 *
 * Use this tool to monitor batch job progress, check completion status,
 * and retrieve output file IDs when jobs are complete.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Get_Batch_Status implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_batch_status';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Batch Status', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves the status and details of a batch processing job. Use to monitor progress, check completion, get output file IDs, or troubleshoot failed jobs.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'batch_id' => array(
					'type'        => 'string',
					'description' => __( 'The ID of the batch job to retrieve.', 'wp-mcp-ai' ),
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
			'read-only',            // Only reads data, doesn't modify.
			'requires-credentials', // Requires OpenAI API key.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(); // Batch status retrieval doesn't require specific model capabilities.
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
				__( 'You do not have permission to retrieve batch job status.', 'wp-mcp-ai' ),
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

		// Call the OpenAI client.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->retrieve_batch( sanitize_text_field( $arguments['batch_id'] ) );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Format the response.
		$response = array(
			'success'         => true,
			'batch_id'        => isset( $result['id'] ) ? $result['id'] : '',
			'status'          => isset( $result['status'] ) ? $result['status'] : '',
			'endpoint'        => isset( $result['endpoint'] ) ? $result['endpoint'] : '',
			'created_at'      => isset( $result['created_at'] ) ? gmdate( 'Y-m-d H:i:s', $result['created_at'] ) : '',
			'completed_at'    => isset( $result['completed_at'] ) && null !== $result['completed_at'] ? gmdate( 'Y-m-d H:i:s', $result['completed_at'] ) : null,
			'failed_at'       => isset( $result['failed_at'] ) && null !== $result['failed_at'] ? gmdate( 'Y-m-d H:i:s', $result['failed_at'] ) : null,
			'expired_at'      => isset( $result['expired_at'] ) && null !== $result['expired_at'] ? gmdate( 'Y-m-d H:i:s', $result['expired_at'] ) : null,
			'output_file_id'  => isset( $result['output_file_id'] ) ? $result['output_file_id'] : null,
			'error_file_id'   => isset( $result['error_file_id'] ) ? $result['error_file_id'] : null,
			'request_counts'  => isset( $result['request_counts'] ) ? $result['request_counts'] : array(),
			'metadata'        => isset( $result['metadata'] ) ? $result['metadata'] : array(),
			'summary'         => $this->generate_summary( $result ),
			'raw_result'      => $result,
		);

		return $response;
	}

	/**
	 * Generate a human-readable summary of the batch job status.
	 *
	 * @param array $result Batch status result from OpenAI.
	 * @return string Summary message.
	 */
	protected function generate_summary( array $result ) {
		$batch_id = isset( $result['id'] ) ? $result['id'] : '';
		$status   = isset( $result['status'] ) ? $result['status'] : '';

		$summary = sprintf(
			/* translators: 1: batch ID, 2: status */
			__( 'Batch Job ID: %1$s, Status: %2$s', 'wp-mcp-ai' ),
			$batch_id,
			ucfirst( $status )
		);

		// Add status-specific details.
		switch ( $status ) {
			case 'validating':
				$summary .= "\n" . __( 'The batch is currently being validated. This usually takes a few moments.', 'wp-mcp-ai' );
				break;

			case 'in_progress':
				$summary .= "\n" . __( 'The batch is being processed. Check back later for completion.', 'wp-mcp-ai' );
				if ( isset( $result['request_counts'] ) && is_array( $result['request_counts'] ) ) {
					$total     = isset( $result['request_counts']['total'] ) ? $result['request_counts']['total'] : 0;
					$completed = isset( $result['request_counts']['completed'] ) ? $result['request_counts']['completed'] : 0;
					if ( $total > 0 ) {
						$progress   = round( ( $completed / $total ) * 100 );
						$summary   .= "\n" . sprintf(
							/* translators: 1: completed count, 2: total count, 3: percentage */
							__( 'Progress: %1$d of %2$d requests completed (%3$d%%)', 'wp-mcp-ai' ),
							$completed,
							$total,
							$progress
						);
					}
				}
				break;

			case 'completed':
				$summary .= "\n" . __( 'The batch has been completed successfully!', 'wp-mcp-ai' );
				if ( isset( $result['output_file_id'] ) && $result['output_file_id'] ) {
					$summary .= "\n" . sprintf(
						/* translators: %s: output file ID */
						__( 'Output File ID: %s', 'wp-mcp-ai' ),
						$result['output_file_id']
					);
					$summary .= "\n" . __( 'Download the output file to retrieve your results.', 'wp-mcp-ai' );
				}
				if ( isset( $result['request_counts'] ) && is_array( $result['request_counts'] ) ) {
					$total  = isset( $result['request_counts']['total'] ) ? $result['request_counts']['total'] : 0;
					$failed = isset( $result['request_counts']['failed'] ) ? $result['request_counts']['failed'] : 0;
					$summary .= "\n" . sprintf(
						/* translators: 1: completed count, 2: failed count */
						__( 'Successfully completed: %1$d requests, Failed: %2$d requests', 'wp-mcp-ai' ),
						$total - $failed,
						$failed
					);
				}
				break;

			case 'failed':
				$summary .= "\n" . __( 'The batch has failed. Check the error file for details.', 'wp-mcp-ai' );
				if ( isset( $result['error_file_id'] ) && $result['error_file_id'] ) {
					$summary .= "\n" . sprintf(
						/* translators: %s: error file ID */
						__( 'Error File ID: %s', 'wp-mcp-ai' ),
						$result['error_file_id']
					);
				}
				break;

			case 'expired':
				$summary .= "\n" . __( 'The batch has expired before completion. You may need to recreate it.', 'wp-mcp-ai' );
				break;

			case 'cancelled':
			case 'cancelling':
				$summary .= "\n" . __( 'The batch has been cancelled or is being cancelled.', 'wp-mcp-ai' );
				break;

			default:
				$summary .= "\n" . __( 'Status is unknown. Check raw result for details.', 'wp-mcp-ai' );
				break;
		}

		return $summary;
	}
}
