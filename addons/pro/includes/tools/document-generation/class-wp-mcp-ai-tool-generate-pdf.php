<?php
/**
 * Generate PDF Tool - Simplified PDF generation.
 *
 * Simplified interface for PDF generation that delegates to the Pro PDF tool.
 * Provides a simpler API for common PDF generation use cases.
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

// Load the Pro PDF tool.
require_once __DIR__ . '/class-wp-mcp-ai-tool-pro-pdf.php';

/**
 * Simplified PDF generation tool.
 *
 * Provides a simpler interface for PDF generation by delegating
 * to the more powerful Pro PDF tool with sensible defaults.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_PDF implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * Pro PDF tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Pro_PDF
	 */
	protected $pro_pdf_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pro_pdf_tool = new WP_MCP_AI_Tool_Pro_PDF();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_pdf';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate PDF', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate PDF documents from content. Simplified interface for creating PDFs with basic formatting and structure. For advanced features, use Pro PDF Document tool.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Content to include in the PDF document.', 'mcp-ai-wpoos-pro' ),
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
		// Delegate to Pro PDF tool with simplified parameters.
		$pro_arguments = array(
			'operation' => 'generate',
			'content'   => $arguments['content'] ?? '',
			'title'     => $arguments['title'] ?? 'Document',
		);

		return $this->pro_pdf_tool->execute( $pro_arguments, $context );
	}
}
