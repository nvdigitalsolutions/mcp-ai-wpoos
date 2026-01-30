<?php
/**
 * Tool for listing OpenAI batch processing jobs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

/**
 * Lists batch processing jobs with optional filtering and pagination.
 *
 * Use this tool to audit batch jobs, find jobs by status, or manage
 * your batch processing queue.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_List_Batches implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_batches';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Batch Jobs', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists batch processing jobs with optional filtering and pagination. Use to audit batch jobs, monitor overall processing status, or find specific jobs by criteria.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of batch jobs to return. Range: 1-100.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 20,
				),
				'after' => array(
					'type'        => 'string',
					'description' => __( 'Cursor for pagination. Use the last batch ID from previous results.', 'mcp-ai-wpoos' ),
				),
			),
			'additionalProperties' => false,
		);
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'ai_model_management',

			'pattern_compatibility' => array( 'experimentation' ),

			'profession_tags'       => array( 'machine_learning_engineer' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',         // Makes external API calls.
			'read-only',            // Only reads data, doesn't modify.
			'paginated',            // Supports pagination.
			'requires-credentials', // Requires OpenAI API key.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(); // Batch listing doesn't require specific model capabilities.
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
				__( 'You do not have permission to list batch jobs.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		// Build options for the client.
		$options = array();

		if ( isset( $arguments['limit'] ) && '' !== $arguments['limit'] ) {
			$limit            = absint( $arguments['limit'] );
			$options['limit'] = max( 1, min( 100, $limit ) );
		}

		if ( isset( $arguments['after'] ) && '' !== $arguments['after'] ) {
			$options['after'] = sanitize_text_field( $arguments['after'] );
		}

		// Call the OpenAI client.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->list_batches( $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Format the response.
		$batches = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $batch ) {
				$batches[] = array(
					'id'             => isset( $batch['id'] ) ? $batch['id'] : '',
					'status'         => isset( $batch['status'] ) ? $batch['status'] : '',
					'endpoint'       => isset( $batch['endpoint'] ) ? $batch['endpoint'] : '',
					'created_at'     => isset( $batch['created_at'] ) ? gmdate( 'Y-m-d H:i:s', $batch['created_at'] ) : '',
					'completed_at'   => isset( $batch['completed_at'] ) && null !== $batch['completed_at'] ? gmdate( 'Y-m-d H:i:s', $batch['completed_at'] ) : null,
					'output_file_id' => isset( $batch['output_file_id'] ) ? $batch['output_file_id'] : null,
					'error_file_id'  => isset( $batch['error_file_id'] ) ? $batch['error_file_id'] : null,
					'request_counts' => isset( $batch['request_counts'] ) ? $batch['request_counts'] : array(),
				);
			}
		}

		$has_more = isset( $result['has_more'] ) ? $result['has_more'] : false;
		$last_id  = isset( $result['last_id'] ) ? $result['last_id'] : null;

		$summary_text = $this->generate_summary( $batches, $has_more );

		$response = array(
			'success'     => true,
			'batches'     => $batches,
			'total_count' => count( $batches ),
			'has_more'    => $has_more,
			'last_id'     => $last_id,
			'message'     => $summary_text,
			'summary'     => $summary_text,
		);

		return $response;
	}

	/**
	 * Generate a human-readable summary of the batch jobs list.
	 *
	 * @param array $batches  List of batch jobs.
	 * @param bool  $has_more Whether there are more batches available.
	 * @return string Summary message.
	 */
	protected function generate_summary( array $batches, $has_more ) {
		$total = count( $batches );

		$summary = sprintf(
			/* translators: %d: number of batch jobs */
			_n( 'Found %d batch job.', 'Found %d batch jobs.', $total, 'mcp-ai-wpoos' ),
			$total
		);

		if ( $has_more ) {
			$summary .= ' ' . __( 'More batches are available. Use the "after" parameter with the last batch ID to retrieve the next page.', 'mcp-ai-wpoos' );
		}

		// Count batches by status.
		$status_counts = array();
		foreach ( $batches as $batch ) {
			$status = isset( $batch['status'] ) ? $batch['status'] : 'unknown';
			if ( ! isset( $status_counts[ $status ] ) ) {
				$status_counts[ $status ] = 0;
			}
			++$status_counts[ $status ];
		}

		if ( ! empty( $status_counts ) ) {
			$summary .= "\n\n" . __( 'Status breakdown:', 'mcp-ai-wpoos' );
			foreach ( $status_counts as $status => $count ) {
				$summary .= "\n" . sprintf(
					/* translators: 1: status, 2: count */
					__( '- %1$s: %2$d', 'mcp-ai-wpoos' ),
					ucfirst( $status ),
					$count
				);
			}
		}

		return $summary;
	}
}
