<?php
/**
 * `db_export` — a bounded CSV/JSON export to the uploads directory.
 *
 * Proposal 167 phase P7, task 7.1. Exports are a separate tier because the risk
 * profile is different: the data leaves the database and lands in a file whose
 * URL may be guessable. The mitigations are all visible here:
 *
 *  - the `export` tier is required;
 *  - the row cap is the *lower* of the requested limit and the scope cap;
 *  - masking still applies (the export is a select, not a side door);
 *  - the file gets a random suffix, and a SHA-256 checksum is returned so the
 *    caller can verify what it received;
 *  - the audit row records the export like any other read.
 *
 * A scheduled/async file-registry hand-off (Proposal 162) is the P7 follow-up;
 * this is the synchronous path that makes the tool useful today. Set `async` to
 * hand the read to a worker and pass the returned `job_id` back to this tool to
 * read it; the worker re-enters the tool, so the grant in force *then* is the one
 * that applies.
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

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;

/**
 * Exports a scoped select.
 */
final class WP_MCP_AI_Tool_Db_Export extends WP_MCP_AI_Database_Tool_Base {

	/**
	 * Maximum rows an export may return.
	 */
	private const MAX_ROWS = 10000;

	/**
	 * {@inheritDoc}
	 */
	public function get_slug() {
		return 'db_export';
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_name() {
		return __( 'Export Database Rows', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_description() {
		return __( 'Export a scoped select to a CSV or JSON file in the host file store. Requires the export capability on the scope; masking still applies and the row cap is enforced.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array_merge(
				$this->connection_properties(),
				array(
					'relation' => $this->relation_schema(),
					'columns'  => array(
						'type'     => 'array',
						'maxItems' => 40,
						'items'    => array( 'type' => 'string' ),
					),
					'filters'  => $this->filter_schema(),
					'order'    => $this->order_schema(),
					'limit'    => array(
						'type'        => 'integer',
						'minimum'     => 1,
						'maximum'     => self::MAX_ROWS,
						'description' => 'Rows to export. The scope cap wins when it is lower.',
					),
					'format'   => array(
						'type'    => 'string',
						'enum'    => array( 'csv', 'json' ),
						'default' => 'csv',
					),
					'async'    => array(
						'type'        => 'boolean',
						'default'     => false,
						'description' => 'Return a job id instead of waiting for the rows. Pass that job_id back to this tool to read the export once it finishes.',
					),
					'job_id'   => array(
						'type'        => 'string',
						'description' => 'Read a queued or finished export instead of starting one. Mutually exclusive with relation.',
					),
				)
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'requires-capability', 'read-only', 'external-api', 'network-dependent', 'pii-data', 'large-response' );
	}

	/**
	 * {@inheritDoc}
	 *
	 * @param array<string, mixed> $arguments Sanitised tool arguments.
	 * @param array<string, mixed> $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		return $this->attempt(
			function () use ( $arguments, $context ) {
				$job_id = isset( $arguments['job_id'] ) ? sanitize_text_field( wp_unslash( (string) $arguments['job_id'] ) ) : '';

				if ( '' !== $job_id ) {
					return $this->status( $job_id, $arguments, $context );
				}

				if ( '' === $this->relation( $arguments ) ) {
					return new WP_Error(
						'wp_mcp_ai_db_relation_required',
						__( 'Name a relation to export, or pass job_id to read a queued export.', 'mcp-ai-wpoos-pro' ),
						array( 'status' => 400 )
					);
				}

				$connection = $this->connection( $arguments, $context );
				$scope      = $this->scope( $arguments );

				$this->assert_tier( $connection, $scope, $context, GrantTier::EXPORT );

				if ( ! empty( $arguments['async'] ) ) {
					if ( ! class_exists( 'WP_MCP_AI_Db_Export_Job' ) ) {
						return new WP_Error(
							'wp_mcp_ai_db_async_unavailable',
							__( 'Background exports are not loaded on this site. Call the tool without async to export inline.', 'mcp-ai-wpoos-pro' ),
							array( 'status' => 501 )
						);
					}

					$job_id = WP_MCP_AI_Db_Export_Job::enqueue( $arguments, $context );

					if ( is_wp_error( $job_id ) ) {
						return $job_id;
					}

					return array(
						'success'        => true,
						'async'          => true,
						'job_id'         => $job_id,
						'status'         => 'queued',
						'poll_with'      => 'db_export',
						'poll_arguments' => array( 'job_id' => $job_id ),
						'connection'     => $connection,
						'relation'       => $this->relation( $arguments ),
						'format'         => 'json' === strtolower( (string) ( $arguments['format'] ?? 'csv' ) ) ? 'json' : 'csv',
						'note'           => __( 'The rows are read by a worker under the grant in force when it runs. Pass job_id back to this tool for the file, checksum and audit id.', 'mcp-ai-wpoos-pro' ),
					);
				}

				$query = $this->select_query( $arguments );

				$limit = isset( $arguments['limit'] ) ? min( self::MAX_ROWS, max( 1, absint( $arguments['limit'] ) ) ) : 1000;

				$query = new \Nvoos\Database\Domain\ValueObject\Database\SelectQuery(
					relation: $query->relation,
					columns: $query->columns,
					filters: $query->filters,
					order: $query->order,
					limit: $limit
				);

				$result = $this->executor( $context )->select( $connection, $scope, $query );

				$format  = 'json' === strtolower( (string) ( $arguments['format'] ?? 'csv' ) ) ? 'json' : 'csv';
				$payload = 'json' === $format
					? (string) wp_json_encode( $result->rows, JSON_PRETTY_PRINT )
					: $this->to_csv( $result->columns, $result->rows );

				if ( ! function_exists( 'wp_upload_bits' ) ) {
					require_once ABSPATH . 'wp-admin/includes/file.php';
				}

				$filename = sprintf(
					'database-export-%s-%s-%s.%s',
					preg_replace( '/[^a-z0-9_-]+/', '-', strtolower( $connection ) ),
					gmdate( 'Ymd-His' ),
					wp_generate_password( 8, false, false ),
					$format
				);

				$upload = wp_upload_bits( $filename, null, $payload );

				if ( ! empty( $upload['error'] ) ) {
					throw DatabaseRefusal::of( RefusalCode::RESULT_TOO_LARGE, 'Export file could not be written.' );
				}

				return array(
					'success'    => true,
					'connection' => $connection,
					'relation'   => $query->relation,
					'format'     => $format,
					'rows'       => $result->rowCount,
					'truncated'  => $result->truncated,
					'masked'     => $result->masked,
					'audit_id'   => $result->auditId,
					'file'       => (string) $upload['file'],
					'url'        => (string) $upload['url'],
					'bytes'      => strlen( $payload ),
					'checksum'   => 'sha256:' . hash( 'sha256', $payload ),
				);
			}
		);
	}

	/**
	 * Read a queued or finished export.
	 *
	 * The poll surface is this tool rather than a separate slug: the toolkit must
	 * work without the engine bridge (which owns the generic job-status tool), and
	 * a second slug would mean a fourteenth tool, a readiness row and a parity
	 * fixture for what is a follow-up call. `db_select` already works this way for
	 * its cursor.
	 *
	 * The tier is asserted first, so a revoked grant also revokes the ability to
	 * read the artefact. Another principal's job answers 404, not 403 — the same
	 * "not allowed and not found are identical" rule the relation allowlist uses.
	 *
	 * @param string               $job_id    Job id from the enqueue response.
	 * @param array<string, mixed> $arguments Tool arguments.
	 * @param array<string, mixed> $context   Execution context.
	 * @return array<string, mixed>|WP_Error
	 */
	private function status( string $job_id, array $arguments, array $context ) {
		$connection = $this->connection( $arguments, $context );
		$scope      = $this->scope( $arguments );

		$this->assert_tier( $connection, $scope, $context, GrantTier::EXPORT );

		$row = WP_MCP_AI_Job_Store::get( $job_id );

		if ( null === $row || WP_MCP_AI_Db_Export_Job::HOOK !== (string) ( $row['handler'] ?? '' ) ) {
			return new WP_Error(
				'wp_mcp_ai_db_export_not_found',
				__( 'That export job does not exist.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$owner = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( $owner > 0 && (int) ( $row['user_id'] ?? 0 ) > 0 && (int) $row['user_id'] !== $owner && ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'wp_mcp_ai_db_export_not_found',
				__( 'That export job does not exist.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$decoded = isset( $row['result'] ) && is_string( $row['result'] ) && '' !== $row['result']
			? json_decode( $row['result'], true )
			: null;

		return array(
			'success'  => true,
			'async'    => true,
			'job_id'   => $job_id,
			'status'   => (string) ( $row['status'] ?? '' ),
			'result'   => is_array( $decoded ) ? $decoded : null,
			'error'    => isset( $row['error'] ) && is_string( $row['error'] ) && '' !== $row['error'] ? $row['error'] : null,
			'attempts' => (int) ( $row['attempts'] ?? 0 ),
		);
	}

	/**
	 * Render rows as CSV.
	 *
	 * @param string[]                   $columns Column names.
	 * @param list<array<string, mixed>> $rows    Rows.
	 */
	private function to_csv( array $columns, array $rows ): string {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen -- `php://temp` is an in-memory stream, not a WP filesystem path; WP_Filesystem cannot address it, and fputcsv() is what makes the quoting correct.
		$handle = fopen( 'php://temp', 'r+' );

		if ( false === $handle ) {
			return '';
		}

		if ( array() !== $columns ) {
			fputcsv( $handle, $columns, ',', '"', '\\' );
		}

		foreach ( $rows as $row ) {
			$line = array();

			foreach ( $columns as $column ) {
				$value = $row[ $column ] ?? '';

				// A leading =, +, - or @ is a spreadsheet formula prefix. Prefixing
				// a single quote keeps Excel from executing exported content.
				if ( is_string( $value ) && '' !== $value && in_array( $value[0], array( '=', '+', '-', '@' ), true ) ) {
					$value = "'" . $value;
				}

				$line[] = is_bool( $value ) ? ( $value ? 'true' : 'false' ) : (string) $value;
			}

			fputcsv( $handle, $line, ',', '"', '\\' );
		}

		rewind( $handle );
		$csv = (string) stream_get_contents( $handle );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose -- Pairs with the php://temp handle above.
		fclose( $handle );

		return $csv;
	}
}
