<?php
/**
 * WP-CLI commands for the native→Woo catalog migration (Proposal 112).
 *
 * Operator-facing conventions (WP-CLI handbook + VIP best practices):
 * dry-run is the DEFAULT — a destructive migration never runs without the
 * explicit `--run` flag plus an interactive confirm. The command names use
 * the plugin's NV oOS identity (nvoos-astro-bridge), never the legacy
 * data-layer prefix.
 *
 *   wp nvoos-astro-bridge migrate-native [--run] [--limit=<n>] [--offset=<n>]
 *   wp nvoos-astro-bridge migration-status
 *
 * @package NvoosAstroBridge\CLI
 */

namespace NvoosAstroBridge\CLI;

use NvoosAstroBridge\Woo\NativeCatalogMigrator;

/**
 * Catalog migration commands.
 */
class MigrationCommand {

	/**
	 * Register the commands — guarded so plugin files stay safe without
	 * WP-CLI present.
	 *
	 * @return void
	 */
	public static function register(): void {
		if ( ! defined( 'WP_CLI' ) || ! WP_CLI ) {
			return;
		}

		\WP_CLI::add_command(
			'nvoos-astro-bridge migrate-native',
			array( self::class, 'migrate_native' ),
			array(
				'shortdesc' => 'Migrate native products into WooCommerce (dry-run by default).',
				'synopsis'  => array(
					array(
						'type'        => 'flag',
						'name'        => 'run',
						'description' => 'Actually perform the migration. Without this flag the command only reports.',
					),
					array(
						'type'        => 'assoc',
						'name'        => 'limit',
						'description' => 'Cap the number of products processed.',
						'optional'    => true,
					),
					array(
						'type'        => 'assoc',
						'name'        => 'offset',
						'description' => 'Start offset for cursor-based runs.',
						'optional'    => true,
					),
				),
			)
		);

		\WP_CLI::add_command(
			'nvoos-astro-bridge migration-status',
			array( self::class, 'status' ),
			array(
				'shortdesc' => 'Show the native-to-Woo migration state.',
			)
		);
	}

	/**
	 * `wp nvoos-astro-bridge migrate-native`.
	 *
	 * @param array<int, string>   $args        Positional args (unused).
	 * @param array<string, mixed> $assoc_args  Named flags.
	 * @return void
	 */
	public function migrate_native( array $args, array $assoc_args ): void {
		$migrator = new NativeCatalogMigrator();

		if ( ! $migrator->is_available() ) {
			\WP_CLI::error( 'WooCommerce is not active — the migration requires it.' );
		}

		$limit  = isset( $assoc_args['limit'] ) ? (int) $assoc_args['limit'] : -1;
		$offset = isset( $assoc_args['offset'] ) ? (int) $assoc_args['offset'] : 0;

		if ( ! \WP_CLI\Utils\get_flag_value( $assoc_args, 'run', false ) ) {
			\WP_CLI::log( 'Dry run — no writes. Use --run to migrate.' );
			$this->render_report( $migrator->dry_run( $limit, $offset ) );
			return;
		}

		$total = $migrator->count_native();
		if ( $total > 50 ) {
			\WP_CLI::confirm(
				sprintf( 'Migrate up to %d native products into WooCommerce?', $total )
			);
		}

		$report = array(
			'scanned'       => 0,
			'create'        => 0,
			'update'        => 0,
			'failed'        => 0,
			'terms_created' => 0,
			'issues'        => array(),
		);

		wp_defer_term_counting( true );
		wp_suspend_cache_invalidation( true );

		$cursor = $offset;
		while ( true ) {
			$ids = $migrator->native_ids( $cursor, $migrator->batch_size() );
			if ( empty( $ids ) ) {
				break;
			}
			foreach ( $ids as $id ) {
				$result = $migrator->migrate_product( (int) $id );
				if ( is_wp_error( $result ) ) {
					++$report['failed'];
					$report['issues'][] = array(
						'nativeId' => (int) $id,
						'message'  => $result->get_error_message(),
					);
				} else {
					++$report['scanned'];
					if ( 'created' === $result['action'] ) {
						++$report['create'];
					} elseif ( 'updated' === $result['action'] ) {
						++$report['update'];
					} else {
						++$report['failed'];
					}
					foreach ( $result['issues'] as $issue ) {
						$report['issues'][] = $issue;
					}
				}
				if ( $limit > 0 && $report['scanned'] + $report['failed'] >= $limit ) {
					break 2;
				}
			}
			$cursor += $migrator->batch_size();
		}

		wp_defer_term_counting( false );
		wp_suspend_cache_invalidation( false );

		\WP_CLI::log( 'Migration complete.' );
		$this->render_report( $report );
	}

	/**
	 * `wp nvoos-astro-bridge migration-status`.
	 *
	 * @param array<int, string>   $args        Positional args (unused).
	 * @param array<string, mixed> $assoc_args  Named flags (unused).
	 * @return void
	 */
	public function status( array $args, array $assoc_args ): void { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter -- WP-CLI callbacks receive both parameters; this command needs neither.
		$migrator = new NativeCatalogMigrator();

		if ( ! $migrator->is_available() ) {
			\WP_CLI::log( 'WooCommerce is not active.' );
		}

		$state = $migrator->state();
		$rows  = array(
			array(
				'Field' => 'Native products',
				'Value' => (string) $migrator->count_native(),
			),
			array(
				'Field' => 'Migrated (Woo) products',
				'Value' => (string) $migrator->count_migrated(),
			),
			array(
				'Field' => 'Last sweep status',
				'Value' => (string) $state['status'],
			),
			array(
				'Field' => 'Scanned / created / updated / failed',
				'Value' => sprintf( '%d / %d / %d / %d', $state['scanned'], $state['created'], $state['updated'], $state['failed'] ),
			),
			array(
				'Field' => 'Started / finished',
				'Value' => sprintf( '%s / %s', $state['started_at'], $state['finished_at'] ),
			),
		);

		\WP_CLI\Utils\format_items( 'table', $rows, array( 'Field', 'Value' ) );

		if ( ! empty( $state['issues'] ) ) {
			\WP_CLI::log( 'Recent issues:' );
			$issue_rows = array_map(
				static function ( array $issue ): array {
					return array(
						'Native ID' => isset( $issue['nativeId'] ) ? (string) $issue['nativeId'] : '-',
						'Message'   => isset( $issue['message'] ) ? (string) $issue['message'] : '-',
					);
				},
				array_slice( $state['issues'], -10 )
			);
			\WP_CLI\Utils\format_items( 'table', $issue_rows, array( 'Native ID', 'Message' ) );
		}
	}

	/**
	 * Print a report (shared by dry-run and live runs).
	 *
	 * @param array<string, mixed> $report Migration report.
	 * @return void
	 */
	private function render_report( array $report ): void {
		\WP_CLI::log(
			sprintf(
				'Scanned %d of %d — would/created %d, would/updated %d, failed %d, terms to create %d.',
				(int) $report['scanned'],
				(int) $report['total'],
				(int) $report['create'],
				(int) $report['update'],
				(int) $report['failed'],
				(int) $report['terms_created']
			)
		);

		if ( ! empty( $report['issues'] ) ) {
			\WP_CLI::log( 'Issues:' );
			foreach ( array_slice( $report['issues'], 0, 20 ) as $issue ) {
				\WP_CLI::log(
					sprintf(
						'  #%s — %s',
						isset( $issue['nativeId'] ) ? (string) $issue['nativeId'] : '-',
						isset( $issue['message'] ) ? (string) $issue['message'] : '-'
					)
				);
			}
		}
	}
}
