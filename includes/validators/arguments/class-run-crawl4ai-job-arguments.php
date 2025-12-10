<?php
/**
 * Run Crawl4AI Job Tool Arguments Validation
 *
 * Validation class for run_crawl4ai_job tool arguments using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

/**
 * Class RunCrawl4AIJobArguments
 *
 * Defines validation rules for run_crawl4ai_job tool arguments.
 */
class RunCrawl4AIJobArguments {

	/**
	 * List of URLs that should be crawled.
	 *
	 * @var array|null
	 */
	#[Assert\Type(type: 'array', message: 'URLs must be an array.')]
	#[Assert\Count(
		min: 1,
		minMessage: 'At least one URL is required.'
	)]
	#[Assert\All([
		new Assert\Type(type: 'string', message: 'Each URL must be a string.'),
		new Assert\Url(message: 'Each URL must be a valid URL.')
	])]
	public $urls = null;

	/**
	 * Convenience field for a single URL.
	 *
	 * @var string|null
	 */
	#[Assert\Type(type: 'string', message: 'URL must be a string.')]
	#[Assert\Url(message: 'URL must be a valid URL.')]
	public $url = null;

	/**
	 * Optional job priority forwarded to Crawl4AI.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Priority must be an integer.')]
	#[Assert\Range(
		min: 0,
		max: 100,
		notInRangeMessage: 'Priority must be between {{ min }} and {{ max }}.'
	)]
	public $priority = null;

	/**
	 * Additional Crawl4AI options.
	 *
	 * @var array|null
	 */
	#[Assert\Type(type: 'array', message: 'Options must be an array.')]
	public $options = null;

	/**
	 * When true, the tool polls Crawl4AI until the job finishes.
	 *
	 * @var bool|null
	 */
	#[Assert\Type(type: 'bool', message: 'Wait for completion must be a boolean.')]
	public $wait_for_completion = null;

	/**
	 * Number of seconds to wait between polling attempts.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Poll interval must be an integer.')]
	#[Assert\Range(
		min: 0,
		max: 30,
		notInRangeMessage: 'Poll interval must be between {{ min }} and {{ max }} seconds.'
	)]
	public $poll_interval = null;

	/**
	 * Maximum number of seconds to wait for the job to finish.
	 *
	 * @var int|null
	 */
	#[Assert\Type(type: 'integer', message: 'Timeout must be an integer.')]
	#[Assert\Range(
		min: 0,
		max: 600,
		notInRangeMessage: 'Timeout must be between {{ min }} and {{ max }} seconds.'
	)]
	public $timeout = null;
}
