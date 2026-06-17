<?php
/**
 * Cloudways DNS List Domains Tool
 *
 * List all managed domains in DNS Made Easy.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage Cloudways_Toolkit
 * @since      1.1.15
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_DNS_List_Domains' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_DNS_List_Domains extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_dns_list_domains';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DNS List Domains', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all managed domains in DNS Made Easy.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'read-only', 'cacheable' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Contextual data.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$result = $this->client()->get( '/dns/domain' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['domains'] ) || ! is_array( $result['domains'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$domains = array();
			foreach ( $result['domains'] as $domain ) {
				$domains[] = array(
					'id'     => isset( $domain['id'] ) ? absint( $domain['id'] ) : 0,
					'name'   => isset( $domain['name'] ) ? sanitize_text_field( $domain['name'] ) : '',
					'status' => isset( $domain['status'] ) ? sanitize_text_field( $domain['status'] ) : '',
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of domains */
					_n( 'Found %d domain.', 'Found %d domains.', count( $domains ), 'mcp-ai-wpoos-pro' ),
					count( $domains )
				),
				array( 'domains' => $domains )
			);
		}
	}
}
