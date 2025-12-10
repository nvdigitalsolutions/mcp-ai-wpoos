<?php
/**
 * Generate Gemini Image Tool Arguments Validation
 *
 * Validation class for generate_gemini_image tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class GenerateGeminiImageArguments
 *
 * Defines validation rules for generate_gemini_image tool arguments.
 */
class GenerateGeminiImageArguments {

	/**
	 * The text prompt describing the desired image.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Prompt is required for image generation.' )]
	#[Assert\Type( type: 'string', message: 'Prompt must be a string.' )]
	#[Assert\Length(
		min: 1,
		max: 4000,
		minMessage: 'Prompt must not be empty.',
		maxMessage: 'Prompt cannot exceed {{ limit }} characters.'
	)]
	public $prompt;

	/**
	 * Gemini image model to use.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Model must be a string.' )]
	#[Assert\Length(
		min: 1,
		max: 100,
		minMessage: 'Model name must not be empty.',
		maxMessage: 'Model name cannot exceed {{ limit }} characters.'
	)]
	public $model = null;

	/**
	 * Aspect ratio for the generated image.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Aspect ratio must be a string.' )]
	#[Assert\Choice(
		choices: array( '1:1', '3:4', '4:3', '9:16', '16:9' ),
		message: 'Aspect ratio must be one of: {{ choices }}.'
	)]
	public $aspect_ratio = null;

	/**
	 * Preferred MIME type for the saved image.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'MIME type must be a string.' )]
	#[Assert\Choice(
		choices: array( 'image/png', 'image/jpeg', 'image/webp' ),
		message: 'MIME type must be one of: {{ choices }}.'
	)]
	public $mime_type = null;

	/**
	 * Optional base file name for the saved image attachment.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'File name must be a string.' )]
	#[Assert\Length(
		max: 255,
		maxMessage: 'File name cannot exceed {{ limit }} characters.'
	)]
	public $file_name = null;

	/**
	 * Override the Gemini request timeout in seconds.
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Timeout must be an integer.' )]
	#[Assert\Range(
		min: 5,
		max: 300,
		notInRangeMessage: 'Timeout must be between {{ min }} and {{ max }} seconds.'
	)]
	public $timeout = null;
}
