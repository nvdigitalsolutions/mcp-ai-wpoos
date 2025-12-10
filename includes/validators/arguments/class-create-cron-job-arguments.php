<?php
/**
 * Validation arguments for Create Cron Job tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for create_cron_job tool arguments.
 *
 * Defines validation rules for scheduling WordPress cron events.
 */
class CreateCronJobArguments {

	/**
	 * The action hook to schedule.
	 *
	 * @var string
	 */
	#[Assert\NotBlank( message: 'A valid hook name is required to schedule a cron job.' )]
	#[Assert\Type( type: 'string' )]
	#[Assert\Length(
		min: 1,
		minMessage: 'Hook name must be at least {{ limit }} character long.'
	)]
	#[Assert\Regex(
		pattern: '/^[a-z0-9_]+$/',
		message: 'Hook name must contain only lowercase letters, numbers, and underscores.'
	)]
	public $hook = '';

	/**
	 * Unix timestamp for when the event should first run.
	 *
	 * @var int|null
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\PositiveOrZero( message: 'Timestamp must be a positive integer or zero.' )]
	public $timestamp = null;

	/**
	 * Recurrence schedule slug.
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	public $schedule = 'single';

	/**
	 * Optional arguments passed to the action when it runs.
	 *
	 * @var array
	 */
	#[Assert\Type( type: 'array' )]
	public $args = array();
}
