<?php
/**
 * Tool for generating image alt text (Validated version).
 *
 * This is the Symfony Validator version of the generate_image_alt_text tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-generate-image-alt-text-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-generate-image-alt-text.php';

/**
 * Generates image alt text using Symfony Validator.
 *
 * This class extends the original generate_image_alt_text tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Generate_Image_Alt_Text_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface {

	/**
	 * The original generate_image_alt_text tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Image_Alt_Text
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Generate_Image_Alt_Text();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_image_alt_text_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Image Alt Text (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates descriptive alt text for images to improve accessibility and SEO using AI vision capabilities with Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GenerateImageAltTextArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GenerateImageAltTextArguments $validated_args Validated arguments object.
	 * @param array                                                     $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array();

		// Add optional arguments if provided.
		if ( null !== $validated_args->image_url ) {
			$arguments['image_url'] = $validated_args->image_url;
		}

		if ( null !== $validated_args->url ) {
			$arguments['url'] = $validated_args->url;
		}

		if ( null !== $validated_args->image_content ) {
			$arguments['image_content'] = $validated_args->image_content;
		}

		if ( null !== $validated_args->attachment_id ) {
			$arguments['attachment_id'] = $validated_args->attachment_id;
		}

		if ( null !== $validated_args->file_id ) {
			$arguments['file_id'] = $validated_args->file_id;
		}

		if ( null !== $validated_args->context ) {
			$arguments['context'] = $validated_args->context;
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
}
