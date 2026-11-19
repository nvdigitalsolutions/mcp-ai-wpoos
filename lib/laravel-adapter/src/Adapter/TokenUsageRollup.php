<?php
/**
 * Daily token usage rollup — Proposal 149 §80.
 *
 * `nvoos_token_usage` is pruned at the retention window, which would take the
 * cost history with it. This class projects one day of raw rows into
 * `nvoos_token_usage_daily`, keyed by day, provider and model, so the shape of
 * spend survives the raw rows.
 *
 * Two properties make it safe to schedule:
 *
 *  - **idempotent** — re-running a day upserts the same keys, so a missed run
 *    heals and a repeated run is free;
 *  - **additive** — a day that has no raw rows left (already pruned) produces no
 *    upsert at all, so an existing aggregate is never zeroed by a late re-run.
 *
 * @package Nvoos\Laravel
 * @since   1.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class TokenUsageRollup {

	private const RAW = 'nvoos_token_usage';

	private const DAILY = 'nvoos_token_usage_daily';

	private const CHUNK = 1000;

	/**
	 * Aggregate one complete day into the daily table.
	 *
	 * The provider comes from the row's `metadata.provider`, the model from
	 * `model_id`, the estate from the `tenant_id` column. Rows are read in id
	 * batches rather than aggregated in SQL: extracting a JSON key differs
	 * between MySQL and SQLite, and the suite runs on SQLite while production
	 * runs on MySQL.
	 *
	 * The estate is part of the group key: two estates running the same model on
	 * the same day are two rows, not one upsert that overwrites the other
	 * (Proposal 152 §3 D6).
	 *
	 * @return array{groups: int, rows: int} Groups written and raw rows read.
	 */
	public function rollupDay( CarbonImmutable $day ): array {
		if ( ! $this->available() ) {
			return array( 'groups' => 0, 'rows' => 0 );
		}

		$start = $day->startOfDay();
		$end   = $start->addDay();
		$date  = $start->toDateString();

		$groups = array();
		$rows   = 0;

		DB::table( self::RAW )
			->where( 'created_at', '>=', $start )
			->where( 'created_at', '<', $end )
			->chunkById(
				self::CHUNK,
				function ( $chunk ) use ( &$groups, &$rows, $date ): void {
					foreach ( $chunk as $row ) {
						++$rows;

						$meta     = json_decode( (string) ( $row->metadata ?? '' ), true ) ?: array();
						$provider = (string) ( $meta['provider'] ?? 'unknown' );
						$model    = (string) $row->model_id;
						$tenant   = $this->tenantOf( $row );
						$key      = $tenant . "\0" . $provider . "\0" . $model;

						if ( ! isset( $groups[ $key ] ) ) {
							$groups[ $key ] = array(
								'tenant_id' => $tenant,
								'day' => $date,
								'provider' => $provider,
								'model_id' => $model,
								'events' => 0,
								'prompt_tokens' => 0,
								'completion_tokens' => 0,
								'total_tokens' => 0,
								'cost' => 0.0,
								'updated_at' => null,
							);
						}

						++$groups[ $key ]['events'];
						$groups[ $key ]['prompt_tokens']    += max( 0, (int) $row->prompt_tokens );
						$groups[ $key ]['completion_tokens'] += max( 0, (int) $row->completion_tokens );
						$groups[ $key ]['total_tokens']     += max( 0, (int) $row->total_tokens );
						$groups[ $key ]['cost']             += (float) $row->cost;
					}
				}
			);

		if ( array() === $groups ) {
			return array( 'groups' => 0, 'rows' => 0 );
		}

		$timestamp = now();

		foreach ( $groups as &$group ) {
			$group['cost']       = round( (float) $group['cost'], 6 );
			$group['updated_at'] = $timestamp;
		}
		unset( $group );

		DB::table( self::DAILY )->upsert(
			array_values( $groups ),
			array( 'tenant_id', 'day', 'provider', 'model_id' ),
			array( 'events', 'prompt_tokens', 'completion_tokens', 'total_tokens', 'cost', 'updated_at' )
		);

		return array( 'groups' => count( $groups ), 'rows' => $rows );
	}

	/**
	 * Raw rows a rollup of this day would read.
	 *
	 * `$tenantId` mirrors the reader contract: `null` counts every estate, which
	 * is what the retention guard and the rollup command ask for.
	 */
	public function countDay( CarbonImmutable $day, ?string $tenantId = null ): int {
		if ( ! $this->available() ) {
			return 0;
		}

		$start = $day->startOfDay();

		return (int) DB::table( self::RAW )
			->where( 'created_at', '>=', $start )
			->where( 'created_at', '<', $start->addDay() )
			->when( null !== $tenantId, fn ( $query ) => $query->where( 'tenant_id', $tenantId ) )
			->count();
	}

	/**
	 * The most recent day that has an aggregate.
	 *
	 * Retention uses this as its floor: a raw row may only be deleted once its
	 * day has been rolled up. `null` means nothing has ever been rolled up —
	 * including "the table does not exist" — and retention treats that as
	 * "delete nothing" rather than "delete everything".
	 *
	 * Deliberately **platform-wide**: the guard has to know whether *every*
	 * estate's day has been rolled up before it deletes that day's raw rows
	 * (Proposal 152 §3 D7).
	 */
	public function lastRolledUpDay(): ?CarbonImmutable {
		if ( ! $this->available() ) {
			return null;
		}

		try {
			$max = DB::table( self::DAILY )->max( 'day' );
		} catch ( \Throwable $exception ) {
			report( $exception );

			return null;
		}

		return null === $max ? null : CarbonImmutable::parse( (string) $max )->startOfDay();
	}

	/**
	 * Cost per day from the aggregate, for a trend that outlives retention.
	 *
	 * `$tenantId` is required, not defaulted, for the same reason as the raw
	 * readers: `null` is a platform-wide read and must be an explicit choice
	 * (Proposal 152 §3 D5).
	 *
	 * @return array<string, float> `YYYY-MM-DD` => cost.
	 */
	public function dailyCosts( string $fromDate, ?string $tenantId ): array {
		if ( ! $this->available() ) {
			return array();
		}

		$rows = DB::table( self::DAILY )
			->selectRaw( 'day, SUM(cost) as cost' )
			->where( 'day', '>=', $fromDate )
			->when( null !== $tenantId, fn ( $query ) => $query->where( 'tenant_id', $tenantId ) )
			->groupBy( 'day' )
			->get();

		$costs = array();

		foreach ( $rows as $row ) {
			$costs[ substr( (string) $row->day, 0, 10 ) ] = (float) $row->cost;
		}

		return $costs;
	}

	/**
	 * The estate a raw row belongs to.
	 *
	 * A row written before the tenant column existed carries no estate; the
	 * column default makes it `default`, and reading it defensively keeps a
	 * rollup running against a not-yet-migrated table honest (Proposal 152 §3 D3).
	 */
	private function tenantOf( object $row ): string {
		$tenant = trim( (string) ( $row->tenant_id ?? '' ) );

		return '' !== $tenant ? $tenant : 'default';
	}

	/**
	 * Both tables exist. Missing tables are a "nothing to do", not an error:
	 * the adapter is usable before migrations have run.
	 */
	private function available(): bool {
		try {
			return Schema::hasTable( self::RAW ) && Schema::hasTable( self::DAILY );
		} catch ( \Throwable $exception ) {
			report( $exception );

			return false;
		}
	}
}
