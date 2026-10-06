<?php
/**
 * WP-CLI `mcp-ai` command for NV oOS.
 *
 * Extracted from includes/class-wp-mcp-ai-cli-command.php
 * (Proposal 050, WP-CLI parity & hardening).
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

if ( ! class_exists( 'WP_MCP_AI_CLI_Command' ) ) {
	class WP_MCP_AI_CLI_Command extends WP_MCP_AI_CLI_Base_Command {
		/**
		 * Display a summary of the WordPress and NV oOS environment.
		 *
		 * ## OPTIONS
		 *
		 * [--format=<format>]
		 * : Render the output in a particular format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 *
		 * ## EXAMPLES
		 *
		 *     # Show the current NV oOS environment status.
		 *     $ wp mcp-ai status
		 *
		 * @since 1.0.0
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function status( $args, $assoc_args ) {
			global $wp_version;

			$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

			$site_url    = get_option( 'siteurl' );
			$home_url    = get_option( 'home' );
			$php_version = PHP_VERSION;
			$wp_env      = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

			if ( ! class_exists( 'WP_MCP_AI_CLI_Plugins_Command' ) ) {
				require_once __DIR__ . '/class-wp-mcp-ai-cli-plugins-command.php';
			}

			$supported_plugins = WP_MCP_AI_CLI_Plugins_Command::get_supported_plugins_with_status();
			$active_plugins    = array_filter(
				$supported_plugins,
				static function ( $plugin ) {
					return 'active' === $plugin['status'];
				}
			);

			$items = array(
				array(
					'context' => 'core',
					'label'   => 'WordPress Version',
					'value'   => $wp_version,
				),
				array(
					'context' => 'core',
					'label'   => 'Environment',
					'value'   => $wp_env,
				),
				array(
					'context' => 'core',
					'label'   => 'PHP Version',
					'value'   => $php_version,
				),
				array(
					'context' => 'core',
					'label'   => 'Site URL',
					'value'   => $site_url,
				),
				array(
					'context' => 'core',
					'label'   => 'Home URL',
					'value'   => $home_url,
				),
				array(
					'context' => 'plugin',
					'label'   => 'NV oOS Version',
					'value'   => defined( 'WP_MCP_AI_VERSION' ) ? WP_MCP_AI_VERSION : 'unknown',
				),
				array(
					'context' => 'plugin',
					'label'   => 'Supported Plugins (active)',
					'value'   => sprintf( '%d/%d', count( $active_plugins ), count( $supported_plugins ) ),
				),
			);

			\WP_CLI\Utils\format_items( $format, $items, array( 'context', 'label', 'value' ) );
		}

		/**
		 * Clean up orphaned CCT items for non-published assistants.
		 *
		 * Removes JetEngine CCT items that are linked to auto-drafts, drafts,
		 * or other non-published assistant posts. This is useful for cleaning
		 * up after the auto-draft sync fix is deployed.
		 *
		 * ## EXAMPLES
		 *
		 *     # Clean up orphaned CCT items.
		 *     $ wp mcp-ai cleanup-cct
		 *     Cleaning up orphaned CCT items for non-published assistants...
		 *     Success: Cleaned up 5 orphaned CCT item(s).
		 *
		 * @since 1.0.0
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function cleanup_cct( $args, $assoc_args ) {
			// Ensure the assistant CPT class is loaded.
			if ( ! class_exists( 'WP_MCP_AI_Assistant_CPT' ) ) {
				WP_CLI::error( 'Assistant CPT class not found.' );
				return;
			}

			$this->require_capability( 'manage_options' );

			WP_CLI::log( 'Cleaning up orphaned CCT items for non-published assistants...' );

			$result = WP_MCP_AI_Assistant_CPT::cleanup_orphaned_cct_items();

			if ( ! empty( $result['errors'] ) ) {
				foreach ( $result['errors'] as $error ) {
					WP_CLI::warning( $error );
				}
			}

			if ( $result['cleaned'] > 0 ) {
				WP_CLI::success( sprintf( 'Cleaned up %d orphaned CCT item(s).', $result['cleaned'] ) );
			} else {
				WP_CLI::log( 'No orphaned CCT items found.' );
			}
		}

		/**
		 * Probe a remote NV oOS instance.
		 *
		 * ## OPTIONS
		 *
		 * <url>
		 * : The REST base URL of the remote server.
		 *
		 * [--token=<token>]
		 * : Optional bearer credential (Auth0 access token or assistant credential).
		 *
		 * [--guest-token=<token>]
		 * : Optional guest token sent via the X-WP-MCP-AI-Guest header.
		 *
		 * [--nonce=<nonce>]
		 * : Optional WordPress REST nonce for same-origin checks.
		 *
		 * [--assistant-id=<id>]
		 * : Include an assistant hint when probing the directory endpoint.
		 *
		 * [--timeout=<seconds>]
		 * : Request timeout in seconds. Default: 15.
		 *
		 * [--verify-ssl=<boolean>]
		 * : Whether to verify the remote SSL certificate. Default: true.
		 *
		 * [--user-agent=<agent>]
		 * : Override the default user agent string.
		 *
		 * [--format=<format>]
		 * : Render the check output in table, json, or yaml format.
		 * ---
		 * default: table
		 * options:
		 *   - table
		 *   - json
		 *   - yaml
		 *
		 * ## EXAMPLES
		 *
		 *     # Probe a remote MCP deployment with an Auth0 access token.
		 *     $ wp mcp-ai remote https://example.com/wp-json/mcp-ai/v1 --token=ey...
		 *
		 *     # Probe with SSL verification disabled (local dev).
		 *     $ wp mcp-ai remote https://localhost/wp-json/mcp-ai/v1 --verify-ssl=false
		 *
		 * @since 1.0.0
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function remote( $args, $assoc_args ) {
			// Remote tester may not be available in production builds.
			if ( ! class_exists( 'WP_MCP_AI_Remote_Tester' ) ) {
				WP_CLI::error( __( 'Remote tester utility is not available in this build.', 'mcp-ai-wpoos' ) );
			}

			if ( empty( $args ) || ! isset( $args[0] ) ) {
				WP_CLI::error( __( 'Please provide the remote MCP REST base URL.', 'mcp-ai-wpoos' ) );
			}

			$base   = $args[0];
			$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			$timeout_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'timeout', WP_MCP_AI_Remote_Tester::DEFAULT_TIMEOUT );
			$timeout     = absint( $timeout_arg );

			if ( $timeout <= 0 ) {
				WP_CLI::error( __( 'Timeout must be a positive integer.', 'mcp-ai-wpoos' ) );
			}

			$verify_flag = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verify-ssl', true );

			if ( is_string( $verify_flag ) ) {
				$parsed_verify = filter_var( $verify_flag, FILTER_VALIDATE_BOOLEAN, array( 'flags' => FILTER_NULL_ON_FAILURE ) );

				if ( null === $parsed_verify ) {
					WP_CLI::error( __( 'Invalid value for --verify-ssl. Use true or false.', 'mcp-ai-wpoos' ) );
				}

				$verify_ssl = $parsed_verify;
			} else {
				$verify_ssl = (bool) $verify_flag;
			}

			$token       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'token', '' );
			$guest_token = \WP_CLI\Utils\get_flag_value( $assoc_args, 'guest-token', '' );
			$nonce       = \WP_CLI\Utils\get_flag_value( $assoc_args, 'nonce', '' );
			$assistant   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'assistant-id', '' );
			$user_agent  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'user-agent', '' );

			$options = array(
				'timeout'    => $timeout,
				'verify_ssl' => $verify_ssl,
			);

			if ( '' !== $token ) {
				$options['token'] = $token;
			}

			if ( '' !== $guest_token ) {
				$options['guest_token'] = $guest_token;
			}

			if ( '' !== $nonce ) {
				$options['nonce'] = $nonce;
			}

			if ( '' !== $assistant ) {
				$options['assistant_id'] = absint( $assistant );
			}

			if ( '' !== $user_agent ) {
				$options['user_agent'] = $user_agent;
			}

			$tester = new WP_MCP_AI_Remote_Tester();
			$result = $tester->probe( $base, $options );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result->get_error_message() );
			}

			$checks = array();
			foreach ( $result['checks'] as $check ) {
				$checks[] = array(
					'step'    => isset( $check['step'] ) ? $check['step'] : '',
					'status'  => isset( $check['status'] ) ? $check['status'] : '',
					'http'    => isset( $check['http_code'] ) && null !== $check['http_code'] ? $check['http_code'] : '',
					'message' => isset( $check['message'] ) ? $check['message'] : '',
				);
			}

			if ( ! empty( $checks ) ) {
				\WP_CLI\Utils\format_items( $format, $checks, array( 'step', 'status', 'http', 'message' ) );
			}

			$assistant_count = null;
			$token_scope     = null;
			$rest_errors     = array();

			foreach ( $result['checks'] as $check ) {
				if ( isset( $check['details']['assistant_count'] ) ) {
					$assistant_count = (int) $check['details']['assistant_count'];
				}

				if ( isset( $check['details']['token_scope']['type'] ) ) {
					$token_scope = $check['details']['token_scope']['type'];
				}

				if ( isset( $check['details']['rest_error_code'] ) && $check['details']['rest_error_code'] ) {
					$rest_errors[] = $check['details']['rest_error_code'];
				}
			}

			if ( $result['success'] ) {
				if ( $token_scope ) {
					/* translators: %s: OAuth token scope */
					WP_CLI::line( sprintf( __( 'Token scope: %s', 'mcp-ai-wpoos' ), $token_scope ) );
				}

				if ( null !== $assistant_count ) {
					WP_CLI::success(
						sprintf(
							/* translators: %d: number of assistants found */
							_n( 'Remote MCP API reachable (%d assistant).', 'Remote MCP API reachable (%d assistants).', $assistant_count, 'mcp-ai-wpoos' ),
							$assistant_count
						)
					);
				} else {
					WP_CLI::success( __( 'Remote MCP API reachable.', 'mcp-ai-wpoos' ) );
				}

				return;
			}

			if ( ! empty( $rest_errors ) ) {
				foreach ( array_unique( $rest_errors ) as $error_code ) {
					/* translators: %s: REST API error code */
					WP_CLI::warning( sprintf( __( 'REST error code: %s', 'mcp-ai-wpoos' ), $error_code ) );
				}
			}

			WP_CLI::error( __( 'Remote MCP API check failed.', 'mcp-ai-wpoos' ) );
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai', 'WP_MCP_AI_CLI_Command' );
}
