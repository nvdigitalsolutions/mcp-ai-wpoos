<?php
/**
 * WP-CLI settings management commands for NV oOS.
 *
 * @package WP_MCP_AI
 * @subpackage CLI
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
	return;
}

require_once __DIR__ . '/class-wp-mcp-ai-cli-base-command.php';

/**
 * Read and update NV oOS plugin settings from the command line.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_CLI_Settings_Command extends WP_MCP_AI_CLI_Base_Command {

	/**
	 * Option key used to store plugin settings.
	 */
	const OPTION_KEY = 'wp_mcp_ai_settings';

	/**
	 * Get one or all plugin settings.
	 *
	 * ## OPTIONS
	 *
	 * [<key>]
	 * : Retrieve a single setting by key. Omit to list all settings.
	 *
	 * [--format=<format>]
	 * : Render output in the given format.
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
	 *     # List all settings.
	 *     $ wp mcp-ai settings get
	 *
	 *     # Get a single setting.
	 *     $ wp mcp-ai settings get openai_model
	 *
	 *     # Dump all settings as JSON.
	 *     $ wp mcp-ai settings get --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$key    = isset( $args[0] ) ? sanitize_key( $args[0] ) : '';
		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		$settings = get_option( self::OPTION_KEY, array() );

		// Strip sensitive values from output.
		$settings = $this->redact_sensitive_values( $settings );

		if ( $key ) {
			if ( ! array_key_exists( $key, $settings ) ) {
				/* translators: %s: setting key */
				WP_CLI::error( sprintf( __( 'Setting "%s" does not exist.', 'mcp-ai-wpoos' ), $key ) );
			}

			$value = $settings[ $key ];

			if ( 'json' === $format ) {
				WP_CLI::line( wp_json_encode( $value, JSON_PRETTY_PRINT ) );
				return;
			}

			if ( 'yaml' === $format ) {
				WP_CLI::line( "{$key}: " . ( is_scalar( $value ) ? $value : wp_json_encode( $value ) ) );
				return;
			}

			// Table: single row.
			$items = array(
				array(
					'key'   => $key,
					'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
				),
			);
			\WP_CLI\Utils\format_items( 'table', $items, array( 'key', 'value' ) );
			return;
		}

		// All settings.
		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $settings, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $settings as $k => $v ) {
				WP_CLI::line( "{$k}: " . ( is_scalar( $v ) ? $v : wp_json_encode( $v ) ) );
			}
			return;
		}

		$items = array();
		foreach ( $settings as $k => $v ) {
			$items[] = array(
				'key'   => $k,
				'value' => is_scalar( $v ) ? (string) $v : wp_json_encode( $v ),
			);
		}

		if ( empty( $items ) ) {
			WP_CLI::log( __( 'No settings stored yet.', 'mcp-ai-wpoos' ) );
			return;
		}

		\WP_CLI\Utils\format_items( $format, $items, array( 'key', 'value' ) );
	}

	/**
	 * Update a plugin setting.
	 *
	 * Writes a scalar or JSON value to the plugin settings option.  Use quotes
	 * around JSON so your shell does not interpret special characters.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The setting key to update.
	 *
	 * <value>
	 * : The new value. Pass a JSON string to set an array or object.
	 *
	 * ## EXAMPLES
	 *
	 *     # Change the active provider.
	 *     $ wp mcp-ai settings set active_provider openai
	 *
	 *     # Enable a boolean feature flag.
	 *     $ wp mcp-ai settings set enable_logging 1
	 *
	 * @subcommand set
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function set_( $args, $assoc_args ) {
		if ( count( $args ) < 2 ) {
			WP_CLI::error( __( 'Usage: wp mcp-ai settings set <key> <value>', 'mcp-ai-wpoos' ) );
		}

		$key   = sanitize_key( $args[0] );
		$value = $args[1];

		if ( ! $key ) {
			WP_CLI::error( __( 'Please provide a valid setting key.', 'mcp-ai-wpoos' ) );
		}

		$this->require_capability( 'manage_options' );

		// Attempt JSON decode; keep as string if not valid JSON.
		$decoded = json_decode( $value, true );
		if ( null !== $decoded && JSON_ERROR_NONE === json_last_error() ) {
			$value = $decoded;
		}

		$settings         = get_option( self::OPTION_KEY, array() );
		$settings[ $key ] = $value;

		update_option( self::OPTION_KEY, $settings );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		/* translators: %s: setting key */
		WP_CLI::success( sprintf( __( 'Setting "%s" updated.', 'mcp-ai-wpoos' ), $key ) );
	}

	/**
	 * Reset all plugin settings to their defaults.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     # Prompt before resetting.
	 *     $ wp mcp-ai settings reset
	 *
	 *     # Reset without prompting.
	 *     $ wp mcp-ai settings reset --yes
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function reset( $args, $assoc_args ) {
		$yes = \WP_CLI\Utils\get_flag_value( $assoc_args, 'yes', false );

		$this->require_capability( 'manage_options' );

		if ( ! $yes ) {
			WP_CLI::confirm( __( 'Are you sure you want to reset all NV oOS settings to their defaults?', 'mcp-ai-wpoos' ) );
		}

		$defaults = array();
		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) && method_exists( 'WP_MCP_AI_Admin_Settings', 'get_default_settings' ) ) {
			$defaults = WP_MCP_AI_Admin_Settings::get_default_settings();
		}

		update_option( self::OPTION_KEY, $defaults );

		if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
			WP_MCP_AI_Admin_Settings::reset_settings_cache();
		}

		WP_CLI::success( __( 'Settings reset to defaults.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Redact sensitive setting values before displaying them.
	 *
	 * @param array $settings Raw settings array.
	 * @return array Settings with sensitive values replaced by redacted placeholders.
	 */
	protected function redact_sensitive_values( $settings ) {
		$sensitive_suffixes = array( '_api_key', '_secret', '_token', '_password', '_credentials_json', '_refresh_token' );

		foreach ( $settings as $key => $value ) {
			if ( ! is_string( $value ) || '' === $value ) {
				continue;
			}

			foreach ( $sensitive_suffixes as $suffix ) {
				if ( substr( $key, -strlen( $suffix ) ) === $suffix ) {
					$settings[ $key ] = '[REDACTED]';
					break;
				}
			}
		}

		return $settings;
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai settings', 'WP_MCP_AI_CLI_Settings_Command' );
}
