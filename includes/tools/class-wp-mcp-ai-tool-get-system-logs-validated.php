<?php
/**
 * Tool for retrieving system logs (Validated version).
 *
 * This is the Symfony Validator version of the get_system_logs tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-get-system-logs-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-get-system-logs.php';

/**
 * Retrieves system logs using Symfony Validator.
 *
 * This class extends the original get_system_logs tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Get_System_Logs_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original get_system_logs tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Get_System_Logs
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Get_System_Logs();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_system_logs_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get System Logs (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns recent log entries from WordPress, WP oOS, and plugin log files for diagnostics using Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GetSystemLogsArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GetSystemLogsArguments $validated_args Validated arguments object.
	 * @param array                                              $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'activity_limit'         => $validated_args->activity_limit,
			'activity_types'         => $validated_args->activity_types,
			'error_limit'            => $validated_args->error_limit,
			'include_debug_log'      => $validated_args->include_debug_log,
			'debug_log_limit'        => $validated_args->debug_log_limit,
			'debug_log_bytes'        => $validated_args->debug_log_bytes,
			'include_plugin_logs'    => $validated_args->include_plugin_logs,
			'plugin_log_limit'       => $validated_args->plugin_log_limit,
			'plugin_log_line_limit'  => $validated_args->plugin_log_line_limit,
			'plugin_log_bytes'       => $validated_args->plugin_log_bytes,
			'plugin_log_directories' => $validated_args->plugin_log_directories,
			'plugin_log_depth'       => $validated_args->plugin_log_depth,
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
