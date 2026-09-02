<?php
/**
 * Paper Query — Fluent query builder for Paper Store collections.
 *
 * Provides a Laravel-Paper-inspired fluent interface for querying indexed
 * collections. Delegates indexed lookups (tag, status, type, author, date)
 * to WP_MCP_AI_Paper_Index and falls back to full file-scan for free-text.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Paper_Query
 *
 * Immutable-style builder: each where/order_by/limit/offset call returns
 * a new instance. Call get() / first() / count() to execute.
 */
class WP_MCP_AI_Paper_Query {

	/**
	 * Collection name.
	 *
	 * @var string
	 */
	private $collection;

	/**
	 * Repository reference (for record hydration).
	 *
	 * @var WP_MCP_AI_Paper_Repository
	 */
	private $repository;

	/**
	 * Index reference.
	 *
	 * @var WP_MCP_AI_Paper_Index
	 */
	private $index;

	/**
	 * Accumulated where clauses.
	 *
	 * Each clause: array( 'field', 'operator', 'value' ).
	 *
	 * @var array
	 */
	private $wheres = array();

	/**
	 * Order-by field.
	 *
	 * @var string|null
	 */
	private $order_by_field = null;

	/**
	 * Order direction ('asc' or 'desc').
	 *
	 * @var string
	 */
	private $order_direction = 'asc';

	/**
	 * Result limit.
	 *
	 * @var int|null
	 */
	private $limit_val = null;

	/**
	 * Result offset.
	 *
	 * @var int
	 */
	private $offset_val = 0;

	/**
	 * Constructor.
	 *
	 * @param string                     $collection Collection name.
	 * @param WP_MCP_AI_Paper_Repository $repository Repository for hydration.
	 * @param WP_MCP_AI_Paper_Index      $index      Index instance.
	 */
	public function __construct( $collection, WP_MCP_AI_Paper_Repository $repository, WP_MCP_AI_Paper_Index $index ) {
		$this->collection  = sanitize_key( $collection );
		$this->repository  = $repository;
		$this->index       = $index;
	}

	/**
	 * Clone method to enable immutable builder pattern.
	 */
	public function __clone() {
		// Deep-clone the wheres array.
		$this->wheres = array_map(
			function ( $w ) {
				return $w;
			},
			$this->wheres
		);
	}

	/**
	 * Add a WHERE clause.
	 *
	 * Supported fields: 'id', 'title', 'tags', 'status', 'type', 'author_id', 'created_at', 'updated_at'.
	 * Supported operators: '=', '!=', 'IN', 'NOT IN', 'LIKE', '>', '<', '>=', '<='.
	 *
	 * @param string $field    Field name.
	 * @param string $operator Comparison operator.
	 * @param mixed  $value    Comparison value.
	 * @return WP_MCP_AI_Paper_Query New query instance.
	 */
	public function where( $field, $operator, $value ) {
		$clone         = clone $this;
		$clone->wheres[] = array(
			'field'    => sanitize_key( $field ),
			'operator' => strtoupper( trim( $operator ) ),
			'value'    => $value,
		);
		return $clone;
	}

	/**
	 * Add a WHERE IN clause.
	 *
	 * @param string $field  Field name.
	 * @param array  $values Array of values.
	 * @return WP_MCP_AI_Paper_Query New query instance.
	 */
	public function where_in( $field, array $values ) {
		return $this->where( $field, 'IN', $values );
	}

	/**
	 * Set the order-by field and direction.
	 *
	 * @param string $field     Field name.
	 * @param string $direction 'asc' or 'desc'.
	 * @return WP_MCP_AI_Paper_Query New query instance.
	 */
	public function order_by( $field, $direction = 'asc' ) {
		$clone                  = clone $this;
		$clone->order_by_field  = sanitize_key( $field );
		$clone->order_direction = 'desc' === strtolower( $direction ) ? 'desc' : 'asc';
		return $clone;
	}

	/**
	 * Set the result limit.
	 *
	 * @param int $num Maximum results.
	 * @return WP_MCP_AI_Paper_Query New query instance.
	 */
	public function limit( $num ) {
		$clone           = clone $this;
		$clone->limit_val = absint( $num );
		return $clone;
	}

	/**
	 * Set the result offset.
	 *
	 * @param int $num Offset.
	 * @return WP_MCP_AI_Paper_Query New query instance.
	 */
	public function offset( $num ) {
		$clone            = clone $this;
		$clone->offset_val = absint( $num );
		return $clone;
	}

	/**
	 * Execute the query and return all matching records.
	 *
	 * @return array Array of record arrays.
	 */
	public function get() {
		$ids = $this->resolve_ids();

		if ( empty( $ids ) ) {
			return array();
		}

		$records = array();
		foreach ( $ids as $id ) {
			$record = $this->repository->find( $id );
			if ( null !== $record && ! is_wp_error( $record ) ) {
				$records[] = $record;
			}
		}

		// Apply post-query filters for fields not in the index.
		$records = $this->apply_post_filters( $records );

		// Apply ordering.
		$records = $this->apply_ordering( $records );

		// Apply limit/offset.
		if ( null !== $this->limit_val || $this->offset_val > 0 ) {
			$offset  = $this->offset_val;
			$length  = null !== $this->limit_val ? $this->limit_val : null;
			$records = array_slice( $records, $offset, $length );
		}

		return $records;
	}

	/**
	 * Execute the query and return the first matching record.
	 *
	 * @return array|null Record array or null if none found.
	 */
	public function first() {
		$results = $this->limit( 1 )->get();
		return ! empty( $results ) ? $results[0] : null;
	}

	/**
	 * Execute the query and return the count of matching records.
	 *
	 * @return int
	 */
	public function count() {
		$ids = $this->resolve_ids();

		if ( empty( $ids ) ) {
			return 0;
		}

		$records = array();
		foreach ( $ids as $id ) {
			$record = $this->repository->find( $id );
			if ( null !== $record && ! is_wp_error( $record ) ) {
				$records[] = $record;
			}
		}

		$records = $this->apply_post_filters( $records );
		return count( $records );
	}

	/**
	 * Resolve record IDs from the index based on where clauses.
	 *
	 * @return string[] Array of record IDs.
	 */
	private function resolve_ids() {
		if ( empty( $this->wheres ) ) {
			return array_keys( $this->index->get_all_record_ids() );
		}

		$result_sets = array();

		foreach ( $this->wheres as $where ) {
			$field    = $where['field'];
			$operator = $where['operator'];
			$value    = $where['value'];

			$ids = $this->resolve_ids_for_clause( $field, $operator, $value );

			if ( null !== $ids ) {
				$result_sets[] = $ids;
			}
			// null means "can't resolve from index — handle in post-filter".
		}

		if ( empty( $result_sets ) ) {
			// No index-resolvable clauses — return all IDs for post-filtering.
			return array_keys( $this->index->get_all_record_ids() );
		}

		// Intersect all result sets (AND logic).
		$result = $result_sets[0];
		for ( $i = 1, $len = count( $result_sets ); $i < $len; $i++ ) {
			$result = array_intersect( $result, $result_sets[ $i ] );
		}

		return array_values( $result );
	}

	/**
	 * Resolve record IDs for a single where clause from the index.
	 *
	 * @param string $field    Field name.
	 * @param string $operator Operator.
	 * @param mixed  $value    Value.
	 * @return string[]|null Array of IDs or null if not index-resolvable.
	 */
	private function resolve_ids_for_clause( $field, $operator, $value ) {
		switch ( $field ) {
			case 'tags':
				if ( '=' === $operator || 'IN' === $operator ) {
					$tag   = is_array( $value ) ? reset( $value ) : $value;
					return $this->index->find_by_tag( $tag );
				}
				return null;

			case 'status':
				if ( '=' === $operator ) {
					return $this->index->find_by_status( $value );
				}
				if ( 'IN' === $operator && is_array( $value ) ) {
					$ids = array();
					foreach ( $value as $v ) {
						$ids = array_merge( $ids, $this->index->find_by_status( $v ) );
					}
					return array_unique( $ids );
				}
				return null;

			case 'type':
				if ( '=' === $operator ) {
					return $this->index->find_by_type( $value );
				}
				return null;

			case 'author_id':
				if ( '=' === $operator ) {
					return $this->index->find_by_author( (int) $value );
				}
				return null;

			case 'created_at':
			case 'updated_at':
				if ( '=' === $operator || '>' === $operator || '<' === $operator || '>=' === $operator || '<=' === $operator ) {
					$ts     = strtotime( $value );
					if ( false !== $ts ) {
						$bucket = gmdate( 'Y-m', $ts );
						return $this->index->find_by_date_bucket( $bucket );
					}
				}
				return null;

			default:
				// Fields not in index — handled by post-filter.
				return null;
		}
	}

	/**
	 * Apply post-query filters for fields not in the index.
	 *
	 * @param array $records Array of record arrays.
	 * @return array Filtered records.
	 */
	private function apply_post_filters( array $records ) {
		foreach ( $this->wheres as $where ) {
			$field    = $where['field'];
			$operator = $where['operator'];
			$value    = $where['value'];

			$records = array_filter(
				$records,
				function ( $record ) use ( $field, $operator, $value ) {
					return $this->evaluate_clause( $record, $field, $operator, $value );
				}
			);
		}

		return array_values( $records );
	}

	/**
	 * Evaluate a single where clause against a record.
	 *
	 * @param array  $record   Record array.
	 * @param string $field    Field name.
	 * @param string $operator Operator.
	 * @param mixed  $value    Value.
	 * @return bool True if the clause matches.
	 */
	private function evaluate_clause( array $record, $field, $operator, $value ) {
		$record_value = isset( $record[ $field ] ) ? $record[ $field ] : null;

		switch ( $operator ) {
			case '=':
				if ( is_array( $record_value ) ) {
					return in_array( $value, $record_value, true );
				}
				return $record_value == $value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Intentional loose comparison for string/int flexibility.

			case '!=':
				if ( is_array( $record_value ) ) {
					return ! in_array( $value, $record_value, true );
				}
				return $record_value != $value; // phpcs:ignore WordPress.PHP.StrictComparisons.LooseComparison -- Intentional loose comparison.

			case 'IN':
				if ( ! is_array( $value ) ) {
					return false;
				}
				if ( is_array( $record_value ) ) {
					return ! empty( array_intersect( $record_value, $value ) );
				}
				return in_array( $record_value, $value, true );

			case 'NOT IN':
				if ( ! is_array( $value ) ) {
					return true;
				}
				if ( is_array( $record_value ) ) {
					return empty( array_intersect( $record_value, $value ) );
				}
				return ! in_array( $record_value, $value, true );

			case 'LIKE':
				if ( null === $record_value ) {
					return false;
				}
				// Simple substring match (no SQL wildcard syntax).
				return false !== stripos( (string) $record_value, (string) $value );

			case '>':
				return $record_value > $value;

			case '<':
				return $record_value < $value;

			case '>=':
				return $record_value >= $value;

			case '<=':
				return $record_value <= $value;

			default:
				return false;
		}
	}

	/**
	 * Apply ordering to records.
	 *
	 * @param array $records Array of record arrays.
	 * @return array Ordered records.
	 */
	private function apply_ordering( array $records ) {
		if ( null === $this->order_by_field || empty( $records ) ) {
			return $records;
		}

		$field     = $this->order_by_field;
		$direction = $this->order_direction;

		usort(
			$records,
			function ( $a, $b ) use ( $field, $direction ) {
				$va = isset( $a[ $field ] ) ? $a[ $field ] : null;
				$vb = isset( $b[ $field ] ) ? $b[ $field ] : null;

				if ( $va === $vb ) {
					return 0;
				}

				$cmp = ( $va < $vb ) ? -1 : 1;

				return 'desc' === $direction ? -$cmp : $cmp;
			}
		);

		return $records;
	}
}
