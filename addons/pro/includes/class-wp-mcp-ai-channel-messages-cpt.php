<?php
/**
 * WordPress Custom Post Type fallback storage for channel messages.
 *
 * Used as a drop-in fallback when JetEngine is not installed or when the CCT
 * database tables do not exist. Provides the same public insert/query API as
 * WP_MCP_AI_Channel_Messages_CCT so that webhook handlers and the REST
 * controller can work without JetEngine.
 *
 * Post type: mcp_chan_message
 * Meta fields (prefixed with underscore to match WP convention):
 *   _channel, _channel_contact_id, _connection_id, _direction, _message_id,
 *   _message_type, _raw_payload, _status, _phone_number_id,
 *   _message_timestamp, _reply_sent, _assigned_agent
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
 * CPT-based storage for channel messages (JetEngine-free fallback).
 */
class WP_MCP_AI_Channel_Messages_CPT {

	/**
	 * Custom post type slug.
	 */
	const POST_TYPE = 'mcp_chan_message';

	/**
	 * Register the post type on init.
	 */
	public static function bootstrap() {
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );
	}

	/**
	 * Register the mcp_chan_message custom post type.
	 */
	public static function register_post_type() {
		if ( post_type_exists( self::POST_TYPE ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => __( 'Channel Messages', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'Channel Message', 'mcp-ai-wpoos-pro' ),
				),
				'public'          => false,
				'show_ui'         => false,
				'show_in_menu'    => false,
				'capability_type' => 'post',
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'custom-fields' ),
				'has_archive'     => false,
				'rewrite'         => false,
				'query_var'       => false,
				'show_in_rest'    => false,
			)
		);
	}

	/**
	 * Insert a message post.
	 *
	 * Accepts the same $data array shape as WP_MCP_AI_Channel_Messages_CCT::insert().
	 *
	 * @param array $data { // phpcs:ignore Squiz.Commenting.FunctionComment.ParamCommentFullStop -- Inline array specification.
	 *   @type string $channel            Platform slug, e.g. 'whatsapp'.
	 *   @type string $channel_contact_id Platform-side contact ID.
	 *   @type string $contact_name       Display name of the contact.
	 *   @type string $direction          'inbound' or 'outbound'.
	 *   @type string $message_id         Platform message ID.
	 *   @type string $message_type       'text', 'image', etc.
	 *   @type string $content            Human-readable message text.
	 *   @type mixed  $raw_payload        Raw platform payload (array or string).
	 *   @type string $status             'received', 'sent', 'delivered', etc.
	 *   @type string $connection_id      Settings connection identifier.
	 *   @type string $phone_number_id    Platform phone/channel ID.
	 *   @type int    $timestamp          Unix timestamp.
	 *   @type int    $reply_sent         1 when an AI reply was dispatched.
	 *   @type string $assigned_agent     Post ID of the AI assistant.
	 * }
	 * @return int|false Post ID on success, false on failure.
	 */
	public static function insert( array $data ) {
		$contact_name = isset( $data['contact_name'] ) ? sanitize_text_field( $data['contact_name'] ) : '';
		$content      = isset( $data['content'] ) ? sanitize_textarea_field( $data['content'] ) : '';

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => $contact_name,
				'post_content' => $content,
				'post_status'  => 'publish',
			),
			true
		);

		if ( is_wp_error( $post_id ) || ! $post_id ) {
			return false;
		}

		$raw_payload = isset( $data['raw_payload'] ) ? $data['raw_payload'] : '';
		if ( is_array( $raw_payload ) ) {
			$raw_payload = wp_json_encode( $raw_payload );
		}

		update_post_meta( $post_id, '_channel', isset( $data['channel'] ) ? sanitize_key( $data['channel'] ) : '' );
		update_post_meta( $post_id, '_channel_contact_id', isset( $data['channel_contact_id'] ) ? sanitize_text_field( $data['channel_contact_id'] ) : '' );
		update_post_meta( $post_id, '_connection_id', isset( $data['connection_id'] ) ? sanitize_text_field( $data['connection_id'] ) : '' );
		update_post_meta( $post_id, '_direction', isset( $data['direction'] ) && 'outbound' === $data['direction'] ? 'outbound' : 'inbound' );
		update_post_meta( $post_id, '_message_id', isset( $data['message_id'] ) ? sanitize_text_field( $data['message_id'] ) : '' );
		update_post_meta( $post_id, '_message_type', isset( $data['message_type'] ) ? sanitize_text_field( $data['message_type'] ) : 'text' );
		update_post_meta( $post_id, '_raw_payload', $raw_payload );
		update_post_meta( $post_id, '_status', isset( $data['status'] ) ? sanitize_text_field( $data['status'] ) : 'received' );
		update_post_meta( $post_id, '_phone_number_id', isset( $data['phone_number_id'] ) ? sanitize_text_field( $data['phone_number_id'] ) : '' );
		update_post_meta( $post_id, '_message_timestamp', isset( $data['timestamp'] ) ? absint( $data['timestamp'] ) : time() );
		update_post_meta( $post_id, '_reply_sent', ! empty( $data['reply_sent'] ) ? 1 : 0 );
		update_post_meta( $post_id, '_assigned_agent', isset( $data['assigned_agent'] ) ? sanitize_text_field( $data['assigned_agent'] ) : '' );

		return $post_id;
	}

	/**
	 * Retrieve recent messages for a contact as OpenAI-style chat pairs.
	 *
	 * @param string $channel       Platform slug.
	 * @param string $contact_id    Platform-side contact/user ID.
	 * @param string $connection_id Plugin connection identifier.
	 * @param int    $limit         Maximum number of messages to return. Default 10.
	 * @return array[] Array of ['role' => 'user'|'assistant', 'content' => string].
	 */
	public static function get_recent_messages( $channel, $contact_id, $connection_id, $limit = 10 ) {
		$channel       = sanitize_key( $channel );
		$contact_id    = sanitize_text_field( $contact_id );
		$connection_id = sanitize_text_field( $connection_id );
		$limit         = max( 1, (int) $limit );

		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'meta_value_num',
				'meta_key'       => '_message_timestamp', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'order'          => 'DESC',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					'relation' => 'AND',
					array(
						'key'     => '_channel',
						'value'   => $channel,
						'compare' => '=',
					),
					array(
						'key'     => '_channel_contact_id',
						'value'   => $contact_id,
						'compare' => '=',
					),
					array(
						'key'     => '_connection_id',
						'value'   => $connection_id,
						'compare' => '=',
					),
					array(
						'key'     => '_message_type',
						'value'   => 'text',
						'compare' => '=',
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( empty( $posts ) ) {
			return array();
		}

		// Reverse to restore chronological order (oldest → newest).
		$posts    = array_reverse( $posts );
		$messages = array();

		foreach ( $posts as $post ) {
			$direction = (string) get_post_meta( $post->ID, '_direction', true );
			$content   = trim( $post->post_content );

			if ( '' === $content ) {
				continue;
			}

			$messages[] = array(
				'role'    => 'outbound' === $direction ? 'assistant' : 'user',
				'content' => $content,
			);
		}

		return $messages;
	}

	/**
	 * Convert a WP_Post + meta to the normalised message array format used
	 * by the REST controller's format_message() helper.
	 *
	 * @param WP_Post $post             The message post.
	 * @param bool    $include_metadata Whether to include raw_payload.
	 * @return array
	 */
	public static function post_to_row( $post, $include_metadata = false ) {
		$id  = (int) $post->ID;
		$row = array(
			'_ID'                => $id,
			'channel'            => (string) get_post_meta( $id, '_channel', true ),
			'channel_contact_id' => (string) get_post_meta( $id, '_channel_contact_id', true ),
			'contact_name'       => $post->post_title,
			'direction'          => (string) get_post_meta( $id, '_direction', true ),
			'message_id'         => (string) get_post_meta( $id, '_message_id', true ),
			'message_type'       => (string) get_post_meta( $id, '_message_type', true ),
			'content'            => $post->post_content,
			'status'             => (string) get_post_meta( $id, '_status', true ),
			'connection_id'      => (string) get_post_meta( $id, '_connection_id', true ),
			'phone_number_id'    => (string) get_post_meta( $id, '_phone_number_id', true ),
			'message_timestamp'  => (int) get_post_meta( $id, '_message_timestamp', true ),
			'reply_sent'         => (bool) get_post_meta( $id, '_reply_sent', true ),
			'assigned_agent'     => (string) get_post_meta( $id, '_assigned_agent', true ),
		);

		if ( $include_metadata ) {
			$row['raw_payload'] = (string) get_post_meta( $id, '_raw_payload', true );
		}

		return $row;
	}
}
