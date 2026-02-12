<?php
/**
 * Code Formatter Service using Prettier
 *
 * Provides code formatting capabilities using the Prettier NPM package.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Prettier Code Formatter Service class
 *
 * Formats code using Prettier with support for:
 * - JavaScript/TypeScript
 * - PHP (with prettier-plugin-php)
 * - CSS/SCSS/Less
 * - HTML
 * - JSON/YAML
 * - Markdown
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Prettier_Service {

	/**
	 * Check if Prettier package is available
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		// Check if prettier package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/prettier/standalone.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/prettier/index.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		return $process_service->is_command_available( 'node' );
	}

	/**
	 * Format code using Prettier
	 *
	 * @param string $code     Code to format.
	 * @param array  $options  Formatting options.
	 * @return string|WP_Error Formatted code or error.
	 */
	public function format_code( $code, $options = array() ) {
		if ( empty( $code ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_code',
				__( 'Code content is empty.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$defaults = array(
			'parser'         => 'babel',      // Parser: babel, typescript, css, html, markdown, php, json, yaml.
			'printWidth'     => 80,           // Line width.
			'tabWidth'       => 2,            // Tab width.
			'useTabs'        => true,         // Use tabs instead of spaces.
			'semi'           => true,         // Add semicolons.
			'singleQuote'    => true,         // Use single quotes.
			'trailingComma'  => 'es5',        // Trailing commas: none, es5, all.
			'bracketSpacing' => true,         // Spaces in object literals.
			'arrowParens'    => 'always',     // Arrow function parens: always, avoid.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'  => 'format_code',
			'code'    => $code,
			'options' => $options,
		);

		/**
		 * Filter to allow custom Prettier code formatting.
		 *
		 * @param string|false $result Formatted code or false.
		 * @param array        $params Formatting parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_prettier_format_code', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_prettier_not_configured',
				__( 'Prettier code formatting requires Node.js integration. Please implement the wp_mcp_ai_prettier_format_code filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 501,
					'package' => 'prettier',
				)
			);
		}

		return $result;
	}

	/**
	 * Check code syntax without formatting
	 *
	 * @param string $code    Code to check.
	 * @param string $parser  Parser to use.
	 * @return bool|WP_Error True if valid, error if invalid.
	 */
	public function check_syntax( $code, $parser = 'babel' ) {
		if ( empty( $code ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_code',
				__( 'Code content is empty.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$params = array(
			'action' => 'check_syntax',
			'code'   => $code,
			'parser' => sanitize_text_field( $parser ),
		);

		/**
		 * Filter to allow custom Prettier syntax checking.
		 *
		 * @param bool|WP_Error|false $result Check result, error, or false if not implemented.
		 * @param array               $params Check parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_prettier_check_syntax', false, $params );

		if ( false === $result ) {
			// If not implemented, return true (no validation).
			return true;
		}

		return $result;
	}

	/**
	 * Get supported file types and their parsers
	 *
	 * @return array Supported file types.
	 */
	public function get_supported_types() {
		return array(
			'js'       => 'babel',
			'jsx'      => 'babel',
			'ts'       => 'typescript',
			'tsx'      => 'typescript',
			'css'      => 'css',
			'scss'     => 'scss',
			'less'     => 'less',
			'html'     => 'html',
			'php'      => 'php',
			'json'     => 'json',
			'yaml'     => 'yaml',
			'yml'      => 'yaml',
			'md'       => 'markdown',
			'markdown' => 'markdown',
		);
	}

	/**
	 * Detect parser from file extension
	 *
	 * @param string $filename Filename or extension.
	 * @return string Parser name.
	 */
	public function detect_parser( $filename ) {
		$extension = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );
		$types     = $this->get_supported_types();

		return isset( $types[ $extension ] ) ? $types[ $extension ] : 'babel';
	}
}
