<?php
/**
 * Tool: `wifi_health_check`.
 *
 * The Phase 0 tool. Its job is to prove the whole pipe end to end — Base tool
 * registration, port resolution, adapter reachability, schema integrity and
 * venue enumeration — without requiring hardware or a Pro licence.
 *
 * @package NvoosWifi
 * @since   0.1.0
 * @license GPL-3.0-or-later
 */

declare( strict_types=1 );

use NvoosWifi\Domain\Kpi_Schema;
use NvoosWifi\Infrastructure\Wp\Class_Result_Mapper;
use NvoosWifi\Infrastructure\Wp\Class_Venue_Repository;
use NvoosWifi\Plugin;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Report NV oOS WiFi adapter health, schema integrity and venue count.
 *
 * @since 0.1.0
 */
class WP_MCP_AI_Tool_WiFi_Health_Check implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'wifi_health_check';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'WiFi Health Check', 'nvoos-wifi' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Report NV oOS WiFi status: the active controller adapter, its reachability, the number of canonical KPI keys in the schema, and the registered venues. Read-only and local-only — it contacts no controller and, on the Phase 0 mock adapter, no network at all.', 'nvoos-wifi' );
	}

	/**
	 * Usage guidance for this tool.
	 *
	 * @return array<string, mixed>
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'First call in any WiFi workflow, and as a smoke test after installing or reconfiguring the addon.', 'nvoos-wifi' ),
			'when_not_to_use' => __( 'Reading actual venue metrics; use wifi_get_kpis once Phase 2 lands.', 'nvoos-wifi' ),
			'related_tools'   => array( 'wifi_get_kpis' ),
			'notes'           => __( 'Read-only, local-only and idempotent. Never mutates a controller.', 'nvoos-wifi' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'manage_options';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'wifi',
			'pattern_compatibility' => array( 'orchestrator' ),
			'profession_tags'       => array( 'system_administrator', 'network_engineer' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',
			'local-only',
			'idempotent',
			'cacheable',
			'requires-capability',
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments (none are accepted).
	 * @param array $context   Execution context.
	 * @return array|WP_Error Canonical success envelope or WP_Error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		unset( $arguments, $context );

		$health = Plugin::kpi_service()->health();

		if ( $health->is_error() ) {
			return Class_Result_Mapper::to_wp_error( $health );
		}

		$payload = $health->value();
		$venues  = ( new Class_Venue_Repository() )->all_venues();

		$venue_ids = array();
		foreach ( $venues as $venue ) {
			$venue_ids[] = $venue->id();
		}

		sort( $venue_ids, SORT_STRING );

		// Gate 2: every emitted value is a scalar this method constructed or a
		// sanitised identifier — nothing is passed through from user input.
		return array(
			'success'     => true,
			'adapter'     => $payload['adapter'],
			'health'      => $payload['health'],
			'schema'      => array(
				'kpi_count' => count( Kpi_Schema::keys() ),
			),
			'venues'      => array(
				'count' => count( $venue_ids ),
				'ids'   => $venue_ids,
			),
			'checked_at'  => Plugin::clock()->now(),
		);
	}
}
