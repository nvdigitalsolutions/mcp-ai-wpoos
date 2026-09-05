<?php
/**
 * Get System Logs tool (D8 Cluster 2c port of the base plugin's
 * WP_MCP_AI_Tool_Get_System_Logs — byte-identical slug, schema, error
 * codes, envelope, clamping, path validation, and tailing; per-mode
 * logging seams for the NV oOS buffers).
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\CoreBridge;

/**
 * Returns recent log entries from WordPress and NV oOS.
 */
class GetSystemLogsTool extends AbstractAiTool {

	public function getSlug(): string {
		return 'get_system_logs';
	}

	public function getName(): string {
		return __( 'Get System Logs', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Returns recent log entries from WordPress, NV oOS, and plugin log files for diagnostics.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'activity_limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of NV oOS activity entries to return.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 10,
				),
				'activity_types'         => array(
					'type'        => 'array',
					'description' => __( 'Optional list of NV oOS activity types to include (tool_execution, chat_interaction, api_request, etc.). Provider-specific types such as openai_request, anthropic_request, gemini_request, and ollama_request are also supported.', 'nvoos-content-graph-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'error_limit'            => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of NV oOS error entries to return.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 50,
					'default'     => 20,
				),
				'include_debug_log'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to include the WordPress debug log if available.', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'debug_log_limit'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of lines to return from the WordPress debug log.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'debug_log_bytes'        => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bytes to inspect when tailing the WordPress debug log.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1024,
					'maximum'     => 200000,
					'default'     => 50000,
				),
				'include_plugin_logs'    => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to scan plugin directories for additional .log files.', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'plugin_log_limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of plugin log files to inspect.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 20,
					'default'     => 5,
				),
				'plugin_log_line_limit'  => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of lines to return from each plugin log.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1,
					'maximum'     => 200,
					'default'     => 50,
				),
				'plugin_log_bytes'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of bytes to inspect when tailing plugin logs.', 'nvoos-content-graph-ai' ),
					'minimum'     => 1024,
					'maximum'     => 200000,
					'default'     => 50000,
				),
				'plugin_log_directories' => array(
					'type'        => 'array',
					'description' => __( 'Optional list of directories to scan for plugin log files. Defaults to wp-content and the plugins directory.', 'nvoos-content-graph-ai' ),
					'items'       => array(
						'type' => 'string',
					),
					'default'     => array(),
				),
				'plugin_log_depth'       => array(
					'type'        => 'integer',
					'description' => __( 'Maximum recursion depth when scanning plugin log directories.', 'nvoos-content-graph-ai' ),
					'minimum'     => 0,
					'maximum'     => 5,
					'default'     => 2,
				),
			),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array( 'read-only', 'local-only', 'requires-capability' );
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'manage_options' ) ) {
			return new \WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to inspect system logs.', 'nvoos-content-graph-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new \WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'nvoos-content-graph-ai' ) );
		}

		$args = $this->prepare_arguments( $arguments );

		$result = array(
			'summary'     => __( 'System logs retrieved successfully', 'nvoos-content-graph-ai' ),
			'wp_mcp_ai'   => $this->get_mcp_ai_logs( $args ),
			'wordpress'   => $this->get_wordpress_logs( $args ),
			'plugin_logs' => $args['include_plugin_logs'] ? $this->get_plugin_logs( $args ) : array(
				'message' => __( 'Plugin log scanning disabled in request parameters.', 'nvoos-content-graph-ai' ),
			),
		);

		return $result;
	}

	/**
	 * Prepare and sanitize incoming arguments (base-identical clamping).
	 *
	 * @param array $arguments Raw arguments.
	 * @return array
	 */
	private function prepare_arguments( $arguments ) {
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
	 * Return recent NV oOS log entries (per-mode seam).
	 *
	 * Monolith: the base logger buffers. Standalone: the base-identical
	 * `wp_mcp_ai_recent_errors` / `wp_mcp_ai_recent_activity` options
	 * with the same output shaping.
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	private function get_mcp_ai_logs( $args ) {
		$logging_enabled = $this->is_logging_enabled();

		$logs = array(
			'logging_enabled' => $logging_enabled,
		);

		if ( $logging_enabled ) {
			$logs['recent_errors']   = $this->get_recent_error_messages( $args['error_limit'] );
			$logs['recent_activity'] = $this->get_recent_activity_entries( $args['activity_limit'], $args['activity_types'] );
		} else {
			$logs['message'] = __( 'NV oOS logging is disabled. Enable logging in the NV oOS settings to capture entries.', 'nvoos-content-graph-ai' );
		}

		return $logs;
	}

	/**
	 * Whether NV oOS logging is enabled (per-mode seam).
	 *
	 * @return bool
	 */
	private function is_logging_enabled() {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			return \WP_MCP_AI_Admin_Settings::is_logging_enabled();
		}

		// Standalone: the Content Graph settings store has no dedicated
		// logging flag — honour one if present, otherwise disabled.
		$settings = CoreBridge::instance()->settings;
		$value    = $settings->get( 'enable_logging', false );

		return ! empty( $value );
	}

	/**
	 * Recent NV oOS error entries (per-mode seam; base-identical shaping).
	 *
	 * @param int $limit Maximum number of entries to return.
	 * @return array
	 */
	private function get_recent_error_messages( $limit = 20 ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			return \WP_MCP_AI_Logger::get_recent_error_messages( $limit );
		}

		$limit  = max( 1, absint( $limit ) );
		$recent = get_option( 'wp_mcp_ai_recent_errors', array() );

		if ( ! is_array( $recent ) || empty( $recent ) ) {
			return array();
		}

		$recent = array_slice( array_reverse( $recent ), 0, $limit );

		return array_values( array_map( array( $this, 'prepare_log_entry_for_output' ), $recent ) );
	}

	/**
	 * Recent NV oOS activity entries (per-mode seam; base-identical shaping).
	 *
	 * @param int   $limit Maximum number of entries to return.
	 * @param array $types Optional list of event types to include.
	 * @return array
	 */
	private function get_recent_activity_entries( $limit = 20, $types = array() ) {
		if ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Logger' ) ) {
			return \WP_MCP_AI_Logger::get_recent_activity_entries( $limit, $types );
		}

		$limit = max( 1, absint( $limit ) );

		$types = array_filter( array_map( 'sanitize_key', (array) $types ) );

		$recent = get_option( 'wp_mcp_ai_recent_activity', array() );

		if ( ! is_array( $recent ) || empty( $recent ) ) {
			return array();
		}

		$recent   = array_reverse( $recent );
		$filtered = array();

		foreach ( $recent as $entry ) {
			if ( ! is_array( $entry ) ) {
				continue;
			}

			$type = isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '';

			if ( ! empty( $types ) && ( '' === $type || ! in_array( $type, $types, true ) ) ) {
				continue;
			}

			$filtered[] = $this->prepare_log_entry_for_output( $entry );

			if ( count( $filtered ) >= $limit ) {
				break;
			}
		}

		return $filtered;
	}

	/**
	 * Prepare a stored buffer entry for safe output (base-identical).
	 *
	 * @param array $entry Stored entry.
	 * @return array
	 */
	private function prepare_log_entry_for_output( $entry ) {
		if ( ! is_array( $entry ) ) {
			return array();
		}

		$prepared = array(
			'timestamp' => isset( $entry['timestamp'] ) ? (string) $entry['timestamp'] : '',
			'type'      => isset( $entry['type'] ) ? sanitize_key( $entry['type'] ) : '',
			'message'   => isset( $entry['message'] ) ? (string) $entry['message'] : '',
		);

		if ( isset( $entry['context'] ) ) {
			$prepared['context'] = $entry['context'];
		}

		return $prepared;
	}

	/**
	 * Gather WordPress level logs (debug.log and PHP error log if available).
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	private function get_wordpress_logs( $args ) {
		$wordpress_logs = array();

		$debug_path = $this->resolve_debug_log_path();

		if ( $args['include_debug_log'] ) {
			if ( $debug_path && is_readable( $debug_path ) ) {
				$wordpress_logs['debug_log'] = $this->prepare_file_log_payload( $debug_path, $args['debug_log_limit'], $args['debug_log_bytes'] );
			} else {
				$wordpress_logs['debug_log'] = array(
					'available' => false,
					'message'   => __( 'No readable WordPress debug.log file was found. Enable WP_DEBUG_LOG to capture entries.', 'nvoos-content-graph-ai' ),
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
					'message'   => __( 'PHP error_log path is not readable by WordPress.', 'nvoos-content-graph-ai' ),
					'path'      => $this->make_relative_path( $error_log_path ),
				);
			}
		}

		if ( empty( $wordpress_logs ) ) {
			$wordpress_logs['message'] = __( 'No WordPress level log files were located.', 'nvoos-content-graph-ai' );
		}

		return $wordpress_logs;
	}

	/**
	 * Scan plugin directories for log files (base-identical).
	 *
	 * @param array $args Prepared arguments.
	 * @return array
	 */
	private function get_plugin_logs( $args ) {
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
				$iterator = new \RecursiveIteratorIterator(
					new \RecursiveDirectoryIterator(
						$directory,
						\FilesystemIterator::SKIP_DOTS | \FilesystemIterator::FOLLOW_SYMLINKS
					),
					\RecursiveIteratorIterator::SELF_FIRST
				);
			} catch ( \Exception $exception ) {
				$found[] = array(
					'path'    => $this->make_relative_path( $directory ),
					'message' => sprintf(
						/* translators: %s: Directory path that could not be scanned. */
						__( 'Unable to scan directory for logs: %s', 'nvoos-content-graph-ai' ),
						$this->make_relative_path( $directory )
					),
				);
				continue;
			}

			foreach ( $iterator as $file_info ) {
				if ( $iterator->getDepth() > $max_depth ) {
					continue;
				}

				if ( ! $file_info instanceof \SplFileInfo || ! $file_info->isFile() ) {
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
				'message' => __( 'No plugin log files were found in the configured directories.', 'nvoos-content-graph-ai' ),
			);
		}

		return array_values( $found );
	}

	/**
	 * Determine if the given path looks like a log file (base-identical).
	 *
	 * @param string $path Path to inspect.
	 * @return bool
	 */
	private function is_log_file( $path ) {
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
	 * Create a structured representation of a log file (base-identical).
	 *
	 * @param string $path       File path.
	 * @param int    $line_limit Maximum number of lines to return.
	 * @param int    $byte_limit Maximum number of bytes to inspect.
	 * @return array
	 */
	private function prepare_file_log_payload( $path, $line_limit, $byte_limit ) {
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
			$payload['message'] = __( 'Log file is empty or not readable.', 'nvoos-content-graph-ai' );
		}

		return $payload;
	}

	/**
	 * Tail a file to retrieve the most recent lines (base-identical).
	 *
	 * @param string $path       Path to the log file.
	 * @param int    $line_limit Maximum number of lines.
	 * @param int    $byte_limit Maximum number of bytes to read from the end of the file.
	 * @return array
	 */
	private function tail_file( $path, $line_limit, $byte_limit ) {
		if ( ! file_exists( $path ) ) {
			return array();
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

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
			$buffer .= fread( $handle, 4096 ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread -- Direct filesystem operation required; WP_Filesystem not available in this execution context.
		}
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Direct filesystem operation required; WP_Filesystem not available in this execution context.

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
	private function sanitize_log_line( $line ) {
		$line = (string) $line;
		$line = preg_replace( '/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $line );

		return $line;
	}

	/**
	 * Resolve the debug.log path based on WordPress configuration.
	 *
	 * @return string|null
	 */
	private function resolve_debug_log_path() {
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
	 * Convert a path to a normalised form (base-identical).
	 *
	 * @param string $path File path.
	 * @return string
	 */
	private function normalize_path( $path ) {
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
	private function make_relative_path( $path ) {
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
	 * Clamp an integer into a safe range (base-identical).
	 *
	 * @param mixed $value   Raw value.
	 * @param int   $minimum Minimum value.
	 * @param int   $maximum Maximum value.
	 * @param int   $default Default fallback.
	 * @return int
	 */
	private function clamp_int( $value, $minimum, $maximum, $default ) {
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
	 * Validate and normalise a directory path ensuring it resides within
	 * WordPress (base-identical).
	 *
	 * @param string $directory Directory path.
	 * @return string
	 */
	private function validate_directory( $directory ) {
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
	 * Retrieve the default directories that should be scanned for log
	 * files (base-identical).
	 *
	 * @return array
	 */
	private function get_default_log_directories() {
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
}
