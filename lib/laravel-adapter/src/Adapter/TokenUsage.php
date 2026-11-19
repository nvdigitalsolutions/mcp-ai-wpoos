<?php
/**
 * Laravel adapter: token usage + cost aggregation.
 *
 * The contract covers per-user and per-model lookups; `summary()`, `trend()`,
 * and `byProvider()` are the console analytics projections (Proposal 090-B,
 * Phase 3.4).
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Nvoos\Core\Domain\Contract\TokenUsageInterface;

class TokenUsage implements TokenUsageInterface {

	private const TABLE = 'nvoos_token_usage';

	/**
	 * {@inheritDoc}
	 */
	public function trackUsage( int $userId, string $modelId, int $promptTokens, int $completionTokens, array $metadata = array() ): void {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return;
		}

		DB::table( self::TABLE )->insert( array(
			'user_id' => $userId,
			// The estate is written as a column so queries can scope by it; the
			// metadata copy stays for debugging (Proposal 152 §3 D4).
			'tenant_id' => $this->tenantFrom( $metadata ),
			// The assistant, for the same reason and at the same altitude
			// (Proposal 154 slice D): a per-assistant budget must not filter a
			// JSON blob on the enforcement path. `0` is "not attributed".
			'assistant_id' => $this->assistantFrom( $metadata ),
			'model_id' => $modelId,
			'prompt_tokens' => max( 0, $promptTokens ),
			'completion_tokens' => max( 0, $completionTokens ),
			'total_tokens' => max( 0, $promptTokens ) + max( 0, $completionTokens ),
			'cost' => (float) ( $metadata['cost'] ?? 0 ),
			'metadata' => json_encode( $metadata, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ),
			'created_at' => now(),
		) );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getUserUsage( int $userId, string $startDate, string $endDate ): array {
		return $this->aggregate( array( 'user_id' => $userId ), $startDate, $endDate );
	}

	/**
	 * {@inheritDoc}
	 */
	public function getModelUsage( string $modelId, string $startDate, string $endDate ): array {
		return $this->aggregate( array( 'model_id' => $modelId ), $startDate, $endDate );
	}

	/**
	 * Cost summary for one estate, or the whole deployment when `null`.
	 *
	 * `$tenantId` is required rather than defaulted: `null` means every estate,
	 * and only an operator surface may ask for that. A default would let a new
	 * call site leak without noticing (Proposal 152 §3 D5).
	 *
	 * @return array<string, mixed>
	 */
	public function summary( int $days, ?string $tenantId ): array {
		$days = max( 1, $days );
		$start = now()->subDays( $days - 1 )->startOfDay();
		$rows = $this->rows( $start, $tenantId );

		$totalCost = 0.0;
		$totalTokens = 0;
		$byProvider = array();
		$byModel = array();
		$byTool = array();
		$topUsers = array();

		foreach ( $rows as $row ) {
			$meta = json_decode( (string) ( $row->metadata ?? '' ), true ) ?: array();
			$provider = (string) ( $meta['provider'] ?? 'unknown' );
			$cost = (float) $row->cost;
			$tokens = (int) $row->total_tokens;

			$totalCost += $cost;
			$totalTokens += $tokens;
			$byProvider[ $provider ] = ( $byProvider[ $provider ] ?? 0 ) + $cost;

			$model = (string) $row->model_id;
			if ( ! isset( $byModel[ $model ] ) ) {
				$byModel[ $model ] = array(
					'provider' => $provider,
					'model' => $model,
					'total_cost' => 0.0,
					'total_tokens' => 0,
				);
			}
			$byModel[ $model ]['total_cost'] += $cost;
			$byModel[ $model ]['total_tokens'] += $tokens;

			$tool = $meta['tool'] ?? null;
			if ( is_string( $tool ) && '' !== $tool ) {
				$byTool[ $tool ] = ( $byTool[ $tool ] ?? 0 ) + $cost;
			}

			$userId = (int) $row->user_id;
			if ( ! isset( $topUsers[ $userId ] ) ) {
				$topUsers[ $userId ] = array( 'name' => '', 'cost' => 0.0 );
			}
			$topUsers[ $userId ]['cost'] += $cost;
		}

		$names = $this->userNames( array_keys( $topUsers ) );
		foreach ( $topUsers as $id => &$user ) {
			$user['name'] = (string) ( $names[ $id ] ?? (string) $id );
		}
		unset( $user );

		arsort( $byProvider );
		arsort( $byTool );
		uasort( $topUsers, static fn ( $a, $b ) => $b['cost'] <=> $a['cost'] );

		return array(
			'total_cost' => $totalCost,
			'total_tokens' => $totalTokens,
			'by_provider' => $byProvider,
			'by_model' => $byModel,
			'by_tool' => $byTool,
			'period_start' => $start->toDateString(),
			'period_end' => now()->toDateString(),
			'top_tools' => array_slice( $byTool, 0, 5, true ),
			'top_users' => array_slice( $topUsers, 0, 5, true ),
		);
	}

	/**
	 * Chart-ready daily cost series.
	 *
	 * @return array<string, mixed>
	 */
	public function trend( int $days, ?string $tenantId ): array {
		$days = max( 1, $days );
		$start = now()->subDays( $days - 1 )->startOfDay();
		$map = array();

		foreach ( $this->rows( $start, $tenantId ) as $row ) {
			$date = substr( (string) $row->created_at, 0, 10 );
			$map[ $date ] = ( $map[ $date ] ?? 0 ) + (float) $row->cost;
		}

		// §80 — raw rows are pruned at the retention window; the daily rollup
		// outlives them. A day that still has raw rows is authoritative (it is
		// the source the rollup was built from); a day without them falls back
		// to the aggregate, so a long trend keeps its shape after retention.
		$rollup = app( TokenUsageRollup::class )->dailyCosts( $start->toDateString(), $tenantId );

		$labels = array();
		$data = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = now()->subDays( $i )->toDateString();
			$labels[] = $date;
			$data[] = array_key_exists( $date, $map )
				? $map[ $date ]
				: (float) ( $rollup[ $date ] ?? 0 );
		}

		return array(
			'labels' => $labels,
			'datasets' => array(
				array(
					'label' => 'Cost',
					'data' => $data,
					'borderColor' => '#2563eb',
					'backgroundColor' => 'rgba(37, 99, 235, 0.15)',
					'fill' => true,
				),
			),
		);
	}

	/**
	 * Chart-ready provider distribution.
	 *
	 * @return array<string, mixed>
	 */
	public function byProvider( int $days, ?string $tenantId ): array {
		$summary = $this->summary( $days, $tenantId );
		$labels = array_keys( $summary['by_provider'] );
		$data = array_values( $summary['by_provider'] );
		$palette = array( '#2563eb', '#7c3aed', '#059669', '#d97706', '#dc2626', '#0891b2' );
		$colors = array();

		foreach ( $labels as $index => $label ) {
			$colors[] = $palette[ $index % count( $palette ) ];
		}

		return array(
			'labels' => $labels,
			'datasets' => array(
				array(
					'label' => 'Cost by provider',
					'data' => $data,
					'backgroundColor' => $colors,
				),
			),
		);
	}

	/**
	 * @param array<string, mixed> $where
	 * @return array<string, mixed>
	 */
	private function aggregate( array $where, string $startDate, string $endDate ): array {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return array( 'total_tokens' => 0, 'total_cost' => 0.0, 'by_model' => array() );
		}

		$query = DB::table( self::TABLE )
			->where( 'created_at', '>=', $startDate . ' 00:00:00' )
			->where( 'created_at', '<=', $endDate . ' 23:59:59' );

		foreach ( $where as $field => $value ) {
			$query->where( $field, $value );
		}

		$byModel = array();
		$totalTokens = 0;
		$totalCost = 0.0;

		foreach ( $query->get() as $row ) {
			$totalTokens += (int) $row->total_tokens;
			$totalCost += (float) $row->cost;
			$byModel[ $row->model_id ] = ( $byModel[ $row->model_id ] ?? 0 ) + (int) $row->total_tokens;
		}

		return array(
			'total_tokens' => $totalTokens,
			'total_cost' => $totalCost,
			'by_model' => $byModel,
		);
	}

	/**
	 * @param \Carbon\CarbonImmutable $start
	 * @param string|null              $tenantId `null` = every estate.
	 * @return \Illuminate\Support\Collection<int, object>
	 */
	private function rows( $start, ?string $tenantId ) {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return collect();
		}

		return DB::table( self::TABLE )
			->where( 'created_at', '>=', $start )
			->when( null !== $tenantId, fn ( $query ) => $query->where( 'tenant_id', $tenantId ) )
			->get();
	}

	/**
	 * The estate a usage row belongs to.
	 *
	 * The writer supplies it in metadata; a missing value is the default estate,
	 * which is the only defensible attribution for a row that records none
	 * (Proposal 152 §3 D3).
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function tenantFrom( array $metadata ): string {
		$tenant = trim( (string) ( $metadata['tenant_id'] ?? '' ) );

		return '' !== $tenant ? substr( $tenant, 0, 64 ) : 'default';
	}

	/**
	 * The assistant that produced the turn, or `0` when the caller did not name
	 * one.
	 *
	 * Never negative: the column is unsigned, and a negative id would make the
	 * row unattributable *and* throw on MySQL's strict mode, failing a telemetry
	 * write after a chat that already succeeded.
	 *
	 * @param array<string, mixed> $metadata
	 */
	private function assistantFrom( array $metadata ): int {
		return max( 0, (int) ( $metadata['assistant_id'] ?? 0 ) );
	}

	/**
	 * @param array<int, int> $ids
	 * @return array<int, string>
	 */
	private function userNames( array $ids ): array {
		if ( array() === $ids ) {
			return array();
		}

		$model = (string) config( 'oos.user_model', 'App\\Models\\User' );

		if ( ! class_exists( $model ) ) {
			return array();
		}

		try {
			return $model::query()->whereIn( 'id', $ids )->pluck( 'name', 'id' )->all();
		} catch ( \Throwable ) {
			return array();
		}
	}
}
