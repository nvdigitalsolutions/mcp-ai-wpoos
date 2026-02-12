<?php
/**
 * Tool that generates embeddings using browser-native Transformers.js
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-restrict-from-chat-client.php';

/**
 * Browser-native semantic search tool
 *
 * Uses Transformers.js to generate embeddings for vector search.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Client_Semantic_Search implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'client_semantic_search';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Browser Semantic Search', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate text embeddings for semantic search using browser-native AI. Processes instantly without server round-trip. Creates 384-dimensional vectors for similarity search.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text' => array(
					'description' => __( 'The text or array of texts to embed for semantic search.', 'mcp-ai-wpoos' ),
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
			),
			'required'             => array( 'text' ),
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

			'toolkit'               => 'research_discovery',

			'pattern_compatibility' => array( 'orchestrator', 'peer_to_peer' ),

			'profession_tags'       => array( 'researcher', 'data_scientist' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api'    => false,
			'consumes-tokens' => false,
			'read-only'       => true,
			'client-side'     => true,
			'offline'         => true,
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
		if ( ! class_exists( 'WP_MCP_AI_Transformers_Enqueue' ) ||
			! WP_MCP_AI_Transformers_Enqueue::is_transformers_enabled() ) {
			return array(
				'success' => false,
				'error'   => __( 'Browser-native AI tasks are not enabled.', 'mcp-ai-wpoos' ),
			);
		}

		$text = isset( $arguments['text'] ) ? $arguments['text'] : '';

		if ( empty( $text ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Text parameter is required.', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'embed',
			'client_arguments'  => array( 'text' => $text ),
			'message'           => __( 'Generating embeddings in browser...', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function should_exclude_from_client() {
		return true;
	}
}
