<?php
/**
 * class-wp-mcp-ai-channel-contacts-cct.php (ecosystem port — Wave F5, chat-channels data layer).
 *
 * Ported from the base Pro addon's `addons/pro/includes/class-wp-mcp-ai-channel-contacts-cct.php` for the
 * standalone `nvoos-content-graph-pro` addon. Kept byte-identical. The base Pro addon owns the
 * class in monolith installs — the addon boots nothing when `WP_MCP_AI_PRO_PATH` is defined
 * (see the plugin entry).
 *
 * Documented deviations: `declare(strict_types=1)` added; text domain `nvoos-content-graph-pro`;
 * `NVOOS_CONTENT_GRAPH_PRO_*` constant swaps.
 *
 * @package NvoosContentGraphPro
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

declare(strict_types=1);

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
	const STATUS_NEW      = 'new';
	const STATUS_ACTIVE   = 'active';
	const STATUS_RESOLVED = 'resolved';
	const STATUS_BLOCKED  = 'blocked';

	/**
	 * Hook into JetEngine to provision the content type.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'maybe_register_cct' ), 100 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_conversation_type' ), 101 );
		add_action( 'init', array( __CLASS__, 'maybe_migrate_connection_id' ), 102 );
	}

	/**
	 * Ensure the conversation_type column exists in the contacts CCT table.
	 */
	public static function maybe_migrate_conversation_type() {
		if ( get_option( 'wp_mcp_ai_channel_contacts_migration_v1' ) ) {
			return;
		}
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! in_array( 'conversation_type', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `conversation_type` VARCHAR(20) NOT NULL DEFAULT 'dm'" );
		}
		update_option( 'wp_mcp_ai_channel_contacts_migration_v1', true );
	}

	/**
	 * Ensure the connection_id column exists in the contacts CCT table.
	 *
	 * Older installations that created the CCT before this field was added to
	 * the schema will not have the column, causing queries that reference it
	 * to fail silently and break the inbox.
	 */
	public static function maybe_migrate_connection_id() {
		if ( get_option( 'wp_mcp_ai_channel_contacts_migration_v2' ) ) {
			return;
		}
		if ( ! self::table_exists() ) {
			return;
		}
		global $wpdb;
		$table = self::get_table_name();
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$existing_cols = $wpdb->get_col( "DESCRIBE `{$table}`", 0 );
		if ( ! in_array( 'connection_id', $existing_cols, true ) ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.SchemaChange, WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$wpdb->query( "ALTER TABLE `{$table}` ADD COLUMN `connection_id` VARCHAR(100) NOT NULL DEFAULT ''" );
		}
		update_option( 'wp_mcp_ai_channel_contacts_migration_v2', true );
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
	 * When `connection_id` is supplied in `$extra`, the lookup uses all three
	 * of `channel`, `channel_contact_id`, and `connection_id` so that the same
	 * platform contact on two different connections appears as two distinct
	 * records. This keeps per-connection conversation threads isolated in the
	 * inbox and ensures that human-takeover state, CRM status, and message
	 * history are never shared across connections.
	 *
	 * @param string $channel           Platform slug, e.g. 'whatsapp'.
	 * @param string $channel_contact_id  Platform-side contact identifier.
	 * @param array  $extra             Optional extra fields to merge on creation:
	 *                                  display_name, phone_number, email, metadata,
	 *                                  connection_id.
	 * @return int|false Contact CCT item ID, or false on failure.
	 */
	public static function find_or_create( $channel, $channel_contact_id, array $extra = array() ) {
		global $wpdb;

		$channel            = sanitize_key( $channel );
		$channel_contact_id = sanitize_text_field( $channel_contact_id );

		if ( empty( $channel ) || empty( $channel_contact_id ) ) {
			return false;
		}

		$connection_id     = isset( $extra['connection_id'] ) ? sanitize_text_field( $extra['connection_id'] ) : '';
		$conversation_type = isset( $extra['conversation_type'] ) ? sanitize_key( $extra['conversation_type'] ) : 'dm';

		// Try to look up the existing contact directly in the CCT table.
		if ( self::table_exists() ) {
			$table = self::get_table_name();

			if ( '' !== $connection_id ) {
				// Connection-scoped lookup: same contact on two connections = two records.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$existing_id = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT _ID FROM `{$table}` WHERE channel = %s AND channel_contact_id = %s AND connection_id = %s LIMIT 1",
						$channel,
						$channel_contact_id,
						$connection_id
					)
				);

				if ( $existing_id ) {
					return (int) $existing_id;
				}

				// Adopt an older record that has no connection_id yet so the
				// contact is not duplicated and gains the connection_id needed
				// for bot_username resolution in the inbox.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$legacy_id = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT _ID FROM `{$table}` WHERE channel = %s AND channel_contact_id = %s AND connection_id = '' LIMIT 1",
						$channel,
						$channel_contact_id
					)
				);

				if ( $legacy_id ) {
					// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
					$wpdb->update(
						$table,
						array(
							'connection_id'     => $connection_id,
							'conversation_type' => $conversation_type,
						),
						array( '_ID' => (int) $legacy_id ),
						array( '%s', '%s' ),
						array( '%d' )
					);
					return (int) $legacy_id;
				}
			} else {
				// Backward-compatible lookup for callers that do not supply connection_id.
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$existing_id = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT _ID FROM `{$table}` WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
						$channel,
						$channel_contact_id
					)
				);

				if ( $existing_id ) {
					return (int) $existing_id;
				}
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
			'connection_id'      => $connection_id,
			'conversation_type'  => $conversation_type,
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

		// Fall back to the CPT store when JetEngine is not available.
		if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
			return WP_MCP_AI_Channel_Contacts_CPT::find_or_create( $channel, $channel_contact_id, $extra );
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
		$row = $wpdb->get_row( $wpdb->prepare( "SELECT tags FROM `{$table}` WHERE _ID = %d LIMIT 1", absint( $contact_id ) ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
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
	 * When `$connection_id` is non-empty the lookup is scoped to the specific
	 * connection, matching the per-connection contact records created by
	 * {@see find_or_create()}. Falls back to a channel+contact-only lookup when
	 * `$connection_id` is omitted or empty for backward compatibility.
	 *
	 * @param string $channel            Platform slug.
	 * @param string $channel_contact_id Platform contact ID.
	 * @param string $connection_id      Optional connection identifier.
	 * @return bool
	 */
	public static function is_human_takeover_active( $channel, $channel_contact_id, $connection_id = '' ) {
		if ( ! self::table_exists() ) {
			// Fall back to the CPT store when the CCT table is unavailable.
			if ( class_exists( 'WP_MCP_AI_Channel_Contacts_CPT' ) ) {
				return WP_MCP_AI_Channel_Contacts_CPT::is_human_takeover_active( $channel, $channel_contact_id, $connection_id );
			}
			return false;
		}

		global $wpdb;
		$table         = self::get_table_name();
		$channel       = sanitize_key( $channel );
		$contact_id    = sanitize_text_field( $channel_contact_id );
		$connection_id = sanitize_text_field( $connection_id );

		if ( '' !== $connection_id ) {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT human_takeover FROM `{$table}` WHERE channel = %s AND channel_contact_id = %s AND connection_id = %s LIMIT 1",
					$channel,
					$contact_id,
					$connection_id
				)
			);

			// Fall back to an unscoped lookup when no connection-specific record
			// exists yet (e.g. contacts created before this feature was deployed).
			if ( null === $result ) {
				// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
				$result = $wpdb->get_var(
					$wpdb->prepare(
						// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
						"SELECT human_takeover FROM `{$table}` WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
						$channel,
						$contact_id
					)
				);
			}
		} else {
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
			$result = $wpdb->get_var(
				$wpdb->prepare(
					// phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
					"SELECT human_takeover FROM `{$table}` WHERE channel = %s AND channel_contact_id = %s LIMIT 1",
					$channel,
					$contact_id
				)
			);
		}

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
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_chat_channels_toolkit'] ) ) {
			return;
		}

		$module = self::get_cct_module();
		if ( ! $module ) {
			return;
		}

		if ( self::cct_exists( $module ) ) {
			return;
		}

		if ( empty( $module->manager ) || empty( $module->manager->data ) ) {
			return;
		}

		$module->manager->data->set_request( self::get_registration_request() );
		$module->manager->data->create_item( false );
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
	 * Check whether the CCT slug exists (database check).
	 *
	 * @param object $module CCT module.
	 * @return bool
	 */
	protected static function cct_exists( $module ) {
		if ( empty( $module->manager ) || empty( $module->manager->data ) || empty( $module->manager->data->db ) ) {
			return false;
		}

		$records = $module->manager->data->db->query(
			'post_types',
			array(
				'slug'   => self::SLUG,
				'status' => 'content-type',
			),
			null,
			false
		);

		return ! empty( $records );
	}

	/**
	 * Build the JetEngine registration request payload.
	 *
	 * @return array
	 */
	protected static function get_registration_request() {
		$label = __( 'Channel Contacts', 'nvoos-content-graph-pro' );

		return array(
			'name'        => $label,
			'slug'        => self::SLUG,
			'args'        => self::get_cct_args( $label ),
			'meta_fields' => self::get_fields_schema(),
		);
	}

	/**
	 * Assemble JetEngine CCT arguments for channel contacts.
	 *
	 * @param string $label Human-readable label.
	 * @return array
	 */
	protected static function get_cct_args( $label ) {
		return array(
			'name'                => $label,
			'slug'                => self::SLUG,
			'position'            => '-1',
			'icon'                => 'dashicons-businessman',
			'capability'          => 'manage_options',
			'has_single'          => false,
			'create_index'        => true,
			'hide_field_names'    => false,
			'rest_get_enabled'    => true,
			'rest_put_enabled'    => false,
			'rest_post_enabled'   => false,
			'rest_delete_enabled' => false,
			'rest_get_access'     => 'manage_options',
			'rest_put_access'     => 'edit_posts',
			'rest_post_access'    => 'edit_posts',
			'rest_delete_access'  => 'edit_posts',
			'admin_columns'       => array(
				'_ID'                => array(
					'enabled'     => true,
					'prefix'      => '#',
					'is_sortable' => true,
					'is_num'      => true,
				),
				'channel'            => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'channel_contact_id' => array( 'enabled' => true ),
				'display_name'       => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'crm_status'         => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
				'last_message_at'    => array(
					'enabled'     => true,
					'is_sortable' => true,
					'is_num'      => true,
				),
				'cct_created'        => array(
					'enabled'     => true,
					'is_sortable' => true,
				),
			),
		);
	}

	/**
	 * Field schema for the channel_contacts CCT.
	 *
	 * @return array
	 */
	protected static function get_fields_schema() {
		$b = self::FIELD_ID_BASE;

		$fields = array(
			array(
				'id'          => $b + 1,
				'title'       => __( 'Channel', 'nvoos-content-graph-pro' ),
				'name'        => 'channel',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'whatsapp',
				'options'     => array(
					array(
						'key'   => 'whatsapp',
						'value' => 'WhatsApp',
					),
					array(
						'key'   => 'telegram',
						'value' => 'Telegram',
					),
					array(
						'key'   => 'slack',
						'value' => 'Slack',
					),
					array(
						'key'   => 'discord',
						'value' => 'Discord',
					),
					array(
						'key'   => 'teams',
						'value' => 'Microsoft Teams',
					),
					array(
						'key'   => 'messenger',
						'value' => 'Facebook Messenger',
					),
					array(
						'key'   => 'google_chat',
						'value' => 'Google Chat',
					),
					array(
						'key'   => 'twitter',
						'value' => 'Twitter/X',
					),
					array(
						'key'   => 'webchat',
						'value' => 'WebChat',
					),
				),
				'description' => __( 'Chat platform this contact belongs to', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 2,
				'title'       => __( 'Channel Contact ID', 'nvoos-content-graph-pro' ),
				'name'        => 'channel_contact_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Platform-side unique identifier for the contact', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 3,
				'title'       => __( 'Display Name', 'nvoos-content-graph-pro' ),
				'name'        => 'display_name',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Human-readable name of the contact', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 4,
				'title'       => __( 'Phone Number', 'nvoos-content-graph-pro' ),
				'name'        => 'phone_number',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Contact phone number (WhatsApp, SMS)', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 5,
				'title'       => __( 'Email', 'nvoos-content-graph-pro' ),
				'name'        => 'email',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Contact email address', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 6,
				'title'       => __( 'Tags', 'nvoos-content-graph-pro' ),
				'name'        => 'tags',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '[]',
				'description' => __( 'JSON array of tag strings for CRM segmentation', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 7,
				'title'       => __( 'CRM Status', 'nvoos-content-graph-pro' ),
				'name'        => 'crm_status',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'new',
				'options'     => array(
					array(
						'key'   => 'new',
						'value' => __( 'New', 'nvoos-content-graph-pro' ),
					),
					array(
						'key'   => 'active',
						'value' => __( 'Active', 'nvoos-content-graph-pro' ),
					),
					array(
						'key'   => 'resolved',
						'value' => __( 'Resolved', 'nvoos-content-graph-pro' ),
					),
					array(
						'key'   => 'blocked',
						'value' => __( 'Blocked', 'nvoos-content-graph-pro' ),
					),
				),
				'description' => __( 'Current CRM lifecycle status', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 8,
				'title'       => __( 'Notes', 'nvoos-content-graph-pro' ),
				'name'        => 'notes',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Internal CRM notes about the contact', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 9,
				'title'       => __( 'Assigned Agent', 'nvoos-content-graph-pro' ),
				'name'        => 'assigned_agent',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Post ID of the AI assistant or WP user ID assigned to this contact', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 10,
				'title'       => __( 'Human Takeover', 'nvoos-content-graph-pro' ),
				'name'        => 'human_takeover',
				'type'        => 'checkbox',
				'is_array'    => false,
				'width'       => '100%',
				'default_val' => false,
				'description' => __( 'When enabled the AI assistant will not auto-reply to this contact', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 11,
				'title'       => __( 'Last Message At', 'nvoos-content-graph-pro' ),
				'name'        => 'last_message_at',
				'type'        => 'number',
				'is_num'      => true,
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Unix timestamp of the most recent message from/to this contact', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 12,
				'title'       => __( 'Metadata', 'nvoos-content-graph-pro' ),
				'name'        => 'metadata',
				'type'        => 'textarea',
				'search'      => false,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'JSON metadata for platform-specific contact fields', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 13,
				'title'       => __( 'Connection ID', 'nvoos-content-graph-pro' ),
				'name'        => 'connection_id',
				'type'        => 'text',
				'search'      => true,
				'width'       => '100%',
				'default_val' => '',
				'description' => __( 'Plugin connection/account identifier this contact belongs to', 'nvoos-content-graph-pro' ),
			),
			array(
				'id'          => $b + 14,
				'title'       => __( 'Conversation Type', 'nvoos-content-graph-pro' ),
				'name'        => 'conversation_type',
				'type'        => 'select',
				'search'      => true,
				'width'       => '100%',
				'default_val' => 'dm',
				'options'     => array(
					array(
						'key'   => 'dm',
						'value' => __( 'Direct Message', 'nvoos-content-graph-pro' ),
					),
					array(
						'key'   => 'channel',
						'value' => __( 'Channel', 'nvoos-content-graph-pro' ),
					),
					array(
						'key'   => 'group',
						'value' => __( 'Group', 'nvoos-content-graph-pro' ),
					),
				),
				'description' => __( 'Whether this is a direct message, a channel (Slack/Teams/Discord), or a group chat', 'nvoos-content-graph-pro' ),
			),
		);

		foreach ( $fields as &$field ) {
			$field['object_type'] = 'field';
			$field['isNested']    = false;
		}
		unset( $field );

		return $fields;
	}
}
