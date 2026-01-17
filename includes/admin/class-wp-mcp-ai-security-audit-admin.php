<?php
/**
 * Security Audit Admin Page
 *
 * Admin interface for viewing audit statistics and managing audit schedule.
 *
 * @package    WP_MCP_AI
 * @subpackage WP_MCP_AI/includes/admin
 * @since      1.0.0
 */

// Prevent direct file access.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security Audit Admin Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Security_Audit_Admin {

	/**
	 * Constructor
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_admin_page' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
	}

	/**
	 * Add admin page under NV oOS Pro menu
	 *
	 * @return void
	 */
	public function add_admin_page() {
		add_submenu_page(
			'nvoos-pro-dashboard',
			__( 'Security Audits Dashboard', 'mcp-ai-wpoos' ),
			__( 'Security Audits', 'mcp-ai-wpoos' ),
			'manage_options',
			'nvoos-pro-dashboard-audits',
			array( $this, 'render_admin_page' )
		);
	}

	/**
	 * Enqueue admin assets
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ) {
		if ( 'nvoos-pro-dashboard_page_nvoos-pro-dashboard-audits' !== $hook ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-security-audit-admin',
			WP_MCP_AI_URL . 'assets/css/security-audit-admin.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-security-audit-admin',
			WP_MCP_AI_URL . 'assets/js/security-audit-admin.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);
	}

	/**
	 * Render admin page
	 *
	 * @return void
	 */
	public function render_admin_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}

		$audit_system  = WP_MCP_AI_Security_Audit::get_instance();
		$stats         = $audit_system->get_audit_statistics();
		$recent_audits = $audit_system->get_recent_audits( 10 );
		?>
		<div class="wrap wp-mcp-ai-security-audit-admin">
			<h1><?php esc_html_e( 'Security Audits Dashboard', 'mcp-ai-wpoos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'ISO 27001:2022 Control A.5.35 - Independent Review of Information Security', 'mcp-ai-wpoos' ); ?>
			</p>

			<!-- Statistics Cards -->
			<div class="wp-mcp-ai-audit-stats">
				<div class="wp-mcp-ai-stat-card">
					<div class="stat-value"><?php echo esc_html( $stats['total_audits'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Total Audits', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card stat-completed">
					<div class="stat-value"><?php echo esc_html( $stats['completed'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Completed', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card stat-in-progress">
					<div class="stat-value"><?php echo esc_html( $stats['in_progress'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'In Progress', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card stat-scheduled">
					<div class="stat-value"><?php echo esc_html( $stats['scheduled'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Scheduled', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card stat-overdue">
					<div class="stat-value"><?php echo esc_html( $stats['overdue'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Overdue', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card stat-findings">
					<div class="stat-value"><?php echo esc_html( $stats['total_findings'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Total Findings', 'mcp-ai-wpoos' ); ?></div>
				</div>

				<div class="wp-mcp-ai-stat-card stat-open-findings">
					<div class="stat-value"><?php echo esc_html( $stats['open_findings'] ); ?></div>
					<div class="stat-label"><?php esc_html_e( 'Open Findings', 'mcp-ai-wpoos' ); ?></div>
				</div>
			</div>

			<!-- Action Buttons -->
			<div class="wp-mcp-ai-audit-actions">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_audit' ) ); ?>" class="button button-primary button-large">
					<?php esc_html_e( 'Create New Audit', 'mcp-ai-wpoos' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_audit' ) ); ?>" class="button button-large">
					<?php esc_html_e( 'View All Audits', 'mcp-ai-wpoos' ); ?>
				</a>
			</div>

			<!-- Recent Audits Table -->
			<h2><?php esc_html_e( 'Recent Audits', 'mcp-ai-wpoos' ); ?></h2>
			<?php if ( ! empty( $recent_audits ) ) : ?>
				<table class="wp-list-table widefat fixed striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Audit', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Auditor', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Findings', 'mcp-ai-wpoos' ); ?></th>
							<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_audits as $audit ) : ?>
							<?php
							$audit_date     = get_post_meta( $audit->ID, '_wp_mcp_ai_audit_date', true );
							$audit_type     = get_post_meta( $audit->ID, '_wp_mcp_ai_audit_type', true );
							$audit_status   = get_post_meta( $audit->ID, '_wp_mcp_ai_audit_status', true );
							$auditor        = get_post_meta( $audit->ID, '_wp_mcp_ai_auditor', true );
							$findings       = get_post_meta( $audit->ID, '_wp_mcp_ai_audit_findings', true );
							$findings_count = is_array( $findings ) ? count( $findings ) : 0;
							?>
							<tr>
								<td>
									<strong>
										<a href="<?php echo esc_url( get_edit_post_link( $audit->ID ) ); ?>">
											<?php echo esc_html( $audit->post_title ); ?>
										</a>
									</strong>
								</td>
								<td><?php echo esc_html( $audit_date ? gmdate( 'M j, Y', strtotime( $audit_date ) ) : '-' ); ?></td>
								<td><?php echo esc_html( $this->format_audit_type( $audit_type ) ); ?></td>
								<td><?php echo wp_kses_post( $this->format_audit_status( $audit_status ) ); ?></td>
								<td><?php echo esc_html( $auditor ?: '-' ); ?></td>
								<td>
									<?php if ( $findings_count > 0 ) : ?>
										<span class="wp-mcp-ai-badge badge-findings">
											<?php echo esc_html( $findings_count ); ?>
										</span>
									<?php else : ?>
										-
									<?php endif; ?>
								</td>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $audit->ID ) ); ?>" class="button button-small">
										<?php esc_html_e( 'Edit', 'mcp-ai-wpoos' ); ?>
									</a>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php else : ?>
				<p><?php esc_html_e( 'No audits found. Create your first security audit to get started.', 'mcp-ai-wpoos' ); ?></p>
			<?php endif; ?>

			<!-- Audit Schedule Information -->
			<div class="wp-mcp-ai-audit-schedule">
				<h2><?php esc_html_e( 'Audit Schedule', 'mcp-ai-wpoos' ); ?></h2>
				<p>
					<?php esc_html_e( 'Quarterly internal audits are automatically scheduled on the first day of each quarter (January 1, April 1, July 1, October 1).', 'mcp-ai-wpoos' ); ?>
				</p>
				<p>
					<strong><?php esc_html_e( 'Next Scheduled Audit:', 'mcp-ai-wpoos' ); ?></strong>
					<?php
					$next_audit = wp_next_scheduled( 'wp_mcp_ai_quarterly_audit' );
					if ( $next_audit ) {
						echo esc_html( gmdate( 'F j, Y', $next_audit ) );
					} else {
						esc_html_e( 'Not scheduled', 'mcp-ai-wpoos' );
					}
					?>
				</p>
			</div>

			<!-- Quick Links -->
			<div class="wp-mcp-ai-quick-links">
				<h2><?php esc_html_e( 'ISO 27001 Resources', 'mcp-ai-wpoos' ); ?></h2>
				<ul>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-dashboard' ) ); ?>">
							<?php esc_html_e( 'Pro Dashboard - Compliance Overview', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_lesson' ) ); ?>">
							<?php esc_html_e( 'Lessons Learned (A.5.27)', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-dashboard-suppliers' ) ); ?>">
							<?php esc_html_e( 'Supplier Security (A.5.19-A.5.22)', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
					<li>
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-dashboard-asset-inventory' ) ); ?>">
							<?php esc_html_e( 'Asset Inventory (A.5.9)', 'mcp-ai-wpoos' ); ?>
						</a>
					</li>
				</ul>
			</div>
		</div>
		<?php
	}

	/**
	 * Format audit type for display
	 *
	 * @param string $type Audit type.
	 * @return string
	 */
	private function format_audit_type( $type ) {
		$types = array(
			'internal'          => __( 'Internal Audit', 'mcp-ai-wpoos' ),
			'external'          => __( 'External Audit', 'mcp-ai-wpoos' ),
			'management_review' => __( 'Management Review', 'mcp-ai-wpoos' ),
		);

		return isset( $types[ $type ] ) ? $types[ $type ] : '-';
	}

	/**
	 * Format audit status for display
	 *
	 * @param string $status Audit status.
	 * @return string
	 */
	private function format_audit_status( $status ) {
		$statuses = array(
			'scheduled'   => '<span class="wp-mcp-ai-badge badge-scheduled">%s</span>',
			'in_progress' => '<span class="wp-mcp-ai-badge badge-in-progress">%s</span>',
			'completed'   => '<span class="wp-mcp-ai-badge badge-completed">%s</span>',
			'overdue'     => '<span class="wp-mcp-ai-badge badge-overdue">%s</span>',
		);

		$labels = array(
			'scheduled'   => __( 'Scheduled', 'mcp-ai-wpoos' ),
			'in_progress' => __( 'In Progress', 'mcp-ai-wpoos' ),
			'completed'   => __( 'Completed', 'mcp-ai-wpoos' ),
			'overdue'     => __( 'Overdue', 'mcp-ai-wpoos' ),
		);

		if ( isset( $statuses[ $status ] ) && isset( $labels[ $status ] ) ) {
			return sprintf( $statuses[ $status ], esc_html( $labels[ $status ] ) );
		}

		return '-';
	}
}
