<?php
/**
 * REST API controller for the Chat Channels inbox dashboard.
 *
 * Provides endpoints for listing conversations and messages, sending replies
 * via the originating platform's Cloud API, tagging contacts, and toggling
 * human takeover. Powers both the WordPress admin inbox UI and any external
 * browser-based dashboards.
 *
 * Namespace : mcp-ai-pro/v1
 * Base route: /chat-channels
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Chat Channels REST controller.
 */
class WP_MCP_AI_Chat_Channels_REST_Controller extends WP_REST_Controller {

	/**
	 * REST namespace.
	 *
	 * @var string
	 */
	protected $namespace = 'mcp-ai-pro/v1';

	/**
	 * Base route.
	 *
	 * @var string
	 */
	protected $rest_base = 'chat-channels';

	/**
	 * Constructor – registers REST routes.
	 */
	public function __construct() {
		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Register REST routes.
	 */
	public function register_routes() {
		// GET /chat-channels/conversations – paginated list of unique conversations.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/conversations',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conversations' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'channel'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'status'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 25,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		// GET /chat-channels/conversations/{contact_id}/messages – messages for one contact.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/conversations/(?P<contact_id>[0-9]+)/messages',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_conversation_messages' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'contact_id' => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'page'     => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page' => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
				),
			)
		);

		// POST /chat-channels/reply – send a manual reply via the platform Cloud API.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/reply',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'send_reply' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'contact_id'  => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'message'     => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_textarea_field',
					),
					'connection_id' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// GET /chat-channels/contacts – paginated contact list.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contacts',
			array(
				'methods'             => WP_REST_Server::READABLE,
				'callback'            => array( $this, 'get_contacts' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'channel'   => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'crm_status' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'tag'       => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'      => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'  => array(
						'type'    => 'integer',
						'default' => 25,
						'minimum' => 1,
						'maximum' => 100,
					),
				),
			)
		);

		// POST /chat-channels/contacts/{id}/tag – add a tag to a contact.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contacts/(?P<id>[0-9]+)/tag',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'add_contact_tag' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'id'  => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'tag' => array(
						'required'          => true,
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
				),
			)
		);

		// POST /chat-channels/contacts/{id}/takeover – enable or disable human takeover.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contacts/(?P<id>[0-9]+)/takeover',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'set_human_takeover' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'id'     => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'enable' => array(
						'required' => true,
						'type'     => 'boolean',
					),
				),
			)
		);

		// POST /chat-channels/contacts/{id}/status – update CRM status.
		register_rest_route(
			$this->namespace,
			'/' . $this->rest_base . '/contacts/(?P<id>[0-9]+)/status',
			array(
				'methods'             => WP_REST_Server::CREATABLE,
				'callback'            => array( $this, 'update_contact_status' ),
				'permission_callback' => array( $this, 'admin_permissions_check' ),
				'args'                => array(
					'id'     => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'status' => array(
						'required' => true,
						'type'     => 'string',
						'enum'     => array( 'new', 'active', 'resolved', 'blocked' ),
					),
				),
			)
		);
	}

	/**
	 * Permission check – require manage_options capability.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return bool|WP_Error
	 */
	public function admin_permissions_check( $request ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found
		if ( ! current_user_can( 'manage_options' ) ) {
			return new WP_Error(
				'rest_forbidden',
				__( 'You do not have permission to access chat channel data.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		return true;
	}

	// =========================================================================
	// Conversations
	// =========================================================================

	/**
	 * Get a paginated list of conversations (one per unique contact).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversations( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0, 'page' => 1, 'per_page' => 25 ) );
		}

		global $wpdb;
		$table    = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( 'cct_status = %s' );
		$values = array( 'publish' );

		$channel = $request->get_param( 'channel' );
		if ( ! empty( $channel ) ) {
			$where[]  = 'channel = %s';
			$values[] = $channel;
		}

		$crm_status = $request->get_param( 'status' );
		if ( ! empty( $crm_status ) ) {
			$where[]  = 'crm_status = %s';
			$values[] = $crm_status;
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$where[]  = '(display_name LIKE %s OR channel_contact_id LIKE %s OR phone_number LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $values ) );

		$values[] = $per_page;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_message_at DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->format_contact( $row );
		}

		return rest_ensure_response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Get messages for a single conversation (identified by contact_id).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation_messages( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) || ! WP_MCP_AI_Channel_Messages_CCT::table_exists() ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
		}

		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
		}

		global $wpdb;

		$contact_id = absint( $request->get_param( 'contact_id' ) );
		$page       = max( 1, (int) $request->get_param( 'page' ) );
		$per_page   = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset     = ( $page - 1 ) * $per_page;

		// Resolve channel + contact ID from the contacts table.
		$contacts_table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$contact = $wpdb->get_row( $wpdb->prepare( "SELECT channel, channel_contact_id FROM {$contacts_table} WHERE _ID = %d LIMIT 1", $contact_id ), ARRAY_A );

		if ( empty( $contact ) ) {
			return new WP_Error(
				'rest_not_found',
				__( 'Contact not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		$messages_table = WP_MCP_AI_Channel_Messages_CCT::get_table_name();

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$messages_table} WHERE channel = %s AND channel_contact_id = %s",
				$contact['channel'],
				$contact['channel_contact_id']
			)
		);

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT * FROM {$messages_table} WHERE channel = %s AND channel_contact_id = %s ORDER BY message_timestamp ASC LIMIT %d OFFSET %d",
				$contact['channel'],
				$contact['channel_contact_id'],
				$per_page,
				$offset
			),
			ARRAY_A
		);

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->format_message( $row );
		}

		return rest_ensure_response(
			array(
				'items'    => $items,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	// =========================================================================
	// Reply
	// =========================================================================

	/**
	 * Send a manual reply to a contact via their originating platform.
	 *
	 * Currently supports WhatsApp Cloud API. Other platforms fire a dedicated
	 * action hook so that their webhook controllers can handle dispatch.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function send_reply( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return new WP_Error( 'rest_unavailable', __( 'Contacts store not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		global $wpdb;
		$contact_id    = absint( $request->get_param( 'contact_id' ) );
		$message_text  = sanitize_textarea_field( $request->get_param( 'message' ) );
		$connection_id = sanitize_text_field( (string) $request->get_param( 'connection_id' ) );

		$contacts_table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM {$contacts_table} WHERE _ID = %d LIMIT 1", $contact_id ), ARRAY_A );

		if ( empty( $contact ) ) {
			return new WP_Error( 'rest_not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$channel            = $contact['channel'];
		$channel_contact_id = $contact['channel_contact_id'];

		$result = false;

		switch ( $channel ) {
			case 'whatsapp':
				$result = $this->send_whatsapp_reply( $channel_contact_id, $message_text, $connection_id );
				break;

			default:
				/**
				 * Fires when a manual reply is requested for a non-WhatsApp channel.
				 *
				 * Third-party channel controllers should hook into this to dispatch the reply.
				 *
				 * @param string $channel            Platform slug.
				 * @param string $channel_contact_id Platform contact ID.
				 * @param string $message_text       Message to send.
				 * @param string $connection_id      Plugin connection identifier.
				 * @param array  $contact            Full contact row.
				 */
				$result = apply_filters(
					'wp_mcp_ai_chat_channels_send_reply',
					false,
					$channel,
					$channel_contact_id,
					$message_text,
					$connection_id,
					$contact
				);
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Record the outbound message.
		if ( class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) ) {
			WP_MCP_AI_Channel_Messages_CCT::insert(
				array(
					'channel'            => $channel,
					'channel_contact_id' => $channel_contact_id,
					'contact_name'       => $contact['display_name'],
					'direction'          => 'outbound',
					'message_id'         => '',
					'message_type'       => 'text',
					'content'            => $message_text,
					'raw_payload'        => array(),
					'status'             => 'sent',
					'connection_id'      => $connection_id,
					'phone_number_id'    => '',
					'timestamp'          => time(),
					'reply_sent'         => 0,
					'assigned_agent'     => '',
				)
			);
		}

		// Bump last_message_at on the contact.
		WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_id );

		return rest_ensure_response(
			array(
				'success' => true,
				'message' => __( 'Reply sent successfully.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Send a text reply via the WhatsApp Cloud API.
	 *
	 * Uses the connection settings stored by the Chat Channels Toolkit settings page.
	 *
	 * @param string $to            Recipient phone number (E.164 without '+').
	 * @param string $message_text  Text to send.
	 * @param string $connection_id Settings connection key.
	 * @return true|WP_Error
	 */
	protected function send_whatsapp_reply( $to, $message_text, $connection_id ) {
		$settings   = get_option( 'wp_mcp_ai_chat_channels_toolkit_settings', array() );
		$connection = $this->resolve_whatsapp_connection( $settings, $connection_id );

		if ( empty( $connection ) ) {
			return new WP_Error(
				'whatsapp_no_connection',
				__( 'No WhatsApp connection found. Please configure the Chat Channels Toolkit settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$access_token    = isset( $connection['access_token'] ) ? $connection['access_token'] : '';
		$phone_number_id = isset( $connection['phone_number_id'] ) ? $connection['phone_number_id'] : '';
		$graph_version   = isset( $connection['graph_version'] ) ? $connection['graph_version'] : 'v19.0';

		if ( empty( $access_token ) || empty( $phone_number_id ) ) {
			return new WP_Error(
				'whatsapp_missing_credentials',
				__( 'WhatsApp access token or phone number ID not configured.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 503 )
			);
		}

		$api_url = sprintf(
			'https://graph.facebook.com/%s/%s/messages',
			rawurlencode( $graph_version ),
			rawurlencode( $phone_number_id )
		);

		$body = array(
			'messaging_product' => 'whatsapp',
			'recipient_type'    => 'individual',
			'to'                => $to,
			'type'              => 'text',
			'text'              => array( 'body' => $message_text ),
		);

		$response = wp_remote_post(
			$api_url,
			array(
				'timeout' => 15,
				'headers' => array(
					'Authorization' => 'Bearer ' . $access_token,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $body ),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'whatsapp_send_failed',
				$response->get_error_message(),
				array( 'status' => 502 )
			);
		}

		$data = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( ! empty( $data['error'] ) ) {
			$error_msg = isset( $data['error']['message'] ) ? $data['error']['message'] : __( 'WhatsApp API error.', 'mcp-ai-wpoos-pro' );
			return new WP_Error( 'whatsapp_api_error', $error_msg, array( 'status' => 502 ) );
		}

		return true;
	}

	/**
	 * Resolve the WhatsApp connection settings array.
	 *
	 * Tries $connection_id first; falls back to the first stored connection.
	 *
	 * @param array  $settings      Chat Channels Toolkit settings option.
	 * @param string $connection_id Optional connection key.
	 * @return array|null
	 */
	protected function resolve_whatsapp_connection( $settings, $connection_id ) {
		$connections = isset( $settings['whatsapp_connections'] ) && is_array( $settings['whatsapp_connections'] )
			? $settings['whatsapp_connections']
			: array();

		if ( ! empty( $connection_id ) && isset( $connections[ $connection_id ] ) ) {
			return $connections[ $connection_id ];
		}

		// Fall back to top-level WhatsApp credentials.
		if ( ! empty( $settings['whatsapp_access_token'] ) && ! empty( $settings['whatsapp_phone_number_id'] ) ) {
			return array(
				'access_token'    => $settings['whatsapp_access_token'],
				'phone_number_id' => $settings['whatsapp_phone_number_id'],
				'graph_version'   => isset( $settings['whatsapp_graph_version'] ) ? $settings['whatsapp_graph_version'] : 'v19.0',
			);
		}

		// Fall back to first connection in array.
		if ( ! empty( $connections ) ) {
			return reset( $connections );
		}

		return null;
	}

	// =========================================================================
	// Contacts / CRM
	// =========================================================================

	/**
	 * Get a paginated list of contacts.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_contacts( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return rest_ensure_response( array( 'items' => array(), 'total' => 0 ) );
		}

		global $wpdb;
		$table    = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		$page     = max( 1, (int) $request->get_param( 'page' ) );
		$per_page = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$offset   = ( $page - 1 ) * $per_page;

		$where  = array( 'cct_status = %s' );
		$values = array( 'publish' );

		$channel = $request->get_param( 'channel' );
		if ( ! empty( $channel ) ) {
			$where[]  = 'channel = %s';
			$values[] = $channel;
		}

		$crm_status = $request->get_param( 'crm_status' );
		if ( ! empty( $crm_status ) ) {
			$where[]  = 'crm_status = %s';
			$values[] = $crm_status;
		}

		$search = $request->get_param( 'search' );
		if ( ! empty( $search ) ) {
			$where[]  = '(display_name LIKE %s OR channel_contact_id LIKE %s OR phone_number LIKE %s OR email LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$tag = $request->get_param( 'tag' );
		if ( ! empty( $tag ) ) {
			$where[]  = 'tags LIKE %s';
			$values[] = '%' . $wpdb->esc_like( '"' . $tag . '"' ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$table} WHERE {$where_sql}", $values ) );

		$values[] = $per_page;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM {$table} WHERE {$where_sql} ORDER BY last_message_at DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->format_contact( $row );
		}

		return rest_ensure_response( array( 'items' => $items, 'total' => $total, 'page' => $page, 'per_page' => $per_page ) );
	}

	/**
	 * Add a tag to a contact.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_contact_tag( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			return new WP_Error( 'rest_unavailable', __( 'Contacts CCT not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$id  = absint( $request->get_param( 'id' ) );
		$tag = sanitize_text_field( $request->get_param( 'tag' ) );

		WP_MCP_AI_Channel_Contacts_CCT::add_tag( $id, $tag );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/**
	 * Enable or disable human takeover for a contact.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_human_takeover( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) ) {
			return new WP_Error( 'rest_unavailable', __( 'Contacts CCT not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$id     = absint( $request->get_param( 'id' ) );
		$enable = (bool) $request->get_param( 'enable' );

		WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $id, $enable );

		return rest_ensure_response(
			array(
				'success'        => true,
				'human_takeover' => $enable,
			)
		);
	}

	/**
	 * Update the CRM status of a contact.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_contact_status( $request ) {
		if ( ! class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) || ! WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return new WP_Error( 'rest_unavailable', __( 'Contacts CCT not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		global $wpdb;
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_key( $request->get_param( 'status' ) );

		$allowed = array( 'new', 'active', 'resolved', 'blocked' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid CRM status.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		$table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'crm_status' => $status ),
			array( '_ID' => $id ),
			array( '%s' ),
			array( '%d' )
		);

		return rest_ensure_response( array( 'success' => true, 'crm_status' => $status ) );
	}

	// =========================================================================
	// Formatters
	// =========================================================================

	/**
	 * Format a raw contact DB row for the REST response.
	 *
	 * @param array $row Raw database row.
	 * @return array
	 */
	protected function format_contact( $row ) {
		$tags = array();
		if ( ! empty( $row['tags'] ) ) {
			$decoded = json_decode( $row['tags'], true );
			$tags    = is_array( $decoded ) ? $decoded : array();
		}

		return array(
			'id'                 => isset( $row['_ID'] ) ? (int) $row['_ID'] : 0,
			'channel'            => isset( $row['channel'] ) ? $row['channel'] : '',
			'channel_contact_id' => isset( $row['channel_contact_id'] ) ? $row['channel_contact_id'] : '',
			'display_name'       => isset( $row['display_name'] ) ? $row['display_name'] : '',
			'phone_number'       => isset( $row['phone_number'] ) ? $row['phone_number'] : '',
			'email'              => isset( $row['email'] ) ? $row['email'] : '',
			'tags'               => $tags,
			'crm_status'         => isset( $row['crm_status'] ) ? $row['crm_status'] : 'new',
			'notes'              => isset( $row['notes'] ) ? $row['notes'] : '',
			'assigned_agent'     => isset( $row['assigned_agent'] ) ? $row['assigned_agent'] : '',
			'human_takeover'     => ! empty( $row['human_takeover'] ),
			'last_message_at'    => isset( $row['last_message_at'] ) ? (int) $row['last_message_at'] : 0,
		);
	}

	/**
	 * Format a raw message DB row for the REST response.
	 *
	 * @param array $row Raw database row.
	 * @return array
	 */
	protected function format_message( $row ) {
		return array(
			'id'                 => isset( $row['_ID'] ) ? (int) $row['_ID'] : 0,
			'channel'            => isset( $row['channel'] ) ? $row['channel'] : '',
			'channel_contact_id' => isset( $row['channel_contact_id'] ) ? $row['channel_contact_id'] : '',
			'contact_name'       => isset( $row['contact_name'] ) ? $row['contact_name'] : '',
			'direction'          => isset( $row['direction'] ) ? $row['direction'] : 'inbound',
			'message_id'         => isset( $row['message_id'] ) ? $row['message_id'] : '',
			'message_type'       => isset( $row['message_type'] ) ? $row['message_type'] : 'text',
			'content'            => isset( $row['content'] ) ? $row['content'] : '',
			'status'             => isset( $row['status'] ) ? $row['status'] : 'received',
			'connection_id'      => isset( $row['connection_id'] ) ? $row['connection_id'] : '',
			'phone_number_id'    => isset( $row['phone_number_id'] ) ? $row['phone_number_id'] : '',
			'timestamp'          => isset( $row['message_timestamp'] ) ? (int) $row['message_timestamp'] : 0,
			'reply_sent'         => ! empty( $row['reply_sent'] ),
			'assigned_agent'     => isset( $row['assigned_agent'] ) ? $row['assigned_agent'] : '',
		);
	}
}
