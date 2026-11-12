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
	 * Site-wide cost summary for the Analytics page.
	 *
	 * @return array<string, mixed>
	 */
	public function summary( int $days = 30 ): array {
		$days = max( 1, $days );
		$start = now()->subDays( $days - 1 )->startOfDay();
		$rows = $this->rows( $start );

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
	public function trend( int $days = 30 ): array {
		$days = max( 1, $days );
		$start = now()->subDays( $days - 1 )->startOfDay();
		$map = array();

		foreach ( $this->rows( $start ) as $row ) {
			$date = substr( (string) $row->created_at, 0, 10 );
			$map[ $date ] = ( $map[ $date ] ?? 0 ) + (float) $row->cost;
		}

		$labels = array();
		$data = array();

		for ( $i = $days - 1; $i >= 0; $i-- ) {
			$date = now()->subDays( $i )->toDateString();
			$labels[] = $date;
			$data[] = (float) ( $map[ $date ] ?? 0 );
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
	public function byProvider( int $days = 30 ): array {
		$summary = $this->summary( $days );
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
	 * @return \Illuminate\Support\Collection<int, object>
	 */
	private function rows( $start ) {
		if ( ! Schema::hasTable( self::TABLE ) ) {
			return collect();
		}

		return DB::table( self::TABLE )->where( 'created_at', '>=', $start )->get();
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
