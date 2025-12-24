<?php
/**
 * Tool that generates vector embeddings for text using OpenAI's Embeddings API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generates vector embeddings for text using OpenAI's embedding models.
 */
class WP_MCP_AI_Tool_Create_Text_Embeddings implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_text_embeddings';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Text Embeddings', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates vector embeddings for text using OpenAI\'s embedding models. Use this for semantic search preparation, content similarity comparison, text classification, recommendation systems, or vector database population.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'input'            => array(
					'description' => __( 'Text or array of texts to embed. Maximum 8191 tokens for text-embedding-3-small/large.', 'wp-mcp-ai' ),
					'oneOf'       => array(
						array(
							'type' => 'string',
						),
						array(
							'type'  => 'array',
							'items' => array(
								'type' => 'string',
							),
						),
					),
				),
				'model'            => array(
					'type'        => 'string',
					'description' => __( 'Embedding model to use.', 'wp-mcp-ai' ),
					'enum'        => array( 'text-embedding-3-small', 'text-embedding-3-large', 'text-embedding-ada-002' ),
					'default'     => 'text-embedding-3-small',
				),
				'encoding_format'  => array(
					'type'        => 'string',
					'enum'        => array( 'float', 'base64' ),
					'description' => __( 'Encoding format for embeddings.', 'wp-mcp-ai' ),
					'default'     => 'float',
				),
				'dimensions'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of dimensions for text-embedding-3-* models. Smaller dimensions are faster and cheaper.', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
				'store_in_meta'    => array(
					'type'        => 'boolean',
					'description' => __( 'Save embeddings to post metadata for later retrieval.', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'post_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to attach embeddings to (required if store_in_meta is true).', 'wp-mcp-ai' ),
					'minimum'     => 1,
				),
			),
			'required'             => array( 'input' ),
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
		if ( ! $user_id || ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to create text embeddings.', 'wp-mcp-ai' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		// Validate input.
		if ( ! isset( $arguments['input'] ) || empty( $arguments['input'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Input text is required.', 'wp-mcp-ai' )
			);
		}

		$input = $arguments['input'];

		// Validate store_in_meta and post_id.
		$store_in_meta = isset( $arguments['store_in_meta'] ) ? (bool) $arguments['store_in_meta'] : false;
		$post_id       = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;

		if ( $store_in_meta && ! $post_id ) {
			return new WP_Error(
				'wp_mcp_ai_missing_post_id',
				__( 'Post ID is required when store_in_meta is true.', 'wp-mcp-ai' )
			);
		}

		if ( $store_in_meta && $post_id ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_post_id',
					__( 'The specified post does not exist.', 'wp-mcp-ai' )
				);
			}

			// Check if user can edit this post.
			if ( ! user_can( $user_id, 'edit_post', $post_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_cannot_edit_post',
					__( 'You do not have permission to edit this post.', 'wp-mcp-ai' )
				);
			}
		}

		// Build options for the OpenAI client.
		$options = array();

		if ( isset( $arguments['model'] ) && '' !== $arguments['model'] ) {
			$options['model'] = sanitize_text_field( $arguments['model'] );
		}

		if ( isset( $arguments['encoding_format'] ) && '' !== $arguments['encoding_format'] ) {
			$options['encoding_format'] = sanitize_text_field( $arguments['encoding_format'] );
		}

		if ( isset( $arguments['dimensions'] ) && '' !== $arguments['dimensions'] ) {
			$options['dimensions'] = absint( $arguments['dimensions'] );
		}

		// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_embeddings( $input, $options );

		// Handle errors.
		if ( is_wp_error( $result ) ) {
			return new WP_Error(
				$result->get_error_code(),
				$result->get_error_message(),
				$result->get_error_data()
			);
		}

		// Store embeddings in post meta if requested.
		if ( $store_in_meta && $post_id ) {
			$embeddings_data = array(
				'embeddings' => isset( $result['data'] ) ? $result['data'] : array(),
				'model'      => isset( $result['model'] ) ? $result['model'] : '',
				'created_at' => gmdate( 'Y-m-d H:i:s' ),
			);

			update_post_meta( $post_id, '_wp_mcp_ai_embeddings', $embeddings_data );
		}

		// Format the response.
		$embeddings       = isset( $result['data'] ) ? $result['data'] : array();
		$model            = isset( $result['model'] ) ? $result['model'] : '';
		$usage            = isset( $result['usage'] ) ? $result['usage'] : array();
		$prompt_tokens    = isset( $usage['prompt_tokens'] ) ? $usage['prompt_tokens'] : 0;
		$total_tokens     = isset( $usage['total_tokens'] ) ? $usage['total_tokens'] : 0;

		return array(
			'success'     => true,
			'embeddings'  => $embeddings,
			'model'       => $model,
			'usage'       => array(
				'prompt_tokens' => $prompt_tokens,
				'total_tokens'  => $total_tokens,
			),
			'stored'      => $store_in_meta && $post_id,
			'post_id'     => $post_id,
			'summary'     => sprintf(
				/* translators: 1: number of embeddings, 2: model name */
				__( 'Created %1$d embeddings using model %2$s.', 'wp-mcp-ai' ),
				count( $embeddings ),
				$model
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',         // Makes external API calls to OpenAI.
			'requires-capability',  // Requires 'edit_posts' capability.
			'modifies-state',       // Can modify post meta when store_in_meta is true.
		);
	}
}
