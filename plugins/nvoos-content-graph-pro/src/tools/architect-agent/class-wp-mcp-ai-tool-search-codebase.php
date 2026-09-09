<?php
/**
 * Tool_Search_Codebase (ecosystem port - Wave F2, architect-agent toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/architect-agent/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see
 * the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` incl.
 * upstream mixed base-domain strings swapped (cloudways-client precedent); `WP_MCP_AI_PATH`
 * workspace-root refs swap to `NVOOS_CONTENT_GRAPH_PRO_PATH` (the standalone workspace is the
 * addon); the base-owned proc helpers (`wp_mcp_ai_run_process|run_shell|find_binary`) resolve from
 * the addon's D8-compat `src/tools/architect-agent/helpers.php` copy.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Architect_Agent
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);




if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Search Codebase tool for finding code elements.
 *
 * This tool enables an "Architect Agent" to search through the plugin codebase,
 * similar to GitHub Copilot CLI's search functionality.
 *
 * Security features:
 * - Requires edit_plugins capability
 * - Restricted to plugin directory
 * - Read-only operation
 * - No file modifications
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Search_Codebase implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_codebase';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Codebase', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Search the plugin codebase for patterns, functions, classes, and files. Supports grep-style pattern matching, file type filtering, and symbol search. Similar to GitHub Copilot CLI search capabilities.', 'nvoos-content-graph-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'query'           => array(
					'type'        => 'string',
					'description' => __( 'Search query. Can be a regex pattern, function name, class name, or plain text.', 'nvoos-content-graph-pro' ),
				),
				'search_type'     => array(
					'type'        => 'string',
					'enum'        => array( 'text', 'function', 'class', 'file', 'symbol' ),
					'description' => __( 'Type of search: "text" (grep-style text search), "function" (find function definitions), "class" (find class definitions), "file" (find files by name), "symbol" (find any symbol).', 'nvoos-content-graph-pro' ),
					'default'     => 'text',
				),
				'file_pattern'    => array(
					'type'        => 'string',
					'description' => __( 'File pattern to filter results. Examples: "*.php", "*.js", "class-*.php". Default: all files.', 'nvoos-content-graph-pro' ),
				),
				'exclude_pattern' => array(
					'type'        => 'string',
					'description' => __( 'Pattern to exclude from search. Examples: "vendor/*", "node_modules/*", "*.min.js".', 'nvoos-content-graph-pro' ),
				),
				'case_sensitive'  => array(
					'type'        => 'boolean',
					'description' => __( 'Whether search should be case-sensitive. Default: false.', 'nvoos-content-graph-pro' ),
					'default'     => false,
				),
				'limit'           => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of results to return. Default: 50, Max: 200.', 'nvoos-content-graph-pro' ),
					'default'     => 50,
					'minimum'     => 1,
					'maximum'     => 200,
				),
				'context_lines'   => array(
					'type'        => 'integer',
					'description' => __( 'Number of context lines to show around matches. Default: 2.', 'nvoos-content-graph-pro' ),
					'default'     => 2,
					'minimum'     => 0,
					'maximum'     => 10,
				),
			),
			'required'             => array( 'query' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                     // Pro tier feature.
			'requires-capability',     // Requires edit_plugins capability.
			'read-only',               // Read-only operation.
			'local-only',              // Works locally, no external APIs.
			'architect-agent',         // Core Architect Agent capability.
			'code-search',             // Can search codebase.
			'requires-workspace-trust', // Requires workspace trust (security).
			'development-workflow',    // Part of development lifecycle.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_plugins';
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Shell-tools constant and capability gate (F-EXEC-01 / R-S-02).
		if ( ! defined( 'WP_MCP_AI_ALLOW_SHELL_TOOLS' ) || ! WP_MCP_AI_ALLOW_SHELL_TOOLS ) {
			return $this->error_response( __( 'Shell tools are disabled. Set define( \'WP_MCP_AI_ALLOW_SHELL_TOOLS\', true ) in wp-config.php to enable them.', 'nvoos-content-graph-pro' ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			return $this->error_response( __( 'You do not have permission to run shell commands.', 'nvoos-content-graph-pro' ) );
		}

		// Extract arguments.
		$query           = isset( $arguments['query'] ) ? trim( $arguments['query'] ) : '';
		$search_type     = isset( $arguments['search_type'] ) ? sanitize_text_field( $arguments['search_type'] ) : 'text';
		$file_pattern    = isset( $arguments['file_pattern'] ) ? sanitize_text_field( $arguments['file_pattern'] ) : '';
		$exclude_pattern = isset( $arguments['exclude_pattern'] ) ? sanitize_text_field( $arguments['exclude_pattern'] ) : '';
		$case_sensitive  = isset( $arguments['case_sensitive'] ) ? (bool) $arguments['case_sensitive'] : false;
		$limit           = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 50;
		$context_lines   = isset( $arguments['context_lines'] ) ? absint( $arguments['context_lines'] ) : 2;

		// Validate query.
		if ( empty( $query ) ) {
			return $this->error_response( __( 'Search query is required.', 'nvoos-content-graph-pro' ) );
		}

		// Validate limits.
		$limit         = max( 1, min( 200, $limit ) );
		$context_lines = max( 0, min( 10, $context_lines ) );

		// Execute search based on type.
		switch ( $search_type ) {
			case 'text':
				return $this->search_text( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit, $context_lines );

			case 'function':
				return $this->search_functions( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit );

			case 'class':
				return $this->search_classes( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit );

			case 'file':
				return $this->search_files( $query, $exclude_pattern, $case_sensitive, $limit );

			case 'symbol':
				return $this->search_symbols( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit );

			default:
				return $this->error_response(
					sprintf(
						/* translators: %s: search type */
						__( 'Unsupported search type: %s', 'nvoos-content-graph-pro' ),
						esc_html( $search_type )
					)
				);
		}
	}

	/**
	 * Search for text patterns.
	 *
	 * @param string $query           Search query.
	 * @param string $file_pattern    File pattern.
	 * @param string $exclude_pattern Exclude pattern.
	 * @param bool   $case_sensitive  Case sensitive search.
	 * @param int    $limit           Result limit.
	 * @param int    $context_lines   Context lines.
	 * @return array Search results.
	 */
	private function search_text( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit, $context_lines ) {
		$grep_cmd = $this->build_grep_command( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit, $context_lines );

		$proc_result = wp_mcp_ai_run_shell( $grep_cmd . ' 2>&1', NVOOS_CONTENT_GRAPH_PRO_PATH );
		$output      = array_filter( explode( "\n", $proc_result['stdout'] ) );

		$results = $this->parse_grep_output( $output );

		return array(
			'search_type' => 'text',
			'query'       => $query,
			'results'     => $results,
			'count'       => count( $results ),
			'limit'       => $limit,
		);
	}

	/**
	 * Search for function definitions.
	 *
	 * @param string $query           Function name pattern.
	 * @param string $file_pattern    File pattern.
	 * @param string $exclude_pattern Exclude pattern.
	 * @param bool   $case_sensitive  Case sensitive search.
	 * @param int    $limit           Result limit.
	 * @return array Search results.
	 */
	private function search_functions( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit ) {
		// Build pattern to match function definitions.
		$pattern = sprintf( 'function\s+%s\s*\(', preg_quote( $query, '/' ) );

		return $this->search_text( $pattern, $file_pattern ? $file_pattern : '*.php', $exclude_pattern, $case_sensitive, $limit, 3 );
	}

	/**
	 * Search for class definitions.
	 *
	 * @param string $query           Class name pattern.
	 * @param string $file_pattern    File pattern.
	 * @param string $exclude_pattern Exclude pattern.
	 * @param bool   $case_sensitive  Case sensitive search.
	 * @param int    $limit           Result limit.
	 * @return array Search results.
	 */
	private function search_classes( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit ) {
		// Build pattern to match class definitions.
		$pattern = sprintf( 'class\s+%s\s', preg_quote( $query, '/' ) );

		return $this->search_text( $pattern, $file_pattern ? $file_pattern : '*.php', $exclude_pattern, $case_sensitive, $limit, 3 );
	}

	/**
	 * Search for files by name.
	 *
	 * @param string $query           File name pattern.
	 * @param string $exclude_pattern Exclude pattern.
	 * @param bool   $case_sensitive  Case sensitive search.
	 * @param int    $limit           Result limit.
	 * @return array Search results.
	 */
	private function search_files( $query, $exclude_pattern, $case_sensitive, $limit ) {
		$find_cmd = 'find . -type f';

		if ( ! $case_sensitive ) {
			$find_cmd .= ' -iname ' . escapeshellarg( '*' . $query . '*' );
		} else {
			$find_cmd .= ' -name ' . escapeshellarg( '*' . $query . '*' );
		}

		// Add exclusions.
		$default_excludes = array( '.git', 'vendor', 'node_modules', '.sass-cache' );
		foreach ( $default_excludes as $exclude ) {
			$find_cmd .= ' -not -path ' . escapeshellarg( '*/' . $exclude . '/*' );
		}

		if ( ! empty( $exclude_pattern ) ) {
			$find_cmd .= ' -not -path ' . escapeshellarg( '*/' . $exclude_pattern );
		}

		$find_cmd .= ' | head -n ' . $limit;

		$proc_result = wp_mcp_ai_run_shell( $find_cmd . ' 2>&1', NVOOS_CONTENT_GRAPH_PRO_PATH );
		$output      = array_filter( explode( "\n", $proc_result['stdout'] ) );

		$results = array();
		foreach ( $output as $file ) {
			$file = ltrim( $file, './' );
			if ( ! empty( $file ) ) {
				$results[] = array(
					'file' => $file,
					'path' => NVOOS_CONTENT_GRAPH_PRO_PATH . $file,
				);
			}
		}

		return array(
			'search_type' => 'file',
			'query'       => $query,
			'results'     => $results,
			'count'       => count( $results ),
			'limit'       => $limit,
		);
	}

	/**
	 * Search for any symbol (function, class, const, etc.).
	 *
	 * @param string $query           Symbol name pattern.
	 * @param string $file_pattern    File pattern.
	 * @param string $exclude_pattern Exclude pattern.
	 * @param bool   $case_sensitive  Case sensitive search.
	 * @param int    $limit           Result limit.
	 * @return array Search results.
	 */
	private function search_symbols( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit ) {
		// Search for various symbol types.
		$patterns = array(
			sprintf( '\b%s\b', preg_quote( $query, '/' ) ), // Any word boundary match.
		);

		return $this->search_text( implode( '|', $patterns ), $file_pattern, $exclude_pattern, $case_sensitive, $limit, 2 );
	}

	/**
	 * Build grep command.
	 *
	 * @param string $query           Search query.
	 * @param string $file_pattern    File pattern.
	 * @param string $exclude_pattern Exclude pattern.
	 * @param bool   $case_sensitive  Case sensitive.
	 * @param int    $limit           Result limit.
	 * @param int    $context_lines   Context lines.
	 * @return string Grep command.
	 */
	private function build_grep_command( $query, $file_pattern, $exclude_pattern, $case_sensitive, $limit, $context_lines ) {
		$cmd = 'grep -rn';

		// Case sensitivity.
		if ( ! $case_sensitive ) {
			$cmd .= 'i';
		}

		// Context lines.
		if ( $context_lines > 0 ) {
			$cmd .= ' -C ' . absint( $context_lines );
		}

		// File pattern.
		if ( ! empty( $file_pattern ) ) {
			$cmd .= ' --include=' . escapeshellarg( $file_pattern );
		}

		// Default exclusions - directories.
		$default_exclude_dirs = array( '.git', 'vendor', 'node_modules', '.sass-cache' );
		foreach ( $default_exclude_dirs as $exclude ) {
			$cmd .= ' --exclude-dir=' . escapeshellarg( $exclude );
		}

		// Default exclusions - file patterns.
		$default_exclude_files = array( '*.min.js', '*.min.css' );
		foreach ( $default_exclude_files as $exclude ) {
			$cmd .= ' --exclude=' . escapeshellarg( $exclude );
		}

		// Custom exclusion.
		if ( ! empty( $exclude_pattern ) ) {
			$cmd .= ' --exclude=' . escapeshellarg( $exclude_pattern );
		}

		// Query - sanitize for shell.
		$cmd .= ' ' . escapeshellarg( $query ) . ' .';

		// Limit results.
		$cmd .= ' | head -n ' . absint( $limit * 3 ); // Account for context lines.

		return $cmd;
	}

	/**
	 * Parse grep output.
	 *
	 * @param array $output Grep output lines.
	 * @return array Parsed results.
	 */
	private function parse_grep_output( $output ) {
		$results       = array();
		$current_match = null;

		foreach ( $output as $line ) {
			// Parse grep output format: file:line:content.
			if ( preg_match( '/^(.+?):(\d+):(.*)$/', $line, $matches ) ) {
				if ( $current_match ) {
					$results[] = $current_match;
				}

				$current_match = array(
					'file'    => ltrim( $matches[1], './' ),
					'line'    => intval( $matches[2] ),
					'content' => $matches[3],
					'context' => array(),
				);
			} elseif ( preg_match( '/^(.+?)-(\d+)-(.*)$/', $line, $matches ) && $current_match ) {
				// Context line.
				$current_match['context'][] = array(
					'line'    => intval( $matches[2] ),
					'content' => $matches[3],
				);
			}
		}

		if ( $current_match ) {
			$results[] = $current_match;
		}

		return $results;
	}

	/**
	 * Generate error response.
	 *
	 * @param string $message Error message.
	 * @return array Error response.
	 */
	private function error_response( $message ) {
		return array(
			'search_type' => 'error',
			'message'     => $message,
			'results'     => array(),
			'count'       => 0,
		);
	}
}
