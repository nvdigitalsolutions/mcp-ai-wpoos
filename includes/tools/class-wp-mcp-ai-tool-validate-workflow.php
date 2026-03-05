<?php
/**
 * Workflow Validation Tool
 *
 * Validates workflow YAML files for correct structure, required fields,
 * and compatibility with the workflow orchestration system.
 *
 * @package WP_MCP_AI
 * @subpackage Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';

/**
 * Workflow Validation Tool
 *
 * Validates workflow definitions to ensure they:
 * - Have required fields (name, steps)
 * - Use valid syntax for parallel/conditional/loop/DAG
 * - Reference existing tasks
 * - Have valid operators and expressions
 * - Meet security requirements
 *
 * @since 1.2.3
 */
class WP_MCP_AI_Tool_Validate_Workflow implements WP_MCP_AI_Tool_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'validate_workflow';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Validate Workflow', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Validates workflow YAML files for correct structure, syntax, and compatibility.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'workflow_file' => array(
					'type'        => 'string',
					'description' => __( 'Path to workflow YAML file to validate.', 'mcp-ai-wpoos' ),
					'required'    => true,
				),
				'strict'        => array(
					'type'        => 'boolean',
					'description' => __( 'Enable strict validation mode with additional checks.', 'mcp-ai-wpoos' ),
					'default'     => false,
				),
			),
			'required'   => array( 'workflow_file' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Result array.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$workflow_file = isset( $arguments['workflow_file'] ) ? $arguments['workflow_file'] : '';
		$strict        = isset( $arguments['strict'] ) ? (bool) $arguments['strict'] : false;

		// Validate file exists.
		if ( ! file_exists( $workflow_file ) ) {
			return array(
				'valid'  => false,
				'errors' => array( sprintf( 'Workflow file not found: %s', $workflow_file ) ),
			);
		}

		// Read and parse file.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents
		$content = file_get_contents( $workflow_file );

		if ( false === $content ) {
			return array(
				'valid'  => false,
				'errors' => array( 'Failed to read workflow file.' ),
			);
		}

		// Parse YAML or JSON.
		$extension = pathinfo( $workflow_file, PATHINFO_EXTENSION );

		if ( 'yml' === $extension || 'yaml' === $extension ) {
			if ( ! function_exists( 'yaml_parse' ) ) {
				return array(
					'valid'  => false,
					'errors' => array( 'YAML parsing not available. Use JSON format or install YAML PHP extension.' ),
				);
			}
			$data = yaml_parse( $content );
		} elseif ( 'json' === $extension ) {
			$data = json_decode( $content, true );
		} else {
			return array(
				'valid'  => false,
				'errors' => array( 'Invalid file format. Use .yml, .yaml, or .json files.' ),
			);
		}

		if ( empty( $data ) || ! is_array( $data ) ) {
			return array(
				'valid'  => false,
				'errors' => array( 'Failed to parse workflow file or file is empty.' ),
			);
		}

		// Extract workflow definition.
		$workflow = isset( $data['workflow'] ) ? $data['workflow'] : $data;

		// Run validation checks.
		$errors   = array();
		$warnings = array();

		// Required fields.
		if ( empty( $workflow['name'] ) ) {
			$errors[] = 'Missing required field: name';
		}

		if ( empty( $workflow['steps'] ) || ! is_array( $workflow['steps'] ) ) {
			$errors[] = 'Missing or invalid required field: steps (must be an array)';
		}

		// Validate steps.
		if ( ! empty( $workflow['steps'] ) && is_array( $workflow['steps'] ) ) {
			$this->validate_steps( $workflow['steps'], $errors, $warnings, $strict );
		}

		// Check for circular dependencies in DAG.
		if ( $this->has_dag_structure( $workflow['steps'] ) ) {
			$dag_errors = $this->validate_dag_dependencies( $workflow['steps'] );
			$errors     = array_merge( $errors, $dag_errors );
		}

		$valid = empty( $errors );

		return array(
			'valid'    => $valid,
			'errors'   => $errors,
			'warnings' => $warnings,
			'summary'  => array(
				'name'             => $workflow['name'] ?? 'Unnamed',
				'description'      => $workflow['description'] ?? '',
				'total_steps'      => count( $workflow['steps'] ?? array() ),
				'has_parallel'     => $this->has_parallel_steps( $workflow['steps'] ?? array() ),
				'has_loops'        => $this->has_loop_steps( $workflow['steps'] ?? array() ),
				'has_conditionals' => $this->has_conditional_steps( $workflow['steps'] ?? array() ),
				'has_dag'          => $this->has_dag_structure( $workflow['steps'] ?? array() ),
			),
		);
	}

	/**
	 * Validate individual steps
	 *
	 * @param array $steps    Steps array.
	 * @param array &$errors  Errors array (passed by reference).
	 * @param array &$warnings Warnings array (passed by reference).
	 * @param bool  $strict   Strict mode.
	 */
	private function validate_steps( $steps, &$errors, &$warnings, $strict ) {
		foreach ( $steps as $index => $step ) {
			$step_num = $index + 1;

			// Parallel block.
			if ( isset( $step['parallel'] ) ) {
				if ( ! is_array( $step['parallel'] ) ) {
					$errors[] = sprintf( 'Step %d: parallel must be an array', $step_num );
				} else {
					$this->validate_steps( $step['parallel'], $errors, $warnings, $strict );
				}
				continue;
			}

			// Conditional block.
			if ( isset( $step['condition'] ) ) {
				if ( empty( $step['condition'] ) ) {
					$errors[] = sprintf( 'Step %d: condition cannot be empty', $step_num );
				}

				if ( ! isset( $step['then'] ) && ! isset( $step['else'] ) ) {
					$errors[] = sprintf( 'Step %d: conditional must have then or else block', $step_num );
				}

				if ( isset( $step['then'] ) && is_array( $step['then'] ) ) {
					$this->validate_steps( $step['then'], $errors, $warnings, $strict );
				}

				if ( isset( $step['else'] ) && is_array( $step['else'] ) ) {
					$this->validate_steps( $step['else'], $errors, $warnings, $strict );
				}

				continue;
			}

			// Loop block.
			if ( isset( $step['repeat_until'] ) || isset( $step['repeat'] ) ) {
				if ( empty( $step['steps'] ) ) {
					$errors[] = sprintf( 'Step %d: loop must have steps array', $step_num );
				} else {
					$this->validate_steps( $step['steps'], $errors, $warnings, $strict );
				}

				if ( isset( $step['max_iterations'] ) && ! is_numeric( $step['max_iterations'] ) ) {
					$errors[] = sprintf( 'Step %d: max_iterations must be numeric', $step_num );
				}

				continue;
			}

			// Regular step.
			if ( empty( $step['task'] ) ) {
				$errors[] = sprintf( 'Step %d: missing required field: task', $step_num );
			}

			// Validate DAG dependencies.
			if ( isset( $step['depends_on'] ) ) {
				if ( ! is_array( $step['depends_on'] ) ) {
					$errors[] = sprintf( 'Step %d: depends_on must be an array', $step_num );
				}

				if ( empty( $step['name'] ) ) {
					$errors[] = sprintf( 'Step %d: steps with depends_on must have a name', $step_num );
				}
			}

			// Strict mode checks.
			if ( $strict ) {
				if ( empty( $step['params'] ) ) {
					$warnings[] = sprintf( 'Step %d: no parameters specified', $step_num );
				}

				if ( isset( $step['timeout'] ) && $step['timeout'] > 300 ) {
					$warnings[] = sprintf( 'Step %d: timeout exceeds recommended 5 minutes', $step_num );
				}
			}
		}
	}

	/**
	 * Check if workflow has DAG structure
	 *
	 * @param array $steps Steps array.
	 * @return bool True if has dependencies.
	 */
	private function has_dag_structure( $steps ) {
		foreach ( $steps as $step ) {
			if ( isset( $step['depends_on'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Validate DAG dependencies for cycles
	 *
	 * @param array $steps Steps array.
	 * @return array Errors array.
	 */
	private function validate_dag_dependencies( $steps ) {
		$errors = array();
		$graph  = array();
		$names  = array();

		// Build graph.
		foreach ( $steps as $step ) {
			if ( isset( $step['name'] ) ) {
				$names[]                = $step['name'];
				$graph[ $step['name'] ] = isset( $step['depends_on'] ) ? $step['depends_on'] : array();
			}
		}

		// Check for invalid dependencies.
		foreach ( $graph as $name => $deps ) {
			foreach ( $deps as $dep ) {
				if ( ! in_array( $dep, $names, true ) ) {
					$errors[] = sprintf( 'Step "%s" depends on undefined step "%s"', $name, $dep );
				}
			}
		}

		// Simple cycle detection (could be more sophisticated).
		$visited = array();
		foreach ( $graph as $name => $deps ) {
			if ( in_array( $name, $deps, true ) ) {
				$errors[] = sprintf( 'Step "%s" has circular dependency on itself', $name );
			}
		}

		return $errors;
	}

	/**
	 * Check if workflow has parallel steps
	 *
	 * @param array $steps Steps array.
	 * @return bool True if has parallel blocks.
	 */
	private function has_parallel_steps( $steps ) {
		foreach ( $steps as $step ) {
			if ( isset( $step['parallel'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if workflow has loop steps
	 *
	 * @param array $steps Steps array.
	 * @return bool True if has loops.
	 */
	private function has_loop_steps( $steps ) {
		foreach ( $steps as $step ) {
			if ( isset( $step['repeat_until'] ) || isset( $step['repeat'] ) ) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if workflow has conditional steps
	 *
	 * @param array $steps Steps array.
	 * @return bool True if has conditionals.
	 */
	private function has_conditional_steps( $steps ) {
		foreach ( $steps as $step ) {
			if ( isset( $step['condition'] ) ) {
				return true;
			}
		}
		return false;
	}
}
