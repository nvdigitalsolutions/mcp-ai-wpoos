<?php
/**
 * Run Timeline dashboard (Wave E-UI-1, sub-cluster 4).
 *
 * Aligned port of the base plugin's `WP_MCP_AI_Admin_Run_Timeline`
 * (`includes/admin/class-wp-mcp-ai-admin-run-timeline.php`):
 * byte-identical dashboard surface — the `mcp-ai-run-timeline` page
 * slug, the two AJAX actions (`wp_mcp_ai_run_timeline_get_run`,
 * `wp_mcp_ai_run_timeline_list_runs`) with the
 * `wp_mcp_ai_run_timeline` nonce, the `wpMcpAiRunTimeline` localized
 * config envelope (incl. the six-string i18n block), the
 * `RUNS_PER_PAGE` (20) and `CACHE_PREFIX`
 * (`wp_mcp_ai_run_timeline_`) constants, the render surface (sidebar
 * run list + filters + pagination + empty-state detail pane), the
 * OTel configuration notice, the assistant filter options, the
 * metric-store-driven run summaries (grouped by `context.run_id`,
 * started-at ordering, token/cost/latency aggregation, pagination
 * math) with the reasoning-trace post-meta fallback scan, the full
 * run detail loader (per-step metric rows, meta-trace hydration, the
 * `wp_mcp_ai_run_timeline_detail` filter), and the try/catch AJAX
 * handlers with their 403/400/404/500 envelopes.
 *
 * Documented deviations:
 *  - Class name/namespace — the platform addon's PSR-4 tree (decision
 *    D-UI/E-UI: operator admin UI ports land in
 *    `nvoos-content-graph-ai-platform`).
 *  - The base's constructor-driven hook wiring becomes a static
 *    `register()` — wired standalone-only via `Plugin::registerAdmin()`;
 *    the base admin owns the same page under the base settings
 *    dashboard menu monolith. Standalone the page registers under the
 *    platform's "NV Platform" menu (`ai-platform-dashboard`).
 *  - Collaborators resolve per install mode
 *    (`defined( 'WP_MCP_AI_PATH' )` discriminator): the metric event
 *    store via the base `WP_MCP_AI_Metric_Event_Store` monolith / the
 *    platform's `Measurement\MetricEventStore` standalone (same
 *    `get_instance()`/`table_exists()`/`query_by_metric()` contract);
 *    the reasoning trace via the base `WP_MCP_AI_Reasoning_Trace`
 *    monolith / the platform's `Harness\ReasoningTrace` standalone
 *    (byte-identical `META_KEY` + `sanitize()`); the OTel exporter
 *    probe is monolith-only (standalone always shows the configure
 *    notice — documented); the observability settings link resolves
 *    per mode via the `observability_settings_url()` seam (monolith
 *    byte-identical `admin.php?page=wp-mcp-ai-dashboard&tab=
 *    orchestration&view=observability` / standalone the NV Platform
 *    dashboard page — documented).
 *  - The `class_exists( 'WP_MCP_AI_Logger' )` guards in the safe-log
 *    helpers are kept byte-identical — they resolve false standalone,
 *    so logging is a dormant no-op there (documented).
 *  - The base's `private` helpers become `protected` — widening
 *    visibility is additive and lets the characterization suite expose
 *    them without reflection (documented deviation).
 *  - The dashboard's own assets (admin-run-timeline.css/.js) are
 *    copied byte-identically into the platform asset tree; the base's
 *    version-constant asset versioning resolves through the platform
 *    constants.
 *  - Text domain `nvoos-content-graph-ai-platform`.
 *
 * @since 2.0.0
 * @package NvoosContentGraphAiPlatform\Admin\Dashboards
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\Admin\Dashboards;

/**
 * Run Timeline admin page.
 *
 * Per-request observability for chat runs: token usage, tool latency,
 * cost breakdown, and harness layer activations.
 *
 * @since 2.0.0
 */
class RunTimelineDashboard {

	/**
	 * How many recent runs to show per page.
	 *
	 * @var int
	 */
	const RUNS_PER_PAGE = 20;

	/**
	 * Transient prefix for cached run summaries.
	 *
	 * @var string
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_run_timeline_';

	/**
	 * Admin page slug (byte-identical public surface).
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'mcp-ai-run-timeline';

	/**
	 * Nonce action for the dashboard AJAX handlers.
	 *
	 * @var string
	 */
	const NONCE_ACTION = 'wp_mcp_ai_run_timeline';

	/**
	 * Register the dashboard hooks (standalone-only — see the class docblock).
	 *
	 * @return void
	 */
	public function register(): void {
		\add_action( 'admin_menu', array( $this, 'add_menu_page' ), 25 );
		\add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_run_timeline_get_run', array( $this, 'ajax_get_run' ) );
		\add_action( 'wp_ajax_wp_mcp_ai_run_timeline_list_runs', array( $this, 'ajax_list_runs' ) );
	}

	/**
	 * Register the submenu page.
	 *
	 * @return void
	 */
	public function add_menu_page(): void {
		\add_submenu_page(
			\NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG,
			__( 'Run Timeline', 'nvoos-content-graph-ai-platform' ),
			__( 'Run Timeline', 'nvoos-content-graph-ai-platform' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 * @return void
	 */
	public function enqueue_assets( $hook ): void {
		if ( false === \strpos( $hook, self::PAGE_SLUG ) ) {
			return;
		}

		\wp_enqueue_style(
			'wp-mcp-ai-run-timeline',
			self::asset_url( 'css/admin-run-timeline.css' ),
			array(),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION
		);

		\wp_enqueue_script(
			'wp-mcp-ai-run-timeline',
			self::asset_url( 'js/admin-run-timeline.js' ),
			array( 'jquery' ),
			NVOOS_CONTENT_GRAPH_AI_PLATFORM_VERSION,
			true
		);

		\wp_localize_script(
			'wp-mcp-ai-run-timeline',
			'wpMcpAiRunTimeline',
			array(
				'ajaxUrl' => \admin_url( 'admin-ajax.php' ),
				'nonce'   => \wp_create_nonce( self::NONCE_ACTION ),
				'i18n'    => array(
					'loading'      => __( 'Loading…', 'nvoos-content-graph-ai-platform' ),
					'noRuns'       => __( 'No runs recorded yet.', 'nvoos-content-graph-ai-platform' ),
					'downloadJSON' => __( 'Download JSON', 'nvoos-content-graph-ai-platform' ),
					'tokens'       => __( 'tokens', 'nvoos-content-graph-ai-platform' ),
					'ms'           => __( 'ms', 'nvoos-content-graph-ai-platform' ),
					'usd'          => __( 'USD', 'nvoos-content-graph-ai-platform' ),
				),
			)
		);
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
	 * Metric event store instance (per-mode seam).
	 *
	 * Monolith resolves the base store; standalone the platform's
	 * `Measurement\MetricEventStore` (same contract).
	 *
	 * @return object|null
	 */
	protected static function metric_event_store() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			if ( \class_exists( 'WP_MCP_AI_Metric_Event_Store' ) ) {
				return \WP_MCP_AI_Metric_Event_Store::get_instance();
			}

			return null;
		}

		if ( \class_exists( 'NvoosContentGraphAiPlatform\Measurement\MetricEventStore' ) ) {
			return \NvoosContentGraphAiPlatform\Measurement\MetricEventStore::get_instance();
		}

		return null;
	}

	/**
	 * Reasoning trace class name (per-mode seam).
	 *
	 * @return string|null
	 */
	protected static function reasoning_trace_class() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return \class_exists( 'WP_MCP_AI_Reasoning_Trace' ) ? 'WP_MCP_AI_Reasoning_Trace' : null;
		}

		return \class_exists( 'NvoosContentGraphAiPlatform\Harness\ReasoningTrace' ) ? 'NvoosContentGraphAiPlatform\Harness\ReasoningTrace' : null;
	}

	/**
	 * OTel exporter availability (per-mode seam).
	 *
	 * Monolith probes the base exporter; standalone has no ported
	 * exporter yet, so the configure notice always renders (documented).
	 *
	 * @return bool
	 */
	protected static function otel_enabled() {
		if ( defined( 'WP_MCP_AI_PATH' ) && \class_exists( 'WP_MCP_AI_Otel_Span_Exporter' ) ) {
			return \WP_MCP_AI_Otel_Span_Exporter::is_enabled();
		}

		return false;
	}

	/**
	 * Observability settings URL (per-mode seam).
	 *
	 * Monolith byte-identical base settings URL; standalone degrades to
	 * the NV Platform dashboard page (no observability settings tab
	 * exists standalone — documented).
	 *
	 * @return string
	 */
	protected static function observability_settings_url() {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return \admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration&view=observability' );
		}

		return \admin_url( 'admin.php?page=' . \NvoosContentGraphAiPlatform\Admin\PlatformDashboard::PAGE_SLUG );
	}

	/**
	 * Render the page.
	 *
	 * @return void
	 */
	public function render_page(): void {
		if ( ! \current_user_can( 'manage_options' ) ) {
			\wp_die( \esc_html__( 'You do not have sufficient permissions to access this page.', 'nvoos-content-graph-ai-platform' ) );
		}
		?>
		<div class="wrap wp-mcp-ai-run-timeline">
			<h1><?php \esc_html_e( 'NV oOS — Run Timeline', 'nvoos-content-graph-ai-platform' ); ?></h1>
			<p class="description">
				<?php \esc_html_e( 'Per-request observability for chat runs: token usage, tool latency, cost breakdown, and harness layer activations.', 'nvoos-content-graph-ai-platform' ); ?>
			</p>

			<?php $this->render_otel_notice(); ?>

			<div id="wp-mcp-ai-run-timeline-app">
				<div class="rt-sidebar">
					<h2><?php \esc_html_e( 'Recent Runs', 'nvoos-content-graph-ai-platform' ); ?></h2>
					<div class="rt-filters">
						<label for="rt-filter-assistant"><?php \esc_html_e( 'Assistant:', 'nvoos-content-graph-ai-platform' ); ?></label>
						<select id="rt-filter-assistant">
							<option value=""><?php \esc_html_e( '— All —', 'nvoos-content-graph-ai-platform' ); ?></option>
							<?php $this->render_assistant_options(); ?>
						</select>
					</div>
					<ul id="rt-run-list" class="rt-run-list">
						<li class="rt-loading"><?php \esc_html_e( 'Loading…', 'nvoos-content-graph-ai-platform' ); ?></li>
					</ul>
					<div id="rt-pagination"></div>
				</div>
				<div class="rt-detail" id="rt-detail">
					<div class="rt-empty-state">
						<span class="dashicons dashicons-chart-area"></span>
						<p><?php \esc_html_e( 'Select a run from the list to view its timeline.', 'nvoos-content-graph-ai-platform' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a notice prompting to configure OTel if not set.
	 *
	 * @return void
	 */
	protected function render_otel_notice() {
		if ( self::otel_enabled() ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				$settings_url = \esc_url( self::observability_settings_url() );
				$tip_text     = \sprintf(
					/* translators: 1: opening strong tag, 2: closing strong tag, 3: opening anchor tag with URL, 4: closing anchor tag */
					__( '%1$sTip:%2$s Configure an OTLP endpoint in %3$sNV oOS Settings → Observability%4$s to export spans to Jaeger, Grafana Tempo, or any OpenTelemetry collector.', 'nvoos-content-graph-ai-platform' ),
					'<strong>',
					'</strong>',
					'<a href="' . \esc_url( $settings_url ) . '">',
					'</a>'
				);
				echo \wp_kses(
					$tip_text,
					array(
						'strong' => array(),
						'a'      => array( 'href' => array() ),
					)
				);
				?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render assistant <option> elements for the filter dropdown.
	 *
	 * @return void
	 */
	protected function render_assistant_options() {
		$assistants = \get_posts(
			array(
				'post_type'      => 'mcp_ai_assistant',
				'post_status'    => 'publish',
				'posts_per_page' => 100,
				'orderby'        => 'title',
				'order'          => 'ASC',
				'fields'         => 'ids',
			)
		);

		foreach ( $assistants as $id ) {
			$title = \get_the_title( $id );
			\printf(
				'<option value="%s">%s</option>',
				\esc_attr( $id ),
				\esc_html( $title )
			);
		}
	}

	/**
	 * AJAX: list recent runs (lightweight summaries).
	 *
	 * @return void
	 */
	public function ajax_list_runs(): void {
		try {
			\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! \current_user_can( 'manage_options' ) ) {
				$this->log_warning_safe(
					'Run Timeline: permission denied for ajax_list_runs.',
					array( 'user_id' => \get_current_user_id() )
				);
				\wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) ), 403 );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination/filter params, no state change.
			$page         = \max( 1, (int) \sanitize_text_field( \wp_unslash( isset( $_GET['page'] ) ? $_GET['page'] : '1' ) ) );
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only pagination/filter params, no state change.
			$assistant_id = (int) \sanitize_text_field( \wp_unslash( isset( $_GET['assistant_id'] ) ? $_GET['assistant_id'] : '0' ) );

			$runs = $this->load_run_summaries( $page, $assistant_id );
			\wp_send_json_success( $runs );
		} catch ( \Throwable $e ) {
			$this->log_error_safe(
				'Run Timeline: ajax_list_runs threw an exception.',
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
			\wp_send_json_error(
				array(
					'message' => \sprintf(
						/* translators: %s: error message */
						__( 'Run Timeline failed to load runs: %s', 'nvoos-content-graph-ai-platform' ),
						$e->getMessage()
					),
				),
				500
			);
		}
	}

	/**
	 * AJAX: get full detail for a single run.
	 *
	 * @return void
	 */
	public function ajax_get_run(): void {
		try {
			\check_ajax_referer( self::NONCE_ACTION, 'nonce' );

			if ( ! \current_user_can( 'manage_options' ) ) {
				$this->log_warning_safe(
					'Run Timeline: permission denied for ajax_get_run.',
					array( 'user_id' => \get_current_user_id() )
				);
				\wp_send_json_error( array( 'message' => __( 'Permission denied.', 'nvoos-content-graph-ai-platform' ) ), 403 );
			}

			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only run-id param, no state change.
			$run_id = \sanitize_text_field( \wp_unslash( $_GET['run_id'] ?? '' ) );
			if ( '' === $run_id ) {
				$this->log_warning_safe( 'Run Timeline: ajax_get_run missing run_id.' );
				\wp_send_json_error( array( 'message' => __( 'run_id is required.', 'nvoos-content-graph-ai-platform' ) ), 400 );
			}

			$detail = $this->load_run_detail( $run_id );
			if ( null === $detail ) {
				$this->log_warning_safe(
					'Run Timeline: run not found.',
					array( 'run_id' => $run_id )
				);
				\wp_send_json_error( array( 'message' => __( 'Run not found.', 'nvoos-content-graph-ai-platform' ) ), 404 );
			}

			\wp_send_json_success( $detail );
		} catch ( \Throwable $e ) {
			$this->log_error_safe(
				'Run Timeline: ajax_get_run threw an exception.',
				array(
					// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- Read-only run-id param, no state change.
					'run_id'  => isset( $_GET['run_id'] ) ? \sanitize_text_field( \wp_unslash( $_GET['run_id'] ) ) : '',
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
			\wp_send_json_error(
				array(
					'message' => \sprintf(
						/* translators: %s: error message */
						__( 'Run Timeline failed to load run detail: %s', 'nvoos-content-graph-ai-platform' ),
						$e->getMessage()
					),
				),
				500
			);
		}
	}

	/**
	 * Defensively log an error through WP_MCP_AI_Logger when available.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	protected function log_error_safe( $message, $context = array() ) {
		if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_error( $message, $context );
		}
	}

	/**
	 * Defensively log a warning through WP_MCP_AI_Logger when available.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 * @return void
	 */
	protected function log_warning_safe( $message, $context = array() ) {
		if ( \class_exists( 'WP_MCP_AI_Logger' ) ) {
			\WP_MCP_AI_Logger::log_warning( $message, $context );
		}
	}

	/**
	 * Load lightweight run summaries from the metric event store and
	 * reasoning trace post meta.
	 *
	 * @param int $page         Page number.
	 * @param int $assistant_id Filter by assistant (0 = all).
	 * @return array{runs: array, total: int, page: int, per_page: int}
	 */
	protected function load_run_summaries( $page, $assistant_id ) {
		$summaries = array();

		// Pull from the metric event store if available and the table is provisioned.
		$store = self::metric_event_store();
		if ( $store && \method_exists( $store, 'table_exists' ) && $store->table_exists() ) {
			$metric_ids = array( 'chat.turn.latency_ms', 'token_usage.total_tokens', 'cost.usd' );
			$row_cap    = self::RUNS_PER_PAGE * 3;

			$grouped = array();
			foreach ( $metric_ids as $metric_id ) {
				$rows = $store->query_by_metric( $metric_id, null, null, $row_cap );
				foreach ( $rows as $row ) {
					$context = isset( $row['context'] ) && \is_array( $row['context'] ) ? $row['context'] : array();
					$run_id  = isset( $context['run_id'] ) ? (string) $context['run_id'] : '';
					if ( '' === $run_id ) {
						continue;
					}
					$row_assistant = isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0;
					if ( $assistant_id > 0 && $row_assistant !== $assistant_id ) {
						continue;
					}
					$recorded_at = isset( $row['recorded_at'] ) ? \strtotime( (string) $row['recorded_at'] . ' UTC' ) : 0;
					if ( ! isset( $grouped[ $run_id ] ) ) {
						$grouped[ $run_id ] = array(
							'run_id'       => $run_id,
							'assistant_id' => $row_assistant,
							'started_at'   => $recorded_at ? $recorded_at : 0,
							'total_tokens' => 0,
							'cost_usd'     => 0.0,
							'latency_ms'   => 0,
						);
					} elseif ( $recorded_at && ( 0 === $grouped[ $run_id ]['started_at'] || $recorded_at < $grouped[ $run_id ]['started_at'] ) ) {
						$grouped[ $run_id ]['started_at'] = $recorded_at;
					}
					switch ( (string) ( $row['metric_id'] ?? '' ) ) {
						case 'token_usage.total_tokens':
							$grouped[ $run_id ]['total_tokens'] += (int) ( $row['metric_value'] ?? 0 );
							break;
						case 'cost.usd':
							$grouped[ $run_id ]['cost_usd'] += (float) ( $row['metric_value'] ?? 0.0 );
							break;
						case 'chat.turn.latency_ms':
							$grouped[ $run_id ]['latency_ms'] = (int) ( $row['metric_value'] ?? 0 );
							break;
					}
				}
			}

			$summaries = \array_values( $grouped );
			\usort(
				$summaries,
				static function ( $a, $b ) {
					return ( $b['started_at'] ?? 0 ) <=> ( $a['started_at'] ?? 0 );
				}
			);
		}

		// Fall back to reasoning trace post meta scan when no metric store data.
		if ( empty( $summaries ) ) {
			$summaries = $this->load_summaries_from_post_meta( $assistant_id );
		}

		$total  = \count( $summaries );
		$offset = ( $page - 1 ) * self::RUNS_PER_PAGE;
		$paged  = \array_slice( $summaries, $offset, self::RUNS_PER_PAGE );

		return array(
			'runs'     => $paged,
			'total'    => $total,
			'page'     => $page,
			'per_page' => self::RUNS_PER_PAGE,
		);
	}

	/**
	 * Fall-back: scan reasoning trace post meta for run summaries.
	 *
	 * @param int $assistant_id Filter by assistant (0 = all).
	 * @return array
	 */
	protected function load_summaries_from_post_meta( $assistant_id ) {
		$trace_class = self::reasoning_trace_class();
		if ( null === $trace_class ) {
			return array();
		}

		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => $assistant_id > 0 ? 1 : 20,
			'meta_query'     => array( // phpcs:ignore WordPress.DB.SlowDBQuery.slow_db_query_meta_query -- meta_query required to scan assistant posts for the reasoning-trace meta key; no alternative index-based query available.
				array(
					'key'     => $trace_class::META_KEY,
					'compare' => 'EXISTS',
				),
			),
		);
		if ( $assistant_id > 0 ) {
			$args['include'] = array( $assistant_id );
		}

		$posts     = \get_posts( $args );
		$summaries = array();

		foreach ( $posts as $post ) {
			$raw   = \get_post_meta( $post->ID, $trace_class::META_KEY, true );
			$trace = $trace_class::sanitize( $raw );

			$summaries[] = array(
				'run_id'       => 'meta:' . $post->ID,
				'assistant_id' => $post->ID,
				'started_at'   => $trace['created_at'] ?? 0,
				'total_tokens' => 0,
				'cost_usd'     => 0.0,
				'latency_ms'   => 0,
				'trace'        => $trace,
			);
		}

		return $summaries;
	}

	/**
	 * Load full run detail including tool call trace, cost per step, and
	 * harness layer activations.
	 *
	 * @param string $run_id Run identifier.
	 * @return array|null Detail array or null if not found.
	 */
	protected function load_run_detail( $run_id ) {
		$detail = array(
			'run_id'  => $run_id,
			'steps'   => array(),
			'summary' => array(),
			'trace'   => array(),
		);

		// Load from metric event store. Iterate the same metric ids as the
		// list view and filter rows by `context.run_id`. The store schema
		// has no top-level run_id column, so per-run queries must scan and
		// post-filter; the row cap below keeps this bounded.
		$store = self::metric_event_store();
		if ( $store && \method_exists( $store, 'table_exists' ) && $store->table_exists() ) {
			$metric_ids = array(
				'chat.turn.latency_ms',
				'token_usage.total_tokens',
				'cost.usd',
				'tool.execution.latency_ms',
				'tool.execution.errors',
			);

			$steps = array();
			foreach ( $metric_ids as $metric_id ) {
				$rows = $store->query_by_metric( $metric_id, null, null, 5000 );
				foreach ( $rows as $row ) {
					$context = isset( $row['context'] ) && \is_array( $row['context'] ) ? $row['context'] : array();
					if ( ( isset( $context['run_id'] ) ? (string) $context['run_id'] : '' ) !== $run_id ) {
						continue;
					}
					$step_key = isset( $context['step'] ) ? (string) $context['step'] : 'root';
					if ( ! isset( $steps[ $step_key ] ) ) {
						$steps[ $step_key ] = array(
							'step'        => $step_key,
							'metrics'     => array(),
							'recorded_at' => isset( $row['recorded_at'] ) ? \strtotime( (string) $row['recorded_at'] . ' UTC' ) : 0,
						);
					}
					$steps[ $step_key ]['metrics'][] = array(
						'id'    => isset( $row['metric_id'] ) ? (string) $row['metric_id'] : '',
						'value' => isset( $row['metric_value'] ) ? (float) $row['metric_value'] : null,
						'unit'  => isset( $row['metric_unit'] ) ? (string) $row['metric_unit'] : '',
					);
				}
			}
			$detail['steps'] = \array_values( $steps );
		}

		// Load reasoning trace from post meta if run_id is a meta-based ID.
		if ( 0 === \strpos( $run_id, 'meta:' ) ) {
			$post_id = (int) \substr( $run_id, 5 );
			$trace_class = self::reasoning_trace_class();
			if ( $post_id > 0 && null !== $trace_class ) {
				$raw             = \get_post_meta( $post_id, $trace_class::META_KEY, true );
				$detail['trace'] = $trace_class::sanitize( $raw );
			}
		}

		if ( empty( $detail['steps'] ) && empty( $detail['trace'] ) ) {
			return null;
		}

		/**
		 * Filter run detail before it is returned to the client.
		 *
		 * @param array  $detail Run detail array.
		 * @param string $run_id Run identifier.
		 */
		$detail = \apply_filters( 'wp_mcp_ai_run_timeline_detail', $detail, $run_id );

		return $detail;
	}
}
