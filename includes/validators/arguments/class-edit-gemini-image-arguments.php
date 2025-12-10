<?php
/**
 * Edit Gemini Image Tool Arguments Validation
 *
 * Validation class for edit_gemini_image tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class EditGeminiImageArguments
 *
 * Defines validation rules for edit_gemini_image tool arguments.
 */
class EditGeminiImageArguments {

	/**
	 * Text instruction describing the desired edits.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'Prompt is required for image editing.' )]
	#[Assert\Type( type: 'string', message: 'Prompt must be a string.' )]
	#[Assert\Length(
		max: 4000,
		maxMessage: 'Prompt cannot exceed {{ limit }} characters.'
	)]
	public $prompt;

	/**
	 * WordPress attachment ID of the image to edit.
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Attachment ID must be an integer.' )]
	#[Assert\Positive( message: 'Attachment ID must be a positive integer.' )]
	public $attachment_id = null;

	/**
	 * URL of the image to edit.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Image URL must be a string.' )]
	#[Assert\Url( message: 'Image URL must be a valid URL.' )]
	public $image_url = null;

	/**
	 * Base64-encoded image data to edit.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Image data must be a string.' )]
	public $image_data = null;

	/**
	 * MIME type of the source image data.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Source MIME type must be a string.' )]
	#[Assert\Choice(
		choices: array( 'image/png', 'image/jpeg', 'image/webp', 'image/gif' ),
		message: 'Source MIME type must be one of: {{ choices }}.'
	)]
	public $source_mime_type = null;

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
	 * Aspect ratio for the edited image.
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
