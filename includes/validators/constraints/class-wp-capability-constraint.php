<?php
/**
 * WordPress Capability Constraint
 *
 * Custom Symfony Validator constraint for WordPress capability checks.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Validators\Constraints;

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
	 * @param mixed $options Options array or capability string.
	 * @param array $groups  Validation groups.
	 * @param mixed $payload Payload.
	 */
	public function __construct( $options = null, array $groups = null, $payload = null ) {
		// Support both attribute syntax and annotation syntax.
		if ( is_string( $options ) ) {
			$options = array( 'capability' => $options );
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
