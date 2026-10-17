<?php
/**
 * GTmetrix tool: list test locations.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.5
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once __DIR__ . '/class-wp-mcp-ai-gtmetrix-tool-base.php';

/**
 * List GTmetrix test locations with availability and region grouping.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_List_Locations extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_list_locations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix List Locations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List GTmetrix test locations with region, availability and supported browsers. Use to pick a location close to the site audience.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'available_only' => array(
					'type'        => 'boolean',
					'description' => __( 'Only return locations the account can test from.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$error = $this->guard_key();
		if ( $error ) {
			return $error;
		}

		$locations = $this->client()->get_locations();
		if ( is_wp_error( $locations ) ) {
			return $locations;
		}

		$available_only = ! empty( $arguments['available_only'] );
		$rows           = array();
		foreach ( $locations as $location ) {
			$attrs = isset( $location['attributes'] ) ? $location['attributes'] : array();
			$row   = array(
				'id'         => isset( $location['id'] ) ? $location['id'] : '',
				'name'       => isset( $attrs['name'] ) ? $attrs['name'] : '',
				'region'     => isset( $attrs['region'] ) ? $attrs['region'] : '',
				'has_access' => ! empty( $attrs['account_has_access'] ),
				'is_default' => ! empty( $attrs['default'] ),
				'active'     => ! empty( $attrs['active_location'] ),
				'servers_up' => isset( $attrs['servers'] ) ? $attrs['servers'] : 0,
				'browsers'   => isset( $attrs['browsers'] ) ? $attrs['browsers'] : array(),
			);
			if ( $available_only && ( ! $row['has_access'] || ! $row['active'] ) ) {
				continue;
			}
			$rows[] = $row;
		}

		return array(
			'success'   => true,
			'locations' => $rows,
			'count'     => count( $rows ),
		);
	}
}
