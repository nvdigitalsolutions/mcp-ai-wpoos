<?php
/**
 * GTmetrix tool: get alert events.
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
 * Read a page's performance-alert trigger history.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Alert_Events extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_alert_events';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Alert Events', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read the performance-alert events triggered for a GTmetrix page, most recent first: which condition fired, the value that triggered it, and any report-generation failures.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'page_id' => array(
					'type'        => 'string',
					'description' => __( 'GTmetrix page ID.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'   => array(
					'type'        => 'integer',
					'description' => __( 'Maximum events to return (default 25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
				),
			),
			'required'   => array( 'page_id' ),
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

		$page_id = $this->arg_page_id( $arguments );
		if ( '' === $page_id ) {
			return new WP_Error( 'wp_mcp_ai_gtmetrix_invalid_page', __( 'A page ID is required.', 'mcp-ai-wpoos-pro' ) );
		}

		$events = $this->client()->get_alert_events( $page_id );
		if ( is_wp_error( $events ) ) {
			return $events;
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;
		$rows  = array();
		foreach ( array_slice( $events, 0, $limit ) as $event ) {
			$attrs  = isset( $event['attributes'] ) ? $event['attributes'] : array();
			$rows[] = array(
				'event_id'   => isset( $event['id'] ) ? $event['id'] : '',
				'timestamp'  => isset( $attrs['timestamp'] ) ? $attrs['timestamp'] : null,
				'report_id'  => isset( $attrs['report_id'] ) ? $attrs['report_id'] : '',
				'alerts'     => isset( $attrs['alerts'] ) ? $attrs['alerts'] : array(),
				'recipients' => isset( $attrs['recipient_emails'] ) ? $attrs['recipient_emails'] : array(),
			);
		}

		return array(
			'success' => true,
			'page_id' => $page_id,
			'events'  => $rows,
			'count'   => count( $rows ),
		);
	}
}
