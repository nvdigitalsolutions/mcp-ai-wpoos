<?php
/**
 * Tool for generating music (Validated version).
 *
 * This is the Symfony Validator version of the generate_music tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-generate-music-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-generate-music.php';

/**
 * Generates music using Google Gemini Lyria with Symfony Validator.
 *
 * This class extends the original generate_music tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Generate_Music_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original generate_music tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Generate_Music
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Generate_Music();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_music_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Music (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generates instrumental music from a text description using Google Gemini Lyria model and saves it to the Media Library with Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GenerateMusicArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GenerateMusicArguments $validated_args Validated arguments object.
	 * @param array                                             $context        Execution context including user_id.
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

		if ( null !== $validated_args->genre ) {
			$arguments['genre'] = $validated_args->genre;
		}

		if ( null !== $validated_args->mood ) {
			$arguments['mood'] = $validated_args->mood;
		}

		if ( null !== $validated_args->instrumentation ) {
			$arguments['instrumentation'] = $validated_args->instrumentation;
		}

		if ( null !== $validated_args->bpm ) {
			$arguments['bpm'] = $validated_args->bpm;
		}

		if ( null !== $validated_args->key ) {
			$arguments['key'] = $validated_args->key;
		}

		if ( null !== $validated_args->temperature ) {
			$arguments['temperature'] = $validated_args->temperature;
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
}
