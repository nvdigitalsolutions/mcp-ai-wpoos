<?php
/**
 * Generate OpenAI Image Tool Arguments Validation
 *
 * Validation class for generate_openai_image tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class GenerateOpenAIImageArguments
 *
 * Defines validation rules for generate_openai_image tool arguments.
 */
class GenerateOpenAIImageArguments {

	/**
	 * The text prompt describing the desired image.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Prompt is required for image generation.' )]
	#[Assert\Type( type: 'string', message: 'Prompt must be a string.' )]
	#[Assert\Length(
		max: 4000,
		maxMessage: 'Prompt cannot exceed {{ limit }} characters.'
	)]
	public $prompt;

	/**
	 * OpenAI image model to use.
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
	 * Size of the generated image.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Size must be a string.' )]
	#[Assert\Choice(
		choices: array( '1024x1024', '1792x1024', '1024x1792', '256x256', '512x512' ),
		message: 'Size must be one of: {{ choices }}.'
	)]
	public $size = null;

	/**
	 * Image quality setting.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Quality must be a string.' )]
	#[Assert\Choice(
		choices: array( 'low', 'medium', 'high', 'auto', 'standard', 'hd' ),
		message: 'Quality must be one of: {{ choices }}.'
	)]
	public $quality = null;

	/**
	 * Style preset for DALL-E 3 models.
	 *
	 * "vivid" produces hyper-real and dramatic images.
	 * "natural" produces more natural, less hyper-real looking images.
	 * Only supported by DALL-E 3 model.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Style must be a string.' )]
	#[Assert\Choice(
		choices: array( 'natural', 'vivid' ),
		message: 'Style must be one of: {{ choices }}.'
	)]
	public $style = null;

	/**
	 * Whether OpenAI should return base64 data or a hosted image URL.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Response format must be a string.' )]
	#[Assert\Choice(
		choices: array( 'b64_json', 'url' ),
		message: 'Response format must be one of: {{ choices }}.'
	)]
	public $response_format = null;

	/**
	 * Image format for the generated file.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Format must be a string.' )]
	#[Assert\Choice(
		choices: array( 'png' ),
		message: 'Format must be one of: {{ choices }}.'
	)]
	public $format = null;

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
	 * Override the OpenAI request timeout in seconds.
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
