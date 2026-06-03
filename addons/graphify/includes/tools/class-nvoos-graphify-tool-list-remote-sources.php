<?php
/**
 * Graphify Tool — List Remote Sources
 *
 * Lists all configured remote source drivers and their current status.
 *
 * @package NV_oOS_Graphify
 * @since   0.6.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool: graphify_list_remote_sources
 *
 * @since 0.6.0
 */
class NV_oOS_Graphify_Tool_List_Remote_Sources implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	use WP_MCP_AI_Tool_Default_Capability;

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/** {@inheritdoc} */
	public function get_slug() {
		return 'graphify_list_remote_sources';
	}

	/** {@inheritdoc} */
	public function get_name() {
		return __( 'List Remote Sources', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_description() {
		return __( 'Returns all configured remote source drivers including their slug, driver type, human-readable label, enabled status, circuit-breaker state, last sync timestamp, last error message, and the capabilities supported by their driver. Use this to discover what remote sources are available before calling graphify_sync_remote_source.', 'nvoos-graphify' );
	}

	/** {@inheritdoc} */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'enabled_only' => array(
					'type'        => 'boolean',
					'description' => __( 'If true, return only enabled sources.', 'nvoos-graphify' ),
					'default'     => false,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/** {@inheritdoc} */
	public function get_capability_flags() {
		return array( 'read-only', 'cacheable' );
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$enabled_only = ! empty( $arguments['enabled_only'] );
		$rows         = NV_oOS_Graphify_DB::list_remote_sources( $enabled_only ? array( 'enabled' => 1 ) : array() );
		$registry     = NV_oOS_Graphify_Remote_Registry::get_instance();

		$sources = array();
		foreach ( $rows as $row ) {
			$driver_instance = $registry->get_driver( $row->driver );
			$capabilities    = array();
			if ( $driver_instance ) {
				$capabilities = $driver_instance->capabilities();
			}

			$sources[] = array(
				'slug'          => $row->slug,
				'driver'        => $row->driver,
				'label'         => $row->label,
				'enabled'       => (bool) $row->enabled,
				'circuit_state' => $row->circuit_state,
				'last_sync_at'  => $row->last_sync_at,
				'last_error'    => $row->last_error,
				'rate_limit'    => $row->rate_limit,
				'capabilities'  => $capabilities,
			);
		}

		// Also list available driver types for reference.
		$available_drivers = $registry->get_registered_driver_slugs();

		return array(
			'success'           => true,
			'sources'           => $sources,
			'total'             => count( $sources ),
			'available_drivers' => $available_drivers,
		);
	}
}
