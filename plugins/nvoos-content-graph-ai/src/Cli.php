<?php
/**
 * WP-CLI surface for credential management.
 *
 *   wp nvoos-cg-ai migrate-keys   Run the plaintext → encrypted migration.
 *   wp nvoos-cg-ai key-status     Show per-provider credential status.
 *
 * @package NvoosContentGraphAi
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi;

use NvoosContentGraphAi\Adapter\CredentialResolver;
use NvoosContentGraphAi\Security\CredentialStore;

/**
 * Registers and implements the plugin's WP-CLI commands.
 */
final class Cli {

	/**
	 * Register WP-CLI commands (hooked to cli_init).
	 *
	 * @return void
	 */
	public static function registerCommands(): void {
		if ( ! class_exists( 'WP_CLI' ) ) {
			return;
		}

		\WP_CLI::add_command( 'nvoos-cg-ai migrate-keys', array( self::class, 'cmdMigrateKeys' ) );
		\WP_CLI::add_command( 'nvoos-cg-ai key-status', array( self::class, 'cmdKeyStatus' ) );

		// Ecosystem surface (Wave D6): status / tools / providers / settings
		// / graph — data logic lives in the command classes; these wrappers
		// only register the callables.
		\WP_CLI::add_command( 'nvoos-cg-ai status', array( \NvoosContentGraphAi\Cli\StatusCommand::class, 'run' ) );
		\WP_CLI::add_command( 'nvoos-cg-ai tools list', array( \NvoosContentGraphAi\Cli\ToolsCommand::class, 'run' ) );
		\WP_CLI::add_command( 'nvoos-cg-ai providers list', array( \NvoosContentGraphAi\Cli\ProvidersCommand::class, 'run' ) );
		\WP_CLI::add_command( 'nvoos-cg-ai settings list', array( \NvoosContentGraphAi\Cli\SettingsCommand::class, 'run_list' ) );
		\WP_CLI::add_command( 'nvoos-cg-ai settings get', array( \NvoosContentGraphAi\Cli\SettingsCommand::class, 'run_get' ) );
		\WP_CLI::add_command( 'nvoos-cg-ai graph stats', array( \NvoosContentGraphAi\Cli\GraphCommand::class, 'run' ) );

		// Engine surface (Wave E6): OOS shadow parity reporting — the base
		// plugin owns `wp mcp-ai oos parity` in monolith installs.
		if ( ! defined( 'WP_MCP_AI_PATH' ) ) {
			\WP_CLI::add_command( 'nvoos-cg-ai oos parity', array( \NvoosContentGraphAi\Cli\OosParityCommand::class, 'report' ) );
			\WP_CLI::add_command( 'nvoos-cg-ai oos parity diff', array( \NvoosContentGraphAi\Cli\OosParityCommand::class, 'diff' ) );
		}
	}

	/**
	 * Migrate legacy plaintext keys into the encrypted credential store.
	 *
	 * ## OPTIONS
	 *
	 * [--yes]
	 * : Skip the confirmation prompt.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nvoos-cg-ai migrate-keys --yes
	 *
	 * @param array<int, mixed> $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function cmdMigrateKeys( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		if ( empty( $assoc_args['yes'] ) ) {
			\WP_CLI::confirm( 'Encrypt any plaintext API keys and remove them from the general settings option?' );
		}

		$result = CredentialStore::migrateAll();

		if ( 0 === $result['migrated'] && empty( $result['failures'] ) ) {
			\WP_CLI::success( 'Nothing to migrate — no plaintext keys found.' );
			return;
		}

		if ( ! empty( $result['failures'] ) ) {
			\WP_CLI::warning(
				sprintf(
					/* translators: 1: migrated count, 2: failed count, 3: failed suffixes */
					'%1$d key(s) migrated, %2$d failed: %3$s',
					$result['migrated'],
					count( $result['failures'] ),
					implode( ', ', $result['failures'] )
				)
			);
			return;
		}

		\WP_CLI::success(
			sprintf(
				/* translators: %d: number of migrated keys */
				'%d API key(s) encrypted and migrated.',
				$result['migrated']
			)
		);
	}

	/**
	 * Show per-provider credential status.
	 *
	 * ## EXAMPLES
	 *
	 *     wp nvoos-cg-ai key-status
	 *
	 * @param array<int, mixed> $args       Positional arguments.
	 * @param array<string, mixed> $assoc_args Associative arguments.
	 * @return void
	 */
	public static function cmdKeyStatus( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.FoundAfterLastUsed -- WP-CLI command signature.
		$rows = array();

		foreach ( CredentialStore::getManagedSuffixes() as $suffix ) {
			$provider = CredentialStore::SUFFIX_TO_PROVIDER[ $suffix ] ?? $suffix;

			$rows[] = array(
				'Provider' => $provider,
				'Stored'   => CredentialStore::has( $suffix ) ? 'yes' : 'no',
				'Source'   => CredentialResolver::getKeySource( $provider ),
			);
		}

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Provider', 'Stored', 'Source' ) );
	}

	/** Private constructor — not instantiable. */
	private function __construct() {}
}
