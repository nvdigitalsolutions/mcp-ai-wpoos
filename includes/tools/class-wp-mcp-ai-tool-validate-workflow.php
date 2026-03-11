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
		$workflow_file = isset( $arguments['workflow_file'] ) ? sanitize_text_field( $arguments['workflow_file'] ) : '';
		$strict        = isset( $arguments['strict'] ) ? (bool) $arguments['strict'] : false;

		// Security: Validate and read the file safely.
		$content_or_error = $this->read_workflow_file( $workflow_file );

		if ( is_wp_error( $content_or_error ) ) {
			return array(
				'valid'  => false,
				'errors' => array( $content_or_error->get_error_message() ),
			);
		}

		$content = $content_or_error;

		// Parse YAML or JSON using the already-validated extension.
		$extension = strtolower( pathinfo( $workflow_file, PATHINFO_EXTENSION ) );

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
	 * Safely read a workflow file after validating the path.
	 *
	 * @param string $file_path Path to workflow file provided by the caller.
	 * @return string|WP_Error File contents on success, WP_Error on failure.
	 */
	private function read_workflow_file( $file_path ) {
		if ( empty( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_path', __( 'No workflow file path provided.', 'mcp-ai-wpoos' ) );
		}

		// Security: Validate file extension before resolving the path so we
		// never call realpath() on a path that is clearly invalid.
		$allowed_extensions = array( 'yml', 'yaml', 'json' );
		$file_extension     = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_file_type',
				__( 'Only workflow files with .yml, .yaml, or .json extensions are allowed.', 'mcp-ai-wpoos' )
			);
		}

		// Security: Resolve the real path to prevent directory traversal attacks
		// (e.g. ../../wp-config.php). realpath() returns false when the file does
		// not exist, permissions prevent resolution, or the path contains null bytes.
		$resolved = realpath( $file_path );

		if ( false === $resolved ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_found',
				__( 'Workflow file not found or is not accessible.', 'mcp-ai-wpoos' )
			);
		}

		// Security: Restrict file access to safe directories.
		$path_check = $this->is_path_in_safe_directory( $resolved );
		if ( is_wp_error( $path_check ) ) {
			return $path_check;
		}

		// Security: Ensure the file is readable.
		if ( ! is_readable( $resolved ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_readable', __( 'Workflow file is not readable.', 'mcp-ai-wpoos' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for local file reading.
		$content = file_get_contents( $resolved );

		if ( false === $content ) {
			return new WP_Error( 'wp_mcp_ai_file_read_failed', __( 'Failed to read workflow file.', 'mcp-ai-wpoos' ) );
		}

		return $content;
	}

	/**
	 * Check whether a resolved file path is within an allowed directory.
	 *
	 * @param string $file_path Resolved (realpath) absolute file path.
	 * @return true|WP_Error True if path is safe, WP_Error otherwise.
	 */
	private function is_path_in_safe_directory( $file_path ) {
		$normalized_path = wp_normalize_path( $file_path );

		$safe_directories = array();

		// Allow WordPress uploads directory.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$safe_directories[] = wp_normalize_path( $upload_dir['basedir'] );
		}

		// Allow WordPress content directory.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$safe_directories[] = wp_normalize_path( WP_CONTENT_DIR );
		}

		/**
		 * Filter the list of directories from which workflow files may be read.
		 *
		 * @param string[] $safe_directories Absolute directory paths.
		 */
		$safe_directories = apply_filters( 'wp_mcp_ai_validate_workflow_safe_directories', $safe_directories );

		foreach ( $safe_directories as $safe_dir ) {
			// Append a trailing slash before comparison so that a directory named
			// "uploads-evil" cannot satisfy a check for "uploads". For example,
			// "/uploads-evil/file.yml" does NOT start with "/uploads/".
			if ( 0 === strpos( $normalized_path, trailingslashit( $safe_dir ) ) ) {
				return true;
			}
		}

		return new WP_Error(
			'wp_mcp_ai_unsafe_file_path',
			__( 'Workflow file path is not within allowed directories. Files must be in the WordPress uploads or content directory.', 'mcp-ai-wpoos' )
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
