<?php
/**
 * Append-only audit trail for every database call.
 *
 * Proposal 167 section 167.6.8. One record per call — **including refusals** — so
 * an operator can answer "what has this assistant asked the warehouse for?" and
 * "what did we refuse, and why?" without enabling debug logging.
 *
 * ## What is deliberately not recorded
 *
 * No bound values, ever. The record holds the compiled statement's **template**
 * (which by construction contains only `:pN` placeholders) and a fingerprint of
 * it. That is enough to group identical queries and to review intent, and it
 * cannot leak a customer name, a token, or a row.
 *
 * The log is a bounded ring buffer in an option. That is a deliberate size/effort
 * trade: the audit surface exists from day one, and a site that needs retention
 * can pipe {@see 'wp_mcp_ai_database_audit_recorded'} into its own store.
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

use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\Contract\Database\AuditReaderInterface;
use Nvoos\Database\Domain\Contract\Database\AuditSinkInterface;

/**
 * Bounded audit log.
 */
final class WP_MCP_AI_Db_Audit_Log implements AuditSinkInterface, AuditReaderInterface {

	/**
	 * Option name.
	 */
	public const OPTION = 'wp_mcp_ai_database_audit';

	/**
	 * Maximum retained records.
	 */
	public const MAX_RECORDS = 500;

	/**
	 * Test-only record override.
	 *
	 * @var list<array<string, mixed>>|null
	 */
	private ?array $records_override = null;

	/**
	 * Constructor.
	 *
	 * @param list<array<string, mixed>>|null $records_override Test-only seed.
	 */
	public function __construct( ?array $records_override = null ) {
		$this->records_override = $records_override;
	}

	/**
	 * Mint an audit id.
	 *
	 * Time-prefixed and random-suffixed so ids sort roughly by time but do not
	 * disclose a counter an outsider could use to infer volume.
	 */
	public function next_id(): string {
		$time = base_convert( (string) time(), 10, 36 );

		return 'aud_' . $time . '_' . bin2hex( random_bytes( 6 ) );
	}

	/**
	 * Append a record.
	 *
	 * @param array<string, mixed> $record Record fields.
	 * @return string The audit id.
	 */
	public function append( array $record ): string {
		$entry = array_merge(
			array(
				'id'            => $this->next_id(),
				'ts'            => time(),
				'connection'    => '',
				'scope'         => '',
				'scope_version' => 0,
				'principal'     => '',
				'action'        => '',
				'tool'          => '',
				'relation'      => '',
				'refusal_code'  => null,
				'sql_template'  => '',
				'fingerprint'   => '',
				'row_count'     => 0,
				'elapsed_ms'    => 0,
				'truncated'     => '',
				'detail'        => '',
			),
			$record
		);

		$records   = $this->read();
		$records[] = $entry;

		if ( count( $records ) > self::MAX_RECORDS ) {
			$records = array_slice( $records, -self::MAX_RECORDS );
		}

		$this->write( $records );

		/**
		 * Fires after an audit record is appended.
		 *
		 * Use this to mirror records into a durable store. The array has already
		 * been scrubbed of bound values.
		 *
		 * @since 3.1.0
		 *
		 * @param array<string, mixed> $entry Audit record.
		 */
		do_action( 'wp_mcp_ai_database_audit_recorded', $entry );

		return (string) $entry['id'];
	}

	/**
	 * Most recent records, newest first.
	 *
	 * @param int                  $limit   Maximum records.
	 * @param array<string, mixed> $filters Optional `connection`, `scope`, `action`, `refusal_code`, `tool`.
	 * @return list<array<string, mixed>>
	 */
	public function tail( int $limit = 50, array $filters = array() ): array {
		$limit   = max( 1, min( self::MAX_RECORDS, $limit ) );
		$records = array_reverse( $this->read() );
		$matched = array();

		foreach ( $records as $record ) {
			if ( ! is_array( $record ) ) {
				continue;
			}

			if ( ! $this->matches( $record, $filters ) ) {
				continue;
			}

			$matched[] = $record;

			if ( count( $matched ) >= $limit ) {
				break;
			}
		}

		return $matched;
	}

	/**
	 * Aggregate counts, for the health screen and refusal-rate posture signal.
	 *
	 * @param int $window_seconds Lookback window.
	 * @return array<string, int> Counts keyed by refusal code, plus `total`.
	 */
	public function summary( int $window_seconds = 3600 ): array {
		$since  = time() - max( 1, $window_seconds );
		$counts = array( 'total' => 0 );

		foreach ( $this->read() as $record ) {
			if ( ! is_array( $record ) || (int) ( $record['ts'] ?? 0 ) < $since ) {
				continue;
			}

			++$counts['total'];

			$code = $record['refusal_code'] ?? null;

			if ( is_string( $code ) && '' !== $code ) {
				$counts[ $code ] = ( $counts[ $code ] ?? 0 ) + 1;
			}
		}

		return $counts;
	}

	/**
	 * {@inheritDoc}
	 *
	 * This store is a bounded ring, so the ceiling is the record cap.
	 */
	public function retained(): int {
		return self::MAX_RECORDS;
	}

	/**
	 * Truncate the log.
	 */
	public function clear(): void {
		$this->write( array() );
	}

	/**
	 * Build an audit record from a compiled statement.
	 *
	 * @param CompiledStatement $statement Compiled statement.
	 * @return array{sql_template: string, fingerprint: string}
	 */
	public static function describe_statement( CompiledStatement $statement ): array {
		return array(
			'sql_template' => $statement->template(),
			'fingerprint'  => $statement->fingerprint(),
		);
	}

	/**
	 * Whether a record matches the filters.
	 *
	 * @param array<string, mixed> $record  Record.
	 * @param array<string, mixed> $filters Filters.
	 */
	private function matches( array $record, array $filters ): bool {
		foreach ( array( 'connection', 'scope', 'action', 'tool' ) as $key ) {
			if ( ! isset( $filters[ $key ] ) || '' === (string) $filters[ $key ] ) {
				continue;
			}

			if ( (string) ( $record[ $key ] ?? '' ) !== (string) $filters[ $key ] ) {
				return false;
			}
		}

		if ( isset( $filters['refusal_code'] ) && '' !== (string) $filters['refusal_code'] ) {
			if ( (string) ( $record['refusal_code'] ?? '' ) !== (string) $filters['refusal_code'] ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Read the ring buffer.
	 *
	 * @return list<array<string, mixed>>
	 */
	private function read(): array {
		if ( null !== $this->records_override ) {
			return $this->records_override;
		}

		$stored = get_option( self::OPTION, array() );

		return is_array( $stored ) ? array_values( $stored ) : array();
	}

	/**
	 * Write the ring buffer.
	 *
	 * @param list<array<string, mixed>> $records Records.
	 */
	private function write( array $records ): void {
		if ( null !== $this->records_override ) {
			$this->records_override = $records;

			return;
		}

		update_option( self::OPTION, array_values( $records ), false );
	}
}
