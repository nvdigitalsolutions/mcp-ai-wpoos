<?php
/**
 * Generate Image Alt Text Tool Arguments Validation
 *
 * Validation class for generate_image_alt_text tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class GenerateImageAltTextArguments
 *
 * Defines validation rules for generate_image_alt_text tool arguments.
 */
class GenerateImageAltTextArguments {

	/**
	 * URL of the image to analyze (legacy parameter).
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Image URL must be a string.' )]
	#[Assert\Url( message: 'Image URL must be a valid URL.' )]
	public $image_url = null;

	/**
	 * URL of the image to analyze (alternative to image_url).
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'URL must be a string.' )]
	#[Assert\Url( message: 'URL must be a valid URL.' )]
	public $url = null;

	/**
	 * Base64-encoded image content.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Image content must be a string.' )]
	#[Assert\Length(
		min: 1,
		max: 10485760,
		minMessage: 'Image content must not be empty.',
		maxMessage: 'Image content cannot exceed {{ limit }} characters (approximately 10MB).'
	)]
	public $image_content = null;

	/**
	 * WordPress attachment ID.
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'integer', message: 'Attachment ID must be an integer.' )]
	#[Assert\Positive( message: 'Attachment ID must be a positive integer.' )]
	public $attachment_id = null;

	/**
	 * File ID from message attachments.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'File ID must be a string.' )]
	#[Assert\Length(
		min: 1,
		max: 200,
		minMessage: 'File ID must be at least {{ limit }} characters long.',
		maxMessage: 'File ID cannot be longer than {{ limit }} characters.'
	)]
	public $file_id = null;

	/**
	 * Optional context about the image.
	 *
	 * @var string|null
	 */
	#[Assert\Type( type: 'string', message: 'Context must be a string.' )]
	#[Assert\Length(
		max: 500,
		maxMessage: 'Context cannot be longer than {{ limit }} characters.'
	)]
	public $context = null;
}
