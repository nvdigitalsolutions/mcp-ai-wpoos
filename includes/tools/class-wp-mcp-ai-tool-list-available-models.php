<?php
/**
 * Tool that lists available OpenAI models.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lists all OpenAI models available to the configured API key.
 */
class WP_MCP_AI_Tool_List_Available_Models implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'list_available_models';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'List Available Models', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Lists all OpenAI models available to the configured API key. Use this to discover new models, check model availability, compare model capabilities, or perform dynamic model selection based on task requirements.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'filter_by_capability' => array(
					'type'        => 'string',
					'enum'        => array( 'chat', 'embeddings', 'images', 'audio', 'moderation' ),
					'description' => __( 'Filter models by capability type.', 'wp-mcp-ai' ),
				),
				'include_deprecated'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include deprecated models in the results.', 'wp-mcp-ai' ),
					'default'     => false,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Check permissions.
		if ( ! $user_id || ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to list available models.', 'wp-mcp-ai' )
			);
		}

		
		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}
// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->list_models();

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// Get filter parameters.
		$filter_capability   = isset( $arguments['filter_by_capability'] ) ? sanitize_text_field( $arguments['filter_by_capability'] ) : '';
		$include_deprecated  = isset( $arguments['include_deprecated'] ) ? (bool) $arguments['include_deprecated'] : false;

		// Process and filter models.
		$models = array();
		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $model ) {
				$model_id = isset( $model['id'] ) ? $model['id'] : '';

				// Skip deprecated models unless explicitly requested.
				if ( ! $include_deprecated && $this->is_deprecated_model( $model_id ) ) {
					continue;
				}

				// Filter by capability if specified.
				if ( '' !== $filter_capability ) {
					$capabilities = $this->get_model_capabilities( $model_id );
					if ( ! in_array( $filter_capability, $capabilities, true ) ) {
						continue;
					}
				}

				$models[] = array(
					'id'           => $model_id,
					'created'      => isset( $model['created'] ) ? $model['created'] : 0,
					'owned_by'     => isset( $model['owned_by'] ) ? $model['owned_by'] : '',
					'capabilities' => $this->get_model_capabilities( $model_id ),
				);
			}
		}

		return array(
			'success'     => true,
			'models'      => $models,
			'total_count' => count( $models ),
			'summary'     => sprintf(
				/* translators: %d: number of models */
				__( 'Found %d available OpenAI models.', 'wp-mcp-ai' ),
				count( $models )
			),
		);
	}

	/**
	 * Determine model capabilities based on model ID.
	 *
	 * @param string $model_id Model identifier.
	 * @return array Array of capability strings.
	 */
	private function get_model_capabilities( $model_id ) {
		$capabilities = array();

		// Chat models.
		if ( strpos( $model_id, 'gpt-' ) === 0 || strpos( $model_id, 'chatgpt' ) === 0 ) {
			$capabilities[] = 'chat';

			// Models with function calling.
			if ( strpos( $model_id, 'gpt-4' ) === 0 || strpos( $model_id, 'gpt-3.5-turbo' ) === 0 ) {
				$capabilities[] = 'function_calling';
			}

			// Models with vision.
			if ( strpos( $model_id, 'gpt-4o' ) === 0 || strpos( $model_id, 'gpt-4-turbo' ) === 0 || strpos( $model_id, 'gpt-4-vision' ) === 0 ) {
				$capabilities[] = 'vision';
			}
		}

		// Embedding models.
		if ( strpos( $model_id, 'text-embedding' ) === 0 || strpos( $model_id, 'embedding' ) !== false ) {
			$capabilities[] = 'embeddings';
		}

		// Image models.
		if ( strpos( $model_id, 'dall-e' ) === 0 || strpos( $model_id, 'gpt-image' ) === 0 ) {
			$capabilities[] = 'images';
		}

		// Audio models.
		if ( strpos( $model_id, 'whisper' ) === 0 || strpos( $model_id, 'tts' ) === 0 ) {
			$capabilities[] = 'audio';
		}

		// Moderation models.
		if ( strpos( $model_id, 'moderation' ) !== false ) {
			$capabilities[] = 'moderation';
		}

		return $capabilities;
	}

	/**
	 * Check if a model is deprecated.
	 *
	 * @param string $model_id Model identifier.
	 * @return bool True if model is deprecated.
	 */
	private function is_deprecated_model( $model_id ) {
		$deprecated_models = array(
			'gpt-3.5-turbo-0301',
			'gpt-3.5-turbo-0613',
			'gpt-4-0314',
			'gpt-4-0613',
			'text-davinci-003',
			'text-davinci-002',
			'text-curie-001',
			'text-babbage-001',
			'text-ada-001',
			'davinci',
			'curie',
			'babbage',
			'ada',
		);

		return in_array( $model_id, $deprecated_models, true );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'external-api',         // Makes external API calls to OpenAI.
			'requires-capability',  // Requires 'read' capability.
			'cacheable',            // Results can be cached.
		);
	}
}
