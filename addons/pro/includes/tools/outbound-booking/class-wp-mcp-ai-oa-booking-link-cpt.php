<?php
/**
 * Outbound Appointment Booking — booking link CPT.
 *
 * `mcp_ai_oa_booking` holds the offer and calendar routing for booked
 * sales calls. Each link carries the offer copy (headline, value props, call
 * title) plus either an external calendar URL (Calendly/Cal.com) or an
 * internal booking form that creates `mcp_appointment` records.
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
 * Booking link CPT.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Booking_Link_CPT {

	/**
	 * Post type slug.
	 *
	 * @var string
	 */
	const POST_TYPE = 'mcp_ai_oa_booking';

	/**
	 * Slot modes.
	 *
	 * @var string[]
	 */
	const MODES = array( 'external', 'internal' );

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
					'name'          => _x( 'Booking Links', 'post type general name', 'mcp-ai-wpoos-pro' ),
					'singular_name' => _x( 'Booking Link', 'post type singular name', 'mcp-ai-wpoos-pro' ),
					'add_new_item'  => __( 'Add New Booking Link', 'mcp-ai-wpoos-pro' ),
					'edit_item'     => __( 'Edit Booking Link', 'mcp-ai-wpoos-pro' ),
					'all_items'     => __( 'Booking Links', 'mcp-ai-wpoos-pro' ),
					'not_found'     => __( 'No booking links found.', 'mcp-ai-wpoos-pro' ),
				),
				'description'     => __( 'Booking offers that route prospects into your calendar.', 'mcp-ai-wpoos-pro' ),
				'public'          => false,
				'show_ui'         => true,
				'show_in_menu'    => 'wp-mcp-ai-outbound',
				'capability_type' => 'post',
				'has_archive'     => false,
				'hierarchical'    => false,
				'supports'        => array( 'title', 'author' ),
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
			'oa-booking-link-details',
			__( 'Offer & Calendar', 'mcp-ai-wpoos-pro' ),
			array( __CLASS__, 'render_meta_box' ),
			self::POST_TYPE,
			'normal',
			'high'
		);
	}

	/**
	 * Render the offer meta box.
	 *
	 * @since 2.12.0
	 * @param WP_Post $post Post object.
	 */
	public static function render_meta_box( $post ) {
		wp_nonce_field( 'wp_mcp_ai_oa_booking_save', 'wp_mcp_ai_oa_booking_nonce' );
		$slug         = get_post_meta( $post->ID, '_oa_booking_slug', true );
		$headline     = get_post_meta( $post->ID, '_oa_booking_headline', true );
		$value_props  = get_post_meta( $post->ID, '_oa_booking_value_props', true );
		$calendar_url = get_post_meta( $post->ID, '_oa_booking_calendar_url', true );
		$mode         = get_post_meta( $post->ID, '_oa_booking_mode', true );
		$duration     = (int) get_post_meta( $post->ID, '_oa_booking_duration', true );
		$call_title   = get_post_meta( $post->ID, '_oa_booking_call_title', true );
		$confirmation = get_post_meta( $post->ID, '_oa_booking_confirmation', true );
		$invite_body  = get_post_meta( $post->ID, '_oa_booking_invite_body', true );
		$active       = get_post_meta( $post->ID, '_oa_booking_active', true );
		if ( ! $mode ) {
			$mode = 'external';
		}
		if ( ! $duration ) {
			$duration = 30;
		}
		if ( ! $call_title ) {
			$call_title = __( 'Strategy Call', 'mcp-ai-wpoos-pro' );
		}
		?>
		<style>
			.oa-booking-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 12px; }
			.oa-booking-full { grid-column: 1 / -1; }
			.oa-booking-field label { display: block; font-weight: 600; margin-bottom: 4px; }
			.oa-booking-field input[type="text"], .oa-booking-field input[type="url"], .oa-booking-field select, .oa-booking-field textarea { width: 100%; }
			.oa-booking-field textarea { min-height: 90px; }
			.oa-booking-hint { color: #757575; font-size: 12px; margin-top: 2px; }
		</style>
		<div class="oa-booking-grid">
			<div class="oa-booking-field">
				<label for="oa-booking-slug"><?php esc_html_e( 'Slug', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="oa-booking-slug" name="oa_booking_link[slug]" value="<?php echo esc_attr( $slug ); ?>" />
				<p class="oa-booking-hint"><?php esc_html_e( 'Shortcode: [nvoos_oa_booking link="your-slug"]', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<div class="oa-booking-field">
				<label for="oa-booking-mode"><?php esc_html_e( 'Booking Mode', 'mcp-ai-wpoos-pro' ); ?></label>
				<select id="oa-booking-mode" name="oa_booking_link[mode]">
					<option value="external" <?php selected( $mode, 'external' ); ?>><?php esc_html_e( 'External calendar (Calendly/Cal.com)', 'mcp-ai-wpoos-pro' ); ?></option>
					<option value="internal" <?php selected( $mode, 'internal' ); ?>><?php esc_html_e( 'Internal booking form', 'mcp-ai-wpoos-pro' ); ?></option>
				</select>
			</div>
			<div class="oa-booking-field oa-booking-full">
				<label for="oa-booking-headline"><?php esc_html_e( 'Offer Headline', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="oa-booking-headline" name="oa_booking_link[headline]" value="<?php echo esc_attr( $headline ); ?>" />
			</div>
			<div class="oa-booking-field oa-booking-full">
				<label for="oa-booking-value-props"><?php esc_html_e( 'Value Props (one per line)', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="oa-booking-value-props" name="oa_booking_link[value_props]"><?php echo esc_textarea( $value_props ); ?></textarea>
			</div>
			<div class="oa-booking-field">
				<label for="oa-booking-call-title"><?php esc_html_e( 'Call Title', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="text" id="oa-booking-call-title" name="oa_booking_link[call_title]" value="<?php echo esc_attr( $call_title ); ?>" />
			</div>
			<div class="oa-booking-field">
				<label for="oa-booking-duration"><?php esc_html_e( 'Duration (minutes)', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="number" id="oa-booking-duration" name="oa_booking_link[duration]" value="<?php echo esc_attr( $duration ); ?>" min="10" step="5" />
			</div>
			<div class="oa-booking-field oa-booking-full">
				<label for="oa-booking-calendar-url"><?php esc_html_e( 'Calendar URL (external mode)', 'mcp-ai-wpoos-pro' ); ?></label>
				<input type="url" id="oa-booking-calendar-url" name="oa_booking_link[calendar_url]" value="<?php echo esc_attr( $calendar_url ); ?>" />
			</div>
			<div class="oa-booking-field oa-booking-full">
				<label for="oa-booking-invite-body"><?php esc_html_e( 'Invite Body (sent on positive replies)', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="oa-booking-invite-body" name="oa_booking_link[invite_body]"><?php echo esc_textarea( $invite_body ); ?></textarea>
				<p class="oa-booking-hint"><?php esc_html_e( 'Supports {{first_name}}, {{booking_url}} and other tokens.', 'mcp-ai-wpoos-pro' ); ?></p>
			</div>
			<div class="oa-booking-field oa-booking-full">
				<label for="oa-booking-confirmation"><?php esc_html_e( 'Confirmation Message (shown after booking)', 'mcp-ai-wpoos-pro' ); ?></label>
				<textarea id="oa-booking-confirmation" name="oa_booking_link[confirmation]"><?php echo esc_textarea( $confirmation ); ?></textarea>
			</div>
			<div class="oa-booking-field">
				<label for="oa-booking-active">
					<input type="checkbox" id="oa-booking-active" name="oa_booking_link[active]" value="1" <?php checked( $active, '1' ); ?> />
					<?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Save booking link meta.
	 *
	 * @since 2.12.0
	 * @param int     $post_id Post ID.
	 * @param WP_Post $post    Post object.
	 */
	public static function save_meta( $post_id, $post ) {
		if ( defined( 'DOING_AUTOSAVE' ) && DOING_AUTOSAVE ) {
			return;
		}
		if ( ! isset( $_POST['wp_mcp_ai_oa_booking_nonce'] ) || ! wp_verify_nonce( sanitize_key( $_POST['wp_mcp_ai_oa_booking_nonce'] ), 'wp_mcp_ai_oa_booking_save' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( ! isset( $_POST['oa_booking_link'] ) || ! is_array( $_POST['oa_booking_link'] ) ) {
			return;
		}
		$data = wp_unslash( $_POST['oa_booking_link'] ); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised per-field below.

		$slug = isset( $data['slug'] ) ? sanitize_title( $data['slug'] ) : '';
		if ( ! $slug ) {
			$slug = sanitize_title( $post->post_title );
		}
		if ( self::slug_exists( $slug, $post_id ) ) {
			$slug .= '-' . $post_id;
		}
		update_post_meta( $post_id, '_oa_booking_slug', $slug );

		$mode = isset( $data['mode'] ) ? sanitize_key( $data['mode'] ) : 'external';
		update_post_meta( $post_id, '_oa_booking_mode', in_array( $mode, self::MODES, true ) ? $mode : 'external' );

		update_post_meta( $post_id, '_oa_booking_headline', isset( $data['headline'] ) ? sanitize_text_field( $data['headline'] ) : '' );
		update_post_meta( $post_id, '_oa_booking_value_props', isset( $data['value_props'] ) ? sanitize_textarea_field( $data['value_props'] ) : '' );
		update_post_meta( $post_id, '_oa_booking_calendar_url', isset( $data['calendar_url'] ) ? esc_url_raw( $data['calendar_url'] ) : '' );
		update_post_meta( $post_id, '_oa_booking_duration', isset( $data['duration'] ) ? max( 10, absint( $data['duration'] ) ) : 30 );
		update_post_meta( $post_id, '_oa_booking_call_title', isset( $data['call_title'] ) ? sanitize_text_field( $data['call_title'] ) : '' );
		update_post_meta( $post_id, '_oa_booking_confirmation', isset( $data['confirmation'] ) ? sanitize_textarea_field( $data['confirmation'] ) : '' );
		update_post_meta( $post_id, '_oa_booking_invite_body', isset( $data['invite_body'] ) ? sanitize_textarea_field( $data['invite_body'] ) : '' );
		update_post_meta( $post_id, '_oa_booking_active', empty( $data['active'] ) ? '0' : '1' );
	}

	/**
	 * Whether a slug is already taken by another booking link.
	 *
	 * @since 2.12.0
	 * @param string $slug      Slug to check.
	 * @param int    $exclude_id Post ID to exclude.
	 * @return bool
	 */
	private static function slug_exists( $slug, $exclude_id = 0 ) {
		if ( ! $slug ) {
			return false;
		}
		$existing = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'meta_key'       => '_oa_booking_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded unique-slug lookup.
				'meta_value'     => $slug, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded unique-slug lookup.
				'exclude'        => array( $exclude_id ),
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);
		return ! empty( $existing );
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
		$columns['oa_slug']    = __( 'Slug', 'mcp-ai-wpoos-pro' );
		$columns['oa_mode']    = __( 'Mode', 'mcp-ai-wpoos-pro' );
		$columns['oa_active']  = __( 'Active', 'mcp-ai-wpoos-pro' );
		$columns['oa_booked']  = __( 'Booked Calls', 'mcp-ai-wpoos-pro' );
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
			case 'oa_slug':
				$slug = get_post_meta( $post_id, '_oa_booking_slug', true );
				echo $slug ? '<code>' . esc_html( $slug ) . '</code>' : '—';
				break;
			case 'oa_mode':
				$mode = get_post_meta( $post_id, '_oa_booking_mode', true );
				echo esc_html( 'internal' === $mode ? __( 'Internal form', 'mcp-ai-wpoos-pro' ) : __( 'External calendar', 'mcp-ai-wpoos-pro' ) );
				break;
			case 'oa_active':
				echo '1' === get_post_meta( $post_id, '_oa_booking_active', true ) ? '✔' : '—';
				break;
			case 'oa_booked':
				echo esc_html( (int) get_post_meta( $post_id, '_oa_booking_bookings', true ) );
				break;
		}
	}

	/**
	 * Get a booking link by slug.
	 *
	 * @since 2.12.0
	 * @param string $slug Booking link slug.
	 * @return WP_Post|null
	 */
	public static function get_by_slug( $slug ) {
		if ( ! $slug ) {
			return null;
		}
		$posts = get_posts(
			array(
				'post_type'      => self::POST_TYPE,
				'post_status'    => 'publish',
				'posts_per_page' => 1,
				'meta_key'       => '_oa_booking_slug', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded unique-slug lookup.
				'meta_value'     => sanitize_title( $slug ), // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Bounded unique-slug lookup.
				'no_found_rows'  => true,
			)
		);
		return $posts ? $posts[0] : null;
	}

	/**
	 * Build the public booking URL for a lead.
	 *
	 * @since 2.12.0
	 * @param int $booking_link_id Booking link post ID.
	 * @param int $lead_id         Lead post ID (0 for anonymous).
	 * @return string Booking URL.
	 */
	public static function get_booking_url( $booking_link_id, $lead_id = 0 ) {
		$post = get_post( $booking_link_id );
		if ( ! $post ) {
			return '';
		}
		$slug  = get_post_meta( $booking_link_id, '_oa_booking_slug', true );
		$page  = (int) WP_MCP_AI_OA_Settings::get_setting( 'booking_page_id', 0 );
		$base  = $page ? get_permalink( $page ) : home_url( '/' );
		$args  = array( 'link' => $slug );
		if ( $lead_id ) {
			$args['lead'] = $lead_id;
			$args['t']    = self::get_lead_token( $lead_id, $booking_link_id );
		}
		return add_query_arg( $args, $base );
	}

	/**
	 * Build a short attribution token for a lead + link pair.
	 *
	 * @since 2.12.0
	 * @param int $lead_id         Lead post ID.
	 * @param int $booking_link_id Booking link post ID.
	 * @return string Token.
	 */
	public static function get_lead_token( $lead_id, $booking_link_id ) {
		return substr( wp_hash( 'oa-booking-' . $lead_id . '-' . $booking_link_id ), 0, 16 );
	}

	/**
	 * Verify a lead attribution token.
	 *
	 * @since 2.12.0
	 * @param int    $lead_id         Lead post ID.
	 * @param int    $booking_link_id Booking link post ID.
	 * @param string $token           Token from the URL.
	 * @return bool
	 */
	public static function verify_lead_token( $lead_id, $booking_link_id, $token ) {
		if ( ! $token ) {
			return false;
		}
		return hash_equals( self::get_lead_token( $lead_id, $booking_link_id ), $token );
	}

	/**
	 * Increment the booked-calls counter on a link.
	 *
	 * @since 2.12.0
	 * @param int $booking_link_id Booking link post ID.
	 * @return void
	 */
	public static function record_booking( $booking_link_id ) {
		if ( ! $booking_link_id ) {
			return;
		}
		update_post_meta( $booking_link_id, '_oa_booking_bookings', (int) get_post_meta( $booking_link_id, '_oa_booking_bookings', true ) + 1 );
	}
}
