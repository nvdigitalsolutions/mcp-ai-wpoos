<?php
/**
 * Schedule and workflow presets for the database toolkit.
 *
 * Proposal 167 phase P7, task 7.2. Two presets, both read-only:
 *
 *  - a weekly **refusal review** that turns the audit trail into a scope-tuning
 *    report, so a misconfigured grant is fixed by an operator rather than by an
 *    assistant retrying until something works;
 *  - a **profile-and-export** workflow that describes, profiles and exports one
 *    relation — the shape a scheduled report takes when the target is a database
 *    rather than a WordPress site.
 *
 * The workflow includes `edges`; the preset sanity gate fails a workflow whose
 * edges do not resolve to declared nodes.
 *
 * @package WP_MCP_AI_Pro
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Preset definitions.
 */
final class WP_MCP_AI_Database_Presets {

	/**
	 * Schedule presets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function schedule(): array {
		return array(
			'db_weekly_refusal_review' => array(
				'name'          => __( 'Weekly Database Refusal Review', 'mcp-ai-wpoos-pro' ),
				'description'   => __( 'Summarise the week\'s database refusals by code, scope and principal, and recommend scope edits. Never retries a refused operation.', 'mcp-ai-wpoos-pro' ),
				'toolkit'       => 'database',
				'category'      => 'reporting',
				'icon'          => 'dashicons-database-view',
				'schedule_type' => 'assistant_run',
				'schedule'      => 'weekly',
				'tags'          => array( 'database', 'weekly', 'audit', 'governance' ),
				'schedule_data' => array(
					'assistant_config' => array(
						'message' => 'Call db_audit_tail with limit 200. Group the records by refusal_code, then by scope and principal. Report each refusal with the statement template and a recommended scope edit. Do not call any other db_* tool, and never retry a refused statement.',
					),
				),
			),
		);
	}

	/**
	 * Workflow presets.
	 *
	 * @return array<string, array<string, mixed>>
	 */
	public static function workflows(): array {
		return array(
			'db_profile_and_export' => array(
				'name'        => __( 'Profile and Export a Table', 'mcp-ai-wpoos-pro' ),
				'description' => __( 'Describe one scoped relation, profile its columns, then export it to CSV. Refuses at the describe step when the relation is outside the scope.', 'mcp-ai-wpoos-pro' ),
				'category'    => 'data',
				'icon'        => 'dashicons-database-export',
				'tags'        => array( 'database', 'export', 'profile' ),
				'nodes'       => array(
					array(
						'id'       => 'node_1',
						'type'     => 'input',
						'position' => array(
							'x' => 250,
							'y' => 0,
						),
						'data'     => array(
							'label'       => __( 'Relation', 'mcp-ai-wpoos-pro' ),
							'description' => __( 'Schema-qualified relation inside the assistant scope, e.g. reporting.orders.', 'mcp-ai-wpoos-pro' ),
						),
					),
					array(
						'id'       => 'node_2',
						'type'     => 'tool',
						'position' => array(
							'x' => 250,
							'y' => 75,
						),
						'data'     => array(
							'label'       => __( 'Describe', 'mcp-ai-wpoos-pro' ),
							'toolSlug'    => 'db_describe',
							'arguments'   => array( 'relation' => '{{input.relation}}' ),
							'description' => __( 'Confirm the relation and its columns are inside the scope.', 'mcp-ai-wpoos-pro' ),
						),
					),
					array(
						'id'       => 'node_3',
						'type'     => 'tool',
						'position' => array(
							'x' => 250,
							'y' => 150,
						),
						'data'     => array(
							'label'       => __( 'Profile', 'mcp-ai-wpoos-pro' ),
							'toolSlug'    => 'db_profile_table',
							'arguments'   => array( 'relation' => '{{input.relation}}' ),
							'description' => __( 'Row count, nulls, distinct counts, min/max and top values per column.', 'mcp-ai-wpoos-pro' ),
						),
					),
					array(
						'id'       => 'node_4',
						'type'     => 'tool',
						'position' => array(
							'x' => 250,
							'y' => 225,
						),
						'data'     => array(
							'label'       => __( 'Export CSV', 'mcp-ai-wpoos-pro' ),
							'toolSlug'    => 'db_export',
							'arguments'   => array(
								'relation' => '{{input.relation}}',
								'format'   => 'csv',
								'limit'    => 1000,
							),
							'description' => __( 'Write a masked CSV to the uploads directory and return its checksum.', 'mcp-ai-wpoos-pro' ),
						),
					),
					array(
						'id'       => 'node_5',
						'type'     => 'output',
						'position' => array(
							'x' => 250,
							'y' => 300,
						),
						'data'     => array(
							'label'       => __( 'Report', 'mcp-ai-wpoos-pro' ),
							'description' => __( 'Return the profile summary and the export checksum.', 'mcp-ai-wpoos-pro' ),
						),
					),
				),
				'edges'       => array(
					array(
						'id'           => 'edge_1_2',
						'source'       => 'node_1',
						'target'       => 'node_2',
						'sourceHandle' => 'output',
					),
					array(
						'id'           => 'edge_2_3',
						'source'       => 'node_2',
						'target'       => 'node_3',
						'sourceHandle' => 'output',
					),
					array(
						'id'           => 'edge_3_4',
						'source'       => 'node_3',
						'target'       => 'node_4',
						'sourceHandle' => 'output',
					),
					array(
						'id'           => 'edge_4_5',
						'source'       => 'node_4',
						'target'       => 'node_5',
						'sourceHandle' => 'output',
					),
				),
			),
		);
	}
}
