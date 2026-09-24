<?php
/**
 * Tool returning recent system log entries.
 *
 * @package WP_MCP_AI
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns recent log entries from WordPress and NV oOS.
 */
class WP_MCP_AI_Tool_Get_System_Logs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;

	/**
	 * Severity levels accepted by the levels filter.
	 *
	 * Maps to NV oOS log types (critical/error/warning/...) and to the
	 * severity markers in WordPress/PHP log lines.
	 */
	const ALLOWED_LEVELS = array( 'critical', 'error', 'warning', 'notice', 'deprecated' );

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'get_system_logs';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Get System Logs', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns recent log entries from WordPress, NV oOS, and plugin log files for diagnostics.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Tailing recent NV oOS activity, error, and WordPress debug log entries.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Strict argument validation or Site Health checks; use get_system_logs_validated or get_site_health.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_system_logs_validated', 'get_site_health', 'get_environment_status' ),
			'notes'           => __( 'Combine since ("2h", "30m", ISO dates), levels (critical/error/warning/notice/deprecated), and search (case-insensitive substring) to narrow results. Limit params cap activity and error entries at 50; debug log lines at 200.', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'activity_limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of NV oOS activity entries to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'activity_types'         => array(
					'type'        => 'array',
					'description' => __( 'Optional list of NV oOS activity types to include (tool_execution, chat_interaction, api_request, etc.). Provider-specific types such as openai_request, anthropic_request, gemini_request, and ollama_request are also supported.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'error_limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of NV oOS error entries to return.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
				'since'                  => array(
					'type'        => 'string',
					'description' => __( 'Only return entries at or after this time. Accepts relative windows like "2h", "30m", "3d", "45 minutes", or "2 hours ago" (a bare number is minutes), or absolute dates in ISO 8601 ("2026-09-24T10:00:00Z") or "Y-m-d H:i:s" format (UTC). Empty means no time filter.', 'mcp-ai-wpoos' ),
					'default'     => '',
				),
				'levels'                 => array(
					'type'        => 'array',
					'description' => __( 'Optional severity levels to include: critical, error, warning, notice, deprecated. Filters NV oOS error entries by their type and WordPress/PHP log lines by their severity markers (e.g. "PHP Fatal error", "PHP Warning"). Empty means no level filter.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'critical', 'error', 'warning', 'notice', 'deprecated' ),
					),
					'default'     => array(),
				),
				'search'                 => array(
					'type'        => 'string',
					'description' => __( 'Optional case-insensitive substring filter. Only entries and log lines whose message (or context) contains this text are returned.', 'mcp-ai-wpoos' ),
					'maxLength'   => 200,
					'default'     => '',
				),
				'include_debug_log'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include the WordPress debug log if available.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'debug_log_limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of lines to return from the WordPress debug log.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'debug_log_bytes'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bytes to inspect when tailing the WordPress debug log.', 'mcp-ai-wpoos' ),
					'minimum'     => 1024,
					'maximum'     => 200000,
					'default'     => 50000,
				),
				'include_plugin_logs'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to scan plugin directories for additional .log files.', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'plugin_log_limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of plugin log files to inspect.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'plugin_log_line_limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of lines to return from each plugin log.', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'plugin_log_bytes'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bytes to inspect when tailing plugin logs.', 'mcp-ai-wpoos' ),
					'minimum'     => 1024,
					'maximum'     => 200000,
					'default'     => 50000,
				),
				'plugin_log_directories' => array(
					'type'        => 'array',
					'description' => __( 'Optional list of directories to scan for plugin log files. Defaults to wp-content and the plugins directory.', 'mcp-ai-wpoos' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'plugin_log_depth'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum recursion depth when scanning plugin log directories.', 'mcp-ai-wpoos' ),
					'minimum'     => 0,
					'maximum'     => 5,
					'default'     => 2,
				),
			),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect system logs.', 'mcp-ai-wpoos' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
		}

		$args = $this->prepare_arguments( $arguments );

		$since_timestamp = 0;
		if ( '' !== $args['since'] ) {
			$since_timestamp = $this->parse_since( $args['since'] );

			if ( false === $since_timestamp ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_since',
					__( 'The "since" parameter could not be parsed. Use a relative window like "2h" or "30 minutes", or an absolute date like "2026-09-24T10:00:00Z".', 'mcp-ai-wpoos' )
				);
			}
		}
		$args['since_timestamp'] = $since_timestamp;

		$result = array(
			'summary'     => __( 'System logs retrieved successfully', 'mcp-ai-wpoos' ),
			'filters'     => $this->describe_filters( $args ),
			'wp_mcp_ai'   => $this->get_mcp_ai_logs( $args ),
			'wordpress'   => $this->get_wordpress_logs( $args ),
			'plugin_logs' => $args['include_plugin_logs'] ? $this->get_plugin_logs( $args ) : array(
				'message' => __( 'Plugin log scanning disabled in request parameters.', 'mcp-ai-wpoos' ),
			),
		);

		return $result;
	}

	/**
	 * Describe the filters applied to this response so callers can verify them.
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	protected function describe_filters( $args ) {
		$filters = array(
			'since'  => $args['since'],
			'levels' => $args['levels'],
			'search' => $args['search'],
		);

		if ( $args['since_timestamp'] > 0 ) {
			$filters['since_timestamp']     = gmdate( DATE_W3C, $args['since_timestamp'] );
			$filters['since_age_seconds']    = max( 0, time() - $args['since_timestamp'] );
		}

		return $filters;
	}

	/**
	 * Prepare and sanitize incoming arguments.
	 *
	 * @param array $arguments Raw arguments.
	 * @return array
	 */
	protected function prepare_arguments( $arguments ) {
		$defaults = array(
			'activity_limit'         => 10,
			'activity_types'         => array(),
			'error_limit'            => 20,
			'include_debug_log'      => true,
			'debug_log_limit'        => 50,
			'debug_log_bytes'        => 50000,
			'include_plugin_logs'    => true,
			'plugin_log_limit'       => 5,
			'plugin_log_line_limit'  => 50,
			'plugin_log_bytes'       => 50000,
			'plugin_log_directories' => array(),
			'plugin_log_depth'       => 2,
			'since'                  => '',
			'levels'                 => array(),
			'search'                 => '',
		);

		$parsed = wp_parse_args( $arguments, $defaults );

		$parsed['activity_limit'] = $this->clamp_int( $parsed['activity_limit'], 1, 50, 10 );
		$parsed['error_limit']    = $this->clamp_int( $parsed['error_limit'], 1, 50, 20 );

		$types = array();
		foreach ( (array) $parsed['activity_types'] as $type ) {
			$type = sanitize_key( $type );
			if ( '' !== $type ) {
				$types[] = $type;
			}
		}
		$parsed['activity_types'] = array_values( array_unique( $types ) );

		$parsed['since'] = trim( (string) $parsed['since'] );

		$levels = array();
		foreach ( (array) $parsed['levels'] as $level ) {
			$level = sanitize_key( $level );
			if ( in_array( $level, self::ALLOWED_LEVELS, true ) ) {
				$levels[] = $level;
			}
		}
		$parsed['levels'] = array_values( array_unique( $levels ) );

		$parsed['search'] = trim( (string) $parsed['search'] );
		if ( function_exists( 'mb_substr' ) ) {
			$parsed['search'] = mb_substr( $parsed['search'], 0, 200 );
		} else {
			$parsed['search'] = substr( $parsed['search'], 0, 200 );
		}

		$parsed['include_debug_log'] = ! empty( $parsed['include_debug_log'] );
		$parsed['debug_log_limit']   = $this->clamp_int( $parsed['debug_log_limit'], 1, 200, 50 );
		$parsed['debug_log_bytes']   = $this->clamp_int( $parsed['debug_log_bytes'], 1024, 200000, 50000 );

		$parsed['include_plugin_logs']   = ! empty( $parsed['include_plugin_logs'] );
		$parsed['plugin_log_limit']      = $this->clamp_int( $parsed['plugin_log_limit'], 1, 20, 5 );
		$parsed['plugin_log_line_limit'] = $this->clamp_int( $parsed['plugin_log_line_limit'], 1, 200, 50 );
		$parsed['plugin_log_bytes']      = $this->clamp_int( $parsed['plugin_log_bytes'], 1024, 200000, 50000 );
		$parsed['plugin_log_depth']      = $this->clamp_int( $parsed['plugin_log_depth'], 0, 5, 2 );

		$directories = array();
		foreach ( (array) $parsed['plugin_log_directories'] as $directory ) {
			$validated = $this->validate_directory( $directory );
			if ( $validated ) {
				$directories[] = $validated;
			}
		}

		if ( empty( $directories ) ) {
			$directories = $this->get_default_log_directories();
		}

		$parsed['plugin_log_directories'] = $directories;

		return $parsed;
	}

	/**
	 * Return recent NV oOS log entries.
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	protected function get_mcp_ai_logs( $args ) {
		$logging_enabled = WP_MCP_AI_Admin_Settings::is_logging_enabled();

		$logs = array(
			'logging_enabled' => $logging_enabled,
		);

		if ( $logging_enabled ) {
			$logs['recent_errors']   = WP_MCP_AI_Logger::get_recent_error_messages( $args['error_limit'], $args['since_timestamp'], $args['levels'], $args['search'] );
			$logs['recent_activity'] = WP_MCP_AI_Logger::get_recent_activity_entries( $args['activity_limit'], $args['activity_types'], $args['since_timestamp'], $args['search'] );
		} else {
			$logs['message'] = __( 'NV oOS logging is disabled. Enable logging in the NV oOS settings to capture entries.', 'mcp-ai-wpoos' );
		}

		return $logs;
	}

	/**
	 * Gather WordPress level logs (debug.log and PHP error log if available).
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	protected function get_wordpress_logs( $args ) {
		$wordpress_logs = array();

		$debug_path = $this->resolve_debug_log_path();

		if ( $args['include_debug_log'] ) {
			if ( $debug_path && is_readable( $debug_path ) ) {
				$wordpress_logs['debug_log'] = $this->prepare_file_log_payload( $debug_path, $args['debug_log_limit'], $args['debug_log_bytes'], $args['since_timestamp'], $args['levels'], $args['search'] );
			} else {
				$wordpress_logs['debug_log'] = array(
					'available' => false,
					'message'   => __( 'No readable WordPress debug.log file was found. Enable WP_DEBUG_LOG to capture entries.', 'mcp-ai-wpoos' ),
				);
			}
		}

		$error_log_path = ini_get( 'error_log' );
		if ( $error_log_path ) {
			$error_log_path = $this->normalize_path( $error_log_path );

			if ( $debug_path && $this->normalize_path( $debug_path ) === $error_log_path ) {
				$error_log_path = '';
			}
		}

		if ( $error_log_path ) {
			if ( is_readable( $error_log_path ) ) {
				$wordpress_logs['php_error_log'] = $this->prepare_file_log_payload( $error_log_path, $args['debug_log_limit'], $args['debug_log_bytes'], $args['since_timestamp'], $args['levels'], $args['search'] );
			} else {
				$wordpress_logs['php_error_log'] = array(
					'available' => false,
					'message'   => __( 'PHP error_log path is not readable by WordPress.', 'mcp-ai-wpoos' ),
					'path'      => $this->make_relative_path( $error_log_path ),
				);
			}
		}

		if ( empty( $wordpress_logs ) ) {
			$wordpress_logs['message'] = __( 'No WordPress level log files were located.', 'mcp-ai-wpoos' );
		}

		return $wordpress_logs;
	}

	/**
	 * Scan plugin directories for log files.
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	protected function get_plugin_logs( $args ) {
		$directories = $args['plugin_log_directories'];
		$max_files   = $args['plugin_log_limit'];
		$line_limit  = $args['plugin_log_line_limit'];
		$byte_limit  = $args['plugin_log_bytes'];
		$max_depth   = $args['plugin_log_depth'];

		$found = array();
		$seen  = array();

		foreach ( $directories as $directory ) {
			if ( count( $found ) >= $max_files ) {
				break;
			}

			try {
				$iterator = new RecursiveIteratorIterator(
					new RecursiveDirectoryIterator(
						$directory,
						FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS
					),
					RecursiveIteratorIterator::SELF_FIRST
				);
			} catch ( Exception $exception ) {
				$found[] = array(
					'path'    => $this->make_relative_path( $directory ),
					'message' => sprintf(
						/* translators: %s: Directory path that could not be scanned. */
						__( 'Unable to scan directory for logs: %s', 'mcp-ai-wpoos' ),
						$this->make_relative_path( $directory )
					),
				);
				continue;
			}

			foreach ( $iterator as $file_info ) {
				if ( $iterator->getDepth() > $max_depth ) {
					continue;
				}

				if ( ! $file_info instanceof SplFileInfo || ! $file_info->isFile() ) {
					continue;
				}

				$path = $file_info->getPathname();

				if ( ! $this->is_log_file( $path ) ) {
					continue;
				}

				$normalized = $this->normalize_path( $path );

				if ( isset( $seen[ $normalized ] ) ) {
					continue;
				}

				$seen[ $normalized ] = true;

				$found[] = $this->prepare_file_log_payload( $path, $line_limit, $byte_limit, $args['since_timestamp'], $args['levels'], $args['search'] );

				if ( count( $found ) >= $max_files ) {
					break;
				}
			}
		}

		if ( empty( $found ) ) {
			return array(
				'message' => __( 'No plugin log files were found in the configured directories.', 'mcp-ai-wpoos' ),
			);
		}

		return array_values( $found );
	}

	/**
	 * Determine if the given path looks like a log file.
	 *
	 * @param string $path Path to inspect.
	 * @return bool
	 */
	protected function is_log_file( $path ) {
		$path = $this->normalize_path( $path );

		if ( '' === $path ) {
			return false;
		}

		if ( ! is_readable( $path ) ) {
			return false;
		}

		$allowed_directories = $this->get_default_log_directories();
		if ( defined( 'WP_PLUGIN_DIR' ) ) {
			$allowed_directories[] = $this->normalize_path( WP_PLUGIN_DIR );
		}

			$allowed = false;
		foreach ( $allowed_directories as $directory ) {
			if ( '' === $directory ) {
				continue;
			}

			if ( 0 === strpos( $path, $directory ) ) {
				$allowed = true;
				break;
			}
		}

		if ( ! $allowed ) {
			return false;
		}

		$extension = strtolower( pathinfo( $path, PATHINFO_EXTENSION ) );

		if ( 'log' === $extension ) {
			return true;
		}

		if ( 'txt' === $extension ) {
			$basename = strtolower( pathinfo( $path, PATHINFO_BASENAME ) );
			return false !== strpos( $basename, 'log' );
		}

		return false;
	}

	/**
	 * Create a structured representation of a log file.
	 *
	 * @param string $path           File path.
	 * @param int    $line_limit     Maximum number of lines to return.
	 * @param int    $byte_limit     Maximum number of bytes to inspect.
	 * @param int    $since_timestamp Optional UTC cutoff timestamp.
	 * @param array  $levels         Optional severity levels to include.
	 * @param string $search         Optional case-insensitive substring filter.
	 * @return array
	 */
	protected function prepare_file_log_payload( $path, $line_limit, $byte_limit, $since_timestamp = 0, $levels = array(), $search = '' ) {
		$path = $this->normalize_path( $path );

		$payload = array(
			'path'     => $this->make_relative_path( $path ),
			'size'     => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'modified' => file_exists( $path ) ? gmdate( DATE_W3C, filemtime( $path ) ) : '',
			'entries'  => array(),
		);

		if ( is_readable( $path ) && $payload['size'] > 0 ) {
			$tail                = $this->tail_file( $path, $line_limit, $byte_limit, $since_timestamp, $levels, $search );
			$payload['entries']  = $tail['entries'];

			if ( $tail['filtered_out'] > 0 ) {
				$payload['filtered_out'] = $tail['filtered_out'];
			}
		} else {
			$payload['message'] = __( 'Log file is empty or not readable.', 'mcp-ai-wpoos' );
		}

		return $payload;
	}

	/**
	 * Tail a file to retrieve the most recent lines.
	 *
	 * @param string $path           Path to the log file.
	 * @param int    $line_limit     Maximum number of lines.
	 * @param int    $byte_limit     Maximum number of bytes to read from the end of the file.
	 * @param int    $since_timestamp Optional UTC cutoff timestamp.
	 * @param array  $levels         Optional severity levels to include.
	 * @param string $search         Optional case-insensitive substring filter.
	 * @return array Entry lines plus the number of lines removed by filters.
	 */
	protected function tail_file( $path, $line_limit, $byte_limit, $since_timestamp = 0, $levels = array(), $search = '' ) {
		if ( ! file_exists( $path ) ) {
			return array(
				'entries'      => array(),
				'filtered_out' => 0,
			);
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

		if ( ! $handle ) {
			return array(
				'entries'      => array(),
				'filtered_out' => 0,
			);
		}

		$line_limit = max( 1, absint( $line_limit ) );
		$byte_limit = max( 1024, absint( $byte_limit ) );

		$size     = filesize( $path );
		$position = $size > $byte_limit ? $size - $byte_limit : 0;

		if ( $position > 0 ) {
			fseek( $handle, $position, SEEK_SET );
		}

		$buffer = '';
		while ( ! feof( $handle ) ) {
			$buffer .= fread( $handle, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

		if ( '' === $buffer ) {
			return array(
				'entries'      => array(),
				'filtered_out' => 0,
			);
		}

		$buffer = str_replace( array( "\r\n", "\r" ), "\n", $buffer );
		$lines  = array_filter( explode( "\n", trim( $buffer ) ), 'strlen' );

		if ( count( $lines ) > $line_limit ) {
			$lines = array_slice( $lines, -1 * $line_limit );
		}

		$lines = array_values( array_map( array( $this, 'sanitize_log_line' ), $lines ) );

		$filtered_out = 0;
		if ( $since_timestamp > 0 || ! empty( $levels ) || '' !== trim( (string) $search ) ) {
			$kept = array();
			foreach ( $lines as $line ) {
				if ( $this->line_matches_filters( $line, $since_timestamp, $levels, $search ) ) {
					$kept[] = $line;
				} else {
					$filtered_out++;
				}
			}
			$lines = $kept;
		}

		return array(
			'entries'      => $lines,
			'filtered_out' => $filtered_out,
		);
	}

	/**
	 * Decide whether a log line matches the active filters.
	 *
	 * Lines with a leading "[...]" timestamp are compared against the cutoff;
	 * lines without a parseable timestamp (e.g. stack trace continuations) are
	 * kept conservatively so errors are never silently dropped.
	 *
	 * @param string $line           Log line.
	 * @param int    $since_timestamp UTC cutoff timestamp.
	 * @param array  $levels         Severity levels to include.
	 * @param string $search         Case-insensitive substring.
	 * @return bool
	 */
	protected function line_matches_filters( $line, $since_timestamp, $levels, $search ) {
		$line = (string) $line;

		if ( $since_timestamp > 0 && preg_match( '/^\s*\[([^\]]+)\]\s*/', $line, $matches ) ) {
			$parsed = strtotime( $matches[1] );

			if ( false === $parsed ) {
				$parsed = strtotime( $matches[1] . ' UTC' );
			}

			if ( false !== $parsed && $parsed < $since_timestamp ) {
				return false;
			}
		}

		if ( ! empty( $levels ) ) {
			$matched = false;
			foreach ( $levels as $level ) {
				if ( $this->line_has_level( $line, $level ) ) {
					$matched = true;
					break;
				}
			}

			if ( ! $matched ) {
				return false;
			}
		}

		if ( '' !== $search && ! $this->contains_case_insensitive( $line, $search ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Check whether a log line carries a given severity level.
	 *
	 * Recognises the severity markers used by WordPress debug.log and PHP
	 * error_log lines, e.g. "PHP Fatal error", "PHP Warning", "PHP Notice",
	 * "PHP Deprecated", and the E_* severity constants.
	 *
	 * @param string $line  Log line.
	 * @param string $level Severity level (critical, error, warning, notice, deprecated).
	 * @return bool
	 */
	protected function line_has_level( $line, $level ) {
		static $patterns = array(
			'critical'   => array(
				'/\b(?:Fatal|Parse) error\b/i',
				'/\bUncaught\b/i',
				'/\bE_(?:ERROR|CORE_ERROR|COMPILE_ERROR)\b/',
			),
			'error'      => array(
				'/\b(?:Fatal|Parse) error\b/i',
				'/\bUncaught\b/i',
				'/\bE_(?:ERROR|CORE_ERROR|COMPILE_ERROR|RECOVERABLE_ERROR|USER_ERROR)\b/',
			),
			'warning'    => array(
				'/\bWarning\b/i',
				'/\bE_(?:WARNING|CORE_WARNING|COMPILE_WARNING|USER_WARNING)\b/',
			),
			'notice'     => array(
				'/\bNotice\b/i',
				'/\bE_(?:NOTICE|USER_NOTICE)\b/',
			),
			'deprecated' => array(
				'/\bDeprecated\b/i',
				'/\bE_(?:DEPRECATED|USER_DEPRECATED)\b/',
			),
		);

		if ( ! isset( $patterns[ $level ] ) ) {
			return false;
		}

		foreach ( $patterns[ $level ] as $pattern ) {
			if ( preg_match( $pattern, $line ) ) {
				return true;
			}
		}

		return false;
	}

	/**
	 * Case-insensitive substring check with multibyte support when available.
	 *
	 * @param string $haystack Text to search within.
	 * @param string $needle   Text to search for.
	 * @return bool
	 */
	protected function contains_case_insensitive( $haystack, $needle ) {
		$haystack = (string) $haystack;
		$needle   = (string) $needle;

		if ( '' === $needle ) {
			return true;
		}

		if ( function_exists( 'mb_stripos' ) ) {
			return false !== mb_stripos( $haystack, $needle );
		}

		return false !== stripos( $haystack, $needle );
	}

	/**
	 * Parse the "since" argument into a UTC cutoff timestamp.
	 *
	 * Supports relative windows ("2h", "30m", "3d", "45 minutes",
	 * "2 hours ago"; a bare number is minutes) and absolute dates in ISO 8601
	 * or "Y-m-d H:i:s" format (interpreted as UTC, matching how NV oOS stores
	 * entry timestamps).
	 *
	 * @param string $since Raw since value.
	 * @return int|false UTC cutoff timestamp, 0 for no filter, or false when unparseable.
	 */
	public static function parse_since( $since ) {
		$since = trim( (string) $since );

		if ( '' === $since ) {
			return 0;
		}

		// Relative windows: "2h", "30 minutes", "2 hours ago", bare numbers are minutes.
		if ( preg_match( '/^\s*(\d+)\s*([a-zA-Z]*)\s*(?:ago)?\s*$/i', $since, $matches ) ) {
			$amount     = (int) $matches[1];
			$unit       = strtolower( $matches[2] );
			$multiplier = 60; // Bare numbers are treated as minutes.

			if ( '' !== $unit ) {
				$first = substr( $unit, 0, 1 );

				if ( 's' === $first && in_array( $unit, array( 's', 'sec', 'secs', 'second', 'seconds' ), true ) ) {
					$multiplier = 1;
				} elseif ( 'm' === $first && in_array( $unit, array( 'm', 'min', 'mins', 'minute', 'minutes' ), true ) ) {
					$multiplier = 60;
				} elseif ( 'h' === $first && in_array( $unit, array( 'h', 'hr', 'hrs', 'hour', 'hours' ), true ) ) {
					$multiplier = 3600;
				} elseif ( 'd' === $first && in_array( $unit, array( 'd', 'day', 'days' ), true ) ) {
					$multiplier = 86400;
				} elseif ( 'w' === $first && in_array( $unit, array( 'w', 'week', 'weeks' ), true ) ) {
					$multiplier = 604800;
				} else {
					return false;
				}
			}

			return max( 0, time() - ( $amount * $multiplier ) );
		}

		// Absolute timestamps: ISO 8601 or "Y-m-d H:i:s" (UTC).
		$parsed = strtotime( $since );

		if ( false === $parsed ) {
			$parsed = strtotime( $since . ' UTC' );
		}

		if ( false === $parsed ) {
			return false;
		}

		// Clamp future dates to now.
		return min( $parsed, time() );
	}

	/**
	 * Make a log line safe for output without stripping useful characters.
	 *
	 * @param string $line Log line.
	 * @return string
	 */
	protected function sanitize_log_line( $line ) {
		$line = (string) $line;
		$line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $line );

		return $line;
	}

	/**
	 * Resolve the debug.log path based on WordPress configuration.
	 *
	 * @return string|null
	 */
	protected function resolve_debug_log_path() {
		$path = null;

		if ( defined( 'WP_DEBUG_LOG' ) && WP_DEBUG_LOG ) {
			if ( true === WP_DEBUG_LOG ) {
				$path = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : null;
			} else {
				$path = WP_DEBUG_LOG;
			}
		} else {
			$path = defined( 'WP_CONTENT_DIR' ) ? WP_CONTENT_DIR . '/debug.log' : null;
		}

		if ( ! $path ) {
			return null;
		}

		$path = $this->normalize_path( $path );

		return $path;
	}

	/**
	 * Convert a path to a normalised form.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	protected function normalize_path( $path ) {
		if ( ! is_string( $path ) || '' === $path ) {
			return '';
		}

		$normalized = wp_normalize_path( $path );

		return $normalized;
	}

	/**
	 * Convert an absolute path into an ABSPATH relative reference.
	 *
	 * @param string $path File path.
	 * @return string
	 */
	protected function make_relative_path( $path ) {
		$path = $this->normalize_path( $path );
		$root = $this->normalize_path( ABSPATH );

		if ( '' === $path || '' === $root ) {
			return $path;
		}

		if ( 0 === strpos( $path, $root ) ) {
			$relative = ltrim( substr( $path, strlen( $root ) ), '/' );
			return $relative;
		}

		return $path;
	}

	/**
	 * Clamp an integer into a safe range.
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $minimum Minimum value.
	 * @param int   $maximum Maximum value.
	 * @param int   $default Default fallback.
	 * @return int
	 */
	protected function clamp_int( $value, $minimum, $maximum, $default ) {
		if ( ! is_numeric( $value ) ) {
			return (int) $default;
		}

		$value = (int) $value;

		if ( $value < $minimum ) {
			return (int) $minimum;
		}

		if ( $value > $maximum ) {
			return (int) $maximum;
		}

		return $value;
	}

	/**
	 * Validate and normalise a directory path ensuring it resides within WordPress.
	 *
	 * @param string $directory Directory path.
	 * @return string
	 */
	protected function validate_directory( $directory ) {
		if ( ! is_string( $directory ) || '' === $directory ) {
			return '';
		}

		$normalized = $this->normalize_path( $directory );
		$real       = realpath( $normalized );

		if ( false === $real ) {
			return '';
		}

		$real = $this->normalize_path( $real );

		$root = $this->normalize_path( ABSPATH );

		if ( '' !== $root && 0 !== strpos( $real, $root ) ) {
			return '';
		}

		if ( ! is_dir( $real ) || ! is_readable( $real ) ) {
			return '';
		}

		return $real;
	}

	/**
	 * Retrieve the default directories that should be scanned for log files.
	 *
	 * @return array
	 */
	protected function get_default_log_directories() {
		$directories = array();

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			$directories[] = $this->normalize_path( $uploads['basedir'] );
		}

		if ( defined( 'WP_PLUGIN_DIR' ) ) {
					$directories[] = $this->normalize_path( WP_PLUGIN_DIR );
		}

				$directories = array_filter( array_unique( $directories ) );

		$validated = array();
		foreach ( $directories as $directory ) {
			$valid = $this->validate_directory( $directory );
			if ( $valid ) {
				$validated[] = $valid;
			}
		}

		return array_values( array_unique( $validated ) );
	}


	/**

	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.0
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {

		return array(

			'name'                  => $this->get_name(),

			'description'           => $this->get_description(),

			'toolkit'               => 'developer_technical',

			'pattern_compatibility' => array( 'skill_router' ),

			'profession_tags'       => array( 'devops_engineer', 'systems_administrator' ),

			'risk_level'            => 'info',

		);
	}


	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'local-only',           // No external API calls.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
