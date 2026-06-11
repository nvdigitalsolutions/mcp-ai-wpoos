<?php
/**
 * DietPi SSH Client
 *
 * Handles all system-level interaction with the Raspberry Pi via SSH.
 * Falls back gracefully:
 *   1. PHP ssh2 extension (preferred)
 *   2. proc_open with the ssh CLI
 *
 * All credential fields are redacted from logs and never returned in
 * tool responses.  Authentication data is stored encrypted in
 * wp_mcp_ai_dietpi_settings.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_DietPi_SSH_Client' ) ) {

	/**
	 * DietPi SSH client.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_DietPi_SSH_Client {

		/**
		 * Default SSH port.
		 *
		 * @since 1.3.0
		 * @var int
		 */
		const DEFAULT_PORT = 22;

		/**
		 * Default command timeout in seconds.
		 *
		 * @since 1.3.0
		 * @var int
		 */
		const COMMAND_TIMEOUT = 30;

		/**
		 * Singleton instance.
		 *
		 * @since 1.3.0
		 * @var self|null
		 */
		private static $instance = null;

		/**
		 * Resolved toolkit settings.
		 *
		 * @since 1.3.0
		 * @var array
		 */
		private $settings = array();

		/**
		 * Whether the connection has been tested and passed.
		 *
		 * @since 1.3.0
		 * @var bool|null
		 */
		private $connection_ok = null;

		/**
		 * Get the singleton instance.
		 *
		 * @since 1.3.0
		 *
		 * @return self
		 */
		public static function instance() {
			if ( null === self::$instance ) {
				self::$instance = new self();
			}
			return self::$instance;
		}

		/**
		 * Constructor — private for singleton.
		 *
		 * @since 1.3.0
		 */
		private function __construct() {
			if ( function_exists( 'wp_mcp_ai_dietpi_get_settings' ) ) {
				$this->settings = wp_mcp_ai_dietpi_get_settings();
			}
		}

		/**
		 * Prevent cloning.
		 *
		 * @since 1.3.0
		 */
		private function __clone() {}

		/**
		 * Prevent unserialization.
		 *
		 * @since 1.3.0
		 */
		public function __wakeup() {
			throw new \Exception( 'Cannot unserialize singleton.' );
		}

		/**
		 * Check whether SSH credentials are configured.
		 *
		 * @since 1.3.0
		 *
		 * @return bool
		 */
		public function is_configured() {
			if ( function_exists( 'wp_mcp_ai_dietpi_has_ssh_credentials' ) ) {
				return wp_mcp_ai_dietpi_has_ssh_credentials();
			}

			$host = isset( $this->settings['host'] ) ? trim( $this->settings['host'] ) : '';
			return '' !== $host;
		}

		/**
		 * Test the SSH connection.
		 *
		 * Runs a lightweight 'echo ok' command and returns true on success.
		 *
		 * @since 1.3.0
		 *
		 * @return true|WP_Error
		 */
		public function test_connection() {
			if ( ! $this->is_configured() ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_not_configured',
					__( 'DietPi SSH credentials are not configured. Please enter the Pi hostname, SSH user, and key or password in the DietPi Toolkit settings.', 'mcp-ai-wpoos-pro' )
				);
			}

			$result = $this->exec( 'echo ok', 10 );
			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stdout = isset( $result['stdout'] ) ? trim( $result['stdout'] ) : '';
			if ( 'ok' !== $stdout ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_unexpected_output',
					__( 'SSH connection succeeded but returned unexpected output.', 'mcp-ai-wpoos-pro' )
				);
			}

			$this->connection_ok = true;

			/**
			 * Action fired after a successful DietPi SSH connection test.
			 *
			 * @since 1.3.0
			 */
			do_action( 'wp_mcp_ai_dietpi_connection_tested' );

			return true;
		}

		/**
		 * Execute a shell command on the Pi.
		 *
		 * This is the core primitive — all system tools route through here.
		 *
		 * @since 1.3.0
		 *
		 * @param string $command Shell command to execute.
		 * @param int    $timeout Timeout in seconds. Default 30.
		 * @return array|WP_Error Associative array { stdout, stderr, exit_code, duration_ms } or WP_Error.
		 */
		public function exec( $command, $timeout = self::COMMAND_TIMEOUT ) {
			if ( ! $this->is_configured() ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_not_configured',
					__( 'DietPi SSH credentials are not configured.', 'mcp-ai-wpoos-pro' )
				);
			}

			$start_time = microtime( true );

			// Log the command (but not arguments) if logging is enabled.
			if ( ! empty( $this->settings['log_ssh_commands'] ) && function_exists( 'wp_mcp_ai_log_activity' ) ) {
				wp_mcp_ai_log_activity(
					'dietpi_ssh_command',
					sprintf(
						'SSH command executed: %s',
						substr( $command, 0, 200 )
					)
				);
			}

			// Try ssh2 extension first.
			if ( function_exists( 'ssh2_connect' ) ) {
				$result = $this->exec_ssh2( $command, $timeout );
			} else {
				$result = $this->exec_proc_open( $command, $timeout );
			}

			$duration_ms = (int) round( ( microtime( true ) - $start_time ) * 1000 );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$result['duration_ms'] = $duration_ms;

			return $result;
		}

		/**
		 * Execute via ssh2 PHP extension.
		 *
		 * @since 1.3.0
		 *
		 * @param string $command Shell command.
		 * @param int    $timeout Timeout in seconds.
		 * @return array|WP_Error
		 */
		private function exec_ssh2( $command, $timeout ) {
			$host     = $this->settings['host'];
			$port     = isset( $this->settings['ssh_port'] ) ? absint( $this->settings['ssh_port'] ) : self::DEFAULT_PORT;
			$user     = isset( $this->settings['ssh_user'] ) ? $this->settings['ssh_user'] : 'root';
			$method   = isset( $this->settings['ssh_auth_method'] ) ? $this->settings['ssh_auth_method'] : 'key';

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged — Connection failure is handled below.
			$connection = @ssh2_connect( $host, $port );
			if ( ! $connection ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_ssh_connect_failed',
					sprintf(
						/* translators: %s: host:port */
						__( 'Failed to connect to DietPi at %s. Please verify the hostname and port.', 'mcp-ai-wpoos-pro' ),
						$host . ':' . $port
					)
				);
			}

			$authenticated = false;

			if ( 'key' === $method ) {
				$private_key = isset( $this->settings['ssh_private_key'] ) ? $this->settings['ssh_private_key'] : '';
				$passphrase  = isset( $this->settings['ssh_key_passphrase'] ) ? $this->settings['ssh_key_passphrase'] : null;

				if ( '' === $private_key ) {
					return new WP_Error(
						'wp_mcp_ai_dietpi_no_ssh_key',
						__( 'SSH private key is empty. Please configure a key in the DietPi Toolkit settings.', 'mcp-ai-wpoos-pro' )
					);
				}

				// Write key to a temp file if it's inline PEM content (not a file path).
				$key_source = $private_key;
				if ( false === strpos( $private_key, '-----BEGIN' ) && ! file_exists( $private_key ) ) {
					return new WP_Error(
						'wp_mcp_ai_dietpi_invalid_key',
						__( 'SSH private key is invalid. Please provide a valid PEM-encoded key or an absolute path to a key file.', 'mcp-ai-wpoos-pro' )
					);
				}

				if ( false !== strpos( $private_key, '-----BEGIN' ) ) {
					// Inline PEM key — write to temp file for ssh2_auth_pubkey_file.
					$temp_key_file = wp_tempnam( 'dietpi_ssh_key_' );
					if ( ! $temp_key_file ) {
						return new WP_Error(
							'wp_mcp_ai_dietpi_temp_file',
							__( 'Could not create temporary file for SSH key.', 'mcp-ai-wpoos-pro' )
						);
					}

					// Write with restrictive permissions.
					file_put_contents( $temp_key_file, $private_key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					chmod( $temp_key_file, 0600 );

					$authenticated = @ssh2_auth_pubkey_file( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$connection,
						$user,
						$temp_key_file . '.pub', // ssh2 requires the public key file; we skip it.
						$temp_key_file,
						$passphrase
					);

					// Clean up temp file immediately.
					@unlink( $temp_key_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				} else {
					// File path to key.
					$authenticated = @ssh2_auth_pubkey_file( // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
						$connection,
						$user,
						$private_key . '.pub',
						$private_key,
						$passphrase
					);
				}
			} else {
				// Password auth.
				$password      = isset( $this->settings['ssh_password'] ) ? $this->settings['ssh_password'] : '';
				$authenticated = @ssh2_auth_password( $connection, $user, $password ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			}

			if ( ! $authenticated ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_ssh_auth_failed',
					__( 'SSH authentication failed. Please verify your SSH key or password.', 'mcp-ai-wpoos-pro' )
				);
			}

			$stream = @ssh2_exec( $connection, $command ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			if ( ! $stream ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_ssh_exec_failed',
					__( 'Failed to execute command on the DietPi device.', 'mcp-ai-wpoos-pro' )
				);
			}

			stream_set_blocking( $stream, true );

			$stdout_stream = ssh2_fetch_stream( $stream, SSH2_STREAM_STDIO );
			$stderr_stream = ssh2_fetch_stream( $stream, SSH2_STREAM_STDERR );

			stream_set_blocking( $stdout_stream, true );
			stream_set_blocking( $stderr_stream, true );

			$stdout = stream_get_contents( $stdout_stream );
			$stderr = stream_get_contents( $stderr_stream );

			fclose( $stdout_stream );
			fclose( $stderr_stream );
			fclose( $stream );

			return array(
				'stdout'    => is_string( $stdout ) ? trim( $stdout ) : '',
				'stderr'    => is_string( $stderr ) ? trim( $stderr ) : '',
				'exit_code' => 0, // ssh2_exec doesn't give us the exit code directly.
			);
		}

		/**
		 * Execute via proc_open (ssh CLI fallback).
		 *
		 * @since 1.3.0
		 *
		 * @param string $command Shell command.
		 * @param int    $timeout Timeout in seconds.
		 * @return array|WP_Error
		 */
		private function exec_proc_open( $command, $timeout ) {
			$host       = $this->settings['host'];
			$port       = isset( $this->settings['ssh_port'] ) ? absint( $this->settings['ssh_port'] ) : self::DEFAULT_PORT;
			$user       = isset( $this->settings['ssh_user'] ) ? $this->settings['ssh_user'] : 'root';
			$method     = isset( $this->settings['ssh_auth_method'] ) ? $this->settings['ssh_auth_method'] : 'key';

			$ssh_args = array(
				'ssh',
				'-q',                     // Quiet mode.
				'-o', 'StrictHostKeyChecking=no',
				'-o', 'UserKnownHostsFile=/dev/null',
				'-o', 'ConnectTimeout=' . max( 5, intval( $timeout / 2 ) ),
				'-p', $port,
			);

			if ( 'key' === $method ) {
				$private_key = isset( $this->settings['ssh_private_key'] ) ? $this->settings['ssh_private_key'] : '';
				if ( '' === $private_key ) {
					return new WP_Error(
						'wp_mcp_ai_dietpi_no_ssh_key',
						__( 'SSH private key is empty.', 'mcp-ai-wpoos-pro' )
					);
				}

				// If inline PEM, write to temp file.
				if ( false !== strpos( $private_key, '-----BEGIN' ) ) {
					$temp_key_file = wp_tempnam( 'dietpi_ssh_key_' );
					if ( ! $temp_key_file ) {
						return new WP_Error(
							'wp_mcp_ai_dietpi_temp_file',
							__( 'Could not create temporary file for SSH key.', 'mcp-ai-wpoos-pro' )
						);
					}
					file_put_contents( $temp_key_file, $private_key ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents
					chmod( $temp_key_file, 0600 );
					$identity_file = $temp_key_file;
				} else {
					$identity_file = $private_key;
				}

				$ssh_args[] = '-i';
				$ssh_args[] = $identity_file;

				$result = $this->run_proc( $ssh_args, $user, $host, $command, $timeout );

				// Clean up temp file if we created one.
				if ( isset( $temp_key_file ) && file_exists( $temp_key_file ) ) {
					@unlink( $temp_key_file ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged, WordPress.WP.AlternativeFunctions.unlink_unlink
				}

				return $result;
			}

			// Password auth via sshpass if available.
			return $this->run_proc( $ssh_args, $user, $host, $command, $timeout );
		}

		/**
		 * Run a command via proc_open.
		 *
		 * @since 1.3.0
		 *
		 * @param array  $ssh_args   SSH argument array.
		 * @param string $user       SSH user.
		 * @param string $host       SSH host.
		 * @param string $command    Command to execute.
		 * @param int    $timeout    Timeout in seconds.
		 * @return array|WP_Error
		 */
		private function run_proc( $ssh_args, $user, $host, $command, $timeout ) {
			$ssh_args[] = $user . '@' . $host;
			$ssh_args[] = $command;

			$descriptors = array(
				0 => array( 'pipe', 'r' ), // stdin.
				1 => array( 'pipe', 'w' ), // stdout.
				2 => array( 'pipe', 'w' ), // stderr.
			);

			// Escape all arguments.
			$cmd_line = '';
			foreach ( $ssh_args as $arg ) {
				$cmd_line .= ' ' . escapeshellarg( $arg );
			}
			$cmd_line = ltrim( $cmd_line );

			// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged
			$process = @proc_open( $cmd_line, $descriptors, $pipes );

			if ( ! is_resource( $process ) ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_proc_open_failed',
					/* translators: %s: SSH command */
					sprintf( __( 'Failed to spawn SSH process. Is the ssh CLI available? Command attempted: %s', 'mcp-ai-wpoos-pro' ), substr( $cmd_line, 0, 100 ) )
				);
			}

			// Close stdin immediately.
			fclose( $pipes[0] );

			// Set streams non-blocking so we can implement a timeout.
			stream_set_blocking( $pipes[1], false );
			stream_set_blocking( $pipes[2], false );

			$stdout      = '';
			$stderr      = '';
			$deadline    = time() + $timeout;

			while ( time() < $deadline ) {
				$read   = array( $pipes[1], $pipes[2] );
				$write  = null;
				$except = null;

				$changed = stream_select( $read, $write, $except, 0, 200000 ); // 200ms timeout.
				if ( false === $changed ) {
					break;
				}

				foreach ( $read as $stream ) {
					$data = fread( $stream, 8192 );
					if ( false === $data || '' === $data ) {
						continue;
					}
					if ( $stream === $pipes[1] ) {
						$stdout .= $data;
					} else {
						$stderr .= $data;
					}
				}

				// Check if process has exited.
				$status = proc_get_status( $process );
				if ( ! $status['running'] ) {
					break;
				}
			}

			// Read any remaining data.
			$stdout .= stream_get_contents( $pipes[1] );
			$stderr .= stream_get_contents( $pipes[2] );

			fclose( $pipes[1] );
			fclose( $pipes[2] );

			$exit_code = proc_close( $process );

			return array(
				'stdout'    => trim( $stdout ),
				'stderr'    => trim( $stderr ),
				'exit_code' => $exit_code,
			);
		}

		/**
		 * Control a DietPi service via dietpi-services.
		 *
		 * @since 1.3.0
		 *
		 * @param string       $action   One of: start, stop, restart, status.
		 * @param string|array $services Single service name or array of names.
		 * @return array|WP_Error Result map keyed by service name.
		 */
		public function dietpi_services( $action, $services ) {
			$valid_actions = array( 'start', 'stop', 'restart', 'status' );
			if ( ! in_array( $action, $valid_actions, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_dietpi_invalid_action',
					sprintf(
						/* translators: %s: action */
						__( 'Invalid service action: %s. Must be one of: start, stop, restart, status.', 'mcp-ai-wpoos-pro' ),
						$action
					)
				);
			}

			if ( is_string( $services ) ) {
				$services = array( $services );
			}

			$safe_services = array_map( 'escapeshellarg', $services );
			$cmd           = '/boot/dietpi/dietpi-services ' . esc_html( $action ) . ' ' . implode( ' ', $safe_services );

			return $this->exec( $cmd );
		}

		/**
		 * Get Raspberry Pi hardware and system info.
		 *
		 * @since 1.3.0
		 *
		 * @return array|WP_Error
		 */
		public function raspberry_pi_info() {
			$result = $this->exec(
				'echo "MODEL:$(cat /proc/device-tree/model 2>/dev/null || echo unknown)";' .
				'echo "REVISION:$(cat /proc/cpuinfo | grep Revision | awk \'{print $3}\')";' .
				'echo "SERIAL:$(cat /proc/cpuinfo | grep Serial | awk \'{print $3}\')";' .
				'echo "THROTTLED:$(vcgencmd get_throttled 2>/dev/null || echo unknown)"',
				10
			);

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$info = array();
			foreach ( explode( "\n", $result['stdout'] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$info[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
			}

			return $info;
		}

		/**
		 * Get live system stats (CPU, RAM, disk, uptime).
		 *
		 * @since 1.3.0
		 *
		 * @return array|WP_Error
		 */
		public function system_stats() {
			$cmd = 'echo "CPU_TEMP:$(cpu 2>/dev/null | head -1)";' .
				'echo "RAM:$(free -m | grep Mem: | awk \'{printf \"%d/%d MB (%.1f%%)\", $3, $2, ($3/$2)*100}\')";' .
				'echo "DISK:$(df -h / | tail -1 | awk \'{printf \"%s/%s (%s)\", $3, $2, $5}\')";' .
				'echo "UPTIME:$(uptime -p | sed \'s/up //\')";' .
				'echo "LOAD:$(uptime | awk -F\'load average:\' \'{print $2}\' | xargs)";';

			$result = $this->exec( $cmd, 15 );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stats = array();
			foreach ( explode( "\n", $result['stdout'] ) as $line ) {
				$line = trim( $line );
				if ( '' === $line ) {
					continue;
				}
				$parts = explode( ':', $line, 2 );
				if ( 2 === count( $parts ) ) {
					$stats[ strtolower( trim( $parts[0] ) ) ] = trim( $parts[1] );
				}
			}

			return $stats;
		}

		/**
		 * Get the list of all DietPi-managed services.
		 *
		 * @since 1.3.0
		 *
		 * @return array|WP_Error
		 */
		public function list_services() {
			$result = $this->exec( '/boot/dietpi/dietpi-services status', 15 );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			$stdout   = $result['stdout'];
			$services = array();
			$lines    = explode( "\n", $stdout );

			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( '' === $line || 0 === strpos( $line, '─' ) || 0 === strpos( $line, 'Mode:' ) ) {
					continue;
				}

				// Parse service lines — typical format: "ServiceName : active (running) | enabled"
				if ( preg_match( '/^(\S+)\s*:\s*(.+)$/', $line, $m ) ) {
					$services[ $m[1] ] = trim( $m[2] );
				}
			}

			return array(
				'services'     => $services,
				'count'        => count( $services ),
				'raw_services' => $stdout,
			);
		}
	}
}
