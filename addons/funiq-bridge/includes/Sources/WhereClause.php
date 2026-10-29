<?php
/**
 * Shared query-clause helpers for the product sources.
 *
 * Both the native and WooCommerce sources accept the same Payload-style
 * where/sort parameters, so the parsing lives here — one set of accepted
 * operators, one set of error-free normalisations.
 *
 * @package FuniqBridge\Sources
 */

namespace FuniqBridge\Sources;

/**
 * Parses and normalises a Payload-style where clause.
 */
final class WhereClause {

	/**
	 * Normalise a raw where param into `[ field => [ operator => value ] ]`.
	 *
	 * Non-array values and unknown structures are dropped silently (the
	 * REST layer already sanitised the JSON shape).
	 *
	 * @param mixed $where Raw where param.
	 * @return array<string, array<string, mixed>>
	 */
	public static function normalize( $where ): array {
		if ( ! is_array( $where ) ) {
			return array();
		}

		$out = array();
		foreach ( $where as $field => $operators ) {
			$field = sanitize_key( (string) $field );
			if ( '' === $field || ! is_array( $operators ) ) {
				continue;
			}
			foreach ( $operators as $operator => $value ) {
				$operator = sanitize_key( (string) $operator );
				if ( '' === $operator ) {
					continue;
				}
				$out[ $field ][ $operator ] = $value;
			}
		}
		return $out;
	}

	/**
	 * True when the clause contains a field/operator pair (used to skip
	 * source-specific work like tax queries).
	 *
	 * @param array<string, array<string, mixed>> $where    Normalized where.
	 * @param string                              $field    Field name.
	 * @param string                              $operator Operator name.
	 * @return bool
	 */
	public static function has( array $where, string $field, string $operator ): bool {
		return isset( $where[ $field ][ $operator ] );
	}

	/**
	 * Read a field/operator value, defaulting when absent.
	 *
	 * @param array<string, array<string, mixed>> $where    Normalized where.
	 * @param string                              $field    Field name.
	 * @param string                              $operator Operator name.
	 * @param mixed                               $default  Fallback value.
	 * @return mixed
	 */
	public static function value( array $where, string $field, string $operator, $default = null ) {
		return self::has( $where, $field, $operator ) ? $where[ $field ][ $operator ] : $default;
	}
}
