<?php
/**
 * Tool that manages contacts and lists in the Brevo (Sendinblue) platform.
 *
 * @package WP_MCP_AI_Pro
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/admin/class-wp-mcp-ai-admin-settings.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';

/**
 * Provides a tool for managing Brevo contacts and contact lists.
 *
 * Brevo (formerly Sendinblue) API docs: https://developers.brevo.com/docs/getting-started
 */
class WP_MCP_AI_Pro_Tool_Manage_Brevo_Contacts implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	const API_BASE = 'https://api.brevo.com/v3';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'manage_brevo_contacts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Manage Brevo Contacts', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Manages contacts and contact lists in Brevo (add, update, remove, list contacts and lists).', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'     => array(
					'type'        => 'string',
					'description' => __( 'Action to perform: list_contacts, get_contact, add_contact, update_contact, remove_contact, list_lists, add_to_list, remove_from_list.', 'mcp-ai-wpoos-pro' ),
					'enum'        => array(
						'list_contacts',
						'get_contact',
						'add_contact',
						'update_contact',
						'remove_contact',
						'list_lists',
						'add_to_list',
						'remove_from_list',
					),
				),
				'email'      => array(
					'type'        => 'string',
					'description' => __( 'Email address of the contact (required for get, add, update, remove, add_to_list, remove_from_list).', 'mcp-ai-wpoos-pro' ),
				),
				'first_name' => array(
					'type'        => 'string',
					'description' => __( 'First name of the contact (used with add_contact or update_contact).', 'mcp-ai-wpoos-pro' ),
				),
				'last_name'  => array(
					'type'        => 'string',
					'description' => __( 'Last name of the contact (used with add_contact or update_contact).', 'mcp-ai-wpoos-pro' ),
				),
				'list_ids'   => array(
					'type'        => 'array',
					'description' => __( 'List IDs to assign or unassign the contact from. Used with add_contact, add_to_list, or remove_from_list.', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'attributes' => array(
					'type'                 => 'object',
					'description'          => __( 'Custom attribute key-value pairs for the contact (used with add_contact or update_contact).', 'mcp-ai-wpoos-pro' ),
					'additionalProperties' => true,
				),
				'limit'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of results to return (default: 10, max: 500).', 'mcp-ai-wpoos-pro' ),
					'default'     => 10,
					'minimum'     => 1,
					'maximum'     => 500,
				),
				'offset'     => array(
					'type'        => 'integer',
					'description' => __( 'Offset for paginating contact results (default: 0).', 'mcp-ai-wpoos-pro' ),
					'default'     => 0,
					'minimum'     => 0,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
		$required_capability = apply_filters( 'wp_mcp_ai_manage_brevo_contacts_capability', $default_capability, $context, $arguments, $this );

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to manage Brevo contacts.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( is_multisite() && $user_id && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos-pro' ) );
		}

		$settings = WP_MCP_AI_Admin_Settings::get_settings();

		$api_key = isset( $settings['brevo_api_key'] ) ? trim( $settings['brevo_api_key'] ) : '';

		if ( '' === $api_key ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_missing_credentials',
				__( 'Brevo API key has not been configured.', 'mcp-ai-wpoos-pro' )
			);
		}

		$action = isset( $arguments['action'] ) ? sanitize_key( $arguments['action'] ) : '';

		if ( empty( $action ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_action', __( 'Action parameter is required.', 'mcp-ai-wpoos-pro' ) );
		}

		switch ( $action ) {
			case 'list_contacts':
				return $this->list_contacts( $arguments, $api_key, $settings );

			case 'get_contact':
				return $this->get_contact( $arguments, $api_key, $settings );

			case 'add_contact':
				return $this->add_contact( $arguments, $api_key, $settings );

			case 'update_contact':
				return $this->update_contact( $arguments, $api_key, $settings );

			case 'remove_contact':
				return $this->remove_contact( $arguments, $api_key, $settings );

			case 'list_lists':
				return $this->list_lists( $arguments, $api_key, $settings );

			case 'add_to_list':
				return $this->add_to_list( $arguments, $api_key, $settings );

			case 'remove_from_list':
				return $this->remove_from_list( $arguments, $api_key, $settings );

			default:
				return new WP_Error( 'wp_mcp_ai_brevo_invalid_action', __( 'Invalid action specified.', 'mcp-ai-wpoos-pro' ) );
		}
	}

	/**
	 * List contacts with optional pagination.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function list_contacts( $arguments, $api_key, $settings ) {
		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;
		$limit  = min( max( $limit, 1 ), 500 );

		$url = add_query_arg(
			array(
				'limit'  => $limit,
				'offset' => $offset,
			),
			self::API_BASE . '/contacts'
		);

		$response = $this->make_api_request( $url, 'GET', null, $api_key, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success'  => true,
			'contacts' => isset( $decoded['contacts'] ) ? $decoded['contacts'] : array(),
			'count'    => isset( $decoded['count'] ) ? absint( $decoded['count'] ) : 0,
		);
	}

	/**
	 * Retrieve a single contact by email address.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function get_contact( $arguments, $api_key, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_email', __( 'Email address is required for get_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		$url      = self::API_BASE . '/contacts/' . rawurlencode( $email );
		$response = $this->make_api_request( $url, 'GET', null, $api_key, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => true,
			'contact' => $decoded,
		);
	}

	/**
	 * Create a new contact.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function add_contact( $arguments, $api_key, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_email', __( 'Email address is required for add_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array( 'email' => $email );

		$attrs = array();

		if ( ! empty( $arguments['first_name'] ) ) {
			$attrs['FIRSTNAME'] = sanitize_text_field( $arguments['first_name'] );
		}

		if ( ! empty( $arguments['last_name'] ) ) {
			$attrs['LASTNAME'] = sanitize_text_field( $arguments['last_name'] );
		}

		if ( ! empty( $arguments['attributes'] ) && is_array( $arguments['attributes'] ) ) {
			foreach ( $arguments['attributes'] as $key => $value ) {
				$clean_key = sanitize_key( $key );
				if ( '' !== $clean_key ) {
					$attrs[ strtoupper( $clean_key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
		}

		if ( ! empty( $attrs ) ) {
			$payload['attributes'] = $attrs;
		}

		if ( ! empty( $arguments['list_ids'] ) && is_array( $arguments['list_ids'] ) ) {
			$payload['listIds'] = array_map( 'absint', $arguments['list_ids'] );
		}

		$url      = self::API_BASE . '/contacts';
		$response = $this->make_api_request( $url, 'POST', $payload, $api_key, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => true,
			'id'      => isset( $decoded['id'] ) ? absint( $decoded['id'] ) : null,
		);
	}

	/**
	 * Update an existing contact.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function update_contact( $arguments, $api_key, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_email', __( 'Email address is required for update_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		$payload = array();
		$attrs   = array();

		if ( ! empty( $arguments['first_name'] ) ) {
			$attrs['FIRSTNAME'] = sanitize_text_field( $arguments['first_name'] );
		}

		if ( ! empty( $arguments['last_name'] ) ) {
			$attrs['LASTNAME'] = sanitize_text_field( $arguments['last_name'] );
		}

		if ( ! empty( $arguments['attributes'] ) && is_array( $arguments['attributes'] ) ) {
			foreach ( $arguments['attributes'] as $key => $value ) {
				$clean_key = sanitize_key( $key );
				if ( '' !== $clean_key ) {
					$attrs[ strtoupper( $clean_key ) ] = is_string( $value ) ? sanitize_text_field( $value ) : $value;
				}
			}
		}

		if ( ! empty( $attrs ) ) {
			$payload['attributes'] = $attrs;
		}

		if ( ! empty( $arguments['list_ids'] ) && is_array( $arguments['list_ids'] ) ) {
			$payload['listIds'] = array_map( 'absint', $arguments['list_ids'] );
		}

		if ( empty( $payload ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_update_data', __( 'At least one field must be provided for update_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		$url      = self::API_BASE . '/contacts/' . rawurlencode( $email );
		$response = $this->make_api_request( $url, 'PUT', $payload, $api_key, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'email'   => $email,
			'message' => __( 'Contact updated successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * Delete a contact by email address.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function remove_contact( $arguments, $api_key, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_email', __( 'Email address is required for remove_contact action.', 'mcp-ai-wpoos-pro' ) );
		}

		$url      = self::API_BASE . '/contacts/' . rawurlencode( $email );
		$response = $this->make_api_request( $url, 'DELETE', null, $api_key, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		return array(
			'success' => true,
			'email'   => $email,
			'message' => __( 'Contact removed successfully.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * List all contact lists.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function list_lists( $arguments, $api_key, $settings ) {
		$limit  = isset( $arguments['limit'] ) ? absint( $arguments['limit'] ) : 10;
		$offset = isset( $arguments['offset'] ) ? absint( $arguments['offset'] ) : 0;
		$limit  = min( max( $limit, 1 ), 500 );

		$url = add_query_arg(
			array(
				'limit'  => $limit,
				'offset' => $offset,
			),
			self::API_BASE . '/contacts/lists'
		);

		$response = $this->make_api_request( $url, 'GET', null, $api_key, $settings );

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		return array(
			'success' => true,
			'lists'   => isset( $decoded['lists'] ) ? $decoded['lists'] : array(),
			'count'   => isset( $decoded['count'] ) ? absint( $decoded['count'] ) : 0,
		);
	}

	/**
	 * Add a contact to one or more lists.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function add_to_list( $arguments, $api_key, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_email', __( 'Email address is required for add_to_list action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['list_ids'] ) || ! is_array( $arguments['list_ids'] ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_list_ids', __( 'At least one list ID is required for add_to_list action.', 'mcp-ai-wpoos-pro' ) );
		}

		$list_ids = array_map( 'absint', $arguments['list_ids'] );

		$payload = array( 'emails' => array( $email ) );

		$errors = array();

		foreach ( $list_ids as $list_id ) {
			$url      = self::API_BASE . '/contacts/lists/' . $list_id . '/contacts/add';
			$response = $this->make_api_request( $url, 'POST', $payload, $api_key, $settings );

			if ( is_wp_error( $response ) ) {
				$errors[] = sprintf( 'List %d: %s', $list_id, $response->get_error_message() );
			}
		}

		if ( count( $errors ) === count( $list_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_add_to_list_failed',
				__( 'Failed to add contact to any list.', 'mcp-ai-wpoos-pro' ),
				array( 'errors' => $errors )
			);
		}

		return array(
			'success'  => true,
			'email'    => $email,
			'list_ids' => $list_ids,
			'errors'   => $errors,
		);
	}

	/**
	 * Remove a contact from one or more lists.
	 *
	 * @param array  $arguments Arguments.
	 * @param string $api_key   API key.
	 * @param array  $settings  Plugin settings.
	 * @return array|WP_Error Result or error.
	 */
	protected function remove_from_list( $arguments, $api_key, $settings ) {
		$email = isset( $arguments['email'] ) ? sanitize_email( $arguments['email'] ) : '';

		if ( empty( $email ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_email', __( 'Email address is required for remove_from_list action.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( empty( $arguments['list_ids'] ) || ! is_array( $arguments['list_ids'] ) ) {
			return new WP_Error( 'wp_mcp_ai_brevo_missing_list_ids', __( 'At least one list ID is required for remove_from_list action.', 'mcp-ai-wpoos-pro' ) );
		}

		$list_ids = array_map( 'absint', $arguments['list_ids'] );

		$payload = array( 'emails' => array( $email ) );

		$errors = array();

		foreach ( $list_ids as $list_id ) {
			$url      = self::API_BASE . '/contacts/lists/' . $list_id . '/contacts/remove';
			$response = $this->make_api_request( $url, 'POST', $payload, $api_key, $settings );

			if ( is_wp_error( $response ) ) {
				$errors[] = sprintf( 'List %d: %s', $list_id, $response->get_error_message() );
			}
		}

		if ( count( $errors ) === count( $list_ids ) ) {
			return new WP_Error(
				'wp_mcp_ai_brevo_remove_from_list_failed',
				__( 'Failed to remove contact from any list.', 'mcp-ai-wpoos-pro' ),
				array( 'errors' => $errors )
			);
		}

		return array(
			'success'  => true,
			'email'    => $email,
			'list_ids' => $list_ids,
			'errors'   => $errors,
		);
	}

	/**
	 * Make an API request to Brevo.
	 *
	 * @param string     $url      Request URL.
	 * @param string     $method   HTTP method (GET, POST, PUT, DELETE).
	 * @param array|null $payload  Request payload (JSON-encoded for body).
	 * @param string     $api_key  Brevo API key.
	 * @param array      $settings Plugin settings.
	 * @return array|WP_Error HTTP response or error.
	 */
	protected function make_api_request( $url, $method, $payload, $api_key, $settings ) {
		$timeout = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 30;

		if ( $timeout <= 0 ) {
			$timeout = 30;
		}

		$args = array(
			'method'  => $method,
			'headers' => array(
				'api-key'      => $api_key,
				'Content-Type' => 'application/json',
				'Accept'       => 'application/json',
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

		if ( ! in_array( (int) $status_code, array( 200, 201, 204 ), true ) ) {
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			$message = __( 'Brevo API request failed.', 'mcp-ai-wpoos-pro' );

			if ( is_array( $decoded ) && isset( $decoded['message'] ) ) {
				$message .= ' ' . $decoded['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_brevo_api_error',
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
			'external-api',         // Calls Brevo API.
			'network-dependent',    // Requires internet connectivity.
			'requires-capability',  // Requires user capabilities.
		);
	}
}
