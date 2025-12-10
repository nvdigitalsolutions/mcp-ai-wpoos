<?php
/**
 * Generate Music Tool Arguments Validation
 *
 * Validation class for generate_music tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class GenerateMusicArguments
 *
 * Defines validation rules for generate_music tool arguments.
 */
class GenerateMusicArguments {

	/**
	 * Description of the desired music.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Prompt is required for music generation.' )]
	#[Assert\Type( type: 'string', message: 'Prompt must be a string.' )]
	#[Assert\Length(
		min: 1,
		max: 2000,
		minMessage: 'Prompt must not be empty.',
		maxMessage: 'Prompt cannot exceed {{ limit }} characters.'
	)]
	public $prompt;

	/**
	 * Duration of the music in seconds (1-300).
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Duration must be an integer.' )]
	#[Assert\Range(
		min: 1,
		max: 300,
		notInRangeMessage: 'Duration must be between {{ min }} and {{ max }} seconds.'
	)]
	public $duration = null;

	/**
	 * Optional music genre.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Genre must be a string.' )]
	#[Assert\Length(
		max: 100,
		maxMessage: 'Genre cannot exceed {{ limit }} characters.'
	)]
	public $genre = null;

	/**
	 * Optional mood or atmosphere.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Mood must be a string.' )]
	#[Assert\Length(
		max: 100,
		maxMessage: 'Mood cannot exceed {{ limit }} characters.'
	)]
	public $mood = null;

	/**
	 * Optional instruments to feature.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Instrumentation must be a string.' )]
	#[Assert\Length(
		max: 200,
		maxMessage: 'Instrumentation cannot exceed {{ limit }} characters.'
	)]
	public $instrumentation = null;

	/**
	 * Optional tempo in beats per minute (20-300).
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'BPM must be an integer.' )]
	#[Assert\Range(
		min: 20,
		max: 300,
		notInRangeMessage: 'BPM must be between {{ min }} and {{ max }}.'
	)]
	public $bpm = null;

	/**
	 * Optional musical key.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Key must be a string.' )]
	#[Assert\Length(
		max: 50,
		maxMessage: 'Key cannot exceed {{ limit }} characters.'
	)]
	public $key = null;

	/**
	 * Optional creativity level (0.0-2.0).
	 *
	 * @var float|null
	 */
	#[Assert\Type( type: 'numeric', message: 'Temperature must be a number.' )]
	#[Assert\Range(
		min: 0.0,
		max: 2.0,
		notInRangeMessage: 'Temperature must be between {{ min }} and {{ max }}.'
	)]
	public $temperature = null;

	/**
	 * Optional base file name for the saved audio attachment.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'File name must be a string.' )]
	#[Assert\Length(
		max: 255,
		maxMessage: 'File name cannot exceed {{ limit }} characters.'
	)]
	public $file_name = null;
}
