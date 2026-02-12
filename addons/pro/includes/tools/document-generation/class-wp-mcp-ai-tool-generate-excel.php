<?php
/**
 * Generate Excel Tool - Simplified Excel spreadsheet generation.
 *
 * Simplified interface for Excel generation that delegates to the Pro Excel tool.
 * Provides a simpler API for common Excel generation use cases.
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

// Load the Pro Excel tool.
require_once __DIR__ . '/class-wp-mcp-ai-tool-pro-excel-document.php';

/**
 * Simplified Excel spreadsheet generation tool.
 *
 * Provides a simpler interface for Excel generation by delegating
 * to the more powerful Pro Excel tool with sensible defaults.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Generate_Excel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Document_Response;

	/**
	 * Pro Excel tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Pro_Excel_Document
	 */
	protected $pro_excel_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->pro_excel_tool = new WP_MCP_AI_Tool_Pro_Excel_Document();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_excel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Excel', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate Excel spreadsheets from data. Simplified interface for creating .xlsx files with tables and data. For advanced features, use Pro Excel Document tool.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'data'  => array(
					'type'        => 'string',
					'description' => __( 'Data to include in the spreadsheet (as JSON string or CSV format).', 'mcp-ai-wpoos-pro' ),
				),
				'title' => array(
					'type'        => 'string',
					'description' => __( 'Spreadsheet title.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'data' ),
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
		// Delegate to Pro Excel tool with simplified parameters.
		$pro_arguments = array(
			'operation' => 'generate',
			'data'      => $arguments['data'] ?? '',
			'title'     => $arguments['title'] ?? 'Spreadsheet',
		);

		return $this->pro_excel_tool->execute( $pro_arguments, $context );
	}
}
