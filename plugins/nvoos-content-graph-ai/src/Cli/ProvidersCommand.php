<?php
/**
 * WP-CLI providers command for the Content Graph AI addon.
 *
 * Lists the AI providers registered in the runtime together with their
 * credential state (via the encrypted credential store + resolver) and
 * the configured default. Mirrors the credential surface already
 * exposed by `wp nvoos-cg-ai key-status`, organised per provider.
 *
 * @package NvoosContentGraphAi\Cli
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Cli;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\CoreBridge;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * `wp nvoos-cg-ai providers list` — provider registry + credentials.
 *
 * @since 1.1.0
 */
final class ProvidersCommand {

	/**
	 * Build the provider rows.
	 *
	 * @return array<int, array{Provider: string, Credentials: string, Source: string, Default: string}>
	 */
	public static function get_providers(): array {
		$bridge  = CoreBridge::instance();
		$default = (string) $bridge->settings->get( 'ai_default_provider', 'openai' );

		$rows = array();
		foreach ( $bridge->providers->getRegisteredSlugs() as $slug ) {
			$rows[] = array(
				'Provider'    => $slug,
				'Credentials' => CredentialResolver::hasCredentials( $slug ) ? 'yes' : 'no',
				'Source'      => (string) CredentialResolver::getKeySource( $slug ),
				'Default'     => $slug === $default ? 'yes' : 'no',
			);
		}

		usort(
			$rows,
			static function ( array $a, array $b ): int {
				return strcmp( $a['Provider'], $b['Provider'] );
			}
		);

		return $rows;
	}

	/**
	 * List registered AI providers with credential state.
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
	 *     # List providers and their credential state.
	 *     $ wp nvoos-cg-ai providers list
	 *
	 * @param array<int, mixed>    $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function run( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		unset( $args );

		$format = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

		\WP_CLI\Utils\format_items( $format, self::get_providers(), array( 'Provider', 'Credentials', 'Source', 'Default' ) );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
