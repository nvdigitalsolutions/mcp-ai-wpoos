<?php
/**
 * WP-CLI commands for workflow DSL export/import (Proposal 091, Track A).
 *
 * Commands:
 *   wp mcp-ai workflow export <id|slug> [--format=json|yaml] [--include-credentials]
 *   wp mcp-ai workflow export --preset=<slug> [--format=json|yaml]
 *   wp mcp-ai workflow import <file> [--dry-run] [--source=native|dify]
 *   wp mcp-ai workflow validate <file>
 *
 * @package WP_MCP_AI
 * @since   1.2.13
 * @author  NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Workflow DSL CLI command.
 *
 * @since 1.2.13
 */
class WP_MCP_AI_CLI_Workflow_Command extends WP_CLI_Command {

	/**
	 * Export a saved workflow or a bundled preset as DSL v1.
	 *
	 * ## OPTIONS
	 *
	 * [<workflow>]
	 * : Workflow post ID or slug (omit when using --preset).
	 *
	 * [--preset=<slug>]
	 * : Export a bundled preset by slug instead of a saved workflow.
	 *
	 * [--format=<format>]
	 * : Output format: json (default) or yaml (cosmetic wrapper).
	 * ---
	 * default: json
	 * options:
	 *   - json
	 *   - yaml
	 * ---
	 *
	 * [--include-credentials]
	 * : Include credential-bearing node params (default: redacted).
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai workflow export 42
	 *     wp mcp-ai workflow export --preset=fnb_weekend_prep
	 *
	 * @since 1.2.13
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function export( $args, $assoc_args ) {
		$preset_slug = isset( $assoc_args['preset'] ) ? sanitize_key( $assoc_args['preset'] ) : '';

		if ( '' !== $preset_slug ) {
			$workflow = apply_filters( 'wp_mcp_ai_workflow_preset_export', null, $preset_slug );
			if ( null === $workflow || ! is_array( $workflow ) ) {
				WP_CLI::error( __( 'Preset not found.', 'mcp-ai-wpoos' ) );
			}
		} else {
			$id = absint( isset( $args[0] ) ? $args[0] : 0 );
			if ( 0 === $id ) {
				WP_CLI::error( __( 'Provide a workflow ID/slug or --preset=<slug>.', 'mcp-ai-wpoos' ) );
			}

			$post = get_post( $id );
			if ( ! $post || WP_MCP_AI_Workflow_CPT::CPT !== $post->post_type ) {
				WP_CLI::error( __( 'Workflow not found.', 'mcp-ai-wpoos' ) );
			}

			$workflow = WP_MCP_AI_Workflow_CPT::export_json( $id );
		}

		$options = array(
			'include_credentials' => isset( $assoc_args['include-credentials'] ),
		);
		$dsl     = WP_MCP_AI_Workflow_DSL_Codec::encode_workflow( $workflow, $options );

		if ( is_wp_error( $dsl ) ) {
			$data = $dsl->get_error_data();
			WP_CLI::error( self::format_findings( $data['findings'] ) );
		}

		$format = isset( $assoc_args['format'] ) ? $assoc_args['format'] : 'json';
		if ( 'yaml' === $format ) {
			WP_CLI::log( self::json_to_yaml( $dsl ) );
			return;
		}

		WP_CLI::log( wp_json_encode( $dsl, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ) );
	}

	/**
	 * Import a DSL file (or legacy JSON export) into a workflow.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the DSL file.
	 *
	 * [--dry-run]
	 * : Validate only — print the findings and canonical preview.
	 *
	 * [--source=<source>]
	 * : Import source: native (default) or dify.
	 * ---
	 * default: native
	 * options:
	 *   - native
	 *   - dify
	 * ---
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai workflow import ./my-workflow.json --dry-run
	 *     wp mcp-ai workflow import ./dify-app.yaml --source=dify
	 *
	 * @since 1.2.13
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function import( $args, $assoc_args ) {
		if ( empty( $args[0] ) || ! file_exists( $args[0] ) ) {
			WP_CLI::error( __( 'File not found.', 'mcp-ai-wpoos' ) );
		}

		$raw = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI file read.
		if ( false === $raw ) {
			WP_CLI::error( __( 'Could not read file.', 'mcp-ai-wpoos' ) );
		}

		$payload = json_decode( $raw, true );
		if ( ! is_array( $payload ) ) {
			WP_CLI::error( __( 'File is not valid JSON.', 'mcp-ai-wpoos' ) );
		}

		$source = isset( $assoc_args['source'] ) ? $assoc_args['source'] : 'native';
		if ( 'dify' === $source ) {
			$adapted = apply_filters( 'wp_mcp_ai_workflow_dify_import', null, $payload );
			if ( null === $adapted || ! is_array( $adapted ) ) {
				WP_CLI::error( __( 'The Dify import adapter is not available on this site.', 'mcp-ai-wpoos' ) );
			}
			$payload = $adapted;
		}

		$dry_run = isset( $assoc_args['dry-run'] );
		$decoded = WP_MCP_AI_Workflow_DSL_Codec::decode_workflow( $payload, $dry_run );

		if ( is_wp_error( $decoded ) ) {
			WP_CLI::error( self::format_findings( $decoded->get_error_data()['findings'] ) );
		}

		$findings = $decoded['findings'];
		WP_CLI::log( self::format_findings( $findings ) );

		if ( $dry_run ) {
			WP_CLI::success( __( 'Dry run: no workflow was written.', 'mcp-ai-wpoos' ) );
			return;
		}

		unset( $decoded['findings'] );
		$post_id = WP_MCP_AI_Workflow_CPT::import_json( $decoded );

		if ( is_wp_error( $post_id ) ) {
			WP_CLI::error( $post_id->get_error_message() );
		}

		WP_CLI::success(
			sprintf(
				/* translators: %d: new workflow post ID. */
				__( 'Workflow imported as post %d.', 'mcp-ai-wpoos' ),
				$post_id
			)
		);
	}

	/**
	 * Validate a DSL file and print the findings.
	 *
	 * ## OPTIONS
	 *
	 * <file>
	 * : Path to the DSL file.
	 *
	 * ## EXAMPLES
	 *
	 *     wp mcp-ai workflow validate ./my-workflow.json
	 *
	 * @since 1.2.13
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Associative args.
	 * @return void
	 */
	public function validate( $args, $assoc_args ) {
		if ( empty( $args[0] ) || ! file_exists( $args[0] ) ) {
			WP_CLI::error( __( 'File not found.', 'mcp-ai-wpoos' ) );
		}

		$raw     = file_get_contents( $args[0] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- CLI file read.
		$payload = $raw ? json_decode( $raw, true ) : null;

		if ( ! is_array( $payload ) ) {
			WP_CLI::error( __( 'File is not valid JSON.', 'mcp-ai-wpoos' ) );
		}

		$findings = WP_MCP_AI_Workflow_DSL_Codec::validate_workflow( $payload );
		WP_CLI::log( self::format_findings( $findings ) );

		$has_blockers = false;
		foreach ( $findings as $finding ) {
			if ( 'error' === $finding['severity'] ) {
				$has_blockers = true;
			}
		}

		if ( $has_blockers ) {
			WP_CLI::warning( __( 'Validation failed: errors block import.', 'mcp-ai-wpoos' ) );
			return;
		}

		WP_CLI::success( __( 'Validation passed.', 'mcp-ai-wpoos' ) );
	}

	/**
	 * Render a findings list as readable lines.
	 *
	 * @since 1.2.13
	 *
	 * @param array[] $findings Findings list.
	 * @return string
	 */
	private static function format_findings( array $findings ) {
		if ( empty( $findings ) ) {
			return __( 'Findings: none.', 'mcp-ai-wpoos' );
		}

		$lines = array( __( 'Findings:', 'mcp-ai-wpoos' ) );
		foreach ( $findings as $finding ) {
			$lines[] = sprintf(
				'  [%s] %s — %s',
				strtoupper( $finding['severity'] ),
				$finding['path'],
				$finding['message']
			);
		}

		return implode( PHP_EOL, $lines );
	}

	/**
	 * Minimal cosmetic JSON→YAML renderer for the DSL envelope.
	 *
	 * Best-effort: flat keys only — nested structures are inlined as compact
	 * JSON. The canonical wire format remains JSON (decision D1).
	 *
	 * @since 1.2.13
	 *
	 * @param array $dsl DSL envelope.
	 * @return string
	 */
	private static function json_to_yaml( array $dsl ) {
		$lines = array( '---' );
		foreach ( $dsl as $key => $value ) {
			$lines[] = $key . ': ' . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) );
		}

		return implode( PHP_EOL, $lines );
	}
}

if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai workflow', 'WP_MCP_AI_CLI_Workflow_Command' );
}
