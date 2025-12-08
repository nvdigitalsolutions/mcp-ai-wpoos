<?php
/**
 * Tool for retrieving recent posts (Validated version).
 *
 * This is the Symfony Validator version of the get_recent_posts tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-get-recent-posts-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-get-recent-posts.php';

/**
 * Retrieves recent posts using Symfony Validator.
 *
 * This class extends the original get_recent_posts tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Get_Recent_Posts_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original get_recent_posts tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Get_Recent_Posts
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Get_Recent_Posts();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_recent_posts_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get Recent Posts (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Retrieves a list of recent posts using Symfony Validator for argument validation. Allows filtering by post type and limiting the number of results.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GetRecentPostsArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GetRecentPostsArguments $validated_args Validated arguments object.
	 * @param array                                               $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'limit'     => $validated_args->limit,
			'post_type' => $validated_args->post_type,
		);

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
}
