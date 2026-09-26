<?php
/**
 * Outbound Appointment Booking — command dashboard.
 *
 * Top-level admin page with the pipeline funnel, outbox summary, angle test
 * leaderboard, CSV list import, and toolkit settings.
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
 * Command dashboard page.
 *
 * @since 2.12.0
 */
class WP_MCP_AI_OA_Dashboard {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-outbound';

	/**
	 * Capability required to view the dashboard.
	 *
	 * @var string
	 */
	const CAP = 'edit_posts';

	/**
	 * Initialize.
	 *
	 * @since 2.12.0
	 */
	public static function init() {
		if ( ! WP_MCP_AI_OA_Settings::is_enabled() ) {
			return;
		}
		add_action( 'admin_menu', array( __CLASS__, 'register_menu' ) );
		add_action( 'admin_post_wp_mcp_ai_oa_import', array( __CLASS__, 'handle_import' ) );
		add_action( 'admin_post_wp_mcp_ai_oa_settings_save', array( __CLASS__, 'handle_settings_save' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
	}

	/**
	 * Register the top-level menu and submenus.
	 *
	 * @since 2.12.0
	 */
	public static function register_menu() {
		add_menu_page(
			__( 'Outbound', 'mcp-ai-wpoos-pro' ),
			__( 'Outbound', 'mcp-ai-wpoos-pro' ),
			self::CAP,
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' ),
			'dashicons-paper-plane',
			56
		);
		add_submenu_page( self::PAGE_SLUG, __( 'Pipeline', 'mcp-ai-wpoos-pro' ), __( 'Pipeline', 'mcp-ai-wpoos-pro' ), self::CAP, self::PAGE_SLUG, array( __CLASS__, 'render_page' ) );
		add_submenu_page( self::PAGE_SLUG, __( 'Approval Board', 'mcp-ai-wpoos-pro' ), __( 'Approval Board', 'mcp-ai-wpoos-pro' ), self::CAP, 'edit.php?post_type=' . WP_MCP_AI_OA_Outbox::POST_TYPE );
		add_submenu_page( self::PAGE_SLUG, __( 'Angle Bank', 'mcp-ai-wpoos-pro' ), __( 'Angle Bank', 'mcp-ai-wpoos-pro' ), self::CAP, 'edit.php?post_type=' . WP_MCP_AI_OA_Angle_CPT::POST_TYPE );
		add_submenu_page( self::PAGE_SLUG, __( 'Booking Links', 'mcp-ai-wpoos-pro' ), __( 'Booking Links', 'mcp-ai-wpoos-pro' ), self::CAP, 'edit.php?post_type=' . WP_MCP_AI_OA_Booking_Link_CPT::POST_TYPE );
	}

	/**
	 * Enqueue dashboard styles.
	 *
	 * @since 2.12.0
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( 'toplevel_page_' . self::PAGE_SLUG !== $hook ) {
			return;
		}
		$css = WP_MCP_AI_PRO_PATH . 'assets/css/admin-outbound-booking.css';
		if ( file_exists( $css ) ) {
			wp_enqueue_style( 'wp-mcp-ai-oa-admin', WP_MCP_AI_PRO_URL . 'assets/css/admin-outbound-booking.css', array(), WP_MCP_AI_PRO_VERSION );
		}
	}

	/**
	 * Render the dashboard.
	 *
	 * @since 2.12.0
	 */
	public static function render_page() {
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to view this page.', 'mcp-ai-wpoos-pro' ) );
		}
		$tab = isset( $_GET['tab'] ) ? sanitize_key( wp_unslash( $_GET['tab'] ) ) : 'pipeline'; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only tab switch.
		$tabs = array(
			'pipeline' => __( 'Pipeline', 'mcp-ai-wpoos-pro' ),
			'tests'    => __( 'Angles & Tests', 'mcp-ai-wpoos-pro' ),
			'import'   => __( 'Import ICP List', 'mcp-ai-wpoos-pro' ),
			'settings' => __( 'Settings', 'mcp-ai-wpoos-pro' ),
		);
		?>
		<div class="wrap oa-dashboard">
			<h1><?php esc_html_e( 'Outbound Appointment Booking', 'mcp-ai-wpoos-pro' ); ?></h1>
			<?php self::render_notices(); ?>
			<h2 class="nav-tab-wrapper">
				<?php foreach ( $tabs as $slug => $label ) : ?>
					<a class="nav-tab <?php echo $tab === $slug ? 'nav-tab-active' : ''; ?>" href="<?php echo esc_url( add_query_arg( 'tab', $slug, admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) ); ?>">
						<?php echo esc_html( $label ); ?>
					</a>
				<?php endforeach; ?>
			</h2>
			<?php
			switch ( $tab ) {
				case 'tests':
					self::render_tests_tab();
					break;
				case 'import':
					self::render_import_tab();
					break;
				case 'settings':
					self::render_settings_tab();
					break;
				default:
					self::render_pipeline_tab();
			}
			?>
		</div>
		<?php
	}

	/**
	 * Render dependency notices (CRM / Calendar Booking toggles).
	 *
	 * @since 2.12.0
	 */
	private static function render_notices() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_crm_toolkit'] ) ) {
			echo '<div class="notice notice-warning"><p>';
			esc_html_e( 'The CRM Toolkit is disabled. Enable it (Settings → NV oOS → Tools & Features) so the outbound engine can create and message leads.', 'mcp-ai-wpoos-pro' );
			echo '</p></div>';
		}
		if ( empty( $settings['enable_calendar_booking_toolkit'] ) ) {
			echo '<div class="notice notice-info"><p>';
			esc_html_e( 'The Calendar Booking Toolkit is disabled — internal booking forms will not work until it is enabled. External (Calendly/Cal.com) links work regardless.', 'mcp-ai-wpoos-pro' );
			echo '</p></div>';
		}
	}

	/**
	 * Render the pipeline tab.
	 *
	 * @since 2.12.0
	 */
	private static function render_pipeline_tab() {
		$stats = WP_MCP_AI_OA_Engine::get_pipeline_stats();
		$cards = array(
			array( __( 'Prospects', 'mcp-ai-wpoos-pro' ), $stats['prospects'] ),
			array( __( 'Messaged', 'mcp-ai-wpoos-pro' ), $stats['messaged'] ),
			array( __( 'Replied', 'mcp-ai-wpoos-pro' ), $stats['replied'] ),
			array( __( 'Positive', 'mcp-ai-wpoos-pro' ), $stats['positive'] ),
			array( __( 'Booked calls', 'mcp-ai-wpoos-pro' ), $stats['booked'] ),
			array( __( 'Shown up', 'mcp-ai-wpoos-pro' ), $stats['shown'] ),
		);
		$rates = array(
			/* translators: %s: reply rate */
			sprintf( __( 'Reply rate: %s%%', 'mcp-ai-wpoos-pro' ), $stats['reply_rate'] ),
			/* translators: %s: positive rate */
			sprintf( __( 'Positive rate: %s%%', 'mcp-ai-wpoos-pro' ), $stats['positive_rate'] ),
			/* translators: %s: book rate */
			sprintf( __( 'Book rate: %s%%', 'mcp-ai-wpoos-pro' ), $stats['book_rate'] ),
			/* translators: %s: show rate */
			sprintf( __( 'Show rate: %s%%', 'mcp-ai-wpoos-pro' ), $stats['show_rate'] ),
		);
		?>
		<div class="oa-stat-grid">
			<?php foreach ( $cards as $card ) : ?>
				<div class="oa-stat-card">
					<div class="oa-stat-value"><?php echo esc_html( $card[1] ); ?></div>
					<div class="oa-stat-label"><?php echo esc_html( $card[0] ); ?></div>
				</div>
			<?php endforeach; ?>
		</div>
		<p class="oa-rates"><?php echo esc_html( implode( ' · ', $rates ) ); ?></p>

		<h3><?php esc_html_e( 'Funnel', 'mcp-ai-wpoos-pro' ); ?></h3>
		<div class="oa-funnel">
			<?php
			$steps = array(
				__( 'Prospects', 'mcp-ai-wpoos-pro' )  => $stats['prospects'],
				__( 'Messaged', 'mcp-ai-wpoos-pro' )   => $stats['messaged'],
				__( 'Replied', 'mcp-ai-wpoos-pro' )    => $stats['replied'],
				__( 'Positive', 'mcp-ai-wpoos-pro' )   => $stats['positive'],
				__( 'Booked', 'mcp-ai-wpoos-pro' )     => $stats['booked'],
				__( 'Shown up', 'mcp-ai-wpoos-pro' )   => $stats['shown'],
			);
			$max = max( 1, $stats['prospects'] );
			foreach ( $steps as $label => $value ) {
				$width = round( $value / $max * 100 );
				echo '<div class="oa-funnel-row"><div class="oa-funnel-label">' . esc_html( $label ) . '</div><div class="oa-funnel-bar"><div class="oa-funnel-fill" style="width:' . esc_attr( $width ) . '%"></div></div><div class="oa-funnel-value">' . esc_html( $value ) . '</div></div>';
			}
			?>
		</div>

		<h3><?php esc_html_e( 'Recent bookings', 'mcp-ai-wpoos-pro' ); ?></h3>
		<?php
		$recent = array();
		if ( post_type_exists( 'mcp_appointment' ) ) {
			$recent = get_posts(
				array(
					'post_type'      => 'mcp_appointment',
					'post_status'    => 'any',
					'posts_per_page' => 10,
					'no_found_rows'  => true,
					'meta_key'       => '_oa_booking_link_id', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Bounded recent-bookings list.
				)
			);
		}
		if ( empty( $recent ) ) {
			echo '<p>' . esc_html__( 'No bookings yet. Share your booking link inside your sequences to start the flow.', 'mcp-ai-wpoos-pro' ) . '</p>';
		} else {
			echo '<table class="widefat striped"><thead><tr><th>' . esc_html__( 'Prospect', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Slot', 'mcp-ai-wpoos-pro' ) . '</th><th>' . esc_html__( 'Status', 'mcp-ai-wpoos-pro' ) . '</th></tr></thead><tbody>';
			foreach ( $recent as $row ) {
				$status = get_post_meta( $row->ID, '_status', true );
				echo '<tr><td><a href="' . esc_url( get_edit_post_link( $row->ID ) ) . '">' . esc_html( $row->post_title ) . '</a></td><td>' . esc_html( get_post_meta( $row->ID, '_start_time', true ) ) . '</td><td>' . esc_html( $status ? $status : 'pending' ) . '</td></tr>';
			}
			echo '</tbody></table>';
		}
		?>
		<p>
			<a class="button button-primary" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . WP_MCP_AI_OA_Outbox::POST_TYPE ) ); ?>">
				<?php esc_html_e( 'Open Approval Board', 'mcp-ai-wpoos-pro' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=import' ) ); ?>">
				<?php esc_html_e( 'Import ICP List', 'mcp-ai-wpoos-pro' ); ?>
			</a>
			<a class="button" href="<?php echo esc_url( admin_url( 'edit.php?post_type=' . WP_MCP_AI_OA_Booking_Link_CPT::POST_TYPE ) ); ?>">
				<?php esc_html_e( 'Manage Booking Links', 'mcp-ai-wpoos-pro' ); ?>
			</a>
		</p>
		<?php
	}

	/**
	 * Render the tests tab.
	 *
	 * @since 2.12.0
	 */
	private static function render_tests_tab() {
		$rows  = WP_MCP_AI_OA_Angle_CPT::get_leaderboard();
		$history = get_option( WP_MCP_AI_OA_Engine::HISTORY_OPTION, array() );
		if ( ! is_array( $history ) ) {
			$history = array();
		}
		?>
		<p><?php esc_html_e( 'Angles accumulate sends and replies as the engine runs. Every week the winning variant in each group is promoted to champion automatically (after the minimum-send threshold).', 'mcp-ai-wpoos-pro' ); ?></p>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Angle', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Test', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Sends', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Replies', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Booked', 'mcp-ai-wpoos-pro' ); ?></th>
					<th><?php esc_html_e( 'Reply rate', 'mcp-ai-wpoos-pro' ); ?></th>
				</tr>
			</thead>
			<tbody>
				<?php if ( empty( $rows ) ) : ?>
					<tr><td colspan="6"><?php esc_html_e( 'No angles yet — add entries to the Angle Bank to power your sequences.', 'mcp-ai-wpoos-pro' ); ?></td></tr>
				<?php endif; ?>
				<?php foreach ( $rows as $row ) : ?>
					<tr>
						<td><a href="<?php echo esc_url( get_edit_post_link( $row['id'] ) ); ?>"><?php echo esc_html( $row['title'] ); ?></a></td>
						<td><?php echo esc_html( $row['status'] ? ucfirst( $row['status'] ) : '—' ); ?></td>
						<td><?php echo esc_html( $row['sends'] ); ?></td>
						<td><?php echo esc_html( $row['replies'] ); ?></td>
						<td><?php echo esc_html( $row['booked'] ); ?></td>
						<td><?php echo esc_html( $row['reply_rate'] ); ?>%</td>
					</tr>
				<?php endforeach; ?>
			</tbody>
		</table>
		<?php if ( ! empty( $history ) ) : ?>
			<h3><?php esc_html_e( 'Test history', 'mcp-ai-wpoos-pro' ); ?></h3>
			<?php foreach ( array_slice( $history, 0, 3 ) as $run ) : ?>
				<p><strong><?php echo esc_html( date_i18n( 'M j, Y', strtotime( $run['date'] ) ) ); ?></strong>:
				<?php
				$parts = array();
				foreach ( $run['promotions'] as $p ) {
					$parts[] = sprintf(
						/* translators: 1: group, 2: winner */
						__( '%1$s → %2$s', 'mcp-ai-wpoos-pro' ),
						$p['group'],
						$p['winner']
					);
				}
				echo esc_html( implode( ' · ', $parts ) );
				?>
				</p>
			<?php endforeach; ?>
		<?php endif; ?>
		<?php
	}

	/**
	 * Render the import tab.
	 *
	 * @since 2.12.0
	 */
	private static function render_import_tab() {
		$sequences = post_type_exists( 'mcp_ai_sequence' ) ? get_posts(
			array(
				'post_type'      => 'mcp_ai_sequence',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		) : array();
		?>
		<p><?php esc_html_e( 'Paste a CSV list of decision-makers. Columns: email, first_name, last_name, company, job_title, linkedin, instagram. A header row is optional. Imports are deduped by email and scored against your default ICP profile.', 'mcp-ai-wpoos-pro' ); ?></p>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp_mcp_ai_oa_import" />
			<?php wp_nonce_field( 'wp_mcp_ai_oa_import' ); ?>
			<table class="form-table">
				<tr>
					<th><label for="oa-import-csv"><?php esc_html_e( 'CSV data', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><textarea id="oa-import-csv" name="csv" rows="12" class="large-text" placeholder="<?php echo esc_attr( "email,first_name,last_name,company,job_title,linkedin,instagram\njane@acme.io,Jane,Smith,Acme,CEO,https://linkedin.com/in/janesmith,@acme" ); ?>"></textarea></td>
				</tr>
				<tr>
					<th><label for="oa-import-sequence"><?php esc_html_e( 'Enroll in sequence', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="oa-import-sequence" name="sequence_id">
							<option value="0"><?php esc_html_e( 'None — import only', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $sequences as $seq ) : ?>
								<option value="<?php echo esc_attr( $seq->ID ); ?>"><?php echo esc_html( $seq->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Email consent', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<label>
							<input type="checkbox" name="consent" value="1" checked />
							<?php esc_html_e( 'I attest these are business contacts I can legally email (required for auto-send email).', 'mcp-ai-wpoos-pro' ); ?>
						</label>
					</td>
				</tr>
			</table>
			<p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e( 'Import Leads', 'mcp-ai-wpoos-pro' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Render the settings tab.
	 *
	 * @since 2.12.0
	 */
	private static function render_settings_tab() {
		$s       = WP_MCP_AI_OA_Settings::get();
		$links   = get_posts(
			array(
				'post_type'      => WP_MCP_AI_OA_Booking_Link_CPT::POST_TYPE,
				'post_status'    => 'any',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		$pages   = get_posts(
			array(
				'post_type'      => 'page',
				'post_status'    => 'publish',
				'posts_per_page' => 200,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'no_found_rows'  => true,
			)
		);
		?>
		<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
			<input type="hidden" name="action" value="wp_mcp_ai_oa_settings_save" />
			<?php wp_nonce_field( 'wp_mcp_ai_oa_settings_save' ); ?>
			<table class="form-table">
				<tr><th colspan="2"><h3><?php esc_html_e( 'Sending identity', 'mcp-ai-wpoos-pro' ); ?></h3></th></tr>
				<tr>
					<th><label for="oa-from-name"><?php esc_html_e( 'From name', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="text" class="regular-text" id="oa-from-name" name="oa[from_name]" value="<?php echo esc_attr( $s['from_name'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="oa-from-email"><?php esc_html_e( 'From email', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="email" class="regular-text" id="oa-from-email" name="oa[from_email]" value="<?php echo esc_attr( $s['from_email'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="oa-signature"><?php esc_html_e( 'Email signature', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><textarea class="large-text" id="oa-signature" name="oa[sender_signature]" rows="3"><?php echo esc_textarea( $s['sender_signature'] ); ?></textarea></td>
				</tr>
				<tr><th colspan="2"><h3><?php esc_html_e( 'Channels', 'mcp-ai-wpoos-pro' ); ?></h3></th></tr>
				<tr>
					<th><label for="oa-email-mode"><?php esc_html_e( 'Email', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="oa-email-mode" name="oa[email_mode]">
							<option value="approval" <?php selected( $s['email_mode'], 'approval' ); ?>><?php esc_html_e( 'Approve before sending', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="auto" <?php selected( $s['email_mode'], 'auto' ); ?>><?php esc_html_e( 'Send automatically', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="off" <?php selected( $s['email_mode'], 'off' ); ?>><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="oa-linkedin-mode"><?php esc_html_e( 'LinkedIn DMs', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="oa-linkedin-mode" name="oa[linkedin_mode]">
							<option value="approval" <?php selected( $s['linkedin_mode'], 'approval' ); ?>><?php esc_html_e( 'Approve & send manually', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="webhook" <?php selected( $s['linkedin_mode'], 'webhook' ); ?>><?php esc_html_e( 'Push to automation webhook', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="off" <?php selected( $s['linkedin_mode'], 'off' ); ?>><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="oa-instagram-mode"><?php esc_html_e( 'Instagram DMs', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="oa-instagram-mode" name="oa[instagram_mode]">
							<option value="approval" <?php selected( $s['instagram_mode'], 'approval' ); ?>><?php esc_html_e( 'Approve & send manually', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="webhook" <?php selected( $s['instagram_mode'], 'webhook' ); ?>><?php esc_html_e( 'Push to automation webhook', 'mcp-ai-wpoos-pro' ); ?></option>
							<option value="off" <?php selected( $s['instagram_mode'], 'off' ); ?>><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></option>
						</select>
					</td>
				</tr>
				<tr>
					<th><label for="oa-webhook-url"><?php esc_html_e( 'Automation webhook URL', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="url" class="regular-text" id="oa-webhook-url" name="oa[webhook_url]" value="<?php echo esc_attr( $s['webhook_url'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Receives LinkedIn/Instagram send requests as JSON (Make, n8n, Instantly, Zapier).', 'mcp-ai-wpoos-pro' ); ?></p></td>
				</tr>
				<tr><th colspan="2"><h3><?php esc_html_e( 'Cadence & limits', 'mcp-ai-wpoos-pro' ); ?></h3></th></tr>
				<tr>
					<th><label for="oa-daily-cap"><?php esc_html_e( 'Daily send cap', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="number" class="small-text" id="oa-daily-cap" name="oa[daily_cap]" value="<?php echo esc_attr( $s['daily_cap'] ); ?>" min="1" max="1000" /></td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Send window', 'mcp-ai-wpoos-pro' ); ?></th>
					<td>
						<input type="number" class="small-text" name="oa[send_window_start]" value="<?php echo esc_attr( $s['send_window_start'] ); ?>" min="0" max="23" />
						<?php esc_html_e( 'to', 'mcp-ai-wpoos-pro' ); ?>
						<input type="number" class="small-text" name="oa[send_window_end]" value="<?php echo esc_attr( $s['send_window_end'] ); ?>" min="0" max="23" />
						<p class="description"><?php esc_html_e( 'Site-local hours.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="oa-champion"><?php esc_html_e( 'Champion allocation', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="number" class="small-text" id="oa-champion" name="oa[champion_allocation]" value="<?php echo esc_attr( $s['champion_allocation'] ); ?>" min="50" max="100" /> %
					<p class="description"><?php esc_html_e( 'Share of sends going to the champion variant.', 'mcp-ai-wpoos-pro' ); ?></p></td>
				</tr>
				<tr>
					<th><label for="oa-min-sends"><?php esc_html_e( 'Min sends per test', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="number" class="small-text" id="oa-min-sends" name="oa[min_test_sends]" value="<?php echo esc_attr( $s['min_test_sends'] ); ?>" min="1" /></td>
				</tr>
				<tr><th colspan="2"><h3><?php esc_html_e( 'Booking', 'mcp-ai-wpoos-pro' ); ?></h3></th></tr>
				<tr>
					<th><label for="oa-booking-page"><?php esc_html_e( 'Booking page', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="oa-booking-page" name="oa[booking_page_id]">
							<option value="0"><?php esc_html_e( 'None', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $pages as $page ) : ?>
								<option value="<?php echo esc_attr( $page->ID ); ?>" <?php selected( $s['booking_page_id'], $page->ID ); ?>><?php echo esc_html( $page->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
						<p class="description"><?php esc_html_e( 'The page that contains the [nvoos_oa_booking] shortcode. Used to build booking URLs in messages.', 'mcp-ai-wpoos-pro' ); ?></p>
					</td>
				</tr>
				<tr>
					<th><label for="oa-default-link"><?php esc_html_e( 'Default booking link', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td>
						<select id="oa-default-link" name="oa[default_booking_link]">
							<option value="0"><?php esc_html_e( 'None', 'mcp-ai-wpoos-pro' ); ?></option>
							<?php foreach ( $links as $link ) : ?>
								<option value="<?php echo esc_attr( $link->ID ); ?>" <?php selected( $s['default_booking_link'], $link->ID ); ?>><?php echo esc_html( $link->post_title ); ?></option>
							<?php endforeach; ?>
						</select>
					</td>
				</tr>
				<tr><th colspan="2"><h3><?php esc_html_e( 'Notifications', 'mcp-ai-wpoos-pro' ); ?></h3></th></tr>
				<tr>
					<th><label for="oa-slack-url"><?php esc_html_e( 'Slack webhook URL', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="url" class="regular-text" id="oa-slack-url" name="oa[slack_webhook_url]" value="<?php echo esc_attr( $s['slack_webhook_url'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="oa-digest-enabled"><?php esc_html_e( 'Daily digest', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><label><input type="checkbox" id="oa-digest-enabled" name="oa[digest_enabled]" value="1" <?php checked( $s['digest_enabled'], '1' ); ?> /> <?php esc_html_e( 'Send a daily pipeline digest to Slack', 'mcp-ai-wpoos-pro' ); ?></label></td>
				</tr>
				<tr>
					<th><label for="oa-digest-email"><?php esc_html_e( 'Digest email (optional)', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="email" class="regular-text" id="oa-digest-email" name="oa[digest_email]" value="<?php echo esc_attr( $s['digest_email'] ); ?>" /></td>
				</tr>
				<tr>
					<th><label for="oa-reply-token"><?php esc_html_e( 'Reply webhook token', 'mcp-ai-wpoos-pro' ); ?></label></th>
					<td><input type="text" class="regular-text" id="oa-reply-token" name="oa[reply_token]" value="<?php echo esc_attr( $s['reply_token'] ); ?>" />
					<p class="description"><?php esc_html_e( 'Send replies via POST /wp-json/nvoos-outbound/v1/replies with header X-OA-Token.', 'mcp-ai-wpoos-pro' ); ?></p></td>
				</tr>
			</table>
			<p class="submit"><button class="button button-primary" type="submit"><?php esc_html_e( 'Save Settings', 'mcp-ai-wpoos-pro' ); ?></button></p>
		</form>
		<?php
	}

	/**
	 * Handle the import form post.
	 *
	 * @since 2.12.0
	 */
	public static function handle_import() {
		check_admin_referer( 'wp_mcp_ai_oa_import' );
		if ( ! current_user_can( self::CAP ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mcp-ai-wpoos-pro' ) );
		}
		$csv         = isset( $_POST['csv'] ) ? wp_unslash( $_POST['csv'] ) : ''; // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Parsed + sanitised per-field in the importer.
		$sequence_id = isset( $_POST['sequence_id'] ) ? absint( $_POST['sequence_id'] ) : 0;
		$consent     = ! empty( $_POST['consent'] );
		$rows        = WP_MCP_AI_OA_Import::parse_csv( $csv );
		$result      = WP_MCP_AI_OA_Import::import_rows( $rows, $sequence_id, $consent );

		$redirect = add_query_arg( 'tab', 'import', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) );
		if ( is_wp_error( $result ) ) {
			$redirect = add_query_arg( 'oa_error', rawurlencode( $result->get_error_message() ), $redirect );
		} else {
			$redirect = add_query_arg(
				array(
					'oa_created' => $result['created'],
					'oa_dupes'   => $result['skipped_duplicate'],
					'oa_bad'     => $result['skipped_invalid'],
					'oa_scored'  => $result['scored'],
				),
				$redirect
			);
		}
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Handle the settings form post.
	 *
	 * @since 2.12.0
	 */
	public static function handle_settings_save() {
		check_admin_referer( 'wp_mcp_ai_oa_settings_save' );
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You are not allowed to do that.', 'mcp-ai-wpoos-pro' ) );
		}
		$raw = isset( $_POST['oa'] ) && is_array( $_POST['oa'] ) ? wp_unslash( $_POST['oa'] ) : array(); // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Sanitised by the settings accessor.
		WP_MCP_AI_OA_Settings::update( $raw );
		wp_safe_redirect( add_query_arg( 'tab', 'settings', admin_url( 'admin.php?page=' . self::PAGE_SLUG ) ) );
		exit;
	}
}
