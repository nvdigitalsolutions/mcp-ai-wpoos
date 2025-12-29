<?php
/**
 * Validator Service
 *
 * Provides Symfony Validator integration for WP oOS.
 *
 * @package WP_MCP_AI
 */

namespace WP_MCP_AI\Validators;

use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;
use Symfony\Component\Validator\ConstraintViolationListInterface;

/**
 * Class WP_MCP_AI_Validator_Service
 *
 * Wraps Symfony Validator for WordPress plugin use.
 */
class WP_MCP_AI_Validator_Service {

	/**
	 * Symfony validator instance.
	 *
	 * @var ValidatorInterface
	 */
	private $validator;

	/**
	 * Singleton instance.
	 *
	 * @var WP_MCP_AI_Validator_Service|null
	 */
	private static $instance = null;

	/**
	 * Get singleton instance.
	 *
	 * @return WP_MCP_AI_Validator_Service
	 */
	public static function get_instance() {
		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$this->validator = Validation::createValidatorBuilder()
			->enableAttributeMapping()
			->getValidator();
	}

	/**
	 * Validate an object.
	 *
	 * @param object $object Object to validate.
	 * @param array  $groups Validation groups.
	 * @return ConstraintViolationListInterface
	 */
	public function validate( $object, array $groups = array() ) {
		return $this->validator->validate( $object, null, $groups );
	}

	/**
	 * Format validation errors as WP_Error.
	 *
	 * @param ConstraintViolationListInterface $violations Validation violations.
	 * @return \WP_Error
	 */
	public function format_errors( ConstraintViolationListInterface $violations ) {
		$errors = array();
		foreach ( $violations as $violation ) {
			$errors[] = array(
				'field'   => $violation->getPropertyPath(),
				'message' => $violation->getMessage(),
			);
		}

		return new \WP_Error(
			'validation_failed',
			__( 'Validation failed', 'mcp-ai-wpoos' ),
			array( 'errors' => $errors )
		);
	}

	/**
	 * Check if validation passed (no violations).
	 *
	 * @param ConstraintViolationListInterface $violations Validation violations.
	 * @return bool
	 */
	public function is_valid( ConstraintViolationListInterface $violations ) {
		return 0 === count( $violations );
	}
}
