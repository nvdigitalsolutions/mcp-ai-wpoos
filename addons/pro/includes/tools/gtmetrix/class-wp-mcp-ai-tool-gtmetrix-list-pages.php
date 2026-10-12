<?php
/**
 * GTmetrix tool: list pages.
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
 * List GTmetrix pages (the account's URL registry) with latest report,
 * monitoring state and tags.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_List_Pages extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_list_pages';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix List Pages', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'List the pages in the GTmetrix account with their latest report, monitoring frequency, report count and tags. Use to find the page ID for retests, monitoring and alert configuration.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'url'       => array(
					'type'        => 'string',
					'description' => __( 'Fuzzy-filter pages by URL fragment (e.g. example.com/shop).', 'mcp-ai-wpoos-pro' ),
				),
				'monitored' => array(
					'type'        => 'string',
					'enum'        => array( 'no', 'hourly', 'daily', 'weekly', 'monthly' ),
					'description' => __( 'Filter by monitoring state.', 'mcp-ai-wpoos-pro' ),
				),
				'limit'     => array(
					'type'        => 'integer',
					'description' => __( 'Maximum pages to return (default 25).', 'mcp-ai-wpoos-pro' ),
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
		if ( isset( $arguments['url'] ) && '' !== sanitize_text_field( $arguments['url'] ) ) {
			$query['filter[url]'] = sanitize_text_field( $arguments['url'] );
		}
		if ( isset( $arguments['monitored'] ) && '' !== sanitize_key( $arguments['monitored'] ) ) {
			$query['filter[monitored]'] = sanitize_key( $arguments['monitored'] );
		}

		$pages = $this->client()->list_pages( $query );
		if ( is_wp_error( $pages ) ) {
			return $pages;
		}

		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 25;
		$rows  = array();
		foreach ( array_slice( $pages, 0, $limit ) as $page ) {
			$attrs  = isset( $page['attributes'] ) ? $page['attributes'] : array();
			$rows[] = array(
				'page_id'            => isset( $page['id'] ) ? $page['id'] : '',
				'url'                => isset( $attrs['url'] ) ? $attrs['url'] : '',
				'latest_report'      => isset( $attrs['latest_report'] ) ? $attrs['latest_report'] : '',
				'latest_report_time' => isset( $attrs['latest_report_time'] ) ? $attrs['latest_report_time'] : null,
				'report_count'       => isset( $attrs['report_count'] ) ? $attrs['report_count'] : 0,
				'monitored'          => isset( $attrs['monitored'] ) ? $attrs['monitored'] : 'no',
				'tags'               => isset( $attrs['page_tags'] ) ? $attrs['page_tags'] : array(),
			);
		}

		return array(
			'success' => true,
			'pages'   => $rows,
			'count'   => count( $rows ),
		);
	}
}
