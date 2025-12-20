<?php
/**
 * Tool that retrieves details about an OpenAI vector store.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Retrieves OpenAI vector store details.
 */
class WP_MCP_AI_Tool_Get_Vector_Store implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_vector_store';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Vector Store', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific OpenAI vector store including file counts, status, and metadata.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'vector_store_id' => array(
					'type'        => 'string',
					'description' => __( 'The ID of the vector store to retrieve.', 'wp-mcp-ai' ),
				),
			),
			'required'   => array( 'vector_store_id' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( $arguments, $context ) {
		if ( empty( $arguments['vector_store_id'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'The vector_store_id parameter is required.', 'wp-mcp-ai' ),
			);
		}

		$vector_store_id = sanitize_text_field( $arguments['vector_store_id'] );

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->retrieve_vector_store( $vector_store_id );

		if ( is_wp_error( $result ) ) {
			return array(
				'success' => false,
				'error'   => $result->get_error_message(),
			);
		}

		return array(
			'success' => true,
			'data'    => array(
				'id'                => isset( $result['id'] ) ? $result['id'] : null,
				'name'              => isset( $result['name'] ) ? $result['name'] : null,
				'status'            => isset( $result['status'] ) ? $result['status'] : null,
				'file_counts'       => isset( $result['file_counts'] ) ? $result['file_counts'] : array(),
				'created_at'        => isset( $result['created_at'] ) ? $result['created_at'] : null,
				'last_active_at'    => isset( $result['last_active_at'] ) ? $result['last_active_at'] : null,
				'expires_after'     => isset( $result['expires_after'] ) ? $result['expires_after'] : null,
				'expires_at'        => isset( $result['expires_at'] ) ? $result['expires_at'] : null,
				'metadata'          => isset( $result['metadata'] ) ? $result['metadata'] : array(),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api',
			'requires-capability',
			'read-only',
			'cacheable',
		);
	}
}
