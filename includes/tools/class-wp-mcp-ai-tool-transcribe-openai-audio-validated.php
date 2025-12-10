<?php
/**
 * Tool for transcribing OpenAI audio (Validated version).
 *
 * This is the Symfony Validator version of the transcribe_openai_audio tool.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/../validators/class-wp-mcp-ai-validated-tool.php';
require_once __DIR__ . '/../validators/arguments/class-transcribe-openai-audio-arguments.php';
require_once __DIR__ . '/class-wp-mcp-ai-tool-transcribe-openai-audio.php';

/**
 * Transcribes audio using Symfony Validator.
 *
 * This class extends the original transcribe_openai_audio tool to use
 * Symfony Validator for argument validation.
 */
class WP_MCP_AI_Tool_Transcribe_OpenAI_Audio_Validated extends WP_MCP_AI_Validated_Tool implements WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * The original transcribe_openai_audio tool instance for delegation.
	 *
	 * @var WP_MCP_AI_Tool_Transcribe_OpenAI_Audio
	 */
	protected $original_tool;

	/**
	 * Constructor.
	 */
	public function __construct() {
		parent::__construct();
		$this->original_tool = new WP_MCP_AI_Tool_Transcribe_OpenAI_Audio();
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'transcribe_openai_audio_validated';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Transcribe OpenAI Audio (Validated)', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Converts an uploaded audio file into English text using OpenAI transcription or translation with Symfony Validator for argument validation.', 'wp-mcp-ai' );
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
		return \WP_MCP_AI\Tools\Arguments\TranscribeOpenAIAudioArguments::class;
	}

	/**
	 * Execute the tool with validated arguments.
	 *
	 * @param \WP_MCP_AI\Tools\Arguments\TranscribeOpenAIAudioArguments $validated_args Validated arguments object.
	 * @param array                                                     $context        Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	protected function execute_validated( $validated_args, $context ) {
		// Convert validated arguments object back to array format.
		$arguments = array(
			'translate'       => $validated_args->translate,
			'model'           => $validated_args->model,
			'response_format' => $validated_args->response_format,
		);

		// Add optional arguments if provided.
		if ( null !== $validated_args->attachment_id ) {
			$arguments['attachment_id'] = $validated_args->attachment_id;
		}

		if ( null !== $validated_args->file_id ) {
			$arguments['file_id'] = $validated_args->file_id;
		}

		if ( null !== $validated_args->url ) {
			$arguments['url'] = $validated_args->url;
		}

		if ( null !== $validated_args->prompt ) {
			$arguments['prompt'] = $validated_args->prompt;
		}

		if ( null !== $validated_args->temperature ) {
			$arguments['temperature'] = $validated_args->temperature;
		}

		if ( null !== $validated_args->timeout ) {
			$arguments['timeout'] = $validated_args->timeout;
		}

		if ( null !== $validated_args->language ) {
			$arguments['language'] = $validated_args->language;
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
