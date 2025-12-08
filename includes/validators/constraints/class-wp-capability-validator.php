<?php
/**
 * WordPress Capability Validator
 *
 * Validator for WordPress capability constraint.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Validators\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;

/**
 * Class WPCapabilityValidator
 *
 * Validates WordPress capability requirements.
 */
class WPCapabilityValidator extends ConstraintValidator {

	/**
	 * Validate the value.
	 *
	 * @param mixed      $value      The value to validate.
	 * @param Constraint $constraint The constraint.
	 * @return void
	 */
	public function validate( $value, Constraint $constraint ) {
		if ( ! $constraint instanceof WPCapability ) {
			throw new UnexpectedTypeException( $constraint, WPCapability::class );
		}

		// Check if current user has the required capability.
		if ( ! current_user_can( $constraint->capability ) ) {
			$this->context->buildViolation( $constraint->message )
				->setParameter( '{{ capability }}', $constraint->capability )
				->addViolation();
		}
	}
}
