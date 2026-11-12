<?php
/**
 * Laravel adapter: session log store.
 *
 * Append-only JSON rows keyed by session id. A remote sink can replace the
 * table later behind the same contract.
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\SessionLogStoreInterface;

class SessionLogStore implements SessionLogStoreInterface {

	private const TABLE = 'nvoos_session_logs';

	/**
	 * {@inheritDoc}
	 */
	public function append( string $sessionId, array $entry ): void {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return;
		}

		DB::table( self::TABLE )->insert( array(
			'session_id' => $sessionId,
			'entry' => json_encode( $entry, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'created_at' => now(),
			'updated_at' => now(),
		) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function load( string $sessionId ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array();
		}

		return DB::table( self::TABLE )
			->where( 'session_id', $sessionId )
			->orderBy( 'id' )
			->pluck( 'entry' )
			->map( static fn ( $entry ) => json_decode( (string) $entry, true ) ?: array() )
			->all();
	}
}
