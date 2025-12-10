<?php
/**
 * Tool for generating OpenAI speech (Validated version).
 *
 * This is the Symfony Validator version of the generate_openai_speech tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-generate-openai-speech-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-generate-openai-speech.php';

/**
 * Generates speech audio using OpenAI with Symfony Validator.
 *
 * This class extends the original generate_openai_speech tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Generate_OpenAI_Speech_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original generate_openai_speech tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Generate_OpenAI_Speech
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Generate_OpenAI_Speech();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_openai_speech_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate OpenAI Speech (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts text to speech using OpenAI and stores the audio in the Media Library with Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\GenerateOpenAISpeechArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\GenerateOpenAISpeechArguments $validated_args Validated arguments object.
	 * @param array                                                    $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'text' => $validated_args->text,
		);

		// Add optional arguments if provided.
		if ( null !== $validated_args->voice ) {
			$arguments['voice'] = $validated_args->voice;
		}

		if ( null !== $validated_args->format ) {
			$arguments['format'] = $validated_args->format;
		}

		if ( null !== $validated_args->model ) {
			$arguments['model'] = $validated_args->model;
		}

		if ( null !== $validated_args->speed ) {
			$arguments['speed'] = $validated_args->speed;
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
}
