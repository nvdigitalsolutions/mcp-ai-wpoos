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
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
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
					'channel'           => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'status'            => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'conversation_type' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'            => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'              => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'          => array(
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
					'contact_id'       => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'page'             => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'         => array(
						'type'    => 'integer',
						'default' => 50,
						'minimum' => 1,
						'maximum' => 200,
					),
					'include_metadata' => array(
						'type'    => 'boolean',
						'default' => false,
					),
					'source'           => array(
						'type'              => 'string',
						'default'           => '',
						'sanitize_callback' => 'sanitize_key',
						'description'       => __( 'Store hint: "cct" or "cpt". When provided, the endpoint queries the specified store first.', 'mcp-ai-wpoos-pro' ),
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
					'contact_id'    => array(
						'required' => true,
						'type'     => 'integer',
						'minimum'  => 1,
					),
					'message'       => array(
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
					'channel'    => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'crm_status' => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_key',
					),
					'search'     => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'tag'        => array(
						'type'              => 'string',
						'sanitize_callback' => 'sanitize_text_field',
					),
					'page'       => array(
						'type'    => 'integer',
						'default' => 1,
						'minimum' => 1,
					),
					'per_page'   => array(
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
		$page              = max( 1, (int) $request->get_param( 'page' ) );
		$per_page          = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$channel           = $request->get_param( 'channel' );
		$crm_status        = $request->get_param( 'status' );
		$search            = $request->get_param( 'search' );
		$conversation_type = $request->get_param( 'conversation_type' );

		$cct_available = class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists();
		$cpt_available = class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' );

		// When both stores exist, merge contacts so that legacy CPT contacts
		// (created before CCT was enabled) are not lost.
		if ( $cct_available && $cpt_available ) {
			return $this->get_conversations_merged( $page, $per_page, $channel, $crm_status, $search, $conversation_type );
		}

		// CCT-only path.
		if ( $cct_available ) {
			return $this->get_conversations_from_cct( $page, $per_page, $channel, $crm_status, $search, $conversation_type );
		}

		// CPT-only path.
		if ( $cpt_available ) {
			return $this->get_conversations_from_cpt( $page, $per_page, $channel, $crm_status, $search );
		}

		return rest_ensure_response(
			array(
				'items'    => array(),
				'total'    => 0,
				'page'     => 1,
				'per_page' => 25,
			)
		);
	}

	/**
	 * Fetch conversations from the CCT table.
	 *
	 * @param int    $page              Page number.
	 * @param int    $per_page          Items per page.
	 * @param string $channel           Optional channel filter.
	 * @param string $crm_status        Optional CRM status filter.
	 * @param string $search            Optional search term.
	 * @param string $conversation_type Optional conversation type filter.
	 * @return WP_REST_Response
	 */
	protected function get_conversations_from_cct( $page, $per_page, $channel, $crm_status, $search, $conversation_type = '' ) {
		global $wpdb;
		$table  = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		$offset = ( $page - 1 ) * $per_page;

		$where  = array( 'cct_status = %s' );
		$values = array( 'publish' );

		if ( ! empty( $channel ) ) {
			$where[]  = 'channel = %s';
			$values[] = $channel;
		}

		if ( ! empty( $crm_status ) ) {
			$where[]  = 'crm_status = %s';
			$values[] = $crm_status;
		}

		if ( ! empty( $conversation_type ) ) {
			$where[]  = 'conversation_type = %s';
			$values[] = $conversation_type;
		}

		if ( ! empty( $search ) ) {
			$where[]  = '(display_name LIKE %s OR channel_contact_id LIKE %s OR phone_number LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $values ) );

		$values[] = $per_page;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY last_message_at DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );

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
	 * Fetch conversations from the CPT store.
	 *
	 * @param int    $page       Page number.
	 * @param int    $per_page   Items per page.
	 * @param string $channel    Optional channel filter.
	 * @param string $crm_status Optional CRM status filter.
	 * @param string $search     Optional search term.
	 * @return WP_REST_Response
	 */
	protected function get_conversations_from_cpt( $page, $per_page, $channel, $crm_status, $search ) {
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $channel ) ) {
			$meta_query[] = array(
				'key'     => '_channel',
				'value'   => $channel,
				'compare' => '=',
			);
		}

		if ( ! empty( $crm_status ) ) {
			$meta_query[] = array(
				'key'     => '_crm_status',
				'value'   => $crm_status,
				'compare' => '=',
			);
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_last_message_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$total = (int) $query->found_posts;
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->format_contact( WP_MCP_AI_Channel_Contacts_CPT::post_to_row( $post ) );
		}

		wp_reset_postdata();

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
	 * Merge conversations from both CCT and CPT stores.
	 *
	 * Contacts that exist in both stores (matched by channel + channel_contact_id
	 * + connection_id) are represented by the CCT record. CPT-only contacts
	 * are appended so they remain visible in the inbox during the transition
	 * from CPT to CCT storage.
	 *
	 * @param int    $page              Page number.
	 * @param int    $per_page          Items per page.
	 * @param string $channel           Optional channel filter.
	 * @param string $crm_status        Optional CRM status filter.
	 * @param string $search            Optional search term.
	 * @param string $conversation_type Optional conversation type filter.
	 * @return WP_REST_Response
	 */
	protected function get_conversations_merged( $page, $per_page, $channel, $crm_status, $search, $conversation_type = '' ) {
		// Fetch all matching contacts from CCT (unpaginated so we can merge).
		$cct_items = $this->get_conversations_items_from_cct( $channel, $crm_status, $search, $conversation_type );

		// Build a lookup set of channel+contact_id+connection keys already in CCT.
		$cct_keys = array();
		foreach ( $cct_items as $item ) {
			$key              = $item['channel'] . '|' . $item['channel_contact_id'] . '|' . $item['connection_id'];
			$cct_keys[ $key ] = true;
		}

		// Fetch CPT contacts and append any that are not already in CCT.
		$cpt_items = $this->get_conversations_items_from_cpt( $channel, $crm_status, $search );
		foreach ( $cpt_items as $item ) {
			$key = $item['channel'] . '|' . $item['channel_contact_id'] . '|' . $item['connection_id'];
			if ( ! isset( $cct_keys[ $key ] ) ) {
				// Mark the item source so the messages endpoint knows which store to query.
				$item['_source'] = 'cpt';
				$cct_items[]     = $item;
			}
		}

		// Sort merged list by last_message_at descending.
		usort(
			$cct_items,
			function ( $a, $b ) {
				return $b['last_message_at'] - $a['last_message_at'];
			}
		);

		$total  = count( $cct_items );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $cct_items, $offset, $per_page );

		return rest_ensure_response(
			array(
				'items'    => $paged,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Retrieve all matching conversation items from CCT (no pagination).
	 *
	 * @param string $channel           Optional channel filter.
	 * @param string $crm_status        Optional CRM status filter.
	 * @param string $search            Optional search term.
	 * @param string $conversation_type Optional conversation type filter.
	 * @return array[] Formatted contact items.
	 */
	protected function get_conversations_items_from_cct( $channel, $crm_status, $search, $conversation_type = '' ) {
		global $wpdb;
		$table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();

		$where  = array( 'cct_status = %s' );
		$values = array( 'publish' );

		if ( ! empty( $channel ) ) {
			$where[]  = 'channel = %s';
			$values[] = $channel;
		}

		if ( ! empty( $crm_status ) ) {
			$where[]  = 'crm_status = %s';
			$values[] = $crm_status;
		}

		if ( ! empty( $conversation_type ) ) {
			$where[]  = 'conversation_type = %s';
			$values[] = $conversation_type;
		}

		if ( ! empty( $search ) ) {
			$where[]  = '(display_name LIKE %s OR channel_contact_id LIKE %s OR phone_number LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY last_message_at DESC", $values ), ARRAY_A );

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->format_contact( $row );
		}

		return $items;
	}

	/**
	 * Retrieve all matching conversation items from CPT (no pagination).
	 *
	 * @param string $channel    Optional channel filter.
	 * @param string $crm_status Optional CRM status filter.
	 * @param string $search     Optional search term.
	 * @return array[] Formatted contact items.
	 */
	protected function get_conversations_items_from_cpt( $channel, $crm_status, $search ) {
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $channel ) ) {
			$meta_query[] = array(
				'key'     => '_channel',
				'value'   => $channel,
				'compare' => '=',
			);
		}

		if ( ! empty( $crm_status ) ) {
			$meta_query[] = array(
				'key'     => '_crm_status',
				'value'   => $crm_status,
				'compare' => '=',
			);
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 200, // phpcs:ignore -- Reasonable upper bound for merging. If more exist, they appear only in the CCT store going forward.
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_last_message_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
			'no_found_rows'  => true,
		);

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->format_contact( WP_MCP_AI_Channel_Contacts_CPT::post_to_row( $post ) );
		}

		wp_reset_postdata();

		return $items;
	}

	/**
	 * Get messages for a single conversation (identified by contact_id).
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function get_conversation_messages( $request ) {
		$contact_id       = absint( $request->get_param( 'contact_id' ) );
		$page             = max( 1, (int) $request->get_param( 'page' ) );
		$per_page         = min( 200, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$include_metadata = rest_sanitize_boolean( $request->get_param( 'include_metadata' ) );
		$source           = sanitize_key( (string) $request->get_param( 'source' ) );

		$cct_contacts_ok = class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists();
		$cct_messages_ok = class_exists( 'WP_MCP_AI_Channel_Messages_CCT' ) && WP_MCP_AI_Channel_Messages_CCT::table_exists();
		$cpt_contacts_ok = class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' );
		$cpt_messages_ok = class_exists( 'WP_MCP_AI_Channel_Messages_CPT' );

		// Resolve the contact from whichever store has it.
		// When the client passes source=cpt (set by the merged conversations
		// endpoint for CPT-only contacts), try the CPT store first so that a
		// coincidental CCT _ID match doesn't shadow the real contact.
		$channel            = '';
		$channel_contact_id = '';
		$connection_id      = '';

		$try_cct_first = 'cpt' !== $source;

		if ( $try_cct_first && $cct_contacts_ok ) {
			$this->resolve_contact_from_cct( $contact_id, $channel, $channel_contact_id, $connection_id );
		}

		// Fall back to CPT when CCT did not yield a usable contact.
		if ( ( '' === $channel || '' === $channel_contact_id ) && $cpt_contacts_ok ) {
			$this->resolve_contact_from_cpt( $contact_id, $channel, $channel_contact_id, $connection_id );
		}

		// If source=cpt was requested but CPT didn't resolve, still try CCT.
		if ( ( '' === $channel || '' === $channel_contact_id ) && ! $try_cct_first && $cct_contacts_ok ) {
			$this->resolve_contact_from_cct( $contact_id, $channel, $channel_contact_id, $connection_id );
		}

		if ( '' === $channel || '' === $channel_contact_id ) {
			return new WP_Error( 'rest_not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		// For Telegram only, scope messages by connection_id using an inclusive
		// filter so that legacy messages stored without a connection_id are still
		// returned. Other channels do not filter by connection_id.
		$scope_connection_id = ( 'telegram' === $channel && '' !== $connection_id ) ? $connection_id : '';

		// Collect messages from all available stores and merge them.
		$all_messages = array();

		// CCT messages.
		if ( $cct_messages_ok ) {
			$all_messages = array_merge( $all_messages, $this->fetch_messages_from_cct( $channel, $channel_contact_id, $include_metadata, $scope_connection_id ) );
		}

		// CPT messages.
		if ( $cpt_messages_ok ) {
			$all_messages = array_merge( $all_messages, $this->fetch_messages_from_cpt( $channel, $channel_contact_id, $include_metadata, $scope_connection_id ) );
		}

		// Deduplicate by message_id (platform message ID) when non-empty, otherwise by store-scoped composite key.
		$all_messages = $this->deduplicate_messages( $all_messages );

		// Sort newest first so page 1 contains the most recent messages.
		usort(
			$all_messages,
			function ( $a, $b ) {
				return $b['timestamp'] - $a['timestamp'];
			}
		);

		$total  = count( $all_messages );
		$offset = ( $page - 1 ) * $per_page;
		$paged  = array_slice( $all_messages, $offset, $per_page );

		// Reverse the page slice so messages display in chronological order
		// (oldest at top, newest at bottom) within the visible page.
		$paged = array_reverse( $paged );

		return rest_ensure_response(
			array(
				'items'    => $paged,
				'total'    => $total,
				'page'     => $page,
				'per_page' => $per_page,
			)
		);
	}

	/**
	 * Resolve contact channel, channel_contact_id, and connection_id from the CCT store.
	 *
	 * @param int    $contact_id          CCT row _ID.
	 * @param string $channel             Reference to channel (populated on success).
	 * @param string $channel_contact_id  Reference to channel_contact_id (populated on success).
	 * @param string $connection_id       Optional. Reference to connection_id (populated on success).
	 *                                    Callers must pass a variable by reference to receive the value.
	 */
	protected function resolve_contact_from_cct( $contact_id, &$channel, &$channel_contact_id, &$connection_id = '' ) {
		global $wpdb;
		$contacts_table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// Use SELECT * so the query succeeds even if the optional connection_id
		// column has not been added yet (older installations that created the CCT
		// before the schema was extended). The isset() check below handles the
		// missing key gracefully.
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$contacts_table}` WHERE _ID = %d LIMIT 1", $contact_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! empty( $contact ) && '' !== (string) $contact['channel'] && '' !== (string) $contact['channel_contact_id'] ) {
			$channel            = (string) $contact['channel'];
			$channel_contact_id = (string) $contact['channel_contact_id'];
			$connection_id      = isset( $contact['connection_id'] ) ? (string) $contact['connection_id'] : '';
		}
	}

	/**
	 * Resolve contact channel, channel_contact_id, and connection_id from the CPT store.
	 *
	 * @param int    $contact_id          WordPress post ID.
	 * @param string $channel             Reference to channel (populated on success).
	 * @param string $channel_contact_id  Reference to channel_contact_id (populated on success).
	 * @param string $connection_id       Optional. Reference to connection_id (populated on success).
	 *                                    Callers must pass a variable by reference to receive the value.
	 */
	protected function resolve_contact_from_cpt( $contact_id, &$channel, &$channel_contact_id, &$connection_id = '' ) {
		$contact_post = get_post( $contact_id );
		if ( $contact_post && WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE === $contact_post->post_type ) {
			$ch  = (string) get_post_meta( $contact_id, '_channel', true );
			$cid = (string) get_post_meta( $contact_id, '_channel_contact_id', true );
			if ( '' !== $ch && '' !== $cid ) {
				$channel            = $ch;
				$channel_contact_id = $cid;
				$connection_id      = (string) get_post_meta( $contact_id, '_connection_id', true );
			}
		}
	}

	/**
	 * Fetch conversation messages from the CCT table.
	 *
	 * @param int  $contact_id       CCT contact ID.
	 * @param int  $page             Page number.
	 * @param int  $per_page         Items per page.
	 * @param bool $include_metadata Whether to include decoded raw payload.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function get_conversation_messages_from_cct( $contact_id, $page, $per_page, $include_metadata ) {
		global $wpdb;
		$offset = ( $page - 1 ) * $per_page;

		// Resolve channel + contact ID from the contacts CCT table.
		$contacts_table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		// Use SELECT * so the query succeeds even if the optional connection_id
		// column has not been added yet (older installations).
		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$contacts_table}` WHERE _ID = %d LIMIT 1", $contact_id ), ARRAY_A ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		if ( empty( $contact ) ) {
			return new WP_Error( 'rest_not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$messages_table = WP_MCP_AI_Channel_Messages_CCT::get_table_name();

		// For Telegram, scope messages by connection_id using an inclusive filter
		// so that legacy messages stored without a connection_id are still returned.
		// Other channels query all messages for the channel + contact pair.
		$contact_connection_id = isset( $contact['connection_id'] ) ? (string) $contact['connection_id'] : '';
		$scope_connection_id   = ( 'telegram' === $contact['channel'] && '' !== $contact_connection_id ) ? $contact_connection_id : '';

		if ( '' !== $scope_connection_id ) {
			// Inclusive filter: match the specific connection_id OR legacy rows
			// where connection_id was never set (empty string or NULL).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$messages_table}` WHERE channel = %s AND channel_contact_id = %s AND (connection_id = %s OR connection_id = '' OR connection_id IS NULL)", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted CCT helper.
					$contact['channel'],
					$contact['channel_contact_id'],
					$scope_connection_id
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$messages_table}` WHERE channel = %s AND channel_contact_id = %s AND (connection_id = %s OR connection_id = '' OR connection_id IS NULL) ORDER BY message_timestamp ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted CCT helper.
					$contact['channel'],
					$contact['channel_contact_id'],
					$scope_connection_id,
					$per_page,
					$offset
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$total = (int) $wpdb->get_var(
				$wpdb->prepare(
					"SELECT COUNT(*) FROM `{$messages_table}` WHERE channel = %s AND channel_contact_id = %s", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted CCT helper.
					$contact['channel'],
					$contact['channel_contact_id']
				)
			);

			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$messages_table}` WHERE channel = %s AND channel_contact_id = %s ORDER BY message_timestamp ASC LIMIT %d OFFSET %d", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared -- Table name from trusted CCT helper.
					$contact['channel'],
					$contact['channel_contact_id'],
					$per_page,
					$offset
				),
				ARRAY_A
			);
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$items[] = $this->format_message( $row, $include_metadata );
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
	 * Fetch conversation messages from the CPT store.
	 *
	 * @param int  $contact_id       CPT contact post ID.
	 * @param int  $page             Page number.
	 * @param int  $per_page         Items per page.
	 * @param bool $include_metadata Whether to include decoded raw payload.
	 * @return WP_REST_Response|WP_Error
	 */
	protected function get_conversation_messages_from_cpt( $contact_id, $page, $per_page, $include_metadata ) {
		$contact_post = get_post( $contact_id );
		if ( ! $contact_post || WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE !== $contact_post->post_type ) {
			return new WP_Error( 'rest_not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		$channel            = (string) get_post_meta( $contact_id, '_channel', true );
		$channel_contact_id = (string) get_post_meta( $contact_id, '_channel_contact_id', true );
		$connection_id      = (string) get_post_meta( $contact_id, '_connection_id', true );

		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_channel',
				'value'   => $channel,
				'compare' => '=',
			),
			array(
				'key'     => '_channel_contact_id',
				'value'   => $channel_contact_id,
				'compare' => '=',
			),
		);

		if ( 'telegram' === $channel && '' !== $connection_id ) {
			// Inclusive filter for Telegram: match the specific connection_id OR
			// legacy messages stored with an empty or missing connection_id.
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_connection_id',
					'value'   => $connection_id,
					'compare' => '=',
				),
				array(
					'key'     => '_connection_id',
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => '_connection_id',
					'compare' => 'NOT EXISTS',
				),
			);
		} elseif ( '' !== $connection_id ) {
			$meta_query[] = array(
				'key'     => '_connection_id',
				'value'   => $connection_id,
				'compare' => '=',
			);
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_message_timestamp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'ASC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'no_found_rows'  => false,
		);

		$query = new WP_Query( $args );
		$total = (int) $query->found_posts;
		$items = array();

		foreach ( $query->posts as $post ) {
			$row     = WP_MCP_AI_Channel_Messages_CPT::post_to_row( $post, $include_metadata );
			$items[] = $this->format_message( $row, $include_metadata );
		}

		wp_reset_postdata();

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
	 * Fetch all messages from the CCT table for a given channel + contact.
	 *
	 * When $connection_id is non-empty an inclusive filter is applied so that
	 * messages stored under the specific connection_id, an empty connection_id,
	 * or a NULL connection_id are all returned. This prevents legacy messages
	 * (stored before connection_id tracking) from disappearing.
	 *
	 * @param string $channel            Platform slug.
	 * @param string $channel_contact_id Platform contact identifier.
	 * @param bool   $include_metadata   Whether to include raw payload.
	 * @param string $connection_id      Optional connection_id for inclusive scoping (Telegram only).
	 * @return array[] Formatted message items.
	 */
	protected function fetch_messages_from_cct( $channel, $channel_contact_id, $include_metadata = false, $connection_id = '' ) {
		global $wpdb;
		$messages_table = WP_MCP_AI_Channel_Messages_CCT::get_table_name();

		if ( '' !== $connection_id ) {
			// Inclusive filter: match the specific connection_id OR legacy rows
			// where connection_id was never set (empty string or NULL).
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$messages_table}` WHERE channel = %s AND channel_contact_id = %s AND (connection_id = %s OR connection_id = '' OR connection_id IS NULL) ORDER BY message_timestamp ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$channel,
					$channel_contact_id,
					$connection_id
				),
				ARRAY_A
			);
		} else {
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$rows = $wpdb->get_results(
				$wpdb->prepare(
					"SELECT * FROM `{$messages_table}` WHERE channel = %s AND channel_contact_id = %s ORDER BY message_timestamp ASC", // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					$channel,
					$channel_contact_id
				),
				ARRAY_A
			);
		}

		$items = array();
		foreach ( (array) $rows as $row ) {
			$item           = $this->format_message( $row, $include_metadata );
			$item['_store'] = 'cct';
			$items[]        = $item;
		}

		return $items;
	}

	/**
	 * Fetch all messages from the CPT store for a given channel + contact.
	 *
	 * When $connection_id is non-empty an inclusive meta_query is applied so
	 * that messages stored under the specific connection_id, an empty value,
	 * or without the meta key entirely are all returned.
	 *
	 * @param string $channel            Platform slug.
	 * @param string $channel_contact_id Platform contact identifier.
	 * @param bool   $include_metadata   Whether to include raw payload.
	 * @param string $connection_id      Optional connection_id for inclusive scoping (Telegram only).
	 * @return array[] Formatted message items.
	 */
	protected function fetch_messages_from_cpt( $channel, $channel_contact_id, $include_metadata = false, $connection_id = '' ) {
		$meta_query = array(
			'relation' => 'AND',
			array(
				'key'     => '_channel',
				'value'   => $channel,
				'compare' => '=',
			),
			array(
				'key'     => '_channel_contact_id',
				'value'   => $channel_contact_id,
				'compare' => '=',
			),
		);

		if ( '' !== $connection_id ) {
			$meta_query[] = array(
				'relation' => 'OR',
				array(
					'key'     => '_connection_id',
					'value'   => $connection_id,
					'compare' => '=',
				),
				array(
					'key'     => '_connection_id',
					'value'   => '',
					'compare' => '=',
				),
				array(
					'key'     => '_connection_id',
					'compare' => 'NOT EXISTS',
				),
			);
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Channel_Messages_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => 500, // phpcs:ignore -- Upper bound for merging across stores. The CCT query has no hard limit; this cap prevents runaway WP_Query on large CPT datasets.
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_message_timestamp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'ASC',
			'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
			'no_found_rows'  => true,
		);

		$query = new WP_Query( $args );
		$items = array();

		foreach ( $query->posts as $post ) {
			$row            = WP_MCP_AI_Channel_Messages_CPT::post_to_row( $post, $include_metadata );
			$item           = $this->format_message( $row, $include_metadata );
			$item['_store'] = 'cpt';
			$items[]        = $item;
		}

		wp_reset_postdata();

		return $items;
	}

	/**
	 * Remove duplicate messages that appear in both CCT and CPT stores.
	 *
	 * Deduplication uses the platform message_id when available. Messages
	 * without a message_id are kept as-is since they cannot be reliably
	 * matched across stores.
	 *
	 * @param array[] $messages Formatted message items.
	 * @return array[] Deduplicated messages (CCT wins over CPT).
	 */
	protected function deduplicate_messages( array $messages ) {
		$seen = array();
		$out  = array();

		foreach ( $messages as $msg ) {
			$mid = isset( $msg['message_id'] ) ? $msg['message_id'] : '';

			if ( '' !== $mid ) {
				if ( isset( $seen[ $mid ] ) ) {
					// Prefer the CCT version when duplicated.
					if ( 'cct' === ( $msg['_store'] ?? '' ) && 'cpt' === ( $out[ $seen[ $mid ] ]['_store'] ?? '' ) ) {
						$out[ $seen[ $mid ] ] = $msg;
					}
					continue;
				}
				$seen[ $mid ] = count( $out );
			}

			$out[] = $msg;
		}

		return array_values( $out );
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
		$cct_available = class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists();
		$cpt_available = class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' );

		if ( ! $cct_available && ! $cpt_available ) {
			return new WP_Error( 'rest_unavailable', __( 'Contacts store not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
		}

		$contact_id    = absint( $request->get_param( 'contact_id' ) );
		$message_text  = sanitize_textarea_field( $request->get_param( 'message' ) );
		$connection_id = sanitize_text_field( (string) $request->get_param( 'connection_id' ) );

		// Resolve contact row – try CCT first, then CPT.
		$contact = null;

		if ( $cct_available ) {
			global $wpdb;
			$contacts_table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
			// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$contact = $wpdb->get_row( $wpdb->prepare( "SELECT * FROM `{$contacts_table}` WHERE _ID = %d LIMIT 1", $contact_id ), ARRAY_A );
		}

		if ( empty( $contact ) && $cpt_available ) {
			$contact_post = get_post( $contact_id );
			if ( $contact_post && WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE === $contact_post->post_type ) {
				$contact = WP_MCP_AI_Channel_Contacts_CPT::post_to_row( $contact_post );
			}
		}

		if ( empty( $contact ) ) {
			return new WP_Error( 'rest_not_found', __( 'Contact not found.', 'mcp-ai-wpoos-pro' ), array( 'status' => 404 ) );
		}

		// When connection_id is not explicitly supplied by the client, resolve it
		// from the contact record so replies always go via the correct connection.
		if ( '' === $connection_id && ! empty( $contact['connection_id'] ) ) {
			$connection_id = sanitize_text_field( $contact['connection_id'] );
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

		// Record the outbound message – CCT::insert() already falls back to CPT internally.
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
		} elseif ( class_exists( 'WP_MCP_AI_Channel_Messages_CPT' ) ) {
			WP_MCP_AI_Channel_Messages_CPT::insert(
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
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			WP_MCP_AI_Channel_Contacts_CCT::touch( $contact_id );
		} elseif ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
			WP_MCP_AI_Channel_Contacts_CPT::touch( $contact_id );
		}

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
		$page       = max( 1, (int) $request->get_param( 'page' ) );
		$per_page   = min( 100, max( 1, (int) $request->get_param( 'per_page' ) ) );
		$channel    = $request->get_param( 'channel' );
		$crm_status = $request->get_param( 'crm_status' );
		$search     = $request->get_param( 'search' );
		$tag        = $request->get_param( 'tag' );

		// CCT path (preferred when JetEngine table exists).
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			return $this->get_contacts_from_cct( $page, $per_page, $channel, $crm_status, $search, $tag );
		}

		// CPT fallback path.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
			return $this->get_contacts_from_cpt( $page, $per_page, $channel, $crm_status, $search, $tag );
		}

		return rest_ensure_response(
			array(
				'items' => array(),
				'total' => 0,
			)
		);
	}

	/**
	 * Fetch contacts from the CCT table.
	 *
	 * @param int    $page       Page number.
	 * @param int    $per_page   Items per page.
	 * @param string $channel    Optional channel filter.
	 * @param string $crm_status Optional CRM status filter.
	 * @param string $search     Optional search term.
	 * @param string $tag        Optional tag filter.
	 * @return WP_REST_Response
	 */
	protected function get_contacts_from_cct( $page, $per_page, $channel, $crm_status, $search, $tag ) {
		global $wpdb;
		$table  = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
		$offset = ( $page - 1 ) * $per_page;

		$where  = array( 'cct_status = %s' );
		$values = array( 'publish' );

		if ( ! empty( $channel ) ) {
			$where[]  = 'channel = %s';
			$values[] = $channel;
		}

		if ( ! empty( $crm_status ) ) {
			$where[]  = 'crm_status = %s';
			$values[] = $crm_status;
		}

		if ( ! empty( $search ) ) {
			$where[]  = '(display_name LIKE %s OR channel_contact_id LIKE %s OR phone_number LIKE %s OR email LIKE %s)';
			$like     = '%' . $wpdb->esc_like( $search ) . '%';
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
			$values[] = $like;
		}

		if ( ! empty( $tag ) ) {
			$where[]  = 'tags LIKE %s';
			$values[] = '%' . $wpdb->esc_like( '"' . $tag . '"' ) . '%';
		}

		$where_sql = implode( ' AND ', $where );

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM `{$table}` WHERE {$where_sql}", $values ) );

		$values[] = $per_page;
		$values[] = $offset;

		// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared, WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$rows = $wpdb->get_results( $wpdb->prepare( "SELECT * FROM `{$table}` WHERE {$where_sql} ORDER BY last_message_at DESC LIMIT %d OFFSET %d", $values ), ARRAY_A );

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
	 * Fetch contacts from the CPT store.
	 *
	 * @param int    $page       Page number.
	 * @param int    $per_page   Items per page.
	 * @param string $channel    Optional channel filter.
	 * @param string $crm_status Optional CRM status filter.
	 * @param string $search     Optional search term.
	 * @param string $tag        Optional tag filter (matched in JSON tags meta).
	 * @return WP_REST_Response
	 */
	protected function get_contacts_from_cpt( $page, $per_page, $channel, $crm_status, $search, $tag ) {
		$meta_query = array( 'relation' => 'AND' );

		if ( ! empty( $channel ) ) {
			$meta_query[] = array(
				'key'     => '_channel',
				'value'   => $channel,
				'compare' => '=',
			);
		}

		if ( ! empty( $crm_status ) ) {
			$meta_query[] = array(
				'key'     => '_crm_status',
				'value'   => $crm_status,
				'compare' => '=',
			);
		}

		if ( ! empty( $tag ) ) {
			// Match JSON-encoded tag string inside the _tags meta value.
			$meta_query[] = array(
				'key'     => '_tags',
				'value'   => '"' . $tag . '"',
				'compare' => 'LIKE',
			);
		}

		$args = array(
			'post_type'      => WP_MCP_AI_Channel_Contacts_CPT::POST_TYPE,
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $page,
			'orderby'        => 'meta_value_num',
			'meta_key'       => '_last_message_at', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
			'order'          => 'DESC',
			'no_found_rows'  => false,
		);

		if ( count( $meta_query ) > 1 ) {
			$args['meta_query'] = $meta_query; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		if ( ! empty( $search ) ) {
			$args['s'] = $search;
		}

		$query = new WP_Query( $args );
		$total = (int) $query->found_posts;
		$items = array();

		foreach ( $query->posts as $post ) {
			$items[] = $this->format_contact( WP_MCP_AI_Channel_Contacts_CPT::post_to_row( $post ) );
		}

		wp_reset_postdata();

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
	 * Add a tag to a contact.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function add_contact_tag( $request ) {
		$id  = absint( $request->get_param( 'id' ) );
		$tag = sanitize_text_field( $request->get_param( 'tag' ) );

		// CCT path (preferred when class exists and table is available).
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			WP_MCP_AI_Channel_Contacts_CCT::add_tag( $id, $tag );
			return rest_ensure_response( array( 'success' => true ) );
		}

		// CPT fallback path.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
			WP_MCP_AI_Channel_Contacts_CPT::add_tag( $id, $tag );
			return rest_ensure_response( array( 'success' => true ) );
		}

		return new WP_Error( 'rest_unavailable', __( 'Contacts store not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
	}

	/**
	 * Enable or disable human takeover for a contact.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function set_human_takeover( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$enable = (bool) $request->get_param( 'enable' );

		// CCT path (preferred when class exists and table is available).
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			WP_MCP_AI_Channel_Contacts_CCT::set_human_takeover( $id, $enable );
			return rest_ensure_response(
				array(
					'success'        => true,
					'human_takeover' => $enable,
				)
			);
		}

		// CPT fallback path.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
			WP_MCP_AI_Channel_Contacts_CPT::set_human_takeover( $id, $enable );
			return rest_ensure_response(
				array(
					'success'        => true,
					'human_takeover' => $enable,
				)
			);
		}

		return new WP_Error( 'rest_unavailable', __( 'Contacts store not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
	}

	/**
	 * Update the CRM status of a contact.
	 *
	 * @param WP_REST_Request $request Request object.
	 * @return WP_REST_Response|WP_Error
	 */
	public function update_contact_status( $request ) {
		$id     = absint( $request->get_param( 'id' ) );
		$status = sanitize_key( $request->get_param( 'status' ) );

		$allowed = array( 'new', 'active', 'resolved', 'blocked' );
		if ( ! in_array( $status, $allowed, true ) ) {
			return new WP_Error( 'rest_invalid_param', __( 'Invalid CRM status.', 'mcp-ai-wpoos-pro' ), array( 'status' => 400 ) );
		}

		// CCT path (preferred when class exists and table is available).
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CCT' ) && WP_MCP_AI_Channel_Contacts_CCT::table_exists() ) {
			global $wpdb;
			$table = WP_MCP_AI_Channel_Contacts_CCT::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$wpdb->update(
				$table,
				array( 'crm_status' => $status ),
				array( '_ID' => $id ),
				array( '%s' ),
				array( '%d' )
			);

			return rest_ensure_response(
				array(
					'success'    => true,
					'crm_status' => $status,
				)
			);
		}

		// CPT fallback path.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
			WP_MCP_AI_Channel_Contacts_CPT::set_crm_status( $id, $status );
			return rest_ensure_response(
				array(
					'success'    => true,
					'crm_status' => $status,
				)
			);
		}

		return new WP_Error( 'rest_unavailable', __( 'Contacts store not available.', 'mcp-ai-wpoos-pro' ), array( 'status' => 503 ) );
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

		// Resolve bot_username from the connection when available.
		$bot_username  = '';
		$connection_id = isset( $row['connection_id'] ) ? $row['connection_id'] : '';
		if ( '' !== $connection_id && 'telegram' === ( isset( $row['channel'] ) ? $row['channel'] : '' ) ) {
			$bot_username = $this->resolve_bot_username( $connection_id );
		}

		return array(
			'id'                 => isset( $row['_ID'] ) ? (int) $row['_ID'] : 0,
			'channel'            => isset( $row['channel'] ) ? $row['channel'] : '',
			'channel_contact_id' => isset( $row['channel_contact_id'] ) ? $row['channel_contact_id'] : '',
			'connection_id'      => $connection_id,
			'conversation_type'  => isset( $row['conversation_type'] ) ? $row['conversation_type'] : 'dm',
			'display_name'       => isset( $row['display_name'] ) ? $row['display_name'] : '',
			'bot_username'       => $bot_username,
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
	 * Resolve the bot_username for a Telegram connection.
	 *
	 * Uses a static cache so that the same connection is only looked up once
	 * per request even when formatting many contacts.
	 *
	 * @param string $connection_id Connection ID.
	 * @return string Bot username (without leading @) or empty string.
	 */
	protected function resolve_bot_username( $connection_id ) {
		static $cache = array();

		if ( isset( $cache[ $connection_id ] ) ) {
			return $cache[ $connection_id ];
		}

		$bot_username = '';

		// Ensure the Remote Site Manager class is loaded (mirrors the pattern
		// used in the Telegram webhook controller).
		if ( ! class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) && defined( 'WP_MCP_AI_PRO_PATH' ) ) {
			$rsm_path = WP_MCP_AI_PRO_PATH . 'includes/class-wp-mcp-ai-pro-remote-site-manager.php';
			if ( file_exists( $rsm_path ) ) {
				require_once $rsm_path;
			}
		}

		if ( class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $connection && ! empty( $connection['bot_username'] ) ) {
				$bot_username = ltrim( sanitize_text_field( $connection['bot_username'] ), '@' );
			}
		}

		$cache[ $connection_id ] = $bot_username;

		return $bot_username;
	}

	/**
	 * Format a raw message DB row for the REST response.
	 *
	 * @param array $row              Raw database row.
	 * @param bool  $include_metadata Whether to include decoded raw payload metadata.
	 * @return array
	 */
	protected function format_message( $row, $include_metadata = false ) {
		$message = array(
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
			'conversation_type'  => isset( $row['conversation_type'] ) ? $row['conversation_type'] : 'dm',
		);

		if ( $include_metadata ) {
			$raw_payload = array();
			if ( isset( $row['raw_payload'] ) && is_string( $row['raw_payload'] ) && '' !== $row['raw_payload'] ) {
				$decoded = json_decode( $row['raw_payload'], true );
				if ( is_array( $decoded ) ) {
					$raw_payload = $decoded;
				}
			}
			$message['raw_payload'] = $raw_payload;
		}

		return $message;
	}
}
