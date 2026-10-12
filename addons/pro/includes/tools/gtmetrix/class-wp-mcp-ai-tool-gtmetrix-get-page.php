<?php
/**
 * GTmetrix tool: get page.
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
 * Read a GTmetrix page's detail: analysis options, latest report,
 * monitoring state and tags.
 *
 * @since 1.2.5
 */
class WP_MCP_AI_Tool_GTmetrix_Get_Page extends WP_MCP_AI_GTmetrix_Tool_Base {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'gtmetrix_get_page';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'GTmetrix Get Page', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Read a GTmetrix page\'s detail: URL, browser/location options, latest report, monitoring frequency and attached tags.', 'mcp-ai-wpoos-pro' );
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

		$page = $this->client()->get_page( $page_id );
		if ( is_wp_error( $page ) ) {
			return $page;
		}

		$attrs = isset( $page['attributes'] ) ? $page['attributes'] : array();

		return array(
			'success'            => true,
			'page_id'            => isset( $page['id'] ) ? $page['id'] : $page_id,
			'url'                => isset( $attrs['url'] ) ? $attrs['url'] : '',
			'browser'            => isset( $attrs['browser'] ) ? $attrs['browser'] : '',
			'location'           => isset( $attrs['location'] ) ? $attrs['location'] : '',
			'latest_report'      => isset( $attrs['latest_report'] ) ? $attrs['latest_report'] : '',
			'latest_report_time' => isset( $attrs['latest_report_time'] ) ? $attrs['latest_report_time'] : null,
			'report_count'       => isset( $attrs['report_count'] ) ? $attrs['report_count'] : 0,
			'monitored'          => isset( $attrs['monitored'] ) ? $attrs['monitored'] : 'no',
			'tags'               => isset( $attrs['page_tags'] ) ? $attrs['page_tags'] : array(),
		);
	}
}
