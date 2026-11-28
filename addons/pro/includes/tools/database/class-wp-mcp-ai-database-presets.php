<?php
/**
 * Schedule and workflow presets for the database toolkit.
 *
 * Proposal 167 phase P7, task 7.2. Every preset is read-only:
 *
 *  - a weekly **refusal review** that turns the audit trail into a scope-tuning
 *    report, so a misconfigured grant is fixed by an operator rather than by an
 *    assistant retrying until something works;
 *  - a daily **connection health** sweep that probes each visible connection and
 *    reports role capabilities, so a widened grant is noticed the day it lands;
 *  - a weekly **scope census** that describes the relations in scope and flags
 *    the ones without a primary key;
 *  - a monthly **data-quality profile** that profiles up to five relations and
 *    reports null-heavy and low-cardinality columns;
 *  - a monthly **export digest** that writes the approved CSV and reports its
 *    checksum;
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
			'db_weekly_refusal_review'        => array(
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
			'db_daily_connection_health'      => array(
				'name'          => __( 'Daily Connection Health Sweep', 'mcp-ai-wpoos-pro' ),
				'description'   => __( 'Probe every visible connection and report its role capabilities, flagging any connection that reports can_write or bypasses_rls. Never retries a failed probe.', 'mcp-ai-wpoos-pro' ),
				'toolkit'       => 'database',
				'category'      => 'monitoring',
				'icon'          => 'dashicons-heart',
				'schedule_type' => 'assistant_run',
				'schedule'      => 'daily',
				'tags'          => array( 'database', 'daily', 'health', 'governance' ),
				'schedule_data' => array(
					'assistant_config' => array(
						'message' => 'Call db_list_connections, then call db_test_connection once per queryable connection. Report reachable, server_version, role, can_write and bypasses_rls for each connection, and flag any connection that reports can_write true or bypasses_rls true. Do not retry a failed probe and do not attempt any write.',
					),
				),
			),
			'db_weekly_scope_census'          => array(
				'name'          => __( 'Weekly Scope Census', 'mcp-ai-wpoos-pro' ),
				'description'   => __( 'Describe the relations in scope, report relation, view and column counts per schema, and flag tables and views without a primary key.', 'mcp-ai-wpoos-pro' ),
				'toolkit'       => 'database',
				'category'      => 'maintenance',
				'icon'          => 'dashicons-list-view',
				'schedule_type' => 'assistant_run',
				'schedule'      => 'weekly',
				'tags'          => array( 'database', 'weekly', 'census', 'schema' ),
				'schedule_data' => array(
					'assistant_config' => array(
						'message' => 'Call db_describe with no relation argument. Report relation, view and column counts per schema, then list every table and view without a primary key. Flag any relation outside the expected reporting schema. When the result reports truncated true, say the census covers the first 60 relations only. Do not call any other db_* tool.',
					),
				),
			),
			'db_monthly_data_quality_profile' => array(
				'name'          => __( 'Monthly Data-Quality Profile', 'mcp-ai-wpoos-pro' ),
				'description'   => __( 'Profile up to five reporting relations and report null-heavy columns, distinct counts and the most frequent values, with skipped sensitive columns named.', 'mcp-ai-wpoos-pro' ),
				'toolkit'       => 'database',
				'category'      => 'reporting',
				'icon'          => 'dashicons-chart-area',
				'schedule_type' => 'assistant_run',
				'schedule'      => 'monthly',
				'tags'          => array( 'database', 'monthly', 'profile', 'quality' ),
				'schedule_data' => array(
					'assistant_config' => array(
						'message' => 'For up to five relations in the reporting schema, call db_profile_table. Report row_count, every column with more than 20 percent nulls, its distinct count, and the five most frequent values. List the columns the tool skipped as sensitive. Do not call db_select against individual rows.',
					),
				),
			),
			'db_monthly_export_digest'        => array(
				'name'          => __( 'Monthly Export Digest', 'mcp-ai-wpoos-pro' ),
				'description'   => __( 'Export the approved reporting relation to CSV and report rows, bytes, masked columns and the sha256 checksum. Never emails or publishes the file.', 'mcp-ai-wpoos-pro' ),
				'toolkit'       => 'database',
				'category'      => 'business',
				'icon'          => 'dashicons-media-spreadsheet',
				'schedule_type' => 'assistant_run',
				'schedule'      => 'monthly',
				'tags'          => array( 'database', 'monthly', 'export', 'finance' ),
				'schedule_data' => array(
					'assistant_config' => array(
						'message' => 'Call db_export with format csv for the approved reporting relation, using the scope row limit. Report rows, bytes, the masked column count and the sha256 checksum. Leave the file where the tool stored it; do not email or publish it. When the result reports truncated true, report the truncation instead of exporting again.',
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
