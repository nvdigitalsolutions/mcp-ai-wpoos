<?php
/**
 * Tool that manages Mailjet contact lists.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for managing Mailjet contact lists.
 */
class WP_MCP_AI_Pro_Tool_Manage_Mailjet_Contacts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_BASE = 'https://api.mailjet.com/v3/REST';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_mailjet_contacts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Mailjet Contacts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages contacts and contact lists in Mailjet (add, remove, list contacts).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'      => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list_contacts, add_contact, remove_contact, list_contactlists.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'list_contacts', 'add_contact', 'remove_contact', 'list_contactlists' ),
				),
				'email'       => array(
					'type'        => 'string',
					'description' => __( 'Email address for add_contact or remove_contact actions.', 'mcp-ai-wpoos-pro' ),
				),
				'name'        => array(
					'type'        => 'string',
					'description' => __( 'Name for the contact (optional, used with add_contact).', 'mcp-ai-wpoos-pro' ),
				),
				'list_id'     => array(
					'type'        => 'integer',
					'description' => __( 'Contact list ID for add_contact or remove_contact actions (optional).', 'mcp-ai-wpoos-pro' ),
				),
				'is_excluded' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to add contact to exclusion list (default: false).', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'limit'       => array(
					'type'        => 'integer',
					'description' => __( 'Number of results to return (default: 10, max: 1000).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 1000,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		$default_capability  = 'manage_options';
		$required_capability = apply_filters( 'wp_mcp_ai_manage_mailjet_contacts_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage Mailjet contacts.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key    = isset( $settings['mailjet_api_key'] ) ? trim( $settings['mailjet_api_key'] ) : '';
		$api_secret = isset( $settings['mailjet_api_secret'] ) ? trim( $settings['mailjet_api_secret'] ) : '';

		if ( '' === $api_key || '' === $api_secret ) {
			return new WP_Error(
				'wp_mcp_ai_mailjet_missing_credentials',
				__( 'Mailjet API credentials have not been configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		if ( empty( $action ) ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_missing_action', __( 'Action parameter is required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'list_contacts':
				return $this->list_contacts( $arguments, $api_key, $api_secret, $settings );

			case 'add_contact':
				return $this->add_contact( $arguments, $api_key, $api_secret, $settings );

			case 'remove_contact':
				return $this->remove_contact( $arguments, $api_key, $api_secret, $settings );

			case 'list_contactlists':
				return $this->list_contactlists( $arguments, $api_key, $api_secret, $settings );

			default:
				return new WP_Error( 'wp_mcp_ai_mailjet_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * List contacts.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key API key.
	 * @param string $api_secret API secret.
	 * @param array  $settings Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function list_contacts( $arguments, $api_key, $api_secret, $settings ) {
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = min( max( $limit, 1 ), 1000 );

		$url = add_query_arg( array( 'Limit' => $limit ), self::API_BASE . '/contact' );

		$response = $this->make_api_request( $url, 'GET', null, $api_key, $api_secret, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success'  => true,
			'contacts' => isset( $decoded['Data'] ) ? $decoded['Data'] : array(),
			'count'    => isset( $decoded['Count'] ) ? absint( $decoded['Count'] ) : 0,
			'total'    => isset( $decoded['Total'] ) ? absint( $decoded['Total'] ) : 0,
		);
	}

	/**
	 * Add a contact.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key API key.
	 * @param string $api_secret API secret.
	 * @param array  $settings Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function add_contact( $arguments, $api_key, $api_secret, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_missing_email', __( 'Email address is required for add_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array(
			'Email'      => $email,
			'IsExcluded' => isset( $arguments['is_excluded'] ) ? (bool) $arguments['is_excluded'] : false,
		);

		if ( ! empty( $arguments['name'] ) ) {
			$payload['Name'] = sanitize_text_field( $arguments['name'] );
		}

		$url = self::API_BASE . '/contact';

		$response = $this->make_api_request( $url, 'POST', $payload, $api_key, $api_secret, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		// If a list_id is provided, add contact to the list.
		if ( ! empty( $arguments['list_id'] ) && isset( $decoded['Data'][0]['ID'] ) ) {
			$contact_id = $decoded['Data'][0]['ID'];
			$list_id    = absint( $arguments['list_id'] );

			$list_payload = array(
				'ContactID' => $contact_id,
				'ListID'    => $list_id,
				'IsActive'  => true,
			);

			$list_url      = self::API_BASE . '/contactslist/' . $list_id . '/managecontact';
			$list_response = $this->make_api_request( $list_url, 'POST', $list_payload, $api_key, $api_secret, $settings );

			if ( is_wp_error( $list_response ) ) {
				return $list_response;
			}
		}

		return array(
			'success' => true,
			'contact' => isset( $decoded['Data'][0] ) ? $decoded['Data'][0] : $decoded,
		);
	}

	/**
	 * Remove a contact.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key API key.
	 * @param string $api_secret API secret.
	 * @param array  $settings Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function remove_contact( $arguments, $api_key, $api_secret, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_missing_email', __( 'Email address is required for remove_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		// First, find the contact ID.
		$find_url  = add_query_arg( array( 'Email' => $email ), self::API_BASE . '/contact' );
		$response  = $this->make_api_request( $find_url, 'GET', null, $api_key, $api_secret, $settings );
		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( empty( $decoded['Data'][0]['ID'] ) ) {
			return new WP_Error( 'wp_mcp_ai_mailjet_contact_not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$contact_id = $decoded['Data'][0]['ID'];

		// Delete the contact.
		$delete_url = self::API_BASE . '/contact/' . $contact_id;
		$response   = $this->make_api_request( $delete_url, 'DELETE', null, $api_key, $api_secret, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success'    => true,
			'contact_id' => $contact_id,
			'message'    => __( 'Contact removed successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List contact lists.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key API key.
	 * @param string $api_secret API secret.
	 * @param array  $settings Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function list_contactlists( $arguments, $api_key, $api_secret, $settings ) {
		$limit = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$limit = min( max( $limit, 1 ), 1000 );

		$url = add_query_arg( array( 'Limit' => $limit ), self::API_BASE . '/contactslist' );

		$response = $this->make_api_request( $url, 'GET', null, $api_key, $api_secret, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => true,
			'lists'   => isset( $decoded['Data'] ) ? $decoded['Data'] : array(),
			'count'   => isset( $decoded['Count'] ) ? absint( $decoded['Count'] ) : 0,
			'total'   => isset( $decoded['Total'] ) ? absint( $decoded['Total'] ) : 0,
		);
	}

	/**
	 * Make an API request to Mailjet.
	 *
	 * @param string      $url URL.
	 * @param string      $method HTTP method.
	 * @param array|null  $payload Request payload.
	 * @param string      $api_key API key.
	 * @param string      $api_secret API secret.
	 * @param array       $settings Settings.
	 * @return array|WP_Error Response or error.
	 */
	protected function make_api_request( $url, $method, $payload, $api_key, $api_secret, $settings ) {
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		if ( $timeout <= 0 ) {
			$timeout = 30;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'Authorization' => 'Basic ' . base64_encode( $api_key . ':' . $api_secret ),
				'Content-Type'  => 'application/json',
			),
			'timeout' => $timeout,
		);

		if ( null !== $payload ) {
			$args['body'] = wp_json_encode( $payload );
		}

		$response = wp_remote_request( $url, $args );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( ! in_array( (int) $status_code, array( 200, 201 ), true ) ) {
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			$message = __( 'Mailjet API request failed.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['ErrorMessage'] ) ) {
				$message .= ' ' . $decoded['ErrorMessage'];
			}

			return new WP_Error(
				'wp_mcp_ai_mailjet_api_error',
				$message,
				array(
					'status_code' => $status_code,
					'response'    => $decoded,
				)
			);
		}

		return $response;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'write',                // Modifies contacts.
			'external-api',         // Calls Mailjet API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
