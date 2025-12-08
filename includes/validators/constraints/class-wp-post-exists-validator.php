<?php
/**
 * WordPress Post Exists Validator
 *
 * Validator for WordPress post existence constraint.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Validators\Constraints;

use Symfony\Component\Validator\Constraint;
use Symfony\Component\Validator\ConstraintValidator;
use Symfony\Component\Validator\Exception\UnexpectedTypeException;
use Symfony\Component\Validator\Exception\UnexpectedValueException;

/**
 * Class WPPostExistsValidator
 *
 * Validates that a WordPress post exists.
 */
class WPPostExistsValidator extends ConstraintValidator {

	/**
	 * Validate the value.
	 *
	 * @param mixed      $value      The value to validate (post ID).
	 * @param Constraint $constraint The constraint.
	 * @return void
	 */
	public function validate( $value, Constraint $constraint ) {
		if ( ! $constraint instanceof WPPostExists ) {
			throw new UnexpectedTypeException( $constraint, WPPostExists::class );
		}

		// Null and empty values are valid (use NotBlank for required fields).
		if ( null === $value || '' === $value ) {
			return;
		}

		if ( ! is_numeric( $value ) && ! is_int( $value ) ) {
			throw new UnexpectedValueException( $value, 'integer' );
		}

		$post_id = absint( $value );
		$post    = get_post( $post_id );

		// Check if post exists.
		if ( ! $post ) {
			$this->context->buildViolation( $constraint->message )
				->setParameter( '{{ post_id }}', $post_id )
				->addViolation();
			return;
		}

		// Check post type if specified.
		if ( null !== $constraint->post_type && $post->post_type !== $constraint->post_type ) {
			$this->context->buildViolation( 'Post {{ post_id }} is not of type {{ post_type }}.' )
				->setParameter( '{{ post_id }}', $post_id )
				->setParameter( '{{ post_type }}', $constraint->post_type )
				->addViolation();
		}
	}
}
