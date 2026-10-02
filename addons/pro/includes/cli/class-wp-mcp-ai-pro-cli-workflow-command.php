<?php
/**
 * WP-CLI workflow management commands for NV oOS Pro.
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
 * Manage NV oOS Pro Workflow Builder definitions from the command line.
 *
 * Workflow definitions are persisted by the Pro Workflow Builder page in the
 * `wp_mcp_ai_pro_workflows` option, keyed by workflow ID (a slug derived from
 * the workflow name). Each record stores `name`, `description`, `nodes`,
 * `edges`, `created_at` and `updated_at`. There is no public execution entry
 * point for these definitions (the schedule manager executes them internally
 * via `workflow_builder` schedules), so this command is read-only.
 *
 * @since 1.3.0
 */
class WP_MCP_AI_Pro_CLI_Workflow_Command extends WP_MCP_AI_Pro_CLI_Base_Command {

	/**
	 * Option key holding Pro Workflow Builder definitions.
	 *
	 * @var string
	 */
	const WORKFLOWS_OPTION = 'wp_mcp_ai_pro_workflows';

	/**
	 * Load all workflow definitions, keyed by workflow ID.
	 *
	 * @return array
	 */
	private function get_all_workflows() {
		$workflows = get_option( self::WORKFLOWS_OPTION, array() );
		return is_array( $workflows ) ? $workflows : array();
	}

	/**
	 * List all Pro Workflow Builder workflows.
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
	 *   - ids
	 * ---
	 *
	 * [--fields=<fields>]
	 * : Comma-separated list of columns to display.
	 *
	 * ## EXAMPLES
	 *
	 *     # List all workflows.
	 *     $ wp mcp-ai workflow list
	 *
	 *     # Export workflow IDs.
	 *     $ wp mcp-ai workflow list --format=ids
	 *
	 * @subcommand list
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function list( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_cron_orchestration', 'Cron-Based Task Orchestration' );

		$format = $this->get_format( $assoc_args, 'table' );
		$fields = $this->get_fields(
			$assoc_args,
			array( 'id', 'name', 'nodes', 'edges', 'updated_at' )
		);

		$workflows = $this->get_all_workflows();

		if ( empty( $workflows ) ) {
			WP_CLI::log( __( 'No workflows found.', 'mcp-ai-wpoos-pro' ) );
			return;
		}

		if ( 'ids' === $format ) {
			WP_CLI::line( implode( ' ', array_keys( $workflows ) ) );
			return;
		}

		$items = array();
		foreach ( $workflows as $workflow_id => $workflow ) {
			$nodes = isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : array();
			$edges = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

			$items[] = array(
				'id'          => (string) $workflow_id,
				'name'        => isset( $workflow['name'] ) ? (string) $workflow['name'] : (string) $workflow_id,
				'description' => isset( $workflow['description'] ) ? (string) $workflow['description'] : '',
				'nodes'       => count( $nodes ),
				'edges'       => count( $edges ),
				'created_at'  => isset( $workflow['created_at'] ) ? wp_date( DATE_ATOM, (int) $workflow['created_at'] ) : '',
				'updated_at'  => isset( $workflow['updated_at'] ) ? wp_date( DATE_ATOM, (int) $workflow['updated_at'] ) : '',
			);
		}

		\WP_CLI\Utils\format_items( $format, $items, $fields );
	}

	/**
	 * Get details for a single workflow.
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The workflow ID.
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
	 *     # Get workflow details.
	 *     $ wp mcp-ai workflow get my-funnel
	 *
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function get( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_cron_orchestration', 'Cron-Based Task Orchestration' );

		$workflow_id = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';
		$format      = $this->get_format( $assoc_args, 'table' );

		if ( '' === $workflow_id ) {
			WP_CLI::error( __( 'Please provide a workflow ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			/* translators: %s: workflow ID */
			WP_CLI::error( sprintf( __( 'Workflow "%s" not found.', 'mcp-ai-wpoos-pro' ), $workflow_id ) );
		}

		$workflow = $workflows[ $workflow_id ];

		$data = array(
			'id'          => $workflow_id,
			'name'        => isset( $workflow['name'] ) ? (string) $workflow['name'] : $workflow_id,
			'description' => isset( $workflow['description'] ) ? (string) $workflow['description'] : '',
			'nodes'       => isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : array(),
			'edges'       => isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array(),
			'created_at'  => isset( $workflow['created_at'] ) ? wp_date( DATE_ATOM, (int) $workflow['created_at'] ) : '',
			'updated_at'  => isset( $workflow['updated_at'] ) ? wp_date( DATE_ATOM, (int) $workflow['updated_at'] ) : '',
		);

		if ( 'json' === $format ) {
			WP_CLI::line( wp_json_encode( $data, JSON_PRETTY_PRINT ) );
			return;
		}

		if ( 'yaml' === $format ) {
			foreach ( $data as $key => $value ) {
				WP_CLI::line( "{$key}: " . ( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ) );
			}
			return;
		}

		$items = array();
		foreach ( $data as $key => $value ) {
			$items[] = array(
				'field' => $key,
				'value' => is_scalar( $value ) ? (string) $value : wp_json_encode( $value ),
			);
		}
		\WP_CLI\Utils\format_items( 'table', $items, array( 'field', 'value' ) );
	}

	/**
	 * Validate a workflow's structure.
	 *
	 * Read-only structural check: the nodes/edges arrays must be present and
	 * well-formed, edge endpoints must resolve to existing nodes, and the graph
	 * must be acyclic (matching the execution order the schedule manager
	 * computes before running a `workflow_builder` schedule).
	 *
	 * ## OPTIONS
	 *
	 * <id>
	 * : The workflow ID.
	 *
	 * ## EXAMPLES
	 *
	 *     # Validate a workflow before scheduling it.
	 *     $ wp mcp-ai workflow validate my-funnel
	 *
	 * @subcommand validate
	 * @param array $args       Positional arguments.
	 * @param array $assoc_args Associative arguments.
	 * @when after_wp_load
	 */
	public function validate( $args, $assoc_args ) {
		$this->assert_pro_loaded();
		$this->assert_toolkit_enabled( 'enable_cron_orchestration', 'Cron-Based Task Orchestration' );

		$workflow_id = isset( $args[0] ) ? sanitize_key( (string) $args[0] ) : '';

		if ( '' === $workflow_id ) {
			WP_CLI::error( __( 'Please provide a workflow ID.', 'mcp-ai-wpoos-pro' ) );
		}

		$workflows = $this->get_all_workflows();

		if ( ! isset( $workflows[ $workflow_id ] ) ) {
			/* translators: %s: workflow ID */
			WP_CLI::error( sprintf( __( 'Workflow "%s" not found.', 'mcp-ai-wpoos-pro' ), $workflow_id ) );
		}

		$workflow = $workflows[ $workflow_id ];
		$nodes    = isset( $workflow['nodes'] ) && is_array( $workflow['nodes'] ) ? $workflow['nodes'] : array();
		$edges    = isset( $workflow['edges'] ) && is_array( $workflow['edges'] ) ? $workflow['edges'] : array();

		$issues = array();

		if ( ! isset( $workflow['nodes'] ) || ! is_array( $workflow['nodes'] ) ) {
			$issues[] = __( 'The "nodes" array is missing or invalid.', 'mcp-ai-wpoos-pro' );
		} elseif ( empty( $workflow['nodes'] ) ) {
			$issues[] = __( 'The workflow has no nodes.', 'mcp-ai-wpoos-pro' );
		}

		if ( ! isset( $workflow['edges'] ) || ! is_array( $workflow['edges'] ) ) {
			$issues[] = __( 'The "edges" array is missing or invalid.', 'mcp-ai-wpoos-pro' );
		}

		$node_ids = array();
		foreach ( $nodes as $index => $node ) {
			if ( ! is_array( $node ) || ! isset( $node['id'] ) || '' === (string) $node['id'] ) {
				/* translators: %d: zero-based node index */
				$issues[] = sprintf( __( 'Node at index %d has no ID.', 'mcp-ai-wpoos-pro' ), (int) $index );
				continue;
			}
			$nid = (string) $node['id'];
			if ( isset( $node_ids[ $nid ] ) ) {
				/* translators: %s: node ID */
				$issues[] = sprintf( __( 'Duplicate node ID "%s".', 'mcp-ai-wpoos-pro' ), $nid );
			}
			$node_ids[ $nid ] = true;
		}

		$adjacency   = array_fill_keys( array_keys( $node_ids ), array() );
		$incoming    = array_fill_keys( array_keys( $node_ids ), 0 );
		$edge_count  = 0;
		$check_cycle = empty( $issues );

		foreach ( $edges as $index => $edge ) {
			if ( ! is_array( $edge ) ) {
				/* translators: %d: zero-based edge index */
				$issues[] = sprintf( __( 'Edge at index %d is invalid.', 'mcp-ai-wpoos-pro' ), (int) $index );
				continue;
			}

			$source = isset( $edge['source'] ) ? (string) $edge['source'] : '';
			$target = isset( $edge['target'] ) ? (string) $edge['target'] : '';

			if ( '' === $source || '' === $target ) {
				/* translators: %d: zero-based edge index */
				$issues[] = sprintf( __( 'Edge at index %d is missing a source or target.', 'mcp-ai-wpoos-pro' ), (int) $index );
				continue;
			}

			foreach ( array( $source, $target ) as $endpoint ) {
				if ( ! isset( $node_ids[ $endpoint ] ) ) {
					/* translators: 1: endpoint node ID, 2: zero-based edge index */
					$issues[] = sprintf( __( 'Edge at index %2$d references missing node "%1$s".', 'mcp-ai-wpoos-pro' ), $endpoint, (int) $index );
				}
			}

			if ( $check_cycle && isset( $adjacency[ $source ] ) && isset( $incoming[ $target ] ) ) {
				$adjacency[ $source ][] = $target;
				++$incoming[ $target ];
				++$edge_count;
			}
		}

		if ( $check_cycle && empty( $issues ) && ! empty( $node_ids ) ) {
			// Kahn's algorithm — mirrors the schedule manager's execution order.
			$queue = array();
			foreach ( $incoming as $nid => $count ) {
				if ( 0 === $count ) {
					$queue[] = $nid;
				}
			}

			$visited = 0;
			while ( ! empty( $queue ) ) {
				$current = array_shift( $queue );
				++$visited;
				foreach ( $adjacency[ $current ] as $neighbor ) {
					--$incoming[ $neighbor ];
					if ( 0 === $incoming[ $neighbor ] ) {
						$queue[] = $neighbor;
					}
				}
			}

			if ( $visited !== count( $node_ids ) ) {
				$issues[] = __( 'The workflow graph contains a cycle.', 'mcp-ai-wpoos-pro' );
			}
		}

		if ( ! empty( $issues ) ) {
			/* translators: %s: workflow ID */
			WP_CLI::warning( sprintf( __( 'Workflow "%s" is invalid:', 'mcp-ai-wpoos-pro' ), $workflow_id ) );
			foreach ( $issues as $issue ) {
				WP_CLI::log( '  - ' . $issue );
			}
			/* translators: %d: number of issues */
			WP_CLI::error( sprintf( _n( '%d issue found.', '%d issues found.', count( $issues ), 'mcp-ai-wpoos-pro' ), count( $issues ) ) );
		}

		/* translators: %s: workflow ID */
		WP_CLI::success( sprintf( __( 'Workflow "%s" is valid.', 'mcp-ai-wpoos-pro' ), $workflow_id ) );
	}
}

// Register command.
if ( class_exists( 'WP_CLI' ) ) {
	WP_CLI::add_command( 'mcp-ai workflow', 'WP_MCP_AI_Pro_CLI_Workflow_Command' );
}
