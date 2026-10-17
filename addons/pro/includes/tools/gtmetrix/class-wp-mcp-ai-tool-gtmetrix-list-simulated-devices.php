<?php
/**
 * GTmetrix tool: list simulated devices.
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
 * List GTmetrix simulated devices (PRO plans) for mobile-first audits.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_List_Simulated_Devices extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_list_simulated_devices';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix List Simulated Devices', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List GTmetrix simulated devices (phones and tablets) with viewport and pixel-ratio details. Requires a GTmetrix PRO plan.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'category' => array(
					'type'        => 'string',
					'enum'        => array( 'phone', 'tablet' ),
					'description' => __( 'Filter by device category.', 'mcp-ai-wpoos-pro' ),
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

		$devices = $this->client()->get_devices();
		if ( is_wp_error( $devices ) ) {
			return $devices;
		}

		$category = isset( $arguments['category'] ) ? sanitize_key( $arguments['category'] ) : '';
		$rows     = array();
		foreach ( $devices as $device ) {
			$attrs = isset( $device['attributes'] ) ? $device['attributes'] : array();
			$cat   = isset( $attrs['category'] ) ? $attrs['category'] : '';
			if ( '' !== $category && $cat !== $category ) {
				continue;
			}
			$rows[] = array(
				'id'           => isset( $device['id'] ) ? $device['id'] : '',
				'name'         => isset( $attrs['name'] ) ? $attrs['name'] : '',
				'manufacturer' => isset( $attrs['manufacturer'] ) ? $attrs['manufacturer'] : '',
				'category'     => $cat,
				'width'        => isset( $attrs['width'] ) ? $attrs['width'] : 0,
				'height'       => isset( $attrs['height'] ) ? $attrs['height'] : 0,
				'dppx'         => isset( $attrs['dppx'] ) ? $attrs['dppx'] : 1,
			);
		}

		return array(
			'success' => true,
			'devices' => $rows,
			'count'   => count( $rows ),
		);
	}
}
