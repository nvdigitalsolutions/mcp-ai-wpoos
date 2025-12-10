<?php
/**
 * Tool for generating Gemini images (Validated version).
 *
 * This is the Symfony Validator version of the generate_gemini_image tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-generate-gemini-image-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-generate-gemini-image.php';

/**
 * Generates images using Gemini with Symfony Validator.
 *
 * This class extends the original generate_gemini_image tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Generate_Gemini_Image_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Rules_Interface {

	/**
	 * The original generate_gemini_image tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Gemini_Image
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Generate_Gemini_Image();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_gemini_image_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Gemini Image (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an image with Gemini and stores it in the Media Library with Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GenerateGeminiImageArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GenerateGeminiImageArguments $validated_args Validated arguments object.
	 * @param array                                                   $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'prompt' => $validated_args->prompt,
		);

		// Add optional arguments if provided.
		if ( null !== $validated_args->model ) {
			$arguments['model'] = $validated_args->model;
		}

		if ( null !== $validated_args->aspect_ratio ) {
			$arguments['aspect_ratio'] = $validated_args->aspect_ratio;
		}

		if ( null !== $validated_args->mime_type ) {
			$arguments['mime_type'] = $validated_args->mime_type;
		}

		if ( null !== $validated_args->file_name ) {
			$arguments['file_name'] = $validated_args->file_name;
		}

		if ( null !== $validated_args->timeout ) {
			$arguments['timeout'] = $validated_args->timeout;
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
	public function get_model_requirements() {
		// Delegate to the original tool.
		return $this->original_tool->get_model_requirements();
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
	public function sanitize_for_llm( $result ) {
		// Delegate to the original tool.
		return $this->original_tool->sanitize_for_llm( $result );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		// Delegate to the original tool.
		return $this->original_tool->get_tool_rules();
	}
}
