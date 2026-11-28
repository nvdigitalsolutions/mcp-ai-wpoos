<?php
/**
 * Row and descriptor redaction.
 *
 * Proposal 167 section 167.6.6. Two jobs, both "do not let a value out that the
 * scope did not grant":
 *
 *  1. **Column masking** — delegate to the kernel {@see ColumnMasker}, which knows
 *     the scope's `sensitive` and `exclude` rules and whether the scope holds
 *     `read_unmasked`. This class does not re-implement that logic; a second
 *     implementation is a second place to get it wrong.
 *  2. **Descriptor redaction** — the summary returned by `db_list_connections`
 *     must not carry a credential handle, a replica handle or a CA path.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\ColumnMasker;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use Nvoos\Database\Domain\Contract\Database\RedactorInterface;

/**
 * Masking bridge.
 */
final class WP_MCP_AI_Db_Redactor implements RedactorInterface {

	/**
	 * Masker.
	 *
	 * @var ColumnMasker
	 */
	private ColumnMasker $masker;

	/**
	 * Constructor.
	 *
	 * @param ColumnMasker|null $masker Masker; a default one is created when omitted.
	 */
	public function __construct( ?ColumnMasker $masker = null ) {
		$this->masker = $masker ?? new ColumnMasker();
	}

	/**
	 * Mask one row.
	 *
	 * @param array<string, mixed> $row     Row.
	 * @param TableDescriptor      $table   Relation.
	 * @param ScopeDescriptor      $scope   Scope.
	 * @param string               $relation `schema.table` label used in the masked list.
	 * @return array{row: array<string, mixed>, masked: list<string>}
	 */
	public function mask_row( array $row, TableDescriptor $table, ScopeDescriptor $scope, string $relation ): array {
		return $this->masker->maskRow( $row, $table, $scope, $relation );
	}

	/**
	 * Redacted connection summary.
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 * @return array<string, mixed>
	 */
	public function connection_summary( ConnectionDescriptor $connection ): array {
		$summary = $connection->toSummary();

		// Defence in depth: these keys should never be present, but a future
		// change to `toSummary()` must not be able to leak them through here.
		unset( $summary['credential'], $summary['read_replica'], $summary['sslrootcert'], $summary['host'], $summary['port'], $summary['database'] );

		return $summary;
	}

	/**
	 * Scrub engine text before it reaches a log line.
	 *
	 * A driver message can echo a statement or a row value, so only the shape is
	 * retained: SQLSTATE/driver numbers, and nothing else.
	 *
	 * @param string $detail Raw detail.
	 */
	public function scrub_detail( string $detail ): string {
		$detail = preg_replace( '/[^a-zA-Z0-9_=:.\- ]+/', '', $detail ) ?? '';

		return substr( trim( $detail ), 0, 200 );
	}
}
