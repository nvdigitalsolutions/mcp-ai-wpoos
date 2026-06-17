<?php
/**
 * Tool: connect_dicomweb
 *
 * Configures and probes a remote DICOMweb (PS3.18) endpoint.  Settings
 * are stored under the `wp_mcp_ai_dicomweb_connection` option and are
 * used by `import_dicom_study`, `export_dicom_study`, and other Phase D
 * imaging tools.
 *
 * Actions:
 *   - configure : save the connection (base_url, auth_type, credentials)
 *   - test      : issue a QIDO-RS ping against /studies?limit=1
 *   - get       : return the active configuration with secrets redacted
 *   - disconnect: clear the stored connection
 *
 * @package WP_MCP_AI_Pro
 * @since 1.4.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Connect / configure DICOMweb tool.
 */
class WP_MCP_AI_Tool_Connect_DICOMweb implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Whether the tool is available.
	 *
	 * @return bool
	 */
	public static function is_available() {
		if ( function_exists( 'wp_mcp_ai_is_base_version' ) && wp_mcp_ai_is_base_version() ) {
			return false;
		}
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		return ! empty( $settings['enable_healthcare_imaging'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'connect_dicomweb';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Connect DICOMweb', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Configure or test the DICOMweb (QIDO-RS / WADO-RS / STOW-RS) connection used by Phase D imaging tools. Supports basic and bearer-token auth.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'       => array(
					'type'    => 'string',
					'enum'    => array( 'configure', 'test', 'get', 'disconnect' ),
					'default' => 'test',
				),
				'base_url'     => array(
					'type'        => 'string',
					'description' => __( 'Root DICOMweb URL (e.g. https://pacs.example.org/dicom-web).', 'mcp-ai-wpoos-pro' ),
				),
				'auth_type'    => array(
					'type' => 'string',
					'enum' => array( 'none', 'basic', 'bearer' ),
				),
				'username'     => array( 'type' => 'string' ),
				'password'     => array( 'type' => 'string' ),
				'bearer_token' => array( 'type' => 'string' ),
				'timeout'      => array(
					'type'    => 'integer',
					'minimum' => 5,
					'maximum' => 600,
				),
			),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array( 'pro', 'write', 'state-changing', 'external-api' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
	}

	/**
	 * Execute.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array|WP_Error
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$current_user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage DICOMweb connections.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'test';

		if ( 'configure' === $action ) {
			$conn = array(
				'base_url'     => isset( $arguments['base_url'] ) ? esc_url_raw( $arguments['base_url'] ) : '',
				'auth_type'    => isset( $arguments['auth_type'] ) ? sanitize_key( $arguments['auth_type'] ) : 'none',
				'username'     => isset( $arguments['username'] ) ? sanitize_text_field( $arguments['username'] ) : '',
				'password'     => isset( $arguments['password'] ) ? (string) $arguments['password'] : '',
				'bearer_token' => isset( $arguments['bearer_token'] ) ? (string) $arguments['bearer_token'] : '',
				'timeout'      => isset( $arguments['timeout'] ) ? absint( $arguments['timeout'] ) : 30,
			);
			if ( '' === $conn['base_url'] ) {
				return new WP_Error( 'wp_mcp_ai_missing_base_url', __( 'A base_url is required for configuration.', 'mcp-ai-wpoos-pro' ) );
			}
			WP_MCP_AI_DICOMweb_Client::save_connection( $conn );

			if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
				WP_MCP_AI_Healthcare_Audit::record(
					'configure',
					'dicomweb_connection',
					0,
					array(
						'user_id'  => $current_user_id,
						'tool'     => $this->get_slug(),
						'base_url' => $conn['base_url'],
					)
				);
			}
			return array(
				'success' => true,
				'message' => __( 'DICOMweb connection saved.', 'mcp-ai-wpoos-pro' ),
				'config'  => $this->redacted_config(),
			);
		}

		if ( 'disconnect' === $action ) {
			delete_option( WP_MCP_AI_DICOMweb_Client::OPTION_CONNECTION );
			if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
				WP_MCP_AI_Healthcare_Audit::record(
					'disconnect',
					'dicomweb_connection',
					0,
					array( 'user_id' => $current_user_id )
				);
			}
			return array(
				'success' => true,
				'message' => __( 'DICOMweb connection removed.', 'mcp-ai-wpoos-pro' ),
			);
		}

		if ( 'get' === $action ) {
			return array(
				'success' => true,
				'config'  => $this->redacted_config(),
			);
		}

		// Default: test ping.
		$ping = WP_MCP_AI_DICOMweb_Client::ping();
		if ( is_wp_error( $ping ) ) {
			return $ping;
		}
		return array(
			'success' => true,
			'message' => __( 'DICOMweb endpoint reachable.', 'mcp-ai-wpoos-pro' ),
			'config'  => $this->redacted_config(),
		);
	}

	/**
	 * Return the active configuration with secrets redacted.
	 *
	 * @return array
	 */
	private function redacted_config() {
		$conn                 = WP_MCP_AI_DICOMweb_Client::get_connection();
		$conn['password']     = '' !== (string) $conn['password'] ? '[redacted]' : '';
		$conn['bearer_token'] = '' !== (string) $conn['bearer_token'] ? '[redacted]' : '';
		return $conn;
	}
}
