<?php
/**
 * Shared sort parsing for the product sources.
 *
 * Accepted values: date (default), -date, price, -price, rating, -rating,
 * name, -name. The `-` prefix flips to ascending.
 *
 * @package FuniqBridge\Sources
 */

namespace FuniqBridge\Sources;

/**
 * Parses the Payload-style sort parameter.
 */
final class SortClause {

	/**
	 * Split a sort string into field + direction.
	 *
	 * @param mixed $sort Raw sort param.
	 * @return array{field: string, direction: string} direction is ASC|DESC.
	 */
	public static function parse( $sort ): array {
		$direction = 'DESC';
		$field     = 'date';

		if ( ! empty( $sort ) && is_string( $sort ) ) {
			$field = $sort;
			if ( '-' === substr( $field, 0, 1 ) ) {
				$direction = 'ASC';
				$field     = substr( $field, 1 );
			}
		}

		$allowed = array( 'date', 'price', 'rating', 'name' );
		if ( ! in_array( $field, $allowed, true ) ) {
			$field = 'date';
		}

		return array(
			'field'     => $field,
			'direction' => $direction,
		);
	}
}
