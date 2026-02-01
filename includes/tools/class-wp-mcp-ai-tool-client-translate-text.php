<?php
/**
 * Tool that translates text using browser-native Transformers.js
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
 * Browser-native translation tool
 *
 * Uses Transformers.js to translate between 200+ languages.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Client_Translate_Text implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Restrict_From_Chat_Client;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'client_translate_text';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Browser Translate Text', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Translate text between 200+ languages using browser-native AI. Processes instantly without server round-trip. Supports major world languages.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'text'        => array(
					'type'        => 'string',
					'description' => __( 'The text to translate.', 'mcp-ai-wpoos' ),
				),
				'source_lang' => array(
					'type'        => 'string',
					'description' => __( 'Source language code (e.g., "eng_Latn" for English). Default is English.', 'mcp-ai-wpoos' ),
					'default'     => 'eng_Latn',
				),
				'target_lang' => array(
					'type'        => 'string',
					'description' => __( 'Target language code (e.g., "fra_Latn" for French). Default is French.', 'mcp-ai-wpoos' ),
					'default'     => 'fra_Latn',
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

			'toolkit'               => 'communication_outreach',

			'pattern_compatibility' => array( 'sequential' ),

			'profession_tags'       => array( 'translator', 'content_creator' ),

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

		$text        = isset( $arguments['text'] ) ? $arguments['text'] : '';
		$source_lang = isset( $arguments['source_lang'] ) ? sanitize_text_field( $arguments['source_lang'] ) : 'eng_Latn';
		$target_lang = isset( $arguments['target_lang'] ) ? sanitize_text_field( $arguments['target_lang'] ) : 'fra_Latn';

		if ( empty( $text ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Text parameter is required.', 'mcp-ai-wpoos' ),
			);
		}

		return array(
			'success'           => true,
			'client_executable' => true,
			'client_method'     => 'translate',
			'client_arguments'  => array(
				'text'    => $text,
				'options' => array(
					'sourceLang' => $source_lang,
					'targetLang' => $target_lang,
				),
			),
			'message'           => __( 'Translating text in browser...', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function should_exclude_from_client() {
		return true;
	}
}
