<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * Validation arguments for Get System Logs tool.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */


namespace WP_MCP_AI\Tools\Arguments;

use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

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
	 * Maximum number of NV oOS activity entries to return.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\Range(
		min: 1,
		max: 50,
		notInRangeMessage: 'Activity limit must be between {{ min }} and {{ max }}.'
	)]
	public $activity_limit = 10;

	/**
	 * Optional list of NV oOS activity types to include.
	 *
	 * @var array
	 */
	#[Assert\Type( type: 'array' )]
	#[Assert\All(
		array(
			new Assert\Type( 'string' ),
		)
	)]
	public $activity_types = array();

	/**
	 * Maximum number of NV oOS error entries to return.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\Range(
		min: 1,
		max: 50,
		notInRangeMessage: 'Error limit must be between {{ min }} and {{ max }}.'
	)]
	public $error_limit = 20;

	/**
	 * Time window lower bound (relative or absolute, UTC).
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\Length(
		max: 100,
		maxMessage: 'Since must be at most {{ limit }} characters long.'
	)]
	#[Assert\Callback( callback: array( self::class, 'validate_since' ) )]
	public $since = '';

	/**
	 * Optional severity levels to include.
	 *
	 * @var array
	 */
	#[Assert\Type( type: 'array' )]
	#[Assert\All(
		array(
			new Assert\Type( 'string' ),
			new Assert\Choice(
				choices: array( 'critical', 'error', 'warning', 'notice', 'deprecated' ),
				message: 'Level must be one of: {{ choices }}.'
			),
		)
	)]
	public $levels = array();

	/**
	 * Optional case-insensitive substring filter.
	 *
	 * @var string
	 */
	#[Assert\Type( type: 'string' )]
	#[Assert\Length(
		max: 200,
		maxMessage: 'Search must be at most {{ limit }} characters long.'
	)]
	public $search = '';

	/**
	 * Whether to include the WordPress debug log if available.
	 *
	 * @var bool
	 */
	#[Assert\Type( type: 'bool' )]
	public $include_debug_log = true;

	/**
	 * Maximum number of lines to return from the WordPress debug log.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
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
	#[Assert\Type( type: 'int' )]
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
	#[Assert\Type( type: 'bool' )]
	public $include_plugin_logs = true;

	/**
	 * Maximum number of plugin log files to inspect.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
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
	#[Assert\Type( type: 'int' )]
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
	#[Assert\Type( type: 'int' )]
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
	#[Assert\Type( type: 'array' )]
	#[Assert\All(
		array(
			new Assert\Type( 'string' ),
		)
	)]
	public $plugin_log_directories = array();

	/**
	 * Maximum recursion depth when scanning plugin log directories.
	 *
	 * @var int
	 */
	#[Assert\Type( type: 'int' )]
	#[Assert\Range(
		min: 0,
		max: 5,
		notInRangeMessage: 'Plugin log depth must be between {{ min }} and {{ max }}.'
	)]
	public $plugin_log_depth = 2;

	/**
	 * Callback validator for the "since" value.
	 *
	 * Delegates to the canonical parser on the base tool so the validated and
	 * non-validated tools can never drift apart on accepted formats.
	 *
	 * @param mixed                     $value   Value to validate.
	 * @param ExecutionContextInterface $context Validation context.
	 */
	public static function validate_since( $value, ExecutionContextInterface $context ) {
		if ( null === $value || '' === $value ) {
			return;
		}

		if ( ! class_exists( 'WP_MCP_AI_Tool_Get_System_Logs' ) ) {
			return;
		}

		if ( false === \WP_MCP_AI_Tool_Get_System_Logs::parse_since( (string) $value ) ) {
			$context->buildViolation( 'The "since" value must be a relative window like "2h" or "30 minutes", or an absolute date like "2026-09-24T10:00:00Z".' )
				->addViolation();
		}
	}
}
