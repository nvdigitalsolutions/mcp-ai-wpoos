<?php
/**
 * WP-CLI status command for the Content Graph AI addon.
 *
 * Ports the base plugin's `wp mcp-ai status` shape (context/label/value
 * rows rendered by `WP_CLI\Utils\format_items`) for the ecosystem:
 * WordPress core facts, the Content Graph AI + Content Graph versions,
 * the install mode (monolith vs standalone), and AI runtime facts
 * (default provider/model, registered tools, provider credentials).
 *
 * The data logic lives in `get_items()` (no WP-CLI dependency) so the
 * characterization tests can exercise it without the WP-CLI runtime;
 * `run()` is the thin CLI wrapper.
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
 * `wp nvoos-cg-ai status` — ecosystem environment summary.
 *
 * @since 1.1.0
 */
final class StatusCommand {

	/**
	 * Build the status rows (context / label / value).
	 *
	 * @return array<int, array{context: string, label: string, value: string}>
	 */
	public static function get_items(): array {
		global $wp_version;

		$bridge     = CoreBridge::instance();
		$providers  = ProvidersCommand::get_providers();
		$with_creds = count(
			array_filter(
				$providers,
				static function ( array $provider ): bool {
					return 'yes' === $provider['Credentials'];
				}
			)
		);

		$items = array(
			array(
				'context' => 'core',
				'label'   => 'WordPress Version',
				'value'   => (string) $wp_version,
			),
			array(
				'context' => 'core',
				'label'   => 'Environment',
				'value'   => function_exists( 'wp_get_environment_type' ) ? wp_get_environment_type() : 'production',
			),
			array(
				'context' => 'core',
				'label'   => 'PHP Version',
				'value'   => PHP_VERSION,
			),
			array(
				'context' => 'core',
				'label'   => 'Site URL',
				'value'   => (string) get_option( 'siteurl' ),
			),
			array(
				'context' => 'core',
				'label'   => 'Home URL',
				'value'   => (string) get_option( 'home' ),
			),
			array(
				'context' => 'plugin',
				'label'   => 'Content Graph AI Version',
				'value'   => defined( 'NVOOS_CONTENT_GRAPH_AI_VERSION' ) ? NVOOS_CONTENT_GRAPH_AI_VERSION : 'unknown',
			),
			array(
				'context' => 'plugin',
				'label'   => 'Content Graph Version',
				'value'   => defined( 'NVOOS_CONTENT_GRAPH_VERSION' ) ? NVOOS_CONTENT_GRAPH_VERSION : 'unknown',
			),
			array(
				'context' => 'plugin',
				'label'   => 'Install Mode',
				'value'   => defined( 'WP_MCP_AI_PATH' ) ? 'monolith' : 'standalone',
			),
			array(
				'context' => 'plugin',
				'label'   => 'Default Provider',
				'value'   => (string) $bridge->settings->get( 'ai_default_provider', 'openai' ),
			),
			array(
				'context' => 'plugin',
				'label'   => 'Default Model',
				'value'   => (string) $bridge->settings->get( 'ai_default_model', '' ),
			),
			array(
				'context' => 'plugin',
				'label'   => 'Tools Registered',
				'value'   => (string) count( ToolsCommand::get_tools() ),
			),
			array(
				'context' => 'plugin',
				'label'   => 'Providers with Credentials',
				'value'   => sprintf( '%d/%d', $with_creds, count( $providers ) ),
			),
		);

		return $items;
	}

	/**
	 * Display a summary of the WordPress and ecosystem environment.
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
	 *     # Show the current ecosystem environment status.
	 *     $ wp nvoos-cg-ai status
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		unset( $args );

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, self::get_items(), array( 'context', 'label', 'value' ) );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
