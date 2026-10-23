<?php
/**
 * WP-CLI Model Foundry commands for NV oOS Pro.
 *
 * Operator surface for the Corpus Foundry slice (Proposal 067, Phase 1):
 * status reporting and corpus exports routed through the canonical tool
 * classes, so the CLI always honours the same consent gate, governance
 * pipeline, and output layout as the MCP tools.
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

require_once __DIR__ . '/class-wp-mcp-ai-pro-cli-base-command.php';

/**
 * Manage the Model Foundry — Corpus Foundry slice.
 *
 * ## EXAMPLES
 *
 *     # Foundry status.
 *     $ wp mcp-ai foundry status
 *
 *     # Dry-run a trajectory corpus export for assistant 123.
 *     $ wp mcp-ai foundry export 123 --corpus=trajectory --dry-run
 *
 *     # Export preference pairs as JSON.
 *     $ wp mcp-ai foundry export 123 --corpus=preference --format=json
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Foundry_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Corpus slug → tool slug map for the export verb.
	 *
	 * @var array<string,string>
	 */
	const CORPUS_TOOLS = array(
		'trajectory' => 'export_trajectory_corpus',
		'preference' => 'export_preference_pairs',
		'docs'       => 'export_plugin_docs_corpus',
	);

	/**
	 * Show the Model Foundry status.
	 *
	 * ## OPTIONS
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Foundry status as a table.
	 *     $ wp mcp-ai foundry status
	 *
	 *     # Export status as JSON.
	 *     $ wp mcp-ai foundry status --format=json
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function status( $args, $assoc_args ) {
		unset( $args );
		$this->assert_pro_loaded();

		$format = $this->get_format( $assoc_args, 'table' );

		$module_active = class_exists( 'WP_MCP_AI_Pro_Module_Registry' )
			&& WP_MCP_AI_Pro_Module_Registry::get_instance()->is_loaded( 'model_foundry' );

		$uploads     = wp_upload_dir();
		$exports_dir = trailingslashit( $uploads['basedir'] ) . 'mcp-ai/model-foundry';

		$consent_assistants = array();
		if ( post_type_exists( 'mcp_ai_assistant' ) ) {
			$consent_assistants = get_posts(
				array(
					'post_type'      => 'mcp_ai_assistant',
					'post_status'    => 'any',
					'posts_per_page' => -1,
					'meta_key'       => '_wp_mcp_ai_training_consent', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional: single-key consent scan, bounded by assistant count.
					'meta_value'     => '1', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional: consent is a boolean marker.
					'fields'         => 'ids',
				)
			);
		}

		$preference_pairs = 0;
		if ( class_exists( 'WP_MCP_AI_Preference_Pair_Store' ) ) {
			foreach ( $consent_assistants as $assistant_id ) {
				$preference_pairs += WP_MCP_AI_Preference_Pair_Store::count( (int) $assistant_id );
			}
		}

		$this->render_envelope(
			array(
				'Module Active'         => $module_active,
				'Exports Dir'           => $exports_dir,
				'Exports Dir Exists'    => is_dir( $exports_dir ),
				'Consenting Assistants' => count( $consent_assistants ),
				'Preference Pairs'      => $preference_pairs,
			),
			$format
		);
	}

	/**
	 * Export a corpus for an assistant.
	 *
	 * Routes through the canonical export tool (same consent gate and
	 * governance pipeline as the MCP surface).
	 *
	 * ## OPTIONS
	 *
	 * <assistant-id>
	 * : Assistant post ID whose data is exported.
	 *
	 * [--corpus=<corpus>]
	 * : Corpus to export.
	 * ---
	 * default: trajectory
	 * options:
	 *   - trajectory
	 *   - preference
	 *   - docs
	 * ---
	 *
	 * [--dry-run]
	 * : Report what would be exported without writing files.
	 *
	 * [--format=<format>]
	 * : Output format.
	 * ---
	 * default: table
	 * options:
	 *   - table
	 *   - json
	 *   - yaml
	 *   - csv
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     # Export the trajectory corpus for assistant 123.
	 *     $ wp mcp-ai foundry export 123 --yes
	 *
	 *     # Preview a docs-corpus export without writing files.
	 *     $ wp mcp-ai foundry export 123 --corpus=docs --dry-run
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function export( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->require_capability( 'manage_options' );

		$assistant_id = isset( $args[0] ) ? absint( $args[0] ) : 0;
		if ( $assistant_id <= 0 ) {
			WP_CLI::error( __( 'Please provide an assistant ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$corpus  = \WP_CLI\Utils\get_flag_value( $assoc_args, 'corpus', 'trajectory' );
		$dry_run = \WP_CLI\Utils\get_flag_value( $assoc_args, 'dry-run', false );
		$format  = $this->get_format( $assoc_args, 'table' );

		if ( ! isset( self::CORPUS_TOOLS[ $corpus ] ) ) {
			/* translators: 1: requested corpus, 2: valid corpus list */
			WP_CLI::error( sprintf( __( 'Unknown corpus "%1$s". Valid options: %2$s.', 'mcp-ai-wpoos-pro' ), $corpus, implode( ', ', array_keys( self::CORPUS_TOOLS ) ) ) );
		}

		$tool = WP_MCP_AI_Tool_Registry::instance()->get_tool( self::CORPUS_TOOLS[ $corpus ] );
		if ( ! $tool ) {
			WP_CLI::error( __( 'The Model Foundry module is not active. Enable the model_foundry Pro module first.', 'mcp-ai-wpoos-pro' ) );
		}

		$arguments = array( 'assistant_id' => $assistant_id );
		if ( $dry_run ) {
			$arguments['dry_run'] = true;
		}

		$result = $tool->execute( $arguments, array( 'user_id' => get_current_user_id() ) );
		if ( is_wp_error( $result ) ) {
			WP_CLI::error( $result->get_error_message() );
		}

		$this->render_envelope( $result, $format );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai foundry', 'WP_MCP_AI_Pro_CLI_Foundry_Command' );
}
