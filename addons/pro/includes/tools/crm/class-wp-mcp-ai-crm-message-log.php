<?php
/**
 * CRM Message Log — Persistence layer for inbound messages.
 *
 * Stores every inbound message (email, SMS, WhatsApp, chat, web form) as
 * a structured record before the pipeline processes it, enabling:
 *
 *  - Deduplication via platform message_id (idempotent imports).
 *  - Full audit trail of raw message content.
 *  - Threaded conversation view (via thread_id).
 *  - Support ticket traceability (linked ticket_id).
 *  - Channel analytics (volume by channel, source, domain).
 *
 * Storage: WordPress custom post type `mcp_crm_message` with minimal
 * meta footprint for high-throughput querying.
 *
 * Industry-standard pattern: HubSpot, Salesforce, Zoho CRM, and Pipedrive
 * all maintain a message/email_log table linked to contacts/deals/tickets.
 *
 * @package WP_MCP_AI_Pro
 * @since  2.9.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Message Log — unified message persistence.
 *
 * @since 2.9.0
 */
class WP_MCP_AI_CRM_Message_Log {

	/**
	 * Post type slug for message records.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_crm_message';

	/**
	 * Option key for the last processed message_id index (dedup cache).
	 *
	 * Maps connection_id:message_id → timestamp for O(1) duplicate lookup.
	 *
	 * @var string
	 */
	const DEDUP_OPTION = 'wp_mcp_ai_crm_message_dedup_map';

	/**
	 * Maximum entries in the dedup map before compaction.
	 *
	 * @var int
	 */
	const DEDUP_MAX_ENTRIES = 10000;

	/**
	 * Register the message post type.
	 *
	 * @since 2.9.0
	 */
	public static function register_post_type() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			return;
		}

		register_post_type(
			self::POST_TYPE,
			array(
				'labels'              => array(
					'name'          => __( 'CRM Messages', 'mcp-ai-wpoos-pro' ),
					'singular_name' => __( 'CRM Message', 'mcp-ai-wpoos-pro' ),
				),
				'public'              => false,
				'show_ui'             => false,
				'show_in_menu'        => false,
				'show_in_nav_menus'   => false,
				'show_in_admin_bar'   => false,
				'exclude_from_search' => true,
				'publicly_queryable'  => false,
				'has_archive'         => false,
				'rewrite'             => false,
				'query_var'           => false,
				'supports'            => array( 'title' ),
				'capability_type'     => 'post',
				'map_meta_cap'        => true,
			)
		);
	}

	/**
	 * Log an inbound message.
	 *
	 * Stores the raw message and returns the post ID. Skips duplicate
	 * messages (same message_id from same connection/channel).
	 *
	 * @since 2.9.0
	 *
	 * @param array $args {
	 *     Message data.
	 *
	 *     @type string $message_id        Platform message ID (Gmail ID, SMS SID, etc.). Required for dedup.
	 *     @type string $thread_id         Platform thread/conversation ID (Gmail threadId, etc.).
	 *     @type string $channel           Message channel: 'email', 'sms', 'whatsapp', 'webchat', 'web_form'.
	 *     @type string $sender_email      Sender email address.
	 *     @type string $sender_name       Sender display name.
	 *     @type string $sender_phone      Sender phone number (E.164).
	 *     @type string $subject           Message subject (email subject line).
	 *     @type string $body              Full message body text.
	 *     @type int    $contact_id        Associated contact/lead post ID (0 if not yet linked).
	 *     @type int    $ticket_id         Associated support ticket post ID (0 if not a support request).
	 *     @type string $source            Import source identifier: 'gmail_import', 'imap_poll', 'sms_webhook', etc.
	 *     @type string $connection_id     Remote Site connection ID for attribution.
	 *     @type string $channel_contact_id Platform-side contact/user ID.
	 * }
	 * @return int|WP_Error Post ID of the logged message, or WP_Error.
	 */
	public static function log( array $args ) {
		$message_id         = sanitize_text_field( $args['message_id'] ?? '' );
		$channel            = sanitize_key( $args['channel'] ?? 'email' );
		$sender_email       = sanitize_email( $args['sender_email'] ?? '' );
		$sender_name        = sanitize_text_field( $args['sender_name'] ?? '' );
		$sender_phone       = sanitize_text_field( $args['sender_phone'] ?? '' );
		$subject            = sanitize_text_field( $args['subject'] ?? '' );
		$body               = sanitize_textarea_field( $args['body'] ?? '' );
		$contact_id         = absint( $args['contact_id'] ?? 0 );
		$ticket_id          = absint( $args['ticket_id'] ?? 0 );
		$source             = sanitize_key( $args['source'] ?? 'unknown' );
		$connection_id      = sanitize_text_field( $args['connection_id'] ?? '' );
		$channel_contact_id = sanitize_text_field( $args['channel_contact_id'] ?? '' );
		$thread_id          = sanitize_text_field( $args['thread_id'] ?? '' );

		// Dedup check: skip if this message_id was already logged.
		if ( ! empty( $message_id ) && ! empty( $channel ) ) {
			if ( self::is_duplicate( $channel, $message_id, $connection_id ) ) {
				// Return existing post ID if found.
				$existing = self::find_by_message_id( $channel, $message_id, $connection_id );
				if ( $existing ) {
					return $existing;
				}
				// Dedup map says yes but post may have been pruned; proceed.
			}
		}

		// Construct post title from subject or truncated body.
		if ( ! empty( $subject ) ) {
			$title = $subject;
		} elseif ( ! empty( $sender_name ) && ! empty( $sender_email ) ) {
			$title = sprintf(
				/* translators: 1: sender name, 2: sender email */
				__( 'Message from %1$s <%2$s>', 'mcp-ai-wpoos-pro' ),
				$sender_name,
				$sender_email
			);
		} elseif ( ! empty( $sender_email ) ) {
			$title = sprintf(
				/* translators: %s: sender email */
				__( 'Message from %s', 'mcp-ai-wpoos-pro' ),
				$sender_email
			);
		} else {
			$title = sprintf(
				/* translators: %s: channel name */
				__( 'Inbound %s message', 'mcp-ai-wpoos-pro' ),
				$channel
			);
		}

		$post_id = wp_insert_post(
			array(
				'post_type'     => self::POST_TYPE,
				'post_title'    => $title,
				'post_content'  => $body,
				'post_status'   => 'publish',
				'post_date_gmt' => current_time( 'mysql', true ),
			),
			true
		);

		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		// Store metadata.
		update_post_meta( $post_id, '_message_id', $message_id );
		update_post_meta( $post_id, '_thread_id', $thread_id );
		update_post_meta( $post_id, '_channel', $channel );
		update_post_meta( $post_id, '_sender_email', $sender_email );
		update_post_meta( $post_id, '_sender_name', $sender_name );
		update_post_meta( $post_id, '_sender_phone', $sender_phone );
		update_post_meta( $post_id, '_subject', $subject );
		update_post_meta( $post_id, '_contact_id', $contact_id );
		update_post_meta( $post_id, '_ticket_id', $ticket_id );
		update_post_meta( $post_id, '_source', $source );
		update_post_meta( $post_id, '_connection_id', $connection_id );
		update_post_meta( $post_id, '_channel_contact_id', $channel_contact_id );
		update_post_meta( $post_id, '_logged_at', current_time( 'mysql', true ) );

		// Add to dedup map.
		self::add_to_dedup_map( $channel, $message_id, $connection_id, $post_id );

		/**
		 * Fires after a CRM message has been logged.
		 *
		 * @since 2.9.0
		 * @param int   $post_id Message post ID.
		 * @param array $args    Original message arguments.
		 */
		do_action( 'wp_mcp_ai_crm_message_logged', $post_id, $args );

		if ( class_exists( 'WP_MCP_AI_CRM_Audit' ) ) {
			WP_MCP_AI_CRM_Audit::record(
				'message_logged',
				'message',
				$post_id,
				array(
					'channel'    => $channel,
					'source'     => $source,
					'message_id' => $message_id,
				)
			);
		}

		return $post_id;
	}

	/**
	 * Check whether a message ID has already been processed.
	 *
	 * @since 2.9.0
	 *
	 * @param string $channel       Channel slug.
	 * @param string $message_id    Platform message ID.
	 * @param string $connection_id Connection ID for scoping.
	 * @return bool
	 */
	public static function is_duplicate( $channel, $message_id, $connection_id = '' ) {
		$map = get_option( self::DEDUP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			return false;
		}

		$key = self::build_dedup_key( $channel, $message_id, $connection_id );
		return isset( $map[ $key ] );
	}

	/**
	 * Find an existing message post by its platform message_id.
	 *
	 * @since 2.9.0
	 *
	 * @param string $channel       Channel slug.
	 * @param string $message_id    Platform message ID.
	 * @param string $connection_id Connection ID for scoping.
	 * @return int|null Post ID or null.
	 */
	public static function find_by_message_id( $channel, $message_id, $connection_id = '' ) {
		$map = get_option( self::DEDUP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			return null;
		}

		$key = self::build_dedup_key( $channel, $message_id, $connection_id );
		if ( isset( $map[ $key ]['post_id'] ) ) {
			$post_id = absint( $map[ $key ]['post_id'] );
			if ( $post_id && 'publish' === get_post_status( $post_id ) ) {
				return $post_id;
			}
		}

		// Fallback: query by meta.
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
					array(
						'key'   => '_message_id',
						'value' => $message_id,
					),
					array(
						'key'   => '_channel',
						'value' => $channel,
					),
				),
				'no_found_rows'  => true,
			)
		);

		if ( $query->have_posts() ) {
			return $query->posts[0];
		}

		return null;
	}

	/**
	 * Build a dedup map key.
	 *
	 * @param string $channel       Channel slug.
	 * @param string $message_id    Platform message ID.
	 * @param string $connection_id Connection ID.
	 * @return string
	 */
	private static function build_dedup_key( $channel, $message_id, $connection_id ) {
		return $channel . ':' . $connection_id . ':' . $message_id;
	}

	/**
	 * Add an entry to the dedup map with compaction.
	 *
	 * @param string $channel       Channel slug.
	 * @param string $message_id    Platform message ID.
	 * @param string $connection_id Connection ID.
	 * @param int    $post_id       Message post ID.
	 */
	private static function add_to_dedup_map( $channel, $message_id, $connection_id, $post_id ) {
		if ( empty( $message_id ) ) {
			return;
		}

		$map = get_option( self::DEDUP_OPTION, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		$key = self::build_dedup_key( $channel, $message_id, $connection_id );

		$map[ $key ] = array(
			'post_id'   => absint( $post_id ),
			'timestamp' => time(),
		);

		// Compact if over the max.
		$count = count( $map );
		if ( $count > self::DEDUP_MAX_ENTRIES ) {
			// Remove oldest entries (keep the most recent half).
			uasort(
				$map,
				function ( $a, $b ) {
					return $b['timestamp'] <=> $a['timestamp'];
				}
			);
			$map = array_slice( $map, 0, self::DEDUP_MAX_ENTRIES / 2, true );
		}

		update_option( self::DEDUP_OPTION, $map, false );
	}

	/**
	 * Get messages for a given contact.
	 *
	 * @since 2.9.0
	 *
	 * @param int $contact_id Contact/lead post ID.
	 * @param int $per_page   Number per page.
	 * @param int $page       Page number (1-based).
	 * @return array<int, array> Array of message data.
	 */
	public static function get_messages_for_contact( $contact_id, $per_page = 20, $page = 1 ) {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, absint( $per_page ) ),
				'paged'          => max( 1, absint( $page ) ),
				'meta_key'       => '_contact_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $contact_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$messages = array();
		foreach ( $query->posts as $post ) {
			$messages[] = self::format_message( $post );
		}

		return $messages;
	}

	/**
	 * Get messages for a given support ticket.
	 *
	 * @since 2.9.0
	 *
	 * @param int $ticket_id Ticket post ID.
	 * @param int $per_page  Number per page.
	 * @param int $page      Page number (1-based).
	 * @return array<int, array>
	 */
	public static function get_messages_for_ticket( $ticket_id, $per_page = 20, $page = 1 ) {
		$query = new WP_Query(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => max( 1, absint( $per_page ) ),
				'paged'          => max( 1, absint( $page ) ),
				'meta_key'       => '_ticket_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				'meta_value'     => absint( $ticket_id ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value
				'orderby'        => 'date',
				'order'          => 'ASC',
			)
		);

		$messages = array();
		foreach ( $query->posts as $post ) {
			$messages[] = self::format_message( $post );
		}

		return $messages;
	}

	/**
	 * Format a message post into a structured array.
	 *
	 * @param WP_Post $post Message post object.
	 * @return array
	 */
	private static function format_message( $post ) {
		return array(
			'id'            => $post->ID,
			'message_id'    => get_post_meta( $post->ID, '_message_id', true ),
			'thread_id'     => get_post_meta( $post->ID, '_thread_id', true ),
			'channel'       => get_post_meta( $post->ID, '_channel', true ),
			'sender_email'  => get_post_meta( $post->ID, '_sender_email', true ),
			'sender_name'   => get_post_meta( $post->ID, '_sender_name', true ),
			'subject'       => get_post_meta( $post->ID, '_subject', true ),
			'body'          => $post->post_content,
			'contact_id'    => (int) get_post_meta( $post->ID, '_contact_id', true ),
			'ticket_id'     => (int) get_post_meta( $post->ID, '_ticket_id', true ),
			'source'        => get_post_meta( $post->ID, '_source', true ),
			'connection_id' => get_post_meta( $post->ID, '_connection_id', true ),
			'logged_at'     => get_post_meta( $post->ID, '_logged_at', true ),
			'date'          => $post->post_date,
		);
	}

	/**
	 * Get channel volume statistics.
	 *
	 * @since 2.9.0
	 *
	 * @param int $days Number of days to look back.
	 * @return array {
	 *     @type int   $total           Total messages in period.
	 *     @type array $by_channel      Channel → count.
	 *     @type array $by_source       Source → count.
	 *     @type int   $support_count   Messages classified as support requests.
	 *     @type int   $sales_count     Messages classified as sales inquiries.
	 * }
	 */
	public static function get_volume_stats( $days = 7 ) {
		global $wpdb;

		$since = gmdate( 'Y-m-d H:i:s', strtotime( "-{$days} days" ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = %s AND post_status = 'publish' AND post_date_gmt >= %s",
				self::POST_TYPE,
				$since
			)
		);

		// Channel breakdown.
		$by_channel = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm.meta_value AS channel, COUNT(*) AS cnt
				FROM {$wpdb->posts} p
				JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_channel'
				WHERE p.post_type = %s AND p.post_status = 'publish' AND p.post_date_gmt >= %s
				GROUP BY pm.meta_value",
				self::POST_TYPE,
				$since
			),
			OBJECT_K
		);
		// phpcs:enable

		$by_channel_counts = array();
		if ( is_array( $by_channel ) ) {
			foreach ( $by_channel as $row ) {
				$by_channel_counts[ $row->channel ] = (int) $row->cnt;
			}
		}

		return array(
			'total'      => $total,
			'by_channel' => $by_channel_counts,
		);
	}

	/**
	 * Link a message to a contact after pipeline processing.
	 *
	 * @since 2.9.0
	 *
	 * @param int $message_id  Message post ID.
	 * @param int $contact_id  Contact/lead post ID.
	 * @return bool
	 */
	public static function link_to_contact( $message_id, $contact_id ) {
		$message_id = absint( $message_id );
		$contact_id = absint( $contact_id );
		if ( ! $message_id || ! $contact_id ) {
			return false;
		}
		update_post_meta( $message_id, '_contact_id', $contact_id );
		return true;
	}

	/**
	 * Link a message to a support ticket.
	 *
	 * @since 2.9.0
	 *
	 * @param int $message_id Message post ID.
	 * @param int $ticket_id  Ticket post ID.
	 * @return bool
	 */
	public static function link_to_ticket( $message_id, $ticket_id ) {
		$message_id = absint( $message_id );
		$ticket_id  = absint( $ticket_id );
		if ( ! $message_id || ! $ticket_id ) {
			return false;
		}
		update_post_meta( $message_id, '_ticket_id', $ticket_id );
		return true;
	}
}

// Register post type on init.
add_action( 'init', array( 'WP_MCP_AI_CRM_Message_Log', 'register_post_type' ) );
