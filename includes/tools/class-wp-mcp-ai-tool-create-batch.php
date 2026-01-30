<?php
/**
 * Tool for creating OpenAI batch processing jobs.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';

/**
 * Creates batch processing jobs for asynchronous operations with 50% cost reduction.
 *
 * The Batch API allows processing large jobs asynchronously with significant cost savings
 * and higher rate limits. Supports chat completions, embeddings, and moderations.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Create_Batch implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_batch';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Batch Job', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a batch processing job for asynchronous operations with 50% cost reduction. Use for bulk content generation, embeddings creation, or mass content moderation. Supports chat completions, embeddings, and moderations endpoints.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'input_file_id'     => array(
					'type'        => 'string',
					'description' => __( 'The ID of the uploaded input file containing batch requests in JSONL format. Each line must be a valid JSON object with custom_id, method, url, and body fields.', 'mcp-ai-wpoos' ),
				),
				'endpoint'          => array(
					'type'        => 'string',
					'enum'        => array( '/v1/chat/completions', '/v1/embeddings', '/v1/moderations' ),
					'description' => __( 'The OpenAI API endpoint to use for the batch.', 'mcp-ai-wpoos' ),
				),
				'completion_window' => array(
					'type'        => 'string',
					'enum'        => array( '24h' ),
					'description' => __( 'Time frame for batch completion. Currently only "24h" is supported.', 'mcp-ai-wpoos' ),
					'default'     => '24h',
				),
				'metadata'          => array(
					'type'        => 'object',
					'description' => __( 'Custom metadata as key-value pairs (up to 16 pairs).', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'input_file_id', 'endpoint' ),
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

			'profession_tags'       => array( 'machine_learning_engineer', 'data_scientist' ),

			'risk_level'            => 'standard',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',         // Makes external API calls.
			'consumes-tokens',      // Uses AI credits (at 50% reduced cost).
			'async',                // Long-running asynchronous operation.
			'deferred-result',      // Result available later, not immediately.
			'requires-polling',     // Need to poll for completion status.
			'long-running',         // May take up to 24 hours.
			'requires-credentials', // Requires OpenAI API key.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_model_requirements() {
		return array(); // Batch management doesn't require specific model capabilities.
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
				__( 'You do not have permission to create batch jobs.', 'mcp-ai-wpoos' ),
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

		// Validate required parameters.
		if ( ! isset( $arguments['input_file_id'] ) || '' === trim( $arguments['input_file_id'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input_file_id',
				__( 'Input file ID is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		if ( ! isset( $arguments['endpoint'] ) || '' === trim( $arguments['endpoint'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_endpoint',
				__( 'Endpoint is required.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		// Prepare options for the client.
		$options = array();

		if ( isset( $arguments['completion_window'] ) && '' !== $arguments['completion_window'] ) {
			$options['completion_window'] = sanitize_text_field( $arguments['completion_window'] );
		}

		if ( isset( $arguments['metadata'] ) && is_array( $arguments['metadata'] ) && ! empty( $arguments['metadata'] ) ) {
			$options['metadata'] = $arguments['metadata'];
		}

		// Call the OpenAI client.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_batch(
			sanitize_text_field( $arguments['input_file_id'] ),
			sanitize_text_field( $arguments['endpoint'] ),
			$options
		);

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Format the response.
		$summary_text = $this->generate_summary( $result );

		$response = array(
			'success'    => true,
			'batch_id'   => isset( $result['id'] ) ? $result['id'] : '',
			'status'     => isset( $result['status'] ) ? $result['status'] : '',
			'endpoint'   => isset( $result['endpoint'] ) ? $result['endpoint'] : '',
			'created_at' => isset( $result['created_at'] ) ? gmdate( 'Y-m-d H:i:s', $result['created_at'] ) : '',
			'message'    => $summary_text, // Chat client display
			'summary'    => $summary_text, // Backward compatibility
			'raw_result' => $result,
		);

		return $response;
	}

	/**
	 * Generate a human-readable summary of the batch job creation.
	 *
	 * @param array $result Batch creation result from OpenAI.
	 * @return string Summary message.
	 */
	protected function generate_summary( array $result ) {
		$batch_id = isset( $result['id'] ) ? $result['id'] : '';
		$status   = isset( $result['status'] ) ? $result['status'] : '';
		$endpoint = isset( $result['endpoint'] ) ? $result['endpoint'] : '';

		$summary = sprintf(
			/* translators: 1: batch ID, 2: status, 3: endpoint */
			__( 'Batch job created successfully. ID: %1$s, Status: %2$s, Endpoint: %3$s', 'mcp-ai-wpoos' ),
			$batch_id,
			$status,
			$endpoint
		);

		$summary .= "\n\n";
		$summary .= __( 'The batch job is now processing. Use the get_batch_status tool to check progress. Results will be available within 24 hours.', 'mcp-ai-wpoos' );

		$summary .= "\n\n";
		$summary .= __( 'Cost Savings: Batch API provides 50% cost reduction compared to synchronous API calls.', 'mcp-ai-wpoos' );

		return $summary;
	}
}
