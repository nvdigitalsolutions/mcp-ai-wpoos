<?php
/**
 * Bitwarden Sync Service
 *
 * Handles bidirectional sync with external Bitwarden/Vaultwarden servers.
 * Supports OAuth2 authentication and REST API communication.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Class WP_MCP_AI_Bitwarden_Sync_Service
 *
 * Sync vault data with external Bitwarden/Vaultwarden server.
 */
class WP_MCP_AI_Bitwarden_Sync_Service {

	/**
	 * Import/Export service instance.
	 *
	 * @var WP_MCP_AI_Bitwarden_Import_Export
	 */
	private $import_export;

	/**
	 * Access token for API requests.
	 *
	 * @var string
	 */
	private $access_token;

	/**
	 * Server base URL.
	 *
	 * @var string
	 */
	private $server_url;

	/**
	 * Constructor.
	 */
	public function __construct() {
		$this->import_export = new WP_MCP_AI_Bitwarden_Import_Export();
	}

	/**
	 * Authenticate with Bitwarden server.
	 *
	 * @param string $server_url      Server base URL (e.g., https://vault.bitwarden.com).
	 * @param string $email           User email.
	 * @param string $master_password Master password or API key.
	 * @param string $auth_method     Authentication method (password, api_key).
	 * @return array|WP_Error         Authentication result or error.
	 */
	public function authenticate( $server_url, $email, $master_password, $auth_method = 'password' ) {
		$this->server_url = trailingslashit( $server_url );

		// Bitwarden uses /identity/connect/token for OAuth2.
		$token_url = $this->server_url . 'identity/connect/token';

		$body = array(
			'grant_type' => 'password',
			'scope'      => 'api offline_access',
			'client_id'  => 'web',
			'username'   => $email,
		);

		if ( 'api_key' === $auth_method ) {
			// API key authentication (client_credentials flow).
			$body['grant_type']    = 'client_credentials';
			$body['client_id']     = $email; // Client ID from Bitwarden.
			$body['client_secret'] = $master_password; // Client secret.
			$body['scope']         = 'api';
			unset( $body['username'] );
		} else {
			// Password authentication (resource owner password flow).
			$body['password'] = $master_password;
		}

		$response = wp_remote_post(
			$token_url,
			array(
				'headers' => array(
					'Content-Type' => 'application/x-www-form-urlencoded',
				),
				'body'    => $body,
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body_data   = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code ) {
			$error_message = isset( $body_data['error_description'] ) ? $body_data['error_description'] : 'Authentication failed';
			return new WP_Error( 'auth_failed', $error_message, array( 'status' => $status_code ) );
		}

		if ( ! isset( $body_data['access_token'] ) ) {
			return new WP_Error( 'no_token', 'No access token received from server' );
		}

		$this->access_token = $body_data['access_token'];

		return array(
			'success'       => true,
			'access_token'  => $body_data['access_token'],
			'refresh_token' => isset( $body_data['refresh_token'] ) ? $body_data['refresh_token'] : null,
			'expires_in'    => isset( $body_data['expires_in'] ) ? absint( $body_data['expires_token'] ) : 3600,
		);
	}

	/**
	 * Sync vault data from Bitwarden server to WordPress.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $token    Access token.
	 * @param array  $options  Sync options.
	 * @return array|WP_Error  Sync result or error.
	 */
	public function sync_from_bitwarden( $user_id, $token, $options = array() ) {
		$this->access_token = $token;

		// Fetch sync data from Bitwarden.
		$sync_data = $this->fetch_sync_data();
		if ( is_wp_error( $sync_data ) ) {
			return $sync_data;
		}

		// Convert sync data to Bitwarden export format.
		$export_json = $this->convert_sync_to_export_format( $sync_data );

		// Import using existing import service.
		return $this->import_export->import_bitwarden_json( $export_json, $user_id, $options );
	}

	/**
	 * Sync vault data from WordPress to Bitwarden server.
	 *
	 * @param int    $user_id  User ID.
	 * @param string $token    Access token.
	 * @param array  $options  Sync options.
	 * @return array|WP_Error  Sync result or error.
	 */
	public function sync_to_bitwarden( $user_id, $token, $options = array() ) {
		$this->access_token = $token;

		// Export WordPress vault to Bitwarden format.
		$export_json = $this->import_export->export_to_bitwarden_json( $user_id, $options );
		if ( is_wp_error( $export_json ) ) {
			return $export_json;
		}

		$export_data = json_decode( $export_json, true );
		if ( json_last_error() !== JSON_ERROR_NONE ) {
			return new WP_Error( 'export_failed', 'Failed to parse export data' );
		}

		$result = array(
			'success'        => false,
			'folders_synced' => 0,
			'items_synced'   => 0,
			'errors'         => array(),
		);

		// Sync folders first.
		if ( isset( $export_data['folders'] ) ) {
			foreach ( $export_data['folders'] as $folder ) {
				$folder_result = $this->create_or_update_folder( $folder );
				if ( ! is_wp_error( $folder_result ) ) {
					++$result['folders_synced'];
				} else {
					$result['errors'][] = $folder_result->get_error_message();
				}
			}
		}

		// Sync items.
		if ( isset( $export_data['items'] ) ) {
			foreach ( $export_data['items'] as $item ) {
				$item_result = $this->create_or_update_item( $item );
				if ( ! is_wp_error( $item_result ) ) {
					++$result['items_synced'];
				} else {
					$result['errors'][] = $item_result->get_error_message();
				}
			}
		}

		$result['success'] = $result['folders_synced'] > 0 || $result['items_synced'] > 0;

		return $result;
	}

	/**
	 * Fetch sync data from Bitwarden server.
	 *
	 * @return array|WP_Error  Sync data or error.
	 */
	private function fetch_sync_data() {
		$sync_url = $this->server_url . 'api/sync';

		$response = wp_remote_get(
			$sync_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
				),
				'timeout' => 30,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = wp_remote_retrieve_body( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error( 'sync_failed', 'Failed to fetch sync data from server', array( 'status' => $status_code ) );
		}

		return json_decode( $body, true );
	}

	/**
	 * Convert Bitwarden sync API response to export format.
	 *
	 * @param array $sync_data  Sync data from API.
	 * @return string           JSON in Bitwarden export format.
	 */
	private function convert_sync_to_export_format( $sync_data ) {
		$export_data = array(
			'encrypted' => false,
			'folders'   => array(),
			'items'     => array(),
		);

		// Convert folders.
		if ( isset( $sync_data['folders'] ) && is_array( $sync_data['folders'] ) ) {
			foreach ( $sync_data['folders'] as $folder ) {
				$export_data['folders'][] = array(
					'id'   => $folder['id'],
					'name' => $folder['name'],
				);
			}
		}

		// Convert ciphers (items).
		if ( isset( $sync_data['ciphers'] ) && is_array( $sync_data['ciphers'] ) ) {
			foreach ( $sync_data['ciphers'] as $cipher ) {
				// Bitwarden sync API returns encrypted data - this would need decryption.
				// For now, we'll note that this requires the encryption key.
				$export_data['items'][] = $this->convert_cipher_to_item( $cipher );
			}
		}

		return wp_json_encode( $export_data );
	}

	/**
	 * Convert Bitwarden cipher to export item format.
	 *
	 * @param array $cipher  Cipher data from sync API.
	 * @return array         Item in export format.
	 */
	private function convert_cipher_to_item( $cipher ) {
		// Note: This is a simplified conversion.
		// Full implementation would require decrypting cipher data with encryption key.
		return array(
			'id'             => isset( $cipher['id'] ) ? $cipher['id'] : '',
			'organizationId' => isset( $cipher['organizationId'] ) ? $cipher['organizationId'] : null,
			'folderId'       => isset( $cipher['folderId'] ) ? $cipher['folderId'] : null,
			'type'           => isset( $cipher['type'] ) ? absint( $cipher['type'] ) : 1,
			'name'           => isset( $cipher['name'] ) ? $cipher['name'] : '',
			'notes'          => isset( $cipher['notes'] ) ? $cipher['notes'] : null,
			'favorite'       => isset( $cipher['favorite'] ) ? (bool) $cipher['favorite'] : false,
			'login'          => isset( $cipher['login'] ) ? $cipher['login'] : null,
			'card'           => isset( $cipher['card'] ) ? $cipher['card'] : null,
			'identity'       => isset( $cipher['identity'] ) ? $cipher['identity'] : null,
			'fields'         => isset( $cipher['fields'] ) ? $cipher['fields'] : array(),
		);
	}

	/**
	 * Create or update folder on Bitwarden server.
	 *
	 * @param array $folder  Folder data.
	 * @return array|WP_Error  Folder ID or error.
	 */
	private function create_or_update_folder( $folder ) {
		$folders_url = $this->server_url . 'api/folders';

		$response = wp_remote_post(
			$folders_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( array( 'name' => $folder['name'] ) ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code && 201 !== $status_code ) {
			return new WP_Error( 'folder_create_failed', 'Failed to create folder on server', array( 'status' => $status_code ) );
		}

		return $body;
	}

	/**
	 * Create or update item on Bitwarden server.
	 *
	 * @param array $item  Item data.
	 * @return array|WP_Error  Item ID or error.
	 */
	private function create_or_update_item( $item ) {
		$ciphers_url = $this->server_url . 'api/ciphers';

		// Convert item to cipher format.
		$cipher = array(
			'type'           => $item['type'],
			'name'           => $item['name'],
			'notes'          => $item['notes'],
			'favorite'       => $item['favorite'],
			'folderId'       => $item['folderId'],
			'organizationId' => $item['organizationId'],
			'login'          => isset( $item['login'] ) ? $item['login'] : null,
			'card'           => isset( $item['card'] ) ? $item['card'] : null,
			'identity'       => isset( $item['identity'] ) ? $item['identity'] : null,
			'fields'         => isset( $item['fields'] ) ? $item['fields'] : array(),
		);

		$response = wp_remote_post(
			$ciphers_url,
			array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $cipher ),
				'timeout' => 15,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );
		$body        = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( 200 !== $status_code && 201 !== $status_code ) {
			$error_message = isset( $body['message'] ) ? $body['message'] : 'Failed to create item on server';
			return new WP_Error( 'item_create_failed', $error_message, array( 'status' => $status_code ) );
		}

		return $body;
	}

	/**
	 * Test connection to Bitwarden server.
	 *
	 * @param string $server_url  Server base URL.
	 * @return array|WP_Error     Connection test result or error.
	 */
	public static function test_connection( $server_url ) {
		$server_url = trailingslashit( $server_url );
		$test_url   = $server_url . 'api/config';

		$response = wp_remote_get(
			$test_url,
			array(
				'timeout' => 10,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 === $status_code ) {
			$body = json_decode( wp_remote_retrieve_body( $response ), true );
			return array(
				'success' => true,
				'version' => isset( $body['version'] ) ? $body['version'] : 'unknown',
				'server'  => isset( $body['server'] ) ? $body['server'] : 'bitwarden',
			);
		}

		return new WP_Error( 'connection_failed', 'Could not connect to Bitwarden server', array( 'status' => $status_code ) );
	}
}
