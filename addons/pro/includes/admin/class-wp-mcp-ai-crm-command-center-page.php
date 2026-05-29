<?php
/**
 * CRM Command Center Admin Page
 *
 * Unified CRM dashboard providing pipeline overview, lead/deal KPIs,
 * recent activity feed, sequence status, and analytics — all under
 * the "NV CRM" top-level admin section.
 *
 * Mirrors the Pro Agent Command Center pattern but is CRM-specific.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.1.24
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * CRM Command Center Page Class
 */
class WP_MCP_AI_CRM_Command_Center_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-crm-command-center';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_crm_cc';

	/**
	 * Page hook.
	 *
	 * @var string
	 */
	private static $page_hook = '';

	/**
	 * Initialize the command center page.
	 */
	public static function init() {
		add_action( 'admin_menu', array( __CLASS__, 'register_page' ), 26 );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_crm_cc_get_dashboard', array( __CLASS__, 'ajax_get_dashboard' ) );
		add_action( 'wp_ajax_wp_mcp_ai_crm_cc_get_pipeline', array( __CLASS__, 'ajax_get_pipeline' ) );
	}

	/**
	 * Register the submenu page under NV CRM.
	 */
	public static function register_page() {
		self::$page_hook = add_submenu_page(
			WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG,
			__( 'CRM Command Center', 'mcp-ai-wpoos-pro' ),
			__( 'Command Center', 'mcp-ai-wpoos-pro' ),
			'edit_posts',
			self::PAGE_SLUG,
			array( __CLASS__, 'render_page' )
		);
	}

	/**
	 * Enqueue assets for the command center page.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public static function enqueue_assets( $hook ) {
		if ( self::$page_hook !== $hook ) {
			// Fallback: check GET parameter.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended
			if ( ! isset( $_GET['page'] ) || self::PAGE_SLUG !== $_GET['page'] ) {
				return;
			}
		}

		// CRM command center styles (inline for now; extract later).
		add_action(
			'admin_head',
			function () {
				?>
				<style>
				.crm-cc-wrap { margin: 0 0 0 -20px; }
				.crm-cc-header {
					background: #fff;
					border-bottom: 1px solid #c3c4c7;
					padding: 16px 24px;
					display: flex;
					align-items: center;
					justify-content: space-between;
				}
				.crm-cc-header h1 {
					margin: 0;
					font-size: 20px;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.crm-cc-header .dashicons { font-size: 28px; width: 28px; height: 28px; color: #2271b1; }
				.crm-cc-badge {
					background: #2271b1;
					color: #fff;
					font-size: 10px;
					padding: 2px 6px;
					border-radius: 3px;
					text-transform: uppercase;
					font-weight: 600;
				}
				.crm-cc-subtitle { color: #646970; margin: 4px 0 0; font-size: 13px; }
				.crm-cc-nav {
					background: #fff;
					border-bottom: 1px solid #c3c4c7;
					padding: 0 24px;
				}
				.crm-cc-nav .nav-tab-wrapper { border-bottom: none; margin-bottom: 0; padding-top: 8px; }
				.crm-cc-content { padding: 24px; }
				.crm-cc-kpi-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
					gap: 16px;
					margin-bottom: 24px;
				}
				.crm-cc-kpi {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 16px;
				}
				.crm-cc-kpi-label {
					font-size: 12px;
					color: #646970;
					text-transform: uppercase;
					font-weight: 600;
					margin-bottom: 8px;
				}
				.crm-cc-kpi-value {
					font-size: 28px;
					font-weight: 700;
					line-height: 1.2;
				}
				.crm-cc-kpi-sub {
					font-size: 12px;
					color: #646970;
					margin-top: 4px;
				}
				.crm-cc-kpi-value.win { color: #00a32a; }
				.crm-cc-kpi-value.warn { color: #dba617; }
				.crm-cc-kpi-value.danger { color: #d63638; }
				.crm-cc-pipeline-stage {
					display: flex;
					align-items: center;
					margin-bottom: 12px;
				}
				.crm-cc-pipeline-stage-name {
					width: 140px;
					font-weight: 600;
					font-size: 13px;
				}
				.crm-cc-pipeline-bar-wrap {
					flex: 1;
					background: #f0f0f1;
					border-radius: 3px;
					height: 20px;
					margin: 0 12px;
					overflow: hidden;
				}
				.crm-cc-pipeline-bar {
					background: #2271b1;
					height: 100%;
					border-radius: 3px;
					min-width: 2px;
					transition: width 0.3s ease;
				}
				.crm-cc-pipeline-count {
					font-weight: 600;
					font-size: 13px;
					min-width: 60px;
					text-align: right;
				}
				.crm-cc-section {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 20px;
					margin-bottom: 24px;
				}
				.crm-cc-section h2 {
					margin: 0 0 16px;
					font-size: 16px;
				}
				.crm-cc-inline-cards {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 16px;
				}
				@media (max-width: 768px) {
					.crm-cc-inline-cards { grid-template-columns: 1fr; }
					.crm-cc-kpi-grid { grid-template-columns: repeat(2, 1fr); }
				}
				</style>
				<?php
			}
		);
	}

	/**
	 * Render the main command center page.
	 */
	public static function render_page() {
		if ( ! current_user_can( 'edit_posts' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		$valid_tabs  = array( 'overview', 'pipeline', 'activities', 'sequences', 'analytics', 'configuration' );
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'overview';
		}

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap crm-cc-wrap">
			<div class="crm-cc-header">
				<div>
					<h1>
						<span class="dashicons dashicons-groups"></span>
						<?php esc_html_e( 'CRM Command Center', 'mcp-ai-wpoos-pro' ); ?>
						<span class="crm-cc-badge"><?php esc_html_e( 'PRO', 'mcp-ai-wpoos-pro' ); ?></span>
					</h1>
					<p class="crm-cc-subtitle">
						<?php esc_html_e( 'Manage your sales pipeline, track leads and deals, monitor sequences, and review CRM analytics.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			</div>

			<div class="crm-cc-nav">
				<nav class="nav-tab-wrapper">
					<?php
					$tabs = array(
						'overview'   => __( 'Overview', 'mcp-ai-wpoos-pro' ),
						'pipeline'   => __( 'Pipeline', 'mcp-ai-wpoos-pro' ),
						'activities' => __( 'Activities', 'mcp-ai-wpoos-pro' ),
						'sequences'  => __( 'Sequences', 'mcp-ai-wpoos-pro' ),
						'analytics'  => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
						'configuration' => __( 'Configuration', 'mcp-ai-wpoos-pro' ),
					);
					foreach ( $tabs as $slug => $label ) {
						$class = 'nav-tab' . ( $current_tab === $slug ? ' nav-tab-active' : '' );
						printf(
							'<a href="%s" class="%s">%s</a>',
							esc_url( add_query_arg( 'tab', $slug, $base_url ) ),
							esc_attr( $class ),
							esc_html( $label )
						);
					}
					?>
				</nav>
			</div>

			<div class="crm-cc-content">
				<?php
				switch ( $current_tab ) {
					case 'pipeline':
						self::render_pipeline_tab();
						break;
					case 'activities':
						self::render_activities_tab();
						break;
					case 'sequences':
						self::render_sequences_tab();
						break;
					case 'analytics':
						self::render_analytics_tab();
						break;
					case 'configuration':
						self::render_configuration_tab();
						break;
					default:
						self::render_overview_tab();
						break;
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render the Overview tab with CRM KPIs and quick links.
	 */
	private static function render_overview_tab() {
		$leads_count       = self::get_cpt_count( 'mcp_ai_lead', 'publish' );
		$deals_count       = self::get_cpt_count( 'mcp_ai_deal', 'publish' );
		$companies_count   = self::get_cpt_count( 'mcp_ai_company', 'publish' );
		$sequences_count   = self::get_cpt_count( 'mcp_ai_sequence', 'publish' );
		$pipeline_value    = self::get_pipeline_value();
		$won_deals         = self::get_cpt_count_by_meta( 'mcp_ai_deal', 'publish', '_deal_stage', 'closed_won' );
		$recent_activities = self::get_recent_activities( 5 );
		$active_sequences  = self::get_active_sequences( 5 );
		?>
		<div class="crm-cc-kpi-grid">
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Total Leads', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $leads_count ) ); ?></div>
				<div class="crm-cc-kpi-sub"><?php esc_html_e( 'Open leads', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Active Deals', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $deals_count ) ); ?></div>
				<div class="crm-cc-kpi-sub"><?php esc_html_e( 'Open opportunities', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Pipeline Value', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( self::format_currency( $pipeline_value ) ); ?></div>
				<div class="crm-cc-kpi-sub"><?php esc_html_e( 'Total open pipeline', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Won Deals', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value win"><?php echo esc_html( number_format_i18n( $won_deals ) ); ?></div>
				<div class="crm-cc-kpi-sub"><?php esc_html_e( 'Closed won', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Companies', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $companies_count ) ); ?></div>
				<div class="crm-cc-kpi-sub"><?php esc_html_e( 'In database', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Sequences', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $sequences_count ) ); ?></div>
				<div class="crm-cc-kpi-sub"><?php esc_html_e( 'Active automations', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
		</div>

		<div class="crm-cc-inline-cards">
			<div class="crm-cc-section">
				<h2><?php esc_html_e( 'Recent Activities', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $recent_activities ) ) : ?>
					<p><?php esc_html_e( 'No recent activities.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Subject', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_activities as $activity ) : ?>
								<tr>
									<td><?php echo esc_html( $activity['date'] ); ?></td>
									<td><?php echo esc_html( $activity['type'] ); ?></td>
									<td><?php echo esc_html( $activity['subject'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top: 12px;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=activities' ) ); ?>">
							<?php esc_html_e( 'View all activities →', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>

			<div class="crm-cc-section">
				<h2><?php esc_html_e( 'Active Sequences', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $active_sequences ) ) : ?>
					<p><?php esc_html_e( 'No active sequences.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Sequence', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Steps', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Target', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $active_sequences as $seq ) : ?>
								<tr>
									<td><?php echo esc_html( $seq['title'] ); ?></td>
									<td><?php echo esc_html( $seq['steps'] ); ?></td>
									<td><?php echo esc_html( $seq['target'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top: 12px;">
						<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=sequences' ) ); ?>">
							<?php esc_html_e( 'View all sequences →', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
			</div>
		</div>

		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . WP_MCP_AI_CRM_Command_Center_Page::PAGE_SLUG . '&tab=pipeline' ) ); ?>" class="button">
					<?php esc_html_e( 'View Pipeline', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_company&page=research-company' ) ); ?>" class="button">
					<?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-crm-toolkit-settings' ) ); ?>" class="button">
					<?php esc_html_e( 'CRM Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	/**
	 * Render the Pipeline tab.
	 */
	private static function render_pipeline_tab() {
		$stages = self::get_pipeline_stages();
		?>
		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'Deal Pipeline', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $stages ) ) : ?>
				<p><?php esc_html_e( 'No deals in the pipeline yet.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_deal' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Create First Deal', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			<?php else : ?>
				<?php foreach ( $stages as $stage ) : ?>
					<div class="crm-cc-pipeline-stage">
						<div class="crm-cc-pipeline-stage-name"><?php echo esc_html( $stage['label'] ); ?></div>
						<div class="crm-cc-pipeline-bar-wrap">
							<div class="crm-cc-pipeline-bar" style="width: <?php echo esc_attr( $stage['pct'] ); ?>%"></div>
						</div>
						<div class="crm-cc-pipeline-count">
							<?php echo esc_html( $stage['count'] ); ?>
							<?php if ( $stage['value'] > 0 ) : ?>
								<br><small><?php echo esc_html( self::format_currency( $stage['value'] ) ); ?></small>
							<?php endif; ?>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Activities tab.
	 */
	private static function render_activities_tab() {
		$activities = self::get_recent_activities( 50 );
		?>
		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'CRM Activity Log', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $activities ) ) : ?>
				<p><?php esc_html_e( 'No activities recorded yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Subject', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $activities as $activity ) : ?>
							<tr>
								<td><?php echo esc_html( $activity['date'] ); ?></td>
								<td><?php echo esc_html( $activity['type'] ); ?></td>
								<td><?php echo esc_html( $activity['subject'] ); ?></td>
								<td><?php echo esc_html( $activity['description'] ?? '' ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Sequences tab.
	 */
	private static function render_sequences_tab() {
		$sequences = self::get_all_sequences();
		?>
		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'Outreach Sequences', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $sequences ) ) : ?>
				<p><?php esc_html_e( 'No sequences configured yet.', 'mcp-ai-wpoos-pro' ); ?></p>
				<p>
					<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_sequence' ) ); ?>" class="button button-primary">
						<?php esc_html_e( 'Create First Sequence', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			<?php else : ?>
				<table class="widefat striped">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Sequence', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Steps', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Target', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $sequences as $seq ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( get_edit_post_link( $seq['id'] ) ); ?>">
										<?php echo esc_html( $seq['title'] ); ?>
									</a>
								</td>
								<td><?php echo esc_html( $seq['status'] ); ?></td>
								<td><?php echo esc_html( $seq['steps'] ); ?></td>
								<td><?php echo esc_html( $seq['target'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Analytics tab.
	 */
	private static function render_analytics_tab() {
		$kpis    = self::get_analytics_kpis();
		$stages  = self::get_pipeline_stages_for_analytics();
		$lead_count    = $kpis['total_leads'];
		$deal_count    = $kpis['total_deals'];
		$pipeline_val  = $kpis['pipeline_value'];
		$weighted_val  = $kpis['weighted_value'];
		$won_val       = $kpis['won_value'];
		$activities    = $kpis['recent_activities'];
		$max_stage     = max( array_column( $stages, 'count' ) ) ?: 1;
		?>
		<div class="crm-cc-kpi-grid" style="margin-bottom: 24px;">
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Total Leads', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $lead_count ) ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Active Deals', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $deal_count ) ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Pipeline Value', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( self::format_currency( $pipeline_val ) ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Weighted Pipeline', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value <?php echo $weighted_val > 0 ? 'win' : ''; ?>"><?php echo esc_html( self::format_currency( $weighted_val ) ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Closed Won', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value win"><?php echo esc_html( self::format_currency( $won_val ) ); ?></div>
			</div>
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Recent Activity (30d)', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value"><?php echo esc_html( number_format_i18n( $activities ) ); ?></div>
			</div>
		</div>

		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'Pipeline by Stage', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $stages ) || $max_stage < 1 ) : ?>
				<p><?php esc_html_e( 'No deals in pipeline yet. Create deals to see stage distribution.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<?php foreach ( $stages as $stage ) : ?>
					<?php
					$pct = $max_stage > 0 ? round( ( $stage['count'] / $max_stage ) * 100 ) : 0;
					$bar_color = 'closed_won' === $stage['stage'] ? '#00a32a' : ( 'closed_lost' === $stage['stage'] ? '#d63638' : '#2271b1' );
					?>
					<div class="crm-cc-pipeline-stage">
						<div class="crm-cc-pipeline-stage-name"><?php echo esc_html( ucwords( str_replace( '_', ' ', $stage['stage'] ) ) ); ?></div>
						<div class="crm-cc-pipeline-bar-wrap">
							<div class="crm-cc-pipeline-bar" style="width: <?php echo esc_attr( $pct ); ?>%; background: <?php echo esc_attr( $bar_color ); ?>;"></div>
						</div>
						<div class="crm-cc-pipeline-count">
							<?php echo esc_html( $stage['count'] ); ?>
							<span style="font-size:11px;color:#646970;margin-left:4px;"><?php echo esc_html( self::format_currency( $stage['value'] ) ); ?></span>
						</div>
					</div>
				<?php endforeach; ?>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Configuration tab.
	 */
	private static function render_configuration_tab() {
		?>
		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p><?php esc_html_e( 'Remote connections, filters, tags, priorities, automated schedules, compliance, and document templates for the CRM toolkit. Use the links below to manage each area.', 'mcp-ai-wpoos-pro' ); ?></p>
			<p>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-crm-toolkit-settings' ) ); ?>" class="button"><?php esc_html_e( 'CRM Settings', 'mcp-ai-wpoos-pro' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" class="button"><?php esc_html_e( 'Remote Connections', 'mcp-ai-wpoos-pro' ); ?></a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-schedule-manager' ) ); ?>" class="button"><?php esc_html_e( 'Schedules', 'mcp-ai-wpoos-pro' ); ?></a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Data helpers
	// =========================================================================

	/**
	 * Get count of posts for a given post type and status.
	 *
	 * @param string $post_type Post type.
	 * @param string $status    Post status.
	 * @return int
	 */
	private static function get_cpt_count( $post_type, $status = 'publish' ) {
		$counts = wp_count_posts( $post_type );
		return isset( $counts->$status ) ? (int) $counts->$status : 0;
	}

	/**
	 * Get count of posts filtered by meta value.
	 *
	 * @param string $post_type  Post type.
	 * @param string $status     Post status.
	 * @param string $meta_key   Meta key.
	 * @param string $meta_value Meta value.
	 * @return int
	 */
	private static function get_cpt_count_by_meta( $post_type, $status, $meta_key, $meta_value ) {
		$query = new WP_Query(
			array(
				'post_type'      => $post_type,
				'post_status'    => $status,
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'meta_key'       => $meta_key,
				'meta_value'     => $meta_value,
				'no_found_rows'  => false,
			)
		);
		$count = $query->found_posts;
		wp_reset_postdata();
		return $count;
	}

	/**
	 * Calculate total pipeline value from open deals.
	 *
	 * @return float
	 */
	private static function get_pipeline_value() {
		$deal_posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$total = 0;
		foreach ( $deal_posts as $deal_id ) {
			$stage = get_post_meta( $deal_id, '_deal_stage', true );
			if ( 'closed_won' === $stage || 'closed_lost' === $stage ) {
				continue;
			}
			$amount = (float) get_post_meta( $deal_id, '_deal_amount', true );
			$total += $amount;
		}

		return $total;
	}

	/**
	 * Get pipeline stages with deal counts and values.
	 *
	 * @return array
	 */
	private static function get_pipeline_stages() {
		$all_stages = array(
			'prospecting'     => __( 'Prospecting', 'mcp-ai-wpoos-pro' ),
			'qualification'   => __( 'Qualification', 'mcp-ai-wpoos-pro' ),
			'needs_analysis'  => __( 'Needs Analysis', 'mcp-ai-wpoos-pro' ),
			'proposal'        => __( 'Proposal', 'mcp-ai-wpoos-pro' ),
			'negotiation'     => __( 'Negotiation', 'mcp-ai-wpoos-pro' ),
			'closed_won'      => __( 'Closed Won', 'mcp-ai-wpoos-pro' ),
			'closed_lost'     => __( 'Closed Lost', 'mcp-ai-wpoos-pro' ),
		);

		$deal_posts = get_posts(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		// Group deals by stage.
		$stage_data = array();
		foreach ( $all_stages as $key => $label ) {
			$stage_data[ $key ] = array(
				'label' => $label,
				'count' => 0,
				'value' => 0,
				'pct'   => 0,
			);
		}

		$total_count = 0;
		foreach ( $deal_posts as $deal_id ) {
			$stage = get_post_meta( $deal_id, '_deal_stage', true );
			if ( ! $stage || ! isset( $stage_data[ $stage ] ) ) {
				$stage = 'prospecting';
			}
			$amount = (float) get_post_meta( $deal_id, '_deal_amount', true );
			++$stage_data[ $stage ]['count'];
			$stage_data[ $stage ]['value'] += $amount;
			++$total_count;
		}

		// Calculate percentages.
		$max_count = 1;
		foreach ( $stage_data as $s ) {
			if ( $s['count'] > $max_count ) {
				$max_count = $s['count'];
			}
		}
		foreach ( $stage_data as &$s ) {
			$s['pct'] = $max_count > 0 ? round( ( $s['count'] / $max_count ) * 100 ) : 0;
		}
		unset( $s );

		return array_values( $stage_data );
	}

	/**
	 * Get recent CRM activities.
	 *
	 * @param int $limit Maximum number of activities to return.
	 * @return array
	 */
	private static function get_recent_activities( $limit = 5 ) {
		$activities = get_posts(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$result = array();
		foreach ( $activities as $activity ) {
			$result[] = array(
				'date'        => get_the_date( 'Y-m-d H:i', $activity ),
				'type'        => get_post_meta( $activity->ID, '_activity_type', true ) ?: __( 'Activity', 'mcp-ai-wpoos-pro' ),
				'subject'     => get_the_title( $activity ),
				'description' => wp_trim_words( $activity->post_content, 15 ),
			);
		}

		return $result;
	}

	/**
	 * Get active sequences.
	 *
	 * @param int $limit Maximum number to return.
	 * @return array
	 */
	private static function get_active_sequences( $limit = 5 ) {
		$sequences = get_posts(
			array(
				'post_type'      => 'mcp_ai_sequence',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$result = array();
		foreach ( $sequences as $seq ) {
			$result[] = array(
				'id'     => $seq->ID,
				'title'  => get_the_title( $seq ),
				'status' => get_post_meta( $seq->ID, '_sequence_status', true ) ?: __( 'Draft', 'mcp-ai-wpoos-pro' ),
				'steps'  => get_post_meta( $seq->ID, '_sequence_steps', true ) ?: '0',
				'target' => get_post_meta( $seq->ID, '_sequence_target', true ) ?: '—',
			);
		}

		return $result;
	}

	/**
	 * Get all sequences (all statuses).
	 *
	 * @return array
	 */
	private static function get_all_sequences() {
		return self::get_active_sequences();
	}

	/**
	 * Get analytics KPIs for the Analytics tab.
	 *
	 * @return array
	 */
	private static function get_analytics_kpis() {
		$lead_count    = wp_count_posts( 'mcp_ai_lead' )->publish ?? 0;
		$deal_count    = wp_count_posts( 'mcp_ai_deal' )->publish ?? 0;

		$deals = get_posts(
			array(
				'post_type'      => 'mcp_ai_deal',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$pipeline_total = 0;
		$weighted_total = 0;
		$won_total      = 0;

		foreach ( $deals as $deal_id ) {
			$amount      = (float) get_post_meta( $deal_id, 'deal_amount', true );
			$probability = (float) get_post_meta( $deal_id, 'deal_probability', true );
			$stage       = get_post_meta( $deal_id, 'deal_stage', true );

			$pipeline_total += $amount;
			$weighted_total += $amount * max( 0, min( 1, $probability ) );

			if ( 'closed_won' === $stage ) {
				$won_total += $amount;
			}
		}

		$recent = get_posts(
			array(
				'post_type'      => 'mcp_ai_crm_activity',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'date_query'     => array(
					array( 'after' => '30 days ago' ),
				),
			)
		);

		return array(
			'total_leads'       => (int) $lead_count,
			'total_deals'       => (int) $deal_count,
			'pipeline_value'    => $pipeline_total,
			'weighted_value'    => $weighted_total,
			'won_value'         => $won_total,
			'recent_activities' => count( $recent ),
		);
	}

	/**
	 * Get pipeline stage breakdown for analytics charts.
	 *
	 * @return array
	 */
	private static function get_pipeline_stages_for_analytics() {
		$stage_names = array(
			'prospecting',
			'qualification',
			'needs_analysis',
			'value_proposition',
			'decision_makers',
			'perception_analysis',
			'proposal',
			'negotiation',
			'closed_won',
			'closed_lost',
		);

		$result = array();

		foreach ( $stage_names as $stage ) {
			$posts = get_posts(
				array(
					'post_type'      => 'mcp_ai_deal',
					'post_status'    => 'publish',
					'posts_per_page' => -1,
					'meta_key'       => 'deal_stage',
					'meta_value'     => $stage,
					'fields'         => 'ids',
				)
			);

			$total_value = 0;
			foreach ( $posts as $post_id ) {
				$total_value += (float) get_post_meta( $post_id, 'deal_amount', true );
			}

			$result[] = array(
				'stage' => $stage,
				'count' => count( $posts ),
				'value' => $total_value,
			);
		}

		return $result;
	}

	/**
	 * Format a number as currency.
	 *
	 * @param float $amount Amount to format.
	 * @return string
	 */
	private static function format_currency( $amount ) {
		if ( class_exists( 'WP_MCP_AI_CRM_Engine' ) && method_exists( 'WP_MCP_AI_CRM_Engine', 'format_currency' ) ) {
			return WP_MCP_AI_CRM_Engine::format_currency( $amount );
		}
		return '$' . number_format_i18n( $amount, 2 );
	}

	/**
	 * AJAX handler: get dashboard data.
	 */
	public static function ajax_get_dashboard() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		wp_send_json_success(
			array(
				'leads'          => self::get_cpt_count( 'mcp_ai_lead', 'publish' ),
				'deals'          => self::get_cpt_count( 'mcp_ai_deal', 'publish' ),
				'companies'      => self::get_cpt_count( 'mcp_ai_company', 'publish' ),
				'pipeline_value' => self::get_pipeline_value(),
				'won_deals'      => self::get_cpt_count_by_meta( 'mcp_ai_deal', 'publish', '_deal_stage', 'closed_won' ),
			)
		);
	}

	/**
	 * AJAX handler: get pipeline data.
	 */
	public static function ajax_get_pipeline() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		wp_send_json_success( self::get_pipeline_stages() );
	}
}
