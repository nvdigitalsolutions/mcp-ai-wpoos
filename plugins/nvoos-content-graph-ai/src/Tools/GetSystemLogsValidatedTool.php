<?php
/**
 * Get System Logs (Validated) tool (D8 Cluster 2c port of the base
 * plugin's WP_MCP_AI_Tool_Get_System_Logs_Validated — byte-identical
 * slug, schema, error codes, and envelope; per-mode validation seam).
 *
 * Monolith: delegates to the base validated tool (Symfony Validator
 * service + GetSystemLogsArguments constraints). Standalone: the
 * Symfony Validator dependency lives in the base plugin, so the port
 * re-implements the identical constraint set inline (Type int/bool/
 * array, Range bounds, All-string items) and returns the same
 * `validation_failed` WP_Error shape with field/message violations,
 * then delegates to the ported GetSystemLogsTool.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

/**
 * Retrieves system logs using Symfony Validator argument validation.
 */
class GetSystemLogsValidatedTool extends AbstractAiTool {

	/**
	 * The original get_system_logs tool instance for delegation.
	 *
	 * @var GetSystemLogsTool
	 */
	private $original_tool;

	/**
	 * The base validated tool instance (monolith mode only).
	 *
	 * @var object|null
	 */
	private $base_tool = null;

	public function __construct( \Nvoos\Core\Domain\Contract\ErrorFactoryInterface $errors ) {
		parent::__construct( $errors );
		$this->original_tool = new GetSystemLogsTool( $errors );

		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Tool_Get_System_Logs_Validated' ) ) {
			$this->base_tool = new \WP_MCP_AI_Tool_Get_System_Logs_Validated();
		}
	}

	public function getSlug(): string {
		return 'get_system_logs_validated';
	}

	public function getName(): string {
		return __( 'Get System Logs (Validated)', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Returns recent log entries from WordPress, NV oOS, and plugin log files for diagnostics using Symfony Validator for argument validation.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		// Same schema as the original tool (base-identical delegation).
		return $this->original_tool->getParametersSchema();
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return $this->original_tool->getCapabilityFlags();
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		// Monolith: delegate to the base validated tool (byte-perfect).
		if ( null !== $this->base_tool ) {
			return $this->base_tool->execute( $arguments, $context );
		}

		// Standalone: inline constraint validation (base-identical set).
		$violations = $this->validate_arguments( $arguments );

		if ( ! empty( $violations ) ) {
			return new \WP_Error(
				'validation_failed',
				__( 'Validation failed', 'nvoos-content-graph-ai' ),
				array( 'errors' => $violations )
			);
		}

		// Delegate to the original tool's execute method (base-identical).
		return $this->original_tool->execute( $arguments, $context );
	}

	/**
	 * Re-implement the GetSystemLogsArguments constraint set inline.
	 *
	 * Mirrors the base Symfony constraints exactly: int type + range
	 * for limits, bool for flags, array of strings for lists.
	 *
	 * @param array $arguments Raw arguments.
	 * @return array<int, array{field: string, message: string}>
	 */
	private function validate_arguments( array $arguments ) {
		$violations = array();

		$int_ranges = array(
			'activity_limit'        => array( 1, 50, 'Activity limit must be between {{ min }} and {{ max }}.' ),
			'error_limit'           => array( 1, 50, 'Error limit must be between {{ min }} and {{ max }}.' ),
			'debug_log_limit'       => array( 1, 200, 'Debug log limit must be between {{ min }} and {{ max }}.' ),
			'debug_log_bytes'       => array( 1024, 200000, 'Debug log bytes must be between {{ min }} and {{ max }}.' ),
			'plugin_log_limit'      => array( 1, 20, 'Plugin log limit must be between {{ min }} and {{ max }}.' ),
			'plugin_log_line_limit' => array( 1, 200, 'Plugin log line limit must be between {{ min }} and {{ max }}.' ),
			'plugin_log_bytes'      => array( 1024, 200000, 'Plugin log bytes must be between {{ min }} and {{ max }}.' ),
			'plugin_log_depth'      => array( 0, 5, 'Plugin log depth must be between {{ min }} and {{ max }}.' ),
		);

		foreach ( $int_ranges as $field => $spec ) {
			if ( ! array_key_exists( $field, $arguments ) ) {
				continue;
			}

			$value = $arguments[ $field ];

			if ( ! is_int( $value ) ) {
				// Symfony Type constraint rejects non-int scalars.
				$violations[] = array(
					'field'   => $field,
					'message' => 'This value should be of type int.',
				);
				continue;
			}

			if ( $value < $spec[0] || $value > $spec[1] ) {
				$violations[] = array(
					'field'   => $field,
					'message' => str_replace(
						array( '{{ min }}', '{{ max }}' ),
						array( (string) $spec[0], (string) $spec[1] ),
						$spec[2]
					),
				);
			}
		}

		$bool_fields = array( 'include_debug_log', 'include_plugin_logs' );

		foreach ( $bool_fields as $field ) {
			if ( ! array_key_exists( $field, $arguments ) ) {
				continue;
			}

			if ( ! is_bool( $arguments[ $field ] ) ) {
				$violations[] = array(
					'field'   => $field,
					'message' => 'This value should be of type bool.',
				);
			}
		}

		$list_fields = array( 'activity_types', 'plugin_log_directories' );

		foreach ( $list_fields as $field ) {
			if ( ! array_key_exists( $field, $arguments ) ) {
				continue;
			}

			$value = $arguments[ $field ];

			if ( ! is_array( $value ) ) {
				$violations[] = array(
					'field'   => $field,
					'message' => 'This value should be of type array.',
				);
				continue;
			}

			foreach ( $value as $item ) {
				if ( ! is_string( $item ) ) {
					$violations[] = array(
						'field'   => $field,
						'message' => 'This value should be of type string.',
					);
					break;
				}
			}
		}

		return $violations;
	}
}
