<?php
/**
 * Validated Tool Base Class
 *
 * Base class for tools using Symfony Validator.
 *
 * @package WP_MCP_AI
 */

require_once __DIR__ . '/class-wp-mcp-ai-validator-service.php';

/**
 * Class WP_MCP_AI_Validated_Tool
 *
 * Abstract base class for tools that use Symfony Validator for argument validation.
 */
abstract class WP_MCP_AI_Validated_Tool extends WP_MCP_AI_Tool_Base {

	/**
	 * Validator service instance.
	 *
	 * @var WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service
	 */
	protected $validator_service;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->validator_service = \WP_MCP_AI\Validators\WP_MCP_AI_Validator_Service::get_instance();
	}

	/**
	 * Get the validation class name for this tool's arguments.
	 *
	 * Must return a fully qualified class name that defines
	 * validation constraints using Symfony Validator attributes.
	 *
	 * @return string Fully qualified class name.
	 */
	abstract protected function get_validation_class();

	/**
	 * Execute the tool with validated arguments.
	 *
	 * This method receives an object of the validation class type
	 * instead of a raw array, providing type safety and validation.
	 *
	 * @param object $validated_args Validated arguments object.
	 * @param array  $context        Execution context including user_id.
	 * @return array|\WP_Error Tool results or error.
	 */
	abstract protected function execute_validated( $validated_args, $context );

	/**
	 * Execute the tool (implements parent interface).
	 *
	 * Validates arguments before calling execute_validated().
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|\WP_Error Tool results or error.
	 */
	final public function execute( $arguments = array(), $context = array() ) {
		// Get validation class.
		$class_name = $this->get_validation_class();

		if ( ! class_exists( $class_name ) ) {
			return new \WP_Error(
				'invalid_validation_class',
				sprintf(
					/* translators: %s: class name */
					__( 'Validation class not found: %s', 'mcp-ai-wpoos' ),
					$class_name
				)
			);
		}

		// Create validation object.
		$validated = new $class_name();

		// Map arguments to validation object properties.
		foreach ( $arguments as $key => $value ) {
			if ( property_exists( $validated, $key ) ) {
				$validated->$key = $value;
			}
		}

		// Validate.
		$violations = $this->validator_service->validate( $validated );

		if ( ! $this->validator_service->is_valid( $violations ) ) {
			return $this->validator_service->format_errors( $violations );
		}

		// Execute with validated arguments.
		return $this->execute_validated( $validated, $context );
	}
}
