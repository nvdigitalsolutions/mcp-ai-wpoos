<?php
/**
 * NV oOS Graphify — Abstract Remote Source Base
 *
 * Optional base class for remote-source drivers that adds capability-flag
 * support and convenience defaults on top of the
 * NV_oOS_Graphify_Remote_Source_Interface contract. Existing drivers that
 * implement the interface directly continue to work unchanged.
 *
 * Capability flags advertised here are independent of the legacy capability
 * strings ('reconcile', 'fetch_nodes', 'fetch_edges', 'webhooks') returned by
 * get_capabilities(); the new flags describe *how* the driver fetches data so
 * the scheduler and admin UI can render appropriate options.
 *
 * @package NV_oOS_Graphify
 * @since   0.7.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Abstract base class for remote-source drivers.
 *
 * @since 0.7.0
 */
abstract class NV_oOS_Graphify_Remote_Source_Base implements NV_oOS_Graphify_Remote_Source_Interface {

	/**
	 * Driver configuration.
	 *
	 * @var array
	 */
	protected $config = array();

	/**
	 * {@inheritdoc}
	 *
	 * @param array $config Driver configuration array.
	 */
	public function set_config( array $config ) {
		$this->config = $config;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_config() {
		return $this->config;
	}

	/**
	 * Default discover() implementation — returns driver metadata.
	 *
	 * @return array
	 */
	public function discover() {
		return array(
			'driver'           => $this->get_driver_id(),
			'label'            => $this->get_driver_label(),
			'capabilities'     => $this->get_capabilities(),
			'capability_flags' => $this->get_capability_flags(),
		);
	}

	/**
	 * Default reconcile() — drivers that don't support reconciliation return
	 * an unmatched result.
	 *
	 * @param object $local_node Unused.
	 * @return array
	 */
	public function reconcile( $local_node ) {
		return array(
			'external_id' => '',
			'confidence'  => 0.0,
			'matched'     => false,
		);
	}

	/**
	 * Default fetch_edges() — most drivers don't expose edges directly.
	 *
	 * @param array $args Unused.
	 * @return array
	 */
	public function fetch_edges( array $args = array() ) {
		return array();
	}

	/**
	 * Return the set of capability flags advertised by this driver.
	 *
	 * Recognised flag keys:
	 *   - supports_incremental  : driver can resume from a watermark
	 *   - supports_webhooks     : driver can receive push notifications
	 *   - supports_oauth        : driver authenticates via OAuth2
	 *   - supports_pagination   : driver paginates large result sets
	 *   - supports_relationships: driver emits edges, not just nodes
	 *
	 * Values are booleans. Drivers override to describe themselves.
	 *
	 * @since 0.7.0
	 *
	 * @return array<string,bool>
	 */
	public function get_capability_flags() {
		return array(
			'supports_incremental'   => false,
			'supports_webhooks'      => false,
			'supports_oauth'         => false,
			'supports_pagination'    => false,
			'supports_relationships' => false,
		);
	}

	/**
	 * Helper: return the source slug from config (or a fallback).
	 *
	 * @return string
	 */
	protected function get_slug() {
		return isset( $this->config['_slug'] ) ? sanitize_key( $this->config['_slug'] ) : sanitize_key( $this->get_driver_id() );
	}
}
