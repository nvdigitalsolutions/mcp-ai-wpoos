<?php
/**
 * Tool that creates OpenAI vector stores for knowledge retrieval.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Creates OpenAI vector stores.
 */
class WP_MCP_AI_Tool_Create_Vector_Store implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_vector_store';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Vector Store', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new OpenAI vector store for knowledge retrieval and semantic search. Vector stores can contain multiple files for RAG (Retrieval-Augmented Generation).', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'name'          => array(
					'type'        => 'string',
					'description' => __( 'Name of the vector store.', 'mcp-ai-wpoos' ),
				),
				'file_ids'      => array(
					'type'        => 'array',
					'description' => __( 'Optional: Array of OpenAI file IDs to add to the vector store.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
				),
				'expires_after' => array(
					'type'        => 'object',
					'description' => __( 'Optional: Auto-expiration configuration.', 'mcp-ai-wpoos' ),
					'properties'  => array(
						'anchor' => array(
							'type' => 'string',
							'enum' => array( 'last_active_at' ),
						),
						'days'   => array(
							'type'    => 'integer',
							'minimum' => 1,
							'maximum' => 365,
						),
					),
				),
				'metadata'      => array(
					'type'        => 'object',
					'description' => __( 'Optional: Custom metadata as key-value pairs (max 16 pairs).', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array( 'name' ),
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate name.
		if ( empty( $arguments['name'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The name parameter is required.', 'mcp-ai-wpoos' ),
			);
		}

		$name = sanitize_text_field( $arguments['name'] );

		// Prepare options.
		$options = array();

		if ( ! empty( $arguments['file_ids'] ) && is_array( $arguments['file_ids'] ) ) {
			$options['file_ids'] = array_map( 'sanitize_text_field', $arguments['file_ids'] );
		}

		if ( ! empty( $arguments['expires_after'] ) && is_array( $arguments['expires_after'] ) ) {
			$expires_after = array();
			if ( isset( $arguments['expires_after']['anchor'] ) ) {
				$expires_after['anchor'] = sanitize_key( $arguments['expires_after']['anchor'] );
			}
			if ( isset( $arguments['expires_after']['days'] ) ) {
				$expires_after['days'] = absint( $arguments['expires_after']['days'] );
			}
			if ( ! empty( $expires_after ) ) {
				$options['expires_after'] = $expires_after;
			}
		}

		if ( ! empty( $arguments['metadata'] ) && is_array( $arguments['metadata'] ) ) {
			// Limit to 16 key-value pairs.
			$metadata            = array_slice( $arguments['metadata'], 0, 16 );
			$options['metadata'] = $metadata;
		}

		// Call OpenAI API.
		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->create_vector_store( $name, $options );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		$vector_store_id   = isset( $result['id'] ) ? $result['id'] : null;
		$vector_store_name = isset( $result['name'] ) ? $result['name'] : $name;
		$message           = sprintf(
			/* translators: 1: vector store name, 2: vector store ID */
			__( 'Successfully created vector store "%1$s" (ID: %2$s)', 'mcp-ai-wpoos' ),
			$vector_store_name,
			$vector_store_id
		);

		return array(
			'success' => true,
			'message' => $message,
			'text'    => $message,
			'data'    => array(
				'id'            => $vector_store_id,
				'name'          => $vector_store_name,
				'status'        => isset( $result['status'] ) ? $result['status'] : null,
				'file_counts'   => isset( $result['file_counts'] ) ? $result['file_counts'] : array(),
				'created_at'    => isset( $result['created_at'] ) ? $result['created_at'] : null,
				'expires_after' => isset( $result['expires_after'] ) ? $result['expires_after'] : null,
				'metadata'      => isset( $result['metadata'] ) ? $result['metadata'] : array(),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
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

			'toolkit'               => 'data_analytics',

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),

			'risk_level'            => 'standard',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',
			'requires-capability',
			'modifies-state',
		);
	}
}
