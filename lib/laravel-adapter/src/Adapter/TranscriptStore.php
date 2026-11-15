<?php
/**
 * Laravel adapter: transcript store.
 *
 * One row per chat session. `save()` upserts by session_id, so the console's
 * session list and the transcript contract share one durable record.
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
use Nvoos\Core\Domain\Contract\TranscriptStoreInterface;

class TranscriptStore implements TranscriptStoreInterface {

	private const TABLE = 'nvoos_transcripts';

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

		$existing = DB::table( self::TABLE )->where( 'session_id', $sessionId )->first();

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

			DB::table( self::TABLE )->insert( array(
				'id' => $id,
				'session_id' => $sessionId,
				'assistant_id' => $assistantId,
				'messages' => json_encode( $messages, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'metadata' => json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
				'created_at' => now(),
				'updated_at' => now(),
			) );
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

		$row = DB::table( self::TABLE )->where( 'id', $transcriptId )->first();

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

		$query = DB::table( self::TABLE );

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

		$deleted = DB::table( self::TABLE )->where( 'id', $transcriptId )->delete();

		return $deleted > 0
			? array( 'success' => true )
			: array( 'success' => false, 'error' => 'Transcript not found.' );
	}

	/**
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

		$query = DB::table( self::TABLE );

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
