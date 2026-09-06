<?php
/**
 * WP-CLI settings command for the Content Graph AI addon.
 *
 * Reads the `nvoos_content_graph_settings` map (via the Content Graph
 * settings store) without exposing secrets: API-key entries live in the
 * encrypted credential store and are refused by `get_setting()`.
 *
 * @package NvoosContentGraphAi\Cli
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Cli;

use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp nvoos-cg-ai settings list|get` — read-only settings surface.
 *
 * @since 1.1.0
 */
final class SettingsCommand {

	/**
	 * Build the settings rows (sorted by key, secrets refused).
	 *
	 * @return array<int, array{Key: string, Value: string}>
	 */
	public static function get_settings_map(): array {
		$all = CoreBridge::instance()->settings->all();
		ksort( $all );

		$rows = array();
		foreach ( $all as $key => $value ) {
			$key     = (string) $key;
			$display = self::format_value( $key, $value );

			if ( null === $display ) {
				continue; // Secret key — refused.
			}

			$rows[] = array(
				'Key'   => $key,
				'Value' => $display,
			);
		}

		return $rows;
	}

	/**
	 * Read a single setting by key.
	 *
	 * Returns null when the key is unknown or holds a secret.
	 *
	 * @param string $key Settings key.
	 * @return string|null
	 */
	public static function get_setting( string $key ): ?string {
		$key = sanitize_key( $key );

		if ( '' === $key ) {
			return null;
		}

		$settings = CoreBridge::instance()->settings->all();

		if ( ! array_key_exists( $key, $settings ) ) {
			return null;
		}

		return self::format_value( $key, $settings[ $key ] );
	}

	/**
	 * Format a settings value for display; secrets return null.
	 *
	 * @param string $key   Settings key.
	 * @param mixed  $value Raw settings value.
	 * @return string|null
	 */
	private static function format_value( string $key, $value ): ?string {
		if ( false !== stripos( $key, 'api_key' ) ) {
			// Secrets live in the encrypted credential store — never
			// surface them through the generic settings map.
			return null;
		}

		if ( is_scalar( $value ) ) {
			return (string) $value;
		}

		if ( null === $value ) {
			return '';
		}

		return (string) wp_json_encode( $value );
	}

	/**
	 * List all readable settings.
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
	 *     # List settings (secrets are withheld).
	 *     $ wp nvoos-cg-ai settings list
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function run_list( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		unset( $args );

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, self::get_settings_map(), array( 'Key', 'Value' ) );
	}

	/**
	 * Get a single setting by key.
	 *
	 * ## OPTIONS
	 *
	 * <key>
	 * : The settings key to read (e.g. ai_default_provider).
	 *
	 * ## EXAMPLES
	 *
	 *     # Show the default provider.
	 *     $ wp nvoos-cg-ai settings get ai_default_provider
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function run_get( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		unset( $assoc_args );

		if ( empty( $args ) || ! is_string( $args[0] ) ) {
			\WP_CLI::error( __( 'Please provide a settings key.', 'nvoos-content-graph-ai' ) );
		}

		$value = self::get_setting( $args[0] );

		if ( null === $value ) {
			\WP_CLI::error(
				__( 'Setting not found or not readable (secret keys are withheld — use "wp nvoos-cg-ai key-status" for credentials).', 'nvoos-content-graph-ai' )
			);
		}

		\WP_CLI::log( $value );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
