<?php
/**
 * Cloudways List Projects Tool
 *
 * List all projects on the account with their IDs, names, and server/app
 * groupings.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_List_Projects' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_List_Projects extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_list_projects';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'List Cloudways Projects', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'List all projects on the account with their IDs, names, and server/app groupings.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Enumerating account projects and their IDs when grouping servers or apps by project.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Server or app inventory; use cloudways_list_servers or cloudways_list_apps.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_list_servers', 'cloudways_list_apps' ),
			);
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
			$result = $this->client()->get( '/project' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			if ( ! isset( $result['projects'] ) || ! is_array( $result['projects'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_invalid_response',
					__( 'Cloudways returned an unexpected response format.', 'mcp-ai-wpoos-pro' )
				);
			}

			$projects = array();
			foreach ( $result['projects'] as $project ) {
				$projects[] = array(
					'id'   => absint( $project['id'] ),
					'name' => sanitize_text_field( $project['name'] ),
				);
			}

			return $this->success(
				sprintf(
					/* translators: %d: number of projects */
					_n( 'Found %d project.', 'Found %d projects.', count( $projects ), 'mcp-ai-wpoos-pro' ),
					count( $projects )
				),
				array( 'projects' => $projects )
			);
		}
	}
}
