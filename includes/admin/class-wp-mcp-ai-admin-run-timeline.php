<?php
/**
 * Admin Page: Run Timeline
 *
 * Renders a per-chat-run observability timeline showing per-step token usage,
 * tool execution latency, cost breakdown, and harness layer activations.
 * Reads from existing data: reasoning trace post meta, cost-tracking transients,
 * and the measurement metric event store.
 *
 * This page closes Gap 5 from the orchestration gap analysis:
 * "Observability is incomplete — no per-run timeline UI."
 *
 * @package WP_MCP_AI
 * @since 1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Run Timeline admin page.
 *
 * @since 1.5.0
 */
class WP_MCP_AI_Admin_Run_Timeline {

	/**
	 * How many recent runs to show per page.
	 */
	const RUNS_PER_PAGE = 20;

	/**
	 * Transient prefix for cached run summaries.
	 */
	const CACHE_PREFIX = 'wp_mcp_ai_run_timeline_';

	/**
	 * Constructor: register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 25 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'wp_ajax_wp_mcp_ai_run_timeline_get_run', array( $this, 'ajax_get_run' ) );
		add_action( 'wp_ajax_wp_mcp_ai_run_timeline_list_runs', array( $this, 'ajax_list_runs' ) );
	}

	/**
	 * Register the submenu page.
	 */
	public function add_menu_page() {
		add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Run Timeline', 'mcp-ai-wpoos' ),
			__( 'Run Timeline', 'mcp-ai-wpoos' ),
			'manage_options',
			'mcp-ai-run-timeline',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue admin assets.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_assets( $hook ) {
		if ( false === strpos( $hook, 'mcp-ai-run-timeline' ) ) {
			return;
		}

		wp_enqueue_style(
			'wp-mcp-ai-run-timeline',
			WP_MCP_AI_URL . 'assets/css/admin-run-timeline.css',
			array(),
			WP_MCP_AI_VERSION
		);

		wp_enqueue_script(
			'wp-mcp-ai-run-timeline',
			WP_MCP_AI_URL . 'assets/js/admin-run-timeline.js',
			array( 'jquery' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-run-timeline',
			'wpMcpAiRunTimeline',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_run_timeline' ),
				'i18n'    => array(
					'loading'      => __( 'Loading…', 'mcp-ai-wpoos' ),
					'noRuns'       => __( 'No runs recorded yet.', 'mcp-ai-wpoos' ),
					'downloadJSON' => __( 'Download JSON', 'mcp-ai-wpoos' ),
					'tokens'       => __( 'tokens', 'mcp-ai-wpoos' ),
					'ms'           => __( 'ms', 'mcp-ai-wpoos' ),
					'usd'          => __( 'USD', 'mcp-ai-wpoos' ),
				),
			)
		);
	}

	/**
	 * Render the page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'mcp-ai-wpoos' ) );
		}
		?>
		<div class="wrap wp-mcp-ai-run-timeline">
			<h1><?php esc_html_e( 'NV oOS — Run Timeline', 'mcp-ai-wpoos' ); ?></h1>
			<p class="description">
				<?php esc_html_e( 'Per-request observability for chat runs: token usage, tool latency, cost breakdown, and harness layer activations.', 'mcp-ai-wpoos' ); ?>
			</p>

			<?php $this->render_otel_notice(); ?>

			<div id="wp-mcp-ai-run-timeline-app">
				<div class="rt-sidebar">
					<h2><?php esc_html_e( 'Recent Runs', 'mcp-ai-wpoos' ); ?></h2>
					<div class="rt-filters">
						<label for="rt-filter-assistant"><?php esc_html_e( 'Assistant:', 'mcp-ai-wpoos' ); ?></label>
						<select id="rt-filter-assistant">
							<option value=""><?php esc_html_e( '— All —', 'mcp-ai-wpoos' ); ?></option>
							<?php $this->render_assistant_options(); ?>
						</select>
					</div>
					<ul id="rt-run-list" class="rt-run-list">
						<li class="rt-loading"><?php esc_html_e( 'Loading…', 'mcp-ai-wpoos' ); ?></li>
					</ul>
					<div id="rt-pagination"></div>
				</div>
				<div class="rt-detail" id="rt-detail">
					<div class="rt-empty-state">
						<span class="dashicons dashicons-chart-area"></span>
						<p><?php esc_html_e( 'Select a run from the list to view its timeline.', 'mcp-ai-wpoos' ); ?></p>
					</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render a notice prompting to configure OTel if not set.
	 */
	private function render_otel_notice() {
		if ( class_exists( 'WP_MCP_AI_Otel_Span_Exporter' ) && WP_MCP_AI_Otel_Span_Exporter::is_enabled() ) {
			return;
		}
		?>
		<div class="notice notice-info is-dismissible">
			<p>
				<?php
				$settings_url = esc_url( admin_url( 'admin.php?page=wp-mcp-ai-dashboard&tab=orchestration&view=observability' ) );
				$tip_text     = sprintf(
					/* translators: 1: opening strong tag, 2: closing strong tag, 3: opening anchor tag with URL, 4: closing anchor tag */
					__( '%1$sTip:%2$s Configure an OTLP endpoint in %3$sNV oOS Settings → Observability%4$s to export spans to Jaeger, Grafana Tempo, or any OpenTelemetry collector.', 'mcp-ai-wpoos' ),
					'<strong>',
					'</strong>',
					'<a href="' . esc_url( $settings_url ) . '">',
					'</a>'
				);
				echo wp_kses(
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
	 */
	private function render_assistant_options() {
		$assistants = get_posts(
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
			$title = get_the_title( $id );
			printf(
				'<option value="%s">%s</option>',
				esc_attr( $id ),
				esc_html( $title )
			);
		}
	}

	/**
	 * AJAX: list recent runs (lightweight summaries).
	 */
	public function ajax_list_runs() {
		try {
			check_ajax_referer( 'wp_mcp_ai_run_timeline', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				$this->log_warning_safe(
					'Run Timeline: permission denied for ajax_list_runs.',
					array( 'user_id' => get_current_user_id() )
				);
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ), 403 );
			}

			$page         = max( 1, (int) sanitize_text_field( wp_unslash( isset( $_GET['page'] ) ? $_GET['page'] : '1' ) ) );
			$assistant_id = (int) sanitize_text_field( wp_unslash( isset( $_GET['assistant_id'] ) ? $_GET['assistant_id'] : '0' ) );

			$runs = $this->load_run_summaries( $page, $assistant_id );
			wp_send_json_success( $runs );
		} catch ( Throwable $e ) {
			$this->log_error_safe(
				'Run Timeline: ajax_list_runs threw an exception.',
				array(
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Run Timeline failed to load runs: %s', 'mcp-ai-wpoos' ),
						$e->getMessage()
					),
				),
				500
			);
		}
	}

	/**
	 * AJAX: get full detail for a single run.
	 */
	public function ajax_get_run() {
		try {
			check_ajax_referer( 'wp_mcp_ai_run_timeline', 'nonce' );

			if ( ! current_user_can( 'manage_options' ) ) {
				$this->log_warning_safe(
					'Run Timeline: permission denied for ajax_get_run.',
					array( 'user_id' => get_current_user_id() )
				);
				wp_send_json_error( array( 'message' => __( 'Permission denied.', 'mcp-ai-wpoos' ) ), 403 );
			}

			$run_id = sanitize_text_field( wp_unslash( $_GET['run_id'] ?? '' ) );
			if ( '' === $run_id ) {
				$this->log_warning_safe( 'Run Timeline: ajax_get_run missing run_id.' );
				wp_send_json_error( array( 'message' => __( 'run_id is required.', 'mcp-ai-wpoos' ) ), 400 );
			}

			$detail = $this->load_run_detail( $run_id );
			if ( null === $detail ) {
				$this->log_warning_safe(
					'Run Timeline: run not found.',
					array( 'run_id' => $run_id )
				);
				wp_send_json_error( array( 'message' => __( 'Run not found.', 'mcp-ai-wpoos' ) ), 404 );
			}

			wp_send_json_success( $detail );
		} catch ( Throwable $e ) {
			$this->log_error_safe(
				'Run Timeline: ajax_get_run threw an exception.',
				array(
					'run_id'  => isset( $_GET['run_id'] ) ? sanitize_text_field( wp_unslash( $_GET['run_id'] ) ) : '',
					'message' => $e->getMessage(),
					'file'    => $e->getFile(),
					'line'    => $e->getLine(),
				)
			);
			wp_send_json_error(
				array(
					'message' => sprintf(
						/* translators: %s: error message */
						__( 'Run Timeline failed to load run detail: %s', 'mcp-ai-wpoos' ),
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
	 */
	private function log_error_safe( $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_error( $message, $context );
		}
	}

	/**
	 * Defensively log a warning through WP_MCP_AI_Logger when available.
	 *
	 * @param string $message Message.
	 * @param array  $context Optional context.
	 */
	private function log_warning_safe( $message, $context = array() ) {
		if ( class_exists( 'WP_MCP_AI_Logger' ) ) {
			WP_MCP_AI_Logger::log_warning( $message, $context );
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
	private function load_run_summaries( $page, $assistant_id ) {
		$summaries = array();

		// Pull from the metric event store if available and the table is provisioned.
		if ( class_exists( 'WP_MCP_AI_Metric_Event_Store' ) ) {
			$store = WP_MCP_AI_Metric_Event_Store::get_instance();
			if ( method_exists( $store, 'table_exists' ) && $store->table_exists() ) {
				$metric_ids = array( 'chat.turn.latency_ms', 'token_usage.total_tokens', 'cost.usd' );
				$row_cap    = self::RUNS_PER_PAGE * 3;

				$grouped = array();
				foreach ( $metric_ids as $metric_id ) {
					$rows = $store->query_by_metric( $metric_id, null, null, $row_cap );
					foreach ( $rows as $row ) {
						$context = isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array();
						$run_id  = isset( $context['run_id'] ) ? (string) $context['run_id'] : '';
						if ( '' === $run_id ) {
							continue;
						}
						$row_assistant = isset( $context['assistant_id'] ) ? (int) $context['assistant_id'] : 0;
						if ( $assistant_id > 0 && $row_assistant !== $assistant_id ) {
							continue;
						}
						$recorded_at = isset( $row['recorded_at'] ) ? strtotime( (string) $row['recorded_at'] . ' UTC' ) : 0;
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

				$summaries = array_values( $grouped );
				usort(
					$summaries,
					static function ( $a, $b ) {
						return ( $b['started_at'] ?? 0 ) <=> ( $a['started_at'] ?? 0 );
					}
				);
			}
		}

		// Fall back to reasoning trace post meta scan when no metric store data.
		if ( empty( $summaries ) ) {
			$summaries = $this->load_summaries_from_post_meta( $assistant_id );
		}

		$total  = count( $summaries );
		$offset = ( $page - 1 ) * self::RUNS_PER_PAGE;
		$paged  = array_slice( $summaries, $offset, self::RUNS_PER_PAGE );

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
	private function load_summaries_from_post_meta( $assistant_id ) {
		$args = array(
			'post_type'      => 'mcp_ai_assistant',
			'post_status'    => 'publish',
			'posts_per_page' => $assistant_id > 0 ? 1 : 20,
			'meta_query'     => array(
				array(
					'key'     => WP_MCP_AI_Reasoning_Trace::META_KEY,
					'compare' => 'EXISTS',
				),
			),
		);
		if ( $assistant_id > 0 ) {
			$args['include'] = array( $assistant_id );
		}

		$posts     = get_posts( $args );
		$summaries = array();

		foreach ( $posts as $post ) {
			$raw   = get_post_meta( $post->ID, WP_MCP_AI_Reasoning_Trace::META_KEY, true );
			$trace = WP_MCP_AI_Reasoning_Trace::sanitize( $raw );

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
	private function load_run_detail( $run_id ) {
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
		if ( class_exists( 'WP_MCP_AI_Metric_Event_Store' ) ) {
			$store = WP_MCP_AI_Metric_Event_Store::get_instance();
			if ( method_exists( $store, 'table_exists' ) && $store->table_exists() ) {
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
						$context = isset( $row['context'] ) && is_array( $row['context'] ) ? $row['context'] : array();
						if ( ( isset( $context['run_id'] ) ? (string) $context['run_id'] : '' ) !== $run_id ) {
							continue;
						}
						$step_key = isset( $context['step'] ) ? (string) $context['step'] : 'root';
						if ( ! isset( $steps[ $step_key ] ) ) {
							$steps[ $step_key ] = array(
								'step'        => $step_key,
								'metrics'     => array(),
								'recorded_at' => isset( $row['recorded_at'] ) ? strtotime( (string) $row['recorded_at'] . ' UTC' ) : 0,
							);
						}
						$steps[ $step_key ]['metrics'][] = array(
							'id'    => isset( $row['metric_id'] ) ? (string) $row['metric_id'] : '',
							'value' => isset( $row['metric_value'] ) ? (float) $row['metric_value'] : null,
							'unit'  => isset( $row['metric_unit'] ) ? (string) $row['metric_unit'] : '',
						);
					}
				}
				$detail['steps'] = array_values( $steps );
			}
		}

		// Load reasoning trace from post meta if run_id is a meta-based ID.
		if ( 0 === strpos( $run_id, 'meta:' ) ) {
			$post_id = (int) substr( $run_id, 5 );
			if ( $post_id > 0 && class_exists( 'WP_MCP_AI_Reasoning_Trace' ) ) {
				$raw             = get_post_meta( $post_id, WP_MCP_AI_Reasoning_Trace::META_KEY, true );
				$detail['trace'] = WP_MCP_AI_Reasoning_Trace::sanitize( $raw );
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
		$detail = apply_filters( 'wp_mcp_ai_run_timeline_detail', $detail, $run_id );

		return $detail;
	}
}
