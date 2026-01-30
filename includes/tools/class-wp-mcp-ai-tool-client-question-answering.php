<?php
/**
 * Tool that answers questions using browser-native Transformers.js
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
 * Browser-native question answering tool
 *
 * Uses Transformers.js to extract answers from context documents.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Client_Question_Answering implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'client_question_answering';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Browser Question Answering', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Extract answers to questions from provided context using browser-native AI. Processes instantly without server round-trip.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'question' => array(
					'type'        => 'string',
					'description' => __( 'The question to answer.', 'mcp-ai-wpoos' ),
				),
				'context'  => array(
					'type'        => 'string',
					'description' => __( 'The context document containing the answer to the question.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'question', 'context' ),
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

			'pattern_compatibility' => array( 'orchestrator' ),

			'profession_tags'       => array( 'researcher', 'analyst' ),

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
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		if ( ! class_exists( 'WP_MCP_AI_Transformers_Enqueue' ) ||
			! WP_MCP_AI_Transformers_Enqueue::is_transformers_enabled() ) {
			return array(
				'success' => false,
				'error'   => __( 'Browser-native AI tasks are not enabled.', 'mcp-ai-wpoos' ),
			);
		}

		$question = isset( $arguments['question'] ) ? $arguments['question'] : '';
		$ctx      = isset( $arguments['context'] ) ? $arguments['context'] : '';

		if ( empty( $question ) || empty( $ctx ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Both question and context parameters are required.', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'questionAnswering',
			'client_arguments'  => array(
				'question' => $question,
				'context'  => $ctx,
			),
			'message'           => __( 'Finding answer in browser...', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function should_exclude_from_client() {
		return true;
	}
}
