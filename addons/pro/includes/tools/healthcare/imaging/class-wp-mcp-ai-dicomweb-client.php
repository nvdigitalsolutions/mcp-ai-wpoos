<?php
/**
 * Lightweight DICOMweb client (QIDO-RS, WADO-RS, STOW-RS).
 *
 * Wraps `wp_remote_*` so partner code can swap the transport (e.g. for
 * Cornerstone3D-served local proxies) via standard WP HTTP filters and
 * adds opinionated defaults for DICOM JSON responses, OAuth bearer
 * tokens, basic auth, and a configurable timeout.
 *
 * The class is intentionally transport-only: it does not parse pixel
 * data, generate UIDs, or persist studies; tools using this client are
 * responsible for storing what they receive into the
 * `mcp_ai_imaging_study` CPT.
 *
 * Endpoints follow PS3.18 (DICOMweb): `{base}/studies`, etc.
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
 * DICOMweb HTTP client.
 */
class WP_MCP_AI_DICOMweb_Client {

	/**
	 * Option key for storing the active DICOMweb connection.
	 */
	const OPTION_CONNECTION = 'wp_mcp_ai_dicomweb_connection';

	/**
	 * Resolve the active connection details from options + filters.
	 *
	 * @return array { base_url, auth_type, username, password, bearer_token, timeout }
	 */
	public static function get_connection() {
		$default = array(
			'base_url'     => '',
			'auth_type'    => 'none', // none|basic|bearer.
			'username'     => '',
			'password'     => '',
			'bearer_token' => '',
			'timeout'      => 30,
		);
		$stored  = get_option( self::OPTION_CONNECTION, array() );
		$conn    = wp_parse_args( is_array( $stored ) ? $stored : array(), $default );

		/**
		 * Filter the resolved DICOMweb connection.
		 *
		 * @since 1.4.0
		 *
		 * @param array $conn Connection settings.
		 */
		return apply_filters( 'wp_mcp_ai_healthcare_dicomweb_connection', $conn );
	}

	/**
	 * Persist a DICOMweb connection.
	 *
	 * @security Credentials (basic auth username/password and bearer tokens)
	 *           are stored in WordPress options in plaintext. For production
	 *           deployments, set up encryption-at-rest via
	 *           WP_MCP_AI_Vault_Encryption_Service by hooking
	 *           {@see wp_mcp_ai_healthcare_dicomweb_connection}.
	 *
	 * @param array $connection Connection settings.
	 * @return bool
	 */
	public static function save_connection( array $connection ) {
		$clean = array(
			'base_url'     => isset( $connection['base_url'] ) ? esc_url_raw( $connection['base_url'] ) : '',
			'auth_type'    => isset( $connection['auth_type'] ) ? sanitize_key( $connection['auth_type'] ) : 'none',
			'username'     => isset( $connection['username'] ) ? sanitize_text_field( $connection['username'] ) : '',
			'password'     => isset( $connection['password'] ) ? (string) $connection['password'] : '',
			'bearer_token' => isset( $connection['bearer_token'] ) ? (string) $connection['bearer_token'] : '',
			'timeout'      => isset( $connection['timeout'] ) ? max( 5, absint( $connection['timeout'] ) ) : 30,
		);
		if ( ! in_array( $clean['auth_type'], array( 'none', 'basic', 'bearer' ), true ) ) {
			$clean['auth_type'] = 'none';
		}

		// Reject private/reserved IPs and localhost (SSRF guard).
		$host = strtolower( wp_parse_url( $clean['base_url'], PHP_URL_HOST ) );
		if ( ! $host || 'localhost' === $host ) {
			return false;
		}
		if ( filter_var( $host, FILTER_VALIDATE_IP ) ) {
			if ( ! filter_var( $host, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE ) ) {
				return false;
			}
		}

		return (bool) update_option( self::OPTION_CONNECTION, $clean, false );
	}

	/**
	 * Build common request args including auth headers.
	 *
	 * @param array $conn   Connection.
	 * @param array $accept Accept header value(s).
	 * @return array
	 */
	private static function build_args( $conn, $accept ) {
		$headers = array(
			'Accept' => is_array( $accept ) ? implode( ', ', $accept ) : (string) $accept,
		);
		if ( 'bearer' === $conn['auth_type'] && '' !== $conn['bearer_token'] ) {
			$headers['Authorization'] = 'Bearer ' . $conn['bearer_token'];
		} elseif ( 'basic' === $conn['auth_type'] && '' !== $conn['username'] ) {
			$headers['Authorization'] = 'Basic ' . base64_encode( $conn['username'] . ':' . $conn['password'] ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_encode
		}
		$args = array(
			'headers'             => $headers,
			'timeout'             => isset( $conn['timeout'] ) ? (int) $conn['timeout'] : 30,
			'sslverify'           => true,
			'user-agent'          => 'NV-oOS-DICOMweb/1.0',
			'reject_unsafe_urls'  => true,
			'redirection'         => 0,
		);

		/**
		 * Filter DICOMweb HTTP request args before dispatch.
		 *
		 * @since 1.4.0
		 *
		 * @param array $args HTTP request args.
		 * @param array $conn Active connection.
		 */
		return apply_filters( 'wp_mcp_ai_healthcare_dicomweb_request_args', $args, $conn );
	}

	/**
	 * Validate the connection by issuing a lightweight QIDO-RS query.
	 *
	 * @return true|WP_Error
	 */
	public static function ping() {
		$conn = self::get_connection();
		if ( '' === $conn['base_url'] ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_missing_base', __( 'DICOMweb base URL is not configured.', 'mcp-ai-wpoos-pro' ) );
		}
		$url      = trailingslashit( $conn['base_url'] ) . 'studies?limit=1';
		$args     = self::build_args( $conn, 'application/dicom+json' );
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code >= 200 && $code < 400 ) {
			return true;
		}
		return new WP_Error(
			'wp_mcp_ai_dicomweb_unreachable',
			sprintf(
				/* translators: %d: HTTP status code */
				__( 'DICOMweb endpoint returned HTTP %d.', 'mcp-ai-wpoos-pro' ),
				$code
			)
		);
	}

	/**
	 * QIDO-RS: search for studies.
	 *
	 * @param array $params Query parameters (e.g. StudyInstanceUID, PatientID).
	 * @return array|WP_Error
	 */
	public static function qido_studies( array $params = array() ) {
		$conn = self::get_connection();
		if ( '' === $conn['base_url'] ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_missing_base', __( 'DICOMweb base URL is not configured.', 'mcp-ai-wpoos-pro' ) );
		}
		$url = trailingslashit( $conn['base_url'] ) . 'studies';
		if ( ! empty( $params ) ) {
			$url = add_query_arg( array_map( 'rawurlencode', $params ), $url );
		}
		$args     = self::build_args( $conn, 'application/dicom+json' );
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_dicomweb_qido_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'QIDO-RS query failed with HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}
		$body = (string) wp_remote_retrieve_body( $response );
		if ( '' === $body ) {
			return array();
		}
		$decoded = json_decode( $body, true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_invalid_json', __( 'QIDO-RS returned invalid JSON.', 'mcp-ai-wpoos-pro' ) );
		}
		return $decoded;
	}

	/**
	 * WADO-RS: retrieve study metadata (DICOM JSON).
	 *
	 * @param string $study_uid StudyInstanceUID.
	 * @return array|WP_Error
	 */
	public static function wado_study_metadata( $study_uid ) {
		$study_uid = sanitize_text_field( $study_uid );
		if ( '' === $study_uid ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_missing_uid', __( 'StudyInstanceUID is required.', 'mcp-ai-wpoos-pro' ) );
		}
		$conn = self::get_connection();
		if ( '' === $conn['base_url'] ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_missing_base', __( 'DICOMweb base URL is not configured.', 'mcp-ai-wpoos-pro' ) );
		}
		$url      = trailingslashit( $conn['base_url'] ) . 'studies/' . rawurlencode( $study_uid ) . '/metadata';
		$args     = self::build_args( $conn, 'application/dicom+json' );
		$response = wp_remote_get( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_dicomweb_wado_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'WADO-RS metadata fetch failed with HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_invalid_json', __( 'WADO-RS returned invalid JSON.', 'mcp-ai-wpoos-pro' ) );
		}
		return $decoded;
	}

	/**
	 * STOW-RS: store one or more DICOM JSON instance metadata documents to
	 * the connected DICOMweb endpoint.
	 *
	 * The body is sent as `application/dicom+json` (metadata only). Sites
	 * that need to push pixel data should hook the request args filter to
	 * switch to multipart/related and supply the binary parts.
	 *
	 * @param array $instances Array of DICOM JSON instance documents.
	 * @return array|WP_Error
	 */
	public static function stow_instances( array $instances ) {
		if ( empty( $instances ) ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_empty_payload', __( 'No instances supplied to STOW-RS.', 'mcp-ai-wpoos-pro' ) );
		}
		$conn = self::get_connection();
		if ( '' === $conn['base_url'] ) {
			return new WP_Error( 'wp_mcp_ai_dicomweb_missing_base', __( 'DICOMweb base URL is not configured.', 'mcp-ai-wpoos-pro' ) );
		}
		$url                             = trailingslashit( $conn['base_url'] ) . 'studies';
		$args                            = self::build_args( $conn, 'application/dicom+json' );
		$args['headers']['Content-Type'] = 'application/dicom+json';
		$args['method']                  = 'POST';
		$args['body']                    = wp_json_encode( $instances );
		$response                        = wp_remote_request( $url, $args );
		if ( is_wp_error( $response ) ) {
			return $response;
		}
		$code = (int) wp_remote_retrieve_response_code( $response );
		if ( $code < 200 || $code >= 300 ) {
			return new WP_Error(
				'wp_mcp_ai_dicomweb_stow_failed',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'STOW-RS store failed with HTTP %d.', 'mcp-ai-wpoos-pro' ),
					$code
				)
			);
		}
		$body    = (string) wp_remote_retrieve_body( $response );
		$decoded = '' === $body ? array() : json_decode( $body, true );
		return is_array( $decoded ) ? $decoded : array();
	}
}
