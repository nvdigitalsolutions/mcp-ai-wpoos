<?php
/**
 * Tool for generating PHPUnit tests for AI tools.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate comprehensive PHPUnit tests for AI tools.
 *
 * This tool analyzes tool implementations and generates complete test suites
 * including unit tests, integration tests, edge cases, and mocking for
 * external dependencies.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Tool_Tests implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_tool_tests';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Tool Tests', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate comprehensive PHPUnit test suites for AI tools. Creates unit tests, integration tests, edge case coverage, and mocking for external dependencies. Follows WordPress and PHPUnit best practices.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_class'        => array(
					'type'        => 'string',
					'description' => __( 'Tool class name to generate tests for', 'mcp-ai-wpoos-pro' ),
				),
				'tool_file'         => array(
					'type'        => 'string',
					'description' => __( 'Path to tool file (for analysis)', 'mcp-ai-wpoos-pro' ),
				),
				'test_types'        => array(
					'type'        => 'array',
					'description' => __( 'Types of tests to generate', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'unit', 'integration', 'edge-cases', 'security', 'performance' ),
					),
					'default'     => array( 'unit', 'integration', 'edge-cases' ),
				),
				'mock_dependencies' => array(
					'type'        => 'boolean',
					'description' => __( 'Generate mocks for external dependencies', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'coverage_goal'     => array(
					'type'        => 'integer',
					'description' => __( 'Target code coverage percentage', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 50,
					'maximum'     => 100,
					'default'     => 80,
				),
				'output_path'       => array(
					'type'        => 'string',
					'description' => __( 'Custom output path for test file', 'mcp-ai-wpoos-pro' ),
				),
				'model'             => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for test generation', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'tool_class' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'requires-capability',
			'consumes-tokens',
			'model-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['tool_class'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Tool class name is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$tool_class        = sanitize_text_field( $arguments['tool_class'] );
		$tool_file_raw     = isset( $arguments['tool_file'] ) ? sanitize_text_field( $arguments['tool_file'] ) : '';
		$test_types        = isset( $arguments['test_types'] ) ? array_map( 'sanitize_text_field', (array) $arguments['test_types'] ) : array( 'unit', 'integration', 'edge-cases' );
		$mock_dependencies = isset( $arguments['mock_dependencies'] ) ? (bool) $arguments['mock_dependencies'] : true;
		$coverage_goal     = isset( $arguments['coverage_goal'] ) ? absint( $arguments['coverage_goal'] ) : 80;

		// Load tool code for analysis.
		$tool_code = '';
		if ( ! empty( $tool_file_raw ) ) {
			// Security: Resolve canonical path and restrict to the WordPress content
			// directory to prevent reading arbitrary server files.
			$resolved_tool = realpath( $tool_file_raw );
			if ( false !== $resolved_tool &&
				0 === strpos( wp_normalize_path( $resolved_tool ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local PHP tool file; path validated against WP_CONTENT_DIR.
				$tool_code = file_get_contents( $resolved_tool );
			}
		}

		// Analyze tool structure.
		$tool_analysis = $this->analyze_tool( $tool_class, $tool_code );

		// Build test generation prompt.
		$prompt = $this->build_test_generation_prompt( $tool_class, $tool_analysis, $test_types, $mock_dependencies, $coverage_goal );

		// Get AI service.
		$ai_service = $this->get_ai_service( $arguments, $context );
		if ( is_wp_error( $ai_service ) ) {
			return array(
				'success' => false,
				'error'   => $ai_service->get_error_message(),
			);
		}

		// Generate tests using AI.
		$ai_response = $ai_service->generate( $prompt );

		if ( is_wp_error( $ai_response ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'Test generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$ai_response->get_error_message()
				),
			);
		}

		// Extract test code.
		$test_code = $this->extract_php_code( $ai_response );

		if ( is_wp_error( $test_code ) ) {
			return array(
				'success' => false,
				'error'   => $test_code->get_error_message(),
			);
		}

		// Determine output path.
		$output_path = $this->determine_output_path( $tool_class, $arguments );

		// Propagate path validation errors.
		if ( is_wp_error( $output_path ) ) {
			return array(
				'success' => false,
				'error'   => $output_path->get_error_message(),
			);
		}

		// Write test file.
		$bytes_written = file_put_contents( $output_path, $test_code );

		if ( false === $bytes_written ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to write test file.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Count test methods.
		$test_count = substr_count( $test_code, 'public function test_' );

		return array(
			'success'    => true,
			'message'    => __( 'Test suite generated successfully.', 'mcp-ai-wpoos-pro' ),
			'test_file'  => $output_path,
			'test_count' => $test_count,
			'test_types' => $test_types,
			'file_size'  => $bytes_written,
			'next_steps' => array(
				'1' => __( 'Run: vendor/bin/phpunit ' . $output_path, 'mcp-ai-wpoos-pro' ),
				'2' => __( 'Review test coverage', 'mcp-ai-wpoos-pro' ),
				'3' => __( 'Add tool-specific assertions', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Analyze tool structure and methods.
	 *
	 * @param string $tool_class Tool class name.
	 * @param string $tool_code  Tool source code.
	 * @return array Analysis results.
	 */
	private function analyze_tool( $tool_class, $tool_code ) {
		$analysis = array(
			'class_name'   => $tool_class,
			'methods'      => array(),
			'parameters'   => array(),
			'dependencies' => array(),
		);

		if ( empty( $tool_code ) ) {
			return $analysis;
		}

		// Extract methods.
		preg_match_all( '/public function (\w+)\(/', $tool_code, $method_matches );
		if ( ! empty( $method_matches[1] ) ) {
			$analysis['methods'] = $method_matches[1];
		}

		// Check for external dependencies.
		if ( strpos( $tool_code, 'wp_remote_' ) !== false ) {
			$analysis['dependencies'][] = 'wp_http';
		}
		if ( strpos( $tool_code, 'WP_Query' ) !== false ) {
			$analysis['dependencies'][] = 'wp_query';
		}

		return $analysis;
	}

	/**
	 * Build test generation prompt.
	 *
	 * @param string $tool_class       Tool class name.
	 * @param array  $tool_analysis    Tool analysis results.
	 * @param array  $test_types       Test types to generate.
	 * @param bool   $mock_dependencies Mock dependencies flag.
	 * @param int    $coverage_goal    Coverage goal percentage.
	 * @return string AI prompt.
	 */
	private function build_test_generation_prompt( $tool_class, $tool_analysis, $test_types, $mock_dependencies, $coverage_goal ) {
		$prompt = "Generate a comprehensive PHPUnit test suite for WordPress AI tool: {$tool_class}\n\n";

		$prompt .= "Test Requirements:\n";
		$prompt .= "- Extend WP_UnitTestCase\n";
		$prompt .= "- Target {$coverage_goal}% code coverage\n";
		$prompt .= "- Follow WordPress testing best practices\n";

		if ( ! empty( $tool_analysis['methods'] ) ) {
			$prompt .= "\nMethods to test:\n";
			foreach ( $tool_analysis['methods'] as $method ) {
				$prompt .= "- {$method}()\n";
			}
		}

		$prompt .= "\nInclude these test types:\n";
		foreach ( $test_types as $type ) {
			switch ( $type ) {
				case 'unit':
					$prompt .= "- Unit tests for individual methods\n";
					break;
				case 'integration':
					$prompt .= "- Integration tests for end-to-end workflows\n";
					break;
				case 'edge-cases':
					$prompt .= "- Edge case tests (empty input, invalid data, etc.)\n";
					break;
				case 'security':
					$prompt .= "- Security tests (capability checks, sanitization)\n";
					break;
				case 'performance':
					$prompt .= "- Performance tests (timing assertions)\n";
					break;
			}
		}

		if ( $mock_dependencies && ! empty( $tool_analysis['dependencies'] ) ) {
			$prompt .= "\nMock these dependencies:\n";
			foreach ( $tool_analysis['dependencies'] as $dep ) {
				$prompt .= "- {$dep}\n";
			}
		}

		$prompt .= "\nGenerate complete test class with:\n";
		$prompt .= "1. setUp() and tearDown() methods\n";
		$prompt .= "2. Test methods with assertions\n";
		$prompt .= "3. Helper methods if needed\n";
		$prompt .= "4. PHPDoc blocks\n\n";
		$prompt .= 'Output only PHP code.';

		return $prompt;
	}

	/**
	 * Get AI service instance.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return object|WP_Error AI service or error.
	 */
	private function get_ai_service( $arguments, $context ) {
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';

		if ( empty( $model ) && isset( $context['assistant_model'] ) ) {
			$model = $context['assistant_model'];
		}

		if ( ! class_exists( 'WP_MCP_AI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_service_unavailable',
				__( 'AI service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		return new WP_MCP_AI_Client( $model );
	}

	/**
	 * Extract PHP code from AI response.
	 *
	 * @param string $response AI response.
	 * @return string|WP_Error PHP code or error.
	 */
	private function extract_php_code( $response ) {
		$php_pattern = '/```php\s*(.*?)\s*```/s';
		if ( preg_match( $php_pattern, $response, $matches ) ) {
			return trim( $matches[1] );
		}

		$code_pattern = '/```\s*(.*?)\s*```/s';
		if ( preg_match( $code_pattern, $response, $matches ) ) {
			return trim( $matches[1] );
		}

		if ( strpos( $response, 'class Test_' ) !== false ) {
			return trim( $response );
		}

		return new WP_Error(
			'wp_mcp_ai_no_code_found',
			__( 'Could not extract test code from AI response.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Determine output path for test file.
	 *
	 * @param string $tool_class Tool class name.
	 * @param array  $arguments  Tool arguments.
	 * @return string Output path.
	 */
	private function determine_output_path( $tool_class, $arguments ) {
		if ( isset( $arguments['output_path'] ) && ! empty( $arguments['output_path'] ) ) {
			$output_path = sanitize_text_field( $arguments['output_path'] );

			// Security: Restrict to the WordPress content directory to prevent
			// writing PHP files to arbitrary server paths.
			$resolved = realpath( dirname( $output_path ) );
			if ( false === $resolved ) {
				return new WP_Error(
					'invalid_output_path',
					__( 'Invalid output path: parent directory does not exist.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 0 !== strpos( wp_normalize_path( $resolved ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				return new WP_Error(
					'invalid_output_path',
					__( 'Output path must be within the WordPress content directory.', 'mcp-ai-wpoos-pro' )
				);
			}

			return $output_path;
		}

		$test_name = 'test-' . str_replace( '_', '-', strtolower( str_replace( 'WP_MCP_AI_Tool_', '', $tool_class ) ) ) . '.php';
		return WP_MCP_AI_PRO_PATH . 'tests/tools/' . $test_name;
	}
}
