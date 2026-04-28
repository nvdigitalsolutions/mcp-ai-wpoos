<?php
/**
 * NV oOS Graphify — Remote Source Interface
 *
 * Contract for every remote-source driver. Drivers fetch nodes and edges
 * from external data sources and reconcile local entities with them.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Interface that every remote-source driver must implement.
 *
 * @since 0.6.0
 */
interface NV_oOS_Graphify_Remote_Source_Interface {

	/**
	 * Returns the unique driver identifier string (e.g. 'wikidata', 'oos_federation').
	 *
	 * @return string
	 */
	public function get_driver_id();

	/**
	 * Returns a human-readable label for this driver.
	 *
	 * @return string
	 */
	public function get_driver_label();

	/**
	 * Set the source-instance config (from DB row / admin form).
	 *
	 * @param array $config Configuration array.
	 * @return void
	 */
	public function set_config( array $config );

	/**
	 * Get the current config array.
	 *
	 * @return array
	 */
	public function get_config();

	/**
	 * Returns string[] of capability flags: 'reconcile', 'fetch_nodes', 'fetch_edges', 'webhooks'.
	 *
	 * @return string[]
	 */
	public function get_capabilities();

	/**
	 * Test connectivity; returns ['success'=>bool,'message'=>string].
	 *
	 * @return array
	 */
	public function test_connection();

	/**
	 * Discover what is available at the remote (returns metadata array).
	 *
	 * @return array
	 */
	public function discover();

	/**
	 * Fetch nodes from the remote; returns array of node arrays compatible with NV_oOS_Graphify_DB::upsert_node().
	 *
	 * @param array $args Optional arguments.
	 * @return array
	 */
	public function fetch_nodes( array $args = array() );

	/**
	 * Fetch edges; returns array of edge arrays compatible with NV_oOS_Graphify_DB::upsert_edge().
	 *
	 * @param array $args Optional arguments.
	 * @return array
	 */
	public function fetch_edges( array $args = array() );

	/**
	 * Given a local node object, attempt reconciliation.
	 *
	 * @param object $local_node Local node object.
	 * @return array ['external_id'=>string,'confidence'=>float,'matched'=>bool]
	 */
	public function reconcile( $local_node );
}
