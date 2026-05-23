<?php
/**
 * Tool for formatting code using Prettier.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Format code using Prettier for clean, consistent output.
 *
 * This tool leverages Prettier to provide:
 * - Automatic code formatting
 * - Support for JavaScript, TypeScript, CSS, HTML, PHP, JSON, YAML, Markdown
 * - Configurable formatting rules
 * - Syntax validation
 * - Perfect for AI-generated code cleanup
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Format_Code_Prettier implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'format_code_prettier';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Format Code with Prettier', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Format code using Prettier for clean, consistent output. Supports JavaScript, TypeScript, CSS, HTML, PHP, JSON, YAML, and Markdown. Perfect for formatting AI-generated code or cleaning up existing code snippets.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'code'           => array(
					'type'        => 'string',
					'description' => __( 'Code content to format', 'mcp-ai-wpoos-pro' ),
				),
				'language'       => array(
					'type'        => 'string',
					'enum'        => array( 'javascript', 'typescript', 'css', 'html', 'php', 'json', 'yaml', 'markdown' ),
					'description' => __( 'Programming language of the code', 'mcp-ai-wpoos-pro' ),
					'default'     => 'javascript',
				),
				'tab_width'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of spaces per indentation level (default: 2 for JS, 4 for PHP)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 8,
				),
				'use_tabs'       => array(
					'type'        => 'boolean',
					'description' => __( 'Use tabs instead of spaces for indentation', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'single_quote'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use single quotes instead of double quotes', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'print_width'    => array(
					'type'        => 'integer',
					'description' => __( 'Maximum line length before wrapping (default: 80)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 40,
					'maximum'     => 200,
					'default'     => 80,
				),
				'trailing_comma' => array(
					'type'        => 'string',
					'enum'        => array( 'none', 'es5', 'all' ),
					'description' => __( 'Add trailing commas: none, es5 (objects/arrays), or all', 'mcp-ai-wpoos-pro' ),
					'default'     => 'es5',
				),
				'check_syntax'   => array(
					'type'        => 'boolean',
					'description' => __( 'Validate code syntax before formatting', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'snippet_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Optional WPCode snippet ID to format (updates the snippet)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
			),
			'required'   => array( 'code' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Primarily read operation.
			'requires-capability',  // Requires edit_posts capability.
			'external-dependency',  // Requires Prettier (Node.js).
			'idempotent',           // Same input produces same output.
			'state-changing',       // May update WPCode snippets.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Validate code input.
		if ( empty( $arguments['code'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Code content is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$code = $arguments['code'];

		// Check if Prettier service is available.
		require_once WP_MCP_AI_PRO_PATH . 'includes/services/class-wp-mcp-ai-prettier-service.php';
		$prettier_service = new WP_MCP_AI_Prettier_Service();

		if ( ! $prettier_service->is_available() ) {
			return array(
				'success' => false,
				'error'   => __( 'Prettier is not available. Please ensure Node.js and Prettier package are installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Get language and determine parser.
		$language   = isset( $arguments['language'] ) ? sanitize_text_field( $arguments['language'] ) : 'javascript';
		$parser_map = array(
			'javascript' => 'babel',
			'typescript' => 'typescript',
			'css'        => 'css',
			'html'       => 'html',
			'php'        => 'php',
			'json'       => 'json',
			'yaml'       => 'yaml',
			'markdown'   => 'markdown',
		);
		$parser     = isset( $parser_map[ $language ] ) ? $parser_map[ $language ] : 'babel';

		// Check syntax if requested.
		$check_syntax = isset( $arguments['check_syntax'] ) ? (bool) $arguments['check_syntax'] : true;
		if ( $check_syntax ) {
			$syntax_check = $prettier_service->check_syntax( $code, $parser );
			if ( is_wp_error( $syntax_check ) ) {
				return array(
					'success' => false,
					'error'   => sprintf(
						/* translators: %s: error message */
						__( 'Syntax error detected: %s', 'mcp-ai-wpoos-pro' ),
						$syntax_check->get_error_message()
					),
				);
			}
		}

		// Build formatting options.
		$format_options = array(
			'parser'         => $parser,
			'printWidth'     => isset( $arguments['print_width'] ) ? absint( $arguments['print_width'] ) : 80,
			'tabWidth'       => isset( $arguments['tab_width'] ) ? absint( $arguments['tab_width'] ) : ( 'php' === $language ? 4 : 2 ),
			'useTabs'        => isset( $arguments['use_tabs'] ) ? (bool) $arguments['use_tabs'] : true,
			'singleQuote'    => isset( $arguments['single_quote'] ) ? (bool) $arguments['single_quote'] : true,
			'trailingComma'  => isset( $arguments['trailing_comma'] ) ? sanitize_text_field( $arguments['trailing_comma'] ) : 'es5',
			'semi'           => true,
			'bracketSpacing' => true,
			'arrowParens'    => 'always',
		);

		// Format code.
		$formatted_code = $prettier_service->format_code( $code, $format_options );

		if ( is_wp_error( $formatted_code ) ) {
			return array(
				'success' => false,
				'error'   => sprintf(
					/* translators: %s: error message */
					__( 'Code formatting failed: %s', 'mcp-ai-wpoos-pro' ),
					$formatted_code->get_error_message()
				),
			);
		}

		$result = array(
			'success'         => true,
			'message'         => __( 'Code formatted successfully with Prettier.', 'mcp-ai-wpoos-pro' ),
			'formatted_code'  => $formatted_code,
			'language'        => $language,
			'original_lines'  => substr_count( $code, "\n" ) + 1,
			'formatted_lines' => substr_count( $formatted_code, "\n" ) + 1,
		);

		// Update WPCode snippet if snippet_id provided.
		if ( isset( $arguments['snippet_id'] ) && absint( $arguments['snippet_id'] ) > 0 ) {
			$snippet_id = absint( $arguments['snippet_id'] );
			$updated    = $this->update_wpcode_snippet( $snippet_id, $formatted_code );

			if ( is_wp_error( $updated ) ) {
				$result['warning'] = sprintf(
					/* translators: %s: error message */
					__( 'Code formatted but snippet update failed: %s', 'mcp-ai-wpoos-pro' ),
					$updated->get_error_message()
				);
			} else {
				$result['snippet_updated'] = true;
				$result['snippet_id']      = $snippet_id;
			}
		}

		return $result;
	}

	/**
	 * Update WPCode snippet with formatted code
	 *
	 * @param int    $snippet_id Snippet post ID.
	 * @param string $code       Formatted code.
	 * @return bool|WP_Error True on success, error on failure.
	 */
	private function update_wpcode_snippet( $snippet_id, $code ) {
		// Check if WPCode is active.
		if ( ! class_exists( 'WPCode' ) ) {
			return new WP_Error(
				'wp_mcp_ai_wpcode_not_active',
				__( 'WPCode plugin is not active.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Verify snippet exists.
		$snippet = get_post( $snippet_id );
		if ( ! $snippet || 'wpcode' !== $snippet->post_type ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_snippet',
				__( 'Invalid WPCode snippet ID.', 'mcp-ai-wpoos-pro' )
			);
		}

		// Update snippet code.
		update_post_meta( $snippet_id, 'wpcode_snippet', $code );

		return true;
	}
}
