<?php
/**
 * Outbound Appointment Booking — outbox / approval board CPT.
 *
 * `mcp_ai_oa_outbox` is the delivery board: every generated outbound message
 * lands here first. Email can auto-send when configured; LinkedIn and
 * Instagram DMs stay pending until a human approves and marks them sent (or
 * the webhook mode pushes them to Make/n8n-style automation).
 *
 * @package WP_MCP_AI_Pro
 * @subpackage Outbound_Booking_Toolkit
 * @since 2.12.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Outbox / approval board CPT.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Outbox {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_oa_outbox';

	/**
	 * Message statuses.
	 *
	 * @var string[]
	 */
	const STATUSES = array( 'pending', 'approved', 'sent', 'rejected', 'failed' );

	/**
	 * Message kinds.
	 *
	 * @var string[]
	 */
	const KINDS = array( 'sequence', 'booking_invite', 'objection_reply', 'manual' );

	/**
	 * Initialize.
	 *
	 * @since 2.12.0
	 */
	public static function init() {
		if ( ! WP_MCP_AI_OA_Settings::is_enabled() ) {
			return;
		}
		add_action( 'init', array( __CLASS__, 'register_post_type' ) );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'add_row_actions' ), 10, 2 );
		add_filter( 'bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'add_bulk_actions' ) );
		add_filter( 'handle_bulk_actions-edit-' . self::POST_TYPE, array( __CLASS__, 'handle_bulk_actions' ), 10, 3 );
		add_action( 'admin_notices', array( __CLASS__, 'render_admin_notices' ) );
		add_action( 'admin_post_wp_mcp_ai_oa_outbox_action', array( __CLASS__, 'handle_admin_action' ) );
	}

	/**
	 * Register the post type.
	 *
	 * @since 2.12.0
	 */
	public static function register_post_type() {
		register_post_type(
			self::POST_TYPE,
			array(
				'labels'          => array(
					'name'          => _x( 'Outbox', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name' => _x( 'Message', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'Approval Board', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'View Message', 'mcp-ai-wpoos-pro' ),
					'not_found'     => __( 'No messages yet.', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Messages', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'Outbound message approval board and delivery log.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wp-mcp-ai-outbound',
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'author' ),
				'show_in_rest'    => true,
				'map_meta_cap'    => true,
			)
		);
	}

	/**
	 * Create an outbox message.
	 *
	 * @since 2.12.0
	 * @param array $args Message args: lead_id, sequence_id, step, channel,
	 *                    subject, body, status, kind, angle_id, booking_link_id.
	 * @return int|WP_Error Outbox post ID or error.
	 */
	public static function create( $args ) {
		$lead_id         = absint( $args['lead_id'] ?? 0 );
		$sequence_id     = absint( $args['sequence_id'] ?? 0 );
		$step            = absint( $args['step'] ?? 0 );
		$channel         = sanitize_key( $args['channel'] ?? 'email' );
		$subject         = sanitize_text_field( $args['subject'] ?? '' );
		$body            = wp_kses_post( $args['body'] ?? '' );
		$status          = in_array( $args['status'] ?? 'pending', self::STATUSES, true ) ? ( $args['status'] ?? 'pending' ) : 'pending';
		$kind            = in_array( $args['kind'] ?? 'sequence', self::KINDS, true ) ? ( $args['kind'] ?? 'sequence' ) : 'sequence';
		$angle_id        = absint( $args['angle_id'] ?? 0 );
		$booking_link_id = absint( $args['booking_link_id'] ?? 0 );

		// Dedup: never create two identical pending/approved messages for the same lead+sequence+step.
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
				'meta_query'     => array(
					'relation' => 'AND',
					array( 'key' => '_oa_ob_lead_id', 'value' => $lead_id, 'compare' => '=' ),
					array( 'key' => '_oa_ob_sequence_id', 'value' => $sequence_id, 'compare' => '=' ),
					array( 'key' => '_oa_ob_step', 'value' => $step, 'compare' => '=' ),
					array( 'key' => '_oa_ob_kind', 'value' => $kind, 'compare' => '=' ),
					array(
						'key'     => '_oa_ob_status',
						'value'   => array( 'pending', 'approved' ),
						'compare' => 'IN',
					),
				),
			)
		);
		if ( ! empty( $existing ) ) {
			return (int) $existing[0];
		}

		$lead = get_post( $lead_id );
		$title = $lead ? sprintf(
			/* translators: 1: lead name, 2: channel, 3: sequence step */
			__( '%1$s · %2$s · step %3$d', 'mcp-ai-wpoos-pro' ),
			$lead->post_title,
			ucfirst( str_replace( '_', ' ', $channel ) ),
			$step + 1
		) : sprintf(
			/* translators: 1: channel, 2: sequence step */
			__( '%1$s · step %2$d', 'mcp-ai-wpoos-pro' ),
			ucfirst( str_replace( '_', ' ', $channel ) ),
			$step + 1
		);

		$post_id = wp_insert_post(
			array(
				'post_type'    => self::POST_TYPE,
				'post_title'   => $title,
				'post_content' => $body,
				'post_status'  => 'publish',
			),
			true
		);
		if ( is_wp_error( $post_id ) ) {
			return $post_id;
		}

		update_post_meta( $post_id, '_oa_ob_lead_id', $lead_id );
		update_post_meta( $post_id, '_oa_ob_sequence_id', $sequence_id );
		update_post_meta( $post_id, '_oa_ob_step', $step );
		update_post_meta( $post_id, '_oa_ob_channel', $channel );
		update_post_meta( $post_id, '_oa_ob_subject', $subject );
		update_post_meta( $post_id, '_oa_ob_body', $body );
		update_post_meta( $post_id, '_oa_ob_status', $status );
		update_post_meta( $post_id, '_oa_ob_kind', $kind );
		update_post_meta( $post_id, '_oa_ob_angle_id', $angle_id );
		update_post_meta( $post_id, '_oa_ob_booking_link_id', $booking_link_id );
		update_post_meta( $post_id, '_oa_ob_created_at', current_time( 'mysql', true ) );

		return $post_id;
	}

	/**
	 * Set a message's status.
	 *
	 * @since 2.12.0
	 * @param int    $outbox_id Outbox post ID.
	 * @param string $status    New status.
	 * @param string $error     Optional failure reason.
	 * @return void
	 */
	public static function set_status( $outbox_id, $status, $error = '' ) {
		if ( ! in_array( $status, self::STATUSES, true ) ) {
			return;
		}
		update_post_meta( $outbox_id, '_oa_ob_status', $status );
		if ( 'sent' === $status ) {
			update_post_meta( $outbox_id, '_oa_ob_sent_at', current_time( 'mysql', true ) );
			delete_post_meta( $outbox_id, '_oa_ob_error' );
		} elseif ( 'failed' === $status && $error ) {
			update_post_meta( $outbox_id, '_oa_ob_error', sanitize_text_field( $error ) );
		}
	}

	/**
	 * Add admin columns.
	 *
	 * @since 2.12.0
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_admin_columns( $columns ) {
		unset( $columns['author'], $columns['date'] );
		$columns['oa_lead']    = __( 'Lead', 'mcp-ai-wpoos-pro' );
		$columns['oa_channel'] = __( 'Channel', 'mcp-ai-wpoos-pro' );
		$columns['oa_kind']    = __( 'Kind', 'mcp-ai-wpoos-pro' );
		$columns['oa_status']  = __( 'Status', 'mcp-ai-wpoos-pro' );
		$columns['oa_created'] = __( 'Created', 'mcp-ai-wpoos-pro' );
		return $columns;
	}

	/**
	 * Render admin columns.
	 *
	 * @since 2.12.0
	 * @param string $column  Column name.
	 * @param int    $post_id Post ID.
	 */
	public static function render_admin_columns( $column, $post_id ) {
		switch ( $column ) {
			case 'oa_lead':
				$lead_id = (int) get_post_meta( $post_id, '_oa_ob_lead_id', true );
				if ( $lead_id ) {
					echo '<a href="' . esc_url( get_edit_post_link( $lead_id ) ) . '">' . esc_html( get_the_title( $lead_id ) ) . '</a>';
				} else {
					echo '—';
				}
				break;
			case 'oa_channel':
				$channel = get_post_meta( $post_id, '_oa_ob_channel', true );
				echo esc_html( $channel ? ucfirst( str_replace( '_', ' ', $channel ) ) : '—' );
				break;
			case 'oa_kind':
				$kind = get_post_meta( $post_id, '_oa_ob_kind', true );
				echo esc_html( $kind ? ucfirst( str_replace( '_', ' ', $kind ) ) : 'sequence' );
				break;
			case 'oa_status':
				$status = get_post_meta( $post_id, '_oa_ob_status', true );
				if ( ! $status ) {
					$status = 'pending';
				}
				$error = get_post_meta( $post_id, '_oa_ob_error', true );
				echo '<span class="oa-status oa-status-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
				if ( $error ) {
					echo '<br><small>' . esc_html( $error ) . '</small>';
				}
				break;
			case 'oa_created':
				$created = get_post_meta( $post_id, '_oa_ob_created_at', true );
				echo esc_html( $created ? date_i18n( 'M j, g:i a', strtotime( $created ) ) : '—' );
				break;
		}
	}

	/**
	 * Add row actions for pending/approved messages.
	 *
	 * @since 2.12.0
	 * @param array   $actions Row actions.
	 * @param WP_Post $post    Post object.
	 * @return array
	 */
	public static function add_row_actions( $actions, $post ) {
		if ( self::POST_TYPE !== $post->post_type || ! current_user_can( 'edit_post', $post->ID ) ) {
			return $actions;
		}
		$status = get_post_meta( $post->ID, '_oa_ob_status', true );
		$base   = admin_url( 'admin-post.php' );
		$url    = function ( $action ) use ( $base, $post ) {
			return wp_nonce_url(
				add_query_arg(
					array(
						'action'    => 'wp_mcp_ai_oa_outbox_action',
						'oa_action' => $action,
						'id'        => $post->ID,
					),
					$base
				),
				'oa_outbox_' . $action . '_' . $post->ID
			);
		};
		if ( 'pending' === $status ) {
			$actions['oa_approve'] = '<a href="' . esc_url( $url( 'approve' ) ) . '">' . esc_html__( 'Approve', 'mcp-ai-wpoos-pro' ) . '</a>';
			$actions['oa_reject']  = '<a href="' . esc_url( $url( 'reject' ) ) . '">' . esc_html__( 'Reject', 'mcp-ai-wpoos-pro' ) . '</a>';
		}
		if ( in_array( $status, array( 'pending', 'approved' ), true ) ) {
			$actions['oa_send'] = '<a href="' . esc_url( $url( 'send' ) ) . '">' . esc_html__( 'Mark Sent', 'mcp-ai-wpoos-pro' ) . '</a>';
		}
		return $actions;
	}

	/**
	 * Add bulk actions.
	 *
	 * @since 2.12.0
	 * @param array $actions Bulk actions.
	 * @return array
	 */
	public static function add_bulk_actions( $actions ) {
		$actions['oa_approve'] = __( 'Approve', 'mcp-ai-wpoos-pro' );
		$actions['oa_reject']  = __( 'Reject', 'mcp-ai-wpoos-pro' );
		$actions['oa_send']    = __( 'Mark Sent', 'mcp-ai-wpoos-pro' );
		return $actions;
	}

	/**
	 * Handle bulk actions.
	 *
	 * @since 2.12.0
	 * @param string $redirect Redirect URL.
	 * @param string $action   Bulk action.
	 * @param array  $post_ids Post IDs.
	 * @return string
	 */
	public static function handle_bulk_actions( $redirect, $action, $post_ids ) {
		if ( ! in_array( $action, array( 'oa_approve', 'oa_reject', 'oa_send' ), true ) ) {
			return $redirect;
		}
		foreach ( $post_ids as $post_id ) {
			self::transition( absint( $post_id ), str_replace( 'oa_', '', $action ) );
		}
		return $redirect;
	}

	/**
	 * Handle the single-row admin action.
	 *
	 * @since 2.12.0
	 */
	public static function handle_admin_action() {
		if ( ! isset( $_GET['oa_action'], $_GET['id'] ) ) { // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
			return;
		}
		$outbox_id = absint( $_GET['id'] ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		$action    = sanitize_key( wp_unslash( $_GET['oa_action'] ) ); // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Nonce verified below.
		if ( ! in_array( $action, array( 'approve', 'reject', 'send' ), true ) ) {
			return;
		}
		check_admin_referer( 'oa_outbox_' . $action . '_' . $outbox_id );
		if ( ! current_user_can( 'edit_post', $outbox_id ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mcp-ai-wpoos-pro' ) );
		}
		self::transition( $outbox_id, $action );
		wp_safe_redirect( admin_url( 'edit.php?post_type=' . self::POST_TYPE ) );
		exit;
	}

	/**
	 * Apply an approve/reject/send transition to a message.
	 *
	 * @since 2.12.0
	 * @param int    $outbox_id Outbox post ID.
	 * @param string $action    approve|reject|send.
	 * @return void
	 */
	private static function transition( $outbox_id, $action ) {
		switch ( $action ) {
			case 'approve':
				self::set_status( $outbox_id, 'approved' );
				// Auto-channels dispatch immediately on approval.
				WP_MCP_AI_OA_Channels::dispatch( $outbox_id );
				break;
			case 'reject':
				self::set_status( $outbox_id, 'rejected' );
				break;
			case 'send':
				WP_MCP_AI_OA_Channels::mark_manually_sent( $outbox_id );
				break;
		}
	}

	/**
	 * Render admin notices for bulk actions (informational).
	 *
	 * @since 2.12.0
	 */
	public static function render_admin_notices() {
		$screen = get_current_screen();
		if ( ! $screen || 'edit-' . self::POST_TYPE !== $screen->id ) {
			return;
		}
		$counts = self::get_status_counts();
		if ( ! empty( $counts['pending'] ) ) {
			echo '<div class="notice notice-info"><p>';
			printf(
				/* translators: %d: number of pending messages */
				esc_html__( '%d message(s) are waiting for approval. Approve them here, or mark them sent once you have sent them on LinkedIn/Instagram.', 'mcp-ai-wpoos-pro' ),
				(int) $counts['pending']
			);
			echo '</p></div>';
		}
	}

	/**
	 * Count messages by status.
	 *
	 * @since 2.12.0
	 * @return array Status => count.
	 */
	public static function get_status_counts() {
		$counts = array_fill_keys( self::STATUSES, 0 );
		$all    = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		foreach ( $all as $post_id ) {
			$status = get_post_meta( $post_id, '_oa_ob_status', true );
			if ( ! $status ) {
				$status = 'pending';
			}
			if ( isset( $counts[ $status ] ) ) {
				$counts[ $status ]++;
			}
		}
		return $counts;
	}
}
