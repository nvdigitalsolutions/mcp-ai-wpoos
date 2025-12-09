<?php
/**
 * Validation arguments for Get System Logs tool.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Validation class for get_system_logs tool arguments.
 *
 * Defines validation rules for retrieving system log entries.
 */
class GetSystemLogsArguments {

	/**
	 * Maximum number of WP oOS activity entries to return.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1,
		max: 50,
		notInRangeMessage: 'Activity limit must be between {{ min }} and {{ max }}.'
	)]
	public $activity_limit = 10;

	/**
	 * Optional list of WP oOS activity types to include.
	 *
	 * @var array
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\All([
		new Assert\Type('string')
	])]
	public $activity_types = array();

	/**
	 * Maximum number of WP oOS error entries to return.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1,
		max: 50,
		notInRangeMessage: 'Error limit must be between {{ min }} and {{ max }}.'
	)]
	public $error_limit = 20;

	/**
	 * Whether to include the WordPress debug log if available.
	 *
	 * @var bool
	 */
	#[Assert\Type(type: 'bool')]
	public $include_debug_log = true;

	/**
	 * Maximum number of lines to return from the WordPress debug log.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1,
		max: 200,
		notInRangeMessage: 'Debug log limit must be between {{ min }} and {{ max }}.'
	)]
	public $debug_log_limit = 50;

	/**
	 * Maximum number of bytes to inspect when tailing the WordPress debug log.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1024,
		max: 200000,
		notInRangeMessage: 'Debug log bytes must be between {{ min }} and {{ max }}.'
	)]
	public $debug_log_bytes = 50000;

	/**
	 * Whether to scan plugin directories for additional .log files.
	 *
	 * @var bool
	 */
	#[Assert\Type(type: 'bool')]
	public $include_plugin_logs = true;

	/**
	 * Maximum number of plugin log files to inspect.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1,
		max: 20,
		notInRangeMessage: 'Plugin log limit must be between {{ min }} and {{ max }}.'
	)]
	public $plugin_log_limit = 5;

	/**
	 * Maximum number of lines to return from each plugin log.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1,
		max: 200,
		notInRangeMessage: 'Plugin log line limit must be between {{ min }} and {{ max }}.'
	)]
	public $plugin_log_line_limit = 50;

	/**
	 * Maximum number of bytes to inspect when tailing plugin logs.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 1024,
		max: 200000,
		notInRangeMessage: 'Plugin log bytes must be between {{ min }} and {{ max }}.'
	)]
	public $plugin_log_bytes = 50000;

	/**
	 * Optional list of directories to scan for plugin log files.
	 *
	 * @var array
	 */
	#[Assert\Type(type: 'array')]
	#[Assert\All([
		new Assert\Type('string')
	])]
	public $plugin_log_directories = array();

	/**
	 * Maximum recursion depth when scanning plugin log directories.
	 *
	 * @var int
	 */
	#[Assert\Type(type: 'int')]
	#[Assert\Range(
		min: 0,
		max: 5,
		notInRangeMessage: 'Plugin log depth must be between {{ min }} and {{ max }}.'
	)]
	public $plugin_log_depth = 2;
}
