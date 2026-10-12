<?php
/**
 * GTmetrix tool: get resource summary.
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
 * Read a report's resource summary: per-type counts and sizes, with the
 * heaviest categories surfaced for page-weight triage.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Resource_Summary extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_resource_summary';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Resource Summary', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read the resource summary of a GTmetrix report: counts and transferred/uncompressed sizes per type (HTML, images, scripts, styles, fonts, media). Use to find the heaviest payload categories.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'report_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix report ID.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'   => array( 'report_id' ),
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

		$report_id = $this->arg_report_id( $arguments );
		if ( '' === $report_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_report', __( 'A report ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$summary = $this->client()->get_resource( $report_id, 'resource-summary' );
		if ( is_wp_error( $summary ) ) {
			return $summary;
		}

		$rows      = array();
		$total_req = 0;
		foreach ( $summary as $type => $data ) {
			if ( ! is_array( $data ) ) {
				continue;
			}
			$transferred = isset( $data['size_transferred'] ) ? absint( $data['size_transferred'] ) : 0;
			$count       = isset( $data['count'] ) ? absint( $data['count'] ) : 0;
			$total_req  += $count;
			$rows[]      = array(
				'type'              => $type,
				'label'             => isset( $data['label'] ) ? $data['label'] : $type,
				'count'             => $count,
				'size_transferred'  => $transferred,
				'size_uncompressed' => isset( $data['size_uncompressed'] ) ? absint( $data['size_uncompressed'] ) : 0,
			);
		}

		// Heaviest categories first.
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['size_transferred'] - $a['size_transferred'];
			}
		);

		return array(
			'success'        => true,
			'report_id'      => $report_id,
			'categories'     => $rows,
			'total_requests' => $total_req,
		);
	}
}
