<?php
/**
 * Generate Word Tool - Simplified Word document generation.
 *
 * Simplified interface for Word document generation that delegates to the Pro Word tool.
 * Provides a simpler API for common Word generation use cases.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load the chat and document response traits from base plugin.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-document-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

// Load the Pro Word tool.
require_once __DIR__ . '/class-wp-mcp-ai-tool-pro-word.php';

/**
 * Simplified Word document generation tool.
 *
 * Provides a simpler interface for Word generation by delegating
 * to the more powerful Pro Word tool with sensible defaults.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Word implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * Pro Word tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Pro_Word
	 */
	protected $pro_word_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pro_word_tool = new WP_MCP_AI_Tool_Pro_Word();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_word';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Word', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate Word documents from content. Simplified interface for creating .docx files with basic formatting. For advanced features, use Pro Word Document tool.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'content' => array(
					'type'        => 'string',
					'description' => __( 'Content to include in the Word document.', 'mcp-ai-wpoos-pro' ),
				),
				'title'   => array(
					'type'        => 'string',
					'description' => __( 'Document title.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'content' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'requires-capability',
			'requires-model',
			'consumes-tokens',
			'write',
			'state-changing',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Delegate to Pro Word tool with simplified parameters.
		$pro_arguments = array(
			'operation' => 'generate',
			'content'   => $arguments['content'] ?? '',
			'title'     => $arguments['title'] ?? 'Document',
		);

		return $this->pro_word_tool->execute( $pro_arguments, $context );
	}
}
