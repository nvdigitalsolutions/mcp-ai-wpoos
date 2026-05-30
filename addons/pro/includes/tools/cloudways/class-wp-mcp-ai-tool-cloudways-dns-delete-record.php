<?php
/**
 * Cloudways DNS Delete Record Tool
 *
 * Delete a DNS record for a domain.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_DNS_Delete_Record extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_dns_delete_record';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DNS Delete Record', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Delete a DNS record for a domain.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'domain'    => array(
						'type'        => 'string',
						'description' => __( 'The domain name.', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
					'record_id' => array(
						'type'        => 'integer',
						'description' => __( 'The DNS record ID to delete.', 'mcp-ai-wpoos-pro' ),
					),
				),
				'required'   => array( 'domain', 'record_id' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'non-reversible' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Contextual data.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$domain    = isset( $arguments['domain'] ) ? sanitize_text_field( $arguments['domain'] ) : '';
			$record_id = isset( $arguments['record_id'] ) ? absint( $arguments['record_id'] ) : 0;

			if ( '' === $domain ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_domain',
					__( 'A domain name is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 0 === $record_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_record_id',
					__( 'A valid record ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/dns/domain/' . $domain . '/record/' . $record_id;
			$result = $this->client()->delete( $path, array() );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: 1: record ID, 2: domain */
					__( 'DNS record %1$d deleted from %2$s.', 'mcp-ai-wpoos-pro' ),
					$record_id,
					esc_html( $domain )
				),
				$result
			);
		}
	}
}
