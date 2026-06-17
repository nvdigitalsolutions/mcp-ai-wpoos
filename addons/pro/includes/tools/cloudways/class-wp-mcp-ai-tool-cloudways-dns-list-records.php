<?php
/**
 * Cloudways DNS List Records Tool
 *
 * List DNS records for a domain.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_DNS_List_Records' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_DNS_List_Records extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_dns_list_records';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DNS List Records', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List DNS records for a domain.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'domain' => array(
						'type'        => 'string',
						'description' => __( 'The domain name to list records for.', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
				),
				'required'   => array( 'domain' ),
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
			$domain = isset( $arguments['domain'] ) ? sanitize_text_field( $arguments['domain'] ) : '';

			if ( '' === $domain ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_domain',
					__( 'A domain name is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/dns/domain/' . $domain . '/record';
			$result = $this->client()->get( $path );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['records'] ) || ! is_array( $result['records'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$records = array();
			foreach ( $result['records'] as $record ) {
				$records[] = array(
					'id'    => isset( $record['id'] ) ? absint( $record['id'] ) : 0,
					'type'  => isset( $record['type'] ) ? sanitize_text_field( $record['type'] ) : '',
					'name'  => isset( $record['name'] ) ? sanitize_text_field( $record['name'] ) : '',
					'value' => isset( $record['value'] ) ? sanitize_text_field( $record['value'] ) : '',
					'ttl'   => isset( $record['ttl'] ) ? absint( $record['ttl'] ) : 0,
				);
			}

			return $this->success(
				sprintf(
					/* translators: 1: number of records, 2: domain */
					_n( 'Found %1$d record for %2$s.', 'Found %1$d records for %2$s.', count( $records ), 'mcp-ai-wpoos-pro' ),
					count( $records ),
					esc_html( $domain )
				),
				array( 'records' => $records )
			);
		}
	}
}
