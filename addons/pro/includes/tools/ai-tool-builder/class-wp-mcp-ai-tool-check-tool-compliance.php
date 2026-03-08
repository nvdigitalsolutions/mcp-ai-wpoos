<?php
/**
 * Tool for checking WordPress coding standards compliance.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @phase Phase 2.9
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Check AI tool code for WordPress coding standards compliance.
 *
 * This tool validates code against WPCS (WordPress Coding Standards),
 * checks formatting, naming conventions, documentation requirements,
 * and provides auto-fix suggestions where possible.
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Check_Tool_Compliance implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'check_tool_compliance';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Check Tool Compliance', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Check AI tool code for WordPress coding standards compliance. Validates WPCS rules, formatting, naming conventions, PHPDoc requirements, and provides auto-fix suggestions. Optionally runs PHPCS if available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'tool_file' => array(
					'type'        => 'string',
					'description' => __( 'Path to tool file to check', 'mcp-ai-wpoos-pro' ),
				),
				'code'      => array(
					'type'        => 'string',
					'description' => __( 'Tool code to check (alternative to file_path)', 'mcp-ai-wpoos-pro' ),
				),
				'standards' => array(
					'type'        => 'array',
					'description' => __( 'Coding standards to check', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'wpcs', 'phpdoc', 'naming', 'formatting', 'i18n' ),
					),
					'default'     => array( 'wpcs', 'phpdoc', 'naming' ),
				),
				'use_phpcs' => array(
					'type'        => 'boolean',
					'description' => __( 'Run PHPCS if available', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'auto_fix'  => array(
					'type'        => 'boolean',
					'description' => __( 'Attempt to auto-fix issues (requires file_path)', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'severity'  => array(
					'type'        => 'string',
					'enum'        => array( 'error', 'warning', 'all' ),
					'description' => __( 'Minimum severity to report', 'mcp-ai-wpoos-pro' ),
					'default'     => 'warning',
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
			'requires-capability',
			'local-only',
			'external-dependency',
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Get code to check.
		$code      = '';
		$file_path = '';

		if ( ! empty( $arguments['code'] ) ) {
			$code = $arguments['code'];
		} elseif ( ! empty( $arguments['tool_file'] ) ) {
			$file_path = sanitize_text_field( $arguments['tool_file'] );

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
					'error'   => __( 'Tool file not found or not accessible.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Security: Restrict to the WordPress content directory (plugins, themes, etc.).
			if ( 0 !== strpos( wp_normalize_path( $resolved ), trailingslashit( wp_normalize_path( WP_CONTENT_DIR ) ) ) ) {
				return array(
					'success' => false,
					'error'   => __( 'File must be in the WordPress content directory.', 'mcp-ai-wpoos-pro' ),
				);
			}

			// Use the resolved path for all subsequent operations (run_phpcs, auto_fix_issues, etc.).
			$file_path = $resolved;

			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for local PHP file reading.
			$code = file_get_contents( $file_path );
		}

		if ( empty( $code ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Either code or tool_file must be provided.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$standards = isset( $arguments['standards'] ) ? array_map( 'sanitize_text_field', (array) $arguments['standards'] ) : array( 'wpcs', 'phpdoc', 'naming' );
		$use_phpcs = isset( $arguments['use_phpcs'] ) ? (bool) $arguments['use_phpcs'] : true;
		$auto_fix  = isset( $arguments['auto_fix'] ) ? (bool) $arguments['auto_fix'] : false;
		$severity  = isset( $arguments['severity'] ) ? sanitize_text_field( $arguments['severity'] ) : 'warning';

		// Perform compliance checks.
		$compliance_issues = array();

		if ( in_array( 'wpcs', $standards, true ) && $use_phpcs ) {
			$phpcs_issues = $this->run_phpcs( $file_path, $code );
			if ( ! is_wp_error( $phpcs_issues ) ) {
				$compliance_issues = array_merge( $compliance_issues, $phpcs_issues );
			}
		}

		if ( in_array( 'phpdoc', $standards, true ) ) {
			$compliance_issues = array_merge( $compliance_issues, $this->check_phpdoc( $code ) );
		}

		if ( in_array( 'naming', $standards, true ) ) {
			$compliance_issues = array_merge( $compliance_issues, $this->check_naming_conventions( $code ) );
		}

		if ( in_array( 'formatting', $standards, true ) ) {
			$compliance_issues = array_merge( $compliance_issues, $this->check_formatting( $code ) );
		}

		if ( in_array( 'i18n', $standards, true ) ) {
			$compliance_issues = array_merge( $compliance_issues, $this->check_i18n( $code ) );
		}

		// Filter by severity.
		$filtered_issues = $this->filter_by_severity( $compliance_issues, $severity );

		// Auto-fix if requested.
		$fixed      = false;
		$fixed_code = null;
		if ( $auto_fix && ! empty( $file_path ) ) {
			$fix_result = $this->auto_fix_issues( $code, $filtered_issues, $file_path );
			if ( ! is_wp_error( $fix_result ) ) {
				$fixed      = true;
				$fixed_code = $fix_result;
			}
		}

		// Calculate compliance score.
		$compliance_score = $this->calculate_compliance_score( $filtered_issues );

		return array(
			'success'          => true,
			'compliance_score' => $compliance_score,
			'total_issues'     => count( $filtered_issues ),
			'error_count'      => $this->count_by_severity( $filtered_issues, 'error' ),
			'warning_count'    => $this->count_by_severity( $filtered_issues, 'warning' ),
			'issues'           => $filtered_issues,
			'auto_fixed'       => $fixed,
			'fixed_code'       => $fixed_code,
			'recommendations'  => $this->generate_recommendations( $filtered_issues ),
		);
	}

	/**
	 * Run PHPCS on code.
	 *
	 * @param string $file_path File path (if available).
	 * @param string $code      Code to check.
	 * @return array|WP_Error Issues found or error.
	 */
	private function run_phpcs( $file_path, $code ) {
		// Check if PHPCS is available.
		$phpcs_path = $this->find_phpcs();

		if ( ! $phpcs_path ) {
			return new WP_Error(
				'phpcs_not_found',
				__( 'PHPCS not found. Install via composer.', 'mcp-ai-wpoos-pro' )
			);
		}

		$issues = array();

		// Run PHPCS.
		if ( ! empty( $file_path ) ) {
			$command = sprintf(
				'%s --standard=WordPress --report=json %s 2>&1',
				escapeshellcmd( $phpcs_path ),
				escapeshellarg( $file_path )
			);

			$output = shell_exec( $command );

			if ( ! empty( $output ) ) {
				$result = json_decode( $output, true );

				if ( isset( $result['files'] ) ) {
					foreach ( $result['files'] as $file => $data ) {
						if ( isset( $data['messages'] ) ) {
							foreach ( $data['messages'] as $message ) {
								$issues[] = array(
									'type'     => 'phpcs',
									'severity' => 'error' === $message['type'] ? 'error' : 'warning',
									'message'  => $message['message'],
									'line'     => $message['line'],
									'source'   => isset( $message['source'] ) ? $message['source'] : '',
								);
							}
						}
					}
				}
			}
		}

		return $issues;
	}

	/**
	 * Find PHPCS executable.
	 *
	 * @return string|false PHPCS path or false.
	 */
	private function find_phpcs() {
		$possible_paths = array(
			WP_MCP_AI_PRO_PATH . '../../../vendor/bin/phpcs',
			'/usr/local/bin/phpcs',
			'/usr/bin/phpcs',
		);

		foreach ( $possible_paths as $path ) {
			if ( file_exists( $path ) ) {
				return $path;
			}
		}

		return false;
	}

	/**
	 * Check PHPDoc compliance.
	 *
	 * @param string $code Code to check.
	 * @return array Issues found.
	 */
	private function check_phpdoc( $code ) {
		$issues = array();

		// Check for class doc block.
		if ( strpos( $code, 'class ' ) !== false && strpos( $code, '/**' ) === false ) {
			$issues[] = array(
				'type'     => 'phpdoc',
				'severity' => 'error',
				'message'  => __( 'Class missing PHPDoc block.', 'mcp-ai-wpoos-pro' ),
				'line'     => 0,
			);
		}

		// Check for @package tag.
		if ( strpos( $code, '@package' ) === false ) {
			$issues[] = array(
				'type'     => 'phpdoc',
				'severity' => 'warning',
				'message'  => __( 'Missing @package tag in PHPDoc.', 'mcp-ai-wpoos-pro' ),
				'line'     => 0,
			);
		}

		// Check for @since tag.
		if ( strpos( $code, '@since' ) === false ) {
			$issues[] = array(
				'type'     => 'phpdoc',
				'severity' => 'warning',
				'message'  => __( 'Missing @since tag in PHPDoc.', 'mcp-ai-wpoos-pro' ),
				'line'     => 0,
			);
		}

		// Check public methods have doc blocks.
		preg_match_all( '/public function (\w+)\(/', $code, $matches, PREG_OFFSET_CAPTURE );
		if ( ! empty( $matches[0] ) ) {
			foreach ( $matches[0] as $match ) {
				$pos    = $match[1];
				$before = substr( $code, max( 0, $pos - 200 ), 200 );

				if ( strpos( $before, '/**' ) === false ) {
					$issues[] = array(
						'type'     => 'phpdoc',
						'severity' => 'warning',
						'message'  => sprintf(
							/* translators: %s: method name */
							__( 'Method %s missing PHPDoc block.', 'mcp-ai-wpoos-pro' ),
							$matches[1][ key( $matches[1] ) ][0]
						),
						'line'     => 0,
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Check naming conventions.
	 *
	 * @param string $code Code to check.
	 * @return array Issues found.
	 */
	private function check_naming_conventions( $code ) {
		$issues = array();

		// Check class name follows convention.
		if ( preg_match( '/class\s+(\w+)/', $code, $matches ) ) {
			$class_name = $matches[1];

			if ( strpos( $class_name, 'WP_MCP_AI_Tool_' ) !== 0 ) {
				$issues[] = array(
					'type'     => 'naming',
					'severity' => 'error',
					'message'  => __( 'Class name should start with WP_MCP_AI_Tool_.', 'mcp-ai-wpoos-pro' ),
					'line'     => 0,
				);
			}

			// Check for PascalCase after prefix.
			$suffix = str_replace( 'WP_MCP_AI_Tool_', '', $class_name );
			if ( ucwords( $suffix, '_' ) !== $suffix ) {
				$issues[] = array(
					'type'     => 'naming',
					'severity' => 'warning',
					'message'  => __( 'Class name should use PascalCase with underscores.', 'mcp-ai-wpoos-pro' ),
					'line'     => 0,
				);
			}
		}

		// Check method names are snake_case.
		preg_match_all( '/(?:private|protected|public)\s+function\s+(\w+)/', $code, $matches );
		if ( ! empty( $matches[1] ) ) {
			foreach ( $matches[1] as $method_name ) {
				if ( strtolower( $method_name ) !== $method_name ) {
					$issues[] = array(
						'type'     => 'naming',
						'severity' => 'warning',
						'message'  => sprintf(
							/* translators: %s: method name */
							__( 'Method %s should use snake_case.', 'mcp-ai-wpoos-pro' ),
							$method_name
						),
						'line'     => 0,
					);
				}
			}
		}

		return $issues;
	}

	/**
	 * Check code formatting.
	 *
	 * @param string $code Code to check.
	 * @return array Issues found.
	 */
	private function check_formatting( $code ) {
		$issues = array();

		// Check for tabs vs spaces (WordPress uses tabs).
		$lines = explode( "\n", $code );
		foreach ( $lines as $line_num => $line ) {
			if ( preg_match( '/^    /', $line ) && strpos( $line, "\t" ) === false ) {
				$issues[] = array(
					'type'     => 'formatting',
					'severity' => 'warning',
					'message'  => __( 'Use tabs for indentation, not spaces.', 'mcp-ai-wpoos-pro' ),
					'line'     => $line_num + 1,
				);
				break; // Only report once.
			}
		}

		// Check for proper brace placement.
		if ( preg_match( '/\)\s*\n\s*\{/', $code ) ) {
			$issues[] = array(
				'type'     => 'formatting',
				'severity' => 'warning',
				'message'  => __( 'Opening brace should be on same line as function/control structure.', 'mcp-ai-wpoos-pro' ),
				'line'     => 0,
			);
		}

		return $issues;
	}

	/**
	 * Check internationalization.
	 *
	 * @param string $code Code to check.
	 * @return array Issues found.
	 */
	private function check_i18n( $code ) {
		$issues = array();

		// Check for hardcoded strings that should be translatable.
		if ( preg_match( '/["\']([A-Z][a-z]+\s+[a-z]+)["\']/', $code, $matches ) ) {
			if ( strpos( $code, "__( '{$matches[1]}'" ) === false && strpos( $code, "_e( '{$matches[1]}'" ) === false ) {
				$issues[] = array(
					'type'     => 'i18n',
					'severity' => 'warning',
					'message'  => __( 'User-facing strings should be wrapped in translation functions.', 'mcp-ai-wpoos-pro' ),
					'line'     => 0,
				);
			}
		}

		// Check translation function has text domain.
		if ( preg_match( '/__\(\s*["\'][^"\']+["\']\s*\)/', $code ) ) {
			$issues[] = array(
				'type'     => 'i18n',
				'severity' => 'error',
				'message'  => __( 'Translation functions must include text domain (mcp-ai-wpoos-pro).', 'mcp-ai-wpoos-pro' ),
				'line'     => 0,
			);
		}

		return $issues;
	}

	/**
	 * Filter issues by severity.
	 *
	 * @param array  $issues   Issues to filter.
	 * @param string $severity Minimum severity.
	 * @return array Filtered issues.
	 */
	private function filter_by_severity( $issues, $severity ) {
		if ( 'all' === $severity ) {
			return $issues;
		}

		return array_filter(
			$issues,
			function ( $issue ) use ( $severity ) {
				if ( 'error' === $severity ) {
					return 'error' === $issue['severity'];
				}
				return true; // Warning includes both errors and warnings.
			}
		);
	}

	/**
	 * Auto-fix issues where possible.
	 *
	 * @param string $code      Original code.
	 * @param array  $issues    Issues to fix.
	 * @param string $file_path File path.
	 * @return string|WP_Error Fixed code or error.
	 */
	private function auto_fix_issues( $code, $issues, $file_path ) {
		// Use PHPCBF if available.
		$phpcbf_path = str_replace( 'phpcs', 'phpcbf', $this->find_phpcs() );

		if ( $phpcbf_path && file_exists( $phpcbf_path ) ) {
			$command = sprintf(
				'%s --standard=WordPress %s 2>&1',
				escapeshellcmd( $phpcbf_path ),
				escapeshellarg( $file_path )
			);

			shell_exec( $command );

			return file_get_contents( $file_path );
		}

		return new WP_Error( 'no_auto_fix', __( 'Auto-fix not available.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Calculate compliance score.
	 *
	 * @param array $issues Issues found.
	 * @return int Compliance score (0-100).
	 */
	private function calculate_compliance_score( $issues ) {
		$score     = 100;
		$penalties = array(
			'error'   => 10,
			'warning' => 3,
		);

		foreach ( $issues as $issue ) {
			$severity = $issue['severity'];
			if ( isset( $penalties[ $severity ] ) ) {
				$score -= $penalties[ $severity ];
			}
		}

		return max( 0, $score );
	}

	/**
	 * Count issues by severity.
	 *
	 * @param array  $issues   Issues.
	 * @param string $severity Severity level.
	 * @return int Count.
	 */
	private function count_by_severity( $issues, $severity ) {
		return count(
			array_filter(
				$issues,
				function ( $issue ) use ( $severity ) {
					return $issue['severity'] === $severity;
				}
			)
		);
	}

	/**
	 * Generate recommendations.
	 *
	 * @param array $issues Issues found.
	 * @return array Recommendations.
	 */
	private function generate_recommendations( $issues ) {
		$recommendations = array();

		if ( $this->count_by_severity( $issues, 'error' ) > 0 ) {
			$recommendations[] = __( 'Fix all errors before committing code.', 'mcp-ai-wpoos-pro' );
		}

		if ( empty( $issues ) ) {
			$recommendations[] = __( 'Code follows WordPress coding standards. Great work!', 'mcp-ai-wpoos-pro' );
		} else {
			$recommendations[] = __( 'Run PHPCBF to auto-fix formatting issues: vendor/bin/phpcbf --standard=WordPress file.php', 'mcp-ai-wpoos-pro' );
		}

		return $recommendations;
	}
}
