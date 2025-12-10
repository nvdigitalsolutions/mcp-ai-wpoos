<?php
/**
 * Transcribe OpenAI Audio Tool Arguments Validation
 *
 * Validation class for transcribe_openai_audio tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class TranscribeOpenAIAudioArguments
 *
 * Defines validation rules for transcribe_openai_audio tool arguments.
 */
class TranscribeOpenAIAudioArguments {

	/**
	 * WordPress attachment ID containing the audio file.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Attachment ID must be an integer.')]
	#[Assert\Positive(message: 'Attachment ID must be a positive integer.')]
	public $attachment_id = null;

	/**
	 * File ID reference from message attachments.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'File ID must be a string.')]
	#[Assert\Length(
		min: 1,
		max: 200,
		minMessage: 'File ID must be at least {{ limit }} characters long.',
		maxMessage: 'File ID cannot be longer than {{ limit }} characters.'
	)]
	public $file_id = null;

	/**
	 * URL to audio file.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'URL must be a string.')]
	#[Assert\Url(message: 'URL must be a valid URL.')]
	public $url = null;

	/**
	 * Whether to translate audio into English (true) or just transcribe (false).
	 *
	 * @var bool
	 */
	#[Assert\Type(type: 'bool', message: 'Translate must be a boolean.')]
	public $translate = true;

	/**
	 * OpenAI model to use for transcription.
	 *
	 * @var string
	 */
	#[Assert\NotBlank(message: 'Model cannot be blank.')]
	#[Assert\Length(
		min: 1,
		max: 100,
		minMessage: 'Model must be at least {{ limit }} characters long.',
		maxMessage: 'Model cannot be longer than {{ limit }} characters.'
	)]
	public $model = 'gpt-4o-mini-transcribe';

	/**
	 * Optional prompt for transcription context.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'Prompt must be a string.')]
	#[Assert\Length(
		max: 1000,
		maxMessage: 'Prompt cannot be longer than {{ limit }} characters.'
	)]
	public $prompt = null;

	/**
	 * Temperature setting (0-1).
	 *
	 * @var float|null
	 */
	#[Assert\Type(type: 'float', message: 'Temperature must be a number.')]
	#[Assert\Range(
		min: 0,
		max: 1,
		notInRangeMessage: 'Temperature must be between {{ min }} and {{ max }}.'
	)]
	public $temperature = null;

	/**
	 * Request timeout in seconds.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Timeout must be an integer.')]
	#[Assert\Positive(message: 'Timeout must be a positive integer.')]
	#[Assert\Range(
		min: 1,
		max: 300,
		notInRangeMessage: 'Timeout must be between {{ min }} and {{ max }} seconds.'
	)]
	public $timeout = null;

	/**
	 * Response format (json or verbose_json).
	 *
	 * @var string
	 */
	#[Assert\Choice(
		choices: array( 'json', 'verbose_json' ),
		message: 'Response format must be one of: {{ choices }}.'
	)]
	public $response_format = 'verbose_json';

	/**
	 * ISO language code hint for transcription.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'Language must be a string.')]
	#[Assert\Length(
		min: 2,
		max: 10,
		minMessage: 'Language code must be at least {{ limit }} characters.',
		maxMessage: 'Language code cannot be longer than {{ limit }} characters.'
	)]
	#[Assert\Regex(
		pattern: '/^[a-z]{2}(-[A-Z]{2})?$/',
		message: 'Language must be a valid ISO language code (e.g., "en", "en-US").'
	)]
	public $language = null;
}
