<?php
/**
 * Tool for generating execute() method implementations using AI.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate execute() method implementation for AI tools using AI assistance.
 *
 * This tool takes a tool specification and uses AI to generate production-ready
 * execute() method code with proper error handling, validation, WordPress
 * integration, and best practices.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Tool_Logic implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_tool_logic';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Tool Logic', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate execute() method implementation for AI tools. Uses AI to create production-ready code with error handling, validation, WordPress integration, and security best practices. Supports various tool types and integration patterns.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_description'       => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of what the tool should do', 'mcp-ai-wpoos-pro' ),
				),
				'parameters'             => array(
					'type'        => 'array',
					'description' => __( 'Tool parameter definitions from get_parameters_schema()', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'object' ),
				),
				'wordpress_apis'         => array(
					'type'        => 'array',
					'description' => __( 'WordPress APIs to use (e.g., WP_Query, WP_REST, Custom Post Types)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
				'external_apis'          => array(
					'type'        => 'array',
					'description' => __( 'External APIs to integrate (e.g., OpenAI, Stripe, SendGrid)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
				'return_format'          => array(
					'type'        => 'string',
					'enum'        => array( 'array', 'wp_error', 'mixed' ),
					'description' => __( 'Expected return format', 'mcp-ai-wpoos-pro' ),
					'default'     => 'array',
				),
				'include_validation'     => array(
					'type'        => 'boolean',
					'description' => __( 'Include parameter validation code', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_sanitization'   => array(
					'type'        => 'boolean',
					'description' => __( 'Include input sanitization code', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'include_error_handling' => array(
					'type'        => 'boolean',
					'description' => __( 'Include comprehensive error handling', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'model'                  => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for code generation', 'mcp-ai-wpoos-pro' ),
				),
				'code_style'             => array(
					'type'        => 'string',
					'enum'        => array( 'wordpress', 'psr-2', 'minimal' ),
					'description' => __( 'Coding style to follow', 'mcp-ai-wpoos-pro' ),
					'default'     => 'wordpress',
				),
			),
			'required'   => array( 'tool_description' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'read',
			'requires-capability',
			'consumes-tokens',
			'model-dependent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['tool_description'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Tool description is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$tool_description       = sanitize_textarea_field( $arguments['tool_description'] );
		$parameters             = isset( $arguments['parameters'] ) ? (array) $arguments['parameters'] : array();
		$wordpress_apis         = isset( $arguments['wordpress_apis'] ) ? array_map( 'sanitize_text_field', (array) $arguments['wordpress_apis'] ) : array();
		$external_apis          = isset( $arguments['external_apis'] ) ? array_map( 'sanitize_text_field', (array) $arguments['external_apis'] ) : array();
		$return_format          = isset( $arguments['return_format'] ) ? sanitize_text_field( $arguments['return_format'] ) : 'array';
		$include_validation     = isset( $arguments['include_validation'] ) ? (bool) $arguments['include_validation'] : true;
		$include_sanitization   = isset( $arguments['include_sanitization'] ) ? (bool) $arguments['include_sanitization'] : true;
		$include_error_handling = isset( $arguments['include_error_handling'] ) ? (bool) $arguments['include_error_handling'] : true;
		$code_style             = isset( $arguments['code_style'] ) ? sanitize_text_field( $arguments['code_style'] ) : 'WordPress';

		// Build AI prompt for code generation.
		$prompt = $this->build_code_generation_prompt(
			$tool_description,
			$parameters,
			$wordpress_apis,
			$external_apis,
			$return_format,
			$include_validation,
			$include_sanitization,
			$include_error_handling,
			$code_style
		);

		// Get AI service.
		$ai_service = $this->get_ai_service( $arguments, $context );
		if ( is_wp_error( $ai_service ) ) {
			return array(
				'success' => false,
				'error'   => $ai_service->get_error_message(),
			);
		}

		// Generate code using AI.
		$ai_response = $ai_service->generate( $prompt );

		if ( is_wp_error( $ai_response ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'Code generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$ai_response->get_error_message()
				),
			);
		}

		// Extract and clean PHP code from response.
		$generated_code = $this->extract_php_code( $ai_response );

		if ( is_wp_error( $generated_code ) ) {
			return array(
				'success' => false,
				'error'   => $generated_code->get_error_message(),
			);
		}

		// Validate generated code syntax.
		$validation = $this->validate_php_syntax( $generated_code );

		return array(
			'success'         => true,
			'message'         => __( 'Tool logic generated successfully.', 'mcp-ai-wpoos-pro' ),
			'generated_code'  => $generated_code,
			'code_lines'      => substr_count( $generated_code, "\n" ) + 1,
			'syntax_valid'    => $validation['valid'],
			'syntax_warnings' => $validation['warnings'],
			'next_steps'      => array(
				'1' => __( 'Review generated code for correctness', 'mcp-ai-wpoos-pro' ),
				'2' => __( 'Test with various input scenarios', 'mcp-ai-wpoos-pro' ),
				'3' => __( 'Add tool-specific optimizations', 'mcp-ai-wpoos-pro' ),
			),
		);
	}

	/**
	 * Build AI prompt for code generation.
	 *
	 * @param string $tool_description      Tool description.
	 * @param array  $parameters            Parameters.
	 * @param array  $wordpress_apis        WordPress APIs to use.
	 * @param array  $external_apis         External APIs.
	 * @param string $return_format         Return format.
	 * @param bool   $include_validation    Include validation.
	 * @param bool   $include_sanitization  Include sanitization.
	 * @param bool   $include_error_handling Include error handling.
	 * @param string $code_style            Coding style.
	 * @return string AI prompt.
	 */
	private function build_code_generation_prompt( $tool_description, $parameters, $wordpress_apis, $external_apis, $return_format, $include_validation, $include_sanitization, $include_error_handling, $code_style ) {
		$prompt  = "Generate a WordPress AI tool execute() method implementation.\n\n";
		$prompt .= "Tool Description:\n{$tool_description}\n\n";

		if ( ! empty( $parameters ) ) {
			$prompt .= "Parameters:\n";
			$prompt .= wp_json_encode( $parameters, JSON_PRETTY_PRINT ) . "\n\n";
		}

		$prompt .= "Requirements:\n";
		$prompt .= "- Follow WordPress coding standards\n";
		$prompt .= "- Return format: {$return_format}\n";

		if ( $include_validation ) {
			$prompt .= "- Include parameter validation\n";
		}

		if ( $include_sanitization ) {
			$prompt .= "- Include input sanitization (sanitize_text_field, etc.)\n";
		}

		if ( $include_error_handling ) {
			$prompt .= "- Include comprehensive error handling\n";
		}

		if ( ! empty( $wordpress_apis ) ) {
			$prompt .= "\nUse these WordPress APIs:\n";
			foreach ( $wordpress_apis as $api ) {
				$prompt .= "- {$api}\n";
			}
		}

		if ( ! empty( $external_apis ) ) {
			$prompt .= "\nIntegrate these external APIs:\n";
			foreach ( $external_apis as $api ) {
				$prompt .= "- {$api}\n";
			}
		}

		$prompt .= "\nGenerate only the execute() method body. Include:\n";
		$prompt .= "1. Parameter validation\n";
		$prompt .= "2. Input sanitization\n";
		$prompt .= "3. Main logic implementation\n";
		$prompt .= "4. Error handling\n";
		$prompt .= "5. Success response array\n\n";
		$prompt .= 'Output only PHP code, no markdown formatting.';

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
		// Try to extract from PHP code blocks.
		$php_pattern = '/```php\s*(.*?)\s*```/s';
		if ( preg_match( $php_pattern, $response, $matches ) ) {
			return trim( $matches[1] );
		}

		// Try generic code blocks.
		$code_pattern = '/```\s*(.*?)\s*```/s';
		if ( preg_match( $code_pattern, $response, $matches ) ) {
			return trim( $matches[1] );
		}

		// If no code blocks, assume entire response is code.
		$trimmed = trim( $response );
		if ( strpos( $trimmed, 'public function execute' ) !== false ) {
			return $trimmed;
		}

		return new WP_Error(
			'wp_mcp_ai_no_code_found',
			__( 'Could not extract PHP code from AI response.', 'mcp-ai-wpoos-pro' )
		);
	}

	/**
	 * Validate PHP syntax.
	 *
	 * @param string $code PHP code to validate.
	 * @return array Validation result.
	 */
	private function validate_php_syntax( $code ) {
		$result = array(
			'valid'    => true,
			'warnings' => array(),
		);

		// Basic syntax checks.
		if ( strpos( $code, 'public function execute' ) === false ) {
			$result['warnings'][] = __( 'Code does not appear to be an execute() method.', 'mcp-ai-wpoos-pro' );
		}

		// Check for return statement.
		if ( strpos( $code, 'return' ) === false ) {
			$result['warnings'][] = __( 'No return statement found.', 'mcp-ai-wpoos-pro' );
		}

		// Check for balanced braces.
		$open_braces  = substr_count( $code, '{' );
		$close_braces = substr_count( $code, '}' );
		if ( $open_braces !== $close_braces ) {
			$result['valid']      = false;
			$result['warnings'][] = __( 'Unbalanced braces detected.', 'mcp-ai-wpoos-pro' );
		}

		return $result;
	}
}
