<?php
/**
 * Tool for generating Veo videos (Validated version).
 *
 * This is the Symfony Validator version of the generate_veo_video tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-generate-veo-video-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-generate-veo-video.php';

/**
 * Generates videos using Veo with Symfony Validator.
 *
 * This class extends the original generate_veo_video tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Generate_Veo_Video_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Model_Requirements_Interface, WP_MCP_AI_Tool_LLM_Sanitizer_Interface, WP_MCP_AI_Tool_Async_Metadata_Interface {

	/**
	 * The original generate_veo_video tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Veo_Video
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Generate_Veo_Video();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_veo_video_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Video with Veo (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates realistic videos from text descriptions using Google\'s Veo models with Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GenerateVeoVideoArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GenerateVeoVideoArguments $validated_args Validated arguments object.
	 * @param array                                                $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'prompt' => $validated_args->prompt,
		);

		// Add optional arguments if provided.
		if ( null !== $validated_args->duration ) {
			$arguments['duration'] = $validated_args->duration;
		}

		if ( null !== $validated_args->aspect_ratio ) {
			$arguments['aspect_ratio'] = $validated_args->aspect_ratio;
		}

		if ( null !== $validated_args->resolution ) {
			$arguments['resolution'] = $validated_args->resolution;
		}

		if ( null !== $validated_args->style ) {
			$arguments['style'] = $validated_args->style;
		}

		if ( null !== $validated_args->negative_prompt ) {
			$arguments['negative_prompt'] = $validated_args->negative_prompt;
		}

		if ( null !== $validated_args->reference_image_id ) {
			$arguments['reference_image_id'] = $validated_args->reference_image_id;
		}

		if ( null !== $validated_args->reference_image_file_id ) {
			$arguments['reference_image_file_id'] = $validated_args->reference_image_file_id;
		}

		if ( null !== $validated_args->reference_image_url ) {
			$arguments['reference_image_url'] = $validated_args->reference_image_url;
		}

		if ( null !== $validated_args->seed ) {
			$arguments['seed'] = $validated_args->seed;
		}

		if ( null !== $validated_args->save_to_media ) {
			$arguments['save_to_media'] = $validated_args->save_to_media;
		}

		if ( null !== $validated_args->model ) {
			$arguments['model'] = $validated_args->model;
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
	public function sanitize_for_llm( $result ) {
		// Delegate to the original tool.
		return $this->original_tool->sanitize_for_llm( $result );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_async_metadata() {
		// Delegate to the original tool.
		return $this->original_tool->get_async_metadata();
	}
}
