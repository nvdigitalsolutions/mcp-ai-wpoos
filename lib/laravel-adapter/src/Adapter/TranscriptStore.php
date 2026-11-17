<?php
/**
 * Laravel adapter: transcript store.
 *
 * One row per chat session. `save()` upserts by session id, so the console's
 * session list and the transcript contract share one durable record.
 *
 * ESTATE SCOPING (Proposal 149 §39). Transcripts are estate data. The store
 * therefore takes an optional tenant RESOLVER and filters every read and write
 * through it — the same seam `SettingsStore` uses, and a closure for the same
 * reason: this object is constructed before any tenant middleware has run, so a
 * captured id would pin every later request to the boot-time estate.
 *
 * `prune()` is the one deliberate exception. Retention is a system job with no
 * request estate, so it spans every estate; see the method docblock.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Closure;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

class TranscriptStore implements TranscriptStoreInterface {

	private const TABLE = 'nvoos_transcripts';

	/**
	 * Resolves the estate every query is scoped to.
	 *
	 * Null means "unscoped": a standalone or adapter-only install with no
	 * tenancy, which is the pre-§39 behaviour and still correct for one estate.
	 *
	 * @var Closure():string|null
	 */
	private ?Closure $tenant;

	/**
	 * @param Closure():string|null $tenant Resolves the estate to scope reads
	 *                                      and writes to.
	 */
	public function __construct( ?Closure $tenant = null ) {
		$this->tenant = $tenant;
	}

	public function isAvailable(): bool {
		return Schema::hasTable( self::TABLE );
	}

	/**
	 * {@inheritDoc}
	 */
	public function save( array $transcript ): array {
		if ( ! $this->isAvailable() ) {
			return array( 'success' => false, 'transcript_id' => '', 'error' => 'Transcript store unavailable.' );
		}

		$sessionId = (string) ( $transcript['session_id'] ?? '' );

		if ( '' === $sessionId ) {
			return array( 'success' => false, 'transcript_id' => '', 'error' => 'session_id is required.' );
		}

		$messages = is_array( $transcript['messages'] ?? null ) ? $transcript['messages'] : array();
		$metadata = is_array( $transcript['metadata'] ?? null ) ? $transcript['metadata'] : array();
		$assistantId = isset( $transcript['assistant_id'] ) ? (string) $transcript['assistant_id'] : null;

		$tenant = $this->tenantId();

		$existing = $this->query( $tenant )->where( 'session_id', $sessionId )->first();

		if ( null !== $existing ) {
			$id = (string) $existing->id;

			DB::table( self::TABLE )->where( 'id', $id )->update( array(
				'assistant_id' => $assistantId,
				'messages' => json_encode( $messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'metadata' => json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'updated_at' => now(),
			) );
		} else {
			$id = (string) Str::uuid();

			$row = array(
				'id' => $id,
				'session_id' => $sessionId,
				'assistant_id' => $assistantId,
				'messages' => json_encode( $messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'metadata' => json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'created_at' => now(),
				'updated_at' => now(),
			);

			// Unscoped use leaves the column to its default, so the pre-§39
			// behaviour is unchanged for a single-estate install.
			if ( null !== $tenant ) {
				$row['tenant_id'] = $tenant;
			}

			DB::table( self::TABLE )->insert( $row );
		}

		return array( 'success' => true, 'transcript_id' => $id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $transcriptId ): array {
		if ( ! $this->isAvailable() ) {
			return array( 'found' => false );
		}

		$row = $this->query()->where( 'id', $transcriptId )->first();

		return null === $row
			? array( 'found' => false )
			: array( 'found' => true, 'transcript' => $this->record( $row ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function list( array $filters = array() ): array {
		if ( ! $this->isAvailable() ) {
			return array( 'transcripts' => array(), 'total' => 0 );
		}

		$query = $this->query();

		if ( ! empty( $filters['assistant_id'] ) ) {
			$query->where( 'assistant_id', (string) $filters['assistant_id'] );
		}

		if ( ! empty( $filters['session_id'] ) ) {
			$query->where( 'session_id', (string) $filters['session_id'] );
		}

		$total = ( clone $query )->count();
		$limit = min( 200, max( 1, (int) ( $filters['limit'] ?? 50 ) ) );
		$offset = max( 0, (int) ( $filters['offset'] ?? 0 ) );

		$rows = $query->orderByDesc( 'updated_at' )->offset( $offset )->limit( $limit )->get();

		return array(
			'transcripts' => array_map( array( $this, 'record' ), $rows->all() ),
			'total' => $total,
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $transcriptId ): array {
		if ( ! $this->isAvailable() ) {
			return array( 'success' => false, 'error' => 'Transcript store unavailable.' );
		}

		$deleted = $this->query()->where( 'id', $transcriptId )->delete();

		return $deleted > 0
			? array( 'success' => true )
			: array( 'success' => false, 'error' => 'Transcript not found.' );
	}

	/**
	 * Delete transcripts older than a window, across EVERY estate.
	 *
	 * Deliberately unscoped. Retention is a server-owned job (the ownership
	 * contract says so on the row), it runs without a request and therefore
	 * without a meaningful estate, and scoping it would silently stop retaining
	 * every estate but the default. The same reasoning as `TriggerDispatcher::due()`
	 * in §29: a system caller spans estates by design.
	 *
	 * {@inheritDoc}
	 */
	public function prune( int $olderThanSeconds ): array {
		if ( ! $this->isAvailable() ) {
			return array( 'success' => false, 'deleted_count' => 0, 'error' => 'Transcript store unavailable.' );
		}

		$deleted = DB::table( self::TABLE )
			->where( 'updated_at', '<', now()->subSeconds( max( 0, $olderThanSeconds ) ) )
			->delete();

		return array( 'success' => true, 'deleted_count' => $deleted );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getCounts( array $filters = array() ): array {
		if ( ! $this->isAvailable() ) {
			return array( 'total' => 0 );
		}

		$query = $this->query();

		if ( ! empty( $filters['assistant_id'] ) ) {
			$query->where( 'assistant_id', (string) $filters['assistant_id'] );
		}

		$byAssistant = ( clone $query )
			->select( 'assistant_id', DB::raw( 'count(*) as total' ) )
			->groupBy( 'assistant_id' )
			->pluck( 'total', 'assistant_id' )
			->map( static fn ( $total ) => (int) $total )
			->all();

		return array(
			'total' => (int) $query->count(),
			'by_assistant' => $byAssistant,
		);
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * The estate to scope to, or null when this instance is unscoped.
	 *
	 * The empty string is treated as "no estate" for the same reason as
	 * `SettingsStore::tenantId()`: an unpinned context can surface as either
	 * null or '', and a row keyed by '' would be a third state nobody can
	 * reason about.
	 */
	private function tenantId(): ?string {
		if ( null === $this->tenant ) {
			return null;
		}

		$tenantId = ( $this->tenant )();

		return is_string( $tenantId ) && '' !== $tenantId ? $tenantId : null;
	}

	/**
	 * The base query. Every method starts here, so a future method cannot
	 * forget the estate filter — adding `->where( 'tenant_id', … )` at each call
	 * site is a change one forgotten line undoes.
	 */
	private function query( ?string $tenant = null ): Builder {
		$query = DB::table( self::TABLE );
		$tenant = $tenant ?? $this->tenantId();

		if ( null !== $tenant ) {
			$query->where( 'tenant_id', $tenant );
		}

		return $query;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record( object $row ): array {
		return array(
			'transcript_id' => (string) $row->id,
			'session_id' => (string) $row->session_id,
			'assistant_id' => $row->assistant_id,
			'messages' => json_decode( (string) $row->messages, true ) ?: array(),
			'metadata' => json_decode( (string) ( $row->metadata ?? '' ), true ) ?: array(),
			'created_at' => $row->created_at,
			'updated_at' => $row->updated_at,
		);
	}
}
