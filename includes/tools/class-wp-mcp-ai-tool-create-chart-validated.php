<?php
/**
 * Tool for creating charts (Validated version).
 *
 * This is the Symfony Validator version of the create_chart tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-create-chart-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-create-chart.php';

/**
 * Creates charts using Symfony Validator.
 *
 * This class extends the original create_chart tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Create_Chart_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_Rules_Interface {

	/**
	 * The original create_chart tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Create_Chart
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Create_Chart();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_chart_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Chart (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates interactive charts using Chart.js with Symfony Validator for argument validation. Supports bar, line, pie, doughnut, radar, and polar area charts.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\CreateChartArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\CreateChartArguments $validated_args Validated arguments object.
	 * @param array                                            $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'type'               => $validated_args->type,
			'data'               => $validated_args->data,
			'width'              => $validated_args->width,
			'height'             => $validated_args->height,
			'save_as_attachment' => $validated_args->save_as_attachment,
		);

		// Add optional parameters if provided.
		if ( null !== $validated_args->options ) {
			$arguments['options'] = $validated_args->options;
		}

		if ( null !== $validated_args->title ) {
			$arguments['title'] = $validated_args->title;
		}

		if ( null !== $validated_args->file_name ) {
			$arguments['file_name'] = $validated_args->file_name;
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
	public function get_shortcut_tasks() {
		// Delegate to the original tool.
		return $this->original_tool->get_shortcut_tasks();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		// Delegate to the original tool.
		return $this->original_tool->get_tool_rules();
	}
}
