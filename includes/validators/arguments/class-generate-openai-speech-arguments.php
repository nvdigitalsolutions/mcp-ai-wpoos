<?php
/**
 * Generate OpenAI Speech Tool Arguments Validation
 *
 * Validation class for generate_openai_speech tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class GenerateOpenAISpeechArguments
 *
 * Defines validation rules for generate_openai_speech tool arguments.
 */
class GenerateOpenAISpeechArguments {

	/**
	 * The text that should be converted to speech.
	 *
	 * @var string
	 */
	#[Assert\NotBlank(message: 'Text is required for speech generation.')]
	#[Assert\Type(type: 'string', message: 'Text must be a string.')]
	#[Assert\Length(
		min: 1,
		max: 4096,
		minMessage: 'Text must not be empty.',
		maxMessage: 'Text cannot exceed {{ limit }} characters.'
	)]
	public $text;

	/**
	 * OpenAI voice to use (e.g., alloy, verse, shimmer).
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'Voice must be a string.')]
	#[Assert\Length(
		min: 1,
		max: 50,
		minMessage: 'Voice name must not be empty.',
		maxMessage: 'Voice name cannot exceed {{ limit }} characters.'
	)]
	public $voice = null;

	/**
	 * Audio format for the generated file.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'Format must be a string.')]
	#[Assert\Choice(
		choices: ['mp3', 'aac', 'flac', 'ogg', 'opus', 'wav'],
		message: 'Format must be one of: {{ choices }}.'
	)]
	public $format = null;

	/**
	 * OpenAI speech model to use.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'Model must be a string.')]
	#[Assert\Length(
		min: 1,
		max: 100,
		minMessage: 'Model name must not be empty.',
		maxMessage: 'Model name cannot exceed {{ limit }} characters.'
	)]
	public $model = null;

	/**
	 * Playback speed multiplier (0.25 – 4).
	 *
	 * @var float|null
	 */
	#[Assert\Type(type: 'numeric', message: 'Speed must be a number.')]
	#[Assert\Range(
		min: 0.25,
		max: 4.0,
		notInRangeMessage: 'Speed must be between {{ min }} and {{ max }}.'
	)]
	public $speed = null;

	/**
	 * Optional base file name for the saved audio attachment.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'File name must be a string.')]
	#[Assert\Length(
		max: 255,
		maxMessage: 'File name cannot exceed {{ limit }} characters.'
	)]
	public $file_name = null;

	/**
	 * Override the OpenAI request timeout in seconds.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Timeout must be an integer.')]
	#[Assert\Range(
		min: 5,
		max: 300,
		notInRangeMessage: 'Timeout must be between {{ min }} and {{ max }} seconds.'
	)]
	public $timeout = null;
}
