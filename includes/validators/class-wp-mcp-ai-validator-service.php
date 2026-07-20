<?php
/**
 * Validator Service
 *
 * Provides Symfony Validator integration for NV oOS.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

namespace WP_MCP_AI\Validators;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-identity-translator.php';

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
	 * @return WP_MCP_AI_Validator_Service|null
	 */
	public static function get_instance() {
		// Check if Symfony Validator is available.
		if ( ! class_exists( 'Symfony\Component\Validator\Validation' ) ) {
			return null;
		}

		if ( null === self::$instance ) {
			self::$instance = new self();
		}
		return self::$instance;
	}

	/**
	 * Constructor.
	 */
	private function __construct() {
		$builder = Validation::createValidatorBuilder()
			->setTranslator( new WP_MCP_AI_Identity_Translator() );

		// enableAttributeMapping() was added in Symfony 6.1.
		// Symfony 5.4 does not have this method, so guard with method_exists.
		if ( method_exists( $builder, 'enableAttributeMapping' ) ) {
			$builder->enableAttributeMapping();
		}

		$this->validator = $builder->getValidator();
	}

	/**
	 * Exclude non-serializable properties when serialized.
	 *
	 * WP_MCP_AI_Validator_Service is a singleton that lazily builds a Symfony
	 * Validator on first use. Persisting the validator object graph (which
	 * may include closures, reflection metadata, and other non-serializable
	 * internals) is unnecessary and error-prone. Returning an empty array
	 * means the serialized form is a bare class token; __wakeup() then
	 * reconstitutes a fully functional validator from scratch. Callers that
	 * unserialize the service get a functionally equivalent instance — the
	 * singleton reference in self::$instance is intentionally NOT restored
	 * here because the deserialized copy is used directly.
	 *
	 * @return array
	 */
	public function __sleep() {
		return array();
	}

	/**
	 * Re-initialize the validator after unserialization.
	 *
	 * @return void
	 */
	public function __wakeup() {
		$builder = Validation::createValidatorBuilder()
			->setTranslator( new WP_MCP_AI_Identity_Translator() );

		if ( method_exists( $builder, 'enableAttributeMapping' ) ) {
			$builder->enableAttributeMapping();
		}

		$this->validator = $builder->getValidator();
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
