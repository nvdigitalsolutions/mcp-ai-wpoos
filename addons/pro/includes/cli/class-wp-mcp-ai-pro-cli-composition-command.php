<?php
/**
 * WP-CLI composition command — effective-composition dump and generation
 * verification (Proposal 029, Phase 5.3).
 *
 * The --dump-config equivalent for the plugin's 1,500-tool assistant
 * configs: resolves an assistant's tools after restriction intersection,
 * prompt sections, guards, and provider route, and prints the whole
 * effective composition with its deterministic generation id.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage CLI
 * @since   1.1.57
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

/**
 * Pro composition WP-CLI command.
 *
 * @since 1.1.57
 */
class WP_MCP_AI_Pro_CLI_Composition_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Require the composition classes on demand.
	 *
	 * @return WP_MCP_AI_Pro_Composition_Service
	 */
	private function service() {
		$dir = WP_MCP_AI_PRO_PATH . 'includes/composition/';

		require_once $dir . 'class-wp-mcp-ai-pro-composition.php';
		require_once $dir . 'class-wp-mcp-ai-pro-legacy-tool-resolver.php';
		require_once $dir . 'class-wp-mcp-ai-pro-composition-service.php';

		return new WP_MCP_AI_Pro_Composition_Service();
	}

	/**
	 * Resolve an assistant post ID argument into an int or error.
	 *
	 * @param array $args Positional args.
	 * @return int|false
	 */
	private function assistant_id_from_args( array $args ) {
		if ( empty( $args ) ) {
			WP_CLI::error( 'Provide an assistant ID: wp mcp-ai composition dump <assistant_id>.' );
		}

		$id = absint( $args[0] );

		if ( $id < 1 || 'mcp_ai_assistant' !== get_post_type( $id ) ) {
			WP_CLI::error( sprintf( 'Post %d is not an mcp_ai_assistant.', $id ) );
		}

		return $id;
	}

	/**
	 * Dump an assistant's effective composition.
	 *
	 * ## OPTIONS
	 *
	 * <assistant_id>
	 * : Assistant post ID to dump.
	 *
	 * [--json]
	 * : Output the effective composition as raw JSON.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public function dump( $args, $assoc_args ) {
		$id          = $this->assistant_id_from_args( $args );
		$composition = $this->service()->compose( $id );
		$effective   = $composition->to_array();

		if ( isset( $assoc_args['json'] ) ) {
			WP_CLI::line( (string) wp_json_encode( $effective ) );
			return;
		}

		WP_CLI::line( sprintf( 'Assistant %d — %s', $id, get_the_title( $id ) ) );
		WP_CLI::line( sprintf( '  generation_id: %s', $effective['generation_id'] ) );
		WP_CLI::line( sprintf( '  source:        %s', $effective['source'] ) );
		WP_CLI::line( sprintf( '  provider:      %s', '' !== $effective['provider'] ? $effective['provider'] : '(site default)' ) );
		WP_CLI::line( sprintf( '  model:         %s', '' !== $effective['model'] ? $effective['model'] : '(provider default)' ) );
		WP_CLI::line( sprintf( '  visible tools: %d', $effective['visible_tool_count'] ) );
		WP_CLI::line( sprintf( '  allow list:    %d slug(s)', count( $effective['allow_slugs'] ) ) );
		WP_CLI::line( sprintf( '  deny list:     %d slug(s)', count( $effective['deny_slugs'] ) ) );
		WP_CLI::line( sprintf( '  guards:        %d slug(s)', count( $effective['guard_slugs'] ) ) );
		WP_CLI::line( sprintf( '  prompt parts:  %d section(s)', count( $effective['prompt_sections'] ) ) );
		WP_CLI::line( '  visible slugs: ' . implode( ', ', $effective['visible_slugs'] ) );
	}

	/**
	 * Verify a recorded generation against the current composition.
	 *
	 * ## OPTIONS
	 *
	 * <assistant_id>
	 * : Assistant post ID to verify.
	 *
	 * [--against=<generation_id>]
	 * : Generation id recorded against a history. When omitted, prints the
	 * current generation id instead.
	 *
	 * @param array $args       Positional args.
	 * @param array $assoc_args Assoc args.
	 * @return void
	 */
	public function verify( $args, $assoc_args ) {
		$id          = $this->assistant_id_from_args( $args );
		$composition = $this->service()->compose( $id );

		if ( empty( $assoc_args['against'] ) ) {
			WP_CLI::line( $composition->generation_id() );
			return;
		}

		$same = $this->service()->assert_same_generation( $composition, (string) $assoc_args['against'] );

		if ( $same ) {
			WP_CLI::success( sprintf( 'History generation %s matches the current composition.', (string) $assoc_args['against'] ) );
		} else {
			WP_CLI::error( sprintf( 'History generation %s drifted from the current composition %s — tools changed since the history was produced.', (string) $assoc_args['against'], $composition->generation_id() ) );
		}
	}
}

WP_CLI::add_command( 'mcp-ai composition', 'WP_MCP_AI_Pro_CLI_Composition_Command' );
