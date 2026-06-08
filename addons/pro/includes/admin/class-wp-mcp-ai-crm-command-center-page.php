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
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only admin page detection for asset enqueue.
			$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';
			if ( ! $page || ! in_array( $page, array( self::PAGE_SLUG, WP_MCP_AI_CRM_Admin_Menu::PARENT_SLUG ), true ) ) {
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
				.crm-cc-muted { color: #646970; font-style: italic; }
				.crm-cc-related-link {
					color: #2271b1;
					text-decoration: none;
				}
				.crm-cc-related-link:hover {
					color: #135e96;
					text-decoration: underline;
				}
				.crm-cc-badge-score {
					display: inline-block;
					min-width: 28px;
					padding: 1px 6px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 700;
					text-align: center;
				}
				.crm-cc-badge-score.hot  { background: #d4edda; color: #155724; }
				.crm-cc-badge-score.warm { background: #fff3cd; color: #856404; }
				.crm-cc-badge-score.cold { background: #f8d7da; color: #721c24; }
				.crm-cc-badge-lifecycle {
					display: inline-block;
					padding: 1px 7px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
					text-transform: uppercase;
					background: #e7e8ea;
					color: #3c434a;
				}
				.crm-cc-badge-lifecycle.sql,
				.crm-cc-badge-lifecycle.opportunity { background: #d4edda; color: #155724; }
				.crm-cc-badge-lifecycle.customer { background: #cce5ff; color: #004085; }
				.crm-cc-source-link {
					color: #2271b1;
					text-decoration: none;
					white-space: nowrap;
				}
				.crm-cc-source-link:hover { color: #135e96; text-decoration: underline; }
				.crm-cc-source-link .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; }
				.crm-cc-ext-link {
					color: #2271b1;
					text-decoration: none;
				}
				.crm-cc-ext-link:hover { color: #135e96; text-decoration: underline; }
				.crm-cc-ext-link .dashicons { font-size: 14px; width: 14px; height: 14px; vertical-align: text-bottom; }
				.crm-cc-badge-status {
					display: inline-block;
					padding: 1px 7px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
				}
				.crm-cc-badge-status.client { background: #d4edda; color: #155724; }
				.crm-cc-badge-status.prospect { background: #e7e8ea; color: #3c434a; }
				.crm-cc-badge-status.target { background: #cce5ff; color: #004085; }
				.crm-cc-badge-status.in_discussion { background: #fff3cd; color: #856404; }
				.crm-cc-badge-status.not_interested { background: #f8d7da; color: #721c24; }
				.crm-cc-sortable a {
					color: #2271b1;
					text-decoration: none;
					white-space: nowrap;
				}
				.crm-cc-sortable a:hover { color: #135e96; }
				.crm-cc-completeness-bar-wrap {
					background: #f0f0f1;
					border-radius: 3px;
					height: 8px;
					margin: 8px 0 4px;
					overflow: hidden;
				}
				.crm-cc-completeness-bar {
					height: 100%;
					border-radius: 3px;
					transition: width 0.4s ease;
					min-width: 2px;
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
		$valid_tabs  = array( 'overview', 'leads', 'pipeline', 'activities', 'sequences', 'analytics', 'configuration' );
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
						'overview'      => __( 'Overview', 'mcp-ai-wpoos-pro' ),
						'leads'         => __( 'Leads', 'mcp-ai-wpoos-pro' ),
						'pipeline'      => __( 'Pipeline', 'mcp-ai-wpoos-pro' ),
						'activities'    => __( 'Activities', 'mcp-ai-wpoos-pro' ),
						'sequences'     => __( 'Sequences', 'mcp-ai-wpoos-pro' ),
						'analytics'     => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
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
					case 'leads':
						self::render_leads_tab();
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
			$recent_leads      = self::get_recent_leads_enriched( 10 );
			$recent_companies  = self::get_recent_companies_enriched( 10 );
			$completeness      = self::get_data_completeness();
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
			<div class="crm-cc-kpi">
				<div class="crm-cc-kpi-label"><?php esc_html_e( 'Data Completeness', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="crm-cc-kpi-value <?php echo esc_attr( $completeness['pct'] >= 80 ? 'win' : ( $completeness['pct'] >= 50 ? 'warn' : 'danger' ) ); ?>">
					<?php echo esc_html( $completeness['pct'] ); ?>%
				</div>
				<div class="crm-cc-completeness-bar-wrap">
					<div class="crm-cc-completeness-bar" style="width: <?php echo esc_attr( $completeness['pct'] ); ?>%; background: <?php echo esc_attr( $completeness['pct'] >= 80 ? '#00a32a' : ( $completeness['pct'] >= 50 ? '#dba617' : '#d63638' ) ); ?>;"></div>
				</div>
				<div class="crm-cc-kpi-sub">
					<?php
					printf(
						/* translators: 1: complete count, 2: total count */
						esc_html__( '%1$d / %2$d leads complete', 'mcp-ai-wpoos-pro' ),
						(int) $completeness['complete'],
						(int) $completeness['total']
					);
					?>
				</div>
			</div>
		</div>

		<div class="crm-cc-section">
			<h2><?php esc_html_e( 'Recent Leads', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $recent_leads ) ) : ?>
				<p><?php esc_html_e( 'No leads yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<table class="widefat striped" style="border: none;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Lead', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Score', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Stage', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Source', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $recent_leads as $lead ) : ?>
							<tr>
								<td>
									<a href="<?php echo esc_url( $lead['edit_url'] ); ?>">
										<strong><?php echo esc_html( $lead['title'] ); ?></strong>
									</a>
									<?php if ( ! empty( $lead['email'] ) ) : ?>
										<br><small><a href="<?php echo esc_url( 'mailto:' . $lead['email'] ); ?>" class="crm-cc-muted"><?php echo esc_html( $lead['email'] ); ?></a></small>
									<?php endif; ?>
									<?php if ( ! empty( $lead['phone'] ) ) : ?>
										<br><small class="crm-cc-muted"><?php echo esc_html( $lead['phone'] ); ?></small>
									<?php endif; ?>
								</td>
								<td><?php echo esc_html( ! empty( $lead['company_name'] ) ? $lead['company_name'] : '—' ); ?></td>
								<td>
									<span class="crm-cc-badge-score <?php echo esc_attr( $lead['score_tier'] ); ?>">
										<?php echo esc_html( $lead['lead_score'] ); ?>
									</span>
								</td>
								<td>
									<span class="crm-cc-badge-lifecycle <?php echo esc_attr( $lead['lifecycle_stage'] ); ?>">
										<?php echo esc_html( $lead['lifecycle_label'] ); ?>
									</span>
								</td>
								<td>
									<?php if ( ! empty( $lead['source_link']['url'] ) ) : ?>
										<a href="<?php echo esc_url( $lead['source_link']['url'] ); ?>"
											class="crm-cc-source-link"
											title="<?php echo esc_attr( $lead['source_link']['label'] ); ?>"
											<?php echo $lead['source_link']['is_external'] ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
											<?php echo wp_kses_post( $lead['source_link']['icon'] ); ?>
											<?php echo esc_html( $lead['source_link']['label'] ); ?>
										</a>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
				<p style="margin-top: 12px;">
					<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_lead' ) ); ?>">
						<?php esc_html_e( 'View all leads →', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			<?php endif; ?>
			</div>

			<div class="crm-cc-section">
				<h2><?php esc_html_e( 'Recent Companies', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $recent_companies ) ) : ?>
					<p><?php esc_html_e( 'No companies yet.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Company', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Industry', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Size', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Location', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Links', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_companies as $company ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( $company['edit_url'] ); ?>">
											<strong><?php echo esc_html( $company['title'] ); ?></strong>
										</a>
									</td>
									<td><?php echo esc_html( ! empty( $company['industry'] ) ? $company['industry'] : '—' ); ?></td>
									<td><?php echo esc_html( ! empty( $company['size_label'] ) ? $company['size_label'] : '—' ); ?></td>
									<td><?php echo esc_html( ! empty( $company['location'] ) ? $company['location'] : '—' ); ?></td>
									<td>
										<?php if ( ! empty( $company['target_status'] ) ) : ?>
											<span class="crm-cc-badge-status <?php echo esc_attr( $company['target_status'] ); ?>">
												<?php echo esc_html( $company['target_status_label'] ); ?>
											</span>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $company['website'] ) ) : ?>
											<a href="<?php echo esc_url( $company['website'] ); ?>"
												class="crm-cc-ext-link"
												target="_blank" rel="noopener noreferrer"
												title="<?php esc_attr_e( 'Open website', 'mcp-ai-wpoos-pro' ); ?>">
												<span class="dashicons dashicons-admin-links"></span>
											</a>
										<?php endif; ?>
										<?php if ( ! empty( $company['linkedin'] ) ) : ?>
											<a href="<?php echo esc_url( $company['linkedin'] ); ?>"
												class="crm-cc-ext-link"
												target="_blank" rel="noopener noreferrer"
												title="<?php esc_attr_e( 'Open LinkedIn profile', 'mcp-ai-wpoos-pro' ); ?>">
												<span class="dashicons dashicons-linkedin" style="color:#0A66C2;"></span>
											</a>
										<?php endif; ?>
										<?php if ( empty( $company['website'] ) && empty( $company['linkedin'] ) ) : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
					<p style="margin-top: 12px;">
						<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_company' ) ); ?>">
							<?php esc_html_e( 'View all companies →', 'mcp-ai-wpoos-pro' ); ?>
						</a>
					</p>
				<?php endif; ?>
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
								<th><?php esc_html_e( 'Related', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_activities as $activity ) : ?>
								<tr>
									<td><?php echo esc_html( $activity['date'] ); ?></td>
									<td><?php echo esc_html( $activity['type'] ); ?></td>
									<td>
										<?php if ( ! empty( $activity['related_url'] ) ) : ?>
											<a href="<?php echo esc_url( $activity['related_url'] ); ?>">
												<?php echo esc_html( $activity['subject'] ); ?>
											</a>
										<?php else : ?>
											<?php echo esc_html( $activity['subject'] ); ?>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( ! empty( $activity['related_label'] ) && ! empty( $activity['related_url'] ) ) : ?>
											<a href="<?php echo esc_url( $activity['related_url'] ); ?>" class="crm-cc-related-link">
												<?php echo esc_html( ucfirst( $activity['related_type'] ) ); ?>:
												<?php echo esc_html( $activity['related_label'] ); ?>
											</a>
										<?php elseif ( ! empty( $activity['related_type'] ) ) : ?>
											<span class="crm-cc-muted">
												<?php echo esc_html( ucfirst( $activity['related_type'] ) ); ?>
											</span>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
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
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=pipeline' ) ); ?>" class="button">
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
		 * Render the Leads tab with filtering, sorting, and pagination.
		 */
	private static function render_leads_tab() {
		// --- Read filter/sort/page from URL ---
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$lifecycle_filter = isset( $_GET['lead_lifecycle'] ) ? sanitize_key( $_GET['lead_lifecycle'] ) : '';
		$status_filter    = isset( $_GET['lead_status'] ) ? sanitize_key( $_GET['lead_status'] ) : '';
		$orderby          = isset( $_GET['orderby'] ) ? sanitize_key( $_GET['orderby'] ) : 'date';
		$order            = isset( $_GET['order'] ) && 'ASC' === strtoupper( sanitize_text_field( wp_unslash( $_GET['order'] ) ) ) ? 'ASC' : 'DESC';
		$paged            = isset( $_GET['paged'] ) ? max( 1, absint( $_GET['paged'] ) ) : 1;
		// phpcs:enable

		$per_page = 20;

		// --- Build query ---
		$args = array(
			'post_type'      => 'mcp_ai_lead',
			'post_status'    => 'publish',
			'posts_per_page' => $per_page,
			'paged'          => $paged,
			'orderby'        => 'date',
			'order'          => $order,
		);

		// Meta query for filters.
		$meta_queries = array();
		if ( $lifecycle_filter ) {
			$meta_queries[] = array(
				'key'   => 'lifecycle_stage',
				'value' => $lifecycle_filter,
			);
		}
		if ( $status_filter ) {
			$meta_queries[] = array(
				'key'   => 'lead_status',
				'value' => $status_filter,
			);
		}
		if ( ! empty( $meta_queries ) ) {
			$args['meta_query'] = $meta_queries; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query
		}

		// Sorting.
		$allowed_orderby = array( 'date', 'title', 'lead_score', 'lifecycle_stage' );
		if ( in_array( $orderby, $allowed_orderby, true ) ) {
			if ( 'lead_score' === $orderby ) {
				$args['meta_key'] = 'lead_score'; // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key
				$args['orderby']  = 'meta_value_num';
			} else {
				$args['orderby'] = $orderby;
			}
		}

		$query = new WP_Query( $args );
		$leads = $query->posts;

		$total_pages = $query->max_num_pages;
		$total_items = (int) $query->found_posts;

		// --- Lookups ---
		$lifecycle_labels = array(
			'lead'        => __( 'Lead', 'mcp-ai-wpoos-pro' ),
			'mql'         => __( 'MQL', 'mcp-ai-wpoos-pro' ),
			'sal'         => __( 'SAL', 'mcp-ai-wpoos-pro' ),
			'sql'         => __( 'SQL', 'mcp-ai-wpoos-pro' ),
			'opportunity' => __( 'Opp', 'mcp-ai-wpoos-pro' ),
			'customer'    => __( 'Customer', 'mcp-ai-wpoos-pro' ),
		);

		$status_options = array(
			''             => __( 'All Statuses', 'mcp-ai-wpoos-pro' ),
			'new'          => __( 'New', 'mcp-ai-wpoos-pro' ),
			'contacted'    => __( 'Contacted', 'mcp-ai-wpoos-pro' ),
			'engaged'      => __( 'Engaged', 'mcp-ai-wpoos-pro' ),
			'qualified'    => __( 'Qualified', 'mcp-ai-wpoos-pro' ),
			'unqualified'  => __( 'Unqualified', 'mcp-ai-wpoos-pro' ),
			'disqualified' => __( 'Disqualified', 'mcp-ai-wpoos-pro' ),
			'converted'    => __( 'Converted', 'mcp-ai-wpoos-pro' ),
		);

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=leads' );
		?>
			<div class="crm-cc-section">
				<h2><?php esc_html_e( 'All Leads', 'mcp-ai-wpoos-pro' ); ?></h2>

			<?php if ( $total_items > 0 ) : ?>
					<p class="crm-cc-muted" style="margin-bottom: 12px;">
						<?php
						printf(
							/* translators: %d: total number of matching leads */
							esc_html( _n( '%d lead found', '%d leads found', $total_items, 'mcp-ai-wpoos-pro' ) ),
							(int) $total_items
						);
						?>
					</p>
				<?php endif; ?>

				<!-- Filters -->
				<form method="get" style="margin-bottom: 16px; display: flex; gap: 8px; align-items: center; flex-wrap: wrap;">
					<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>">
					<input type="hidden" name="tab" value="leads">

					<select name="lead_lifecycle">
						<option value=""><?php esc_html_e( 'All Lifecycle Stages', 'mcp-ai-wpoos-pro' ); ?></option>
						<?php foreach ( $lifecycle_labels as $val => $lbl ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $lifecycle_filter, $val ); ?>>
								<?php echo esc_html( $lbl ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<select name="lead_status">
						<?php foreach ( $status_options as $val => $lbl ) : ?>
							<option value="<?php echo esc_attr( $val ); ?>" <?php selected( $status_filter, $val ); ?>>
								<?php echo esc_html( $lbl ); ?>
							</option>
						<?php endforeach; ?>
					</select>

					<?php submit_button( __( 'Filter', 'mcp-ai-wpoos-pro' ), 'secondary', 'filter_action', false ); ?>

					<a href="<?php echo esc_url( $base_url ); ?>" class="button" style="margin-left: 4px;">
						<?php esc_html_e( 'Reset', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</form>

				<?php if ( empty( $leads ) ) : ?>
					<p><?php esc_html_e( 'No leads match your filters.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<table class="widefat striped">
						<thead>
							<tr>
								<?php
								$sort_cols = array(
									array(
										'key'   => 'date',
										'label' => __( 'Date', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => 'title',
										'label' => __( 'Name', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => '',
										'label' => __( 'Email / Phone', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => '',
										'label' => __( 'Company', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => 'lead_score',
										'label' => __( 'Score', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => 'lifecycle_stage',
										'label' => __( 'Stage', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => '',
										'label' => __( 'Status', 'mcp-ai-wpoos-pro' ),
									),
									array(
										'key'   => '',
										'label' => __( 'Source', 'mcp-ai-wpoos-pro' ),
									),
								);
								foreach ( $sort_cols as $col_def ) :
									$col_key = $col_def['key'];
									$col_lbl = $col_def['label'];
									if ( $col_key ) :
										$sort_url = add_query_arg(
											array(
												'orderby' => $col_key,
												'order'   => ( $orderby === $col_key && 'ASC' === $order ) ? 'DESC' : 'ASC',
											),
											$base_url
										);
										$arrow    = '';
										if ( $orderby === $col_key ) {
											$arrow = 'ASC' === $order ? ' ↑' : ' ↓';
										}
										?>
										<th class="crm-cc-sortable">
											<a href="<?php echo esc_url( $sort_url ); ?>">
												<?php echo esc_html( $col_lbl . $arrow ); ?>
											</a>
										</th>
										<?php
									else :
										?>
										<th><?php echo esc_html( $col_lbl ); ?></th>
										<?php
									endif;
								endforeach;
								?>
							</tr>
						</thead>
						<tbody>
							<?php
							foreach ( $leads as $lead ) :
								$email        = get_post_meta( $lead->ID, 'email', true );
								$phone        = get_post_meta( $lead->ID, 'phone', true );
								$company_name = get_post_meta( $lead->ID, 'company', true );
								if ( ! $company_name ) {
									$company_name = get_post_meta( $lead->ID, 'company_name', true );
								}
																	$score     = (int) get_post_meta( $lead->ID, 'lead_score', true );
																	$lifecycle = get_post_meta( $lead->ID, 'lifecycle_stage', true );
								if ( ! $lifecycle ) {
									$lifecycle = 'lead';
								}
																	$status = get_post_meta( $lead->ID, 'lead_status', true );
								if ( ! $status ) {
									$status = 'new';
								}
								$source        = get_post_meta( $lead->ID, 'source', true );
								$connection_id = get_post_meta( $lead->ID, '_source_connection_id', true );

								$score_tier      = $score >= 70 ? 'hot' : ( $score >= 30 ? 'warm' : 'cold' );
								$lifecycle_label = isset( $lifecycle_labels[ $lifecycle ] ) ? $lifecycle_labels[ $lifecycle ] : ucfirst( $lifecycle );
								$source_link     = self::resolve_source_link( $source, $connection_id );
								$status_label    = isset( $status_options[ $status ] ) ? $status_options[ $status ] : ucfirst( $status );
								?>
								<tr>
									<td><?php echo esc_html( get_the_date( 'Y-m-d', $lead ) ); ?></td>
									<td>
										<a href="<?php echo esc_url( get_edit_post_link( $lead->ID, 'raw' ) ); ?>">
											<strong><?php echo esc_html( get_the_title( $lead ) ); ?></strong>
										</a>
									</td>
									<td>
										<?php if ( $email ) : ?>
											<a href="<?php echo esc_url( 'mailto:' . $email ); ?>"><?php echo esc_html( $email ); ?></a>
										<?php endif; ?>
										<?php if ( $phone ) : ?>
											<br><small class="crm-cc-muted"><?php echo esc_html( $phone ); ?></small>
										<?php endif; ?>
										<?php if ( ! $email && ! $phone ) : ?>
											—
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( ! empty( $company_name ) ? $company_name : '—' ); ?></td>
									<td>
										<span class="crm-cc-badge-score <?php echo esc_attr( $score_tier ); ?>">
											<?php echo esc_html( $score ); ?>
										</span>
									</td>
									<td>
										<span class="crm-cc-badge-lifecycle <?php echo esc_attr( $lifecycle ); ?>">
											<?php echo esc_html( $lifecycle_label ); ?>
										</span>
									</td>
									<td><?php echo esc_html( $status_label ); ?></td>
									<td>
										<?php if ( ! empty( $source_link['url'] ) ) : ?>
											<a href="<?php echo esc_url( $source_link['url'] ); ?>"
												class="crm-cc-source-link"
												<?php echo $source_link['is_external'] ? 'target="_blank" rel="noopener noreferrer"' : ''; ?>>
												<?php echo wp_kses_post( $source_link['icon'] ); ?>
												<?php echo esc_html( $source_link['label'] ); ?>
											</a>
										<?php elseif ( $source ) : ?>
											<span class="crm-cc-muted"><?php echo esc_html( ucfirst( $source ) ); ?></span>
										<?php else : ?>
											—
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>

					<?php if ( $total_pages > 1 ) : ?>
						<div class="tablenav" style="margin-top: 12px;">
							<div class="tablenav-pages">
								<span class="displaying-num">
									<?php
									printf(
										/* translators: %s: total items count */
										esc_html__( '%s items', 'mcp-ai-wpoos-pro' ),
										esc_html( number_format_i18n( $total_items ) )
									);
									?>
								</span>
								<?php
								$page_links = paginate_links(
									array(
										'base'      => add_query_arg( 'paged', '%#%', $base_url ),
										'format'    => '',
										'prev_text' => '&laquo;',
										'next_text' => '&raquo;',
										'total'     => $total_pages,
										'current'   => $paged,
									)
								);
								if ( $page_links ) {
									echo '<span class="pagination-links">' . wp_kses_post( $page_links ) . '</span>';
								}
								?>
							</div>
						</div>
					<?php endif; ?>
				<?php endif; ?>
			</div>

			<p>
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_lead' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Add New Lead', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
			<?php
			wp_reset_postdata();
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
							<th><?php esc_html_e( 'Related', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Description', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $activities as $activity ) : ?>
							<tr>
								<td><?php echo esc_html( $activity['date'] ); ?></td>
								<td><?php echo esc_html( $activity['type'] ); ?></td>
								<td>
									<?php if ( ! empty( $activity['related_url'] ) ) : ?>
										<a href="<?php echo esc_url( $activity['related_url'] ); ?>">
											<?php echo esc_html( $activity['subject'] ); ?>
										</a>
									<?php else : ?>
										<?php echo esc_html( $activity['subject'] ); ?>
									<?php endif; ?>
								</td>
								<td>
									<?php if ( ! empty( $activity['related_label'] ) && ! empty( $activity['related_url'] ) ) : ?>
										<a href="<?php echo esc_url( $activity['related_url'] ); ?>" class="crm-cc-related-link">
											<?php echo esc_html( ucfirst( $activity['related_type'] ) ); ?>:
											<?php echo esc_html( $activity['related_label'] ); ?>
										</a>
									<?php elseif ( ! empty( $activity['related_type'] ) ) : ?>
										<span class="crm-cc-muted">
											<?php echo esc_html( ucfirst( $activity['related_type'] ) ); ?>
										</span>
									<?php else : ?>
										—
									<?php endif; ?>
								</td>
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
		$kpis         = self::get_analytics_kpis();
		$stages       = self::get_pipeline_stages_for_analytics();
		$lead_count   = $kpis['total_leads'];
		$deal_count   = $kpis['total_deals'];
		$pipeline_val = $kpis['pipeline_value'];
		$weighted_val = $kpis['weighted_value'];
		$won_val      = $kpis['won_value'];
		$activities   = $kpis['recent_activities'];
		$max_stage    = max( array_column( $stages, 'count' ) );
		$max_stage    = $max_stage > 0 ? $max_stage : 1;
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
					$pct       = $max_stage > 0 ? round( ( $stage['count'] / $max_stage ) * 100 ) : 0;
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
				'meta_key'       => $meta_key, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional single-key count lookup.
				'meta_value'     => $meta_value, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional single-key count lookup.
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
			'prospecting'    => __( 'Prospecting', 'mcp-ai-wpoos-pro' ),
			'qualification'  => __( 'Qualification', 'mcp-ai-wpoos-pro' ),
			'needs_analysis' => __( 'Needs Analysis', 'mcp-ai-wpoos-pro' ),
			'proposal'       => __( 'Proposal', 'mcp-ai-wpoos-pro' ),
			'negotiation'    => __( 'Negotiation', 'mcp-ai-wpoos-pro' ),
			'closed_won'     => __( 'Closed Won', 'mcp-ai-wpoos-pro' ),
			'closed_lost'    => __( 'Closed Lost', 'mcp-ai-wpoos-pro' ),
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
			$activity_type = get_post_meta( $activity->ID, 'activity_type', true );
			if ( ! $activity_type ) {
				$activity_type = get_post_meta( $activity->ID, '_activity_type', true );
			}

			$related_type = get_post_meta( $activity->ID, 'related_type', true );
			$related_id   = (int) get_post_meta( $activity->ID, 'related_id', true );

			$related_label = '';
			$related_url   = '';
			if ( $related_id && in_array( $related_type, array( 'lead', 'deal', 'contact', 'company' ), true ) ) {
				$related_post = get_post( $related_id );
				if ( $related_post ) {
					$related_label = get_the_title( $related_post );
					$related_url   = get_edit_post_link( $related_id, 'raw' );
				}
			}

			$result[] = array(
				'id'            => $activity->ID,
				'date'          => get_the_date( 'Y-m-d H:i', $activity ),
				'type'          => $activity_type ? $activity_type : __( 'Activity', 'mcp-ai-wpoos-pro' ),
				'subject'       => get_the_title( $activity ),
				'description'   => wp_trim_words( $activity->post_content, 15 ),
				'related_type'  => $related_type,
				'related_id'    => $related_id,
				'related_label' => $related_label,
				'related_url'   => $related_url,
			);
		}

		return $result;
	}

	/**
	 * Get recent leads with enriched data for the smart table.
	 *
	 * @param int $limit Maximum number of leads to return.
	 * @return array
	 */
	private static function get_recent_leads_enriched( $limit = 10 ) {
		$leads = get_posts(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$lifecycle_labels = array(
			'lead'        => __( 'Lead', 'mcp-ai-wpoos-pro' ),
			'mql'         => __( 'MQL', 'mcp-ai-wpoos-pro' ),
			'sal'         => __( 'SAL', 'mcp-ai-wpoos-pro' ),
			'sql'         => __( 'SQL', 'mcp-ai-wpoos-pro' ),
			'opportunity' => __( 'Opp', 'mcp-ai-wpoos-pro' ),
			'customer'    => __( 'Customer', 'mcp-ai-wpoos-pro' ),
		);

		$result = array();
		foreach ( $leads as $lead ) {
			$score     = (int) get_post_meta( $lead->ID, 'lead_score', true );
			$lifecycle = get_post_meta( $lead->ID, 'lifecycle_stage', true );
			if ( ! $lifecycle ) {
				$lifecycle = 'lead';
			}
			$email        = get_post_meta( $lead->ID, 'email', true );
			$phone        = get_post_meta( $lead->ID, 'phone', true );
			$company_name = get_post_meta( $lead->ID, 'company', true );
			if ( ! $company_name ) {
				$company_name = get_post_meta( $lead->ID, 'company_name', true );
			}
			$source        = get_post_meta( $lead->ID, 'source', true );
			$connection_id = get_post_meta( $lead->ID, '_source_connection_id', true );

			// Score tier for color badge.
			if ( $score >= 70 ) {
				$score_tier = 'hot';
			} elseif ( $score >= 30 ) {
				$score_tier = 'warm';
			} else {
				$score_tier = 'cold';
			}

			$lifecycle_label = isset( $lifecycle_labels[ $lifecycle ] )
				? $lifecycle_labels[ $lifecycle ]
				: ucfirst( $lifecycle );

			$source_link = self::resolve_source_link( $source, $connection_id );

			$result[] = array(
				'id'              => $lead->ID,
				'title'           => get_the_title( $lead ),
				'email'           => $email,
				'phone'           => $phone,
				'company_name'    => $company_name,
				'lead_score'      => $score,
				'score_tier'      => $score_tier,
				'lifecycle_stage' => $lifecycle,
				'lifecycle_label' => $lifecycle_label,
				'source'          => $source,
				'source_link'     => $source_link,
				'edit_url'        => get_edit_post_link( $lead->ID, 'raw' ),
			);
		}

		return $result;
	}

	/**
	 * Resolve a source/connection link for a lead.
	 *
	 * @param string $source        Raw source meta value.
	 * @param string $connection_id Remote Site Manager connection ID.
	 * @return array{url: string, label: string, icon: string, is_external: bool}
	 */
	private static function resolve_source_link( $source, $connection_id ) {
		$result = array(
			'url'         => '',
			'label'       => '',
			'icon'        => '',
			'is_external' => false,
		);

		// If we have a remote connection, link to its settings page.
		if ( $connection_id && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );
			if ( $connection ) {
				$connection_type = isset( $connection['connection_type'] ) ? $connection['connection_type'] : '';
				$connection_name = isset( $connection['name'] ) ? $connection['name'] : $connection_id;

				$result['url']   = admin_url( 'admin.php?page=wp-mcp-ai-remote-sites&edit=' . rawurlencode( $connection_id ) );
				$result['label'] = $connection_name;
				$result['icon']  = self::get_source_icon( $connection_type );
				return $result;
			}
		}

		// Fall back to source string for display.
		if ( $source ) {
			$result['label'] = ucfirst( $source );
			$result['icon']  = self::get_source_icon( $source );

			// If source looks like a URL, make it an external link.
			if ( filter_var( $source, FILTER_VALIDATE_URL ) ) {
				$result['url']         = $source;
				$result['is_external'] = true;
			}
		}

		return $result;
	}

	/**
	 * Get an icon span for a source/channel type.
	 *
	 * @param string $type Source or connection type slug.
	 * @return string HTML span with dashicon.
	 */
	private static function get_source_icon( $type ) {
		$type  = strtolower( $type );
		$icons = array(
			'whatsapp'        => '<span class="dashicons dashicons-whatsapp" style="color:#25D366;"></span>',
			'whatsapp_cloud'  => '<span class="dashicons dashicons-whatsapp" style="color:#25D366;"></span>',
			'telegram'        => '<span class="dashicons dashicons-email-alt" style="color:#0088cc;"></span>',
			'slack'           => '<span class="dashicons dashicons-groups" style="color:#4A154B;"></span>',
			'discord'         => '<span class="dashicons dashicons-microphone" style="color:#5865F2;"></span>',
			'microsoft_teams' => '<span class="dashicons dashicons-video-alt3" style="color:#6264A7;"></span>',
			'google_chat'     => '<span class="dashicons dashicons-google" style="color:#4285F4;"></span>',
			'messenger'       => '<span class="dashicons dashicons-format-chat" style="color:#00B2FF;"></span>',
			'email'           => '<span class="dashicons dashicons-email"></span>',
			'gmail'           => '<span class="dashicons dashicons-email" style="color:#EA4335;"></span>',
			'web_form'        => '<span class="dashicons dashicons-admin-site"></span>',
			'wordpress'       => '<span class="dashicons dashicons-wordpress"></span>',
			'sms'             => '<span class="dashicons dashicons-smartphone"></span>',
			'chat_channel'    => '<span class="dashicons dashicons-format-chat"></span>',
			'website'         => '<span class="dashicons dashicons-admin-links"></span>',
			'referral'        => '<span class="dashicons dashicons-networking"></span>',
			'event'           => '<span class="dashicons dashicons-calendar"></span>',
			'cold_outreach'   => '<span class="dashicons dashicons-email-alt"></span>',
		);

		if ( isset( $icons[ $type ] ) ) {
			return $icons[ $type ];
		}

		return '<span class="dashicons dashicons-networking"></span>';
	}

	/**
	 * Get recent companies with enriched data.
	 *
	 * @param int $limit Maximum number of companies to return.
	 * @return array
	 */
	private static function get_recent_companies_enriched( $limit = 10 ) {
		$companies = get_posts(
			array(
				'post_type'      => 'mcp_ai_company',
				'post_status'    => 'publish',
				'posts_per_page' => $limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$size_labels = array(
			'1-10'      => '1-10',
			'11-50'     => '11-50',
			'51-200'    => '51-200',
			'201-500'   => '201-500',
			'501-1000'  => '501-1K',
			'1001-5000' => '1K-5K',
			'5001+'     => '5K+',
		);

		$status_labels = array(
			'prospect'       => __( 'Prospect', 'mcp-ai-wpoos-pro' ),
			'target'         => __( 'Target', 'mcp-ai-wpoos-pro' ),
			'in_discussion'  => __( 'In Discussion', 'mcp-ai-wpoos-pro' ),
			'client'         => __( 'Client', 'mcp-ai-wpoos-pro' ),
			'not_interested' => __( 'Not Interested', 'mcp-ai-wpoos-pro' ),
		);

		$result = array();
		foreach ( $companies as $company ) {
			$industry = get_post_meta( $company->ID, '_company_industry', true );
			$size     = get_post_meta( $company->ID, '_company_size', true );
			$city     = get_post_meta( $company->ID, '_company_city', true );
			$state    = get_post_meta( $company->ID, '_company_state', true );
			$country  = get_post_meta( $company->ID, '_company_country', true );
			$website  = get_post_meta( $company->ID, '_company_website', true );
			$linkedin = get_post_meta( $company->ID, '_company_linkedin', true );
			$target   = get_post_meta( $company->ID, '_company_target_status', true );

			// Build location string.
			$location_parts = array_filter( array( $city, $state, $country ) );
			$location       = ! empty( $location_parts ) ? implode( ', ', $location_parts ) : '';

			// Human-readable size.
			$size_label = isset( $size_labels[ $size ] ) ? $size_labels[ $size ] : $size;

			// Target status label.
			$target_label = isset( $status_labels[ $target ] ) ? $status_labels[ $target ] : '';

			// Normalise website URL (prepend https:// if missing).
			if ( $website && ! preg_match( '#^https?://#', $website ) ) {
				$website = 'https://' . $website;
			}

			$result[] = array(
				'id'                  => $company->ID,
				'title'               => get_the_title( $company ),
				'industry'            => $industry,
				'size'                => $size,
				'size_label'          => $size_label,
				'city'                => $city,
				'state'               => $state,
				'country'             => $country,
				'location'            => $location,
				'website'             => $website,
				'linkedin'            => $linkedin,
				'target_status'       => $target,
				'target_status_label' => $target_label,
				'edit_url'            => get_edit_post_link( $company->ID, 'raw' ),
			);
		}

		return $result;
	}

	/**
	 * Calculate lead data completeness.
	 *
	 * A lead is "complete" when it has all three core fields:
	 * email, phone, and company.
	 *
	 * @return array{pct: int, complete: int, total: int}
	 */
	private static function get_data_completeness() {
		$leads = get_posts(
			array(
				'post_type'      => 'mcp_ai_lead',
				'post_status'    => 'publish',
				'posts_per_page' => -1,
				'fields'         => 'ids',
			)
		);

		$total    = count( $leads );
		$complete = 0;

		if ( $total > 0 ) {
			foreach ( $leads as $lead_id ) {
				$email   = get_post_meta( $lead_id, 'email', true );
				$phone   = get_post_meta( $lead_id, 'phone', true );
				$company = get_post_meta( $lead_id, 'company', true );
				if ( ! $company ) {
					$company = get_post_meta( $lead_id, 'company_name', true );
				}

				if ( $email && $phone && $company ) {
					++$complete;
				}
			}
		}

		$pct = $total > 0 ? (int) round( ( $complete / $total ) * 100 ) : 100;

		return array(
			'pct'      => $pct,
			'complete' => $complete,
			'total'    => $total,
		);
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
			$seq_status = get_post_meta( $seq->ID, '_sequence_status', true );
			$seq_steps  = get_post_meta( $seq->ID, '_sequence_steps', true );
			$seq_target = get_post_meta( $seq->ID, '_sequence_target', true );
			$result[]   = array(
				'id'     => $seq->ID,
				'title'  => get_the_title( $seq ),
				'status' => $seq_status ? $seq_status : __( 'Draft', 'mcp-ai-wpoos-pro' ),
				'steps'  => $seq_steps ? $seq_steps : '0',
				'target' => $seq_target ? $seq_target : '—',
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
		$lead_count = wp_count_posts( 'mcp_ai_lead' )->publish ?? 0;
		$deal_count = wp_count_posts( 'mcp_ai_deal' )->publish ?? 0;

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
					'meta_key'       => 'deal_stage', // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_key -- Intentional stage lookup for pipeline totals.
					'meta_value'     => $stage, // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_value -- Intentional stage lookup for pipeline totals.
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
