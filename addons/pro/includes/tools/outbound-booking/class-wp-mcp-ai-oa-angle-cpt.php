<?php
/**
 * Outbound Appointment Booking — angle bank and objection matrix CPT.
 *
 * `mcp_ai_oa_angle` holds the copy library: outbound angles (per-channel
 * subject / first-line / body variants) and objection→response pairs. Entries
 * carry champion/challenger test status and cumulative send/reply/booking
 * counters that power the weekly A/B promotion job.
 *
 * Sequence steps reference an angle through their `template_id` (angle post
 * ID or slug).
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
 * Angle bank / objection matrix CPT.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Angle_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_oa_angle';

	/**
	 * Kinds.
	 *
	 * @var string[]
	 */
	const KINDS = array( 'angle', 'objection' );

	/**
	 * Test statuses.
	 *
	 * @var string[]
	 */
	const TEST_STATUSES = array( '', 'champion', 'challenger', 'paused' );

	/**
	 * Channels an angle can target.
	 *
	 * @var string[]
	 */
	const CHANNELS = array( 'any', 'email', 'linkedin_dm', 'instagram_dm' );

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
		add_action( 'add_meta_boxes', array( __CLASS__, 'register_meta_boxes' ) );
		add_action( 'save_post_' . self::POST_TYPE, array( __CLASS__, 'save_meta' ), 10, 2 );

		add_filter( 'manage_' . self::POST_TYPE . '_posts_columns', array( __CLASS__, 'add_admin_columns' ) );
		add_action( 'manage_' . self::POST_TYPE . '_posts_custom_column', array( __CLASS__, 'render_admin_columns' ), 10, 2 );
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
					'name'          => _x( 'Angles & Objections', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name' => _x( 'Angle', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Angle', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Angle', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'Angle Bank', 'mcp-ai-wpoos-pro' ),
					'search_items'  => __( 'Search Angles', 'mcp-ai-wpoos-pro' ),
					'not_found'     => __( 'No angles found.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'Outbound angle bank and objection matrix entries.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wp-mcp-ai-outbound',
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'editor', 'author' ),
				'show_in_rest'    => true,
			)
		);
	}

	/**
	 * Register meta boxes.
	 *
	 * @since 2.12.0
	 */
	public static function register_meta_boxes() {
		add_meta_box(
			'oa-angle-details',
			__( 'Angle Details', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the details meta box.
	 *
	 * @since 2.12.0
	 * @param WP_Post $post Post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_oa_angle_save', 'wp_mcp_ai_oa_angle_nonce' );
		$kind         = get_post_meta( $post->ID, '_oa_angle_kind', true );
		$channel      = get_post_meta( $post->ID, '_oa_angle_channel', true );
		$subject      = get_post_meta( $post->ID, '_oa_angle_subject', true );
		$first_line   = get_post_meta( $post->ID, '_oa_angle_first_line', true );
		$objection    = get_post_meta( $post->ID, '_oa_angle_objection', true );
		$response     = get_post_meta( $post->ID, '_oa_angle_response', true );
		$variant_of   = (int) get_post_meta( $post->ID, '_oa_angle_variant_of', true );
		$test_status  = get_post_meta( $post->ID, '_oa_angle_test_status', true );
		if ( ! $kind ) {
			$kind = 'angle';
		}
		if ( ! $channel ) {
			$channel = 'any';
		}
		?>
		<style>
			.oa-angle-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
			.oa-angle-full { grid-column: 1 / -1; }
			.oa-angle-field label { display: block; font-weight: 600; margin-bottom: 4px; }
			.oa-angle-field input[type="text"], .oa-angle-field select, .oa-angle-field textarea { width: 100%; }
			.oa-angle-field textarea { min-height: 80px; }
			.oa-angle-hint { color: #757575; font-size: 12px; margin-top: 2px; }
		</style>
		<div class="oa-angle-grid">
			<div class="oa-angle-field">
				<label for="oa-angle-kind"><?php esc_html_e( 'Kind', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="oa-angle-kind" name="oa_angle[kind]">
					<option value="angle" <?php selected( $kind, 'angle' ); ?>><?php esc_html_e( 'Angle (outbound copy)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="objection" <?php selected( $kind, 'objection' ); ?>><?php esc_html_e( 'Objection → Response', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</div>
			<div class="oa-angle-field">
				<label for="oa-angle-channel"><?php esc_html_e( 'Channel', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="oa-angle-channel" name="oa_angle[channel]">
					<?php foreach ( self::CHANNELS as $c ) : ?>
						<option value="<?php echo esc_attr( $c ); ?>" <?php selected( $channel, $c ); ?>><?php echo esc_html( ucfirst( str_replace( '_', ' ', $c ) ) ); ?></option>
					<?php endforeach; ?>
				</select>
			</div>
			<div class="oa-angle-field">
				<label for="oa-angle-subject"><?php esc_html_e( 'Email Subject', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="oa-angle-subject" name="oa_angle[subject]" value="<?php echo esc_attr( $subject ); ?>" />
			</div>
			<div class="oa-angle-field">
				<label for="oa-angle-first-line"><?php esc_html_e( 'First Line', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="oa-angle-first-line" name="oa_angle[first_line]" value="<?php echo esc_attr( $first_line ); ?>" />
				<p class="oa-angle-hint"><?php esc_html_e( 'Supports {{first_name}}, {{company}}, {{job_title}} and other tokens.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<div class="oa-angle-field oa-angle-full">
				<label for="oa-angle-objection"><?php esc_html_e( 'Objection (kind = objection)', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="oa-angle-objection" name="oa_angle[objection]" value="<?php echo esc_attr( $objection ); ?>" />
			</div>
			<div class="oa-angle-field oa-angle-full">
				<label for="oa-angle-response"><?php esc_html_e( 'Response (kind = objection)', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="oa-angle-response" name="oa_angle[response]"><?php echo esc_textarea( $response ); ?></textarea>
			</div>
			<div class="oa-angle-field">
				<label for="oa-angle-variant-of"><?php esc_html_e( 'Variant Of (angle ID)', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="oa-angle-variant-of" name="oa_angle[variant_of]" value="<?php echo esc_attr( $variant_of ? $variant_of : '' ); ?>" min="0" step="1" />
				<p class="oa-angle-hint"><?php esc_html_e( 'Group copy variants under one angle for weekly A/B tests.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<div class="oa-angle-field">
				<label for="oa-angle-test-status"><?php esc_html_e( 'Test Status', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="oa-angle-test-status" name="oa_angle[test_status]">
					<option value=""><?php esc_html_e( 'Not in test', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="champion" <?php selected( $test_status, 'champion' ); ?>><?php esc_html_e( 'Champion', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="challenger" <?php selected( $test_status, 'challenger' ); ?>><?php esc_html_e( 'Challenger', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="paused" <?php selected( $test_status, 'paused' ); ?>><?php esc_html_e( 'Paused', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</div>
		</div>
		<?php
	}

	/**
	 * Save angle meta.
	 *
	 * @since 2.12.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['wp_mcp_ai_oa_angle_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp_mcp_ai_oa_angle_nonce'] ), 'wp_mcp_ai_oa_angle_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['oa_angle'] ) || ! is_array( $_POST['oa_angle'] ) ) {
			return;
		}
		$data = wp_unslash( $_POST['oa_angle'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised per-field below.

		$kind = isset( $data['kind'] ) ? sanitize_key( $data['kind'] ) : 'angle';
		update_post_meta( $post_id, '_oa_angle_kind', in_array( $kind, self::KINDS, true ) ? $kind : 'angle' );

		$channel = isset( $data['channel'] ) ? sanitize_key( $data['channel'] ) : 'any';
		update_post_meta( $post_id, '_oa_angle_channel', in_array( $channel, self::CHANNELS, true ) ? $channel : 'any' );

		$test_status = isset( $data['test_status'] ) ? sanitize_key( $data['test_status'] ) : '';
		update_post_meta( $post_id, '_oa_angle_test_status', in_array( $test_status, self::TEST_STATUSES, true ) ? $test_status : '' );

		$fields = array(
			'subject'    => 'sanitize_text_field',
			'first_line' => 'sanitize_text_field',
			'objection'  => 'sanitize_text_field',
			'response'   => 'sanitize_textarea_field',
		);
		foreach ( $fields as $field => $sanitizer ) {
			$value = isset( $data[ $field ] ) ? call_user_func( $sanitizer, $data[ $field ] ) : '';
			update_post_meta( $post_id, '_oa_angle_' . $field, $value );
		}

		$variant_of = isset( $data['variant_of'] ) ? absint( $data['variant_of'] ) : 0;
		update_post_meta( $post_id, '_oa_angle_variant_of', $variant_of === $post_id ? 0 : $variant_of );
	}

	/**
	 * Add admin columns.
	 *
	 * @since 2.12.0
	 * @param array $columns Existing columns.
	 * @return array
	 */
	public static function add_admin_columns( $columns ) {
		unset( $columns['author'] );
		$columns['oa_kind']    = __( 'Kind', 'mcp-ai-wpoos-pro' );
		$columns['oa_channel'] = __( 'Channel', 'mcp-ai-wpoos-pro' );
		$columns['oa_test']    = __( 'Test', 'mcp-ai-wpoos-pro' );
		$columns['oa_stats']   = __( 'Sends / Replies / Booked', 'mcp-ai-wpoos-pro' );
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
			case 'oa_kind':
				$kind = get_post_meta( $post_id, '_oa_angle_kind', true );
				echo esc_html( 'objection' === $kind ? __( 'Objection', 'mcp-ai-wpoos-pro' ) : __( 'Angle', 'mcp-ai-wpoos-pro' ) );
				break;
			case 'oa_channel':
				$channel = get_post_meta( $post_id, '_oa_angle_channel', true );
				echo esc_html( $channel ? ucfirst( str_replace( '_', ' ', $channel ) ) : 'Any' );
				break;
			case 'oa_test':
				$status = get_post_meta( $post_id, '_oa_angle_test_status', true );
				if ( $status ) {
					echo '<span class="oa-test-status oa-test-' . esc_attr( $status ) . '">' . esc_html( ucfirst( $status ) ) . '</span>';
				} else {
					echo '—';
				}
				break;
			case 'oa_stats':
				$sends   = (int) get_post_meta( $post_id, '_oa_angle_sends', true );
				$replies = (int) get_post_meta( $post_id, '_oa_angle_replies', true );
				$booked  = (int) get_post_meta( $post_id, '_oa_angle_bookings', true );
				$rate    = $sends > 0 ? round( $replies / $sends * 100 ) . '%' : '—';
				/* translators: 1: sends, 2: replies, 3: booked, 4: reply rate */
				echo esc_html( sprintf( __( '%1$d / %2$d / %3$d (%4$s)', 'mcp-ai-wpoos-pro' ), $sends, $replies, $booked, $rate ) );
				break;
		}
	}

	/**
	 * Resolve a sequence step template reference (angle ID or slug) to an angle post ID.
	 *
	 * @since 2.12.0
	 * @param string|int $template_ref Angle post ID or slug.
	 * @return int Angle post ID or 0.
	 */
	public static function resolve( $template_ref ) {
		if ( ! $template_ref ) {
			return 0;
		}
		if ( is_numeric( $template_ref ) ) {
			$post = get_post( absint( $template_ref ) );
			return ( $post && self::POST_TYPE === $post->post_type ) ? (int) $post->ID : 0;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'name'           => sanitize_title( $template_ref ),
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return $posts ? (int) $posts[0] : 0;
	}

	/**
	 * Get the full variant group for an angle (root + children).
	 *
	 * @since 2.12.0
	 * @param int $angle_id Angle post ID.
	 * @return array Map of post ID => post object.
	 */
	public static function get_group( $angle_id ) {
		$angle = get_post( $angle_id );
		if ( ! $angle ) {
			return array();
		}
		$root = (int) get_post_meta( $angle_id, '_oa_angle_variant_of', true );
		$root = $root ? $root : $angle_id;

		$children = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => -1,
				'meta_key'       => '_oa_angle_variant_of', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded variant group lookup.
				'meta_value'     => $root, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded variant group lookup.
				'no_found_rows'  => true,
			)
		);
		$group = array();
		$root_post = get_post( $root );
		if ( $root_post && self::POST_TYPE === $root_post->post_type ) {
			$group[ $root ] = $root_post;
		}
		foreach ( $children as $child ) {
			$group[ $child->ID ] = $child;
		}
		return $group;
	}

	/**
	 * Pick the variant to send for an angle, honouring the champion allocation.
	 *
	 * @since 2.12.0
	 * @param int $angle_id Angle post ID.
	 * @return int Chosen angle post ID (0 if none).
	 */
	public static function pick_variant( $angle_id ) {
		$group = self::get_group( $angle_id );
		if ( empty( $group ) ) {
			return 0;
		}
		if ( count( $group ) === 1 ) {
			$only = array_keys( $group );
			return (int) $only[0];
		}

		$settings  = WP_MCP_AI_OA_Settings::get();
		$allocation = (int) $settings['champion_allocation'];

		$champion = 0;
		$others   = array();
		foreach ( $group as $id => $post ) {
			if ( 'champion' === get_post_meta( $id, '_oa_angle_test_status', true ) ) {
				$champion = (int) $id;
			} elseif ( '' === get_post_meta( $id, '_oa_angle_test_status', true ) || 'challenger' === get_post_meta( $id, '_oa_angle_test_status', true ) ) {
				$others[] = (int) $id;
			}
		}
		$roll = wp_rand( 1, 100 );
		if ( $champion && $roll <= $allocation ) {
			return $champion;
		}
		if ( ! empty( $others ) ) {
			return $others[ array_rand( $others ) ];
		}
		if ( $champion ) {
			return $champion;
		}
		$ids = array_keys( $group );
		return (int) $ids[ array_rand( $ids ) ];
	}

	/**
	 * Record a send/reply/booking event on an angle's counters.
	 *
	 * @since 2.12.0
	 * @param int    $angle_id Angle post ID.
	 * @param string $event    send|reply|booking.
	 * @return void
	 */
	public static function record_event( $angle_id, $event ) {
		if ( ! $angle_id ) {
			return;
		}
		$plural = array(
			'send'    => 'sends',
			'reply'   => 'replies',
			'booking' => 'bookings',
		);
		$key = '_oa_angle_' . ( isset( $plural[ $event ] ) ? $plural[ $event ] : $event . 's' );
		$current = (int) get_post_meta( $angle_id, $key, true );
		update_post_meta( $angle_id, $key, $current + 1 );
	}

	/**
	 * Get leaderboard rows for the tests dashboard.
	 *
	 * @since 2.12.0
	 * @return array Rows sorted by sends descending.
	 */
	public static function get_leaderboard() {
		$angles = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'no_found_rows'  => true,
			)
		);
		$rows = array();
		foreach ( $angles as $angle ) {
			$sends   = (int) get_post_meta( $angle->ID, '_oa_angle_sends', true );
			$replies = (int) get_post_meta( $angle->ID, '_oa_angle_replies', true );
			$booked  = (int) get_post_meta( $angle->ID, '_oa_angle_bookings', true );
			$rows[]  = array(
				'id'      => (int) $angle->ID,
				'title'   => $angle->post_title,
				'status'  => (string) get_post_meta( $angle->ID, '_oa_angle_test_status', true ),
				'variant_of' => (int) get_post_meta( $angle->ID, '_oa_angle_variant_of', true ),
				'sends'   => $sends,
				'replies' => $replies,
				'booked'  => $booked,
				'reply_rate' => $sends > 0 ? round( $replies / $sends * 100, 1 ) : 0,
			);
		}
		usort(
			$rows,
			function ( $a, $b ) {
				return $b['sends'] <=> $a['sends'];
			}
		);
		return $rows;
	}
}
