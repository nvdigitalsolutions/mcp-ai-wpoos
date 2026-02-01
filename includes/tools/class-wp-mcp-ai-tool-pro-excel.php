<?php
/**
 * Pro Excel Tool - AI-powered Excel formula generation and manipulation.
 *
 * Leverages Excel's status as a Turing-complete programming language with LAMBDA functions
 * to provide AI-assisted spreadsheet formula generation, debugging, and documentation.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Pro Excel tool for AI-powered Excel formula generation and manipulation.
 *
 * This tool recognizes Excel as a full programming language (as of 2021 with LAMBDA functions)
 * and provides AI-powered assistance for:
 * - Generating Excel formulas from natural language descriptions
 * - Creating custom LAMBDA functions
 * - Explaining complex formulas
 * - Debugging problematic formulas
 * - Documenting formula logic
 * - Converting multi-step calculations into single LAMBDA functions
 * - Generating recursive formulas
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Pro_Excel implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'pro_excel';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Pro Excel', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'AI-powered Excel formula generation and manipulation. Recognizes Excel as a Turing-complete programming language with LAMBDA functions. Generate formulas from natural language, explain complex formulas, debug errors, create custom LAMBDA functions, and document spreadsheet logic.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'operation'     => array(
					'type'        => 'string',
					'enum'        => array( 'generate', 'explain', 'debug', 'document', 'convert', 'lambda' ),
					'description' => __( 'Operation to perform: "generate" (create formula from description), "explain" (explain existing formula), "debug" (fix problematic formula), "document" (add documentation), "convert" (convert multi-step to LAMBDA), "lambda" (create custom LAMBDA function).', 'mcp-ai-wpoos' ),
				),
				'description'   => array(
					'type'        => 'string',
					'description' => __( 'Natural language description of what you want the formula to do (for generate/lambda operations).', 'mcp-ai-wpoos' ),
				),
				'formula'       => array(
					'type'        => 'string',
					'description' => __( 'Existing Excel formula to explain, debug, or document.', 'mcp-ai-wpoos' ),
				),
				'context'       => array(
					'type'        => 'string',
					'description' => __( 'Additional context about your spreadsheet structure, data ranges, or specific requirements.', 'mcp-ai-wpoos' ),
				),
				'excel_version' => array(
					'type'        => 'string',
					'enum'        => array( 'modern', 'legacy', 'online' ),
					'description' => __( 'Excel version target: "modern" (Microsoft 365 with LAMBDA), "legacy" (older Excel without LAMBDA), "online" (Excel Online). Default: modern.', 'mcp-ai-wpoos' ),
					'default'     => 'modern',
				),
				'model'         => array(
					'type'        => 'string',
					'description' => __( 'AI model to use for generation. If not specified, uses assistant default or global default.', 'mcp-ai-wpoos' ),
				),
			),
			'required'             => array( 'operation' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                   // Pro tier feature.
			'requires-credentials',  // Requires AI provider API credentials.
			'requires-capability',   // Requires user to be logged in.
			'requires-model',        // Needs AI model to generate formulas.
			'consumes-tokens',       // Uses AI model tokens.
			'model-dependent',       // Quality varies by model selected.
			'external-api',          // Makes API calls to AI providers.
			'network-dependent',     // Requires internet connectivity.
			'cacheable',             // Results can be cached for identical inputs.
			'non-deterministic',     // AI may generate different formulas for same description.
		);
	}

	/**
	 * Get tool definition for LLM payload.
	 *
	 * @return array Tool definition including name, description, parameters, and required capability.
	 */
	public function get_definition() {
		return array(
			'name'                => $this->get_name(),
			'description'         => $this->get_description(),
			'parameters'          => $this->get_parameters_schema(),
			'required_capability' => 'edit_posts',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id, assistant_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		// Verify user is logged in.
		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_unauthorized',
				__( 'You must be logged in to use the Pro Excel tool.', 'mcp-ai-wpoos' )
			);
		}

		// Check user has required capability (edit_posts as minimum).
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to use the Pro Excel tool.', 'mcp-ai-wpoos' )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		// Validate operation parameter.
		if ( empty( $arguments['operation'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_operation',
				__( 'The "operation" parameter is required.', 'mcp-ai-wpoos' )
			);
		}

		$operation        = sanitize_text_field( $arguments['operation'] );
		$valid_operations = array( 'generate', 'explain', 'debug', 'document', 'convert', 'lambda' );

		if ( ! in_array( $operation, $valid_operations, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_operation',
				sprintf(
					/* translators: %s: comma-separated list of valid operations */
					__( 'Invalid operation. Must be one of: %s', 'mcp-ai-wpoos' ),
					implode( ', ', $valid_operations )
				)
			);
		}

		// Get Excel version preference.
		$excel_version = isset( $arguments['excel_version'] ) ? sanitize_text_field( $arguments['excel_version'] ) : 'modern';
		if ( ! in_array( $excel_version, array( 'modern', 'legacy', 'online' ), true ) ) {
			$excel_version = 'modern';
		}

		// Route to appropriate handler based on operation.
		switch ( $operation ) {
			case 'generate':
			case 'lambda':
				return $this->handle_generate_operation( $arguments, $context, $excel_version );

			case 'explain':
				return $this->handle_explain_operation( $arguments, $context );

			case 'debug':
				return $this->handle_debug_operation( $arguments, $context, $excel_version );

			case 'document':
				return $this->handle_document_operation( $arguments, $context );

			case 'convert':
				return $this->handle_convert_operation( $arguments, $context, $excel_version );

			default:
				return new WP_Error(
					'wp_mcp_ai_unhandled_operation',
					__( 'Operation not yet implemented.', 'mcp-ai-wpoos' )
				);
		}
	}

	/**
	 * Handle formula generation operations (generate/lambda).
	 *
	 * @param array  $arguments     Tool arguments.
	 * @param array  $context       Execution context.
	 * @param string $excel_version Excel version target.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_generate_operation( array $arguments, array $context, $excel_version ) {
		if ( empty( $arguments['description'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_description',
				__( 'The "description" parameter is required for formula generation.', 'mcp-ai-wpoos' )
			);
		}

		$description  = sanitize_textarea_field( $arguments['description'] );
		$user_context = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';
		$operation    = sanitize_text_field( $arguments['operation'] );

		// Build the system prompt based on operation type and Excel version.
		$system_prompt = $this->build_generation_system_prompt( $operation, $excel_version );

		// Build the user prompt.
		$user_prompt = $this->build_generation_user_prompt( $description, $user_context, $operation );

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		// Parse and format the response.
		return array(
			'operation'     => $operation,
			'excel_version' => $excel_version,
			'description'   => $description,
			'formula'       => $ai_response['formula'] ?? $ai_response['content'],
			'explanation'   => $ai_response['explanation'] ?? '',
			'usage_notes'   => $ai_response['usage_notes'] ?? '',
			'text'          => sprintf(
				/* translators: 1: operation type, 2: formula description */
				__( 'Generated Excel %1$s for: %2$s', 'mcp-ai-wpoos' ),
				'lambda' === $operation ? 'LAMBDA function' : 'formula',
				$description
			),
		);
	}

	/**
	 * Handle formula explanation operations.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_explain_operation( array $arguments, array $context ) {
		if ( empty( $arguments['formula'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_formula',
				__( 'The "formula" parameter is required for explanation.', 'mcp-ai-wpoos' )
			);
		}

		$formula      = sanitize_textarea_field( $arguments['formula'] );
		$user_context = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';

		// Build the system prompt.
		$system_prompt = $this->build_explain_system_prompt();

		// Build the user prompt.
		$user_prompt  = "Please explain this Excel formula in detail:\n\n";
		$user_prompt .= "Formula: {$formula}\n\n";
		if ( $user_context ) {
			$user_prompt .= "Context: {$user_context}\n\n";
		}
		$user_prompt .= "Provide a clear explanation including:\n";
		$user_prompt .= "1. Overall purpose\n";
		$user_prompt .= "2. How it works step-by-step\n";
		$user_prompt .= "3. What each function/operator does\n";
		$user_prompt .= '4. Any potential issues or edge cases';

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		return array(
			'operation'   => 'explain',
			'formula'     => $formula,
			'explanation' => $ai_response['content'],
			'text'        => sprintf(
				/* translators: %s: formula being explained */
				__( 'Explanation for formula: %s', 'mcp-ai-wpoos' ),
				wp_trim_words( $formula, 10, '...' )
			),
		);
	}

	/**
	 * Handle formula debugging operations.
	 *
	 * @param array  $arguments     Tool arguments.
	 * @param array  $context       Execution context.
	 * @param string $excel_version Excel version target.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_debug_operation( array $arguments, array $context, $excel_version ) {
		if ( empty( $arguments['formula'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_formula',
				__( 'The "formula" parameter is required for debugging.', 'mcp-ai-wpoos' )
			);
		}

		$formula      = sanitize_textarea_field( $arguments['formula'] );
		$user_context = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';

		// Build the system prompt.
		$system_prompt = $this->build_debug_system_prompt( $excel_version );

		// Build the user prompt.
		$user_prompt  = "Please debug this Excel formula and provide a corrected version:\n\n";
		$user_prompt .= "Formula: {$formula}\n\n";
		if ( $user_context ) {
			$user_prompt .= "Issue/Context: {$user_context}\n\n";
		}
		$user_prompt .= "Provide:\n";
		$user_prompt .= "1. Identified issues\n";
		$user_prompt .= "2. Corrected formula\n";
		$user_prompt .= '3. Explanation of fixes';

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		return array(
			'operation'         => 'debug',
			'excel_version'     => $excel_version,
			'original_formula'  => $formula,
			'corrected_formula' => $ai_response['corrected_formula'] ?? '',
			'issues_found'      => $ai_response['issues'] ?? '',
			'fixes_applied'     => $ai_response['fixes'] ?? '',
			'explanation'       => $ai_response['content'],
			'text'              => __( 'Formula debugged and corrected', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Handle formula documentation operations.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_document_operation( array $arguments, array $context ) {
		if ( empty( $arguments['formula'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_formula',
				__( 'The "formula" parameter is required for documentation.', 'mcp-ai-wpoos' )
			);
		}

		$formula      = sanitize_textarea_field( $arguments['formula'] );
		$user_context = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';

		// Build the system prompt.
		$system_prompt = $this->build_document_system_prompt();

		// Build the user prompt.
		$user_prompt  = "Please create professional documentation for this Excel formula:\n\n";
		$user_prompt .= "Formula: {$formula}\n\n";
		if ( $user_context ) {
			$user_prompt .= "Context: {$user_context}\n\n";
		}
		$user_prompt .= "Provide structured documentation including:\n";
		$user_prompt .= "1. Purpose statement\n";
		$user_prompt .= "2. Input requirements\n";
		$user_prompt .= "3. Expected output\n";
		$user_prompt .= "4. Usage instructions\n";
		$user_prompt .= '5. Maintenance notes';

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		return array(
			'operation'     => 'document',
			'formula'       => $formula,
			'documentation' => $ai_response['content'],
			'text'          => __( 'Formula documentation generated', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Handle multi-step to LAMBDA conversion operations.
	 *
	 * @param array  $arguments     Tool arguments.
	 * @param array  $context       Execution context.
	 * @param string $excel_version Excel version target.
	 * @return array|WP_Error Result or error.
	 */
	protected function handle_convert_operation( array $arguments, array $context, $excel_version ) {
		if ( empty( $arguments['description'] ) && empty( $arguments['formula'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'Either "description" or "formula" parameter is required for conversion.', 'mcp-ai-wpoos' )
			);
		}

		if ( 'legacy' === $excel_version ) {
			return new WP_Error(
				'wp_mcp_ai_incompatible_version',
				__( 'LAMBDA conversion requires modern Excel version (Microsoft 365).', 'mcp-ai-wpoos' )
			);
		}

		$description  = isset( $arguments['description'] ) ? sanitize_textarea_field( $arguments['description'] ) : '';
		$formula      = isset( $arguments['formula'] ) ? sanitize_textarea_field( $arguments['formula'] ) : '';
		$user_context = isset( $arguments['context'] ) ? sanitize_textarea_field( $arguments['context'] ) : '';

		// Build the system prompt.
		$system_prompt = $this->build_convert_system_prompt();

		// Build the user prompt.
		$user_prompt = "Please convert this multi-step calculation into a single LAMBDA function:\n\n";
		if ( $formula ) {
			$user_prompt .= "Current formula(s): {$formula}\n\n";
		}
		if ( $description ) {
			$user_prompt .= "Process description: {$description}\n\n";
		}
		if ( $user_context ) {
			$user_prompt .= "Context: {$user_context}\n\n";
		}
		$user_prompt .= "Provide:\n";
		$user_prompt .= "1. Consolidated LAMBDA function\n";
		$user_prompt .= "2. How to use it\n";
		$user_prompt .= '3. Benefits of consolidation';

		// Get AI response.
		$ai_response = $this->call_ai_model( $system_prompt, $user_prompt, $arguments, $context );

		if ( is_wp_error( $ai_response ) ) {
			return $ai_response;
		}

		return array(
			'operation'       => 'convert',
			'excel_version'   => $excel_version,
			'original'        => $formula ?: $description,
			'lambda_function' => $ai_response['lambda'] ?? '',
			'usage'           => $ai_response['usage'] ?? '',
			'benefits'        => $ai_response['benefits'] ?? '',
			'explanation'     => $ai_response['content'],
			'text'            => __( 'Multi-step calculation converted to LAMBDA function', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Build system prompt for formula generation.
	 *
	 * @param string $operation     Operation type (generate/lambda).
	 * @param string $excel_version Excel version target.
	 * @return string System prompt.
	 */
	protected function build_generation_system_prompt( $operation, $excel_version ) {
		$prompt = "You are an expert Excel formula engineer with deep knowledge of Excel as a Turing-complete programming language.\n\n";

		if ( 'modern' === $excel_version ) {
			$prompt .= "Target: Microsoft 365 Excel with LAMBDA functions and modern dynamic arrays.\n\n";
			$prompt .= "Key capabilities:\n";
			$prompt .= "- LAMBDA: Create custom, reusable functions\n";
			$prompt .= "- LET: Define variables for cleaner formulas\n";
			$prompt .= "- MAP, REDUCE, SCAN: Functional programming constructs\n";
			$prompt .= "- FILTER, SORT, UNIQUE: Dynamic array functions\n";
			$prompt .= "- Recursive formulas: Self-referencing LAMBDAs\n\n";
		} elseif ( 'online' === $excel_version ) {
			$prompt .= "Target: Excel Online (most modern features available).\n\n";
		} else {
			$prompt .= "Target: Legacy Excel (no LAMBDA support).\n";
			$prompt .= "Use traditional Excel functions only.\n\n";
		}

		if ( 'lambda' === $operation ) {
			$prompt .= "Task: Generate a custom LAMBDA function that is reusable and named.\n";
			$prompt .= "Structure: =LAMBDA(parameters, formula_logic)\n";
			$prompt .= "Best practices:\n";
			$prompt .= "- Use clear parameter names\n";
			$prompt .= "- Add comments explaining logic\n";
			$prompt .= "- Handle edge cases\n";
			$prompt .= "- Make it self-documenting\n\n";
		} else {
			$prompt .= "Task: Generate efficient Excel formula from natural language.\n";
			$prompt .= "Best practices:\n";
			$prompt .= "- Prefer modern functions when available\n";
			$prompt .= "- Use array formulas for efficiency\n";
			$prompt .= "- Minimize volatile functions\n";
			$prompt .= "- Consider performance implications\n\n";
		}

		$prompt .= "Response format (JSON):\n";
		$prompt .= "{\n";
		$prompt .= '  "formula": "The Excel formula",';
		$prompt .= "\n";
		$prompt .= '  "explanation": "How it works",';
		$prompt .= "\n";
		$prompt .= '  "usage_notes": "How to use it in Excel"';
		$prompt .= "\n";
		$prompt .= '}';

		return $prompt;
	}

	/**
	 * Build user prompt for formula generation.
	 *
	 * @param string $description  User's formula description.
	 * @param string $context      Additional context.
	 * @param string $operation    Operation type.
	 * @return string User prompt.
	 */
	protected function build_generation_user_prompt( $description, $context, $operation ) {
		$prompt = '';

		if ( 'lambda' === $operation ) {
			$prompt .= "Create a custom LAMBDA function for:\n\n";
		} else {
			$prompt .= "Generate an Excel formula for:\n\n";
		}

		$prompt .= $description . "\n\n";

		if ( $context ) {
			$prompt .= "Additional context:\n";
			$prompt .= $context . "\n\n";
		}

		$prompt .= 'Provide the formula with explanation and usage notes.';

		return $prompt;
	}

	/**
	 * Build system prompt for formula explanation.
	 *
	 * @return string System prompt.
	 */
	protected function build_explain_system_prompt() {
		$prompt  = "You are an expert Excel formula analyst.\n\n";
		$prompt .= "Task: Explain Excel formulas in clear, accessible language.\n\n";
		$prompt .= "Your explanations should:\n";
		$prompt .= "- Break down complex formulas step-by-step\n";
		$prompt .= "- Explain what each function does\n";
		$prompt .= "- Identify the overall logic\n";
		$prompt .= "- Point out clever techniques or potential issues\n";
		$prompt .= "- Use analogies when helpful\n";
		$prompt .= "- Be understandable to non-experts\n\n";
		$prompt .= "Recognize Excel as a programming language with:\n";
		$prompt .= "- LAMBDA for custom functions\n";
		$prompt .= "- Recursive capabilities\n";
		$prompt .= "- Functional programming patterns\n";
		$prompt .= '- Dynamic arrays and spilling';

		return $prompt;
	}

	/**
	 * Build system prompt for formula debugging.
	 *
	 * @param string $excel_version Excel version target.
	 * @return string System prompt.
	 */
	protected function build_debug_system_prompt( $excel_version ) {
		$prompt  = "You are an expert Excel formula debugger.\n\n";
		$prompt .= 'Target: ' . ucfirst( $excel_version ) . " Excel\n\n";
		$prompt .= "Task: Identify and fix issues in Excel formulas.\n\n";
		$prompt .= "Common issues to check:\n";
		$prompt .= "- Syntax errors (missing parentheses, commas)\n";
		$prompt .= "- Reference errors (wrong cell ranges)\n";
		$prompt .= "- Logic errors (incorrect operator order)\n";
		$prompt .= "- Type mismatches (text vs numbers)\n";
		$prompt .= "- Circular references\n";
		$prompt .= "- Array formula issues\n";
		$prompt .= "- Volatile function overuse\n\n";
		$prompt .= "Response format (JSON):\n";
		$prompt .= "{\n";
		$prompt .= '  "issues": "List of identified problems",';
		$prompt .= "\n";
		$prompt .= '  "corrected_formula": "The fixed formula",';
		$prompt .= "\n";
		$prompt .= '  "fixes": "What was changed and why",';
		$prompt .= "\n";
		$prompt .= '  "content": "Full explanation"';
		$prompt .= "\n";
		$prompt .= '}';

		return $prompt;
	}

	/**
	 * Build system prompt for formula documentation.
	 *
	 * @return string System prompt.
	 */
	protected function build_document_system_prompt() {
		$prompt  = "You are an expert technical writer specializing in Excel documentation.\n\n";
		$prompt .= "Task: Create professional, maintainable documentation for Excel formulas.\n\n";
		$prompt .= "Documentation should include:\n";
		$prompt .= "- Clear purpose statement\n";
		$prompt .= "- Input requirements (data types, ranges)\n";
		$prompt .= "- Expected output\n";
		$prompt .= "- Step-by-step usage instructions\n";
		$prompt .= "- Edge cases and limitations\n";
		$prompt .= "- Maintenance considerations\n";
		$prompt .= "- Performance notes\n\n";
		$prompt .= 'Write for an audience that may not have created the formula but needs to maintain it.';

		return $prompt;
	}

	/**
	 * Build system prompt for LAMBDA conversion.
	 *
	 * @return string System prompt.
	 */
	protected function build_convert_system_prompt() {
		$prompt  = "You are an expert in Excel's LAMBDA functions and formula optimization.\n\n";
		$prompt .= "Task: Convert multi-step calculations into single, reusable LAMBDA functions.\n\n";
		$prompt .= "Benefits of LAMBDA consolidation:\n";
		$prompt .= "- Eliminate helper columns\n";
		$prompt .= "- Create reusable logic\n";
		$prompt .= "- Improve maintainability\n";
		$prompt .= "- Enable recursive algorithms\n";
		$prompt .= "- Reduce formula duplication\n\n";
		$prompt .= "Response format (JSON):\n";
		$prompt .= "{\n";
		$prompt .= '  "lambda": "The consolidated LAMBDA function",';
		$prompt .= "\n";
		$prompt .= '  "usage": "How to use the function",';
		$prompt .= "\n";
		$prompt .= '  "benefits": "Why this consolidation helps",';
		$prompt .= "\n";
		$prompt .= '  "content": "Full explanation"';
		$prompt .= "\n";
		$prompt .= '}';

		return $prompt;
	}

	/**
	 * Call AI model to process the request.
	 *
	 * @param string $system_prompt System instructions.
	 * @param string $user_prompt   User request.
	 * @param array  $arguments     Tool arguments (may include model preference).
	 * @param array  $context       Execution context.
	 * @return array|WP_Error AI response or error.
	 */
	protected function call_ai_model( $system_prompt, $user_prompt, array $arguments, array $context ) {
		// Get model preference.
		$model = isset( $arguments['model'] ) ? sanitize_text_field( $arguments['model'] ) : '';

		// If no model specified, try to get from assistant context or use default.
		if ( empty( $model ) ) {
			if ( isset( $context['assistant_id'] ) ) {
				$assistant_id = absint( $context['assistant_id'] );
				$model        = get_post_meta( $assistant_id, '_wp_mcp_ai_model', true );
			}

			if ( empty( $model ) ) {
				// Get global default model.
				if ( class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
					$model = WP_MCP_AI_Settings_Registry::get_setting( 'default_model', 'gpt-4o-mini' );
				} else {
					$model = 'gpt-4o-mini';
				}
			}
		}

		// Prepare messages for AI model.
		$messages = array(
			array(
				'role'    => 'system',
				'content' => $system_prompt,
			),
			array(
				'role'    => 'user',
				'content' => $user_prompt,
			),
		);

		// Get AI provider based on model.
		$provider = $this->get_provider_for_model( $model );

		// Call the appropriate provider.
		$response = $this->call_provider( $provider, $model, $messages, $context );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		// Try to parse JSON response if present.
		$content = $response['content'] ?? '';
		$parsed  = $this->try_parse_json_response( $content );

		if ( $parsed ) {
			// JSON response successfully parsed.
			return array_merge( array( 'content' => $content ), $parsed );
		}

		// Plain text response.
		return array( 'content' => $content );
	}

	/**
	 * Get provider name for a model.
	 *
	 * @param string $model Model identifier.
	 * @return string Provider name (openai, gemini, ollama).
	 */
	protected function get_provider_for_model( $model ) {
		// Check for Gemini models.
		if ( false !== strpos( $model, 'gemini' ) ) {
			return 'gemini';
		}

		// Check for Ollama models.
		if ( false !== strpos( $model, 'llama' ) || false !== strpos( $model, 'mistral' ) || false !== strpos( $model, 'qwen' ) ) {
			return 'ollama';
		}

		// Default to OpenAI.
		return 'openai';
	}

	/**
	 * Call AI provider with messages.
	 *
	 * @param string $provider Provider name.
	 * @param string $model    Model identifier.
	 * @param array  $messages Message array.
	 * @param array  $context  Execution context.
	 * @return array|WP_Error Response or error.
	 */
	protected function call_provider( $provider, $model, array $messages, array $context ) {
		// Load provider classes if needed.
		if ( ! class_exists( 'WP_MCP_AI_OpenAI_Provider' ) ) {
			require_once WP_MCP_AI_PATH . 'includes/providers/class-wp-mcp-ai-openai-provider.php';
		}

		try {
			switch ( $provider ) {
				case 'gemini':
					if ( ! class_exists( 'WP_MCP_AI_Gemini_Provider' ) ) {
						require_once WP_MCP_AI_PATH . 'includes/providers/class-wp-mcp-ai-gemini-provider.php';
					}
					$provider_instance = new WP_MCP_AI_Gemini_Provider();
					break;

				case 'ollama':
					if ( ! class_exists( 'WP_MCP_AI_Ollama_Provider' ) ) {
						require_once WP_MCP_AI_PATH . 'includes/providers/class-wp-mcp-ai-ollama-provider.php';
					}
					$provider_instance = new WP_MCP_AI_Ollama_Provider();
					break;

				case 'openai':
				default:
					$provider_instance = new WP_MCP_AI_OpenAI_Provider();
					break;
			}

			// Make API call.
			$response = $provider_instance->create_chat_completion(
				array(
					'model'    => $model,
					'messages' => $messages,
				)
			);

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			// Extract content from response.
			$content = '';
			if ( isset( $response['choices'][0]['message']['content'] ) ) {
				$content = $response['choices'][0]['message']['content'];
			} elseif ( isset( $response['content'] ) ) {
				$content = $response['content'];
			}

			return array( 'content' => $content );

		} catch ( Exception $e ) {
			return new WP_Error(
				'wp_mcp_ai_provider_error',
				sprintf(
					/* translators: %s: error message */
					__( 'AI provider error: %s', 'mcp-ai-wpoos' ),
					$e->getMessage()
				)
			);
		}
	}

	/**
	 * Try to parse JSON response from AI model.
	 *
	 * @param string $content Response content.
	 * @return array|false Parsed JSON or false if not valid JSON.
	 */
	protected function try_parse_json_response( $content ) {
		// Try to find JSON in the response (may be wrapped in markdown code blocks).
		$json_pattern = '/```(?:json)?\s*(\{.*?\})\s*```/s';
		if ( preg_match( $json_pattern, $content, $matches ) ) {
			$json_str = $matches[1];
		} elseif ( preg_match( '/\{.*\}/s', $content, $matches ) ) {
			// Try to find JSON object directly.
			$json_str = $matches[0];
		} else {
			return false;
		}

		$parsed = json_decode( $json_str, true );

		if ( json_last_error() === JSON_ERROR_NONE && is_array( $parsed ) ) {
			return $parsed;
		}

		return false;
	}
}
