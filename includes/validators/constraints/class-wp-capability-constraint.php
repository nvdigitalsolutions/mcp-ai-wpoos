<?php // phpcs:disable WordPress.Files.FileName.InvalidClassFileName -- Descriptive file names follow WordPress kebab-case conventions for better readability.
/**
 * WordPress Capability Constraint
 *
 * Custom Symfony Validator constraint for WordPress capability checks.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */


namespace WP_MCP_AI\Validators\Constraints;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}


use Symfony\Component\Validator\Constraint;

/**
 * Class WPCapability
 *
 * Validates that the current user has the specified WordPress capability.
 *
 * @Annotation
 * @Target({"PROPERTY", "METHOD", "ANNOTATION"})
 */
#[\Attribute( \Attribute::TARGET_PROPERTY | \Attribute::TARGET_METHOD | \Attribute::IS_REPEATABLE )]
class WPCapability extends Constraint {

	/**
	 * Error message template.
	 *
	 * @var string
	 */
	public $message = 'User lacks required capability: {{ capability }}';

	/**
	 * Required WordPress capability.
	 *
	 * @var string
	 */
	public $capability;

	/**
	 * Constructor.
	 *
	 * Accepts all three call styles so the constraint works whether it is used
	 * as a PHP attribute with a named argument
	 * (`#[WPCapability( capability: 'edit_posts' )]`), as an attribute with a
	 * bare string (`#[WPCapability( 'edit_posts' )]`), or constructed directly
	 * with an options array. Without the explicit `$capability` parameter, PHP
	 * raises "Unknown named parameter $capability" the moment attribute mapping
	 * actually reads the constraint.
	 *
	 * @param mixed       $options    Options array or capability string.
	 * @param string|null $capability Capability name, when passed as a named argument.
	 * @param array|null  $groups     Validation groups.
	 * @param mixed       $payload    Payload.
	 */
	public function __construct( $options = null, $capability = null, array $groups = null, $payload = null ) {
		// Support both attribute syntax and annotation syntax.
		if ( is_string( $options ) ) {
			$options = array( 'capability' => $options );
		}

		if ( null !== $capability ) {
			$options               = is_array( $options ) ? $options : array();
			$options['capability'] = $capability;
		}

		parent::__construct( $options, $groups, $payload );
	}

	/**
	 * Get the name of the required option.
	 *
	 * @return string
	 */
	public function getRequiredOptions() {
		return array( 'capability' );
	}
}
