<?php
/**
 * Run Pro Slash Command
 *
 * Run a saved Workflow Builder DAG by ID or name.
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Slash_Commands
 * @since 2.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run Command Class
 *
 * Args: $args[0] = workflow ID or name (required, unless --list)
 *
 * Flags:
 *   --list      List available saved workflows
 *   --dry-run   Show workflow without executing
 *   --json      JSON output
 *
 * @since 2.1.0
 */
class WP_MCP_AI_Pro_Slash_Command_Run {

	/**
	 * Execute run command.
	 *
	 * @param array $args    Positional arguments.
	 * @param array $flags   Command flags.
	 * @param array $context Execution context.
	 * @return string|array|WP_Error
	 */
	public function execute( $args, $flags, $context ) {
		// Block guest requests.
		if ( ! empty( $context['guest_request'] ) ) {
			return new WP_Error(
				'guest_forbidden',
				__( 'This command requires authentication.', 'mcp-ai-wpoos-pro' )
			);
		}

		$user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		$as_json = isset( $flags['json'] );
		$dry_run = isset( $flags['dry-run'] );

		// Require edit_posts.
		if ( ! user_can( $user_id, 'edit_posts' ) ) {
			return new WP_Error(
				'forbidden',
				__( 'Permission denied. Requires edit_posts capability.', 'mcp-ai-wpoos-pro' )
			);
		}

		$workflows = get_option( 'wp_mcp_ai_pro_workflows', array() );
		$workflows = is_array( $workflows ) ? $workflows : array();

		// --list: list available workflows.
		if ( isset( $flags['list'] ) || empty( $args[0] ) ) {
			return $this->list_workflows( $workflows, $as_json );
		}

		$query = sanitize_text_field( $args[0] );

		// Find workflow by ID (exact) or name (case-insensitive match).
		$workflow_id   = null;
		$workflow_data = null;

		if ( isset( $workflows[ $query ] ) ) {
			$workflow_id   = $query;
			$workflow_data = $workflows[ $query ];
		} else {
			$query_lower = strtolower( $query );
			foreach ( $workflows as $wid => $wdata ) {
				$wname = strtolower( isset( $wdata['name'] ) ? $wdata['name'] : '' );
				if ( strpos( $wname, $query_lower ) !== false ) {
					$workflow_id   = $wid;
					$workflow_data = $wdata;
					break;
				}
			}
		}

		if ( ! $workflow_id || ! $workflow_data ) {
			return new WP_Error(
				'not_found',
				sprintf(
					/* translators: %s: the search query */
					__( 'No workflow found matching "%s". Use --list to see available workflows.', 'mcp-ai-wpoos-pro' ),
					esc_html( $query )
				)
			);
		}

		$workflow_name = isset( $workflow_data['name'] ) ? $workflow_data['name'] : $workflow_id;
		$node_count    = isset( $workflow_data['nodes'] ) ? count( (array) $workflow_data['nodes'] ) : 0;
		$edge_count    = isset( $workflow_data['edges'] ) ? count( (array) $workflow_data['edges'] ) : 0;

		// --dry-run: show without executing.
		if ( $dry_run ) {
			if ( $as_json ) {
				return array(
					'success' => true,
					'message' => __( 'Dry run — workflow not executed.', 'mcp-ai-wpoos-pro' ),
					'data'    => array(
						'workflow_id'   => $workflow_id,
						'workflow_name' => $workflow_name,
						'node_count'    => $node_count,
						'edge_count'    => $edge_count,
						'dry_run'       => true,
					),
				);
			}

			$output  = '## ' . __( 'Dry Run — Workflow Preview', 'mcp-ai-wpoos-pro' ) . "\n\n";
			$output .= '- **Name:** ' . esc_html( $workflow_name ) . "\n";
			$output .= '- **ID:** ' . esc_html( $workflow_id ) . "\n";
			$output .= "- **Nodes:** {$node_count}\n";
			$output .= "- **Edges:** {$edge_count}\n\n";
			$output .= "_Run without `--dry-run` to execute._\n";

			return $output;
		}

		// Execute: fire the Pro workflow action hook.
		do_action( 'wp_mcp_ai_run_workflow_builder', $workflow_id, $workflow_data, $context );

		$success_message = sprintf(
			/* translators: %s: workflow name */
			__( 'Workflow "%s" queued for execution.', 'mcp-ai-wpoos-pro' ),
			$workflow_name
		);

		if ( $as_json ) {
			return array(
				'success'     => true,
				'action'      => 'run_workflow',
				'workflow_id' => $workflow_id,
				'message'     => $success_message,
			);
		}

		return '✅ ' . esc_html( $success_message );
	}

	/**
	 * List saved workflows.
	 *
	 * @param array $workflows Workflows from options.
	 * @param bool  $as_json   JSON output.
	 * @return string|array
	 */
	private function list_workflows( $workflows, $as_json ) {
		if ( empty( $workflows ) ) {
			if ( $as_json ) {
				return array(
					'success' => true,
					'message' => __( 'No saved workflows found.', 'mcp-ai-wpoos-pro' ),
					'data'    => array(),
				);
			}

			return __( 'No saved workflows found. Build one in the Workflow Builder.', 'mcp-ai-wpoos-pro' );
		}

		if ( $as_json ) {
			return array(
				'success' => true,
				'message' => __( 'Workflows retrieved.', 'mcp-ai-wpoos-pro' ),
				'data'    => $workflows,
			);
		}

		$output  = '## ' . __( 'Saved Workflows', 'mcp-ai-wpoos-pro' ) . "\n\n";
		$output .= "| ID | Name | Nodes | Edges | Updated |\n";
		$output .= "|----|------|-------|-------|---------|\n";

		foreach ( $workflows as $wid => $wdata ) {
			$name    = isset( $wdata['name'] ) ? esc_html( $wdata['name'] ) : '–';
			$nodes   = isset( $wdata['nodes'] ) ? count( (array) $wdata['nodes'] ) : 0;
			$edges   = isset( $wdata['edges'] ) ? count( (array) $wdata['edges'] ) : 0;
			$updated = isset( $wdata['updated_at'] ) ? esc_html( $wdata['updated_at'] ) : '–';
			$output .= '| ' . esc_html( $wid ) . " | {$name} | {$nodes} | {$edges} | {$updated} |\n";
		}

		$output .= "\n_Usage: `/run <workflow-id-or-name>` — Use `--dry-run` to preview._\n";

		return $output;
	}
}
