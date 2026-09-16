<?php
/**
 * Technical Indicators
 *
 * Pure-PHP technical indicator math over OHLCV series: SMA, EMA, VWAP,
 * Bollinger Bands, RSI (Wilder) and MACD. No external dependencies — the
 * same indicators OpenTerminal computes client-side from cached candles.
 *
 * Every indicator is exposed twice: as a raw computation method and through
 * `compute()` with per-indicator filter seams
 * (`wp_mcp_ai_indicator_<slug>`) so integrations can override or extend.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.80
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Technical Indicators Class.
 */
class WP_MCP_AI_Technical_Indicators {

	/**
	 * Extract a close-price series from normalized or yfinance-style rows.
	 *
	 * @param array $rows OHLCV rows.
	 * @return array<float> Close prices.
	 */
	public static function extract_closes( $rows ) {
		$closes = array();

		if ( ! is_array( $rows ) ) {
			return $closes;
		}

		foreach ( $rows as $row ) {
			if ( ! is_array( $row ) ) {
				continue;
			}
			if ( isset( $row['close'] ) ) {
				$closes[] = (float) $row['close'];
			} elseif ( isset( $row['Close'] ) ) {
				$closes[] = (float) $row['Close'];
			}
		}

		return $closes;
	}

	/**
	 * Get a row value by key with fallback for yfinance-style keys.
	 *
	 * @param array  $row Row.
	 * @param string $key Lowercase key.
	 * @return float|null
	 */
	protected static function row_value( $row, $key ) {
		if ( isset( $row[ $key ] ) ) {
			return (float) $row[ $key ];
		}
		if ( isset( $row[ ucfirst( $key ) ] ) ) {
			return (float) $row[ ucfirst( $key ) ];
		}

		return null;
	}

	/**
	 * Simple Moving Average.
	 *
	 * @param array $values Numeric series.
	 * @param int   $period Period.
	 * @return array|null SMA series aligned to input (null before warm-up).
	 */
	public static function sma( $values, $period = 20 ) {
		$period = max( 1, (int) $period );
		$values = array_values( array_map( 'floatval', (array) $values ) );
		$count  = count( $values );

		if ( $count < $period ) {
			return null;
		}

		$out = array_fill( 0, $count, null );
		$sum = 0.0;

		for ( $i = 0; $i < $count; $i++ ) {
			$sum += $values[ $i ];
			if ( $i >= $period ) {
				$sum -= $values[ $i - $period ];
			}
			if ( $i >= $period - 1 ) {
				$out[ $i ] = $sum / $period;
			}
		}

		return $out;
	}

	/**
	 * Exponential Moving Average (seeded with the SMA of the first period).
	 *
	 * @param array $values Numeric series.
	 * @param int   $period Period.
	 * @return array|null EMA series aligned to input.
	 */
	public static function ema( $values, $period = 20 ) {
		$period = max( 1, (int) $period );
		$values = array_values( array_map( 'floatval', (array) $values ) );
		$count  = count( $values );

		if ( $count < $period ) {
			return null;
		}

		$out      = array_fill( 0, $count, null );
		$seed_sum = 0.0;
		for ( $i = 0; $i < $period; $i++ ) {
			$seed_sum += $values[ $i ];
		}

		$multiplier         = 2.0 / ( $period + 1 );
		$previous           = $seed_sum / $period;
		$out[ $period - 1 ] = $previous;

		for ( $i = $period; $i < $count; $i++ ) {
			$previous  = ( ( $values[ $i ] - $previous ) * $multiplier ) + $previous;
			$out[ $i ] = $previous;
		}

		return $out;
	}

	/**
	 * Volume-Weighted Average Price.
	 *
	 * @param array $rows   OHLCV rows.
	 * @param int   $period Rolling window (0 = cumulative from start).
	 * @return array|null VWAP series aligned to input.
	 */
	public static function vwap( $rows, $period = 0 ) {
		$rows   = array_values( (array) $rows );
		$period = max( 0, (int) $period );
		$count  = count( $rows );

		if ( 0 === $count ) {
			return null;
		}

		$out = array_fill( 0, $count, null );

		for ( $i = 0; $i < $count; $i++ ) {
			$start = ( $period > 0 && $i >= $period ) ? $i - $period + 1 : 0;

			$pv_sum = 0.0;
			$v_sum  = 0.0;

			for ( $j = $start; $j <= $i; $j++ ) {
				$row = $rows[ $j ];
				if ( ! is_array( $row ) ) {
					continue;
				}
				$high   = self::row_value( $row, 'high' );
				$low    = self::row_value( $row, 'low' );
				$close  = self::row_value( $row, 'close' );
				$volume = self::row_value( $row, 'volume' );

				if ( null === $high || null === $low || null === $close || null === $volume || $volume <= 0 ) {
					continue;
				}

				$typical = ( $high + $low + $close ) / 3.0;
				$pv_sum += $typical * $volume;
				$v_sum  += $volume;
			}

			if ( $v_sum > 0 ) {
				$out[ $i ] = $pv_sum / $v_sum;
			}
		}

		return $out;
	}

	/**
	 * Bollinger Bands (SMA +/- multiplier * population standard deviation).
	 *
	 * @param array $values     Numeric series.
	 * @param int   $period     Period.
	 * @param float $multiplier Standard-deviation multiplier.
	 * @return array|null Map with 'middle', 'upper', 'lower' series.
	 */
	public static function bollinger( $values, $period = 20, $multiplier = 2.0 ) {
		$period     = max( 1, (int) $period );
		$multiplier = (float) $multiplier;
		$values     = array_values( array_map( 'floatval', (array) $values ) );
		$count      = count( $values );

		if ( $count < $period ) {
			return null;
		}

		$middle = self::sma( $values, $period );
		$upper  = array_fill( 0, $count, null );
		$lower  = array_fill( 0, $count, null );

		for ( $i = $period - 1; $i < $count; $i++ ) {
			$window   = array_slice( $values, $i - $period + 1, $period );
			$mean     = $middle[ $i ];
			$variance = 0.0;
			foreach ( $window as $value ) {
				$variance += pow( $value - $mean, 2 );
			}
			$stddev = sqrt( $variance / $period );

			$upper[ $i ] = $mean + ( $multiplier * $stddev );
			$lower[ $i ] = $mean - ( $multiplier * $stddev );
		}

		return array(
			'middle' => $middle,
			'upper'  => $upper,
			'lower'  => $lower,
		);
	}

	/**
	 * Relative Strength Index (Wilder's smoothing).
	 *
	 * @param array $values Numeric series.
	 * @param int   $period Period (default 14).
	 * @return array|null RSI series aligned to input.
	 */
	public static function rsi( $values, $period = 14 ) {
		$period = max( 1, (int) $period );
		$values = array_values( array_map( 'floatval', (array) $values ) );
		$count  = count( $values );

		if ( $count <= $period ) {
			return null;
		}

		$out    = array_fill( 0, $count, null );
		$gains  = 0.0;
		$losses = 0.0;

		for ( $i = 1; $i <= $period; $i++ ) {
			$change = $values[ $i ] - $values[ $i - 1 ];
			if ( $change >= 0 ) {
				$gains += $change;
			} else {
				$losses -= $change;
			}
		}

		$avg_gain       = $gains / $period;
		$avg_loss       = $losses / $period;
		$out[ $period ] = self::rsi_value( $avg_gain, $avg_loss );

		for ( $i = $period + 1; $i < $count; $i++ ) {
			$change = $values[ $i ] - $values[ $i - 1 ];
			$gain   = max( 0.0, $change );
			$loss   = max( 0.0, -$change );

			$avg_gain  = ( ( $avg_gain * ( $period - 1 ) ) + $gain ) / $period;
			$avg_loss  = ( ( $avg_loss * ( $period - 1 ) ) + $loss ) / $period;
			$out[ $i ] = self::rsi_value( $avg_gain, $avg_loss );
		}

		return $out;
	}

	/**
	 * Compute the RSI value from average gain/loss.
	 *
	 * @param float $avg_gain Average gain.
	 * @param float $avg_loss Average loss.
	 * @return float
	 */
	protected static function rsi_value( $avg_gain, $avg_loss ) {
		if ( 0.0 === $avg_loss ) {
			return 100.0;
		}
		if ( 0.0 === $avg_gain ) {
			return 0.0;
		}

		$rs = $avg_gain / $avg_loss;

		return 100.0 - ( 100.0 / ( 1.0 + $rs ) );
	}

	/**
	 * MACD (12/26/9 by default).
	 *
	 * @param array $values Numeric series.
	 * @param int   $fast   Fast EMA period.
	 * @param int   $slow   Slow EMA period.
	 * @param int   $signal Signal EMA period.
	 * @return array|null Map with 'macd', 'signal', 'histogram' series.
	 */
	public static function macd( $values, $fast = 12, $slow = 26, $signal = 9 ) {
		$values = array_values( array_map( 'floatval', (array) $values ) );
		$count  = count( $values );

		$ema_fast = self::ema( $values, max( 1, (int) $fast ) );
		$ema_slow = self::ema( $values, max( 1, (int) $slow ) );

		if ( null === $ema_fast || null === $ema_slow ) {
			return null;
		}

		$macd = array_fill( 0, $count, null );
		for ( $i = 0; $i < $count; $i++ ) {
			if ( null !== $ema_fast[ $i ] && null !== $ema_slow[ $i ] ) {
				$macd[ $i ] = $ema_fast[ $i ] - $ema_slow[ $i ];
			}
		}

		// Signal line: EMA of the non-null MACD tail.
		$macd_values = array_values(
			array_filter(
				$macd,
				function ( $value ) {
					return null !== $value;
				}
			)
		);

		$signal = self::ema( $macd_values, max( 1, (int) $signal ) );

		$signal_series = array_fill( 0, $count, null );
		$histogram     = array_fill( 0, $count, null );

		if ( null !== $signal ) {
			$offset = $count - count( $signal );
			foreach ( $signal as $index => $value ) {
				$signal_series[ $offset + $index ] = $value;
				if ( null !== $macd[ $offset + $index ] ) {
					$histogram[ $offset + $index ] = $macd[ $offset + $index ] - $value;
				}
			}
		}

		return array(
			'macd'      => $macd,
			'signal'    => $signal_series,
			'histogram' => $histogram,
		);
	}

	/**
	 * Last non-null value of a series.
	 *
	 * @param array $series Series.
	 * @return float|null
	 */
	public static function last_value( $series ) {
		if ( ! is_array( $series ) ) {
			return null;
		}

		$reversed = array_reverse( $series );
		foreach ( $reversed as $value ) {
			if ( null !== $value ) {
				return (float) $value;
			}
		}

		return null;
	}

	/**
	 * Compute a set of indicators over OHLCV rows.
	 *
	 * @param array $rows       OHLCV rows (normalized or yfinance-style keys).
	 * @param array $indicators Indicator slugs to compute.
	 * @return array Map of slug => array( series, latest, signal ).
	 */
	public static function compute( $rows, $indicators ) {
		$rows       = array_values( (array) $rows );
		$indicators = array_map( 'sanitize_key', (array) $indicators );
		$closes     = self::extract_closes( $rows );
		$output     = array();

		foreach ( $indicators as $slug ) {
			$result = null;

			switch ( $slug ) {
				case 'sma':
					$result = self::sma( $closes, 20 );
					break;

				case 'ema':
					$result = self::ema( $closes, 20 );
					break;

				case 'vwap':
					$result = self::vwap( $rows, 0 );
					break;

				case 'bollinger':
					$bands  = self::bollinger( $closes, 20, 2.0 );
					$result = null === $bands ? null : $bands;
					break;

				case 'rsi':
					$result = self::rsi( $closes, 14 );
					break;

				case 'macd':
					$result = self::macd( $closes, 12, 26, 9 );
					break;

				default:
					continue 2;
			}

			/**
			 * Filter a computed indicator result.
			 *
			 * @since 1.1.80
			 *
			 * @param mixed $result Indicator result (null on insufficient data).
			 * @param array $rows   Source OHLCV rows.
			 */
			$result = apply_filters( 'wp_mcp_ai_indicator_' . $slug, $result, $rows );

			$output[ $slug ] = self::summarize_indicator( $slug, $result, $closes );
		}

		return $output;
	}

	/**
	 * Build the compact {series, latest, signal} summary for an indicator.
	 *
	 * @param string $slug   Indicator slug.
	 * @param mixed  $result Raw indicator result.
	 * @param array  $closes Close series.
	 * @return array
	 */
	protected static function summarize_indicator( $slug, $result, $closes ) {
		$summary = array(
			'series' => $result,
			'latest' => null,
			'signal' => '',
		);

		if ( null === $result ) {
			$summary['signal'] = 'insufficient_data';
			return $summary;
		}

		$price = self::last_value( $closes );

		switch ( $slug ) {
			case 'sma':
			case 'ema':
				$latest            = self::last_value( $result );
				$summary['latest'] = $latest;
				if ( null !== $price && null !== $latest ) {
					$summary['signal'] = $price > $latest ? 'above' : 'below';
				}
				break;

			case 'vwap':
				$latest            = self::last_value( $result );
				$summary['latest'] = $latest;
				if ( null !== $price && null !== $latest ) {
					$summary['signal'] = $price > $latest ? 'above' : 'below';
				}
				break;

			case 'bollinger':
				$upper             = self::last_value( isset( $result['upper'] ) ? $result['upper'] : null );
				$lower             = self::last_value( isset( $result['lower'] ) ? $result['lower'] : null );
				$middle            = self::last_value( isset( $result['middle'] ) ? $result['middle'] : null );
				$summary['latest'] = array(
					'upper'  => $upper,
					'middle' => $middle,
					'lower'  => $lower,
				);
				if ( null !== $price && null !== $upper && $price >= $upper ) {
					$summary['signal'] = 'above_upper';
				} elseif ( null !== $price && null !== $lower && $price <= $lower ) {
					$summary['signal'] = 'below_lower';
				} else {
					$summary['signal'] = 'inside_bands';
				}
				break;

			case 'rsi':
				$latest            = self::last_value( $result );
				$summary['latest'] = $latest;
				if ( null !== $latest && $latest >= 70 ) {
					$summary['signal'] = 'overbought';
				} elseif ( null !== $latest && $latest <= 30 ) {
					$summary['signal'] = 'oversold';
				} else {
					$summary['signal'] = 'neutral';
				}
				break;

			case 'macd':
				$macd_last         = self::last_value( isset( $result['macd'] ) ? $result['macd'] : null );
				$signal_last       = self::last_value( isset( $result['signal'] ) ? $result['signal'] : null );
				$summary['latest'] = array(
					'macd'      => $macd_last,
					'signal'    => $signal_last,
					'histogram' => self::last_value( isset( $result['histogram'] ) ? $result['histogram'] : null ),
				);
				if ( null !== $macd_last && null !== $signal_last ) {
					$summary['signal'] = $macd_last > $signal_last ? 'bullish_cross' : 'bearish_cross';
				}
				break;
		}

		return $summary;
	}
}
