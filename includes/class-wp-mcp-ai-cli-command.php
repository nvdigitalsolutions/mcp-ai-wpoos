<?php
/**
 * WP-CLI commands for the WP MCP AI plugin.
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
                    'description' => __( 'Enables WooCommerce aware MCP AI tools.', 'wp-mcp-ai' ),
                ),
                'jet-engine'  => array(
                    'name'        => __( 'JetEngine', 'wp-mcp-ai' ),
                    'slug'        => 'jet-engine',
                    'plugin_file' => 'jet-engine/jet-engine.php',
                    'description' => __( 'Unlocks JetEngine powered MCP AI tools.', 'wp-mcp-ai' ),
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
         * Root WP-CLI command for WP MCP AI.
         */
        class WP_MCP_AI_CLI_Command extends WP_CLI_Command {
            /**
             * Display a summary of the WordPress and MCP AI environment.
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
             *     # Show the current MCP AI environment status.
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

                $site_url   = get_option( 'siteurl' );
                $home_url   = get_option( 'home' );
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
                        'label'   => 'WP MCP AI Version',
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
        }
    }

    if ( ! class_exists( 'WP_MCP_AI_CLI_Plugins_Command' ) ) {
        /**
         * Manage supported MCP AI plugin dependencies.
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
                    WP_CLI::error( sprintf( __( 'Unsupported plugin slug: %s', 'wp-mcp-ai' ), $slug ) );
                }

                $network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

                $this->ensure_plugin_file_loaded();

                $plugin_file = $plugin['plugin_file'];
                $plugin_path = WP_PLUGIN_DIR . '/' . $plugin_file;

                if ( ! file_exists( $plugin_path ) ) {
                    WP_CLI::error( sprintf( __( '%s is not installed. Install it with `wp plugin install %s`.', 'wp-mcp-ai' ), $plugin['name'], $plugin['slug'] ) );
                }

                if ( is_plugin_active( $plugin_file ) ) {
                    WP_CLI::success( sprintf( __( '%s is already active.', 'wp-mcp-ai' ), $plugin['name'] ) );
                    return;
                }

                $result = activate_plugin( $plugin_file, '', $network );

                if ( is_wp_error( $result ) ) {
                    WP_CLI::error( $result );
                }

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
                    WP_CLI::error( sprintf( __( 'Unsupported plugin slug: %s', 'wp-mcp-ai' ), $slug ) );
                }

                $network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

                $this->ensure_plugin_file_loaded();

                $plugin_file = $plugin['plugin_file'];

                if ( ! is_plugin_active( $plugin_file ) && ! is_plugin_active_for_network( $plugin_file ) ) {
                    WP_CLI::success( sprintf( __( '%s is already inactive.', 'wp-mcp-ai' ), $plugin['name'] ) );
                    return;
                }

                deactivate_plugins( $plugin_file, false, $network );

                if ( is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file ) ) {
                    WP_CLI::error( sprintf( __( 'Failed to deactivate %s.', 'wp-mcp-ai' ), $plugin['name'] ) );
                }

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

    WP_CLI::add_command( 'mcp-ai', 'WP_MCP_AI_CLI_Command' );
    WP_CLI::add_command( 'mcp-ai plugins', 'WP_MCP_AI_CLI_Plugins_Command' );
}
