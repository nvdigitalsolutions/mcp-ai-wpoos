<?php
/**
 * GTmetrix tool: list tests.
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
 * List recent GTmetrix tests (24-hour API window).
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_List_Tests extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_list_tests';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix List Tests', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List recent GTmetrix tests with their state and URL. Note: GTmetrix only retains tests for 24 hours.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'state' => array(
					'type'        => 'string',
					'enum'        => array( 'queued', 'started', 'error', 'completed' ),
					'description' => __( 'Filter by test state.', 'mcp-ai-wpoos-pro' ),
				),
				'limit' => array(
					'type'        => 'integer',
					'description' => __( 'Maximum tests to return (default 25).', 'mcp-ai-wpoos-pro' ),
					'default'     => 25,
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

		$query = array();
		if ( isset( $arguments['state'] ) && '' !== sanitize_key( $arguments['state'] ) ) {
			$query['filter[state]'] = sanitize_key( $arguments['state'] );
		}

		$tests = $this->client()->list_tests( $query );
		if ( is_wp_error( $tests ) ) {
			return $tests;
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;
		$rows  = array();
		foreach ( array_slice( $tests, 0, $limit ) as $test ) {
			$attrs  = isset( $test['attributes'] ) ? $test['attributes'] : array();
			$rows[] = array(
				'test_id'   => isset( $test['id'] ) ? $test['id'] : '',
				'url'       => isset( $attrs['url'] ) ? $attrs['url'] : '',
				'state'     => isset( $attrs['state'] ) ? $attrs['state'] : '',
				'report_id' => isset( $attrs['report'] ) ? $attrs['report'] : '',
				'created'   => isset( $attrs['created'] ) ? $attrs['created'] : null,
				'finished'  => isset( $attrs['finished'] ) ? $attrs['finished'] : null,
			);
		}

		return array(
			'success' => true,
			'tests'   => $rows,
			'count'   => count( $rows ),
		);
	}
}
