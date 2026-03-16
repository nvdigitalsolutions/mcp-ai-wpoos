<?php
/**
 * WordPress Custom Post Type fallback storage for channel contacts.
 *
 * Used as a drop-in fallback when JetEngine is not installed or when the CCT
 * database tables do not exist. Provides the same public API as
 * WP_MCP_AI_Channel_Contacts_CCT so that the REST controller and webhook
 * handlers can work without JetEngine.
 *
 * Post type: mcp_chan_contact
 * Meta fields (prefixed with underscore to match WP convention):
 *   _channel, _channel_contact_id, _connection_id, _phone_number, _email,
 *   _tags, _crm_status, _notes, _assigned_agent, _human_takeover,
 *   _last_message_at, _metadata
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CPT-based storage for channel contacts (JetEngine-free fallback).
 */
class WP_MCP_AI_Channel_Contacts_CPT {

	/**
	 * Custom post type slug.
	 */
	const POST_TYPE = 'mcp_chan_contact';

	/**
	 * Register the post type on init.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the mcp_chan_contact custom post type.
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Channel Contacts', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Channel Contact', 'mcp-ai-wpoos-pro' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'capability_type' => 'post',
				'hierarchical'    => false,
				'supports'        => array( 'title', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
	}

	/**
	 * Find or create a contact record.
	 *
	 * When `connection_id` is supplied in `$extra`, the lookup uses all three
	 * of channel, channel_contact_id, and connection_id so that the same
	 * platform contact on two different connections appears as two distinct
	 * records. Falls back to a channel+contact-only lookup when connection_id
	 * is empty for backward compatibility.
	 *
	 * @param string $channel            Platform slug, e.g. 'whatsapp'.
	 * @param string $channel_contact_id Platform-side contact identifier.
	 * @param array  $extra              Optional extra fields: display_name,
	 *                                   phone_number, email, metadata,
	 *                                   connection_id.
	 * @return int|false Post ID on success, false on failure.
	 */
	public static function find_or_create( $channel, $channel_contact_id, array $extra = array() ) {
		$channel            = sanitize_key( $channel );
		$channel_contact_id = sanitize_text_field( $channel_contact_id );

		if ( empty( $channel ) || empty( $channel_contact_id ) ) {
			return false;
		}

		$connection_id = isset( $extra['connection_id'] ) ? sanitize_text_field( $extra['connection_id'] ) : '';

		// Build meta query for lookup.
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
				'key'     => '_connection_id',
				'value'   => $connection_id,
				'compare' => '=',
			);
		}

		// Look for an existing post.
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		// When no connection_id was given and the lookup above failed, skip
		// an additional unscoped lookup because get_posts already did it.

		// Create a new contact post.
		$display_name = isset( $extra['display_name'] ) && '' !== $extra['display_name']
			? sanitize_text_field( $extra['display_name'] )
			: $channel_contact_id;

		$post_id = wp_insert_post(
			array(
				'post_type'   => self::POST_TYPE,
				'post_title'  => $display_name,
				'post_status' => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return false;
		}

		update_post_meta( $post_id, '_channel', $channel );
		update_post_meta( $post_id, '_channel_contact_id', $channel_contact_id );
		update_post_meta( $post_id, '_connection_id', $connection_id );
		update_post_meta( $post_id, '_phone_number', isset( $extra['phone_number'] ) ? sanitize_text_field( $extra['phone_number'] ) : '' );
		update_post_meta( $post_id, '_email', isset( $extra['email'] ) ? sanitize_email( $extra['email'] ) : '' );
		update_post_meta( $post_id, '_tags', '[]' );
		update_post_meta( $post_id, '_crm_status', 'new' );
		update_post_meta( $post_id, '_notes', '' );
		update_post_meta( $post_id, '_assigned_agent', '' );
		update_post_meta( $post_id, '_human_takeover', 0 );
		update_post_meta( $post_id, '_last_message_at', time() );
		update_post_meta( $post_id, '_metadata', isset( $extra['metadata'] ) ? wp_json_encode( $extra['metadata'] ) : '' );

		return $post_id;
	}

	/**
	 * Update the last_message_at timestamp for a contact.
	 *
	 * @param int $contact_id Post ID.
	 */
	public static function touch( $contact_id ) {
		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return;
		}

		$post = get_post( $contact_id );
		if ( ! $post || self::POST_TYPE !== $post->post_type ) {
			return;
		}

		update_post_meta( $contact_id, '_last_message_at', time() );
	}

	/**
	 * Add a tag to a contact (idempotent).
	 *
	 * @param int    $contact_id Post ID.
	 * @param string $tag        Tag value to add.
	 */
	public static function add_tag( $contact_id, $tag ) {
		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return;
		}

		$raw  = get_post_meta( $contact_id, '_tags', true );
		$tags = json_decode( ( '' !== $raw ) ? $raw : '[]', true );

		if ( ! is_array( $tags ) ) {
			$tags = array();
		}

		$tag = sanitize_text_field( $tag );

		if ( ! in_array( $tag, $tags, true ) ) {
			$tags[] = $tag;
		}

		update_post_meta( $contact_id, '_tags', wp_json_encode( $tags ) );
	}

	/**
	 * Toggle the human_takeover flag for a contact.
	 *
	 * @param int  $contact_id     Post ID.
	 * @param bool $human_takeover True to enable, false to disable.
	 */
	public static function set_human_takeover( $contact_id, $human_takeover ) {
		$contact_id = absint( $contact_id );
		if ( ! $contact_id ) {
			return;
		}

		update_post_meta( $contact_id, '_human_takeover', $human_takeover ? 1 : 0 );
	}

	/**
	 * Check whether human takeover is active for a given channel + contact.
	 *
	 * @param string $channel            Platform slug.
	 * @param string $channel_contact_id Platform contact ID.
	 * @param string $connection_id      Optional connection identifier.
	 * @return bool
	 */
	public static function is_human_takeover_active( $channel, $channel_contact_id, $connection_id = '' ) {
		$channel            = sanitize_key( $channel );
		$channel_contact_id = sanitize_text_field( $channel_contact_id );
		$connection_id      = sanitize_text_field( $connection_id );

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
				'key'     => '_connection_id',
				'value'   => $connection_id,
				'compare' => '=',
			);
		}

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_query'     => $meta_query, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return false;
		}

		return (bool) get_post_meta( (int) $posts[0], '_human_takeover', true );
	}

	/**
	 * Update the CRM status for a contact.
	 *
	 * @param int    $contact_id Post ID.
	 * @param string $crm_status One of 'new', 'active', 'resolved', 'blocked'.
	 */
	public static function set_crm_status( $contact_id, $crm_status ) {
		$contact_id = absint( $contact_id );
		$crm_status = sanitize_key( $crm_status );

		if ( ! $contact_id || ! in_array( $crm_status, array( 'new', 'active', 'resolved', 'blocked' ), true ) ) {
			return;
		}

		update_post_meta( $contact_id, '_crm_status', $crm_status );
	}

	/**
	 * Convert a WP_Post + meta to the normalised contact array format used
	 * by the REST controller's format_contact() helper.
	 *
	 * @param WP_Post $post The contact post.
	 * @return array
	 */
	public static function post_to_row( $post ) {
		$id = (int) $post->ID;

		return array(
			'_ID'                => $id,
			'channel'            => (string) get_post_meta( $id, '_channel', true ),
			'channel_contact_id' => (string) get_post_meta( $id, '_channel_contact_id', true ),
			'connection_id'      => (string) get_post_meta( $id, '_connection_id', true ),
			'display_name'       => $post->post_title,
			'phone_number'       => (string) get_post_meta( $id, '_phone_number', true ),
			'email'              => (string) get_post_meta( $id, '_email', true ),
			'tags'               => (string) get_post_meta( $id, '_tags', true ),
			'crm_status'         => (string) get_post_meta( $id, '_crm_status', true ),
			'notes'              => (string) get_post_meta( $id, '_notes', true ),
			'assigned_agent'     => (string) get_post_meta( $id, '_assigned_agent', true ),
			'human_takeover'     => (bool) get_post_meta( $id, '_human_takeover', true ),
			'last_message_at'    => (int) get_post_meta( $id, '_last_message_at', true ),
			'cct_status'         => 'publish',
		);
	}
}
