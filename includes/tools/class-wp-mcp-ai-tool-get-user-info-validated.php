<?php
/**
 * Validated version of Get User Info tool.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once plugin_dir_path( __FILE__ ) . 'class-wp-mcp-ai-tool-get-user-info.php';

/**
 * Get User Info tool with Symfony Validator integration.
 *
 * This tool validates input parameters using Symfony Validator before
 * delegating to the original get_user_info tool implementation.
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Get_User_Info_Validated extends WP_MCP_AI_Validated_Tool {

	/**
	 * Original tool instance.
	 *
	 * @var WP_MCP_AI_Tool_Get_User_Info
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Get_User_Info();
	}

	/**
	 * Get the validation class for this tool.
	 *
	 * @return string Fully qualified class name of the validation class.
	 */
	protected function get_validation_class() {
		return \WP_MCP_AI\Tools\Arguments\GetUserInfoArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param object $validated_args Validated arguments object.
	 * @param array  $context        Execution context.
	 * @return array|WP_Error Tool execution result.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object to array.
		$arguments = array();
		if ( ! empty( $validated_args->user_id ) ) {
			$arguments['user_id'] = $validated_args->user_id;
		}

		// Delegate to original tool.
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return $this->original_tool->get_slug();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return $this->original_tool->get_name();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return $this->original_tool->get_description();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return $this->original_tool->get_parameters_schema();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		if ( method_exists( $this->original_tool, 'get_capability_flags' ) ) {
			return $this->original_tool->get_capability_flags();
		}
		return array();
	}
}
