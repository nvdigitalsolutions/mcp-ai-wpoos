<?php
/**
 * Tool returning recent system log entries.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Returns recent log entries from WordPress and WP oOS.
 */
class WP_MCP_AI_Tool_Get_System_Logs implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
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
		return __( 'Get System Logs', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Returns recent log entries from WordPress, WP oOS, and plugin log files for diagnostics.', 'wp-mcp-ai' );
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
					'description' => __( 'Maximum number of WP oOS activity entries to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'activity_types'         => array(
					'type'        => 'array',
					'description' => __( 'Optional list of WP oOS activity types to include (tool_execution, openai_request, etc.).', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'error_limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of WP oOS error entries to return.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
				'include_debug_log'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include the WordPress debug log if available.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'debug_log_limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of lines to return from the WordPress debug log.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'debug_log_bytes'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bytes to inspect when tailing the WordPress debug log.', 'wp-mcp-ai' ),
					'minimum'     => 1024,
					'maximum'     => 200000,
					'default'     => 50000,
				),
				'include_plugin_logs'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to scan plugin directories for additional .log files.', 'wp-mcp-ai' ),
					'default'     => true,
				),
				'plugin_log_limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of plugin log files to inspect.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'plugin_log_line_limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of lines to return from each plugin log.', 'wp-mcp-ai' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'plugin_log_bytes'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bytes to inspect when tailing plugin logs.', 'wp-mcp-ai' ),
					'minimum'     => 1024,
					'maximum'     => 200000,
					'default'     => 50000,
				),
				'plugin_log_directories' => array(
					'type'        => 'array',
					'description' => __( 'Optional list of directories to scan for plugin log files. Defaults to wp-content and the plugins directory.', 'wp-mcp-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'plugin_log_depth'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum recursion depth when scanning plugin log directories.', 'wp-mcp-ai' ),
					'minimum'     => 0,
					'maximum'     => 5,
					'default'     => 2,
				),
			),
			'additionalProperties' => false,
		);
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
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect system logs.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$args = $this->prepare_arguments( $arguments );

		$result = array(
			'summary'     => __( 'System logs retrieved successfully', 'wp-mcp-ai' ),
			'wp_mcp_ai'   => $this->get_mcp_ai_logs( $args ),
			'wordpress'   => $this->get_wordpress_logs( $args ),
			'plugin_logs' => $args['include_plugin_logs'] ? $this->get_plugin_logs( $args ) : array(
				'message' => __( 'Plugin log scanning disabled in request parameters.', 'wp-mcp-ai' ),
			),
		);

		return $result;
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
	 * Return recent WP oOS log entries.
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
			$logs['recent_errors']   = WP_MCP_AI_Logger::get_recent_error_messages( $args['error_limit'] );
			$logs['recent_activity'] = WP_MCP_AI_Logger::get_recent_activity_entries( $args['activity_limit'], $args['activity_types'] );
		} else {
			$logs['message'] = __( 'WP oOS logging is disabled. Enable logging in the WP oOS settings to capture entries.', 'wp-mcp-ai' );
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
				$wordpress_logs['debug_log'] = $this->prepare_file_log_payload( $debug_path, $args['debug_log_limit'], $args['debug_log_bytes'] );
			} else {
				$wordpress_logs['debug_log'] = array(
					'available' => false,
					'message'   => __( 'No readable WordPress debug.log file was found. Enable WP_DEBUG_LOG to capture entries.', 'wp-mcp-ai' ),
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
				$wordpress_logs['php_error_log'] = $this->prepare_file_log_payload( $error_log_path, $args['debug_log_limit'], $args['debug_log_bytes'] );
			} else {
				$wordpress_logs['php_error_log'] = array(
					'available' => false,
					'message'   => __( 'PHP error_log path is not readable by WordPress.', 'wp-mcp-ai' ),
					'path'      => $this->make_relative_path( $error_log_path ),
				);
			}
		}

		if ( empty( $wordpress_logs ) ) {
			$wordpress_logs['message'] = __( 'No WordPress level log files were located.', 'wp-mcp-ai' );
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
						__( 'Unable to scan directory for logs: %s', 'wp-mcp-ai' ),
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

				$found[] = $this->prepare_file_log_payload( $path, $line_limit, $byte_limit );

				if ( count( $found ) >= $max_files ) {
					break;
				}
			}
		}

		if ( empty( $found ) ) {
			return array(
				'message' => __( 'No plugin log files were found in the configured directories.', 'wp-mcp-ai' ),
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

		$allowed_directories   = $this->get_default_log_directories();
		$allowed_directories[] = $this->normalize_path( WP_PLUGIN_DIR );

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
	 * @param string $path       File path.
	 * @param int    $line_limit Maximum number of lines to return.
	 * @param int    $byte_limit Maximum number of bytes to inspect.
	 * @return array
	 */
	protected function prepare_file_log_payload( $path, $line_limit, $byte_limit ) {
		$path = $this->normalize_path( $path );

		$payload = array(
			'path'     => $this->make_relative_path( $path ),
			'size'     => file_exists( $path ) ? (int) filesize( $path ) : 0,
			'modified' => file_exists( $path ) ? gmdate( DATE_W3C, filemtime( $path ) ) : '',
			'entries'  => array(),
		);

		if ( is_readable( $path ) && $payload['size'] > 0 ) {
			$payload['entries'] = $this->tail_file( $path, $line_limit, $byte_limit );
		} else {
			$payload['message'] = __( 'Log file is empty or not readable.', 'wp-mcp-ai' );
		}

		return $payload;
	}

	/**
	 * Tail a file to retrieve the most recent lines.
	 *
	 * @param string $path       Path to the log file.
	 * @param int    $line_limit Maximum number of lines.
	 * @param int    $byte_limit Maximum number of bytes to read from the end of the file.
	 * @return array
	 */
	protected function tail_file( $path, $line_limit, $byte_limit ) {
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$handle = fopen( $path, 'rb' );

		if ( ! $handle ) {
			return array();
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
			$buffer .= fread( $handle, 4096 );
		}
		fclose( $handle );

		if ( '' === $buffer ) {
			return array();
		}

		$buffer = str_replace( array( "\r\n", "\r" ), "\n", $buffer );
		$lines  = array_filter( explode( "\n", trim( $buffer ) ), 'strlen' );

		if ( count( $lines ) > $line_limit ) {
			$lines = array_slice( $lines, -1 * $line_limit );
		}

		return array_values( array_map( array( $this, 'sanitize_log_line' ), $lines ) );
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
				$path = WP_CONTENT_DIR . '/debug.log';
			} else {
				$path = WP_DEBUG_LOG;
			}
		} else {
			$path = WP_CONTENT_DIR . '/debug.log';
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
		$directories = array(
			$this->normalize_path( WP_CONTENT_DIR ),
		);

		$uploads = wp_get_upload_dir();
		if ( ! empty( $uploads['basedir'] ) ) {
			$directories[] = $this->normalize_path( $uploads['basedir'] );
		}

		$directories[] = $this->normalize_path( WP_PLUGIN_DIR );

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
