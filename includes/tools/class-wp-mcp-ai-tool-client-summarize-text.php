<?php
/**
 * Tool that summarizes text using browser-native Transformers.js
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
 * Browser-native text summarization tool
 *
 * Uses Transformers.js in the browser to generate summaries instantly
 * without server round-trip. Models are loaded from HuggingFace CDN
 * and cached locally for performance.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Client_Summarize_Text implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'client_summarize_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Browser Summarize Text', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a concise summary of the provided text using browser-native AI. Processes instantly in the browser without server round-trip. Best for summarizing articles, documents, or long content.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'       => array(
					'type'        => 'string',
					'description' => __( 'The text content to summarize. Should be at least 100 characters for meaningful summaries.', 'mcp-ai-wpoos' ),
				),
				'max_length' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum length of the summary in tokens. Default is 130.', 'mcp-ai-wpoos' ),
					'minimum'     => 30,
					'maximum'     => 200,
					'default'     => 130,
				),
				'min_length' => array(
					'type'        => 'integer',
					'description' => __( 'Minimum length of the summary in tokens. Default is 30.', 'mcp-ai-wpoos' ),
					'minimum'     => 10,
					'maximum'     => 100,
					'default'     => 30,
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

			'pattern_compatibility' => array( 'sequential', 'peer_to_peer' ),

			'profession_tags'       => array( 'researcher', 'analyst', 'writer' ),

			'risk_level'            => 'info',

		);

	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'external-api'    => false, // Runs locally in browser.
			'consumes-tokens' => false, // No API tokens consumed.
			'read-only'       => true,  // Does not modify data.
			'client-side'     => true,  // Executes in browser.
			'offline'         => true,  // Works without internet after model cached.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if Transformers.js is enabled.
		if ( ! class_exists( 'WP_MCP_AI_Transformers_Enqueue' ) ||
			! WP_MCP_AI_Transformers_Enqueue::is_transformers_enabled() ) {
			return array(
				'success' => false,
				'error'   => __( 'Browser-native AI tasks are not enabled. Please enable Transformers.js in settings.', 'mcp-ai-wpoos' ),
			);
		}

		$text       = isset( $arguments['text'] ) ? $arguments['text'] : '';
		$max_length = isset( $arguments['max_length'] ) ? absint( $arguments['max_length'] ) : 130;
		$min_length = isset( $arguments['min_length'] ) ? absint( $arguments['min_length'] ) : 30;

		if ( empty( $text ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Text parameter is required.', 'mcp-ai-wpoos' ),
			);
		}

		// Return instructions for client-side execution.
		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'summarize',
			'client_arguments'  => array(
				'text'    => $text,
				'options' => array(
					'maxLength' => $max_length,
					'minLength' => $min_length,
				),
			),
			'message'           => __( 'Generating summary in browser...', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function should_exclude_from_client() {
		return true;
	}
}
