<?php
/**
 * Generate Veo Video Tool Arguments Validation
 *
 * Validation class for generate_veo_video tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class GenerateVeoVideoArguments
 *
 * Defines validation rules for generate_veo_video tool arguments.
 */
class GenerateVeoVideoArguments {

	/**
	 * The text prompt describing the desired video.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Prompt is required for video generation.' )]
	#[Assert\Type( type: 'string', message: 'Prompt must be a string.' )]
	#[Assert\Length(
		max: 4000,
		maxMessage: 'Prompt cannot exceed {{ limit }} characters.'
	)]
	public $prompt;

	/**
	 * Video duration in seconds (4-8).
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Duration must be an integer.' )]
	#[Assert\Range(
		min: 4,
		max: 8,
		notInRangeMessage: 'Duration must be between {{ min }} and {{ max }} seconds.'
	)]
	public $duration = null;

	/**
	 * Video aspect ratio.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Aspect ratio must be a string.' )]
	#[Assert\Choice(
		choices: array( '1:1', '2:3', '3:2', 'auto' ),
		message: 'Aspect ratio must be one of: {{ choices }}.'
	)]
	public $aspect_ratio = null;

	/**
	 * Video resolution.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Resolution must be a string.' )]
	#[Assert\Choice(
		choices: array( '720p', '1080p' ),
		message: 'Resolution must be one of: {{ choices }}.'
	)]
	public $resolution = null;

	/**
	 * Visual style preset.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Style must be a string.' )]
	#[Assert\Choice(
		choices: array( 'cinematic', 'realistic', 'anime', 'documentary', 'artistic', 'none' ),
		message: 'Style must be one of: {{ choices }}.'
	)]
	public $style = null;

	/**
	 * What to avoid in the video.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Negative prompt must be a string.' )]
	#[Assert\Length(
		max: 2000,
		maxMessage: 'Negative prompt cannot exceed {{ limit }} characters.'
	)]
	public $negative_prompt = null;

	/**
	 * WordPress attachment ID of a reference image.
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Reference image ID must be an integer.' )]
	#[Assert\Positive( message: 'Reference image ID must be a positive integer.' )]
	public $reference_image_id = null;

	/**
	 * File ID of a reference image.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Reference image file ID must be a string.' )]
	#[Assert\Length(
		max: 255,
		maxMessage: 'Reference image file ID cannot exceed {{ limit }} characters.'
	)]
	public $reference_image_file_id = null;

	/**
	 * URL of a reference image.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Reference image URL must be a string.' )]
	#[Assert\Url( message: 'Reference image URL must be a valid URL.' )]
	public $reference_image_url = null;

	/**
	 * Random seed for reproducible results.
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Seed must be an integer.' )]
	#[Assert\PositiveOrZero( message: 'Seed must be zero or a positive integer.' )]
	public $seed = null;

	/**
	 * Whether to save the generated video to WordPress Media Library.
	 *
	 * @var bool|null
	 */
	#[Assert\Type( type: 'bool', message: 'Save to media must be a boolean.' )]
	public $save_to_media = null;

	/**
	 * Force a specific Veo model.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Model must be a string.' )]
	#[Assert\Choice(
		choices: array( 'veo-3.1', 'veo-2.0' ),
		message: 'Model must be one of: {{ choices }}.'
	)]
	public $model = null;
}
