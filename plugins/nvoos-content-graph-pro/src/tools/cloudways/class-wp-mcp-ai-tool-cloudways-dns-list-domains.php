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

declare(strict_types=1);


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
			return __( 'DNS List Domains', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all managed domains in DNS Made Easy.', 'nvoos-content-graph-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				// Empty stdClass encodes as `{}`; an empty PHP array would encode
				// as `[]`, which strict providers (DeepSeek) reject.
				'properties' => new stdClass(),
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
					__( 'Cloudways returned an unexpected response format.', 'nvoos-content-graph-pro' )
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
					_n( 'Found %d domain.', 'Found %d domains.', count( $domains ), 'nvoos-content-graph-pro' ),
					count( $domains )
				),
				array( 'domains' => $domains )
			);
		}
	}
}
