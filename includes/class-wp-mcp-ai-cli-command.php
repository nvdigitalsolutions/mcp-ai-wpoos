<?php
/**
 * WP-CLI commands for the WP oOS plugin.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	if ( ! function_exists( 'wp_mcp_ai_get_supported_plugins' ) ) {
		/**
		 * Retrieve a map of supported optional plugin dependencies.
		 *
		 * @since 1.0.0
		 *
		 * @return array[]
		 */
		function wp_mcp_ai_get_supported_plugins() {
			$plugins = array(
				'woocommerce' => array(
					'name'        => __( 'WooCommerce', 'wp-mcp-ai' ),
					'slug'        => 'woocommerce',
					'plugin_file' => 'woocommerce/woocommerce.php',
					'description' => __( 'Enables WooCommerce aware WP oOS tools.', 'wp-mcp-ai' ),
				),
				'jet-engine'  => array(
					'name'        => __( 'JetEngine', 'wp-mcp-ai' ),
					'slug'        => 'jet-engine',
					'plugin_file' => 'jet-engine/jet-engine.php',
					'description' => __( 'Unlocks JetEngine powered WP oOS tools.', 'wp-mcp-ai' ),
				),
			);

			/**
			 * Filter the supported plugins list exposed to the CLI command.
			 *
			 * @since 1.0.0
			 *
			 * @param array[] $plugins Associative array of plugin metadata keyed by slug.
			 */
			return apply_filters( 'wp_mcp_ai_supported_plugins', $plugins );
		}
	}

	if ( ! class_exists( 'WP_MCP_AI_CLI_Command' ) ) {
		/**
		 * Root WP-CLI command for WP oOS.
		 */
		class WP_MCP_AI_CLI_Command extends WP_CLI_Command {
			/**
			 * Display a summary of the WordPress and WP oOS environment.
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
			 *     # Show the current WP oOS environment status.
			 *     $ wp mcp-ai status
			 *
			 * @since 1.0.0
			 *
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function status( $args, $assoc_args ) {
				global $wp_version;

				$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';

				$site_url    = get_option( 'siteurl' );
				$home_url    = get_option( 'home' );
				$php_version = PHP_VERSION;
				$wp_env      = function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production';

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
						'label'   => 'WP oOS Version',
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
			 * Test remote MCP REST API connectivity from this site.
			 *
			 * ## OPTIONS
			 *
			 * <base>
			 * : Base URL to the remote MCP REST namespace (for example, https://example.com/wp-json/mcp-ai/v1).
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
			 * @since 1.0.0
			 *
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function remote( $args, $assoc_args ) {
				if ( empty( $args ) || ! isset( $args[0] ) ) {
					WP_CLI::error( __( 'Please provide the remote MCP REST base URL.', 'wp-mcp-ai' ) );
				}

				$base   = $args[0];
				$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

				$timeout_arg = \WP_CLI\Utils\get_flag_value( $assoc_args, 'timeout', WP_MCP_AI_Remote_Tester::DEFAULT_TIMEOUT );
				$timeout     = absint( $timeout_arg );

				if ( $timeout <= 0 ) {
					WP_CLI::error( __( 'Timeout must be a positive integer.', 'wp-mcp-ai' ) );
				}

				$verify_flag = \WP_CLI\Utils\get_flag_value( $assoc_args, 'verify-ssl', true );

				if ( is_string( $verify_flag ) ) {
					$parsed_verify = filter_var( $verify_flag, FILTER_VALIDATE_BOOLEAN, array( 'flags' => FILTER_NULL_ON_FAILURE ) );

					if ( null === $parsed_verify ) {
						WP_CLI::error( __( 'Invalid value for --verify-ssl. Use true or false.', 'wp-mcp-ai' ) );
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
						WP_CLI::line( sprintf( __( 'Token scope: %s', 'wp-mcp-ai' ), $token_scope ) );
					}

					if ( null !== $assistant_count ) {
						WP_CLI::success(
							sprintf(
								/* translators: %d: number of assistants found */
								_n( 'Remote MCP API reachable (%d assistant).', 'Remote MCP API reachable (%d assistants).', $assistant_count, 'wp-mcp-ai' ),
								$assistant_count
							)
						);
					} else {
						WP_CLI::success( __( 'Remote MCP API reachable.', 'wp-mcp-ai' ) );
					}

					return;
				}

				if ( ! empty( $rest_errors ) ) {
					foreach ( array_unique( $rest_errors ) as $error_code ) {
						/* translators: %s: REST API error code */
						WP_CLI::warning( sprintf( __( 'REST error code: %s', 'wp-mcp-ai' ), $error_code ) );
					}
				}

				WP_CLI::error( __( 'Remote MCP API check failed.', 'wp-mcp-ai' ) );
			}
		}
	}

	if ( ! class_exists( 'WP_MCP_AI_CLI_Plugins_Command' ) ) {
		/**
		 * Manage supported WP oOS plugin dependencies.
		 */
		class WP_MCP_AI_CLI_Plugins_Command extends WP_CLI_Command {
			/**
			 * Retrieve supported plugin metadata with calculated status.
			 *
			 * @since 1.0.0
			 *
			 * @return array[]
			 */
			public static function get_supported_plugins_with_status() {
				if ( ! function_exists( 'wp_mcp_ai_get_supported_plugins' ) ) {
					return array();
				}

				if ( ! function_exists( 'get_plugins' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}

				$supported = wp_mcp_ai_get_supported_plugins();
				$plugins   = array();

				foreach ( $supported as $slug => $plugin ) {
					$plugin_file = isset( $plugin['plugin_file'] ) ? $plugin['plugin_file'] : '';
					$plugin_path = $plugin_file ? WP_PLUGIN_DIR . '/' . $plugin_file : '';
					$installed   = $plugin_path && file_exists( $plugin_path );
					$active      = $installed && ( is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file ) );
					$version     = null;

					if ( $installed ) {
						$plugin_data = get_plugin_data( $plugin_path, false, false );
						if ( isset( $plugin_data['Version'] ) ) {
							$version = $plugin_data['Version'];
						}
					}

					$plugins[ $slug ] = array(
						'slug'        => $slug,
						'name'        => isset( $plugin['name'] ) ? $plugin['name'] : $slug,
						'status'      => $active ? 'active' : ( $installed ? 'inactive' : 'missing' ),
						'installed'   => $installed ? 'yes' : 'no',
						'active'      => $active ? 'yes' : 'no',
						'version'     => $version ? $version : '',
						'description' => isset( $plugin['description'] ) ? $plugin['description'] : '',
						'plugin_file' => $plugin_file,
					);
				}

				return $plugins;
			}

			/**
			 * List supported plugin dependencies and their status.
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
			 *     # List supported optional plugins.
			 *     $ wp mcp-ai plugins list
			 *
			 * @since 1.0.0
			 *
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function list_( $args, $assoc_args ) {
				$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
				$plugins = self::get_supported_plugins_with_status();

				if ( empty( $plugins ) ) {
					WP_CLI::warning( __( 'No supported plugins are registered.', 'wp-mcp-ai' ) );
					return;
				}

				$items = array();
				foreach ( $plugins as $plugin ) {
					$items[] = array(
						'slug'        => $plugin['slug'],
						'name'        => $plugin['name'],
						'status'      => $plugin['status'],
						'installed'   => $plugin['installed'],
						'active'      => $plugin['active'],
						'version'     => $plugin['version'],
						'description' => $plugin['description'],
					);
				}

				\WP_CLI\Utils\format_items( $format, $items, array( 'slug', 'name', 'status', 'installed', 'active', 'version', 'description' ) );
			}

			/**
			 * Activate a supported plugin dependency.
			 *
			 * ## OPTIONS
			 *
			 * <plugin>
			 * : Supported plugin slug (e.g. `woocommerce` or `jet-engine`).
			 *
			 * [--network]
			 * : Activate the plugin for the entire network (multisite only).
			 *
			 * ## EXAMPLES
			 *
			 *     # Activate WooCommerce.
			 *     $ wp mcp-ai plugins activate woocommerce
			 *
			 * @since 1.0.0
			 *
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function activate( $args, $assoc_args ) {
				if ( empty( $args ) ) {
					WP_CLI::error( __( 'Please provide a plugin slug.', 'wp-mcp-ai' ) );
				}

				$slug   = $args[0];
				$plugin = $this->get_supported_plugin( $slug );

				if ( ! $plugin ) {
					/* translators: %s: plugin slug */
					WP_CLI::error( sprintf( __( 'Unsupported plugin slug: %s', 'wp-mcp-ai' ), $slug ) );
				}

				$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

				$this->ensure_plugin_file_loaded();

				$plugin_file = $plugin['plugin_file'];
				$plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

				if ( ! file_exists( $plugin_path ) ) {
					/* translators: 1: plugin name, 2: plugin slug */
					WP_CLI::error( sprintf( __( '%1$s is not installed. Install it with `wp plugin install %2$s`.', 'wp-mcp-ai' ), $plugin['name'], $plugin['slug'] ) );
				}

				if ( is_plugin_active( $plugin_file ) ) {
					/* translators: %s: plugin name */
					WP_CLI::success( sprintf( __( '%s is already active.', 'wp-mcp-ai' ), $plugin['name'] ) );
					return;
				}

				$result = activate_plugin( $plugin_file, '', $network );

				if ( is_wp_error( $result ) ) {
					WP_CLI::error( $result );
				}

				/* translators: %s: plugin name */
				WP_CLI::success( sprintf( __( '%s activated.', 'wp-mcp-ai' ), $plugin['name'] ) );
			}

			/**
			 * Deactivate a supported plugin dependency.
			 *
			 * ## OPTIONS
			 *
			 * <plugin>
			 * : Supported plugin slug (e.g. `woocommerce` or `jet-engine`).
			 *
			 * [--network]
			 * : Deactivate the plugin across the network (multisite only).
			 *
			 * ## EXAMPLES
			 *
			 *     # Deactivate JetEngine.
			 *     $ wp mcp-ai plugins deactivate jet-engine
			 *
			 * @since 1.0.0
			 *
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function deactivate( $args, $assoc_args ) {
				if ( empty( $args ) ) {
					WP_CLI::error( __( 'Please provide a plugin slug.', 'wp-mcp-ai' ) );
				}

				$slug   = $args[0];
				$plugin = $this->get_supported_plugin( $slug );

				if ( ! $plugin ) {
					/* translators: %s: plugin slug */
					WP_CLI::error( sprintf( __( 'Unsupported plugin slug: %s', 'wp-mcp-ai' ), $slug ) );
				}

				$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

				$this->ensure_plugin_file_loaded();

				$plugin_file = $plugin['plugin_file'];

				if ( ! is_plugin_active( $plugin_file ) && ! is_plugin_active_for_network( $plugin_file ) ) {
					/* translators: %s: plugin name */
					WP_CLI::success( sprintf( __( '%s is already inactive.', 'wp-mcp-ai' ), $plugin['name'] ) );
					return;
				}

				deactivate_plugins( $plugin_file, false, $network );

				if ( is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file ) ) {
					/* translators: %s: plugin name */
					WP_CLI::error( sprintf( __( 'Failed to deactivate %s.', 'wp-mcp-ai' ), $plugin['name'] ) );
				}

				/* translators: %s: plugin name */
				WP_CLI::success( sprintf( __( '%s deactivated.', 'wp-mcp-ai' ), $plugin['name'] ) );
			}

			/**
			 * Get metadata for a supported plugin.
			 *
			 * @since 1.0.0
			 *
			 * @param string $slug Plugin slug.
			 * @return array|null
			 */
			protected function get_supported_plugin( $slug ) {
				$slug     = sanitize_key( $slug );
				$plugins  = wp_mcp_ai_get_supported_plugins();
				$fallback = null;

				if ( isset( $plugins[ $slug ] ) ) {
					$fallback = $plugins[ $slug ];
				}

				return $fallback;
			}

			/**
			 * Ensure core plugin functions are available.
			 */
			protected function ensure_plugin_file_loaded() {
				if ( ! function_exists( 'activate_plugin' ) ) {
					require_once ABSPATH . 'wp-admin/includes/plugin.php';
				}
			}
		}
	}

	if ( ! class_exists( 'WP_MCP_AI_CLI_Queue_Command' ) ) {
		/**
		 * WP-CLI commands for managing the job queue.
		 */
		class WP_MCP_AI_CLI_Queue_Command extends WP_CLI_Command {

			/**
			 * Display queue statistics.
			 *
			 * ## EXAMPLES
			 *
			 *     wp mcp-ai queue stats
			 *
			 * @subcommand stats
			 */
			public function stats() {
				$stats = WP_MCP_AI_Job_Queue_Manager::get_queue_stats();

				WP_CLI::line( 'Job Queue Statistics:' );
				WP_CLI::line( '  Total jobs:   ' . $stats['total'] );
				WP_CLI::line( '  Active jobs:  ' . $stats['active'] );
				WP_CLI::line( '  Pending jobs: ' . $stats['pending'] );
				WP_CLI::line( '  Failed jobs:  ' . $stats['failed'] );
			}

			/**
			 * Process the job queue.
			 *
			 * ## OPTIONS
			 *
			 * [--max-concurrent=<num>]
			 * : Maximum number of concurrent jobs to process. Default: 3
			 *
			 * ## EXAMPLES
			 *
			 *     wp mcp-ai queue process
			 *     wp mcp-ai queue process --max-concurrent=5
			 *
			 * @subcommand process
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function process( $args, $assoc_args ) {
				$max_concurrent = isset( $assoc_args['max-concurrent'] ) ? absint( $assoc_args['max-concurrent'] ) : 3;

				WP_CLI::line( 'Processing job queue...' );

				$result = WP_MCP_AI_Job_Queue_Manager::process_queue( $max_concurrent );

				WP_CLI::success(
					sprintf(
						'Processed %d jobs. %d jobs currently active.',
						$result['processed'],
						$result['active']
					)
				);
			}

			/**
			 * Clear the job queue.
			 *
			 * ## EXAMPLES
			 *
			 *     wp mcp-ai queue clear
			 *
			 * @subcommand clear
			 */
			public function clear() {
				WP_CLI::confirm( 'Are you sure you want to clear the entire job queue?' );

				WP_MCP_AI_Job_Queue_Manager::clear_queue();

				WP_CLI::success( 'Job queue cleared.' );
			}
		}
	}

	if ( ! class_exists( 'WP_MCP_AI_CLI_Token_Command' ) ) {
		/**
		 * WP-CLI commands for token tracking and cost management.
		 */
		class WP_MCP_AI_CLI_Token_Command extends WP_CLI_Command {
			/**
			 * Migrate historical token tracking data to correct provider/model misattributions.
			 *
			 * Identifies records where Gemini tools were incorrectly tracked with OpenAI provider
			 * and corrects them with the proper provider, model, and recalculated costs.
			 *
			 * ## OPTIONS
			 *
			 * [--dry-run]
			 * : Preview changes without applying them.
			 *
			 * [--limit=<number>]
			 * : Maximum number of records to process (default: 1000).
			 * ---
			 * default: 1000
			 * ---
			 *
			 * [--format=<format>]
			 * : Output format.
			 * ---
			 * default: table
			 * options:
			 *   - table
			 *   - json
			 *   - yaml
			 * ---
			 *
			 * ## EXAMPLES
			 *
			 *     # Preview what would be changed
			 *     $ wp mcp-ai token migrate-providers --dry-run
			 *
			 *     # Apply the migration to first 1000 records
			 *     $ wp mcp-ai token migrate-providers
			 *
			 *     # Apply migration with custom limit
			 *     $ wp mcp-ai token migrate-providers --limit=5000
			 *
			 * @since 1.1.0
			 *
			 * @param array $args       Positional arguments.
			 * @param array $assoc_args Associative arguments.
			 */
			public function migrate_providers( $args, $assoc_args ) {
				$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
				$limit   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 1000 );
				$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

				if ( ! class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
					WP_CLI::error( 'Enhanced token tracking is not available.' );
					return;
				}

				if ( $dry_run ) {
					WP_CLI::log( WP_CLI::colorize( '%yDRY RUN MODE - No changes will be applied%n' ) );
				} else {
					WP_CLI::warning( 'Running migration. This will modify historical token tracking data.' );
				}

				WP_CLI::log( sprintf( 'Processing up to %d records...', $limit ) );

				// Run the migration.
				$results = WP_MCP_AI_Enhanced_Token_Tracking::migrate_provider_misattributions( $dry_run, $limit );

				WP_CLI::log( '' );
				WP_CLI::log( sprintf( 'Records checked: %d', $results['total_checked'] ) );
				WP_CLI::log( sprintf( 'Records to update: %d', $results['records_updated'] ) );

				if ( ! empty( $results['updates'] ) ) {
					WP_CLI::log( '' );
					WP_CLI::log( 'Sample updates:' );

					// Show first 10 updates.
					$sample_updates = array_slice( $results['updates'], 0, 10 );
					$display_items  = array();

					foreach ( $sample_updates as $update ) {
						$display_items[] = array(
							'ID'           => $update['id'],
							'Tool'         => $update['tool'],
							'Old Provider' => $update['old_provider'],
							'New Provider' => $update['new_provider'],
							'Old Model'    => substr( $update['old_model'], 0, 20 ),
							'New Model'    => substr( $update['new_model'], 0, 20 ),
							'Cost Change'  => sprintf( '$%.4f → $%.4f', $update['old_cost'], $update['new_cost'] ),
						);
					}

					\WP_CLI\Utils\format_items( $format, $display_items, array( 'ID', 'Tool', 'Old Provider', 'New Provider', 'Old Model', 'New Model', 'Cost Change' ) );

					if ( count( $results['updates'] ) > 10 ) {
						WP_CLI::log( sprintf( '... and %d more', count( $results['updates'] ) - 10 ) );
					}
				}

				WP_CLI::log( '' );

				if ( $dry_run ) {
					WP_CLI::success( sprintf( 'Dry run complete. Run without --dry-run to apply %d updates.', $results['records_updated'] ) );
				} else {
					WP_CLI::success( sprintf( 'Migration complete. Updated %d records.', $results['records_updated'] ) );
				}
			}
		}
	}

	WP_CLI::add_command( 'mcp-ai', 'WP_MCP_AI_CLI_Command' );
	WP_CLI::add_command( 'mcp-ai plugins', 'WP_MCP_AI_CLI_Plugins_Command' );
	WP_CLI::add_command( 'mcp-ai queue', 'WP_MCP_AI_CLI_Queue_Command' );
	WP_CLI::add_command( 'mcp-ai token', 'WP_MCP_AI_CLI_Token_Command' );
}
