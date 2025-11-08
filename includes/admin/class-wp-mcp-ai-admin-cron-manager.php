<?php
/**
 * Admin cron manager for WP oOS.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Cron_Manager' ) ) {
	require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-cron-manager.php';
}

/**
 * Renders the management UI for cron events scheduled via WP oOS.
 */
class WP_MCP_AI_Admin_Cron_Manager {
	const PAGE_SLUG = 'wp-mcp-ai-cron-manager';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook = '';

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_post_wp_mcp_ai_delete_cron', array( $this, 'handle_delete_cron' ) );
	}

	/**
	 * Register the cron manager page under the WP oOS menu.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'WP oOS Cron Manager', 'wp-mcp-ai' ),
			__( 'Cron Manager', 'wp-mcp-ai' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue lightweight styles for the cron table.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		$inline_css = '.wp-mcp-ai-cron-manager__table{margin-top:1.5rem;border-collapse:collapse;width:100%;}'
			. '.wp-mcp-ai-cron-manager__table th,.wp-mcp-ai-cron-manager__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-cron-manager__table th{background:#f8f9ff;font-weight:600;}'
			. '.wp-mcp-ai-cron-manager__empty{margin-top:1.5rem;padding:1rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-cron-manager__actions form{display:inline-block;margin-right:0.5rem;}'
			. '.wp-mcp-ai-cron-manager__args{font-family:monospace;font-size:13px;white-space:pre-wrap;word-break:break-word;}';

		wp_register_style( 'wp-mcp-ai-cron-manager-inline', false );
		wp_enqueue_style( 'wp-mcp-ai-cron-manager-inline' );
		wp_add_inline_style( 'wp-mcp-ai-cron-manager-inline', $inline_css );
	}

	/**
	 * Handle deletion of a cron event from the manager.
	 */
	public function handle_delete_cron() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to manage cron events.', 'wp-mcp-ai' ) );
		}

		$job_id = isset( $_POST['job_id'] ) ? sanitize_text_field( wp_unslash( $_POST['job_id'] ) ) : '';

		if ( '' === $job_id ) {
			wp_die( esc_html__( 'Missing cron identifier.', 'wp-mcp-ai' ) );
		}

		check_admin_referer( 'wp_mcp_ai_delete_cron_' . $job_id );

		$deleted = WP_MCP_AI_Cron_Manager::remove_job( $job_id );

		$redirect = add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => $deleted ? '1' : '0',
			),
			admin_url( 'admin.php' )
		);

		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Render the cron manager page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		WP_MCP_AI_Cron_Manager::maybe_prune_jobs();

		$jobs = WP_MCP_AI_Cron_Manager::get_jobs();
		?>
		<div class="wrap">
			<h1><?php esc_html_e( 'WP oOS Cron Manager', 'wp-mcp-ai' ); ?></h1>
			<?php
			// Display update status message if present in query string.
			// Nonce verification not required as this is a read-only display of status after redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) ) :
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				if ( '1' === $_GET['updated'] ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php esc_html_e( 'Cron event removed successfully.', 'wp-mcp-ai' ); ?></p>
					</div>
					<?php
				// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				elseif ( '0' === $_GET['updated'] ) :
					?>
					<div class="notice notice-error is-dismissible">
						<p><?php esc_html_e( 'The cron event could not be removed. It may have already run or been deleted.', 'wp-mcp-ai' ); ?></p>
					</div>
					<?php
				endif;
			endif;
			?>
			<?php if ( empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__empty">
					<p><?php esc_html_e( 'No cron events have been scheduled through WP oOS yet.', 'wp-mcp-ai' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-mcp-ai-cron-manager__table">
					<thead>
						<tr>
							<th scope="col"><?php esc_html_e( 'Hook', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Next run', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Schedule', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Arguments', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created by', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Created at', 'wp-mcp-ai' ); ?></th>
							<th scope="col"><?php esc_html_e( 'Actions', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jobs as $job ) : ?>
							<?php
							$event      = wp_get_scheduled_event( $job['hook'], $job['args'] );
							$next_run   = $event ? $event->timestamp : false;
							$schedule   = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
							$creator    = '';
							$created_by = isset( $job['created_by'] ) ? (int) $job['created_by'] : 0;

							if ( $created_by > 0 ) {
								$user = get_userdata( $created_by );
								if ( $user ) {
									$creator = $user->display_name;
								}
							}

							if ( '' === $creator ) {
								$creator = __( 'System', 'wp-mcp-ai' );
							}

							$created_at   = isset( $job['created_at'] ) && $job['created_at'] ? wp_date( DATE_ATOM, (int) $job['created_at'] ) : __( 'Unknown', 'wp-mcp-ai' );
							$args_display = wp_json_encode( $job['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
							?>
							<tr>
								<td><code><?php echo esc_html( $job['hook'] ); ?></code></td>
								<td>
									<?php
									if ( $next_run ) {
										echo esc_html( wp_date( DATE_ATOM, $next_run ) );
									} else {
										esc_html_e( 'Not scheduled', 'wp-mcp-ai' );
									}
									?>
								</td>
								<td>
									<?php
									if ( 'single' === $schedule || '' === $schedule ) {
										esc_html_e( 'One-off', 'wp-mcp-ai' );
									} else {
										echo esc_html( $schedule );
									}
									?>
								</td>
								<td class="wp-mcp-ai-cron-manager__args">
									<?php echo esc_html( $args_display ); ?>
								</td>
								<td><?php echo esc_html( $creator ); ?></td>
								<td><?php echo esc_html( $created_at ); ?></td>
								<td class="wp-mcp-ai-cron-manager__actions">
									<form method="post" action="<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>">
										<input type="hidden" name="action" value="wp_mcp_ai_delete_cron" />
										<input type="hidden" name="job_id" value="<?php echo esc_attr( $job['job_id'] ); ?>" />
										<?php wp_nonce_field( 'wp_mcp_ai_delete_cron_' . $job['job_id'] ); ?>
										<?php submit_button( __( 'Delete', 'wp-mcp-ai' ), 'delete', '', false ); ?>
									</form>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}
}
