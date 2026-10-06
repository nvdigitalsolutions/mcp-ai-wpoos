<?php
/**
 * WP-CLI `mcp-ai plugins` command for NV oOS.
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

if ( ! function_exists( 'wp_mcp_ai_get_supported_plugins' ) ) {
	/**
	 * Retrieve a map of supported optional plugin dependencies.
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	/**
	 * Get supported plugins list for WP-CLI diagnostics.
	 *
	 * @since 1.0.0
	 *
	 * @return array[]
	 */
	function wp_mcp_ai_get_supported_plugins() {
		$plugins = array(
			'woocommerce' => array(
				'name'        => __( 'WooCommerce', 'mcp-ai-wpoos' ),
				'slug'        => 'woocommerce',
				'plugin_file' => 'woocommerce/woocommerce.php',
				'description' => __( 'Enables WooCommerce aware NV oOS tools.', 'mcp-ai-wpoos' ),
			),
			'jet-engine'  => array(
				'name'        => __( 'JetEngine', 'mcp-ai-wpoos' ),
				'slug'        => 'jet-engine',
				'plugin_file' => 'jet-engine/jet-engine.php',
				'description' => __( 'Unlocks JetEngine powered NV oOS tools.', 'mcp-ai-wpoos' ),
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

if ( ! class_exists( 'WP_MCP_AI_CLI_Plugins_Command' ) ) {
	class WP_MCP_AI_CLI_Plugins_Command extends WP_MCP_AI_CLI_Base_Command {
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
				$plugin_path = ( $plugin_file && defined( 'WP_PLUGIN_DIR' ) ) ? WP_PLUGIN_DIR . '/' . $plugin_file : '';
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
		 * @when after_wp_load
		 */
		public function list( $args, $assoc_args ) {
			$format  = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'table';
			$plugins = self::get_supported_plugins_with_status();

			if ( empty( $plugins ) ) {
				WP_CLI::warning( __( 'No supported plugins are registered.', 'mcp-ai-wpoos' ) );
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
		 * @when after_wp_load
		 */
		public function activate( $args, $assoc_args ) {
			if ( empty( $args ) ) {
				WP_CLI::error( __( 'Please provide a plugin slug.', 'mcp-ai-wpoos' ) );
			}

			$slug   = $args[0];
			$plugin = $this->get_supported_plugin( $slug );

			if ( ! $plugin ) {
				/* translators: %s: plugin slug */
				WP_CLI::error( sprintf( __( 'Unsupported plugin slug: %s', 'mcp-ai-wpoos' ), $slug ) );
			}

			$this->require_capability( 'manage_options' );

			$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

			$this->ensure_plugin_file_loaded();

			$plugin_file = $plugin['plugin_file'];
			$plugin_path = defined( 'WP_PLUGIN_DIR' ) ? WP_PLUGIN_DIR . '/' . $plugin_file : '';

			if ( ! $plugin_path || ! file_exists( $plugin_path ) ) {
				/* translators: 1: plugin name, 2: plugin slug */
				WP_CLI::error( sprintf( __( '%1$s is not installed. Install it with `wp plugin install %2$s`.', 'mcp-ai-wpoos' ), $plugin['name'], $plugin['slug'] ) );
			}

			if ( is_plugin_active( $plugin_file ) ) {
				/* translators: %s: plugin name */
				WP_CLI::success( sprintf( __( '%s is already active.', 'mcp-ai-wpoos' ), $plugin['name'] ) );
				return;
			}

			$result = activate_plugin( $plugin_file, '', $network );

			if ( is_wp_error( $result ) ) {
				WP_CLI::error( $result );
			}

			/* translators: %s: plugin name */
			WP_CLI::success( sprintf( __( '%s activated.', 'mcp-ai-wpoos' ), $plugin['name'] ) );
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
		 * @when after_wp_load
		 */
		public function deactivate( $args, $assoc_args ) {
			if ( empty( $args ) ) {
				WP_CLI::error( __( 'Please provide a plugin slug.', 'mcp-ai-wpoos' ) );
			}

			$slug   = $args[0];
			$plugin = $this->get_supported_plugin( $slug );

			if ( ! $plugin ) {
				/* translators: %s: plugin slug */
				WP_CLI::error( sprintf( __( 'Unsupported plugin slug: %s', 'mcp-ai-wpoos' ), $slug ) );
			}

			$this->require_capability( 'manage_options' );

			$network = \WP_CLI\Utils\get_flag_value( $assoc_args, 'network', false );

			$this->ensure_plugin_file_loaded();

			$plugin_file = $plugin['plugin_file'];

			if ( ! is_plugin_active( $plugin_file ) && ! is_plugin_active_for_network( $plugin_file ) ) {
				/* translators: %s: plugin name */
				WP_CLI::success( sprintf( __( '%s is already inactive.', 'mcp-ai-wpoos' ), $plugin['name'] ) );
				return;
			}

			deactivate_plugins( $plugin_file, false, $network );

			if ( is_plugin_active( $plugin_file ) || is_plugin_active_for_network( $plugin_file ) ) {
				/* translators: %s: plugin name */
				WP_CLI::error( sprintf( __( 'Failed to deactivate %s.', 'mcp-ai-wpoos' ), $plugin['name'] ) );
			}

			/* translators: %s: plugin name */
			WP_CLI::success( sprintf( __( '%s deactivated.', 'mcp-ai-wpoos' ), $plugin['name'] ) );
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

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai plugins', 'WP_MCP_AI_CLI_Plugins_Command' );
}
