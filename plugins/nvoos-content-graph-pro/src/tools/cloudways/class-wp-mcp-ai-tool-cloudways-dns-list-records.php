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

declare(strict_types=1);


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
			return __( 'DNS List Records', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List DNS records for a domain.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'domain' => array(
						'type'        => 'string',
						'description' => __( 'The domain name to list records for.', 'nvoos-content-graph-pro' ),
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
					__( 'A domain name is required.', 'nvoos-content-graph-pro' )
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
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
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
					_n( 'Found %1$d record for %2$s.', 'Found %1$d records for %2$s.', count( $records ), 'nvoos-content-graph-pro' ),
					count( $records ),
					esc_html( $domain )
				),
				array( 'records' => $records )
			);
		}
	}
}
