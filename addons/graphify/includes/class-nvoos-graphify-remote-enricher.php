<?php
/**
 * NV oOS Graphify — Remote Enricher
 *
 * Orchestrates remote enrichment by iterating active remote sources,
 * reconciling local nodes via 'reconcile'-capable drivers, and importing
 * new nodes from 'fetch_nodes'-capable drivers.
 *
 * Called from NV_oOS_Graphify_Builder::build() as pipeline stage 3.5.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Orchestrates remote source enrichment for the knowledge graph.
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Remote_Enricher {

	/**
	 * WP Cron hook for async enrichment.
	 *
	 * @var string
	 */
	const CRON_HOOK = 'nvoos_graphify_cron_enrich_source';

	/**
	 * Minimum confidence threshold for reconciliation.
	 *
	 * @var float
	 */
	const MIN_CONFIDENCE = 0.6;

	/**
	 * Enrich a set of freshly-extracted nodes by reconciling them with remote
	 * sources and importing remote nodes.
	 *
	 * @since 0.6.0
	 *
	 * @param array $nodes Array of node arrays (freshly extracted).
	 * @param array $args  Optional: 'async' => bool.
	 * @return array Summary: ['sameAs_edges'=>int,'remote_nodes'=>int,'reconciled'=>int]
	 */
	public static function enrich( array $nodes, array $args = array() ) {
		$async   = ! empty( $args['async'] );
		$summary = array(
			'sameAs_edges' => 0,
			'remote_nodes' => 0,
			'reconciled'   => 0,
		);

		if ( $async ) {
			// Schedule async enrichment and return immediately.
			wp_schedule_single_event( time() + 10, NV_oOS_Graphify::CRON_ENRICH_HOOK );
			return $summary;
		}

		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$sources  = $registry->get_active_sources();

		if ( empty( $sources ) ) {
			return $summary;
		}

		$settings = NV_oOS_Graphify::get_settings();
		$budget   = isset( $settings['remote_enrich_budget'] ) ? absint( $settings['remote_enrich_budget'] ) : 50;

		foreach ( $sources as $slug => $source ) {
			$slug = sanitize_key( $slug );

			// Skip if circuit is open.
			$db_source = NV_oOS_Graphify_DB::get_remote_source( $slug );
			if ( $db_source && 'open' === $db_source->circuit_state ) {
				continue;
			}

			// Acquire lock.
			$lock_key = 'nvoos_graphify_syncing_' . $slug;
			if ( get_transient( $lock_key ) ) {
				continue;
			}
			set_transient( $lock_key, 1, 120 );

			$capabilities = $source->get_capabilities();

			// Reconcile local entity nodes.
			if ( in_array( 'reconcile', $capabilities, true ) ) {
				foreach ( $nodes as $node_array ) {
					$type = isset( $node_array['type'] ) ? $node_array['type'] : '';
					if ( ! in_array( $type, array( 'entity', 'topic', 'person', 'organization', 'place', 'concept' ), true ) ) {
						continue;
					}
					$node_obj = (object) $node_array;
					$result   = $source->reconcile( $node_obj );

					if ( empty( $result['matched'] ) || $result['confidence'] < self::MIN_CONFIDENCE ) {
						continue;
					}

					$node_id = isset( $node_array['node_id'] ) ? $node_array['node_id'] : '';
					if ( empty( $node_id ) ) {
						continue;
					}

					// Update external_id on local node if not already set.
					$local_node = NV_oOS_Graphify_DB::get_node( $node_id );
					if ( $local_node ) {
						NV_oOS_Graphify_DB::update_node_external_id( $node_id, $result['external_id'] );
					}

					// Create sameAs edge to remote entity node.
					$remote_node_id = 'remote_' . sanitize_key( $slug ) . '_' . sanitize_key( $result['external_id'] );
					NV_oOS_Graphify_DB::upsert_edge( array(
						'source_node_id' => $node_id,
						'target_node_id' => $remote_node_id,
						'relation'       => 'SAME_AS',
						'confidence'     => (float) $result['confidence'],
						'provenance'     => 'FEDERATED',
						'source_slug'    => $slug,
					) );
					$summary['sameAs_edges']++;
					$summary['reconciled']++;
				}
			}

			// Import remote nodes.
			if ( in_array( 'fetch_nodes', $capabilities, true ) ) {
				$remote_nodes = $source->fetch_nodes( array( 'limit' => $budget ) );
				$count        = 0;
				foreach ( $remote_nodes as $rn ) {
					if ( $count >= $budget ) {
						break;
					}
					NV_oOS_Graphify_DB::upsert_node( $rn );
					$summary['remote_nodes']++;
					$count++;
				}
			}

			// Release lock.
			delete_transient( $lock_key );

			// Update last_sync_at.
			NV_oOS_Graphify_DB::update_remote_source_sync( $slug );
		}

		NV_oOS_Graphify_DB::set_meta( 'last_enrich_summary', $summary );

		return $summary;
	}

	/**
	 * Sync a single named remote source by slug.
	 *
	 * @since 0.6.0
	 *
	 * @param string $slug Source slug.
	 * @return array|WP_Error Summary array or WP_Error.
	 */
	public static function sync_source( $slug ) {
		$slug = sanitize_key( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid source slug.', 'nvoos-graphify' ) );
		}

		$db_source = NV_oOS_Graphify_DB::get_remote_source( $slug );
		if ( ! $db_source ) {
			return new WP_Error( 'not_found', __( 'Remote source not found.', 'nvoos-graphify' ) );
		}

		$registry = NV_oOS_Graphify_Remote_Registry::get_instance();
		$config   = array();
		if ( ! empty( $db_source->config_json ) ) {
			$decoded = json_decode( $db_source->config_json, true );
			if ( is_array( $decoded ) ) {
				// Decrypt sensitive values.
				foreach ( $decoded as $k => $v ) {
					if ( is_string( $v ) && ( false !== strpos( $k, 'token' ) || false !== strpos( $k, 'password' ) || false !== strpos( $k, 'secret' ) || false !== strpos( $k, 'key' ) ) ) {
						$decoded[ $k ] = NV_oOS_Graphify_Crypto::decrypt( $v );
					}
				}
				$config = $decoded;
			}
		}
		$config['_slug']        = $slug;
		$config['_rate_limit']  = absint( $db_source->rate_limit );

		$source = $registry->get_driver_instance( $db_source->driver, $config );
		if ( ! $source ) {
			return new WP_Error( 'driver_not_found', __( 'Driver not found or not loaded.', 'nvoos-graphify' ) );
		}

		// Acquire lock.
		$lock_key = 'nvoos_graphify_syncing_' . $slug;
		if ( get_transient( $lock_key ) ) {
			return new WP_Error( 'already_syncing', __( 'This source is already being synced.', 'nvoos-graphify' ) );
		}
		set_transient( $lock_key, 1, 120 );

		$settings     = NV_oOS_Graphify::get_settings();
		$budget       = isset( $settings['remote_enrich_budget'] ) ? absint( $settings['remote_enrich_budget'] ) : 50;
		$capabilities = $source->get_capabilities();
		$summary      = array(
			'slug'         => $slug,
			'driver'       => $db_source->driver,
			'remote_nodes' => 0,
			'remote_edges' => 0,
			'reconciled'   => 0,
			'error'        => null,
		);

		// Fetch nodes.
		if ( in_array( 'fetch_nodes', $capabilities, true ) ) {
			$remote_nodes = $source->fetch_nodes( array( 'limit' => $budget ) );
			foreach ( array_slice( $remote_nodes, 0, $budget ) as $rn ) {
				NV_oOS_Graphify_DB::upsert_node( $rn );
				$summary['remote_nodes']++;
			}
		}

		// Fetch edges.
		if ( in_array( 'fetch_edges', $capabilities, true ) ) {
			$remote_edges = $source->fetch_edges( array( 'limit' => $budget ) );
			foreach ( array_slice( $remote_edges, 0, $budget ) as $re ) {
				NV_oOS_Graphify_DB::upsert_edge( $re );
				$summary['remote_edges']++;
			}
		}

		// Reconcile with entity-type local nodes.
		if ( in_array( 'reconcile', $capabilities, true ) ) {
			$local_nodes = NV_oOS_Graphify_DB::list_nodes( array( 'type' => 'entity', 'limit' => 100 ) );
			foreach ( $local_nodes as $ln ) {
				$result = $source->reconcile( $ln );
				if ( ! empty( $result['matched'] ) && $result['confidence'] >= self::MIN_CONFIDENCE ) {
					NV_oOS_Graphify_DB::update_node_external_id( $ln->node_id, $result['external_id'] );
					$remote_node_id = 'remote_' . sanitize_key( $slug ) . '_' . sanitize_key( $result['external_id'] );
					NV_oOS_Graphify_DB::upsert_edge( array(
						'source_node_id' => $ln->node_id,
						'target_node_id' => $remote_node_id,
						'relation'       => 'SAME_AS',
						'confidence'     => (float) $result['confidence'],
						'provenance'     => 'FEDERATED',
						'source_slug'    => $slug,
					) );
					$summary['reconciled']++;
				}
			}
		}

		// Release lock and update DB.
		delete_transient( $lock_key );
		NV_oOS_Graphify_DB::update_remote_source_sync( $slug );

		return $summary;
	}
}
