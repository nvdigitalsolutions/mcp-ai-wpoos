<?php
/**
 * Tool for web search (Validated version).
 *
 * This is the Symfony Validator version of the web_search tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-web-search-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-web-search.php';

/**
 * Performs web search with Symfony Validator.
 *
 * This class extends the original web_search tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Web_Search_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface {

	/**
	 * The original web_search tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Web_Search
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Web_Search();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'web_search_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Web Search (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Searches the public web via the configured provider and returns the top results with Symfony Validator for argument validation.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		// Use the same schema as the original tool.
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\WebSearchArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\WebSearchArguments $validated_args Validated arguments object.
	 * @param array                                         $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'query' => $validated_args->query,
		);

		// Add optional arguments if provided.
		if ( null !== $validated_args->max_results ) {
			$arguments['max_results'] = $validated_args->max_results;
		}

		// Delegate to the original tool's execute method.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		// Delegate to the original tool.
		return $this->original_tool->get_capability_flags();
	}

	/**
	 * {@inheritdoc}
	 */
	public function sanitize_for_llm( $result ) {
		// Delegate to the original tool.
		return $this->original_tool->sanitize_for_llm( $result );
	}
}
