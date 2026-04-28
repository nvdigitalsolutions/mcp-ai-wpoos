<?php
/**
 * NV oOS Graphify — Entity Resolver
 *
 * Cross-source entity resolution by canonical keys. Given a freshly-ingested
 * remote node and a list of canonical-key paths, the resolver:
 *
 *   1. Extracts canonical-key values from the node's properties
 *   2. Searches existing nodes for matching canonical-key values
 *   3. Emits SAME_AS edges between the new node and any existing matches
 *
 * Supported canonical keys (extensible via the
 * `nvoos_graphify_canonical_key_paths` filter):
 *
 *   email, phone, url, sku, gtin, isbn, ean, upc, mpn
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Cross-source entity resolver.
 *
 * @since 0.7.0
 */
class NV_oOS_Graphify_Entity_Resolver {

	/**
	 * Default canonical-key normalisers, keyed by canonical-key name.
	 *
	 * @var array<string,callable>|null
	 */
	private static $normalizers = null;

	/**
	 * Default list of canonical key names.
	 *
	 * @return string[]
	 */
	public static function default_canonical_keys() {
		return array( 'email', 'phone', 'url', 'sku', 'gtin', 'isbn', 'ean', 'upc', 'mpn' );
	}

	/**
	 * Normalise a canonical-key value for comparison.
	 *
	 * Returns '' when the value is unusable (empty, malformed).
	 *
	 * @since 0.7.0
	 *
	 * @param string $key   Canonical-key name (email, phone, url, sku, ...).
	 * @param mixed  $value Raw value from a node's properties.
	 * @return string Normalised value (empty string when unusable).
	 */
	public static function normalize( $key, $value ) {
		$key = sanitize_key( $key );
		if ( ! is_scalar( $value ) ) {
			return '';
		}
		$value = (string) $value;
		$value = trim( $value );
		if ( '' === $value ) {
			return '';
		}
		self::init_normalizers();
		if ( isset( self::$normalizers[ $key ] ) ) {
			return call_user_func( self::$normalizers[ $key ], $value );
		}
		return strtolower( $value );
	}

	/**
	 * Extract a map of canonical_key => normalised_value from a node array.
	 *
	 * Looks for properties named exactly after each canonical key, plus a
	 * filterable list of alternative property paths via
	 * `nvoos_graphify_canonical_key_paths`.
	 *
	 * @since 0.7.0
	 *
	 * @param array $node Node array as accepted by upsert_node().
	 * @return array<string,string> Map of canonical key => normalised value (empty values stripped).
	 */
	public static function extract_canonical_keys( array $node ) {
		$properties = isset( $node['properties'] ) && is_array( $node['properties'] ) ? $node['properties'] : array();

		// Allow third-party extension of the canonical-key list and per-key path lookups.
		/**
		 * Filter the property paths used for each canonical key.
		 *
		 * @since 0.7.0
		 *
		 * @param array $paths Map of canonical_key => array<string> of property names to check, in priority order.
		 */
		$paths = apply_filters(
			'nvoos_graphify_canonical_key_paths',
			array(
				'email' => array( 'email' ),
				'phone' => array( 'phone', 'telephone' ),
				'url'   => array( 'url', 'permalink', 'canonical_url' ),
				'sku'   => array( 'sku' ),
				'gtin'  => array( 'gtin', 'gtin13', 'gtin14' ),
				'isbn'  => array( 'isbn', 'isbn10', 'isbn13' ),
				'ean'   => array( 'ean' ),
				'upc'   => array( 'upc' ),
				'mpn'   => array( 'mpn', 'manufacturer_part_number' ),
			)
		);

		$result = array();
		foreach ( $paths as $key => $candidates ) {
			$key = sanitize_key( $key );
			if ( ! is_array( $candidates ) ) {
				continue;
			}
			foreach ( $candidates as $candidate ) {
				if ( isset( $properties[ $candidate ] ) ) {
					$normalised = self::normalize( $key, $properties[ $candidate ] );
					if ( '' !== $normalised ) {
						$result[ $key ] = $normalised;
						break;
					}
				}
			}
		}

		// Also consider top-level url field on the node.
		if ( ! isset( $result['url'] ) && ! empty( $node['url'] ) ) {
			$normalised = self::normalize( 'url', $node['url'] );
			if ( '' !== $normalised ) {
				$result['url'] = $normalised;
			}
		}

		return $result;
	}

	/**
	 * Find existing node IDs that share at least one canonical key value with
	 * the supplied keys, excluding the supplied node_id itself.
	 *
	 * Backed by a LIKE search against the JSON-encoded properties column. This
	 * is intentionally simple — sites with very large graphs should add a
	 * dedicated canonical-keys index in a follow-up. For typical Graphify
	 * graphs (thousands of nodes), this is fast enough.
	 *
	 * @since 0.7.0
	 *
	 * @param array  $canonical_keys      Map of canonical_key => normalised value.
	 * @param string $exclude_node_id     Node ID to exclude from results.
	 * @param int    $limit               Max matches to return per key.
	 * @return string[] Distinct list of matched node IDs.
	 */
	public static function find_matches( array $canonical_keys, $exclude_node_id = '', $limit = 25 ) {
		global $wpdb;
		if ( empty( $canonical_keys ) ) {
			return array();
		}
		$exclude_node_id = sanitize_text_field( (string) $exclude_node_id );
		$limit           = max( 1, min( 200, absint( $limit ) ) );
		$table           = NV_oOS_Graphify_DB::nodes_table();

		$matches = array();
		foreach ( $canonical_keys as $key => $value ) {
			if ( '' === (string) $value ) {
				continue;
			}
			// Build a LIKE pattern that matches the JSON-encoded value, e.g. "email":"alice@example.com".
			$json_fragment = '"' . $key . '":"' . str_replace( array( '\\', '"' ), array( '\\\\', '\\"' ), (string) $value ) . '"';
			$like          = '%' . $wpdb->esc_like( $json_fragment ) . '%';

			// phpcs:disable WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$rows = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT node_id FROM {$table} WHERE properties LIKE %s AND node_id <> %s LIMIT %d",
					$like,
					$exclude_node_id,
					$limit
				)
			);
			// phpcs:enable WordPress.DB.PreparedSQL.InterpolatedNotPrepared

			if ( is_array( $rows ) ) {
				foreach ( $rows as $node_id ) {
					$matches[ (string) $node_id ] = true;
				}
			}
		}

		return array_keys( $matches );
	}

	/**
	 * Emit SAME_AS edges from $node to every match in $matches.
	 *
	 * @since 0.7.0
	 *
	 * @param string   $node_id      Source node ID (the freshly ingested one).
	 * @param string[] $matches      Existing node IDs that match canonical keys.
	 * @param string   $source_slug  Source slug for provenance.
	 * @return int Number of edges created.
	 */
	public static function emit_same_as_edges( $node_id, array $matches, $source_slug = '' ) {
		$node_id     = sanitize_text_field( (string) $node_id );
		$source_slug = sanitize_key( (string) $source_slug );
		if ( '' === $node_id || empty( $matches ) ) {
			return 0;
		}
		$count = 0;
		foreach ( $matches as $target ) {
			$target = sanitize_text_field( (string) $target );
			if ( '' === $target || $target === $node_id ) {
				continue;
			}
			$result = NV_oOS_Graphify_DB::upsert_edge(
				array(
					'source_node_id' => $node_id,
					'target_node_id' => $target,
					'relation'       => 'SAME_AS',
					'confidence'     => 1.0,
					'provenance'     => 'RESOLVED',
					'source_slug'    => $source_slug,
				)
			);
			if ( $result ) {
				++$count;
			}
		}
		return $count;
	}

	/**
	 * Convenience: resolve a node end-to-end (extract → find → emit).
	 *
	 * @since 0.7.0
	 *
	 * @param array  $node        Node array.
	 * @param string $source_slug Source slug.
	 * @return int Number of SAME_AS edges created.
	 */
	public static function resolve_node( array $node, $source_slug = '' ) {
		if ( empty( $node['node_id'] ) ) {
			return 0;
		}
		$keys = self::extract_canonical_keys( $node );
		if ( empty( $keys ) ) {
			return 0;
		}
		$matches = self::find_matches( $keys, (string) $node['node_id'] );
		if ( empty( $matches ) ) {
			return 0;
		}
		return self::emit_same_as_edges( (string) $node['node_id'], $matches, (string) $source_slug );
	}

	/**
	 * Initialise the canonical-key normaliser callbacks.
	 *
	 * @return void
	 */
	private static function init_normalizers() {
		if ( null !== self::$normalizers ) {
			return;
		}
		self::$normalizers = array(
			'email' => static function ( $v ) {
				$v = strtolower( trim( (string) $v ) );
				return is_email( $v ) ? $v : '';
			},
			'phone' => static function ( $v ) {
				// Strip everything except digits and a leading + for E.164-ish comparison.
				$v = preg_replace( '/(?!^\+)[^0-9]/', '', (string) $v );
				return ( null === $v || strlen( $v ) < 7 ) ? '' : $v;
			},
			'url'   => static function ( $v ) {
				$v = strtolower( trim( (string) $v ) );
				$v = preg_replace( '#^https?://#', '', $v );
				$v = rtrim( (string) $v, '/' );
				return $v;
			},
			'sku'   => static function ( $v ) {
				return strtoupper( preg_replace( '/\s+/', '', (string) $v ) );
			},
			'gtin'  => static function ( $v ) {
				return preg_replace( '/[^0-9]/', '', (string) $v );
			},
			'isbn'  => static function ( $v ) {
				return strtoupper( preg_replace( '/[^0-9X]/i', '', (string) $v ) );
			},
			'ean'   => static function ( $v ) {
				return preg_replace( '/[^0-9]/', '', (string) $v );
			},
			'upc'   => static function ( $v ) {
				return preg_replace( '/[^0-9]/', '', (string) $v );
			},
			'mpn'   => static function ( $v ) {
				return strtoupper( preg_replace( '/\s+/', '', (string) $v ) );
			},
		);
	}
}
