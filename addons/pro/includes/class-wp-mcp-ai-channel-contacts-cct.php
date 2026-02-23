<?php
/**
 * JetEngine Custom Content Type registration for channel contacts.
 *
 * Stores CRM-style contact records for every unique sender across all
 * supported chat channels. Supports tagging, agent assignment, and
 * human-takeover state so the inbox can surface the right conversations
 * to the right people.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Provision and interact with the channel_contacts CCT.
 */
class WP_MCP_AI_Channel_Contacts_CCT {

	/**
	 * CCT slug.
	 */
	const SLUG = 'channel_contacts';

	/**
	 * Base ID for meta field identifiers (42000 range).
	 */
	const FIELD_ID_BASE = 42000;

	/**
	 * CRM status options.
	 */
	const STATUS_NEW       = 'new';
	const STATUS_ACTIVE    = 'active';
	const STATUS_RESOLVED  = 'resolved';
	const STATUS_BLOCKED   = 'blocked';

	/**
	 * Hook into JetEngine to provision the content type.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );
	}

	/**
	 * Retrieve the CCT slug.
	 *
	 * @return string
	 */
	public static function get_slug() {
		return self::SLUG;
	}

	/**
	 * Return the raw database table name.
	 *
	 * @return string
	 */
	public static function get_table_name() {
		global $wpdb;
		return $wpdb->prefix . 'jet_cct_' . self::SLUG;
	}

	/**
	 * Check whether the CCT database table exists.
	 *
	 * @return bool
	 */
	public static function table_exists() {
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		return $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $table ) ) === $table;
	}

	/**
	 * Find or create a contact record for the given platform + contact ID.
	 *
	 * @param string $channel           Platform slug, e.g. 'whatsapp'.
	 * @param string $channel_contact_id  Platform-side contact identifier.
	 * @param array  $extra             Optional extra fields to merge on creation:
	 *                                  display_name, phone_number, email, metadata.
	 * @return int|false Contact CCT item ID, or false on failure.
	 */
	public static function find_or_create( $channel, $channel_contact_id, array $extra = array() ) {
		global $wpdb;

		$channel            = sanitize_key( $channel );
		$channel_contact_id = sanitize_text_field( $channel_contact_id );

		if ( empty( $channel ) || empty( $channel_contact_id ) ) {
			return false;
		}

		// Try to look up the existing contact directly in the CCT table.
		if ( self::table_exists() ) {
			$table = self::get_table_name();
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$existing_id = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT _ID FROM {$table} WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
					$channel,
					$channel_contact_id
				)
			);

			if ( $existing_id ) {
				return (int) $existing_id;
			}
		}

		// Create a new contact record.
		$display_name = isset( $extra['display_name'] ) ? sanitize_text_field( $extra['display_name'] ) : '';
		$phone_number = isset( $extra['phone_number'] ) ? sanitize_text_field( $extra['phone_number'] ) : '';
		$email        = isset( $extra['email'] ) ? sanitize_email( $extra['email'] ) : '';
		$metadata     = isset( $extra['metadata'] ) ? wp_json_encode( $extra['metadata'] ) : '';

		$data = array(
			'channel'            => $channel,
			'channel_contact_id' => $channel_contact_id,
			'display_name'       => $display_name ? $display_name : $channel_contact_id,
			'phone_number'       => $phone_number,
			'email'              => $email,
			'tags'               => '[]',
			'crm_status'         => self::STATUS_NEW,
			'notes'              => '',
			'assigned_agent'     => '',
			'human_takeover'     => 0,
			'last_message_at'    => time(),
			'metadata'           => $metadata,
			'cct_status'         => 'publish',
		);

		$handler = self::get_item_handler();
		if ( $handler && method_exists( $handler, 'create_item' ) ) {
			$result = $handler->create_item( $data );
			return is_numeric( $result ) ? (int) $result : false;
		}

		if ( self::table_exists() ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$wpdb->insert( self::get_table_name(), $data );
			return $wpdb->insert_id ? $wpdb->insert_id : false;
		}

		return false;
	}

	/**
	 * Update the last_message_at timestamp for a contact.
	 *
	 * @param int $contact_id CCT item ID.
	 */
	public static function touch( $contact_id ) {
		if ( ! self::table_exists() ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'last_message_at' => time() ),
			array( '_ID' => absint( $contact_id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Add a tag to a contact (idempotent).
	 *
	 * @param int    $contact_id CCT item ID.
	 * @param string $tag        Tag value to add.
	 */
	public static function add_tag( $contact_id, $tag ) {
		if ( ! self::table_exists() ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT tags FROM {$table} WHERE _ID = %d LIMIT 1", absint( $contact_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		if ( ! $row ) {
			return;
		}

		$tags = json_decode( $row->tags, true );
		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		$tag = sanitize_text_field( $tag );
		if ( ! in_array( $tag, $tags, true ) ) {
			$tags[] = $tag;
		}

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'tags' => wp_json_encode( $tags ) ),
			array( '_ID' => absint( $contact_id ) ),
			array( '%s' ),
			array( '%d' )
		);
	}

	/**
	 * Toggle human takeover state for a contact.
	 *
	 * When human_takeover is enabled the AI assistant will NOT auto-reply
	 * to messages from this contact.
	 *
	 * @param int  $contact_id     CCT item ID.
	 * @param bool $human_takeover True to enable, false to disable.
	 */
	public static function set_human_takeover( $contact_id, $human_takeover ) {
		if ( ! self::table_exists() ) {
			return;
		}

		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$wpdb->update(
			$table,
			array( 'human_takeover' => $human_takeover ? 1 : 0 ),
			array( '_ID' => absint( $contact_id ) ),
			array( '%d' ),
			array( '%d' )
		);
	}

	/**
	 * Check whether human takeover is active for a given channel + contact.
	 *
	 * @param string $channel            Platform slug.
	 * @param string $channel_contact_id Platform contact ID.
	 * @return bool
	 */
	public static function is_human_takeover_active( $channel, $channel_contact_id ) {
		if ( ! self::table_exists() ) {
			return false;
		}

		global $wpdb;
		$table = self::get_table_name();

		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$result = $wpdb->get_var(
			$wpdb->prepare(
				// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
				"SELECT human_takeover FROM {$table} WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
				sanitize_key( $channel ),
				sanitize_text_field( $channel_contact_id )
			)
		);

		return (bool) $result;
	}

	/**
	 * Retrieve the JetEngine item handler.
	 *
	 * @return object|null
	 */
	public static function get_item_handler() {
		$module = self::get_cct_module();
		if ( ! $module || empty( $module->manager ) ) {
			return null;
		}

		if ( ! self::cct_exists( $module ) ) {
			self::maybe_register_cct();
		}

		$instance = $module->manager->get_content_types( self::SLUG );
		if ( ! $instance ) {
			return null;
		}

		return $instance->get_item_handler();
	}

	/**
	 * Register the CCT in JetEngine if not already registered.
	 */
	public static function maybe_register_cct() {
		$module = self::get_cct_module();
		if ( ! $module ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		$args = array(
			'slug'           => self::SLUG,
			'name'           => __( 'Channel Contacts', 'mcp-ai-wpoos-pro' ),
			'singular_name'  => __( 'Channel Contact', 'mcp-ai-wpoos-pro' ),
			'status'         => 'publish',
			'show_edit_link' => false,
			'has_single'     => false,
			'fields'         => self::get_fields_schema(),
		);

		if ( method_exists( $module->manager, 'edit_item' ) ) {
			$module->manager->edit_item( false, $args );
		}
	}

	/**
	 * Get the JetEngine CCT module instance.
	 *
	 * @return object|null
	 */
	protected static function get_cct_module() {
		if ( ! function_exists( 'jet_engine' ) ) {
			return null;
		}

		$engine = jet_engine();

		if ( empty( $engine->modules ) || ! method_exists( $engine->modules, 'is_module_active' ) ) {
			return null;
		}

		if ( ! $engine->modules->is_module_active( 'custom-content-types' ) ) {
			return null;
		}

		$module_wrapper = $engine->modules->get_module( 'custom-content-types' );
		if ( empty( $module_wrapper ) || empty( $module_wrapper->instance ) ) {
			return null;
		}

		return $module_wrapper->instance;
	}

	/**
	 * Check whether the CCT slug exists.
	 *
	 * @param object $module CCT module.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) ) {
			return false;
		}

		return ! empty( $module->manager->get_content_types( self::SLUG ) );
	}

	/**
	 * Field schema for the channel_contacts CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$b = self::FIELD_ID_BASE;

		return array(
			array(
				'id'          => $b + 1,
				'title'       => __( 'Channel', 'mcp-ai-wpoos-pro' ),
				'name'        => 'channel',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'whatsapp',
				'options'     => array(
					array( 'key' => 'whatsapp', 'value' => 'WhatsApp' ),
					array( 'key' => 'telegram', 'value' => 'Telegram' ),
					array( 'key' => 'slack', 'value' => 'Slack' ),
					array( 'key' => 'discord', 'value' => 'Discord' ),
					array( 'key' => 'teams', 'value' => 'Microsoft Teams' ),
					array( 'key' => 'messenger', 'value' => 'Facebook Messenger' ),
					array( 'key' => 'google_chat', 'value' => 'Google Chat' ),
					array( 'key' => 'twitter', 'value' => 'Twitter/X' ),
					array( 'key' => 'webchat', 'value' => 'WebChat' ),
				),
				'description' => __( 'Chat platform this contact belongs to', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 2,
				'title'       => __( 'Channel Contact ID', 'mcp-ai-wpoos-pro' ),
				'name'        => 'channel_contact_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Platform-side unique identifier for the contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 3,
				'title'       => __( 'Display Name', 'mcp-ai-wpoos-pro' ),
				'name'        => 'display_name',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Human-readable name of the contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 4,
				'title'       => __( 'Phone Number', 'mcp-ai-wpoos-pro' ),
				'name'        => 'phone_number',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Contact phone number (WhatsApp, SMS)', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 5,
				'title'       => __( 'Email', 'mcp-ai-wpoos-pro' ),
				'name'        => 'email',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Contact email address', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 6,
				'title'       => __( 'Tags', 'mcp-ai-wpoos-pro' ),
				'name'        => 'tags',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '[]',
				'description' => __( 'JSON array of tag strings for CRM segmentation', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 7,
				'title'       => __( 'CRM Status', 'mcp-ai-wpoos-pro' ),
				'name'        => 'crm_status',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'new',
				'options'     => array(
					array( 'key' => 'new', 'value' => __( 'New', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'active', 'value' => __( 'Active', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'resolved', 'value' => __( 'Resolved', 'mcp-ai-wpoos-pro' ) ),
					array( 'key' => 'blocked', 'value' => __( 'Blocked', 'mcp-ai-wpoos-pro' ) ),
				),
				'description' => __( 'Current CRM lifecycle status', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 8,
				'title'       => __( 'Notes', 'mcp-ai-wpoos-pro' ),
				'name'        => 'notes',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Internal CRM notes about the contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 9,
				'title'       => __( 'Assigned Agent', 'mcp-ai-wpoos-pro' ),
				'name'        => 'assigned_agent',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Post ID of the AI assistant or WP user ID assigned to this contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 10,
				'title'       => __( 'Human Takeover', 'mcp-ai-wpoos-pro' ),
				'name'        => 'human_takeover',
				'type'        => 'checkbox',
				'is_array'    => false,
				'width'       => '100%',
				'default_val' => false,
				'description' => __( 'When enabled the AI assistant will not auto-reply to this contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 11,
				'title'       => __( 'Last Message At', 'mcp-ai-wpoos-pro' ),
				'name'        => 'last_message_at',
				'type'        => 'number',
				'is_num'      => true,
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Unix timestamp of the most recent message from/to this contact', 'mcp-ai-wpoos-pro' ),
			),
			array(
				'id'          => $b + 12,
				'title'       => __( 'Metadata', 'mcp-ai-wpoos-pro' ),
				'name'        => 'metadata',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'JSON metadata for platform-specific contact fields', 'mcp-ai-wpoos-pro' ),
			),
		);
	}
}
