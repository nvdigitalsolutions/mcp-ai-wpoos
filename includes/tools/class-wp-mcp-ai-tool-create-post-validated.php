<?php
/**
 * Tool for creating posts (Validated version).
 *
 * This is the Symfony Validator version of the create_post tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-create-post-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-create-post.php';

/**
 * Creates posts using Symfony Validator.
 *
 * This class extends the original create_post tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Create_Post_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original create_post tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Create_Post
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Create_Post();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_post_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Post (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates a new WordPress post with Symfony Validator for argument validation. For updating existing posts, use save_post instead.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\CreatePostArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\CreatePostArguments $validated_args Validated arguments object.
	 * @param array                                           $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'title'     => $validated_args->title,
			'content'   => $validated_args->content,
			'post_type' => $validated_args->post_type,
			'status'    => $validated_args->status,
		);

		// Add optional user_id if provided (when null, base tool will use current user from context).
		if ( null !== $validated_args->user_id ) {
			$arguments['user_id'] = $validated_args->user_id;
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
}
