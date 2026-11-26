<?php
/**
 * Laravel adapter: agent memory store.
 *
 * Durable memory with tier/wing/room filters and a LIKE-based recall. A
 * vector/pgvector search can replace the scoring later without changing the
 * contract.
 *
 * ESTATE SCOPING (Proposal 149 §40). Memory is estate data. The store takes an
 * optional tenant RESOLVER and filters every read and write through it, the same
 * seam as `SettingsStore` (§16), `RemoteSiteStore` (§27) and `TranscriptStore`
 * (§39) — a closure because this object is constructed before tenant middleware
 * runs, so a captured id would pin every later request to the boot-time estate.
 *
 * `listByAgent( $agentId )` is NOT a boundary: the agent id arrives as a query
 * parameter, so it narrows a result set rather than authorising access.
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
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;

class MemoryStore implements MemoryStoreInterface {

	private const TABLE = 'nvoos_memories';

	/**
	 * Resolves the estate every query is scoped to.
	 *
	 * Null means "unscoped": a standalone or adapter-only install with no
	 * tenancy, which is the pre-§40 behaviour and still correct for one estate.
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

	/**
	 * {@inheritDoc}
	 */
	public function store( array $record ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'success' => false, 'error' => 'Memory store unavailable.' );
		}

		$ttl = isset( $record['ttl'] ) ? (int) $record['ttl'] : null;
		$id = (string) Str::uuid();
		$tenant = $this->tenantId();
		$metadata = is_array( $record['metadata'] ?? null ) ? $record['metadata'] : array();

		if ( $this->metadataBytes( $metadata ) > self::MAX_METADATA_BYTES ) {
			return array(
				'success' => false,
				'error' => 'metadata exceeds the ' . self::MAX_METADATA_BYTES . '-byte limit; put the content in the file registry and reference it by id.',
			);
		}

		$row = array(
			'id' => $id,
			'agent_id' => (string) ( $record['agent_id'] ?? '' ),
			'title' => $record['title'] ?? null,
			'content' => (string) ( $record['content'] ?? '' ),
			'tags' => json_encode( array_values( $record['tags'] ?? array() ), JSON_UNESCAPED_UNICODE ),
			'tier' => (string) ( $record['tier'] ?? self::TIER_RECALL ),
			'importance' => (float) ( $record['importance'] ?? 0.5 ),
			'wing' => $record['wing'] ?? null,
			'room' => $record['room'] ?? null,
			'metadata' => $this->metadataJson( $metadata ),
			'ttl' => $ttl,
			'expires_at' => null !== $ttl ? now()->addSeconds( $ttl ) : null,
			'created_at' => now(),
			'updated_at' => now(),
		);

		// Unscoped use leaves the column to its default, so the pre-§40 behaviour
		// is unchanged for a single-estate install.
		if ( null !== $tenant ) {
			$row['tenant_id'] = $tenant;
		}

		DB::table( self::TABLE )->insert( $row );

		return array( 'success' => true, 'memory_id' => $id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $memoryId ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'found' => false );
		}

		$row = $this->query()->where( 'id', $memoryId )->first();

		return null === $row
			? array( 'found' => false )
			: array( 'found' => true, 'record' => $this->record( $row ) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function update( string $memoryId, array $patch ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'success' => false, 'error' => 'Memory store unavailable.' );
		}

		$row = $this->query()->where( 'id', $memoryId )->first();

		// Existence is checked before the write, and the result is not derived
		// from the affected-row count: a database reports zero rows changed when
		// a patch writes the values already present, which used to make a no-op
		// patch indistinguishable from a missing record.
		if ( null === $row ) {
			return array( 'success' => false, 'error' => 'Memory not found.' );
		}

		$update = array( 'updated_at' => now() );

		foreach ( array( 'title', 'content', 'tier', 'wing', 'room', 'importance' ) as $field ) {
			if ( array_key_exists( $field, $patch ) ) {
				$update[ $field ] = $patch[ $field ];
			}
		}

		if ( array_key_exists( 'tags', $patch ) ) {
			$update['tags'] = json_encode( array_values( (array) $patch['tags'] ), JSON_UNESCAPED_UNICODE );
		}

		if ( array_key_exists( 'ttl', $patch ) ) {
			$ttl = null !== $patch['ttl'] ? (int) $patch['ttl'] : null;
			$update['ttl'] = $ttl;
			$update['expires_at'] = null !== $ttl ? now()->addSeconds( $ttl ) : null;
		}

		// Metadata is a merge patch (RFC 7386) rather than a replacement, so a
		// caller adding `valid_until` does not have to send back every key it
		// did not mean to touch.
		if ( array_key_exists( 'metadata', $patch ) ) {
			$merged = $this->mergeMetadata(
				$this->decodeMetadata( $row->metadata ?? null ),
				is_array( $patch['metadata'] ) ? $patch['metadata'] : array(),
			);

			if ( $this->metadataBytes( $merged ) > self::MAX_METADATA_BYTES ) {
				return array(
					'success' => false,
					'error' => 'metadata exceeds the ' . self::MAX_METADATA_BYTES . '-byte limit; put the content in the file registry and reference it by id.',
				);
			}

			$update['metadata'] = $this->metadataJson( $merged );
		}

		$this->query()->where( 'id', $memoryId )->update( $update );

		return array( 'success' => true );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $memoryId ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'success' => false, 'error' => 'Memory store unavailable.' );
		}

		return $this->query()->where( 'id', $memoryId )->delete() > 0
			? array( 'success' => true )
			: array( 'success' => false, 'error' => 'Memory not found.' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function search( string $query, array $filters = array(), int $limit = 10 ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array();
		}

		$builder = $this->query()
			->where( function ( $where ) use ( $query ): void {
				$where->where( 'content', 'like', '%' . $query . '%' )
					->orWhere( 'title', 'like', '%' . $query . '%' );
			} );

		$this->applyFilters( $builder, $filters );
		$this->applyExpiry( $builder );

		$rows = $builder->orderByDesc( 'importance' )
			->limit( min( 50, max( 1, $limit ) ) )
			->get();

		return array_map(
			fn ( object $row ): array => array_merge(
				$this->record( $row ),
				array(
					'memory_id' => (string) $row->id,
					'score' => 1.0,
				),
			),
			$rows->all(),
		);
	}

	/**
	 * {@inheritDoc}
	 */
	public function listByAgent( string $agentId, array $filters = array(), int $limit = 50, int $offset = 0 ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'memories' => array(), 'total' => 0 );
		}

		$builder = $this->query()->where( 'agent_id', $agentId );
		$this->applyFilters( $builder, $filters );
		$this->applyExpiry( $builder );

		$total = ( clone $builder )->count();
		$rows = $builder->orderByDesc( 'importance' )
			->orderByDesc( 'updated_at' )
			->offset( max( 0, $offset ) )
			->limit( min( 200, max( 1, $limit ) ) )
			->get();

		return array(
			'memories' => array_map( array( $this, 'record' ), $rows->all() ),
			'total' => $total,
		);
	}

	// ─── Private helpers ──────────────────────────────────────────────

	/**
	 * The estate to scope to, or null when this instance is unscoped.
	 *
	 * The empty string is treated as "no estate" for the same reason as
	 * `SettingsStore::tenantId()` and `TranscriptStore::tenantId()`.
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
	 * forget the estate filter.
	 */
	private function query(): Builder {
		$query = DB::table( self::TABLE );
		$tenant = $this->tenantId();

		if ( null !== $tenant ) {
			$query->where( 'tenant_id', $tenant );
		}

		return $query;
	}

	/**
	 * @param \Illuminate\Database\Query\Builder $builder
	 * @param array<string, mixed>               $filters
	 */
	private function applyFilters( $builder, array $filters ): void {
		foreach ( array( 'tier', 'wing', 'room' ) as $field ) {
			if ( empty( $filters[ $field ] ) ) {
				continue;
			}

			// `tier` arrives as a list from the tool contract (`include_tiers`
			// defaults to core + recall), so an array filter has to become a set.
			// Casting it produced "Array to string conversion" — a 500 the
			// readiness probe caught (090-M batch 3).
			if ( is_array( $filters[ $field ] ) ) {
				$builder->whereIn( $field, array_map( 'strval', $filters[ $field ] ) );
				continue;
			}

			$builder->where( $field, (string) $filters[ $field ] );
		}

		if ( ! empty( $filters['agent_id'] ) ) {
			$builder->where( 'agent_id', (string) $filters['agent_id'] );
		}
	}

	/**
	 * @param \Illuminate\Database\Query\Builder $builder
	 */
	private function applyExpiry( $builder ): void {
		$builder->where( function ( $where ): void {
			$where->whereNull( 'expires_at' )->orWhere( 'expires_at', '>', now() );
		} );
	}

	/**
	 * @return array<string, mixed>
	 */
	private function record( object $row ): array {
		return array(
			'context_id' => (string) $row->id,
			'id' => (string) $row->id,
			'agent_id' => (string) $row->agent_id,
			'title' => $row->title,
			'content' => (string) $row->content,
			'tags' => json_decode( (string) ( $row->tags ?? '' ), true ) ?: array(),
			'tier' => (string) $row->tier,
			'importance' => (float) $row->importance,
			'wing' => $row->wing,
			'room' => $row->room,
			'metadata' => $this->decodeMetadata( $row->metadata ?? null ),
			'expires_at' => $row->expires_at,
			'created_at' => $row->created_at,
			'updated_at' => $row->updated_at,
		);
	}

	/**
	 * Encode a metadata map for storage, or null when there is nothing to store.
	 *
	 * `null` rather than `'{}'`: an empty map and no provenance are the same
	 * thing, and the column should say which one it holds.
	 *
	 * @param array<string, mixed> $metadata Metadata map.
	 */
	private function metadataJson( array $metadata ): ?string {
		if ( array() === $metadata ) {
			return null;
		}

		$json = json_encode( $metadata, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES );

		return false === $json ? null : $json;
	}

	/**
	 * The encoded size of a metadata map, for the contract's ceiling.
	 *
	 * @param array<string, mixed> $metadata Metadata map.
	 */
	private function metadataBytes( array $metadata ): int {
		$json = $this->metadataJson( $metadata );

		return null === $json ? 0 : strlen( $json );
	}

	/**
	 * Decode a stored metadata map, always returning an array.
	 *
	 * A record written before the column existed, or one written without
	 * provenance, reads back as an empty map so a caller never has to tell
	 * "absent" from "empty".
	 */
	private function decodeMetadata( mixed $raw ): array {
		if ( ! is_string( $raw ) || '' === $raw ) {
			return array();
		}

		$decoded = json_decode( $raw, true );

		return is_array( $decoded ) ? $decoded : array();
	}

	/**
	 * Apply a JSON Merge Patch (RFC 7386) to a stored metadata map.
	 *
	 * A nested object merges recursively, anything else replaces, and a null
	 * value removes the key. An empty array is treated as an object, which is the
	 * safe reading: PHP decodes `{}` and `[]` to the same value, and merging
	 * nothing beats replacing a map with a list.
	 *
	 * @param array<string, mixed> $target Stored map.
	 * @param array<string, mixed> $patch  Patch to apply.
	 *
	 * @return array<string, mixed>
	 */
	private function mergeMetadata( array $target, array $patch ): array {
		foreach ( $patch as $key => $value ) {
			if ( null === $value ) {
				unset( $target[ $key ] );
				continue;
			}

			if (
				is_array( $value ) && $this->isMap( $value )
				&& is_array( $target[ $key ] ?? null ) && $this->isMap( $target[ $key ] )
			) {
				$target[ $key ] = $this->mergeMetadata( $target[ $key ], $value );
				continue;
			}

			$target[ $key ] = $value;
		}

		return $target;
	}

	/**
	 * Whether an array is a JSON object rather than a JSON array.
	 *
	 * @param array<array-key, mixed> $value Candidate.
	 */
	private function isMap( array $value ): bool {
		return array() === $value || array_keys( $value ) !== range( 0, count( $value ) - 1 );
	}
}
