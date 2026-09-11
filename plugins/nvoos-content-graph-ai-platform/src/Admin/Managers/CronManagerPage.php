<?php
/**
 * Cron manager page (Wave E-UI-2, sub-cluster 3).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_Cron_Manager`
 * (`includes/admin/class-wp-mcp-ai-admin-cron-manager.php`):
 * byte-identical page surface — the `wp-mcp-ai-cron-manager` page
 * slug (priority 15, `manage_options`), the `admin_post_`
 * `wp_mcp_ai_delete_cron` handler with per-job nonces, the
 * `wp_ajax_wp_mcp_ai_get_cron_manager_stats` stats handler, the
 * inline-stylesheet + shared monitor stylesheet + admin-cron-manager.js
 * asset enqueues with the `wpMcpAiCronManager` localized envelope,
 * the auto-refresh controls, the retention-period intro, the
 * updated/error notices, the statistics cards, the eight-column
 * jobs table (status pill, next-run human time, schedule-type pill,
 * pretty-printed args, creator, created-at, per-row delete form),
 * the empty state, the DLQ/SLA job-queue-health section (incl. the
 * tier table + tuning recommendations), the job-store section, and
 * the delete/redirect flow.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform` under `Admin\Managers\`. The
 *    class is named `CronManagerPage` to disambiguate from the
 *    ported runtime `Queues\CronManager` (E2).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerManagers()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`).
 *  - Collaborators resolve per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the runtime cron
 *    manager via the base `WP_MCP_AI_Cron_Manager` monolith / the
 *    platform's `Queues\CronManager` standalone (same static
 *    `get_jobs()`/`remove_job()`/`maybe_prune_jobs()` contract — the
 *    E2 port); the DLQ stats via the base `WP_MCP_AI_Dead_Letter_Queue`
 *    monolith / the platform's `Queues\DeadLetterQueue` standalone
 *    (static `get_stats()`); the SLA stats via the base
 *    `WP_MCP_AI_SLA_Manager` monolith / the platform's
 *    `Queues\SlaManager` standalone (static `is_enabled()`/
 *    `get_all_tiers_info()`/`get_tuning_recommendations()`); the job
 *    store is base-owned and not yet ported — standalone the section
 *    is hidden (documented); the retention period via the base
 *    `WP_MCP_AI_Settings_Registry` monolith / the `wp_mcp_ai_settings`
 *    option standalone (identical effective behavior — the E2
 *    `Queues\CronManager` convention).
 *  - The DLQ cross-link resolves per mode via the `dlq_manager_url()`
 *    seam — both modes use the byte-identical `wp-mcp-ai-dlq-manager`
 *    page slug (standalone resolves once the DLQ manager sub-cluster
 *    ports; documented forward-reference).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - The page's own asset (admin-cron-manager.js) is copied
 *    byte-identically into the platform asset tree (the shared
 *    admin-monitor-shared.css landed with E-UI-1); the base's
 *    filemtime-based versioning resolves through the platform's
 *    per-file asset seam.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Managers
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Managers;

/**
 * Renders the management UI for cron events scheduled via NV oOS.
 *
 * @since 2.0.0
 */
class CronManagerPage {

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'wp-mcp-ai-cron-manager';

	/**
	 * Nonce action for the page AJAX handlers.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_cron_manager';

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	protected $page_hook = '';

	/**
	 * Register the page hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'register_page' ), 15 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'admin_post_wp_mcp_ai_delete_cron', array( $this, 'handle_delete_cron' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_get_cron_manager_stats', array( $this, 'ajax_get_stats' ) );
	}

	/**
	 * Runtime cron manager class name (per-mode seam).
	 *
	 * @return string|null
	 */
	protected static function cron_manager_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return \class_exists( 'WP_MCP_AI_Cron_Manager' ) ? 'WP_MCP_AI_Cron_Manager' : null;
		}

		return \class_exists( 'NvoosContentGraphAiPlatform\Queues\CronManager' ) ? 'NvoosContentGraphAiPlatform\Queues\CronManager' : null;
	}

	/**
	 * Dead-letter queue class name (per-mode seam).
	 *
	 * @return string|null
	 */
	protected static function dlq_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return \class_exists( 'WP_MCP_AI_Dead_Letter_Queue' ) ? 'WP_MCP_AI_Dead_Letter_Queue' : null;
		}

		return \class_exists( 'NvoosContentGraphAiPlatform\Queues\DeadLetterQueue' ) ? 'NvoosContentGraphAiPlatform\Queues\DeadLetterQueue' : null;
	}

	/**
	 * SLA manager class name (per-mode seam).
	 *
	 * @return string|null
	 */
	protected static function sla_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return \class_exists( 'WP_MCP_AI_SLA_Manager' ) ? 'WP_MCP_AI_SLA_Manager' : null;
		}

		return \class_exists( 'NvoosContentGraphAiPlatform\Queues\SlaManager' ) ? 'NvoosContentGraphAiPlatform\Queues\SlaManager' : null;
	}

	/**
	 * Job store class name (per-mode seam).
	 *
	 * Base-owned and not yet ported — standalone hides the section
	 * (documented).
	 *
	 * @return string|null
	 */
	protected static function job_store_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Job_Store' ) ) {
			return 'WP_MCP_AI_Job_Store';
		}

		return null;
	}

	/**
	 * Cron-job retention period in hours (per-mode seam).
	 *
	 * @return int
	 */
	protected static function retention_hours() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Settings_Registry' ) ) {
			return \absint( \WP_MCP_AI_Settings_Registry::get_setting( 'cron_job_retention_period', 24 ) );
		}

		$settings = \get_option( 'wp_mcp_ai_settings', array() );
		if ( \is_array( $settings ) && isset( $settings['cron_job_retention_period'] ) ) {
			return \absint( $settings['cron_job_retention_period'] );
		}

		return 24;
	}

	/**
	 * DLQ manager page URL (per-mode seam).
	 *
	 * Both modes use the byte-identical page slug — standalone resolves
	 * once the DLQ manager sub-cluster ports (documented forward-reference).
	 *
	 * @return string
	 */
	protected static function dlq_manager_url() {
		return \admin_url( 'admin.php?page=wp-mcp-ai-dlq-manager' );
	}

	/**
	 * Asset URL for the platform's local copies (per-mode seam).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_url( $relative_path ) {
		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_URL . 'assets/' . \ltrim( $relative_path, '/' );
	}

	/**
	 * Asset version for the platform's local copies (per-file mtime).
	 *
	 * @param string $relative_path Asset path relative to the platform assets dir.
	 * @return string
	 */
	protected static function asset_version( $relative_path ) {
		$absolute_path = NVOOS_CONTENT_GRAPH_AI_PLATFORM_PATH . 'assets/' . \ltrim( $relative_path, '/' );

		if ( \file_exists( $absolute_path ) ) {
			$modified = \filemtime( $absolute_path );
			if ( $modified ) {
				return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION . '.' . $modified;
			}
		}

		return NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION;
	}

	/**
	 * Register the cron manager page under the NV Platform menu.
	 *
	 * @return void
	 */
	public function register_page(): void {
		$this->page_hook = \add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'NV oOS Cron Manager', 'nvoos-content-graph-ai-platform' ),
			__( 'Cron Manager', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue lightweight styles for the cron table.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( $this->page_hook !== $hook ) {
			return;
		}

		// Enqueue shared admin monitor styles.
		\wp_enqueue_style(
			'wp-mcp-ai-admin-monitor-shared',
			self::asset_url( 'css/admin-monitor-shared.css' ),
			array(),
			self::asset_version( 'css/admin-monitor-shared.css' )
		);

		$inline_css = '.wp-mcp-ai-cron-manager__intro{margin:1.5rem 0;padding:1rem;background:#f0f6fc;border-left:4px solid #2271b1;}'
			. '.wp-mcp-ai-cron-manager__intro p{margin:0.5rem 0;}'
			. '.wp-mcp-ai-cron-manager__intro p:first-child{margin-top:0;}'
			. '.wp-mcp-ai-cron-manager__intro p:last-child{margin-bottom:0;}'
			. '.wp-mcp-ai-cron-manager__stats{display:flex;gap:1.5rem;margin:1.5rem 0;}'
			. '.wp-mcp-ai-cron-manager__stat{padding:1rem;background:#fff;border:1px solid #dcdcde;border-radius:4px;flex:1;}'
			. '.wp-mcp-ai-cron-manager__stat-label{font-size:0.875rem;color:#646970;margin-bottom:0.25rem;}'
			. '.wp-mcp-ai-cron-manager__stat-value{font-size:1.75rem;font-weight:600;color:#1d2327;}'
			. '.wp-mcp-ai-cron-manager__table{margin-top:1.5rem;border-collapse:collapse;width:100%;}'
			. '.wp-mcp-ai-cron-manager__table th,.wp-mcp-ai-cron-manager__table td{border:1px solid #dcdcde;padding:0.75rem;text-align:left;vertical-align:top;}'
			. '.wp-mcp-ai-cron-manager__table th{background:#f8f9ff;font-weight:600;}'
			. '.wp-mcp-ai-cron-manager__empty{margin-top:1.5rem;padding:1.5rem;border:1px solid #dcdcde;background:#fff;border-radius:4px;}'
			. '.wp-mcp-ai-cron-manager__empty h3{margin-top:0;}'
			. '.wp-mcp-ai-cron-manager__empty ul{margin-left:1.5rem;}'
			. '.wp-mcp-ai-cron-manager__actions form{display:inline-block;margin-right:0.5rem;}'
			. '.wp-mcp-ai-cron-manager__args{font-family:monospace;font-size:13px;white-space:pre-wrap;word-break:break-word;}'
			. '.wp-mcp-ai-cron-manager__status{display:inline-block;padding:0.25rem 0.5rem;border-radius:3px;font-size:0.75rem;font-weight:600;}'
			. '.wp-mcp-ai-cron-manager__status--active{background:#d5f0db;color:#0a5f1a;}'
			. '.wp-mcp-ai-cron-manager__status--executed{background:#e0f2ff;color:#0056a0;}'
			. '.wp-mcp-ai-cron-manager__status--inactive{background:#f0f0f1;color:#50575e;}'
			. '.wp-mcp-ai-cron-manager__status--recurring{background:#e5f2ff;color:#0c5ba0;}'
			. '.wp-mcp-ai-cron-manager__status--oneoff{background:#fef7e0;color:#8b6c00;}';

		// phpcs:ignore WordPress.WP.EnqueuedResourceParameters.MissingVersion -- Inline style registered with no URL; version not applicable.
		\wp_register_style( 'wp-mcp-ai-cron-manager-inline', false );
		\wp_enqueue_style( 'wp-mcp-ai-cron-manager-inline' );
		\wp_add_inline_style( 'wp-mcp-ai-cron-manager-inline', $inline_css );

		// Enqueue JavaScript for auto-refresh functionality.
		\wp_enqueue_script(
			'wp-mcp-ai-admin-cron-manager',
			self::asset_url( 'js/admin-cron-manager.js' ),
			array( 'jquery' ),
			self::asset_version( 'js/admin-cron-manager.js' ),
			true
		);

		\wp_localize_script(
			'wp-mcp-ai-admin-cron-manager',
			'wpMcpAiCronManager',
			array(
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE_ACTION ),
				'strings' => array(
					'noJobs' => __( 'No cron events scheduled.', 'nvoos-content-graph-ai-platform' ),
				),
			)
		);
	}

	/**
	 * Handle deletion of a cron event from the manager.
	 *
	 * @return void
	 */
	public function handle_delete_cron(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have permission to manage cron events.', 'nvoos-content-graph-ai-platform' ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- Nonce verified by check_admin_referer below.
		$job_id = isset( $_POST['job_id'] ) ? \sanitize_text_field( \wp_unslash( $_POST['job_id'] ) ) : '';

		if ( '' === $job_id ) {
			\wp_die( \esc_html__( 'Missing cron identifier.', 'nvoos-content-graph-ai-platform' ) );
		}

		\check_admin_referer( 'wp_mcp_ai_delete_cron_' . $job_id );

		$cron_class = self::cron_manager_class();
		if ( null === $cron_class ) {
			\wp_safe_redirect( $this->manager_redirect( '0' ) );
			exit;
		}

		$deleted = $cron_class::remove_job( $job_id );

		\wp_safe_redirect( $this->manager_redirect( $deleted ? '1' : '0' ) );
		exit;
	}

	/**
	 * Post-action redirect URL back to the manager page.
	 *
	 * @param string $updated Updated flag (1/0).
	 * @return string
	 */
	protected function manager_redirect( $updated ) {
		return \add_query_arg(
			array(
				'page'    => self::PAGE_SLUG,
				'updated' => $updated,
			),
			\admin_url( 'admin.php' )
		);
	}

	/**
	 * Get statistics for display.
	 *
	 * @param array $jobs Array of cron jobs.
	 * @return array Statistics array.
	 */
	protected function get_statistics( $jobs ) {
		$total_jobs    = \count( $jobs );
		$active_jobs   = 0;
		$inactive_jobs = 0;
		$recurring     = 0;
		$one_off       = 0;

		foreach ( $jobs as $job ) {
			$event = \wp_get_scheduled_event( $job['hook'], $job['args'] );
			if ( $event ) {
				++$active_jobs;
			} else {
				++$inactive_jobs;
			}

			$schedule = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
			if ( 'single' === $schedule || '' === $schedule ) {
				++$one_off;
			} else {
				++$recurring;
			}
		}

		return array(
			'total'     => $total_jobs,
			'active'    => $active_jobs,
			'inactive'  => $inactive_jobs,
			'recurring' => $recurring,
			'one_off'   => $one_off,
		);
	}

	/**
	 * AJAX handler for getting cron manager statistics.
	 *
	 * @return void
	 */
	public function ajax_get_stats(): void {
		// Verify nonce.
		\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

		// Check permissions.
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_send_json_error( array( 'message' => __( 'Insufficient permissions.', 'nvoos-content-graph-ai-platform' ) ) );
		}

		$cron_class = self::cron_manager_class();
		$dlq_class  = self::dlq_class();
		$job_store  = self::job_store_class();

		if ( $cron_class ) {
			$cron_class::maybe_prune_jobs();
			$jobs = $cron_class::get_jobs();
		} else {
			$jobs = array();
		}
		$stats           = $this->get_statistics( $jobs );
		$dlq_stats       = null;
		$job_store_stats = null;

		// Get DLQ stats if available.
		if ( $dlq_class ) {
			$dlq_stats = $dlq_class::get_stats();
		}

		// Get job store stats if available (Proposal 017).
		if ( $job_store ) {
			$job_store_stats = $job_store::get_stats();
		}

		// Format jobs for AJAX response.
		$formatted_jobs = array();
		foreach ( $jobs as $job ) {
			$event           = \wp_get_scheduled_event( $job['hook'], $job['args'] );
			$next_run        = $event ? $event->timestamp : false;
			$schedule        = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
			$is_active       = (bool) $event;
			$is_recurring    = ! ( 'single' === $schedule || '' === $schedule );
			$first_timestamp = isset( $job['first_timestamp'] ) ? (int) $job['first_timestamp'] : 0;
			$was_executed    = ! $is_active && $first_timestamp > 0 && $first_timestamp < \time();

			$creator    = '';
			$created_by = isset( $job['created_by'] ) ? (int) $job['created_by'] : 0;

			if ( $created_by > 0 ) {
				$user = \get_userdata( $created_by );
				if ( $user ) {
					$creator = $user->display_name;
				}
			}

			if ( '' === $creator ) {
				$creator = __( 'System', 'nvoos-content-graph-ai-platform' );
			}

			$formatted_jobs[] = array(
				'hook'                 => $job['hook'],
				'args'                 => $job['args'],
				'schedule'             => $schedule,
				'is_active'            => $is_active,
				'is_recurring'         => $is_recurring,
				'was_executed'         => $was_executed,
				'next_run'             => $next_run,
				'next_run_formatted'   => $next_run ? \wp_date( 'Y-m-d H:i:s T', $next_run ) : null,
				'creator'              => $creator,
				'created_at_formatted' => isset( $job['created_at'] ) && $job['created_at'] ? \wp_date( 'Y-m-d H:i:s T', (int) $job['created_at'] ) : __( 'Unknown', 'nvoos-content-graph-ai-platform' ),
				'job_id'               => $job['job_id'],
				'delete_nonce'         => \wp_create_nonce( 'wp_mcp_ai_delete_cron_' . $job['job_id'] ),
				'first_timestamp'      => $first_timestamp,
			);
		}

		\wp_send_json_success(
			array(
				'stats'           => $stats,
				'jobs'            => $formatted_jobs,
				'dlq_stats'       => $dlq_stats,
				'job_store_stats' => $job_store_stats,
			)
		);
	}

	/**
	 * Render the cron manager page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			return;
		}

		$cron_class = self::cron_manager_class();
		if ( $cron_class ) {
			$cron_class::maybe_prune_jobs();
			$jobs = $cron_class::get_jobs();
		} else {
			$jobs = array();
		}
		$stats = $this->get_statistics( $jobs );
		?>
		<div class="wrap">
			<h1><?php \esc_html_e( 'NV oOS Cron Manager', 'nvoos-content-graph-ai-platform' ); ?></h1>

			<div class="wp-mcp-ai-cron-manager__notices"></div>

			<div class="auto-refresh-controls">
				<label for="toggle-auto-refresh">
					<input type="checkbox" id="toggle-auto-refresh" checked />
					<?php \esc_html_e( 'Auto-refresh (every 15 seconds)', 'nvoos-content-graph-ai-platform' ); ?>
				</label>
				<button type="button" id="refresh-cron-manager" class="button button-secondary">
					<span class="dashicons dashicons-update-alt"></span>
					<?php \esc_html_e( 'Refresh Now', 'nvoos-content-graph-ai-platform' ); ?>
				</button>
				<span class="last-refresh">
					<?php \esc_html_e( 'Last updated:', 'nvoos-content-graph-ai-platform' ); ?>
					<strong id="last-refresh-time"><?php echo \esc_html( \wp_date( 'H:i:s' ) ); ?></strong>
				</span>
			</div>

			<div class="wp-mcp-ai-cron-manager__intro">
				<p><strong><?php \esc_html_e( 'About Cron Manager', 'nvoos-content-graph-ai-platform' ); ?></strong></p>
				<p><?php \esc_html_e( 'The Cron Manager displays and manages scheduled tasks created through NV oOS AI Assistant tools. Cron events allow the assistant to schedule automated tasks to run at specific times or on recurring schedules.', 'nvoos-content-graph-ai-platform' ); ?></p>
				<p>
				<?php
				$retention_hours = self::retention_hours();

				if ( $retention_hours > 0 ) {
					if ( $retention_hours < 24 ) {
						echo \esc_html(
							\sprintf(
								/* translators: %d: number of hours */
								\_n(
									'Test jobs and completed one-time events remain visible for %d hour after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									'Test jobs and completed one-time events remain visible for %d hours after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									$retention_hours,
									'nvoos-content-graph-ai-platform'
								),
								$retention_hours
							)
						);
					} elseif ( $retention_hours >= 24 && $retention_hours < 168 ) {
						$retention_days = \floor( $retention_hours / 24 );
						echo \esc_html(
							\sprintf(
								/* translators: %d: number of days */
								\_n(
									'Test jobs and completed one-time events remain visible for %d day after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									'Test jobs and completed one-time events remain visible for %d days after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.',
									$retention_days,
									'nvoos-content-graph-ai-platform'
								),
								$retention_days
							)
						);
					} else {
						$retention_days = \floor( $retention_hours / 24 );
						echo \esc_html(
							\sprintf(
								/* translators: %d: number of days */
								__( 'Test jobs and completed one-time events remain visible for %d days after execution, then are automatically removed. You can adjust this retention period in Settings → Orchestration Layer.', 'nvoos-content-graph-ai-platform' ),
								$retention_days
							)
						);
					}
				} else {
					\esc_html_e( 'Completed one-time events are removed immediately after execution. You can enable job retention in Settings → Orchestration Layer to keep them visible for testing and verification.', 'nvoos-content-graph-ai-platform' );
				}
				?>
				</p>
			</div>

			<?php
			// Display update status message if present in query string.
			// Nonce verification not required as this is a read-only display of status after redirect.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
			if ( isset( $_GET['updated'] ) ) :
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only query parameter for admin notice display.
				$updated = \sanitize_key( \wp_unslash( $_GET['updated'] ) );
				if ( '1' === $updated ) :
					?>
					<div class="notice notice-success is-dismissible">
						<p><?php \esc_html_e( 'Cron event successfully removed and unscheduled from WordPress Cron.', 'nvoos-content-graph-ai-platform' ); ?></p>
					</div>
					<?php
				elseif ( '0' === $updated ) :
					?>
					<div class="notice notice-error is-dismissible">
						<p><?php \esc_html_e( 'The cron event could not be removed. It may have already completed and been removed automatically, or it may not exist.', 'nvoos-content-graph-ai-platform' ); ?></p>
					</div>
					<?php
				endif;
			endif;
			?>

			<?php if ( ! empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__stats">
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php \esc_html_e( 'Total Events', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="total"><?php echo \esc_html( $stats['total'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php \esc_html_e( 'Active', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="active"><?php echo \esc_html( $stats['active'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php \esc_html_e( 'Recurring', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="recurring"><?php echo \esc_html( $stats['recurring'] ); ?></div>
					</div>
					<div class="wp-mcp-ai-cron-manager__stat">
						<div class="wp-mcp-ai-cron-manager__stat-label"><?php \esc_html_e( 'One-off', 'nvoos-content-graph-ai-platform' ); ?></div>
						<div class="wp-mcp-ai-cron-manager__stat-value" data-stat="one_off"><?php echo \esc_html( $stats['one_off'] ); ?></div>
					</div>
				</div>
			<?php endif; ?>

			<?php
			// Show DLQ and SLA statistics if classes are available.
			if ( self::dlq_class() || self::sla_class() ) :
				$this->render_dlq_sla_stats();
			endif;

			// Show job store statistics (Proposal 017 — persistent job tracking).
			if ( self::job_store_class() ) :
				$this->render_job_store_section();
			endif;
			?>

			<?php if ( empty( $jobs ) ) : ?>
				<div class="wp-mcp-ai-cron-manager__empty">
					<h3><?php \esc_html_e( 'No Scheduled Events', 'nvoos-content-graph-ai-platform' ); ?></h3>
					<p><?php \esc_html_e( 'No cron events have been scheduled through NV oOS yet. The AI Assistant can create scheduled tasks using the following tools:', 'nvoos-content-graph-ai-platform' ); ?></p>
					<ul>
						<li><strong>create_cron_job</strong> - <?php \esc_html_e( 'Schedule a new one-time or recurring task', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><strong>list_cron_jobs</strong> - <?php \esc_html_e( 'View all scheduled tasks', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><strong>get_cron_job</strong> - <?php \esc_html_e( 'Get details about a specific scheduled task', 'nvoos-content-graph-ai-platform' ); ?></li>
						<li><strong>delete_cron_job</strong> - <?php \esc_html_e( 'Remove a scheduled task', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ul>
					<p><?php \esc_html_e( 'Once the assistant creates scheduled events, they will appear here immediately for monitoring and management. Test jobs and completed one-time events will remain visible for the configured retention period, allowing you to verify successful execution.', 'nvoos-content-graph-ai-platform' ); ?></p>
				</div>
			<?php else : ?>
				<table class="wp-mcp-ai-cron-manager__table" id="cron-jobs-table">
					<thead>
						<tr>
							<th scope="col"><?php \esc_html_e( 'Hook', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Status', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Next Run', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Schedule Type', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Arguments', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Created By', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Created At', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th scope="col"><?php \esc_html_e( 'Actions', 'nvoos-content-graph-ai-platform' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<?php foreach ( $jobs as $job ) : ?>
							<?php
							$event           = \wp_get_scheduled_event( $job['hook'], $job['args'] );
							$next_run        = $event ? $event->timestamp : false;
							$schedule        = isset( $job['schedule'] ) ? $job['schedule'] : 'single';
							$is_active       = (bool) $event;
							$is_recurring    = ! ( 'single' === $schedule || '' === $schedule );
							$first_timestamp = isset( $job['first_timestamp'] ) ? (int) $job['first_timestamp'] : 0;

							// Determine if job was executed (not active but timestamp is in the past).
							$was_executed = ! $is_active && $first_timestamp > 0 && $first_timestamp < \time();

							$creator    = '';
							$created_by = isset( $job['created_by'] ) ? (int) $job['created_by'] : 0;

							if ( $created_by > 0 ) {
								$user = \get_userdata( $created_by );
								if ( $user ) {
									$creator = $user->display_name;
								}
							}

							if ( '' === $creator ) {
								$creator = __( 'System', 'nvoos-content-graph-ai-platform' );
							}

							$created_at   = isset( $job['created_at'] ) && $job['created_at'] ? \wp_date( 'Y-m-d H:i:s T', (int) $job['created_at'] ) : __( 'Unknown', 'nvoos-content-graph-ai-platform' );
							$args_display = \wp_json_encode( $job['args'], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
							?>
							<tr>
								<td><code><?php echo \esc_html( $job['hook'] ); ?></code></td>
								<td>
									<?php if ( $is_active ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--active"><?php \esc_html_e( 'Active', 'nvoos-content-graph-ai-platform' ); ?></span>
									<?php elseif ( $was_executed ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--executed"><?php \esc_html_e( 'Executed', 'nvoos-content-graph-ai-platform' ); ?></span>
									<?php else : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--inactive"><?php \esc_html_e( 'Inactive', 'nvoos-content-graph-ai-platform' ); ?></span>
									<?php endif; ?>
								</td>
								<td>
									<?php
									if ( $next_run ) {
										$time_diff = \human_time_diff( \time(), $next_run );
										if ( $next_run > \time() ) {
											/* translators: %s: human-readable time difference */
											echo \esc_html( \sprintf( __( 'In %s', 'nvoos-content-graph-ai-platform' ), $time_diff ) );
										} else {
											/* translators: %s: human-readable time difference */
											echo \esc_html( \sprintf( __( '%s ago', 'nvoos-content-graph-ai-platform' ), $time_diff ) );
										}
										echo '<br><small>' . \esc_html( \wp_date( 'Y-m-d H:i:s T', $next_run ) ) . '</small>';
									} elseif ( $was_executed && $first_timestamp > 0 ) {
										// Show when the job was scheduled to run for executed jobs.
										$time_diff = \human_time_diff( $first_timestamp, \time() );
										/* translators: %s: human-readable time difference */
										echo \esc_html( \sprintf( __( 'Ran %s ago', 'nvoos-content-graph-ai-platform' ), $time_diff ) );
										echo '<br><small>' . \esc_html( \wp_date( 'Y-m-d H:i:s T', $first_timestamp ) ) . '</small>';
									} else {
										\esc_html_e( 'Not scheduled', 'nvoos-content-graph-ai-platform' );
									}
									?>
								</td>
								<td>
									<?php if ( $is_recurring ) : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--recurring"><?php \esc_html_e( 'Recurring', 'nvoos-content-graph-ai-platform' ); ?></span>
										<br><small><?php echo \esc_html( $schedule ); ?></small>
									<?php else : ?>
										<span class="wp-mcp-ai-cron-manager__status wp-mcp-ai-cron-manager__status--oneoff"><?php \esc_html_e( 'One-off', 'nvoos-content-graph-ai-platform' ); ?></span>
									<?php endif; ?>
								</td>
								<td class="wp-mcp-ai-cron-manager__args">
									<?php
									if ( empty( $job['args'] ) ) {
										echo '<em>' . \esc_html__( 'None', 'nvoos-content-graph-ai-platform' ) . '</em>';
									} else {
										echo \esc_html( $args_display );
									}
									?>
								</td>
								<td><?php echo \esc_html( $creator ); ?></td>
								<td><?php echo \esc_html( $created_at ); ?></td>
								<td class="wp-mcp-ai-cron-manager__actions">
									<form method="post" action="<?php echo \esc_url( \admin_url( 'admin-post.php' ) ); ?>" onsubmit="return confirm('<?php echo \esc_js( __( 'Are you sure you want to delete this cron event? This action cannot be undone.', 'nvoos-content-graph-ai-platform' ) ); ?>');">
										<input type="hidden" name="action" value="wp_mcp_ai_delete_cron" />
										<input type="hidden" name="job_id" value="<?php echo \esc_attr( $job['job_id'] ); ?>" />
										<?php \wp_nonce_field( 'wp_mcp_ai_delete_cron_' . $job['job_id'] ); ?>
										<?php \submit_button( __( 'Delete', 'nvoos-content-graph-ai-platform' ), 'delete', '', false ); ?>
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

	/**
	 * Render DLQ and SLA statistics section.
	 *
	 * @return void
	 */
	protected function render_dlq_sla_stats() {
		$dlq_class = self::dlq_class();
		$sla_class = self::sla_class();
		?>
		<div class="wp-mcp-ai-cron-manager__intro" style="margin-top:2rem;">
			<h2 style="margin-top:0;"><?php \esc_html_e( 'Job Queue Health', 'nvoos-content-graph-ai-platform' ); ?></h2>

			<?php if ( $dlq_class ) : ?>
				<?php
				$dlq_stats = $dlq_class::get_stats();
				if ( $dlq_stats['total'] > 0 ) :
					?>
					<div style="padding:1rem;background:#fff3cd;border-left:4px solid #ffc107;margin-bottom:1rem;">
						<strong><?php \esc_html_e( 'Dead Letter Queue', 'nvoos-content-graph-ai-platform' ); ?></strong>
						<p style="margin:0.5rem 0 0 0;">
							<?php
							\printf(
								/* translators: 1: total items, 2: active items, 3: dismissed items */
								\esc_html__( '%1$d failed items in queue (%2$d active, %3$d dismissed)', 'nvoos-content-graph-ai-platform' ),
								(int) $dlq_stats['total'],
								(int) $dlq_stats['active'],
								(int) $dlq_stats['dismissed']
							);
							?>
							<?php if ( ! empty( $dlq_stats['by_type'] ) ) : ?>
								<br>
								<?php
								$type_labels = array(
									'webhook'    => __( 'Webhooks', 'nvoos-content-graph-ai-platform' ),
									'cron_job'   => __( 'Cron Jobs', 'nvoos-content-graph-ai-platform' ),
									'async_tool' => __( 'Async Tools', 'nvoos-content-graph-ai-platform' ),
									'job_queue'  => __( 'Queue Jobs', 'nvoos-content-graph-ai-platform' ),
								);
								$type_parts  = array();
								foreach ( $dlq_stats['by_type'] as $type => $count ) {
									$label        = isset( $type_labels[ $type ] ) ? $type_labels[ $type ] : $type;
									$type_parts[] = \sprintf( '%s: %d', \esc_html( $label ), $count );
								}
								echo \esc_html( \implode( ', ', $type_parts ) );
								?>
							<?php endif; ?>
						</p>
						<p style="margin:0.5rem 0 0 0;">
							<a href="<?php echo \esc_url( self::dlq_manager_url() ); ?>" class="button button-secondary">
								<?php \esc_html_e( 'View Dead Letter Queue →', 'nvoos-content-graph-ai-platform' ); ?>
							</a>
						</p>
					</div>
				<?php else : ?>
					<div style="padding:1rem;background:#d4edda;border-left:4px solid #28a745;margin-bottom:1rem;">
						<strong><?php \esc_html_e( 'Dead Letter Queue', 'nvoos-content-graph-ai-platform' ); ?></strong>
						<p style="margin:0.5rem 0 0 0;">
							✓ <?php \esc_html_e( 'No failed items - all jobs completing successfully', 'nvoos-content-graph-ai-platform' ); ?>
						</p>
					</div>
				<?php endif; ?>
			<?php endif; ?>

			<?php if ( $sla_class && $sla_class::is_enabled() ) : ?>
				<div style="padding:1rem;background:#e7f3ff;border-left:4px solid #2271b1;margin-bottom:1rem;">
					<strong><?php \esc_html_e( 'SLA Prioritization', 'nvoos-content-graph-ai-platform' ); ?></strong>
					<p style="margin:0.5rem 0;">
						<?php \esc_html_e( 'Jobs are automatically prioritized into tiers based on latency requirements:', 'nvoos-content-graph-ai-platform' ); ?>
					</p>
					<?php
					$tiers_info = $sla_class::get_all_tiers_info();
					?>
					<table style="width:100%;margin-top:0.5rem;border-collapse:collapse;">
						<tr style="background:#f0f6fc;">
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php \esc_html_e( 'Tier', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php \esc_html_e( 'Priority', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php \esc_html_e( 'SLA Target', 'nvoos-content-graph-ai-platform' ); ?></th>
							<th style="padding:0.5rem;text-align:left;border:1px solid #ddd;"><?php \esc_html_e( 'Max Concurrent', 'nvoos-content-graph-ai-platform' ); ?></th>
						</tr>
						<?php foreach ( $tiers_info as $tier => $info ) : ?>
							<tr>
								<td style="padding:0.5rem;border:1px solid #ddd;">
									<strong><?php echo \esc_html( \ucfirst( \str_replace( '_', ' ', $tier ) ) ); ?></strong>
								</td>
								<td style="padding:0.5rem;border:1px solid #ddd;"><?php echo \esc_html( $info['priority'] ); ?></td>
								<td style="padding:0.5rem;border:1px solid #ddd;"><?php echo \esc_html( $info['sla_target'] ); ?>s</td>
								<td style="padding:0.5rem;border:1px solid #ddd;"><?php echo \esc_html( $info['concurrent'] ); ?></td>
							</tr>
						<?php endforeach; ?>
					</table>

					<?php
					// Show tuning recommendations if there are issues.
					$recommendations = $sla_class::get_tuning_recommendations();
					$has_issues      = false;
					foreach ( $recommendations as $rec ) {
						if ( 'ok' !== $rec['status'] ) {
							$has_issues = true;
							break;
						}
					}
					?>

					<?php if ( $has_issues ) : ?>
						<div style="margin-top:1rem;padding:0.75rem;background:#fff3cd;border:1px solid #ffc107;border-radius:4px;">
							<strong><?php \esc_html_e( '⚠️ Tuning Recommendations:', 'nvoos-content-graph-ai-platform' ); ?></strong>
							<ul style="margin:0.5rem 0 0 1.5rem;padding:0;">
								<?php foreach ( $recommendations as $rec ) : ?>
									<?php if ( 'ok' !== $rec['status'] ) : ?>
										<li>
											<strong><?php echo \esc_html( \ucfirst( $rec['tier'] ) ); ?>:</strong>
											<?php echo \esc_html( $rec['message'] ); ?>
										</li>
									<?php endif; ?>
								<?php endforeach; ?>
							</ul>
						</div>
					<?php endif; ?>
				</div>
			<?php endif; ?>
		</div>
		<?php
	}

	/**
	 * Render the Job Store section showing persistent job tracking stats.
	 *
	 * @since 2.0.0
	 * @return void
	 */
	protected function render_job_store_section() {
		$job_store = self::job_store_class();
		if ( null === $job_store ) {
			return;
		}

		$stats = $job_store::get_stats();

		if ( 0 === $stats['total'] ) {
			return;
		}
		?>
		<div class="wp-mcp-ai-cron-manager__intro" style="margin-top:2rem;">
			<h2 style="margin-top:0;"><?php \esc_html_e( 'Job Store', 'nvoos-content-graph-ai-platform' ); ?></h2>
			<p><?php \esc_html_e( 'Persistent job tracking for async operations enqueued via the QueueClient. The job store is the canonical source of truth for job status regardless of transport (Action Scheduler, WP-Cron, or RabbitMQ).', 'nvoos-content-graph-ai-platform' ); ?></p>

			<div style="display:flex;gap:1rem;margin:1rem 0;flex-wrap:wrap;">
				<?php
				$statuses = array(
					'queued'    => array(
						'label' => __( 'Queued', 'nvoos-content-graph-ai-platform' ),
						'color' => '#e5f2ff',
						'text'  => '#0c5ba0',
					),
					'running'   => array(
						'label' => __( 'Running', 'nvoos-content-graph-ai-platform' ),
						'color' => '#fff3cd',
						'text'  => '#856404',
					),
					'completed' => array(
						'label' => __( 'Completed', 'nvoos-content-graph-ai-platform' ),
						'color' => '#d4edda',
						'text'  => '#155724',
					),
					'failed'    => array(
						'label' => __( 'Failed', 'nvoos-content-graph-ai-platform' ),
						'color' => '#f8d7da',
						'text'  => '#721c24',
					),
					'cancelled' => array(
						'label' => __( 'Cancelled', 'nvoos-content-graph-ai-platform' ),
						'color' => '#e2e3e5',
						'text'  => '#383d41',
					),
				);

				foreach ( $statuses as $status => $meta ) :
					$count = isset( $stats[ $status ] ) ? (int) $stats[ $status ] : 0;
					?>
					<div style="
						background:<?php echo \esc_attr( $meta['color'] ); ?>;
						border:1px solid <?php echo \esc_attr( $meta['text'] ); ?>;
						border-radius:6px;
						padding:0.75rem 1rem;
						min-width:100px;
						text-align:center;
					">
						<div style="font-size:1.5rem;font-weight:700;color:<?php echo \esc_attr( $meta['text'] ); ?>;"><?php echo \esc_html( (string) $count ); ?></div>
						<div style="font-size:0.8rem;color:<?php echo \esc_attr( $meta['text'] ); ?>;margin-top:0.25rem;"><?php echo \esc_html( $meta['label'] ); ?></div>
					</div>
					<?php
				endforeach;
				?>

				<div style="
					background:#f0f6fc;
					border:1px solid #2271b1;
					border-radius:6px;
					padding:0.75rem 1rem;
					min-width:100px;
					text-align:center;
				">
					<div style="font-size:1.5rem;font-weight:700;color:#2271b1;"><?php echo \esc_html( (string) $stats['total'] ); ?></div>
					<div style="font-size:0.8rem;color:#2271b1;margin-top:0.25rem;"><?php \esc_html_e( 'Total', 'nvoos-content-graph-ai-platform' ); ?></div>
				</div>
			</div>

			<p style="font-size:0.85rem;color:#646970;">
				<?php \esc_html_e( 'The job store provides durable, transport-agnostic tracking. Job IDs use UUID format (job_ prefix). Status is updated in real-time as jobs move through queued → running → completed/failed lifecycle.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>
		</div>
		<?php
	}
}
