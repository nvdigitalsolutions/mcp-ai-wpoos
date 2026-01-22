<?php
/**
 * Tool for generating parameter schemas from natural language descriptions.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate JSON schema parameter definitions from natural language descriptions.
 *
 * This tool uses AI to intelligently parse natural language tool descriptions
 * and generate properly structured JSON schema parameter definitions with
 * type inference, validation rules, and description text.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Tool_Parameters implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_tool_parameters';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Tool Parameters', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate JSON schema parameter definitions from natural language descriptions. Uses AI to intelligently infer parameter types, validation rules, required fields, and descriptions. Perfect for quickly scaffolding tool parameters.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'description'    => array(
					'type'        => 'string',
					'description' => __( 'Natural language description of the tool and what parameters it needs', 'mcp-ai-wpoos-pro' ),
				),
				'example_usage'  => array(
					'type'        => 'string',
					'description' => __( 'Optional example of how the tool would be called (helps infer parameters)', 'mcp-ai-wpoos-pro' ),
				),
				'include_common' => array(
					'type'        => 'boolean',
					'description' => __( 'Include common parameters like limit, offset, format (default: false)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'model'          => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for generation (default: assistant context model)', 'mcp-ai-wpoos-pro' ),
				),
				'output_format'  => array(
					'type'        => 'string',
					'enum'        => array( 'php_array', 'json', 'markdown' ),
					'description' => __( 'Output format for the generated schema', 'mcp-ai-wpoos-pro' ),
					'default'     => 'php_array',
				),
				'strict_types'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use strict type definitions (no union types or flexible schemas)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'description' ),
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
			'idempotent',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate required parameters.
		if ( empty( $arguments['description'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Description is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$description    = sanitize_textarea_field( $arguments['description'] );
		$example_usage  = isset( $arguments['example_usage'] ) ? sanitize_textarea_field( $arguments['example_usage'] ) : '';
		$include_common = isset( $arguments['include_common'] ) ? (bool) $arguments['include_common'] : false;
		$output_format  = isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'php_array';
		$strict_types   = isset( $arguments['strict_types'] ) ? (bool) $arguments['strict_types'] : true;

		// Build AI prompt for parameter generation.
		$prompt = $this->build_parameter_generation_prompt( $description, $example_usage, $include_common, $strict_types );

		// Get AI service.
		$ai_service = $this->get_ai_service( $arguments, $context );
		if ( is_wp_error( $ai_service ) ) {
			return array(
				'success' => false,
				'error'   => $ai_service->get_error_message(),
			);
		}

		// Generate parameters using AI.
		$ai_response = $ai_service->generate( $prompt );

		if ( is_wp_error( $ai_response ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'AI generation failed: %s', 'mcp-ai-wpoos-pro' ),
					$ai_response->get_error_message()
				),
			);
		}

		// Parse AI response to extract parameter schema.
		$parameters = $this->parse_ai_response( $ai_response, $output_format );

		if ( is_wp_error( $parameters ) ) {
			return array(
				'success' => false,
				'error'   => $parameters->get_error_message(),
			);
		}

		// Format output based on requested format.
		$formatted_output = $this->format_output( $parameters, $output_format );

		return array(
			'success'         => true,
			'message'         => __( 'Parameter schema generated successfully.', 'mcp-ai-wpoos-pro' ),
			'parameters'      => $parameters,
			'formatted_code'  => $formatted_output,
			'output_format'   => $output_format,
			'parameter_count' => count( $parameters ),
			'required_params' => $this->count_required_params( $parameters ),
		);
	}

	/**
	 * Build AI prompt for parameter generation.
	 *
	 * @param string $description    Tool description.
	 * @param string $example_usage  Example usage.
	 * @param bool   $include_common Include common parameters.
	 * @param bool   $strict_types   Use strict types.
	 * @return string Prompt for AI.
	 */
	private function build_parameter_generation_prompt( $description, $example_usage, $include_common, $strict_types ) {
		$prompt  = "Generate a JSON schema parameter definition for a WordPress AI tool with the following description:\n\n";
		$prompt .= $description . "\n\n";

		if ( ! empty( $example_usage ) ) {
			$prompt .= "Example usage:\n" . $example_usage . "\n\n";
		}

		$prompt .= "Requirements:\n";
		$prompt .= "- Output valid JSON schema format\n";
		$prompt .= "- Include type, description for each parameter\n";
		$prompt .= "- Mark required parameters appropriately\n";
		$prompt .= "- Use WordPress-appropriate naming (snake_case)\n";

		if ( $strict_types ) {
			$prompt .= "- Use strict single types (string, integer, boolean, array, object)\n";
		}

		if ( $include_common ) {
			$prompt .= "- Include common parameters: limit (integer, 10-100), offset (integer), format (string)\n";
		}

		$prompt .= "\nOutput the schema in this format:\n";
		$prompt .= "{\n";
		$prompt .= '  "type": "object",';
		$prompt .= '  "properties": { ... },';
		$prompt .= '  "required": [ ... ]';
		$prompt .= "}\n\n";
		$prompt .= 'Only output the JSON schema, no additional text.';

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
		// Get model from arguments or context.
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';

		if ( empty( $model ) && isset( $context['assistant_model'] ) ) {
			$model = $context['assistant_model'];
		}

		// Check if AI service is available.
		if ( ! class_exists( 'WP_MCP_AI_Client' ) ) {
			return new WP_Error(
				'wp_mcp_ai_service_unavailable',
				__( 'AI service is not available.', 'mcp-ai-wpoos-pro' )
			);
		}

		return new WP_MCP_AI_Client( $model );
	}

	/**
	 * Parse AI response to extract parameters.
	 *
	 * @param string $response      AI response text.
	 * @param string $output_format Output format.
	 * @return array|WP_Error Parameters array or error.
	 */
	private function parse_ai_response( $response, $output_format ) {
		// Extract JSON from response (may be wrapped in markdown code blocks).
		$json_pattern = '/```json\s*(.*?)\s*```/s';
		if ( preg_match( $json_pattern, $response, $matches ) ) {
			$json_string = $matches[1];
		} else {
			// Try to find JSON without code blocks.
			$json_string = $response;
		}

		// Parse JSON.
		$schema = json_decode( $json_string, true );

		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_json',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse AI response as JSON: %s', 'mcp-ai-wpoos-pro' ),
					json_last_error_msg()
				)
			);
		}

		// Validate schema structure.
		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_schema',
				__( 'AI response does not contain valid parameter schema.', 'mcp-ai-wpoos-pro' )
			);
		}

		return $schema;
	}

	/**
	 * Format output based on requested format.
	 *
	 * @param array  $parameters    Parameters array.
	 * @param string $output_format Output format.
	 * @return string Formatted output.
	 */
	private function format_output( $parameters, $output_format ) {
		switch ( $output_format ) {
			case 'json':
				return wp_json_encode( $parameters, JSON_PRETTY_PRINT );

			case 'markdown':
				return $this->format_as_markdown( $parameters );

			case 'php_array':
			default:
				return $this->format_as_php_array( $parameters );
		}
	}

	/**
	 * Format parameters as PHP array code.
	 *
	 * @param array $parameters Parameters array.
	 * @return string PHP array code.
	 */
	private function format_as_php_array( $parameters ) {
		$code  = "array(\n";
		$code .= "\t'type'       => 'object',\n";
		$code .= "\t'properties' => array(\n";

		if ( isset( $parameters['properties'] ) ) {
			foreach ( $parameters['properties'] as $name => $config ) {
				$code .= "\t\t'{$name}' => array(\n";
				foreach ( $config as $key => $value ) {
					if ( is_string( $value ) ) {
						$code .= "\t\t\t'{$key}' => '{$value}',\n";
					} elseif ( is_bool( $value ) ) {
						$code .= "\t\t\t'{$key}' => " . ( $value ? 'true' : 'false' ) . ",\n";
					} elseif ( is_numeric( $value ) ) {
						$code .= "\t\t\t'{$key}' => {$value},\n";
					}
				}
				$code .= "\t\t),\n";
			}
		}

		$code .= "\t),\n";

		if ( isset( $parameters['required'] ) && is_array( $parameters['required'] ) ) {
			$code .= "\t'required'   => array( '" . implode( "', '", $parameters['required'] ) . "' ),\n";
		}

		$code .= ')';

		return $code;
	}

	/**
	 * Format parameters as Markdown table.
	 *
	 * @param array $parameters Parameters array.
	 * @return string Markdown formatted text.
	 */
	private function format_as_markdown( $parameters ) {
		$md  = "## Parameters\n\n";
		$md .= "| Parameter | Type | Required | Description |\n";
		$md .= "|-----------|------|----------|-------------|\n";

		$required = isset( $parameters['required'] ) ? (array) $parameters['required'] : array();

		if ( isset( $parameters['properties'] ) ) {
			foreach ( $parameters['properties'] as $name => $config ) {
				$type        = isset( $config['type'] ) ? $config['type'] : 'string';
				$is_required = in_array( $name, $required, true ) ? 'Yes' : 'No';
				$description = isset( $config['description'] ) ? $config['description'] : '';

				$md .= "| `{$name}` | {$type} | {$is_required} | {$description} |\n";
			}
		}

		return $md;
	}

	/**
	 * Count required parameters.
	 *
	 * @param array $parameters Parameters array.
	 * @return int Count of required parameters.
	 */
	private function count_required_params( $parameters ) {
		if ( ! isset( $parameters['required'] ) || ! is_array( $parameters['required'] ) ) {
			return 0;
		}
		return count( $parameters['required'] );
	}
}
