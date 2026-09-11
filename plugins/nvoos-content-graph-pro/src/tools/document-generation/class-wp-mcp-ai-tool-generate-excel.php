<?php
/**
 * WP_MCP_AI_Tool_Generate_Excel (ecosystem port - Wave F2, document-generation tool batch).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/document-generation/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * the base-owned interface/Logger/trait requires gain exists-check seams resolving from the addon's D8-compat `src/` copies; the monolith-gated openai/gemini/ollama client requires stay monolith-gated (architectural-design precedent).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Document_Generation
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);





if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Standalone seam (documented deviation): the base-owned interface and
// Logger requires gain exists-check seams resolving from the addon's
// D8-compat copies (the monorepo root classmap serves the base copies
// monolith).
if ( ! interface_exists( 'WP_MCP_AI_Tool_Interface' ) ) {
	$nvoos_content_graph_pro_tool_interface = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/interfaces/interface-wp-mcp-ai-tool.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_interface ) ) {
		require_once $nvoos_content_graph_pro_tool_interface;
	}
}
if ( ! class_exists( 'WP_MCP_AI_Logger' ) ) {
	$nvoos_content_graph_pro_logger = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/class-wp-mcp-ai-logger.php';
	if ( file_exists( $nvoos_content_graph_pro_logger ) ) {
		require_once $nvoos_content_graph_pro_logger;
	}
}


// Load the chat and document response traits from base plugin.
if ( ! trait_exists( 'WP_MCP_AI_Tool_Document_Response' ) ) {
	$nvoos_content_graph_pro_tool_document_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/tools/trait-wp-mcp-ai-tool-document-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_document_response ) ) {
		require_once $nvoos_content_graph_pro_tool_document_response;
	}
}
if ( ! trait_exists( 'WP_MCP_AI_Tool_Chat_Response' ) ) {
	$nvoos_content_graph_pro_tool_chat_response = NVOOS_CONTENT_GRAPH_PRO_PATH . 'src/trait-wp-mcp-ai-tool-chat-response.php';
	if ( file_exists( $nvoos_content_graph_pro_tool_chat_response ) ) {
		require_once $nvoos_content_graph_pro_tool_chat_response;
	}
}

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
		return __( 'Generate Excel', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate Excel spreadsheets from data. Simplified interface for creating .xlsx files with tables and data. For advanced features, use Pro Excel Document tool.', 'nvoos-content-graph-pro' );
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
					'description' => __( 'Data to include in the spreadsheet (as JSON string or CSV format).', 'nvoos-content-graph-pro' ),
				),
				'title' => array(
					'type'        => 'string',
					'description' => __( 'Spreadsheet title.', 'nvoos-content-graph-pro' ),
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
	 * Get required capability.
	 *
	 * @return string
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
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
