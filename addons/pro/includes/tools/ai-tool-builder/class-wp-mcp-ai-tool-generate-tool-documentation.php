<?php
/**
 * Tool for generating comprehensive documentation for AI tools.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate comprehensive documentation for AI tools.
 *
 * This tool creates detailed documentation including usage examples,
 * parameter descriptions, return values, integration guides, and
 * troubleshooting tips.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Tool_Documentation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_tool_documentation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Tool Documentation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate comprehensive documentation for AI tools. Creates detailed usage guides, parameter tables, code examples, integration guides, and troubleshooting sections. Supports Markdown and HTML output.', 'mcp-ai-wpoos-pro' );
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
					'description' => __( 'Tool class name to document', 'mcp-ai-wpoos-pro' ),
				),
				'tool_file'         => array(
					'type'        => 'string',
					'description' => __( 'Path to tool file for analysis', 'mcp-ai-wpoos-pro' ),
				),
				'doc_sections'      => array(
					'type'        => 'array',
					'description' => __( 'Documentation sections to include', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'overview', 'parameters', 'examples', 'return-values', 'integration', 'troubleshooting', 'changelog' ),
					),
					'default'     => array( 'overview', 'parameters', 'examples', 'return-values' ),
				),
				'include_examples'  => array(
					'type'        => 'boolean',
					'description' => __( 'Include code usage examples', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'example_scenarios' => array(
					'type'        => 'array',
					'description' => __( 'Specific use case scenarios to document', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'string' ),
					'default'     => array(),
				),
				'output_format'     => array(
					'type'        => 'string',
					'enum'        => array( 'markdown', 'html', 'phpdoc' ),
					'description' => __( 'Documentation output format', 'mcp-ai-wpoos-pro' ),
					'default'     => 'markdown',
				),
				'audience_level'    => array(
					'type'        => 'string',
					'enum'        => array( 'beginner', 'intermediate', 'advanced', 'developer' ),
					'description' => __( 'Target audience technical level', 'mcp-ai-wpoos-pro' ),
					'default'     => 'intermediate',
				),
				'output_file'       => array(
					'type'        => 'string',
					'description' => __( 'Path to save documentation file', 'mcp-ai-wpoos-pro' ),
				),
				'model'             => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for documentation generation', 'mcp-ai-wpoos-pro' ),
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
		$doc_sections      = isset( $arguments['doc_sections'] ) ? array_map( 'sanitize_text_field', (array) $arguments['doc_sections'] ) : array( 'overview', 'parameters', 'examples', 'return-values' );
		$include_examples  = isset( $arguments['include_examples'] ) ? (bool) $arguments['include_examples'] : true;
		$example_scenarios = isset( $arguments['example_scenarios'] ) ? array_map( 'sanitize_text_field', (array) $arguments['example_scenarios'] ) : array();
		$output_format     = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'markdown';
		$audience_level    = isset( $arguments['audience_level'] ) ? sanitize_text_field( $arguments['audience_level'] ) : 'intermediate';

		// Security: Validate tool_file before passing it to the analysis helper.
		$tool_file = '';
		$tool_file_security_warning = '';
		if ( ! empty( $tool_file_raw ) ) {
			$resolved_tool = realpath( $tool_file_raw );
			if ( false !== $resolved_tool &&
				0 === strpos( wp_normalize_path( $resolved_tool ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				$tool_file = $resolved_tool;
			} else {
				// Path is outside WP_CONTENT_DIR or unresolvable: reject it and fall back
				// to reflection-only analysis. Surface this to the caller so they know why.
				$tool_file_security_warning = __( 'tool_file was ignored: path must be within the WordPress content directory.', 'mcp-ai-wpoos-pro' );
			}
		}

		// Load and analyze tool.
		$tool_data = $this->analyze_tool_for_docs( $tool_class, $tool_file );

		if ( is_wp_error( $tool_data ) ) {
			return array(
				'success' => false,
				'error'   => $tool_data->get_error_message(),
			);
		}

		// Build documentation generation prompt.
		$prompt = $this->build_documentation_prompt( $tool_data, $doc_sections, $include_examples, $example_scenarios, $output_format, $audience_level );

		// Get AI service.
		$ai_service = $this->get_ai_service( $arguments, $context );
		if ( is_wp_error( $ai_service ) ) {
			return array(
				'success' => false,
				'error'   => $ai_service->get_error_message(),
			);
		}

		// Generate documentation.
		$ai_response = $ai_service->generate( $prompt );

		if ( is_wp_error( $ai_response ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'Documentation generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$ai_response->get_error_message()
				),
			);
		}

		// Format documentation based on output format.
		$formatted_docs = $this->format_documentation( $ai_response, $output_format );

		$result = array(
			'success'       => true,
			'message'       => __( 'Documentation generated successfully.', 'mcp-ai-wpoos-pro' ),
			'documentation' => $formatted_docs,
			'format'        => $output_format,
			'word_count'    => str_word_count( strip_tags( $formatted_docs ) ),
		);

		// Surface any tool_file security rejection so the caller understands
		// why the file was not used for analysis.
		if ( ! empty( $tool_file_security_warning ) ) {
			$result['tool_file_warning'] = $tool_file_security_warning;
		}

		// Save to file if path provided.
		if ( isset( $arguments['output_file'] ) && ! empty( $arguments['output_file'] ) ) {
			$output_file = sanitize_text_field( $arguments['output_file'] );

			// Security: Restrict output location to the WordPress content directory to
			// prevent writing files to arbitrary server paths.
			$resolved_parent = realpath( dirname( $output_file ) );
			if ( false === $resolved_parent ) {
				$result['warning'] = __( 'Invalid output path: parent directory does not exist.', 'mcp-ai-wpoos-pro' );
			} elseif ( 0 !== strpos( wp_normalize_path( $resolved_parent ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				$result['warning'] = __( 'Output file must be within the WordPress content directory.', 'mcp-ai-wpoos-pro' );
			} else {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing documentation markdown file to local disk.
				$bytes_written = file_put_contents( $output_file, $formatted_docs );

				if ( false !== $bytes_written ) {
					$result['file_saved'] = true;
					$result['file_path']  = $output_file;
					$result['file_size']  = $bytes_written;
				} else {
					$result['warning'] = __( 'Failed to save documentation file.', 'mcp-ai-wpoos-pro' );
				}
			}
		}

		return $result;
	}

	/**
	 * Analyze tool for documentation generation.
	 *
	 * @param string $tool_class Tool class name.
	 * @param string $tool_file  Tool file path.
	 * @return array|WP_Error Tool data or error.
	 */
	private function analyze_tool_for_docs( $tool_class, $tool_file ) {
		$tool_data = array(
			'class_name'   => $tool_class,
			'name'         => '',
			'description'  => '',
			'slug'         => '',
			'parameters'   => array(),
			'capabilities' => array(),
		);

		// Try to load tool file for analysis.
		if ( ! empty( $tool_file ) && file_exists( $tool_file ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Reading local PHP tool file; path already validated against WP_CONTENT_DIR.
			$tool_code = file_get_contents( $tool_file );

			// Extract tool name.
			if ( preg_match( "/return __\( '([^']+)', 'mcp-ai-wpoos-pro' \);.*get_name/s", $tool_code, $matches ) ) {
				$tool_data['name'] = $matches[1];
			}

			// Extract description.
			if ( preg_match( "/return __\( '([^']+)', 'mcp-ai-wpoos-pro' \);.*get_description/s", $tool_code, $matches ) ) {
				$tool_data['description'] = $matches[1];
			}

			// Extract slug.
			if ( preg_match( "/return '([^']+)';.*get_slug/s", $tool_code, $matches ) ) {
				$tool_data['slug'] = $matches[1];
			}
		}

		return $tool_data;
	}

	/**
	 * Build documentation generation prompt.
	 *
	 * @param array  $tool_data        Tool data.
	 * @param array  $doc_sections     Documentation sections.
	 * @param bool   $include_examples Include examples flag.
	 * @param array  $example_scenarios Example scenarios.
	 * @param string $output_format    Output format.
	 * @param string $audience_level   Audience level.
	 * @return string AI prompt.
	 */
	private function build_documentation_prompt( $tool_data, $doc_sections, $include_examples, $example_scenarios, $output_format, $audience_level ) {
		$prompt = "Generate comprehensive documentation for WordPress AI tool: {$tool_data['class_name']}\n\n";

		if ( ! empty( $tool_data['name'] ) ) {
			$prompt .= "Tool Name: {$tool_data['name']}\n";
		}
		if ( ! empty( $tool_data['description'] ) ) {
			$prompt .= "Description: {$tool_data['description']}\n";
		}
		if ( ! empty( $tool_data['slug'] ) ) {
			$prompt .= "Slug: {$tool_data['slug']}\n";
		}

		$prompt .= "\nTarget Audience: {$audience_level}\n";
		$prompt .= "Output Format: {$output_format}\n\n";

		$prompt .= "Include these sections:\n";
		foreach ( $doc_sections as $section ) {
			switch ( $section ) {
				case 'overview':
					$prompt .= "- Overview: What the tool does and why it's useful\n";
					break;
				case 'parameters':
					$prompt .= "- Parameters: Detailed parameter table with types, descriptions, required/optional\n";
					break;
				case 'examples':
					$prompt .= "- Examples: Code examples showing how to use the tool\n";
					break;
				case 'return-values':
					$prompt .= "- Return Values: What the tool returns on success/failure\n";
					break;
				case 'integration':
					$prompt .= "- Integration Guide: How to integrate with other systems\n";
					break;
				case 'troubleshooting':
					$prompt .= "- Troubleshooting: Common issues and solutions\n";
					break;
				case 'changelog':
					$prompt .= "- Changelog: Version history and changes\n";
					break;
			}
		}

		if ( $include_examples ) {
			$prompt .= "\nInclude code examples for:\n";
			if ( ! empty( $example_scenarios ) ) {
				foreach ( $example_scenarios as $scenario ) {
					$prompt .= "- {$scenario}\n";
				}
			} else {
				$prompt .= "- Basic usage\n";
				$prompt .= "- Advanced usage\n";
				$prompt .= "- Error handling\n";
			}
		}

		$prompt .= "\nStyle Guidelines:\n";
		$prompt .= "- Clear, concise language\n";
		$prompt .= "- Professional tone\n";
		$prompt .= "- Code examples with comments\n";
		$prompt .= "- Proper formatting for {$output_format}\n";

		return $prompt;
	}

	/**
	 * Format documentation based on output format.
	 *
	 * @param string $content       Documentation content.
	 * @param string $output_format Output format.
	 * @return string Formatted documentation.
	 */
	private function format_documentation( $content, $output_format ) {
		switch ( $output_format ) {
			case 'html':
				// Convert Markdown to HTML if needed.
				if ( class_exists( 'Parsedown' ) ) {
					$parsedown = new Parsedown();
					return $parsedown->text( $content );
				}
				return '<pre>' . esc_html( $content ) . '</pre>';

			case 'phpdoc':
				// Wrap in PHPDoc format.
				$lines  = explode( "\n", $content );
				$phpdoc = "/**\n";
				foreach ( $lines as $line ) {
					$phpdoc .= ' * ' . $line . "\n";
				}
				$phpdoc .= " */\n";
				return $phpdoc;

			case 'markdown':
			default:
				return $content;
		}
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
}
