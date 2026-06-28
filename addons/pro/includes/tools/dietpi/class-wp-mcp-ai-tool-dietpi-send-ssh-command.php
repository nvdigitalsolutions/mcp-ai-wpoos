<?php
/**
 * DietPi Send SSH Command Tool
 *
 * Execute an arbitrary shell command on the Raspberry Pi via SSH.
 * Admin-gated — requires manage_options.
 *
 * @package    WP_MCP_AI_Pro
 * @subpackage DietPi_Toolkit
 * @since      1.3.0
 * @author     NV Digital Solutions
 * @copyright  Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license    Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Tool_DietPi_Send_SSH_Command' ) ) {

	/**
	 * Send SSH command tool.
	 *
	 * @since 1.3.0
	 */
	class WP_MCP_AI_Tool_DietPi_Send_SSH_Command extends WP_MCP_AI_Tool_DietPi_Base {

		/** {@inheritdoc} */
		public function get_slug() {
			return 'dietpi_send_ssh_command';
		}

		/** {@inheritdoc} */
		public function get_name() {
			return __( 'Send SSH Command to DietPi', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_description() {
			return __( 'Execute a shell command on the Raspberry Pi via SSH. Use this for system administration, reading logs, managing packages, or running DietPi utilities. State-changing commands require explicit confirmation. Results include stdout, stderr, and exit code.', 'mcp-ai-wpoos-pro' );
		}

		/** {@inheritdoc} */
		public function get_parameters_schema() {
			return array(
				'type'       => 'object',
				'properties' => array(
					'command' => array(
						'type'        => 'string',
						'description' => __( 'The shell command to execute on the Pi. Examples: "cpu", "df -h", "dietpi-software list", "systemctl status sonarr".', 'mcp-ai-wpoos-pro' ),
					),
					'timeout' => array(
						'type'        => 'integer',
						'description' => __( 'Command timeout in seconds. Default: 30.', 'mcp-ai-wpoos-pro' ),
						'default'     => 30,
						'minimum'     => 5,
						'maximum'     => 120,
					),
				),
				'required'   => array( 'command' ),
			);
		}

		/** {@inheritdoc} */
		public function get_capability_flags() {
			return array_merge( parent::get_capability_flags(), array( 'write', 'state-changing', 'performance-impact' ) );
		}

		/**
		 * {@inheritdoc}
		 *
		 * @param array $arguments Tool arguments.
		 * @param array $context   Execution context.
		 * @return array|WP_Error
		 */
		public function execute( array $arguments = array(), array $context = array() ) {
			$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
			if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to execute shell commands.', 'mcp-ai-wpoos-pro' ) );
			}

			$command = $this->sanitize_string( $arguments, 'command' );
			if ( '' === $command ) {
				return new WP_Error( 'wp_mcp_ai_missing_command', __( 'A shell command is required.', 'mcp-ai-wpoos-pro' ) );
			}

			$timeout = $this->sanitize_int( $arguments, 'timeout', WP_MCP_AI_DietPi_SSH_Client::COMMAND_TIMEOUT );
			$timeout = max( 5, min( 120, $timeout ) );

			$result = $this->ssh()->exec( $command, $timeout );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return $this->success(
				__( 'Command executed.', 'mcp-ai-wpoos-pro' ),
				array(
					'stdout'      => $result['stdout'],
					'stderr'      => $result['stderr'],
					'exit_code'   => $result['exit_code'],
					'duration_ms' => $result['duration_ms'],
				)
			);
		}
	}
}
