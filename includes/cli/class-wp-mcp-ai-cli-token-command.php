<?php
/**
 * WP-CLI `mcp-ai token` command for NV oOS.
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

if ( ! class_exists( 'WP_MCP_AI_CLI_Token_Command' ) ) {
	class WP_MCP_AI_CLI_Token_Command extends WP_MCP_AI_CLI_Base_Command {
		/**
		 * Migrate historical token tracking data to correct provider/model misattributions.
		 *
		 * Identifies records where Gemini tools were incorrectly tracked with OpenAI provider
		 * and corrects them with the proper provider, model, and recalculated costs.
		 *
		 * ## OPTIONS
		 *
		 * [--dry-run]
		 * : Preview changes without applying them.
		 *
		 * [--limit=<number>]
		 * : Maximum number of records to process (default: 1000).
		 * ---
		 * default: 1000
		 * ---
		 *
		 * [--format=<format>]
		 * : Output format.
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
		 *     # Preview what would be changed
		 *     $ wp mcp-ai token migrate-providers --dry-run
		 *
		 *     # Apply the migration to first 1000 records
		 *     $ wp mcp-ai token migrate-providers
		 *
		 *     # Apply migration with custom limit
		 *     $ wp mcp-ai token migrate-providers --limit=5000
		 *
		 * @since 1.1.0
		 *
		 * @param array $args       Positional arguments.
		 * @param array $assoc_args Associative arguments.
		 * @when after_wp_load
		 */
		public function migrate_providers( $args, $assoc_args ) {
			$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
			$limit   = \WP_CLI\Utils\get_flag_value( $assoc_args, 'limit', 1000 );
			$format  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'format', 'table' );

			$this->require_capability( 'manage_options' );

			if ( ! class_exists( 'WP_MCP_AI_Enhanced_Token_Tracking' ) ) {
				WP_CLI::error( 'Enhanced token tracking is not available.' );
				return;
			}

			if ( $dry_run ) {
				WP_CLI::log( WP_CLI::colorize( '%yDRY RUN MODE - No changes will be applied%n' ) );
			} else {
				WP_CLI::warning( 'Running migration. This will modify historical token tracking data.' );
			}

			WP_CLI::log( sprintf( 'Processing up to %d records...', $limit ) );

			// Run the migration.
			$results = WP_MCP_AI_Enhanced_Token_Tracking::migrate_provider_misattributions( $dry_run, $limit );

			WP_CLI::log( '' );
			WP_CLI::log( sprintf( 'Records checked: %d', $results['total_checked'] ) );
			WP_CLI::log( sprintf( 'Records to update: %d', $results['records_updated'] ) );

			if ( ! empty( $results['updates'] ) ) {
				WP_CLI::log( '' );
				WP_CLI::log( 'Sample updates:' );

				// Show first 10 updates.
				$sample_updates = array_slice( $results['updates'], 0, 10 );
				$display_items  = array();

				foreach ( $sample_updates as $update ) {
					$display_items[] = array(
						'ID'           => $update['id'],
						'Tool'         => $update['tool'],
						'Old Provider' => $update['old_provider'],
						'New Provider' => $update['new_provider'],
						'Old Model'    => substr( $update['old_model'], 0, 20 ),
						'New Model'    => substr( $update['new_model'], 0, 20 ),
						'Cost Change'  => sprintf( '$%.4f → $%.4f', $update['old_cost'], $update['new_cost'] ),
					);
				}

				\WP_CLI\Utils\format_items( $format, $display_items, array( 'ID', 'Tool', 'Old Provider', 'New Provider', 'Old Model', 'New Model', 'Cost Change' ) );

				if ( count( $results['updates'] ) > 10 ) {
					WP_CLI::log( sprintf( '... and %d more', count( $results['updates'] ) - 10 ) );
				}
			}

			WP_CLI::log( '' );

			if ( $dry_run ) {
				WP_CLI::success( sprintf( 'Dry run complete. Run without --dry-run to apply %d updates.', $results['records_updated'] ) );
			} else {
				WP_CLI::success( sprintf( 'Migration complete. Updated %d records.', $results['records_updated'] ) );
			}
		}
	}

}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai token', 'WP_MCP_AI_CLI_Token_Command' );
}
