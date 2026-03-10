<?php
/**
 * Tool for generating complete AI tool class scaffolds.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate complete tool class scaffold with proper structure and boilerplate.
 *
 * This tool creates a fully structured WordPress tool class file following
 * the WP_MCP_AI_Tool_Interface pattern with proper PHPDoc, namespacing,
 * and method stubs ready for implementation.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Tool_Scaffold implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_tool_scaffold';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Tool Scaffold', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate a complete WordPress AI tool class scaffold with proper structure, PHPDoc, interfaces, and method stubs. Creates production-ready boilerplate following WP_MCP_AI tool patterns.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_name'        => array(
					'type'        => 'string',
					'description' => __( 'Human-readable tool name (e.g., "Generate PDF Report")', 'mcp-ai-wpoos-pro' ),
				),
				'tool_slug'        => array(
					'type'        => 'string',
					'description' => __( 'Tool slug in snake_case (e.g., "generate_pdf_report"). Auto-generated if not provided.', 'mcp-ai-wpoos-pro' ),
				),
				'description'      => array(
					'type'        => 'string',
					'description' => __( 'Detailed description of what the tool does', 'mcp-ai-wpoos-pro' ),
				),
				'capability'       => array(
					'type'        => 'string',
					'description' => __( 'Required WordPress capability (default: manage_options)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'manage_options',
				),
				'toolkit'          => array(
					'type'        => 'string',
					'description' => __( 'Toolkit category (e.g., "image-production", "analytics", "custom")', 'mcp-ai-wpoos-pro' ),
					'default'     => 'custom',
				),
				'interfaces'       => array(
					'type'        => 'array',
					'description' => __( 'Additional interfaces to implement (e.g., WP_MCP_AI_Tool_Capability_Flags_Interface)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'capability_flags' => array(
					'type'        => 'array',
					'description' => __( 'Tool capability flags (pro, read-only, state-changing, etc.)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array( 'pro', 'requires-capability' ),
				),
				'parameters'       => array(
					'type'        => 'array',
					'description' => __( 'Tool parameter definitions (name, type, description, required)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'       => 'object',
						'properties' => array(
							'name'        => array( 'type' => 'string' ),
							'type'        => array( 'type' => 'string' ),
							'description' => array( 'type' => 'string' ),
							'required'    => array( 'type' => 'boolean' ),
						),
					),
					'default'     => array(),
				),
				'output_path'      => array(
					'type'        => 'string',
					'description' => __( 'Custom output file path (optional, defaults to toolkit directory)', 'mcp-ai-wpoos-pro' ),
				),
				'include_tests'    => array(
					'type'        => 'boolean',
					'description' => __( 'Generate accompanying PHPUnit test file', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'include_docs'     => array(
					'type'        => 'boolean',
					'description' => __( 'Generate comprehensive documentation', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
			'required'   => array( 'tool_name', 'description' ),
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
			'local-only',
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
		if ( empty( $arguments['tool_name'] ) || empty( $arguments['description'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'tool_name and description are required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$tool_name   = sanitize_text_field( $arguments['tool_name'] );
		$description = sanitize_textarea_field( $arguments['description'] );

		// Generate tool slug if not provided.
		$tool_slug = isset( $arguments['tool_slug'] ) ?
			sanitize_title( $arguments['tool_slug'] ) :
			sanitize_title( str_replace( ' ', '_', strtolower( $tool_name ) ) );

		$capability       = isset( $arguments['capability'] ) ? sanitize_text_field( $arguments['capability'] ) : 'manage_options';
		$toolkit          = isset( $arguments['toolkit'] ) ? sanitize_text_field( $arguments['toolkit'] ) : 'custom';
		$interfaces       = isset( $arguments['interfaces'] ) ? array_map( 'sanitize_text_field', (array) $arguments['interfaces'] ) : array();
		$capability_flags = isset( $arguments['capability_flags'] ) ? array_map( 'sanitize_text_field', (array) $arguments['capability_flags'] ) : array( 'pro', 'requires-capability' );
		$parameters       = isset( $arguments['parameters'] ) ? (array) $arguments['parameters'] : array();

		// Generate class name from slug.
		$class_name = 'WP_MCP_AI_Tool_' . str_replace( ' ', '_', ucwords( str_replace( '_', ' ', $tool_slug ) ) );
		$file_name  = 'class-' . str_replace( '_', '-', strtolower( $class_name ) ) . '.php';

		// Build output path.
		if ( isset( $arguments['output_path'] ) && ! empty( $arguments['output_path'] ) ) {
			$output_dir = sanitize_text_field( $arguments['output_path'] );

			// Security: Restrict output directory to the WordPress content directory to
			// prevent writing PHP files to arbitrary server paths.
			$resolved_dir = realpath( $output_dir );
			if ( false === $resolved_dir ) {
				// Directory doesn't exist yet — resolve the nearest existing ancestor and validate.
				$resolved_dir = realpath( dirname( $output_dir ) );
				if ( false === $resolved_dir ) {
					return array(
						'success' => false,
						'error'   => __( 'Invalid output path: parent directory does not exist.', 'mcp-ai-wpoos-pro' ),
					);
				}
			}

			if ( 0 !== strpos( wp_normalize_path( $resolved_dir ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				return array(
					'success' => false,
					'error'   => __( 'Output path must be within the WordPress content directory.', 'mcp-ai-wpoos-pro' ),
				);
			}
		} else {
			$output_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/' . sanitize_file_name( $toolkit );
		}

		// Create directory if it doesn't exist.
		if ( ! file_exists( $output_dir ) ) {
			wp_mkdir_p( $output_dir );
		}

		$output_path = trailingslashit( $output_dir ) . $file_name;

		// Check if file already exists.
		if ( file_exists( $output_path ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: file path */
					__( 'Tool file already exists: %s. Use a different tool_slug or delete the existing file.', 'mcp-ai-wpoos-pro' ),
					$output_path
				),
			);
		}

		// Generate scaffold code.
		$scaffold = $this->generate_scaffold_code(
			array(
				'class_name'       => $class_name,
				'tool_name'        => $tool_name,
				'tool_slug'        => $tool_slug,
				'description'      => $description,
				'capability'       => $capability,
				'interfaces'       => $interfaces,
				'capability_flags' => $capability_flags,
				'parameters'       => $parameters,
			)
		);

		// Write file.
		$bytes_written = file_put_contents( $output_path, $scaffold );

		if ( false === $bytes_written ) {
			return array(
				'success' => false,
				'error'   => __( 'Failed to write tool scaffold file. Check directory permissions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$result = array(
			'success'    => true,
			'message'    => __( 'Tool scaffold generated successfully.', 'mcp-ai-wpoos-pro' ),
			'file_path'  => $output_path,
			'class_name' => $class_name,
			'tool_slug'  => $tool_slug,
			'file_size'  => $bytes_written,
			'next_steps' => array(
				'1' => __( 'Implement the execute() method logic', 'mcp-ai-wpoos-pro' ),
				'2' => __( 'Register the tool in the toolkit loader', 'mcp-ai-wpoos-pro' ),
				'3' => __( 'Test the tool functionality', 'mcp-ai-wpoos-pro' ),
			),
		);

		// Generate tests if requested.
		if ( isset( $arguments['include_tests'] ) && $arguments['include_tests'] ) {
			$test_result         = $this->generate_test_file( $class_name, $tool_slug, $output_dir );
			$result['test_file'] = $test_result;
		}

		// Generate documentation if requested.
		if ( isset( $arguments['include_docs'] ) && $arguments['include_docs'] ) {
			$docs_result             = $this->generate_documentation( $tool_name, $tool_slug, $description, $parameters );
			$result['documentation'] = $docs_result;
		}

		return $result;
	}

	/**
	 * Generate the PHP scaffold code.
	 *
	 * @param array $config Configuration array.
	 * @return string Generated PHP code.
	 */
	private function generate_scaffold_code( array $config ) {
		$class_name       = $config['class_name'];
		$tool_name        = $config['tool_name'];
		$tool_slug        = $config['tool_slug'];
		$description      = $config['description'];
		$capability       = $config['capability'];
		$interfaces       = $config['interfaces'];
		$capability_flags = $config['capability_flags'];
		$parameters       = $config['parameters'];

		// Build interface list.
		$interface_list = array( 'WP_MCP_AI_Tool_Interface' );
		if ( ! empty( $capability_flags ) ) {
			$interface_list[] = 'WP_MCP_AI_Tool_Capability_Flags_Interface';
		}
		$interface_list = array_merge( $interface_list, $interfaces );
		$implements     = implode( ', ', $interface_list );

		// Build capability flags method.
		$flags_method = '';
		if ( ! empty( $capability_flags ) ) {
			$flags_array = "array(\n";
			foreach ( $capability_flags as $flag ) {
				$flags_array .= "\t\t\t'" . esc_attr( $flag ) . "',\n";
			}
			$flags_array .= "\t\t)";

			$flags_method  = "\n\t/**\n\t * {@inheritdoc}\n\t */\n";
			$flags_method .= "\tpublic function get_capability_flags() {\n";
			$flags_method .= "\t\treturn " . $flags_array . ";\n";
			$flags_method .= "\t}\n";
		}

		// Build parameters schema.
		$params_schema = $this->build_parameters_schema( $parameters );

		// Build the scaffold.
		$scaffold  = "<?php\n";
		$scaffold .= "/**\n";
		$scaffold .= " * Tool: {$tool_name}\n";
		$scaffold .= " *\n";
		$scaffold .= " * @package WP_MCP_AI\n";
		$scaffold .= " * @since 1.1.0\n";
		$scaffold .= " * @phase Phase 2.9\n";
		$scaffold .= " */\n\n";
		$scaffold .= "if ( ! defined( 'ABSPATH' ) ) {\n";
		$scaffold .= "\texit;\n";
		$scaffold .= "}\n\n";
		$scaffold .= "/**\n";
		$scaffold .= " * {$description}\n";
		$scaffold .= " *\n";
		$scaffold .= " * @since 1.1.0\n";
		$scaffold .= " */\n";
		$scaffold .= "class {$class_name} implements {$implements} {\n\n";

		// get_slug method.
		$scaffold .= "\t/**\n\t * {@inheritdoc}\n\t */\n";
		$scaffold .= "\tpublic function get_slug() {\n";
		$scaffold .= "\t\treturn '{$tool_slug}';\n";
		$scaffold .= "\t}\n\n";

		// get_name method.
		$scaffold .= "\t/**\n\t * {@inheritdoc}\n\t */\n";
		$scaffold .= "\tpublic function get_name() {\n";
		$scaffold .= "\t\treturn __( '{$tool_name}', 'mcp-ai-wpoos-pro' );\n";
		$scaffold .= "\t}\n\n";

		// get_description method.
		$scaffold .= "\t/**\n\t * {@inheritdoc}\n\t */\n";
		$scaffold .= "\tpublic function get_description() {\n";
		$scaffold .= "\t\treturn __( '{$description}', 'mcp-ai-wpoos-pro' );\n";
		$scaffold .= "\t}\n\n";

		// get_parameters_schema method.
		$scaffold .= "\t/**\n\t * {@inheritdoc}\n\t */\n";
		$scaffold .= "\tpublic function get_parameters_schema() {\n";
		$scaffold .= "\t\treturn {$params_schema};\n";
		$scaffold .= "\t}\n\n";

		// Add capability flags method if needed.
		if ( ! empty( $flags_method ) ) {
			$scaffold .= $flags_method . "\n";
		}

		// execute method.
		$scaffold .= "\t/**\n\t * {@inheritdoc}\n\t */\n";
		$scaffold .= "\tpublic function execute( array \$arguments = array(), array \$context = array() ) {\n";
		$scaffold .= "\t\t// TODO: Implement tool logic here.\n\n";
		$scaffold .= "\t\t// Example validation:\n";
		$scaffold .= "\t\t// if ( empty( \$arguments['required_param'] ) ) {\n";
		$scaffold .= "\t\t//     return array(\n";
		$scaffold .= "\t\t//         'success' => false,\n";
		$scaffold .= "\t\t//         'error'   => __( 'Required parameter missing.', 'mcp-ai-wpoos-pro' ),\n";
		$scaffold .= "\t\t//     );\n";
		$scaffold .= "\t\t// }\n\n";
		$scaffold .= "\t\treturn array(\n";
		$scaffold .= "\t\t\t'success' => true,\n";
		$scaffold .= "\t\t\t'message' => __( 'Tool executed successfully.', 'mcp-ai-wpoos-pro' ),\n";
		$scaffold .= "\t\t\t'data'    => array(),\n";
		$scaffold .= "\t\t);\n";
		$scaffold .= "\t}\n";
		$scaffold .= "}\n";

		return $scaffold;
	}

	/**
	 * Build parameters schema array code.
	 *
	 * @param array $parameters Parameters configuration.
	 * @return string PHP array code.
	 */
	private function build_parameters_schema( array $parameters ) {
		if ( empty( $parameters ) ) {
			return "array(\n\t\t\t'type'       => 'object',\n\t\t\t'properties' => array(),\n\t\t\t'required'   => array(),\n\t\t)";
		}

		$schema   = "array(\n\t\t\t'type'       => 'object',\n\t\t\t'properties' => array(\n";
		$required = array();

		foreach ( $parameters as $param ) {
			$name = isset( $param['name'] ) ? sanitize_key( $param['name'] ) : '';
			if ( empty( $name ) ) {
				continue;
			}

			$type        = isset( $param['type'] ) ? sanitize_text_field( $param['type'] ) : 'string';
			$desc        = isset( $param['description'] ) ? esc_attr( $param['description'] ) : '';
			$is_required = isset( $param['required'] ) && $param['required'];

			if ( $is_required ) {
				$required[] = $name;
			}

			$schema .= "\t\t\t\t'{$name}' => array(\n";
			$schema .= "\t\t\t\t\t'type'        => '{$type}',\n";
			$schema .= "\t\t\t\t\t'description' => __( '{$desc}', 'mcp-ai-wpoos-pro' ),\n";
			$schema .= "\t\t\t\t),\n";
		}

		$schema .= "\t\t\t),\n";
		$schema .= "\t\t\t'required'   => array( '" . implode( "', '", $required ) . "' ),\n";
		$schema .= "\t\t)";

		return $schema;
	}

	/**
	 * Generate test file scaffold.
	 *
	 * @param string $class_name Tool class name.
	 * @param string $tool_slug  Tool slug.
	 * @param string $output_dir Output directory.
	 * @return array Result array.
	 */
	private function generate_test_file( $class_name, $tool_slug, $output_dir ) {
		$test_file = WP_MCP_AI_PRO_PATH . 'tests/tools/test-' . str_replace( '_', '-', strtolower( $tool_slug ) ) . '.php';

		$test_content  = "<?php\n";
		$test_content .= "/**\n * Tests for {$class_name}\n *\n * @package WP_MCP_AI\n */\n\n";
		$test_content .= "class Test_{$class_name} extends WP_UnitTestCase {\n\n";
		$test_content .= "\tpublic function test_tool_registration() {\n";
		$test_content .= "\t\t// Test tool is properly registered.\n";
		$test_content .= "\t\t\$this->assertTrue( true );\n";
		$test_content .= "\t}\n";
		$test_content .= "}\n";

		file_put_contents( $test_file, $test_content );

		return array(
			'path'    => $test_file,
			'message' => __( 'Test file created.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Generate tool documentation.
	 *
	 * @param string $tool_name   Tool name.
	 * @param string $tool_slug   Tool slug.
	 * @param string $description Description.
	 * @param array  $parameters  Parameters.
	 * @return array Result array.
	 */
	private function generate_documentation( $tool_name, $tool_slug, $description, $parameters ) {
		$docs  = "# {$tool_name}\n\n";
		$docs .= "**Slug:** `{$tool_slug}`\n\n";
		$docs .= "## Description\n\n{$description}\n\n";

		if ( ! empty( $parameters ) ) {
			$docs .= "## Parameters\n\n";
			foreach ( $parameters as $param ) {
				$name = isset( $param['name'] ) ? $param['name'] : '';
				$type = isset( $param['type'] ) ? $param['type'] : 'string';
				$desc = isset( $param['description'] ) ? $param['description'] : '';
				$req  = isset( $param['required'] ) && $param['required'] ? 'Required' : 'Optional';

				$docs .= "- **{$name}** ({$type}, {$req}): {$desc}\n";
			}
		}

		return array(
			'documentation' => $docs,
			'message'       => __( 'Documentation generated.', 'mcp-ai-wpoos-pro' ),
		);
	}
}
