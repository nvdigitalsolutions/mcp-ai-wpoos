<?php
/**
 * Plugin Name: NV oOS Fleet Operator (Hermes)
 * Description: Scoped external-operator credentials, server-side tool allowlisting, audit attribution, and Hermes config generation so a supervisor agent (Hermes or any MCP/A2A host) can operate this site within a declared scope.
 * Version: 1.0.0
 * Author: NV Digital Solutions
 * Author URI: https://nvdigitalsolutions.com
 * License: GPL-3.0-or-later
 * Text Domain: mcp-ai-wpoos
 * Requires PHP: 7.4
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

define( 'WP_MCP_AI_FLEET_OPERATOR_VERSION', '1.0.0' );
define( 'WP_MCP_AI_FLEET_OPERATOR_FILE', __FILE__ );
define( 'WP_MCP_AI_FLEET_OPERATOR_PATH', plugin_dir_path( __FILE__ ) );
define( 'WP_MCP_AI_FLEET_OPERATOR_URL', plugin_dir_url( __FILE__ ) );

require_once WP_MCP_AI_FLEET_OPERATOR_PATH . 'includes/class-wp-mcp-ai-operator-credential-repository.php';
require_once WP_MCP_AI_FLEET_OPERATOR_PATH . 'includes/class-wp-mcp-ai-operator-tool-scope.php';
require_once WP_MCP_AI_FLEET_OPERATOR_PATH . 'includes/class-wp-mcp-ai-operator-config-generator.php';
require_once WP_MCP_AI_FLEET_OPERATOR_PATH . 'includes/class-wp-mcp-ai-operator-authenticator.php';
require_once WP_MCP_AI_FLEET_OPERATOR_PATH . 'includes/class-wp-mcp-ai-operator-admin.php';
require_once WP_MCP_AI_FLEET_OPERATOR_PATH . 'includes/class-wp-mcp-ai-operator-cli.php';

/**
 * Bootstraps the Fleet Operator addon.
 */
class WP_MCP_AI_Fleet_Operator_Plugin {

	/**
	 * Initialise the addon once the base plugin is loaded.
	 *
	 * Runs on plugins_loaded (priority 20) so WP_MCP_AI classes from the
	 * base plugin are available. Shows a dismissible admin notice when the
	 * base plugin is missing or inactive.
	 *
	 * @return void
	 */
	public static function init() {
		if ( ! class_exists( 'WP_MCP_AI_REST_Authenticator' ) ) {
			add_action( 'admin_notices', array( __CLASS__, 'render_missing_base_notice' ) );
			return;
		}

		$authenticator = new WP_MCP_AI_Operator_Authenticator();
		$authenticator->register_hooks();

		if ( is_admin() ) {
			new WP_MCP_AI_Operator_Admin();
		}

		if ( defined( 'WP_CLI' ) && WP_CLI ) {
			WP_MCP_AI_Operator_CLI::register();
		}
	}

	/**
	 * Render an admin notice when the base NV oOS plugin is unavailable.
	 *
	 * @return void
	 */
	public static function render_missing_base_notice() {
		if ( ! current_user_can( 'activate_plugins' ) ) {
			return;
		}
		?>
		<div class="notice notice-error">
			<p>
			<?php
				echo esc_html__( 'NV oOS Fleet Operator requires the NV oOS (Open Operator System) plugin to be installed and active.', 'mcp-ai-wpoos' );
			?>
			</p>
		</div>
		<?php
	}
}

add_action( 'plugins_loaded', array( 'WP_MCP_AI_Fleet_Operator_Plugin', 'init' ), 20 );
