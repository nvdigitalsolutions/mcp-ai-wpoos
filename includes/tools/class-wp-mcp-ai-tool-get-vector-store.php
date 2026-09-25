<?php
/**
 * Tool that retrieves details about an OpenAI vector store.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-openai-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Retrieves OpenAI vector store details.
 */
class WP_MCP_AI_Tool_Get_Vector_Store implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface, WP_MCP_AI_Tool_Data_Contract_Interface {

	use WP_MCP_AI_Tool_Chat_Response;

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
		return __( 'Get Vector Store', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves detailed information about a specific OpenAI vector store including file counts, status, and metadata.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Inspecting one OpenAI vector store: file counts, status, metadata, or expiration.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Listing stores or managing files; use list_vector_stores or manage_vector_store_files.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'list_vector_stores', 'manage_vector_store_files', 'create_vector_store' ),
			'notes'           => __( 'Omit vector_store_id to use the store configured on the calling assistant.', 'mcp-ai-wpoos' ),
		);
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
					'description' => __( 'The ID of the vector store to retrieve. When omitted, the assistant\'s configured vector store is used.', 'mcp-ai-wpoos' ),
				),
			),
			'required'   => array(),
		);
	}

		/**
		 * {@inheritdoc}
		 */
	public function get_data_contract() {
		return array(
			'produces' => null,
			'consumes' => array( 'vector_store_id' ),
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
		// Resolve vector store ID: explicit argument > assistant context configuration.
		$vector_store_id = '';
		if ( ! empty( $arguments['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $arguments['vector_store_id'] );
		} elseif ( ! empty( $context['assistant_config']['vector_store_id'] ) ) {
			$vector_store_id = sanitize_text_field( $context['assistant_config']['vector_store_id'] );
		}

		if ( empty( $vector_store_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_error',
				__( 'No vector store ID provided and none configured for this assistant.', 'mcp-ai-wpoos' )
			);
		}

		$client = new WP_MCP_AI_OpenAI_Client();
		$result = $client->retrieve_vector_store( $vector_store_id );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		$vector_store_name = isset( $result['name'] ) ? $result['name'] : null;
		$vector_store_id   = isset( $result['id'] ) ? $result['id'] : null;
		$status            = isset( $result['status'] ) ? $result['status'] : null;

		$message = sprintf(
			/* translators: 1: vector store name, 2: vector store ID, 3: status */
			__( 'Successfully retrieved vector store "%1$s" (ID: %2$s, Status: %3$s)', 'mcp-ai-wpoos' ),
			$vector_store_name ? $vector_store_name : __( 'Unknown', 'mcp-ai-wpoos' ),
			$vector_store_id,
			$status ? $status : __( 'unknown', 'mcp-ai-wpoos' )
		);

		return array(
			'success' => true,
			'message' => $message,
			'text'    => $message,
			'data'    => array(
				'id'             => $vector_store_id,
				'name'           => $vector_store_name,
				'status'         => $status,
				'file_counts'    => isset( $result['file_counts'] ) ? $result['file_counts'] : array(),
				'created_at'     => isset( $result['created_at'] ) ? $result['created_at'] : null,
				'last_active_at' => isset( $result['last_active_at'] ) ? $result['last_active_at'] : null,
				'expires_after'  => isset( $result['expires_after'] ) ? $result['expires_after'] : null,
				'expires_at'     => isset( $result['expires_at'] ) ? $result['expires_at'] : null,
				'metadata'       => isset( $result['metadata'] ) ? $result['metadata'] : array(),
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

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'data_scientist', 'machine_learning_engineer' ),

			'risk_level'            => 'info',

		);
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
