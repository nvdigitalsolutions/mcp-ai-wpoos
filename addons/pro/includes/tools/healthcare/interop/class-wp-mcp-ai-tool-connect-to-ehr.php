<?php
/**
 * Tool: connect_to_ehr
 *
 * Stores, tests, retrieves, and disconnects EHR (Epic / Cerner / generic
 * SMART-on-FHIR) OAuth client credentials.  Connections are saved under
 * `wp_mcp_ai_ehr_connections` keyed by vendor and exercised via the
 * SMART-on-FHIR `client_credentials` flow when a `token_url` is supplied.
 *
 * Sites with the Pro password vault active can hook
 * `wp_mcp_ai_healthcare_ehr_credentials` to delegate persistence to the
 * vault — by default the credentials are stored in options.
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
 * Connect to EHR tool.
 */
class WP_MCP_AI_Tool_Connect_To_EHR implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * Option key.
	 */
	const OPTION_KEY = 'wp_mcp_ai_ehr_connections';

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
		return ! empty( $settings['enable_health_wellness_management'] );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'connect_to_ehr';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Connect to EHR', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manage Epic, Cerner, or generic SMART-on-FHIR EHR connections used by import_fhir_bundle. Supports configure, test (client_credentials token), get (redacted), and disconnect.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'action'        => array(
					'type'    => 'string',
					'enum'    => array( 'configure', 'test', 'get', 'disconnect' ),
					'default' => 'get',
				),
				'vendor'        => array(
					'type'        => 'string',
					'enum'        => array( 'epic', 'cerner', 'generic' ),
					'description' => __( 'EHR vendor identifier.', 'mcp-ai-wpoos-pro' ),
				),
				'fhir_base_url' => array( 'type' => 'string' ),
				'token_url'     => array( 'type' => 'string' ),
				'client_id'     => array( 'type' => 'string' ),
				'client_secret' => array( 'type' => 'string' ),
				'scope'         => array(
					'type'    => 'string',
					'default' => 'system/*.read',
				),
			),
			'required'   => array( 'vendor' ),
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
		$current_user_id = ! empty( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();
		if ( ! $current_user_id || ! user_can( $current_user_id, 'manage_options' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage EHR connections.', 'mcp-ai-wpoos-pro' ) );
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : 'get';
		$vendor = isset( $arguments['vendor'] ) ? sanitize_key( $arguments['vendor'] ) : '';
		if ( '' === $vendor ) {
			return new WP_Error( 'wp_mcp_ai_ehr_missing_vendor', __( 'A vendor is required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'configure':
				return $this->configure( $vendor, $arguments, $current_user_id );
			case 'disconnect':
				return $this->disconnect( $vendor, $current_user_id );
			case 'test':
				return $this->test( $vendor, $current_user_id );
			case 'get':
			default:
				return array(
					'success'    => true,
					'connection' => $this->get_redacted( $vendor ),
				);
		}
	}

	/**
	 * Save a connection.
	 *
	 * @security Client credentials (client_id / client_secret) are stored in
	 *           WordPress options in plaintext. For production deployments,
	 *           set up encryption-at-rest via WP_MCP_AI_Vault_Encryption_Service
	 *           by hooking {@see wp_mcp_ai_healthcare_ehr_credentials}.
	 *
	 * @param string $vendor          Vendor.
	 * @param array  $arguments       Args.
	 * @param int    $current_user_id User id.
	 * @return array|WP_Error
	 */
	private function configure( $vendor, $arguments, $current_user_id ) {
		$conn = array(
			'vendor'        => $vendor,
			'fhir_base_url' => isset( $arguments['fhir_base_url'] ) ? esc_url_raw( $arguments['fhir_base_url'] ) : '',
			'token_url'     => isset( $arguments['token_url'] ) ? esc_url_raw( $arguments['token_url'] ) : '',
			'client_id'     => isset( $arguments['client_id'] ) ? sanitize_text_field( $arguments['client_id'] ) : '',
			'client_secret' => isset( $arguments['client_secret'] ) ? (string) $arguments['client_secret'] : '',
			'scope'         => isset( $arguments['scope'] ) ? sanitize_text_field( $arguments['scope'] ) : 'system/*.read',
		);
		if ( '' === $conn['fhir_base_url'] || '' === $conn['client_id'] ) {
			return new WP_Error( 'wp_mcp_ai_ehr_missing_required', __( 'fhir_base_url and client_id are required.', 'mcp-ai-wpoos-pro' ) );
		}

		/**
		 * Filter EHR credentials before persistence.
		 *
		 * Hook this to delegate storage to the Pro password vault.
		 *
		 * @since 1.4.0
		 *
		 * @param array  $conn   Connection details.
		 * @param string $vendor Vendor key.
		 */
		$conn = (array) apply_filters( 'wp_mcp_ai_healthcare_ehr_credentials', $conn, $vendor );

		$all            = (array) get_option( self::OPTION_KEY, array() );
		$all[ $vendor ] = $conn;
		update_option( self::OPTION_KEY, $all, false );

		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'configure',
				'ehr_connection',
				0,
				array(
					'user_id' => $current_user_id,
					'vendor'  => $vendor,
				)
			);
		}
		return array(
			'success'    => true,
			'message'    => __( 'EHR connection saved.', 'mcp-ai-wpoos-pro' ),
			'connection' => $this->get_redacted( $vendor ),
		);
	}

	/**
	 * Disconnect (clear) a vendor connection.
	 *
	 * @param string $vendor          Vendor.
	 * @param int    $current_user_id User id.
	 * @return array
	 */
	private function disconnect( $vendor, $current_user_id ) {
		$all = (array) get_option( self::OPTION_KEY, array() );
		if ( isset( $all[ $vendor ] ) ) {
			unset( $all[ $vendor ] );
			update_option( self::OPTION_KEY, $all, false );
		}
		if ( class_exists( 'WP_MCP_AI_Healthcare_Audit' ) ) {
			WP_MCP_AI_Healthcare_Audit::record(
				'disconnect',
				'ehr_connection',
				0,
				array(
					'user_id' => $current_user_id,
					'vendor'  => $vendor,
				)
			);
		}
		return array(
			'success' => true,
			'message' => __( 'EHR connection removed.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Run a SMART-on-FHIR client_credentials token request to test the connection.
	 *
	 * @param string $vendor          Vendor.
	 * @param int    $current_user_id User id.
	 * @return array|WP_Error
	 */
	private function test( $vendor, $current_user_id ) {
		$conn = $this->get_connection( $vendor );
		if ( ! $conn ) {
			return new WP_Error( 'wp_mcp_ai_ehr_not_configured', __( 'No connection configured for that vendor.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( '' === $conn['token_url'] ) {
			return new WP_Error( 'wp_mcp_ai_ehr_missing_token_url', __( 'A token_url is required to test the connection.', 'mcp-ai-wpoos-pro' ) );
		}

		// Reject private/reserved IPs and localhost (SSRF guard).
		$host = strtolower( wp_parse_url( $conn['token_url'], PHP_URL_HOST ) );
		if ( ! $host || 'localhost' === $host ) {
			return new WP_Error( 'wp_mcp_ai_ehr_invalid_token_url', __( 'The token URL resolves to an invalid or non-routable host.', 'mcp-ai-wpoos-pro' ) );
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return new WP_Error( 'wp_mcp_ai_ehr_invalid_token_url', __( 'The token URL resolves to an invalid or non-routable host.', 'mcp-ai-wpoos-pro' ) );
			}
		}

		$response = wp_remote_post(
			$conn['token_url'],
			array(
				'timeout'             => 30,
				'reject_unsafe_urls'  => true,
				'redirection'         => 0,
				'headers'             => array(
					'Accept'        => 'application/json',
					'Content-Type'  => 'application/x-www-form-urlencoded',
					'Authorization' => 'Basic ' . base64_encode( $conn['client_id'] . ':' . $conn['client_secret'] ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
				),
				'body'                => array(
					'grant_type' => 'client_credentials',
					'scope'      => $conn['scope'],
				),
			)
		);
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_ehr_token_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'Token request failed with HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}
		$body = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $body ) || empty( $body['access_token'] ) ) {
			return new WP_Error( 'wp_mcp_ai_ehr_invalid_token_response', __( 'Token endpoint did not return an access_token.', 'mcp-ai-wpoos-pro' ) );
		}
		return array(
			'success'      => true,
			'message'      => __( 'EHR endpoint reachable; access_token issued.', 'mcp-ai-wpoos-pro' ),
			'expires_in'   => isset( $body['expires_in'] ) ? (int) $body['expires_in'] : null,
			'token_type'   => isset( $body['token_type'] ) ? (string) $body['token_type'] : '',
			'scope_issued' => isset( $body['scope'] ) ? (string) $body['scope'] : '',
		);
	}

	/**
	 * Resolve a stored connection (with secrets) for internal use.
	 *
	 * @param string $vendor Vendor.
	 * @return array|null
	 */
	private function get_connection( $vendor ) {
		$all = (array) get_option( self::OPTION_KEY, array() );
		if ( ! isset( $all[ $vendor ] ) ) {
			return null;
		}
		return wp_parse_args(
			$all[ $vendor ],
			array(
				'fhir_base_url' => '',
				'token_url'     => '',
				'client_id'     => '',
				'client_secret' => '',
				'scope'         => 'system/*.read',
			)
		);
	}

	/**
	 * Return a redacted copy of the connection.
	 *
	 * @param string $vendor Vendor.
	 * @return array
	 */
	private function get_redacted( $vendor ) {
		$conn = $this->get_connection( $vendor );
		if ( ! $conn ) {
			return array(
				'vendor'     => $vendor,
				'configured' => false,
			);
		}
		$conn['client_secret'] = '' !== (string) $conn['client_secret'] ? '[redacted]' : '';
		$conn['configured']    = true;
		return $conn;
	}
}
