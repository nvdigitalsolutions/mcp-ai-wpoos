<?php
declare(strict_types=1);

namespace NvoosContentGraph\Remote;

use NvoosContentGraph\Graph\Db;
use NvoosContentGraph\Plugin;
use NvoosContentGraph\Schema;
use NvoosContentGraph\Settings;
use WP_Error;
use function absint;
use function array_slice;
use function class_exists;
use function delete_transient;
use function get_transient;
use function in_array;
use function sanitize_key;
use function set_transient;
use function time;
use function wp_next_scheduled;
use function wp_schedule_single_event;

/**
 * Orchestrates remote source enrichment for the knowledge graph.
 *
 * Iterates active remote sources, reconciles local nodes via
 * 'reconcile'-capable drivers, and imports new nodes/edges from
 * 'fetch_nodes'/'fetch_edges'-capable drivers.
 *
 * @since 1.0.0
 */
class Enricher {

	/** @var float Minimum confidence threshold for reconciliation. */
	private const MIN_CONFIDENCE = 0.6;

	/**
	 * Enrich a set of freshly-extracted nodes by reconciling them with remote
	 * sources and importing remote nodes.
	 *
	 * @param array<int,array<string,mixed>> $nodes Array of node arrays.
	 * @param array<string,mixed>            $args  Optional: 'async' => bool.
	 * @return array<string,int> Summary: ['sameAs_edges'=>int,'remote_nodes'=>int,'reconciled'=>int]
	 */
	public static function enrich( array $nodes, array $args = array() ): array {
		$async   = ! empty( $args['async'] );
		$summary = array(
			'sameAs_edges' => 0,
			'remote_nodes' => 0,
			'reconciled'   => 0,
		);

		if ( $async ) {
			// Schedule async enrichment and return immediately.
			wp_schedule_single_event( time() + 10, Schema::CRON_ENRICH );
			return $summary;
		}

		$registry = Plugin::instance()->getRemoteRegistry();
		$sources  = $registry->getActiveSources();

		if ( empty( $sources ) ) {
			return $summary;
		}

		$budget = absint( Settings::get( 'remote_enrich_budget', 50 ) );

		foreach ( $sources as $slug => $source ) {
			$slug = sanitize_key( $slug );

			// Skip if circuit is open.
			$dbSource = Db::getRemoteSource( $slug );
			if ( $dbSource && 'open' === ( $dbSource->circuit_state ?? null ) ) {
				continue;
			}

			// Acquire lock.
			$lockKey = 'nvoos_content_graph_syncing_' . $slug;
			if ( get_transient( $lockKey ) ) {
				continue;
			}
			set_transient( $lockKey, 1, 120 );

			$capabilities = $source->getCapabilities();

			// Reconcile local entity nodes.
			if ( in_array( 'reconcile', $capabilities, true ) ) {
				foreach ( $nodes as $nodeArray ) {
					$type = $nodeArray['type'] ?? '';
					if ( ! in_array( $type, array( 'entity', 'topic', 'person', 'organization', 'place', 'concept' ), true ) ) {
						continue;
					}
					$nodeObj = (object) $nodeArray;
					$result  = $source->reconcile( $nodeObj );

					if ( empty( $result['matched'] ) || ( $result['confidence'] ?? 0.0 ) < self::MIN_CONFIDENCE ) {
						continue;
					}

					$nodeId = $nodeArray['node_id'] ?? '';
					if ( empty( $nodeId ) ) {
						continue;
					}

					// Update external_id on local node.
					Db::updateNodeExternalId( $nodeId, $result['external_id'] );

					// Create sameAs edge to remote entity node.
					$remoteNodeId = 'remote_' . sanitize_key( $slug ) . '_' . sanitize_key( $result['external_id'] );
					Db::upsertEdge(
						array(
							'source_node_id' => $nodeId,
							'target_node_id' => $remoteNodeId,
							'relation'       => 'SAME_AS',
							'confidence'     => (float) ( $result['confidence'] ?? 1.0 ),
							'provenance'     => 'FEDERATED',
							'source_slug'    => $slug,
						)
					);
					++$summary['sameAs_edges'];
					++$summary['reconciled'];
				}
			}

			// Import remote nodes.
			if ( in_array( 'fetch_nodes', $capabilities, true ) ) {
				$remoteNodes = $source->fetchNodes( array( 'limit' => $budget ) );
				$count       = 0;
				foreach ( $remoteNodes as $rn ) {
					if ( $count >= $budget ) {
						break;
					}
					Db::upsertNode( $rn );
					++$summary['remote_nodes'];
					++$count;
				}
			}

			// Import remote edges.
			if ( in_array( 'fetch_edges', $capabilities, true ) ) {
				$remoteEdges = $source->fetchEdges( array( 'limit' => $budget ) );
				foreach ( array_slice( $remoteEdges, 0, $budget ) as $re ) {
					Db::upsertEdge( $re );
				}
			}

			// Release lock and update sync timestamp.
			delete_transient( $lockKey );
			Db::updateRemoteSourceSync( $slug );
		}

		Db::setMeta( 'last_enrich_summary', $summary );

		return $summary;
	}

	/**
	 * Sync a single named remote source by slug.
	 *
	 * @param string $slug  Source slug.
	 * @param bool   $async When true, schedules a background cron event
	 *                      instead of running synchronously.
	 * @return array<string,mixed>|WP_Error Summary array or WP_Error.
	 */
	public static function syncSource( string $slug, bool $async = false ) {
		$slug = sanitize_key( $slug );
		if ( empty( $slug ) ) {
			return new WP_Error( 'invalid_slug', __( 'Invalid source slug.', 'nvoos-content-graph' ) );
		}

		$dbSource = Db::getRemoteSource( $slug );
		if ( ! $dbSource ) {
			return new WP_Error( 'not_found', __( 'Remote source not found.', 'nvoos-content-graph' ) );
		}

		if ( $async ) {
			// Delegate to the existing enrich-all cron handler, which iterates
			// every active source.  This source will be picked up alongside any
			// others that are ready to sync.
			if ( ! wp_next_scheduled( Schema::CRON_ENRICH ) ) {
				wp_schedule_single_event( time() + 10, Schema::CRON_ENRICH );
			}
			return array(
				'slug'   => $slug,
				'async'  => true,
				'status' => 'scheduled',
			);
		}

		$registry = Plugin::instance()->getRemoteRegistry();
		$config   = array();
		if ( ! empty( $dbSource->config_json ) ) {
			$decoded = json_decode( $dbSource->config_json, true );
			if ( is_array( $decoded ) ) {
				foreach ( $decoded as $k => $v ) {
					if ( is_string( $v ) && Crypto::isSensitiveKey( $k ) ) {
						$decoded[ $k ] = Crypto::decrypt( $v );
					}
				}
				$config = $decoded;
			}
		}
		$config['_slug']       = $slug;
		$config['_rate_limit'] = absint( $dbSource->rate_limit ?? 0 );

		$source = $registry->getDriverInstance( $dbSource->driver, $config );
		if ( ! $source ) {
			return new WP_Error( 'driver_not_found', __( 'Driver not found or not loaded.', 'nvoos-content-graph' ) );
		}

		// Acquire lock.
		$lockKey = 'nvoos_content_graph_syncing_' . $slug;
		if ( get_transient( $lockKey ) ) {
			return new WP_Error( 'already_syncing', __( 'This source is already being synced.', 'nvoos-content-graph' ) );
		}
		set_transient( $lockKey, 1, 120 );

		$budget       = absint( Settings::get( 'remote_enrich_budget', 50 ) );
		$capabilities = $source->getCapabilities();
		$summary      = array(
			'slug'         => $slug,
			'driver'       => $dbSource->driver,
			'remote_nodes' => 0,
			'remote_edges' => 0,
			'reconciled'   => 0,
			'error'        => null,
		);

		// Fetch nodes.
		if ( in_array( 'fetch_nodes', $capabilities, true ) ) {
			$remoteNodes = $source->fetchNodes( array( 'limit' => $budget ) );
			foreach ( array_slice( $remoteNodes, 0, $budget ) as $rn ) {
				Db::upsertNode( $rn );
				++$summary['remote_nodes'];
			}
		}

		// Fetch edges.
		if ( in_array( 'fetch_edges', $capabilities, true ) ) {
			$remoteEdges = $source->fetchEdges( array( 'limit' => $budget ) );
			foreach ( array_slice( $remoteEdges, 0, $budget ) as $re ) {
				Db::upsertEdge( $re );
				++$summary['remote_edges'];
			}
		}

		// Reconcile with entity-type local nodes.
		if ( in_array( 'reconcile', $capabilities, true ) ) {
			$localNodes = Db::listNodes(
				array(
					'type'  => 'entity',
					'limit' => 100,
				)
			);
			foreach ( $localNodes as $ln ) {
				$result = $source->reconcile( $ln );
				if ( ! empty( $result['matched'] ) && ( $result['confidence'] ?? 0.0 ) >= self::MIN_CONFIDENCE ) {
					Db::updateNodeExternalId( $ln->node_id, $result['external_id'] );
					$remoteNodeId = 'remote_' . sanitize_key( $slug ) . '_' . sanitize_key( $result['external_id'] );
					Db::upsertEdge(
						array(
							'source_node_id' => $ln->node_id,
							'target_node_id' => $remoteNodeId,
							'relation'       => 'SAME_AS',
							'confidence'     => (float) ( $result['confidence'] ?? 1.0 ),
							'provenance'     => 'FEDERATED',
							'source_slug'    => $slug,
						)
					);
					++$summary['reconciled'];
				}
			}
		}

		// Release lock and update DB.
		delete_transient( $lockKey );
		Db::updateRemoteSourceSync( $slug );

		return $summary;
	}

	/**
	 * Enrich all currently stored graph nodes against all active remote sources.
	 *
	 * @param bool $async When true, schedules a background cron event instead of
	 *                    running synchronously.
	 * @return array<string,int> Summary array.
	 */
	public function enrichAll( bool $async = false ): array {
		$nodes   = Db::getAllNodes();
		$summary = self::enrich( is_array( $nodes ) ? $nodes : array(), array( 'async' => $async ) );
		return array(
			'nodes'      => $summary['remote_nodes'] ?? 0,
			'edges'      => $summary['sameAs_edges'] ?? 0,
			'reconciled' => $summary['reconciled'] ?? 0,
		);
	}
}
