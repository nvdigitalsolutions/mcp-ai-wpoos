<?php
/**
 * Bitwarden API Client for WP MCP AI
 *
 * Provides methods for interacting with the Bitwarden Vault API.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Bitwarden_Client' ) ) {
	/**
	 * Bitwarden API Client class.
	 */
	class WP_MCP_AI_Bitwarden_Client {
		/**
		 * Bitwarden API base URL.
		 *
		 * @var string
		 */
		private $api_base_url;

		/**
		 * Access token for authentication.
		 *
		 * @var string
		 */
		private $access_token;

		/**
		 * OAuth handler instance.
		 *
		 * @var WP_MCP_AI_Bitwarden_OAuth_Handler
		 */
		private $oauth_handler;

		/**
		 * Constructor.
		 *
		 * @param string $access_token Optional access token. If not provided, will use stored token.
		 */
		public function __construct( $access_token = '' ) {
			$settings           = WP_MCP_AI_Admin_Settings::get_settings();
			$this->api_base_url = ! empty( $settings['bitwarden_api_server'] ) ? $settings['bitwarden_api_server'] : 'https://api.bitwarden.com';

			if ( ! empty( $access_token ) ) {
				$this->access_token = $access_token;
			} else {
				$this->access_token = ! empty( $settings['bitwarden_access_token'] ) ? $settings['bitwarden_access_token'] : '';
			}

			$this->oauth_handler = new WP_MCP_AI_Bitwarden_OAuth_Handler();
		}

		/**
		 * Make an authenticated API request to Bitwarden.
		 *
		 * @param string $endpoint API endpoint (without base URL).
		 * @param string $method HTTP method (GET, POST, PUT, DELETE).
		 * @param array  $body Request body data.
		 * @return array|WP_Error Response data or WP_Error on failure.
		 */
		private function make_request( $endpoint, $method = 'GET', $body = array() ) {
			if ( empty( $this->access_token ) ) {
				return new WP_Error( 'no_access_token', __( 'Bitwarden access token not found. Please connect your Bitwarden account.', 'mcp-ai-wpoos' ) );
			}

			// Check if token is expired and refresh if needed.
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			if ( ! empty( $settings['bitwarden_token_expires'] ) && time() >= $settings['bitwarden_token_expires'] ) {
				$refreshed = $this->oauth_handler->refresh_access_token();
				if ( $refreshed ) {
					// Get the new token.
					$settings           = WP_MCP_AI_Admin_Settings::get_settings();
					$this->access_token = $settings['bitwarden_access_token'];
				}
			}

			$url  = trailingslashit( $this->api_base_url ) . ltrim( $endpoint, '/' );
			$args = array(
				'method'  => $method,
				'headers' => array(
					'Authorization' => 'Bearer ' . $this->access_token,
					'Content-Type'  => 'application/json',
					'Accept'        => 'application/json',
				),
				'timeout' => 30,
			);

			if ( ! empty( $body ) && in_array( $method, array( 'POST', 'PUT', 'PATCH' ), true ) ) {
				$args['body'] = wp_json_encode( $body );
			}

			$response = wp_remote_request( $url, $args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$response_code = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );

			// Handle different response codes.
			if ( 401 === $response_code ) {
				// Unauthorized - try to refresh token and retry once.
				$refreshed = $this->oauth_handler->refresh_access_token();
				if ( $refreshed ) {
					// Get the new token and retry.
					$settings           = WP_MCP_AI_Admin_Settings::get_settings();
					$this->access_token = $settings['bitwarden_access_token'];
					$args['headers']['Authorization'] = 'Bearer ' . $this->access_token;

					$response      = wp_remote_request( $url, $args );
					$response_code = wp_remote_retrieve_response_code( $response );
					$response_body = wp_remote_retrieve_body( $response );
				}

				if ( 401 === $response_code ) {
					return new WP_Error( 'unauthorized', __( 'Bitwarden authentication failed. Please reconnect your account.', 'mcp-ai-wpoos' ) );
				}
			}

			if ( $response_code < 200 || $response_code >= 300 ) {
				$error_data = json_decode( $response_body, true );
				$error_msg  = ! empty( $error_data['message'] ) ? $error_data['message'] : __( 'API request failed', 'mcp-ai-wpoos' );
				return new WP_Error( 'api_error', $error_msg, array( 'status' => $response_code ) );
			}

			$data = json_decode( $response_body, true );
			return null !== $data ? $data : array();
		}

		/**
		 * List vault items (passwords, notes, cards, identities).
		 *
		 * @param array $args Optional arguments for filtering.
		 * @return array|WP_Error Array of vault items or WP_Error on failure.
		 */
		public function list_vault_items( $args = array() ) {
			$defaults = array(
				'type'           => null, // 1=login, 2=note, 3=card, 4=identity.
				'favorite'       => null,
				'organizationId' => null,
				'collectionId'   => null,
			);

			$args     = wp_parse_args( $args, $defaults );
			$endpoint = 'ciphers';

			// Build query parameters.
			$query_params = array();
			if ( null !== $args['type'] ) {
				$query_params['type'] = (int) $args['type'];
			}
			if ( null !== $args['favorite'] ) {
				$query_params['favorite'] = (bool) $args['favorite'];
			}
			if ( null !== $args['organizationId'] ) {
				$query_params['organizationId'] = sanitize_text_field( $args['organizationId'] );
			}
			if ( null !== $args['collectionId'] ) {
				$query_params['collectionId'] = sanitize_text_field( $args['collectionId'] );
			}

			if ( ! empty( $query_params ) ) {
				$endpoint = add_query_arg( $query_params, $endpoint );
			}

			return $this->make_request( $endpoint, 'GET' );
		}

		/**
		 * Get a specific vault item by ID.
		 *
		 * @param string $item_id Vault item ID.
		 * @return array|WP_Error Item data or WP_Error on failure.
		 */
		public function get_vault_item( $item_id ) {
			$item_id  = sanitize_text_field( $item_id );
			$endpoint = 'ciphers/' . $item_id;
			return $this->make_request( $endpoint, 'GET' );
		}

		/**
		 * Create a new vault item.
		 *
		 * @param array $item_data Item data.
		 * @return array|WP_Error Created item data or WP_Error on failure.
		 */
		public function create_vault_item( $item_data ) {
			return $this->make_request( 'ciphers', 'POST', $item_data );
		}

		/**
		 * Update an existing vault item.
		 *
		 * @param string $item_id Vault item ID.
		 * @param array  $item_data Updated item data.
		 * @return array|WP_Error Updated item data or WP_Error on failure.
		 */
		public function update_vault_item( $item_id, $item_data ) {
			$item_id  = sanitize_text_field( $item_id );
			$endpoint = 'ciphers/' . $item_id;
			return $this->make_request( $endpoint, 'PUT', $item_data );
		}

		/**
		 * Delete a vault item.
		 *
		 * @param string $item_id Vault item ID.
		 * @return bool|WP_Error True on success or WP_Error on failure.
		 */
		public function delete_vault_item( $item_id ) {
			$item_id  = sanitize_text_field( $item_id );
			$endpoint = 'ciphers/' . $item_id;
			$result   = $this->make_request( $endpoint, 'DELETE' );

			if ( is_wp_error( $result ) ) {
				return $result;
			}

			return true;
		}

		/**
		 * List organizations.
		 *
		 * @return array|WP_Error Array of organizations or WP_Error on failure.
		 */
		public function list_organizations() {
			return $this->make_request( 'organizations', 'GET' );
		}

		/**
		 * List collections for an organization.
		 *
		 * @param string $organization_id Organization ID.
		 * @return array|WP_Error Array of collections or WP_Error on failure.
		 */
		public function list_collections( $organization_id ) {
			$organization_id = sanitize_text_field( $organization_id );
			$endpoint        = 'organizations/' . $organization_id . '/collections';
			return $this->make_request( $endpoint, 'GET' );
		}

		/**
		 * Search vault items by name or URI.
		 *
		 * @param string $search_term Search term.
		 * @return array|WP_Error Array of matching items or WP_Error on failure.
		 */
		public function search_vault( $search_term ) {
			$search_term = sanitize_text_field( $search_term );
			$all_items   = $this->list_vault_items();

			if ( is_wp_error( $all_items ) ) {
				return $all_items;
			}

			// Filter items based on search term.
			$matching_items = array();
			$data_items     = ! empty( $all_items['data'] ) ? $all_items['data'] : $all_items;

			if ( ! is_array( $data_items ) ) {
				return array();
			}

			foreach ( $data_items as $item ) {
				$name_match = ! empty( $item['name'] ) && false !== stripos( $item['name'], $search_term );
				$uri_match  = false;

				if ( ! empty( $item['login']['uris'] ) && is_array( $item['login']['uris'] ) ) {
					foreach ( $item['login']['uris'] as $uri_obj ) {
						if ( ! empty( $uri_obj['uri'] ) && false !== stripos( $uri_obj['uri'], $search_term ) ) {
							$uri_match = true;
							break;
						}
					}
				}

				if ( $name_match || $uri_match ) {
					$matching_items[] = $item;
				}
			}

			return $matching_items;
		}

		/**
		 * Get account profile information.
		 *
		 * @return array|WP_Error Profile data or WP_Error on failure.
		 */
		public function get_profile() {
			return $this->make_request( 'accounts/profile', 'GET' );
		}

		/**
		 * Sync vault data.
		 *
		 * @return array|WP_Error Sync data or WP_Error on failure.
		 */
		public function sync() {
			return $this->make_request( 'sync', 'GET' );
		}

		/**
		 * Helper method to format vault item for display.
		 *
		 * @param array $item Raw vault item data.
		 * @return array Formatted item data.
		 */
		public static function format_vault_item( $item ) {
			$formatted = array(
				'id'       => $item['id'],
				'name'     => $item['name'],
				'type'     => self::get_item_type_name( $item['type'] ),
				'favorite' => ! empty( $item['favorite'] ),
			);

			// Add type-specific data.
			switch ( $item['type'] ) {
				case 1: // Login.
					$formatted['username'] = ! empty( $item['login']['username'] ) ? $item['login']['username'] : '';
					$formatted['uris']     = ! empty( $item['login']['uris'] ) ? array_column( $item['login']['uris'], 'uri' ) : array();
					break;

				case 2: // Secure Note.
					$formatted['note_type'] = ! empty( $item['secureNote']['type'] ) ? $item['secureNote']['type'] : 0;
					break;

				case 3: // Card.
					$formatted['card_holder'] = ! empty( $item['card']['cardholderName'] ) ? $item['card']['cardholderName'] : '';
					$formatted['brand']       = ! empty( $item['card']['brand'] ) ? $item['card']['brand'] : '';
					$formatted['last_four']   = ! empty( $item['card']['number'] ) ? substr( $item['card']['number'], -4 ) : '';
					break;

				case 4: // Identity.
					$formatted['full_name'] = trim(
						( ! empty( $item['identity']['firstName'] ) ? $item['identity']['firstName'] : '' ) . ' ' .
						( ! empty( $item['identity']['lastName'] ) ? $item['identity']['lastName'] : '' )
					);
					$formatted['email']     = ! empty( $item['identity']['email'] ) ? $item['identity']['email'] : '';
					break;
			}

			return $formatted;
		}

		/**
		 * Get human-readable item type name.
		 *
		 * @param int $type Item type number.
		 * @return string Type name.
		 */
		public static function get_item_type_name( $type ) {
			$types = array(
				1 => 'Login',
				2 => 'Secure Note',
				3 => 'Card',
				4 => 'Identity',
			);

			return isset( $types[ $type ] ) ? $types[ $type ] : 'Unknown';
		}
	}
}
