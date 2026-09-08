<?php
/**
 * Cloudways tool batch (ecosystem port - Wave F2, cloudways toolkit).
 *
 * Ported from the base Pro addon's `addons/pro/includes/tools/cloudways/` directory for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the class in
 * monolith installs - the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro` (no
 * path constants - the shared base class resolves via the entry's spl autoloader).
 *
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

declare(strict_types=1);


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
			return __( 'DNS Delete Record', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Delete a DNS record for a domain.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'domain'    => array(
						'type'        => 'string',
						'description' => __( 'The domain name.', 'nvoos-content-graph-pro' ),
						'minLength'   => 1,
					),
					'record_id' => array(
						'type'        => 'integer',
						'description' => __( 'The DNS record ID to delete.', 'nvoos-content-graph-pro' ),
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
					__( 'A domain name is required.', 'nvoos-content-graph-pro' )
				);
			}

			if ( 0 === $record_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_record_id',
					__( 'A valid record ID is required.', 'nvoos-content-graph-pro' )
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
					__( 'DNS record %1$d deleted from %2$s.', 'nvoos-content-graph-pro' ),
					$record_id,
					esc_html( $domain )
				),
				$result
			);
		}
	}
}
