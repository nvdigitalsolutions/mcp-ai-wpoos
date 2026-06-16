<?php
/**
 * Media Command Center Admin Page
 *
 * Unified Media dashboard providing asset library overview, template and
 * collection management, processing queue monitoring, storage analytics,
 * health & compliance auditing, and configuration — all under the
 * "NV Media" top-level admin section.
 *
 * Mirrors the CRM Command Center and PM Command Center patterns but is
 * Media-specific.
 *
 * @package WP_MCP_AI_Pro
 * @since 3.9.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Media Command Center Page Class
 */
class WP_MCP_AI_Media_Command_Center_Page {

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'nvoos-media-command-center';

	/**
	 * AJAX nonce action.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_media_cc';

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
		add_action( 'wp_ajax_wp_mcp_ai_media_cc_health_snapshot', array( __CLASS__, 'ajax_health_snapshot' ) );
		add_action( 'wp_ajax_wp_mcp_ai_media_cc_compression_sweep', array( __CLASS__, 'ajax_compression_sweep' ) );
		add_action( 'wp_ajax_wp_mcp_ai_media_cc_alt_text_audit', array( __CLASS__, 'ajax_alt_text_audit' ) );
	}

	/**
	 * Register the submenu page under NV Media.
	 */
	public static function register_page() {
		self::$page_hook = add_submenu_page(
			WP_MCP_AI_Media_Admin_Menu::PARENT_SLUG,
			__( 'Media Command Center', 'mcp-ai-wpoos-pro' ),
			__( 'Command Center', 'mcp-ai-wpoos-pro' ),
			'upload_files',
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
			if ( ! $page || ! in_array( $page, array( self::PAGE_SLUG, WP_MCP_AI_Media_Admin_Menu::PARENT_SLUG ), true ) ) {
				return;
			}
		}

		// Media command center styles (inline, mirrors CRM/PM pattern).
		add_action(
			'admin_head',
			function () {
				?>
				<style>
				.media-cc-wrap { margin: 0 0 0 -20px; }
				.media-cc-header {
					background: #fff;
					border-bottom: 1px solid #c3c4c7;
					padding: 16px 24px;
					display: flex;
					align-items: center;
					justify-content: space-between;
				}
				.media-cc-header h1 {
					margin: 0;
					font-size: 20px;
					display: flex;
					align-items: center;
					gap: 8px;
				}
				.media-cc-header .dashicons { font-size: 28px; width: 28px; height: 28px; color: #2271b1; }
				.media-cc-badge {
					background: #2271b1;
					color: #fff;
					font-size: 10px;
					padding: 2px 6px;
					border-radius: 3px;
					text-transform: uppercase;
					font-weight: 600;
				}
				.media-cc-subtitle { color: #646970; margin: 4px 0 0; font-size: 13px; }
				.media-cc-nav {
					background: #fff;
					border-bottom: 1px solid #c3c4c7;
					padding: 0 24px;
				}
				.media-cc-nav .nav-tab-wrapper { border-bottom: none; margin-bottom: 0; padding-top: 8px; }
				.media-cc-content { padding: 24px; }
				.media-cc-kpi-grid {
					display: grid;
					grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
					gap: 16px;
					margin-bottom: 24px;
				}
				.media-cc-kpi {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 16px;
				}
				.media-cc-kpi-label {
					font-size: 12px;
					color: #646970;
					text-transform: uppercase;
					font-weight: 600;
					margin-bottom: 8px;
				}
				.media-cc-kpi-value {
					font-size: 28px;
					font-weight: 700;
					line-height: 1.2;
				}
				.media-cc-kpi-sub {
					font-size: 12px;
					color: #646970;
					margin-top: 4px;
				}
				.media-cc-kpi-value.good { color: #00a32a; }
				.media-cc-kpi-value.warn { color: #dba617; }
				.media-cc-kpi-value.danger { color: #d63638; }
				.media-cc-section {
					background: #fff;
					border: 1px solid #c3c4c7;
					border-radius: 4px;
					padding: 20px;
					margin-bottom: 24px;
				}
				.media-cc-section h2 {
					margin: 0 0 16px;
					font-size: 16px;
				}
				.media-cc-inline-cards {
					display: grid;
					grid-template-columns: 1fr 1fr;
					gap: 16px;
				}
				.media-cc-muted { color: #646970; font-style: italic; }
				.media-cc-health-bar-wrap {
					background: #f0f0f1;
					border-radius: 3px;
					height: 8px;
					margin: 8px 0 4px;
					overflow: hidden;
				}
				.media-cc-health-bar {
					height: 100%;
					border-radius: 3px;
					transition: width 0.4s ease;
					min-width: 2px;
				}
				.media-cc-badge-status {
					display: inline-block;
					padding: 1px 7px;
					border-radius: 3px;
					font-size: 11px;
					font-weight: 600;
				}
				.media-cc-badge-status.good { background: #d4edda; color: #155724; }
				.media-cc-badge-status.warn { background: #fff3cd; color: #856404; }
				.media-cc-badge-status.bad { background: #f8d7da; color: #721c24; }
				.media-cc-badge-status.info { background: #cce5ff; color: #004085; }
				.media-cc-badge-status.neutral { background: #e7e8ea; color: #3c434a; }
				.media-cc-pipeline-stage {
					display: flex;
					align-items: center;
					margin-bottom: 12px;
				}
				.media-cc-pipeline-stage-name {
					width: 140px;
					font-weight: 600;
					font-size: 13px;
				}
				.media-cc-pipeline-bar-wrap {
					flex: 1;
					background: #f0f0f1;
					border-radius: 3px;
					height: 20px;
					margin: 0 12px;
					overflow: hidden;
				}
				.media-cc-pipeline-bar {
					background: #2271b1;
					height: 100%;
					border-radius: 3px;
					min-width: 2px;
					transition: width 0.3s ease;
				}
				.media-cc-pipeline-count {
					font-weight: 600;
					font-size: 13px;
					min-width: 60px;
					text-align: right;
				}
				.media-cc-table-wrap { overflow-x: auto; }
				@media (max-width: 768px) {
					.media-cc-inline-cards { grid-template-columns: 1fr; }
					.media-cc-kpi-grid { grid-template-columns: repeat(2, 1fr); }
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
		if ( ! current_user_can( 'upload_files' ) ) {
			wp_die( esc_html__( 'You do not have permission to access this page.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$current_tab = isset( $_GET['tab'] ) ? sanitize_key( $_GET['tab'] ) : 'overview';
		$valid_tabs  = array(
			'overview',
			'templates',
			'collections',
			'processing',
			'analytics',
			'blueprints',
			'schedules',
			'health',
			'configuration',
		);
		if ( ! in_array( $current_tab, $valid_tabs, true ) ) {
			$current_tab = 'overview';
		}

		$base_url = admin_url( 'admin.php?page=' . self::PAGE_SLUG );
		?>
		<div class="wrap media-cc-wrap">
			<div class="media-cc-header">
				<div>
					<h1>
						<span class="dashicons dashicons-admin-media"></span>
						<?php esc_html_e( 'Media Command Center', 'mcp-ai-wpoos-pro' ); ?>
						<span class="media-cc-badge"><?php esc_html_e( 'PRO', 'mcp-ai-wpoos-pro' ); ?></span>
					</h1>
					<p class="media-cc-subtitle">
						<?php esc_html_e( 'Monitor your media library, manage templates and collections, track processing jobs, review analytics, and audit health & compliance.', 'mcp-ai-wpoos-pro' ); ?>
					</p>
				</div>
			</div>

			<div class="media-cc-nav">
				<nav class="nav-tab-wrapper">
					<?php
					$tabs = array(
						'overview'      => __( 'Overview', 'mcp-ai-wpoos-pro' ),
						'templates'     => __( 'Templates', 'mcp-ai-wpoos-pro' ),
						'collections'   => __( 'Collections', 'mcp-ai-wpoos-pro' ),
						'processing'    => __( 'Processing', 'mcp-ai-wpoos-pro' ),
						'analytics'     => __( 'Analytics', 'mcp-ai-wpoos-pro' ),
						'blueprints'    => __( 'Blueprints', 'mcp-ai-wpoos-pro' ),
						'schedules'     => __( 'Schedules', 'mcp-ai-wpoos-pro' ),
						'health'        => __( 'Health', 'mcp-ai-wpoos-pro' ),
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

			<div class="media-cc-content">
				<?php
				switch ( $current_tab ) {
					case 'templates':
						self::render_templates_tab();
						break;
					case 'collections':
						self::render_collections_tab();
						break;
					case 'processing':
						self::render_processing_tab();
						break;
					case 'analytics':
						self::render_analytics_tab();
						break;
					case 'blueprints':
						self::render_blueprints_tab();
						break;
					case 'schedules':
						self::render_schedules_tab();
						break;
					case 'health':
						self::render_health_tab();
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

	// =========================================================================
	// Tab: Overview
	// =========================================================================

	/**
	 * Render the Overview tab with media KPIs, recent uploads, and quick links.
	 */
	private static function render_overview_tab() {
		$attachments       = self::get_attachment_stats();
		$templates_count   = self::get_cpt_count( 'mcp_ai_media_tpl', 'publish' );
		$collections_count = self::get_cpt_count( 'mcp_ai_media_coll', 'publish' );
		$storage_bytes     = self::get_storage_used();
		$alt_text_pct      = self::get_alt_text_compliance();
		$nextgen_pct       = self::get_nextgen_format_pct();
		$compression_saved = self::get_compression_savings();
		$recent_uploads    = self::get_recent_uploads( 15 );
		$processing_queue  = self::get_processing_queue_status();
		$schedule_health   = self::get_schedule_health_summary();
		?>
		<div class="media-cc-kpi-grid">
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Assets', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $attachments['total'] ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'In media library', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Templates', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $templates_count ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Processing templates', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Collections', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $collections_count ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Organised groups', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Storage Used', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( size_format( $storage_bytes ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Total disk space', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Alt Text Coverage', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $alt_text_pct >= 80 ? 'good' : ( $alt_text_pct >= 50 ? 'warn' : 'danger' ) ); ?>">
					<?php echo esc_html( $alt_text_pct ); ?>%
				</div>
				<div class="media-cc-health-bar-wrap">
					<div class="media-cc-health-bar" style="width: <?php echo esc_attr( $alt_text_pct ); ?>%; background: <?php echo esc_attr( $alt_text_pct >= 80 ? '#00a32a' : ( $alt_text_pct >= 50 ? '#dba617' : '#d63638' ) ); ?>;"></div>
				</div>
				<div class="media-cc-kpi-sub">
					<?php
					printf(
						/* translators: 1: images with alt text, 2: total images */
						esc_html__( '%1$d / %2$d images', 'mcp-ai-wpoos-pro' ),
						(int) $attachments['with_alt'],
						(int) $attachments['image_count']
					);
					?>
				</div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Next-Gen Format', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $nextgen_pct >= 60 ? 'good' : ( $nextgen_pct >= 30 ? 'warn' : 'danger' ) ); ?>">
					<?php echo esc_html( $nextgen_pct ); ?>%
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'WebP / AVIF adoption', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Compression Saved', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value good"><?php echo esc_html( size_format( $compression_saved ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Lifetime savings', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Processing Queue', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $processing_queue['failed'] > 0 ? 'warn' : '' ); ?>">
					<?php echo esc_html( number_format_i18n( $processing_queue['pending'] ) ); ?>
				</div>
				<div class="media-cc-kpi-sub">
					<?php
					printf(
						/* translators: %d: number of failed jobs */
						esc_html__( '%d pending, %d failed', 'mcp-ai-wpoos-pro' ),
						(int) $processing_queue['pending'],
						(int) $processing_queue['failed']
					);
					?>
				</div>
			</div>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Recent Uploads', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $recent_uploads ) ) : ?>
				<p><?php esc_html_e( 'No uploads yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<div class="media-cc-table-wrap">
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th style="width: 60px;"><?php esc_html_e( 'Preview', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'File', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Dimensions', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Size', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Alt Text', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Date', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $recent_uploads as $item ) : ?>
								<tr>
									<td>
										<?php if ( $item['thumbnail'] ) : ?>
											<img src="<?php echo esc_url( $item['thumbnail'] ); ?>"
												alt="<?php echo esc_attr( $item['title'] ); ?>"
												style="width: 50px; height: 50px; object-fit: cover; border-radius: 3px;" />
										<?php else : ?>
											<div style="width: 50px; height: 50px; background: #f0f0f1; border-radius: 3px; display: flex; align-items: center; justify-content: center;">
												<span class="dashicons dashicons-media-default" style="color: #8c8f94;"></span>
											</div>
										<?php endif; ?>
									</td>
									<td>
										<a href="<?php echo esc_url( $item['edit_url'] ); ?>">
											<strong><?php echo esc_html( $item['title'] ); ?></strong>
										</a>
										<br><small class="media-cc-muted"><?php echo esc_html( $item['filename'] ); ?></small>
									</td>
									<td><span class="media-cc-badge-status neutral"><?php echo esc_html( $item['mime_short'] ); ?></span></td>
									<td><?php echo esc_html( $item['dimensions'] ); ?></td>
									<td><?php echo esc_html( size_format( $item['filesize'] ) ); ?></td>
									<td>
										<?php if ( $item['has_alt'] ) : ?>
											<span class="media-cc-badge-status good"><?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php else : ?>
											<span class="media-cc-badge-status bad"><?php esc_html_e( 'Missing', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( $item['date'] ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
				<p style="margin-top: 12px;">
					<a href="<?php echo esc_url( admin_url( 'upload.php' ) ); ?>">
						<?php esc_html_e( 'View full media library →', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			<?php endif; ?>
		</div>

		<div class="media-cc-inline-cards">
			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Processing Queue', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Pending:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $processing_queue['pending'] ) ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'Completed Today:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $processing_queue['completed_today'] ) ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'Failed:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<span style="color: <?php echo $processing_queue['failed'] > 0 ? '#d63638' : '#00a32a'; ?>;">
						<?php echo esc_html( number_format_i18n( $processing_queue['failed'] ) ); ?>
					</span>
				</p>
				<?php if ( ! empty( $processing_queue['recent_failures'] ) ) : ?>
					<p style="color: #d63638; font-size: 12px;">
						<?php
						printf(
							/* translators: %s: comma-separated list of failed job names */
							esc_html__( 'Recent failures: %s', 'mcp-ai-wpoos-pro' ),
							esc_html( implode( ', ', $processing_queue['recent_failures'] ) )
						);
						?>
					</p>
				<?php endif; ?>
				<p style="margin-top: 12px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=processing' ) ); ?>" class="button">
						<?php esc_html_e( 'View Processing', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>

			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Schedule Health', 'mcp-ai-wpoos-pro' ); ?></h2>
				<p>
					<strong><?php esc_html_e( 'Active Schedules:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $schedule_health['active'] ) ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'Ran Today:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<?php echo esc_html( number_format_i18n( $schedule_health['ran_today'] ) ); ?>
					&nbsp;|&nbsp;
					<strong><?php esc_html_e( 'Overdue:', 'mcp-ai-wpoos-pro' ); ?></strong>
					<span style="color: <?php echo $schedule_health['overdue'] > 0 ? '#d63638' : '#00a32a'; ?>;">
						<?php echo esc_html( number_format_i18n( $schedule_health['overdue'] ) ); ?>
					</span>
				</p>
				<?php if ( ! empty( $schedule_health['next_run'] ) ) : ?>
					<p style="font-size: 12px; color: #646970;">
						<?php
						printf(
							/* translators: %s: next run time */
							esc_html__( 'Next run: %s', 'mcp-ai-wpoos-pro' ),
							esc_html( $schedule_health['next_run'] )
						);
						?>
					</p>
				<?php endif; ?>
				<p style="margin-top: 12px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=schedules' ) ); ?>" class="button">
						<?php esc_html_e( 'View Schedules', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Quick Actions', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p>
				<a href="<?php echo esc_url( admin_url( 'upload.php?page=design-media' ) ); ?>" class="button">
					<span class="dashicons dashicons-edit-page" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php esc_html_e( 'Design & Add', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button">
					<span class="dashicons dashicons-layout" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php esc_html_e( 'Browse Templates', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_coll' ) ); ?>" class="button">
					<span class="dashicons dashicons-portfolio" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php esc_html_e( 'View Collections', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-media-toolkit-settings' ) ); ?>" class="button">
					<span class="dashicons dashicons-admin-settings" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php esc_html_e( 'Media Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=health' ) ); ?>" class="button">
					<span class="dashicons dashicons-heart" style="vertical-align: middle; font-size: 16px; width: 16px; height: 16px;"></span>
					<?php esc_html_e( 'Health Audit', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Templates
	// =========================================================================

	/**
	 * Render the Templates tab with template grid, usage stats, and quick actions.
	 */
	private static function render_templates_tab() {
		$templates  = self::get_all_templates();
		$total      = count( $templates );
		$categories = self::get_template_categories();
		$most_used  = self::get_most_used_templates( 5 );
		$unused     = self::get_unused_templates();
		?>
		<div class="media-cc-kpi-grid">
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Templates', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
				<?php if ( $total > 0 ) : ?>
					<div class="media-cc-kpi-sub">
						<?php
						printf(
							/* translators: %d: number of unused templates */
							esc_html__( '%d unused', 'mcp-ai-wpoos-pro' ),
							(int) count( $unused )
						);
						?>
					</div>
				<?php endif; ?>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Categories', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( count( $categories ) ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Template categories', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Most Used', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value">
					<?php echo esc_html( ! empty( $most_used ) ? $most_used[0]['title'] : '—' ); ?>
				</div>
				<div class="media-cc-kpi-sub">
					<?php
					echo ! empty( $most_used ) && ! empty( $most_used[0]['usage_count'] )
						? esc_html(
							sprintf(
								/* translators: %d: usage count */
								__( '%d applications', 'mcp-ai-wpoos-pro' ),
								(int) $most_used[0]['usage_count']
							)
						)
						: '—';
					?>
				</div>
			</div>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Template Library', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $templates ) ) : ?>
				<p><?php esc_html_e( 'No templates created yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<div class="media-cc-table-wrap">
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Template', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Usage', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Last Used', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $templates as $tpl ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( $tpl['edit_url'] ); ?>">
											<strong><?php echo esc_html( $tpl['title'] ); ?></strong>
										</a>
									</td>
									<td><?php echo esc_html( ! empty( $tpl['category'] ) ? $tpl['category'] : '—' ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $tpl['usage_count'] ) ); ?></td>
									<td><?php echo esc_html( ! empty( $tpl['last_used'] ) ? $tpl['last_used'] : __( 'Never', 'mcp-ai-wpoos-pro' ) ); ?></td>
									<td>
										<?php if ( $tpl['is_unused'] ) : ?>
											<span class="media-cc-badge-status warn"><?php esc_html_e( 'Unused', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php else : ?>
											<span class="media-cc-badge-status good"><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php endif; ?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<p style="margin-top: 12px;">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Add New Template', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_tpl' ) ); ?>" class="button">
					<?php esc_html_e( 'View All Templates', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>

		<?php if ( ! empty( $most_used ) ) : ?>
		<div class="media-cc-inline-cards">
			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Most-Used Templates', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat striped" style="border: none;">
					<thead>
						<tr>
							<th>#</th>
							<th><?php esc_html_e( 'Template', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Uses', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $most_used as $i => $mu ) : ?>
							<tr>
								<td><?php echo esc_html( $i + 1 ); ?></td>
								<td><?php echo esc_html( $mu['title'] ); ?></td>
								<td><?php echo esc_html( number_format_i18n( $mu['usage_count'] ) ); ?></td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>

			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Categories', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $categories ) ) : ?>
					<p><?php esc_html_e( 'No categories defined.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<ul style="margin: 0; padding: 0; list-style: none;">
						<?php foreach ( $categories as $cat ) : ?>
							<li style="padding: 6px 0; border-bottom: 1px solid #f0f0f1; display: flex; justify-content: space-between;">
								<span><?php echo esc_html( $cat['name'] ); ?></span>
								<span class="media-cc-badge-status neutral"><?php echo esc_html( number_format_i18n( $cat['count'] ) ); ?></span>
							</li>
						<?php endforeach; ?>
					</ul>
				<?php endif; ?>
			</div>
		</div>
		<?php endif; ?>
		<?php
	}

	// =========================================================================
	// Tab: Collections
	// =========================================================================

	/**
	 * Render the Collections tab with health dashboard and collection list.
	 */
	private static function render_collections_tab() {
		$collections = self::get_all_collections();
		$total       = count( $collections );
		$total_items = 0;
		$empty_count = 0;
		$stale_count = 0;
		$now         = time();

		foreach ( $collections as $c ) {
			$total_items += $c['item_count'];
			if ( 0 === $c['item_count'] ) {
				++$empty_count;
			}
			if ( $c['last_updated_ts'] > 0 && ( $now - $c['last_updated_ts'] ) > 90 * DAY_IN_SECONDS ) {
				++$stale_count;
			}
		}
		?>
		<div class="media-cc-kpi-grid">
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Collections', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $total ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Organised groups', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Items', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $total_items ) ); ?></div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Across all collections', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Empty Collections', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $empty_count > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $empty_count ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Need attention', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Stale (>90d)', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $stale_count > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $stale_count ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Not updated recently', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Collection Health', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $collections ) ) : ?>
				<p><?php esc_html_e( 'No collections created yet.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<div class="media-cc-table-wrap">
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Collection', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Items', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Templates', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Last Updated', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Health', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $collections as $c ) : ?>
								<tr>
									<td>
										<a href="<?php echo esc_url( $c['edit_url'] ); ?>">
											<strong><?php echo esc_html( $c['title'] ); ?></strong>
										</a>
									</td>
									<td><?php echo esc_html( number_format_i18n( $c['item_count'] ) ); ?></td>
									<td><?php echo esc_html( number_format_i18n( $c['template_count'] ) ); ?></td>
									<td><?php echo esc_html( ! empty( $c['last_updated'] ) ? $c['last_updated'] : '—' ); ?></td>
									<td>
										<?php
										if ( 0 === $c['item_count'] ) {
											echo '<span class="media-cc-badge-status warn">' . esc_html__( 'Empty', 'mcp-ai-wpoos-pro' ) . '</span>';
										} elseif ( $c['last_updated_ts'] > 0 && ( $now - $c['last_updated_ts'] ) > 90 * DAY_IN_SECONDS ) {
											echo '<span class="media-cc-badge-status warn">' . esc_html__( 'Stale', 'mcp-ai-wpoos-pro' ) . '</span>';
										} elseif ( $c['template_count'] < 1 ) {
											echo '<span class="media-cc-badge-status info">' . esc_html__( 'No Template', 'mcp-ai-wpoos-pro' ) . '</span>';
										} else {
											echo '<span class="media-cc-badge-status good">' . esc_html__( 'Healthy', 'mcp-ai-wpoos-pro' ) . '</span>';
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<p style="margin-top: 12px;">
				<a href="<?php echo esc_url( admin_url( 'post-new.php?post_type=mcp_ai_media_coll' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Create Collection', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'edit.php?post_type=mcp_ai_media_coll' ) ); ?>" class="button">
					<?php esc_html_e( 'View All Collections', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Processing
	// =========================================================================

	/**
	 * Render the Processing tab with Sharp status, job queue, and package health.
	 */
	private static function render_processing_tab() {
		$sharp_ready = self::check_nodejs_available() && self::check_package_available( 'sharp' );
		$queue       = self::get_processing_queue_status();
		$packages    = self::get_media_package_statuses();
		$compression = self::get_compression_stats();
		?>
		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Sharp Processing Engine', 'mcp-ai-wpoos-pro' ); ?></h2>
			<table class="widefat" style="max-width: 600px;">
				<tbody>
					<tr>
						<td style="width: 180px;"><strong><?php esc_html_e( 'Node.js Available', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( self::check_nodejs_available() ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Yes', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status bad"><?php esc_html_e( 'Not Found', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Sharp Library', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( self::check_package_available( 'sharp' ) ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status bad"><?php esc_html_e( 'Missing', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Processing Mode', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php
							$settings = get_option( 'wp_mcp_ai_media_toolkit_settings', array() );
							$mode     = isset( $settings['sharp_processing_mode'] ) ? $settings['sharp_processing_mode'] : 'local';
							if ( 'microservice' === $mode ) {
								$ms_url = isset( $settings['sharp_microservice_url'] ) ? $settings['sharp_microservice_url'] : '';
								echo '<span class="media-cc-badge-status info">' . esc_html__( 'Microservice', 'mcp-ai-wpoos-pro' ) . '</span>';
								if ( $ms_url ) {
									echo ' <code>' . esc_html( $ms_url ) . '</code>';
								}
							} else {
								echo '<span class="media-cc-badge-status good">' . esc_html__( 'Local', 'mcp-ai-wpoos-pro' ) . '</span>';
							}
							?>
						</td>
					</tr>
				</tbody>
			</table>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Processing Queue', 'mcp-ai-wpoos-pro' ); ?></h2>
			<div class="media-cc-kpi-grid" style="margin-bottom: 20px;">
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Pending', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $queue['pending'] ) ); ?></div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Completed Today', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value good"><?php echo esc_html( number_format_i18n( $queue['completed_today'] ) ); ?></div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Failed', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value <?php echo esc_attr( $queue['failed'] > 0 ? 'danger' : 'good' ); ?>">
						<?php echo esc_html( number_format_i18n( $queue['failed'] ) ); ?>
					</div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Processed', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $queue['total_processed'] ) ); ?></div>
				</div>
			</div>
			<p>
				<a href="#" class="button media-cc-refresh-queue" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
					<span class="dashicons dashicons-update" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Refresh Queue', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Compression Stats', 'mcp-ai-wpoos-pro' ); ?></h2>
			<table class="widefat" style="max-width: 600px;">
				<tbody>
					<tr>
						<td style="width: 200px;"><strong><?php esc_html_e( 'Images Compressed', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $compression['total_compressed'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Bytes Saved', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td class="media-cc-kpi-value good" style="font-size: 16px;"><?php echo esc_html( size_format( $compression['bytes_saved'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Avg. Reduction', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( $compression['avg_reduction_pct'] ); ?>%</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WebP Conversions', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $compression['webp_count'] ) ); ?></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'AVIF Conversions', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><?php echo esc_html( number_format_i18n( $compression['avif_count'] ) ); ?></td>
					</tr>
				</tbody>
			</table>
			<p style="margin-top: 12px;">
				<button type="button" class="button media-cc-compression-sweep" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
					<span class="dashicons dashicons-image-rotate" style="vertical-align: middle;"></span>
					<?php esc_html_e( 'Run Compression Sweep', 'mcp-ai-wpoos-pro' ); ?>
				</button>
			</p>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Package Status', 'mcp-ai-wpoos-pro' ); ?></h2>
			<div class="media-cc-table-wrap">
				<table class="widefat striped" style="max-width: 600px; border: none;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Package', 'mcp-ai-wpoos-pro' ); ?></th>
							<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $packages as $pkg ) : ?>
							<tr>
								<td><code><?php echo esc_html( $pkg['name'] ); ?></code></td>
								<td>
									<?php if ( $pkg['available'] ) : ?>
										<span class="media-cc-badge-status good"><?php esc_html_e( 'Available', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php else : ?>
										<span class="media-cc-badge-status bad"><?php esc_html_e( 'Missing', 'mcp-ai-wpoos-pro' ); ?></span>
									<?php endif; ?>
								</td>
							</tr>
						<?php endforeach; ?>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Analytics
	// =========================================================================

	/**
	 * Render the Analytics tab with storage, format, and SEO stats.
	 */
	private static function render_analytics_tab() {
		$format_dist   = self::get_format_distribution();
		$storage_bytes = self::get_storage_used();
		$attachments   = self::get_attachment_stats();
		$alt_pct       = self::get_alt_text_compliance();
		$nextgen_pct   = self::get_nextgen_format_pct();
		?>
		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Storage Overview', 'mcp-ai-wpoos-pro' ); ?></h2>
			<div class="media-cc-kpi-grid" style="margin-bottom: 20px;">
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Storage', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value"><?php echo esc_html( size_format( $storage_bytes ) ); ?></div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Files', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $attachments['total'] ) ); ?></div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Avg File Size', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value">
						<?php
						$avg = $attachments['total'] > 0 ? round( $storage_bytes / $attachments['total'] ) : 0;
						echo esc_html( size_format( $avg ) );
						?>
					</div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Alt Text Rate', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value <?php echo esc_attr( $alt_pct >= 80 ? 'good' : ( $alt_pct >= 50 ? 'warn' : 'danger' ) ); ?>">
						<?php echo esc_html( $alt_pct ); ?>%
					</div>
				</div>
				<div class="media-cc-kpi">
					<div class="media-cc-kpi-label"><?php esc_html_e( 'Next-Gen Rate', 'mcp-ai-wpoos-pro' ); ?></div>
					<div class="media-cc-kpi-value <?php echo esc_attr( $nextgen_pct >= 60 ? 'good' : ( $nextgen_pct >= 30 ? 'warn' : 'danger' ) ); ?>">
						<?php echo esc_html( $nextgen_pct ); ?>%
					</div>
				</div>
			</div>
		</div>

		<div class="media-cc-inline-cards">
			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Format Distribution', 'mcp-ai-wpoos-pro' ); ?></h2>
				<?php if ( empty( $format_dist ) ) : ?>
					<p><?php esc_html_e( 'No media files found.', 'mcp-ai-wpoos-pro' ); ?></p>
				<?php else : ?>
					<?php
					$max_count = max( wp_list_pluck( $format_dist, 'count' ) );
					?>
					<?php foreach ( $format_dist as $fmt ) : ?>
						<div class="media-cc-pipeline-stage">
							<div class="media-cc-pipeline-stage-name"><?php echo esc_html( $fmt['label'] ); ?></div>
							<div class="media-cc-pipeline-bar-wrap">
								<div class="media-cc-pipeline-bar" style="width: <?php echo esc_attr( $max_count > 0 ? round( ( $fmt['count'] / $max_count ) * 100 ) : 0 ); ?>%;"></div>
							</div>
							<div class="media-cc-pipeline-count">
								<?php echo esc_html( number_format_i18n( $fmt['count'] ) ); ?>
								(<?php echo esc_html( $fmt['pct'] ); ?>%)
							</div>
						</div>
					<?php endforeach; ?>
				<?php endif; ?>
			</div>

			<div class="media-cc-section">
				<h2><?php esc_html_e( 'SEO Completeness', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat" style="border: none;">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Alt Text Coverage', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td>
								<div class="media-cc-health-bar-wrap" style="margin: 0;">
									<div class="media-cc-health-bar" style="width: <?php echo esc_attr( $alt_pct ); ?>%; background: <?php echo esc_attr( $alt_pct >= 80 ? '#00a32a' : ( $alt_pct >= 50 ? '#dba617' : '#d63638' ) ); ?>;"></div>
								</div>
								<small><?php echo esc_html( $alt_pct ); ?>%</small>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Descriptive Filenames', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td>
								<?php
								$desc_pct = $attachments['image_count'] > 0
									? round( ( $attachments['descriptive_filename_count'] / $attachments['image_count'] ) * 100 )
									: 0;
								?>
								<div class="media-cc-health-bar-wrap" style="margin: 0;">
									<div class="media-cc-health-bar" style="width: <?php echo esc_attr( $desc_pct ); ?>%; background: <?php echo esc_attr( $desc_pct >= 70 ? '#00a32a' : ( $desc_pct >= 40 ? '#dba617' : '#d63638' ) ); ?>;"></div>
								</div>
								<small><?php echo esc_html( $desc_pct ); ?>%</small>
							</td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Next-Gen Format', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td>
								<div class="media-cc-health-bar-wrap" style="margin: 0;">
									<div class="media-cc-health-bar" style="width: <?php echo esc_attr( $nextgen_pct ); ?>%; background: <?php echo esc_attr( $nextgen_pct >= 60 ? '#00a32a' : ( $nextgen_pct >= 30 ? '#dba617' : '#d63638' ) ); ?>;"></div>
								</div>
								<small><?php echo esc_html( $nextgen_pct ); ?>%</small>
							</td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Blueprints
	// =========================================================================

	/**
	 * Render the Blueprints tab with installed blueprint summary.
	 */
	private static function render_blueprints_tab() {
		$blueprints = self::get_media_blueprints_status();
		?>
		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Installed Media Blueprints', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p class="description">
				<?php esc_html_e( 'Curated AI assistant blueprints pre-configured for media management. Install a blueprint to create a new assistant in one click.', 'mcp-ai-wpoos-pro' ); ?>
			</p>

			<?php if ( empty( $blueprints ) ) : ?>
				<p><?php esc_html_e( 'No blueprints available. Ensure the blueprint examples directory exists.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<div class="media-cc-table-wrap">
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Blueprint', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Role', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Tools', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $blueprints as $slug => $bp ) : ?>
								<tr>
									<td>
										<strong><?php echo esc_html( $bp['name'] ); ?></strong>
										<br><small class="media-cc-muted"><?php echo esc_html( $bp['description'] ); ?></small>
									</td>
									<td>
										<?php if ( ! empty( $bp['profession'] ) ) : ?>
											<span class="media-cc-badge-status info">
												<?php echo esc_html( ucfirst( str_replace( '_', ' ', $bp['profession'] ) ) ); ?>
											</span>
										<?php endif; ?>
									</td>
									<td>
										<?php if ( $bp['installed'] ) : ?>
											<span class="media-cc-badge-status good"><?php esc_html_e( 'Installed', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php else : ?>
											<span class="media-cc-badge-status neutral"><?php esc_html_e( 'Not Installed', 'mcp-ai-wpoos-pro' ); ?></span>
										<?php endif; ?>
									</td>
									<td><?php echo esc_html( number_format_i18n( $bp['tool_count'] ) ); ?></td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
			<p style="margin-top: 12px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-media-blueprints' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Browse & Install Blueprints', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Schedules
	// =========================================================================

	/**
	 * Render the Schedules tab with media schedule preset status.
	 */
	private static function render_schedules_tab() {
		$presets = self::get_media_schedule_presets_status();
		$active  = 0;
		$ran     = 0;
		$overdue = 0;
		foreach ( $presets as $p ) {
			if ( 'active' === $p['status'] ) {
				++$active;
			}
			if ( ! empty( $p['ran_today'] ) ) {
				++$ran;
			}
			if ( ! empty( $p['is_overdue'] ) ) {
				++$overdue;
			}
		}
		?>
		<div class="media-cc-kpi-grid">
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Total Presets', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( count( $presets ) ) ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Active', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value good"><?php echo esc_html( number_format_i18n( $active ) ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Ran Today', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value"><?php echo esc_html( number_format_i18n( $ran ) ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Overdue', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $overdue > 0 ? 'danger' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $overdue ) ); ?>
				</div>
			</div>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Media Schedule Presets', 'mcp-ai-wpoos-pro' ); ?></h2>
			<?php if ( empty( $presets ) ) : ?>
				<p><?php esc_html_e( 'No media schedule presets available.', 'mcp-ai-wpoos-pro' ); ?></p>
			<?php else : ?>
				<div class="media-cc-table-wrap">
					<table class="widefat striped" style="border: none;">
						<thead>
							<tr>
								<th><?php esc_html_e( 'Preset', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Category', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Schedule', 'mcp-ai-wpoos-pro' ); ?></th>
								<th><?php esc_html_e( 'Status', 'mcp-ai-wpoos-pro' ); ?></th>
							</tr>
						</thead>
						<tbody>
							<?php foreach ( $presets as $p ) : ?>
								<tr>
									<td><strong><?php echo esc_html( $p['name'] ); ?></strong></td>
									<td><span class="media-cc-badge-status neutral"><?php echo esc_html( $p['category'] ); ?></span></td>
									<td><?php echo esc_html( $p['type'] ); ?></td>
									<td><code><?php echo esc_html( $p['schedule'] ); ?></code></td>
									<td>
										<?php
										switch ( $p['status'] ) {
											case 'active':
												echo '<span class="media-cc-badge-status good">' . esc_html__( 'Active', 'mcp-ai-wpoos-pro' ) . '</span>';
												break;
											case 'idle':
												echo '<span class="media-cc-badge-status neutral">' . esc_html__( 'Idle', 'mcp-ai-wpoos-pro' ) . '</span>';
												break;
											case 'overdue':
												echo '<span class="media-cc-badge-status warn">' . esc_html__( 'Overdue', 'mcp-ai-wpoos-pro' ) . '</span>';
												break;
											default:
												echo '<span class="media-cc-badge-status neutral">' . esc_html( $p['status'] ) . '</span>';
										}
										?>
									</td>
								</tr>
							<?php endforeach; ?>
						</tbody>
					</table>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Health & Compliance
	// =========================================================================

	/**
	 * Render the Health tab with alt text audit, duplicates, copyright, and orphans.
	 */
	private static function render_health_tab() {
		$alt_stats     = self::get_alt_text_compliance_detailed();
		$duplicates    = self::get_duplicate_media_count();
		$copyright     = self::get_copyright_status();
		$broken_refs   = self::get_broken_reference_count();
		$orphans       = self::get_orphan_attachment_count();
		$generic_names = self::get_generic_filename_count();
		?>
		<div class="media-cc-kpi-grid">
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Alt Text Issues', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $alt_stats['missing'] > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $alt_stats['missing'] ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Images missing alt text', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Duplicates', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $duplicates > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $duplicates ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Potential duplicates', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Copyright Flags', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $copyright['missing_license'] > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $copyright['missing_license'] ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Missing license info', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Broken Refs', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $broken_refs > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $broken_refs ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Content refs to deleted media', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Orphans', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $orphans > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $orphans ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'Unattached media', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
			<div class="media-cc-kpi">
				<div class="media-cc-kpi-label"><?php esc_html_e( 'Generic Names', 'mcp-ai-wpoos-pro' ); ?></div>
				<div class="media-cc-kpi-value <?php echo esc_attr( $generic_names > 0 ? 'warn' : 'good' ); ?>">
					<?php echo esc_html( number_format_i18n( $generic_names ) ); ?>
				</div>
				<div class="media-cc-kpi-sub"><?php esc_html_e( 'IMG_*.jpg, screenshot-*, etc.', 'mcp-ai-wpoos-pro' ); ?></div>
			</div>
		</div>

		<div class="media-cc-inline-cards">
			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Alt Text Audit', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat" style="border: none;">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'Total Images', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $alt_stats['total_images'] ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'With Alt Text', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><span style="color: #00a32a;"><?php echo esc_html( number_format_i18n( $alt_stats['with_alt'] ) ); ?></span></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Missing Alt Text', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><span style="color: <?php echo $alt_stats['missing'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( number_format_i18n( $alt_stats['missing'] ) ); ?></span></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Compliance Rate', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><strong><?php echo esc_html( $alt_stats['pct'] ); ?>%</strong></td>
						</tr>
					</tbody>
				</table>
				<p style="margin-top: 12px;">
					<button type="button" class="button media-cc-alt-audit" data-nonce="<?php echo esc_attr( wp_create_nonce( self::NONCE_ACTION ) ); ?>">
						<span class="dashicons dashicons-visibility" style="vertical-align: middle;"></span>
						<?php esc_html_e( 'Run Alt Text Audit', 'mcp-ai-wpoos-pro' ); ?>
					</button>
				</p>
			</div>

			<div class="media-cc-section">
				<h2><?php esc_html_e( 'Copyright & Licensing', 'mcp-ai-wpoos-pro' ); ?></h2>
				<table class="widefat" style="border: none;">
					<tbody>
						<tr>
							<td><strong><?php esc_html_e( 'No License Info', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><span style="color: <?php echo $copyright['missing_license'] > 0 ? '#d63638' : '#00a32a'; ?>;"><?php echo esc_html( number_format_i18n( $copyright['missing_license'] ) ); ?></span></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Stock Photos', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $copyright['stock_photos'] ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'AI-Generated', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $copyright['ai_generated'] ) ); ?></td>
						</tr>
						<tr>
							<td><strong><?php esc_html_e( 'Watermarked', 'mcp-ai-wpoos-pro' ); ?></strong></td>
							<td><?php echo esc_html( number_format_i18n( $copyright['watermarked'] ) ); ?></td>
						</tr>
					</tbody>
				</table>
				<p style="margin-top: 12px;">
					<a href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '&tab=processing' ) ); ?>" class="button">
						<?php esc_html_e( 'Run Copyright Review', 'mcp-ai-wpoos-pro' ); ?>
					</a>
				</p>
			</div>
		</div>

		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Quick Fixes', 'mcp-ai-wpoos-pro' ); ?></h2>
			<p>
				<?php if ( $broken_refs > 0 ) : ?>
					<button type="button" class="button" disabled>
						<?php
						printf(
							/* translators: %d: number of broken references */
							esc_html__( 'Fix %d Broken References', 'mcp-ai-wpoos-pro' ),
							(int) $broken_refs
						);
						?>
					</button>
				<?php endif; ?>
				<?php if ( $orphans > 0 ) : ?>
					<button type="button" class="button" disabled>
						<?php
						printf(
							/* translators: %d: number of orphan attachments */
							esc_html__( 'Clean %d Orphans', 'mcp-ai-wpoos-pro' ),
							(int) $orphans
						);
						?>
					</button>
				<?php endif; ?>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-media-toolkit-settings' ) ); ?>" class="button">
					<?php esc_html_e( 'Media Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Tab: Configuration
	// =========================================================================

	/**
	 * Render the Configuration tab with read-only settings summary and links.
	 */
	private static function render_configuration_tab() {
		$media_settings    = get_option( 'wp_mcp_ai_media_toolkit_settings', array() );
		$general_settings  = get_option( 'wp_mcp_ai_settings', array() );
		$template_settings = get_option( 'wp_mcp_ai_media_settings', array() );

		$sharp_mode       = isset( $media_settings['sharp_processing_mode'] ) ? $media_settings['sharp_processing_mode'] : 'local';
		$webp_enabled     = ! empty( $media_settings['sharp_enable_webp'] );
		$ai_design        = ! empty( $media_settings['enable_ai_design'] );
		$smart_tagging    = ! empty( $template_settings['enable_smart_tagging'] );
		$ocr_provider     = isset( $media_settings['ocr_primary_provider'] ) ? $media_settings['ocr_primary_provider'] : 'tesseract';
		$research_enabled = ! empty( $media_settings['enable_research'] );
		?>
		<div class="media-cc-section">
			<h2><?php esc_html_e( 'Media Toolkit Configuration', 'mcp-ai-wpoos-pro' ); ?></h2>
			<table class="widefat" style="max-width: 700px;">
				<tbody>
					<tr>
						<td style="width: 220px;"><strong><?php esc_html_e( 'Media Toolkit', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( ! empty( $general_settings['enable_media_toolkit'] ) ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status bad"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Sharp Processing Mode', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><code><?php echo esc_html( $sharp_mode ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'WebP Auto-Conversion', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( $webp_enabled ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status neutral"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'OCR Provider', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td><code><?php echo esc_html( $ocr_provider ); ?></code></td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'AI Design Generation', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( $ai_design ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status neutral"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Smart Tagging', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( $smart_tagging ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status neutral"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
					<tr>
						<td><strong><?php esc_html_e( 'Research & Add', 'mcp-ai-wpoos-pro' ); ?></strong></td>
						<td>
							<?php if ( $research_enabled ) : ?>
								<span class="media-cc-badge-status good"><?php esc_html_e( 'Enabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php else : ?>
								<span class="media-cc-badge-status neutral"><?php esc_html_e( 'Disabled', 'mcp-ai-wpoos-pro' ); ?></span>
							<?php endif; ?>
						</td>
					</tr>
				</tbody>
			</table>

			<p style="margin-top: 16px;">
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-media-toolkit-settings' ) ); ?>" class="button button-primary">
					<?php esc_html_e( 'Edit Toolkit Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'upload.php?page=media-toolkit-settings' ) ); ?>" class="button">
					<?php esc_html_e( 'Template Settings', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=nvoos-pro-schedule-manager' ) ); ?>" class="button">
					<?php esc_html_e( 'Schedule Manager', 'mcp-ai-wpoos-pro' ); ?>
				</a>
				<a href="<?php echo esc_url( admin_url( 'admin.php?page=wp-mcp-ai-remote-sites' ) ); ?>" class="button">
					<?php esc_html_e( 'Remote Sites', 'mcp-ai-wpoos-pro' ); ?>
				</a>
			</p>
		</div>
		<?php
	}

	// =========================================================================
	// Helper Methods — Data fetching
	// =========================================================================

	/**
	 * Get post type count by status.
	 *
	 * @param string $post_type Post type slug.
	 * @param string $status    Post status.
	 * @return int
	 */
	private static function get_cpt_count( $post_type, $status = 'publish' ) {
		$counts = wp_count_posts( $post_type );
		return isset( $counts->$status ) ? (int) $counts->$status : 0;
	}

	/**
	 * Get attachment statistics.
	 *
	 * @return array
	 */
	private static function get_attachment_stats() {
		$counts = wp_count_attachments();
		$total  = array_sum( (array) $counts );

		// Count images with alt text.
		global $wpdb;
		$image_mimes       = array( 'image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif' );
		$mime_placeholders = implode( ',', array_fill( 0, count( $image_mimes ), '%s' ) );

		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery
		$image_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} WHERE post_type = 'attachment' AND post_mime_type IN ($mime_placeholders)",
				...$image_mimes
			)
		);

		$with_alt = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(DISTINCT p.ID) FROM {$wpdb->posts} p
				INNER JOIN {$wpdb->postmeta} pm ON p.ID = pm.post_id AND pm.meta_key = '_wp_attachment_image_alt'
				WHERE p.post_type = 'attachment'
				AND p.post_mime_type IN ($mime_placeholders)
				AND pm.meta_value != ''",
				...$image_mimes
			)
		);

		// Count descriptive filenames (not IMG_*, screenshot-*, etc.).
		$descriptive_count = (int) $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts}
				WHERE post_type = 'attachment'
				AND post_mime_type IN ($mime_placeholders)
				AND post_title NOT LIKE 'img_%'
				AND post_title NOT LIKE 'IMG_%'
				AND post_title NOT LIKE 'screenshot-%'
				AND post_title NOT LIKE 'Screenshot_%'
				AND post_title NOT LIKE 'image-%'
				AND post_title NOT LIKE 'Image_%'
				AND post_title NOT LIKE 'photo-%'
				AND post_title NOT LIKE 'Photo_%'
				AND post_title NOT LIKE 'dsc_%'
				AND post_title NOT LIKE 'DSC_%'",
				...$image_mimes
			)
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching,WordPress.DB.PreparedSQL.InterpolatedNotPrepared,WordPress.DB.PreparedSQLPlaceholders.UnfinishedPrepare,WordPress.DB.PreparedSQLPlaceholders.LikeWildcardsInQuery

		return array(
			'total'                      => $total,
			'image_count'                => $image_count,
			'with_alt'                   => $with_alt,
			'descriptive_filename_count' => $descriptive_count,
		);
	}

	/**
	 * Get total storage used by attachments.
	 *
	 * @return int Bytes.
	 */
	private static function get_storage_used() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total = (int) $wpdb->get_var(
			"SELECT COALESCE(SUM(meta_value), 0) FROM {$wpdb->postmeta}
			INNER JOIN {$wpdb->posts} ON {$wpdb->postmeta}.post_id = {$wpdb->posts}.ID
			WHERE {$wpdb->posts}.post_type = 'attachment'
			AND {$wpdb->postmeta}.meta_key = '_wp_attached_file'"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		// We need actual filesize, not the file path. Use a faster approach.
		$uploads_dir = wp_get_upload_dir();
		$basedir     = $uploads_dir['basedir'];

		// Get all attachment post IDs.
		$attachment_ids = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => -1,
				'fields'         => 'ids',
				'post_status'    => 'inherit',
			)
		);

		$total_bytes = 0;
		foreach ( $attachment_ids as $id ) {
			$file = get_attached_file( $id );
			if ( $file && file_exists( $file ) ) {
				$total_bytes += filesize( $file );
			}
		}

		return $total_bytes;
	}

	/**
	 * Get alt text compliance percentage.
	 *
	 * @return int Percentage 0-100.
	 */
	private static function get_alt_text_compliance() {
		$stats = self::get_attachment_stats();
		if ( $stats['image_count'] < 1 ) {
			return 100;
		}
		return (int) round( ( $stats['with_alt'] / $stats['image_count'] ) * 100 );
	}

	/**
	 * Get detailed alt text stats.
	 *
	 * @return array
	 */
	private static function get_alt_text_compliance_detailed() {
		$stats   = self::get_attachment_stats();
		$pct     = $stats['image_count'] > 0 ? (int) round( ( $stats['with_alt'] / $stats['image_count'] ) * 100 ) : 100;
		$missing = $stats['image_count'] - $stats['with_alt'];

		return array(
			'total_images' => $stats['image_count'],
			'with_alt'     => $stats['with_alt'],
			'missing'      => $missing,
			'pct'          => $pct,
		);
	}

	/**
	 * Get next-gen format adoption percentage.
	 *
	 * @return int Percentage 0-100.
	 */
	private static function get_nextgen_format_pct() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$total_images = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_mime_type IN ('image/jpeg', 'image/png', 'image/gif', 'image/webp', 'image/avif')"
		);

		if ( $total_images < 1 ) {
			return 0;
		}

		$nextgen = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_mime_type IN ('image/webp', 'image/avif')"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return (int) round( ( $nextgen / $total_images ) * 100 );
	}

	/**
	 * Get compression savings in bytes.
	 *
	 * @return int
	 */
	private static function get_compression_savings() {
		$stats = get_option( 'wp_mcp_ai_media_compression_stats', array() );
		return isset( $stats['bytes_saved'] ) ? (int) $stats['bytes_saved'] : 0;
	}

	/**
	 * Get compression statistics.
	 *
	 * @return array
	 */
	private static function get_compression_stats() {
		$stats = get_option( 'wp_mcp_ai_media_compression_stats', array() );
		return array(
			'total_compressed'  => isset( $stats['total_compressed'] ) ? (int) $stats['total_compressed'] : 0,
			'bytes_saved'       => isset( $stats['bytes_saved'] ) ? (int) $stats['bytes_saved'] : 0,
			'avg_reduction_pct' => isset( $stats['avg_reduction_pct'] ) ? (int) $stats['avg_reduction_pct'] : 0,
			'webp_count'        => isset( $stats['webp_count'] ) ? (int) $stats['webp_count'] : 0,
			'avif_count'        => isset( $stats['avif_count'] ) ? (int) $stats['avif_count'] : 0,
		);
	}

	/**
	 * Get recent uploads with metadata.
	 *
	 * @param int $limit Number of items.
	 * @return array
	 */
	private static function get_recent_uploads( $limit = 15 ) {
		$attachments = get_posts(
			array(
				'post_type'      => 'attachment',
				'posts_per_page' => $limit,
				'post_status'    => 'inherit',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $attachments as $att ) {
			$meta     = wp_get_attachment_metadata( $att->ID );
			$thumb    = wp_get_attachment_image_url( $att->ID, 'thumbnail' );
			$file     = get_attached_file( $att->ID );
			$filesize = $file && file_exists( $file ) ? filesize( $file ) : 0;
			$alt      = get_post_meta( $att->ID, '_wp_attachment_image_alt', true );
			$mime     = explode( '/', $att->post_mime_type );

			$dimensions = '';
			if ( ! empty( $meta['width'] ) && ! empty( $meta['height'] ) ) {
				$dimensions = $meta['width'] . ' × ' . $meta['height'];
			}

			$items[] = array(
				'id'         => $att->ID,
				'title'      => $att->post_title ? $att->post_title : __( '(untitled)', 'mcp-ai-wpoos-pro' ),
				'filename'   => basename( get_attached_file( $att->ID ) ),
				'thumbnail'  => $thumb ? $thumb : '',
				'mime_short' => isset( $mime[1] ) ? strtoupper( $mime[1] ) : strtoupper( $mime[0] ),
				'dimensions' => $dimensions ? $dimensions : '—',
				'filesize'   => $filesize,
				'has_alt'    => ! empty( $alt ),
				'edit_url'   => get_edit_post_link( $att->ID, 'raw' ),
				'date'       => get_the_date( 'Y-m-d', $att ),
			);
		}

		return $items;
	}

	/**
	 * Get processing queue status.
	 *
	 * @return array
	 */
	private static function get_processing_queue_status() {
		$stats = get_option( 'wp_mcp_ai_media_processing_queue', array() );
		return array(
			'pending'         => isset( $stats['pending'] ) ? (int) $stats['pending'] : 0,
			'completed_today' => isset( $stats['completed_today'] ) ? (int) $stats['completed_today'] : 0,
			'failed'          => isset( $stats['failed'] ) ? (int) $stats['failed'] : 0,
			'total_processed' => isset( $stats['total_processed'] ) ? (int) $stats['total_processed'] : 0,
			'recent_failures' => isset( $stats['recent_failures'] ) ? (array) $stats['recent_failures'] : array(),
		);
	}

	/**
	 * Get schedule health summary.
	 *
	 * @return array
	 */
	private static function get_schedule_health_summary() {
		$active  = 0;
		$ran     = 0;
		$overdue = 0;
		$next    = '';

		if ( function_exists( 'as_get_scheduled_actions' ) ) {
			$hooks = array(
				'wp_mcp_ai_media_compression_sweep',
				'wp_mcp_ai_media_duplicate_detect',
				'wp_mcp_ai_media_broken_link_scan',
			);

			foreach ( $hooks as $hook ) {
				$pending = as_get_scheduled_actions(
					array(
						'hook'   => $hook,
						'status' => \ActionScheduler_Store::STATUS_PENDING,
					),
					'ids'
				);
				if ( ! empty( $pending ) ) {
					++$active;
				}
			}

			// Check completed today.
			$today_start = strtotime( 'today midnight' );
			$completed   = as_get_scheduled_actions(
				array(
					'status' => \ActionScheduler_Store::STATUS_COMPLETE,
					'date'   => gmdate( 'Y-m-d H:i:s', $today_start ),
				),
				'ids'
			);
			$ran         = count( $completed );

			// Find next run.
			$actions = as_get_scheduled_actions(
				array(
					'status'   => \ActionScheduler_Store::STATUS_PENDING,
					'per_page' => 1,
					'orderby'  => 'schedule',
					'order'    => 'ASC',
				)
			);
			if ( ! empty( $actions ) ) {
				$action = reset( $actions );
				$next   = human_time_diff( $action->get_schedule()->get_date()->getTimestamp() ) . ' ' . __( 'from now', 'mcp-ai-wpoos-pro' );
			}
		}

		return array(
			'active'    => $active,
			'ran_today' => $ran,
			'overdue'   => $overdue,
			'next_run'  => $next,
		);
	}

	/**
	 * Get all media templates with metadata.
	 *
	 * @return array
	 */
	private static function get_all_templates() {
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_tpl',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		$now   = time();
		foreach ( $templates as $tpl ) {
			$cats         = wp_get_object_terms( $tpl->ID, 'media_template_category', array( 'fields' => 'names' ) );
			if ( is_wp_error( $cats ) ) {
				$cats = array();
			}
			$usage        = (int) get_post_meta( $tpl->ID, '_mcp_ai_template_usage_count', true );
			$last_used_ts = (int) get_post_meta( $tpl->ID, '_mcp_ai_template_last_used', true );

			$items[] = array(
				'id'          => $tpl->ID,
				'title'       => $tpl->post_title,
				'edit_url'    => get_edit_post_link( $tpl->ID, 'raw' ),
				'category'    => ! empty( $cats ) ? implode( ', ', $cats ) : '',
				'usage_count' => $usage,
				'last_used'   => $last_used_ts > 0 ? gmdate( 'Y-m-d', $last_used_ts ) : '',
				'is_unused'   => ( $usage < 1 ) || ( $last_used_ts > 0 && ( $now - $last_used_ts ) > 90 * DAY_IN_SECONDS ),
			);
		}

		return $items;
	}

	/**
	 * Get template categories with counts.
	 *
	 * @return array
	 */
	private static function get_template_categories() {
		$terms = get_terms(
			array(
				'taxonomy'   => 'media_template_category',
				'hide_empty' => true,
			)
		);

		if ( is_wp_error( $terms ) || empty( $terms ) ) {
			return array();
		}

		$categories = array();
		foreach ( $terms as $term ) {
			$categories[] = array(
				'name'  => $term->name,
				'count' => $term->count,
			);
		}

		return $categories;
	}

	/**
	 * Get most-used templates.
	 *
	 * @param int $limit Number of items.
	 * @return array
	 */
	private static function get_most_used_templates( $limit = 5 ) {
		$templates = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_tpl',
				'posts_per_page' => 100,
				'post_status'    => 'publish',
			)
		);

		$items = array();
		foreach ( $templates as $tpl ) {
			$items[] = array(
				'title'       => $tpl->post_title,
				'usage_count' => (int) get_post_meta( $tpl->ID, '_mcp_ai_template_usage_count', true ),
			);
		}

		usort(
			$items,
			function ( $a, $b ) {
				return $b['usage_count'] - $a['usage_count'];
			}
		);

		return array_slice( $items, 0, $limit );
	}

	/**
	 * Get unused templates.
	 *
	 * @return array
	 */
	private static function get_unused_templates() {
		$all       = self::get_all_templates();
		$unused    = array();
		$stale_sec = 90 * DAY_IN_SECONDS;
		$now       = time();

		foreach ( $all as $tpl ) {
			if ( $tpl['usage_count'] < 1 || ( $tpl['last_used'] && ( $now - strtotime( $tpl['last_used'] ) ) > $stale_sec ) ) {
				$unused[] = $tpl;
			}
		}

		return $unused;
	}

	/**
	 * Get all media collections with metadata.
	 *
	 * @return array
	 */
	private static function get_all_collections() {
		$collections = get_posts(
			array(
				'post_type'      => 'mcp_ai_media_coll',
				'posts_per_page' => -1,
				'post_status'    => 'publish',
				'orderby'        => 'date',
				'order'          => 'DESC',
			)
		);

		$items = array();
		foreach ( $collections as $coll ) {
			$collection_items     = get_post_meta( $coll->ID, '_mcp_ai_collection_items', true );
			$collection_templates = get_post_meta( $coll->ID, '_mcp_ai_collection_templates', true );
			$item_count           = is_array( $collection_items ) ? count( $collection_items ) : 0;
			$template_count       = is_array( $collection_templates ) ? count( $collection_templates ) : 0;

			$items[] = array(
				'id'              => $coll->ID,
				'title'           => $coll->post_title,
				'edit_url'        => get_edit_post_link( $coll->ID, 'raw' ),
				'item_count'      => $item_count,
				'template_count'  => $template_count,
				'last_updated'    => get_the_modified_date( 'Y-m-d', $coll ),
				'last_updated_ts' => get_post_modified_time( 'U', false, $coll ),
			);
		}

		return $items;
	}

	/**
	 * Check if Node.js is available.
	 *
	 * @return bool
	 */
	private static function check_nodejs_available() {
		if ( function_exists( 'wp_mcp_ai_check_nodejs_available' ) ) {
			return wp_mcp_ai_check_nodejs_available();
		}
		// Fallback: try to execute node --version.
		$output     = null;
		$return_var = 0;
		// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.system_calls_exec
		exec( 'node --version 2>/dev/null', $output, $return_var );
		return 0 === $return_var;
	}

	/**
	 * Check if an NPM package is available.
	 *
	 * @param string $package_name Package name.
	 * @return bool
	 */
	private static function check_package_available( $package_name ) {
		if ( function_exists( 'wp_mcp_ai_get_npm_package_status' ) ) {
			$status = wp_mcp_ai_get_npm_package_status( $package_name );
			return ! empty( $status['available'] );
		}
		return false;
	}

	/**
	 * Get media package statuses.
	 *
	 * @return array
	 */
	private static function get_media_package_statuses() {
		$packages = array( 'sharp', 'canvas', 'tesseract', 'ffmpeg' );
		$items    = array();

		foreach ( $packages as $pkg ) {
			$items[] = array(
				'name'      => $pkg,
				'available' => self::check_package_available( $pkg ),
			);
		}

		return $items;
	}

	/**
	 * Get format distribution.
	 *
	 * @return array
	 */
	private static function get_format_distribution() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$results = $wpdb->get_results(
			"SELECT post_mime_type, COUNT(*) as cnt
			FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			GROUP BY post_mime_type
			ORDER BY cnt DESC",
			ARRAY_A
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		$total = array_sum( wp_list_pluck( $results, 'cnt' ) );
		$items = array();

		$label_map = array(
			'image/jpeg'      => 'JPEG',
			'image/png'       => 'PNG',
			'image/gif'       => 'GIF',
			'image/webp'      => 'WebP',
			'image/avif'      => 'AVIF',
			'image/svg+xml'   => 'SVG',
			'video/mp4'       => 'MP4',
			'audio/mpeg'      => 'MP3',
			'application/pdf' => 'PDF',
		);

		foreach ( $results as $row ) {
			$mime    = $row['post_mime_type'];
			$cnt     = (int) $row['cnt'];
			$items[] = array(
				'mime'  => $mime,
				'label' => isset( $label_map[ $mime ] ) ? $label_map[ $mime ] : $mime,
				'count' => $cnt,
				'pct'   => $total > 0 ? round( ( $cnt / $total ) * 100, 1 ) : 0,
			);
		}

		return $items;
	}

	/**
	 * Get media blueprints install status.
	 *
	 * @return array
	 */
	private static function get_media_blueprints_status() {
		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			$installer_path = WP_MCP_AI_PRO_PATH . 'includes/tools/orchestration/class-wp-mcp-ai-blueprint-installer.php';
			if ( file_exists( $installer_path ) ) {
				require_once $installer_path;
			}
		}

		if ( ! class_exists( 'WP_MCP_AI_Blueprint_Installer' ) ) {
			return array();
		}

		$blueprints_dir = WP_MCP_AI_PRO_PATH . 'includes/tools/media/examples';
		if ( ! is_dir( $blueprints_dir ) ) {
			return array();
		}

		$slugs           = WP_MCP_AI_Blueprint_Installer::list_blueprints( $blueprints_dir );
		$installed_names = array();

		$assistants      = get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'any',
				'posts_per_page' => 200, // phpcs:ignore WordPress.WP.PostsPerPage.posts_per_page_posts_per_page
				'fields'         => 'post_title',
			)
		);
		$installed_names = wp_list_pluck( $assistants, 'post_title' );

		$items = array();
		foreach ( $slugs as $slug ) {
			$data = WP_MCP_AI_Blueprint_Installer::load_blueprint( $blueprints_dir, $slug );
			if ( is_wp_error( $data ) ) {
				continue;
			}
			$items[ $slug ] = array(
				'name'        => $data['name'],
				'description' => isset( $data['description'] ) ? $data['description'] : '',
				'profession'  => isset( $data['meta']['profession'] ) ? $data['meta']['profession'] : '',
				'tool_count'  => isset( $data['meta']['available_tools'] ) ? count( $data['meta']['available_tools'] ) : 0,
				'installed'   => in_array( $data['name'], $installed_names, true ),
			);
		}

		return $items;
	}

	/**
	 * Get media schedule presets status.
	 *
	 * @return array
	 */
	private static function get_media_schedule_presets_status() {
		if ( ! class_exists( 'WP_MCP_AI_Pro_Schedule_Presets' ) ) {
			return array();
		}

		$presets = array();
		if ( method_exists( 'WP_MCP_AI_Pro_Schedule_Presets', 'get_media_presets' ) ) {
			$presets = WP_MCP_AI_Pro_Schedule_Presets::get_media_presets();
		}

		$items = array();
		$now   = time();

		foreach ( $presets as $slug => $preset ) {
			$type_label = $preset['schedule_type'];
			switch ( $preset['schedule_type'] ) {
				case 'assistant_run':
					$type_label = __( 'Assistant Run', 'mcp-ai-wpoos-pro' );
					break;
				case 'task':
					$type_label = __( 'Task', 'mcp-ai-wpoos-pro' );
					break;
				case 'channel_broadcast':
					$type_label = __( 'Broadcast', 'mcp-ai-wpoos-pro' );
					break;
			}

			$items[] = array(
				'slug'       => $slug,
				'name'       => $preset['name'],
				'category'   => isset( $preset['category'] ) ? $preset['category'] : '',
				'type'       => $type_label,
				'schedule'   => isset( $preset['schedule'] ) ? $preset['schedule'] : '',
				'status'     => 'idle',
				'ran_today'  => false,
				'is_overdue' => false,
			);
		}

		return $items;
	}

	/**
	 * Get duplicate media count.
	 *
	 * @return int
	 */
	private static function get_duplicate_media_count() {
		$count = get_option( 'wp_mcp_ai_media_duplicate_count', -1 );
		if ( $count >= 0 ) {
			return (int) $count;
		}
		// Placeholder until duplicate scanner runs.
		return 0;
	}

	/**
	 * Get copyright status.
	 *
	 * @return array
	 */
	private static function get_copyright_status() {
		// Placeholder values until copyright review runs.
		return array(
			'missing_license' => 0,
			'stock_photos'    => 0,
			'ai_generated'    => 0,
			'watermarked'     => 0,
		);
	}

	/**
	 * Get broken reference count.
	 *
	 * @return int
	 */
	private static function get_broken_reference_count() {
		// Count posts/pages that reference attachment IDs that no longer exist.
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		// Simple heuristic: count references in post_content to wp-image-XXXX where XXXX is not a valid attachment.
		$posts = $wpdb->get_results(
			"SELECT ID, post_content FROM {$wpdb->posts}
			WHERE post_content LIKE '%wp-image-%'
			AND post_status = 'publish'"
		);

		$broken = 0;
		foreach ( $posts as $post ) {
			preg_match_all( '/wp-image-(\d+)/', $post->post_content, $matches );
			if ( ! empty( $matches[1] ) ) {
				foreach ( $matches[1] as $att_id ) {
					$attachment = get_post( (int) $att_id );
					if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
						++$broken;
					}
				}
			}
		}
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching

		return $broken;
	}

	/**
	 * Get orphan attachment count.
	 *
	 * @return int
	 */
	private static function get_orphan_attachment_count() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND post_parent = 0"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $count;
	}

	/**
	 * Get generic filename count.
	 *
	 * @return int
	 */
	private static function get_generic_filename_count() {
		global $wpdb;
		// phpcs:disable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		$count = (int) $wpdb->get_var(
			"SELECT COUNT(*) FROM {$wpdb->posts}
			WHERE post_type = 'attachment'
			AND (
				post_title LIKE 'img_%'
				OR post_title LIKE 'IMG_%'
				OR post_title LIKE 'screenshot-%'
				OR post_title LIKE 'Screenshot_%'
				OR post_title LIKE 'image-%'
				OR post_title LIKE 'Image_%'
				OR post_title LIKE 'dsc_%'
				OR post_title LIKE 'DSC_%'
			)"
		);
		// phpcs:enable WordPress.DB.DirectDatabaseQuery.DirectQuery,WordPress.DB.DirectDatabaseQuery.NoCaching
		return $count;
	}

	// =========================================================================
	// AJAX Handlers
	// =========================================================================

	/**
	 * AJAX: Get health snapshot.
	 */
	public static function ajax_health_snapshot() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$attachments = self::get_attachment_stats();
		$storage     = self::get_storage_used();
		$alt_pct     = self::get_alt_text_compliance();
		$nextgen_pct = self::get_nextgen_format_pct();
		$compression = self::get_compression_savings();
		$queue       = self::get_processing_queue_status();
		$schedule    = self::get_schedule_health_summary();

		wp_send_json_success(
			array(
				'attachments' => $attachments,
				'storage'     => $storage,
				'alt_pct'     => $alt_pct,
				'nextgen_pct' => $nextgen_pct,
				'compression' => $compression,
				'queue'       => $queue,
				'schedule'    => $schedule,
			)
		);
	}

	/**
	 * AJAX: Trigger compression sweep.
	 */
	public static function ajax_compression_sweep() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		// Dispatch compression sweep as an Action Scheduler task.
		if ( function_exists( 'as_enqueue_async_action' ) ) {
			as_enqueue_async_action( 'wp_mcp_ai_media_compression_sweep' );
			wp_send_json_success( array( 'message' => __( 'Compression sweep queued. Check the Processing tab for status.', 'mcp-ai-wpoos-pro' ) ) );
		}

		wp_send_json_error( array( 'message' => __( 'Action Scheduler is not available.', 'mcp-ai-wpoos-pro' ) ) );
	}

	/**
	 * AJAX: Run alt text audit.
	 */
	public static function ajax_alt_text_audit() {
		check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		if ( ! current_user_can( 'upload_files' ) ) {
			wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos-pro' ) ) );
		}

		$stats = self::get_alt_text_compliance_detailed();

		wp_send_json_success(
			array(
				'message' => sprintf(
					/* translators: 1: percentage, 2: with alt count, 3: total count */
					__( 'Alt text audit complete: %1$d%% compliance (%2$d / %3$d images have alt text).', 'mcp-ai-wpoos-pro' ),
					$stats['pct'],
					$stats['with_alt'],
					$stats['total_images']
				),
				'stats'   => $stats,
			)
		);
	}
}
