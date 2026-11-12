<?php
/**
 * Laravel adapter: agent memory store.
 *
 * Durable memory with tier/wing/room filters and a LIKE-based recall. A
 * vector/pgvector search can replace the scoring later without changing the
 * contract.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;

class MemoryStore implements MemoryStoreInterface {

	private const TABLE = 'nvoos_memories';

	/**
	 * {@inheritDoc}
	 */
	public function store( array $record ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'success' => false, 'error' => 'Memory store unavailable.' );
		}

		$ttl = isset( $record['ttl'] ) ? (int) $record['ttl'] : null;
		$id = (string) Str::uuid();

		DB::table( self::TABLE )->insert( array(
			'id' => $id,
			'agent_id' => (string) ( $record['agent_id'] ?? '' ),
			'title' => $record['title'] ?? null,
			'content' => (string) ( $record['content'] ?? '' ),
			'tags' => json_encode( array_values( $record['tags'] ?? array() ), JSON_UNESCAPED_UNICODE ),
			'tier' => (string) ( $record['tier'] ?? self::TIER_RECALL ),
			'importance' => (float) ( $record['importance'] ?? 0.5 ),
			'wing' => $record['wing'] ?? null,
			'room' => $record['room'] ?? null,
			'ttl' => $ttl,
			'expires_at' => null !== $ttl ? now()->addSeconds( $ttl ) : null,
			'created_at' => now(),
			'updated_at' => now(),
		) );

		return array( 'success' => true, 'memory_id' => $id );
	}

	/**
	 * {@inheritDoc}
	 */
	public function get( string $memoryId ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'found' => false );
		}

		$row = DB::table( self::TABLE )->where( 'id', $memoryId )->first();

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

		$updated = DB::table( self::TABLE )->where( 'id', $memoryId )->update( $update );

		return $updated > 0
			? array( 'success' => true )
			: array( 'success' => false, 'error' => 'Memory not found.' );
	}

	/**
	 * {@inheritDoc}
	 */
	public function delete( string $memoryId ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'success' => false, 'error' => 'Memory store unavailable.' );
		}

		return DB::table( self::TABLE )->where( 'id', $memoryId )->delete() > 0
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

		$builder = DB::table( self::TABLE )
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

		$builder = DB::table( self::TABLE )->where( 'agent_id', $agentId );
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

	/**
	 * @param \Illuminate\Database\Query\Builder $builder
	 * @param array<string, mixed>               $filters
	 */
	private function applyFilters( $builder, array $filters ): void {
		foreach ( array( 'tier', 'wing', 'room' ) as $field ) {
			if ( ! empty( $filters[ $field ] ) ) {
				$builder->where( $field, (string) $filters[ $field ] );
			}
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
			'expires_at' => $row->expires_at,
			'created_at' => $row->created_at,
			'updated_at' => $row->updated_at,
		);
	}
}
