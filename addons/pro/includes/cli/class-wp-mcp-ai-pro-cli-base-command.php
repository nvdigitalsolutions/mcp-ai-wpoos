<?php
/**
 * Abstract base class for NV oOS Pro WP-CLI commands.
 *
 * Extends the core base command so Pro commands inherit progress-bar helpers,
 * batch processing utilities, and consistent output formatting without
 * duplicating code.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

// Core base command must be available first.
if ( ! class_exists( 'WP_MCP_AI_CLI_Base_Command' ) ) {
	$base_file = WP_MCP_AI_PATH . 'includes/cli/class-wp-mcp-ai-cli-base-command.php';
	if ( file_exists( $base_file ) ) {
		require_once $base_file;
	}
}

/**
 * Abstract base for all Pro WP-CLI commands.
 *
 * @since 1.3.0
 */
abstract class WP_MCP_AI_Pro_CLI_Base_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Assert that the pro plugin is loaded.
	 *
	 * Calls WP_CLI::error and exits if the pro plugin has not initialised.
	 *
	 * @return void
	 */
	protected function assert_pro_loaded() {
		if ( ! defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			WP_CLI::error( __( 'NV oOS Pro is not active. Please activate the pro addon first.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * Assert that a specific pro toolkit is enabled.
	 *
	 * @param string $setting_key The settings key (e.g. enable_crm_toolkit).
	 * @param string $label       Human-readable toolkit name for the error message.
	 * @return void
	 */
	protected function assert_toolkit_enabled( $setting_key, $label ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings[ $setting_key ] ) ) {
			WP_CLI::error(
				sprintf(
					/* translators: 1: toolkit label, 2: setting key */
					__( 'The "%1$s" toolkit is not enabled. Enable it in NV oOS → Settings (%2$s).', 'mcp-ai-wpoos-pro' ),
					$label,
					$setting_key
				)
			);
		}
	}
}
