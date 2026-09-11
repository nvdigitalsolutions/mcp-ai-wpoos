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
 * Cloudways DNS Add Record Tool
 *
 * Add a DNS record for a domain.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_DNS_Add_Record' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_DNS_Add_Record extends WP_MCP_AI_Tool_Cloudways_Base {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_dns_add_record';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'DNS Add Record', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Add a DNS record for a domain.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'domain' => array(
						'type'        => 'string',
						'description' => __( 'The domain name.', 'nvoos-content-graph-pro' ),
						'minLength'   => 1,
					),
					'type'   => array(
						'type'        => 'string',
						'description' => __( 'The DNS record type.', 'nvoos-content-graph-pro' ),
						'enum'        => array( 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS' ),
					),
					'name'   => array(
						'type'        => 'string',
						'description' => __( 'The record name/host.', 'nvoos-content-graph-pro' ),
						'minLength'   => 1,
					),
					'value'  => array(
						'type'        => 'string',
						'description' => __( 'The record value.', 'nvoos-content-graph-pro' ),
						'minLength'   => 1,
					),
					'ttl'    => array(
						'type'        => 'integer',
						'description' => __( 'TTL in seconds (optional).', 'nvoos-content-graph-pro' ),
					),
				),
				'required'   => array( 'domain', 'type', 'name', 'value' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'reversible' ) );
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
			$type   = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
			$name   = isset( $arguments['name'] ) ? sanitize_text_field( $arguments['name'] ) : '';
			$value  = isset( $arguments['value'] ) ? sanitize_text_field( $arguments['value'] ) : '';
			$ttl    = isset( $arguments['ttl'] ) ? absint( $arguments['ttl'] ) : 0;

			if ( '' === $domain ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_domain',
					__( 'A domain name is required.', 'nvoos-content-graph-pro' )
				);
			}

			$valid_types = array( 'A', 'AAAA', 'CNAME', 'MX', 'TXT', 'NS' );
			if ( '' === $type || ! in_array( $type, $valid_types, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_type',
					__( 'A valid record type is required (A, AAAA, CNAME, MX, TXT, NS).', 'nvoos-content-graph-pro' )
				);
			}

			if ( '' === $name ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_name',
					__( 'A record name is required.', 'nvoos-content-graph-pro' )
				);
			}

			if ( '' === $value ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_value',
					__( 'A record value is required.', 'nvoos-content-graph-pro' )
				);
			}

			$body = array(
				'type'  => $type,
				'name'  => $name,
				'value' => $value,
			);

			if ( $ttl > 0 ) {
				$body['ttl'] = $ttl;
			}

			$path   = '/dns/domain/' . $domain . '/record';
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: 1: record type, 2: record name, 3: domain */
					__( '%1$s record "%2$s" added to %3$s.', 'nvoos-content-graph-pro' ),
					esc_html( $type ),
					esc_html( $name ),
					esc_html( $domain )
				),
				$result
			);
		}
	}
}
