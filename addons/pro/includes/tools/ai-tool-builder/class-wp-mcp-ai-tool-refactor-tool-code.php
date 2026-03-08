<?php
/**
 * Tool for refactoring and improving existing tool code.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Refactor and improve existing AI tool code with AI assistance.
 *
 * This tool analyzes existing tool implementations and suggests or applies
 * improvements including performance optimizations, security enhancements,
 * code style fixes, and WordPress best practices.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Refactor_Tool_Code implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'refactor_tool_code';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Refactor Tool Code', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Refactor and improve existing AI tool code. Analyzes code for performance, security, style issues and suggests or applies improvements. Supports WordPress coding standards, optimization, and best practices.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'code'                   => array(
					'type'        => 'string',
					'description' => __( 'Existing tool code to refactor', 'mcp-ai-wpoos-pro' ),
				),
				'file_path'              => array(
					'type'        => 'string',
					'description' => __( 'Path to tool file (alternative to providing code directly)', 'mcp-ai-wpoos-pro' ),
				),
				'refactor_goals'         => array(
					'type'        => 'array',
					'description' => __( 'Specific refactoring goals (performance, security, readability, etc.)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'performance', 'security', 'readability', 'standards', 'dry', 'error-handling' ),
					),
					'default'     => array( 'readability', 'standards' ),
				),
				'apply_changes'          => array(
					'type'        => 'boolean',
					'description' => __( 'Apply changes to file (requires file_path). Default is false (suggestions only)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'preserve_functionality' => array(
					'type'        => 'boolean',
					'description' => __( 'Ensure refactoring preserves original functionality', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'model'                  => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for analysis and refactoring', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array(),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',
			'write',
			'state-changing',
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
		$code          = '';
		$file_path     = '';

		if ( ! empty( $arguments['code'] ) ) {
			$code = $arguments['code'];
		} elseif ( ! empty( $arguments['file_path'] ) ) {
			$file_path = sanitize_text_field( $arguments['file_path'] );

			// Security: Validate PHP file extension.
			$extension = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );
			if ( 'php' !== $extension ) {
				return array(
					'success' => false,
					'error'   => __( 'Only PHP files (.php) are allowed.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Security: Resolve canonical path to prevent directory traversal attacks.
			$resolved = realpath( $file_path );
			if ( false === $resolved ) {
				return array(
					'success' => false,
					'error'   => __( 'File not found or not accessible.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Security: Restrict to the WordPress content directory (plugins, themes, etc.).
			if ( 0 !== strpos( wp_normalize_path( $resolved ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				return array(
					'success' => false,
					'error'   => __( 'File must be in the WordPress content directory.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Use the resolved path for all subsequent file operations.
			$file_path = $resolved;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for local PHP file reading.
			$code = file_get_contents( $file_path );
		}

		if ( empty( $code ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either code or file_path must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$refactor_goals         = isset( $arguments['refactor_goals'] ) ? array_map( 'sanitize_text_field', (array) $arguments['refactor_goals'] ) : array( 'readability', 'standards' );
		$apply_changes          = isset( $arguments['apply_changes'] ) ? (bool) $arguments['apply_changes'] : false;
		$preserve_functionality = isset( $arguments['preserve_functionality'] ) ? (bool) $arguments['preserve_functionality'] : true;

		// Build refactoring prompt.
		$prompt = $this->build_refactoring_prompt( $code, $refactor_goals, $preserve_functionality );

		// Get AI service.
		$ai_service = $this->get_ai_service( $arguments, $context );
		if ( is_wp_error( $ai_service ) ) {
			return array(
				'success' => false,
				'error'   => $ai_service->get_error_message(),
			);
		}

		// Generate refactored code.
		$ai_response = $ai_service->generate( $prompt );

		if ( is_wp_error( $ai_response ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'Refactoring failed: %s', 'mcp-ai-wpoos-pro' ),
					$ai_response->get_error_message()
				),
			);
		}

		// Extract refactored code and suggestions.
		$refactored = $this->parse_refactoring_response( $ai_response );

		if ( is_wp_error( $refactored ) ) {
			return array(
				'success' => false,
				'error'   => $refactored->get_error_message(),
			);
		}

		$result = array(
			'success'          => true,
			'message'          => __( 'Code refactoring completed.', 'mcp-ai-wpoos-pro' ),
			'original_lines'   => substr_count( $code, "\n" ) + 1,
			'refactored_lines' => substr_count( $refactored['code'], "\n" ) + 1,
			'refactored_code'  => $refactored['code'],
			'improvements'     => $refactored['improvements'],
			'changes_applied'  => false,
		);

		// Apply changes if requested and file path provided.
		if ( $apply_changes && ! empty( $file_path ) ) {
			$backup_path = $file_path . '.backup-' . time();

			// Create backup.
			copy( $file_path, $backup_path );

			// Write refactored code.
			$bytes_written = file_put_contents( $file_path, $refactored['code'] );

			if ( false !== $bytes_written ) {
				$result['changes_applied'] = true;
				$result['backup_path']     = $backup_path;
				$result['message']         = __( 'Code refactored and changes applied successfully.', 'mcp-ai-wpoos-pro' );
			} else {
				$result['warning'] = __( 'Failed to write changes to file.', 'mcp-ai-wpoos-pro' );
			}
		}

		return $result;
	}

	/**
	 * Build refactoring prompt for AI.
	 *
	 * @param string $code                   Original code.
	 * @param array  $refactor_goals         Refactoring goals.
	 * @param bool   $preserve_functionality Preserve functionality flag.
	 * @return string AI prompt.
	 */
	private function build_refactoring_prompt( $code, $refactor_goals, $preserve_functionality ) {
		$prompt  = "Refactor the following WordPress AI tool code:\n\n```php\n{$code}\n```\n\n";
		$prompt .= "Refactoring Goals:\n";

		foreach ( $refactor_goals as $goal ) {
			switch ( $goal ) {
				case 'performance':
					$prompt .= "- Optimize for performance (reduce complexity, improve efficiency)\n";
					break;
				case 'security':
					$prompt .= "- Enhance security (input validation, sanitization, escaping)\n";
					break;
				case 'readability':
					$prompt .= "- Improve readability (better naming, comments, structure)\n";
					break;
				case 'standards':
					$prompt .= "- Follow WordPress coding standards strictly\n";
					break;
				case 'dry':
					$prompt .= "- Reduce code duplication (DRY principle)\n";
					break;
				case 'error-handling':
					$prompt .= "- Improve error handling and edge cases\n";
					break;
			}
		}

		if ( $preserve_functionality ) {
			$prompt .= "\nIMPORTANT: Preserve all original functionality. Do not change the tool's behavior.\n";
		}

		$prompt .= "\nProvide:\n";
		$prompt .= "1. Refactored code (in ```php code block)\n";
		$prompt .= "2. List of improvements made\n";

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
	 * Parse refactoring response from AI.
	 *
	 * @param string $response AI response.
	 * @return array|WP_Error Parsed data or error.
	 */
	private function parse_refactoring_response( $response ) {
		// Extract code from PHP code blocks.
		$php_pattern = '/```php\s*(.*?)\s*```/s';
		if ( ! preg_match( $php_pattern, $response, $matches ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_code_found',
				__( 'Could not extract refactored code from response.', 'mcp-ai-wpoos-pro' )
			);
		}

		$code = trim( $matches[1] );

		// Extract improvements list.
		$improvements    = array();
		$lines           = explode( "\n", $response );
		$in_improvements = false;

		foreach ( $lines as $line ) {
			$trimmed = trim( $line );

			if ( strpos( $trimmed, 'improvements' ) !== false || strpos( $trimmed, 'changes' ) !== false ) {
				$in_improvements = true;
				continue;
			}

			if ( $in_improvements && ( strpos( $trimmed, '-' ) === 0 || strpos( $trimmed, '*' ) === 0 ) ) {
				$improvements[] = ltrim( $trimmed, '- *' );
			}
		}

		return array(
			'code'         => $code,
			'improvements' => $improvements,
		);
	}
}
