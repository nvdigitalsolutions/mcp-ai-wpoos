<?php
/**
 * Cloudways Git Clone Tool
 *
 * Clone a Git repository into an application's web root and deploy.
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

if ( ! class_exists( 'WP_MCP_AI_Tool_Cloudways_Git_Clone' ) ) {

	/**
	 * {@inheritdoc}
	 */
	class WP_MCP_AI_Tool_Cloudways_Git_Clone extends WP_MCP_AI_Tool_Cloudways_Base implements WP_MCP_AI_Tool_Usage_Guidance_Interface {

		/** {@inheritdoc} */

		/** {@inheritdoc} */
		public function get_slug() {
			return 'cloudways_git_clone';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Git Clone', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Clone a Git repository into an application\'s web root and deploy.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_usage_guidance() {
			return array(
				'when_to_use'     => __( 'Performing the initial deploy of a chosen branch into an app\'s web root.', 'mcp-ai-wpoos-pro' ),
				'when_not_to_use' => __( 'Deploying newer commits of an already linked repository; use cloudways_git_pull instead.', 'mcp-ai-wpoos-pro' ),
				'related_tools'   => array( 'cloudways_git_pull', 'cloudways_git_branches_get', 'cloudways_git_key_get', 'cloudways_git_history_get' ),
				'notes'           => __( 'Replaces the app web root with the cloned branch; take a backup with cloudways_create_app_backup first.', 'mcp-ai-wpoos-pro' ),
			);
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'server_id' => array(
						'type'        => 'integer',
						'description' => __( 'The server ID.', 'mcp-ai-wpoos-pro' ),
					),
					'app_id'    => array(
						'type'        => 'integer',
						'description' => __( 'The application ID.', 'mcp-ai-wpoos-pro' ),
					),
					'branch'    => array(
						'type'        => 'string',
						'description' => __( 'The branch name to deploy.', 'mcp-ai-wpoos-pro' ),
						'minLength'   => 1,
					),
				),
				'required'   => array( 'server_id', 'app_id', 'branch' ),
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
			$server_id = $this->sanitize_server_id( $arguments );
			$app_id    = $this->sanitize_app_id( $arguments );
			$branch    = isset( $arguments['branch'] ) ? sanitize_text_field( $arguments['branch'] ) : '';

			if ( 0 === $server_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_server_id',
					__( 'A valid server ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( 0 === $app_id ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_app_id',
					__( 'A valid app ID is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			if ( '' === $branch ) {
				return new WP_Error(
					'wp_mcp_ai_cloudways_missing_branch',
					__( 'A branch name is required.', 'mcp-ai-wpoos-pro' )
				);
			}

			$path   = '/app/' . $server_id . '/' . $app_id . '/git/clone';
			$body   = array( 'branch' => $branch );
			$result = $this->client()->post( $path, $body );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				sprintf(
					/* translators: 1: branch name, 2: app ID */
					__( 'Git clone initiated for branch "%1$s" on app %2$d.', 'mcp-ai-wpoos-pro' ),
					esc_html( $branch ),
					$app_id
				),
				$result
			);
		}
	}
}
