<?php
/**
 * Validation arguments for Send Group Email tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for send_group_email tool arguments.
 *
 * Defines validation rules for sending group emails via WordPress mailer.
 */
class SendGroupEmailArguments {

	/**
	 * Email subject line.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	#[Assert\Length(
		max: 200,
		maxMessage: 'Email subject cannot exceed {{ limit }} characters.'
	)]
	public $subject = null;

	/**
	 * Email message content.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	public $message = null;

	/**
	 * List of email recipients.
	 *
	 * @var array|null
	 */
	#[Assert\Type(type: 'array')]
	public $recipients = null;

	/**
	 * WordPress attachment ID containing email definition.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Positive(message: 'Attachment ID must be a positive integer.')]
	public $attachment_id = null;

	/**
	 * File ID for email definition file.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	public $file_id = null;

	/**
	 * URL to email definition file.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	#[Assert\Url(message: 'URL must be a valid URL.')]
	public $url = null;

	/**
	 * List of WordPress attachment IDs to combine.
	 *
	 * @var array|null
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\All([
		new Assert\Type(type: 'int'),
		new Assert\Positive(message: 'Each attachment ID must be a positive integer.')
	])]
	public $attachment_ids = null;

	/**
	 * Override for the from email address.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	#[Assert\Email(message: 'From email must be a valid email address.')]
	public $from_email = null;

	/**
	 * Override for the from name.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string')]
	#[Assert\Length(
		max: 100,
		maxMessage: 'From name cannot exceed {{ limit }} characters.'
	)]
	public $from_name = null;

	/**
	 * Additional email headers.
	 *
	 * @var array|null
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\All([
		new Assert\Type(type: 'string')
	])]
	public $headers = null;
}
