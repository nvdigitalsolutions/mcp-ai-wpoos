<?php
/**
 * Admin Page: Measurement Dashboard (read-only).
 *
 * Shows the current state of the measurement subsystem:
 *   - Registered metrics (with privacy tier and counter-metric pairing)
 *   - Registered verifiers (with independence profile)
 *   - Registered reward functions (with anti-gaming text)
 *   - Registered eval suites (with case counts)
 *   - The most recent events from the in-memory collector buffer
 *
 * This page writes nothing. It exists so operators can inspect what's
 * wired before PR 4 adds the OTel exporter and writable admin actions.
 *
 * @package WP_MCP_AI
 * @since   1.3.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Measurement Dashboard Admin Page.
 */
class WP_MCP_AI_Admin_Measurement_Dashboard {

	/**
	 * Parent menu slug. Matches the existing plugin menu.
	 */
	const PARENT_SLUG = 'wp-mcp-ai-dashboard';

	/**
	 * This page's slug.
	 */
	const PAGE_SLUG = 'wp-mcp-ai-measurement';

	/**
	 * Maximum recent events to display (from the collector buffer).
	 */
	const MAX_RECENT_EVENTS = 50;

	/**
	 * Time-range presets available in the persisted-metrics panel.
	 * Values are seconds. Labels are set in `time_range_labels()`.
	 * Used by PR 9.1 dashboard time-range selector.
	 *
	 * @var array<string,int>
	 */
	const TIME_RANGES = array(
		'1h'  => 3600,
		'24h' => 86400,
		'7d'  => 604800,
		'30d' => 2592000,
	);

	/**
	 * Default time-range slug.
	 */
	const DEFAULT_RANGE = '24h';

	/**
	 * Number of buckets the sparkline is divided into. Kept at 24 so
	 * 24h mode reads one-bucket-per-hour. Other ranges compress / expand.
	 */
	const SPARKLINE_BUCKETS = 24;

	/**
	 * Required capability. `manage_options` matches the rest of the plugin's
	 * admin surface and is the narrowest capability that still lets a site
	 * admin inspect measurement wiring.
	 */
	const REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Constructor: register hooks.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'add_menu_page' ), 30 );
		add_action( 'admin_post_wp_mcp_ai_measurement_action', array( $this, 'handle_admin_post' ) );
	}

	/**
	 * Register submenu.
	 *
	 * @return void
	 */
	public function add_menu_page() {
		// Only register if the parent menu exists; otherwise fall back to Tools.
		global $submenu;
		$parent = self::PARENT_SLUG;
		if ( empty( $submenu[ self::PARENT_SLUG ] ) && empty( $GLOBALS['admin_page_hooks'][ self::PARENT_SLUG ] ) ) {
			$parent = 'tools.php';
		}

		$hook = add_submenu_page(
			$parent,
			__( 'Measurement', 'mcp-ai-wpoos' ),
			__( 'Measurement', 'mcp-ai-wpoos' ),
			self::REQUIRED_CAPABILITY,
			self::PAGE_SLUG,
			array( $this, 'render_page' )
		);

		// Register contextual help tabs only when the page actually loads —
		// `add_help_tab()` requires the current screen to be available, which
		// is only true after the `load-{$hook}` action fires.
		if ( $hook ) {
			add_action( 'load-' . $hook, array( $this, 'register_help_tabs' ) );
		}
	}

	/**
	 * Register WordPress contextual help tabs for the dashboard screen.
	 *
	 * Four tabs ship: overview / metrics / privacy / CLI. Tabs are
	 * filterable as a single array via
	 * `wp_mcp_ai_measurement_help_tabs` so site authors can add their
	 * own (e.g. internal runbook links) without subclassing.
	 *
	 * @return void
	 */
	public function register_help_tabs() {
		$screen = function_exists( 'get_current_screen' ) ? get_current_screen() : null;
		if ( ! $screen || ! method_exists( $screen, 'add_help_tab' ) ) {
			return;
		}

		$tabs = array(
			array(
				'id'      => 'wp_mcp_ai_measurement_overview',
				'title'   => __( 'Overview', 'mcp-ai-wpoos' ),
				'content' =>
					'<p>' . esc_html__( 'The Measurement dashboard surfaces signals from the NV oOS measurement subsystem: stock metrics, eval-suite runs, budget envelopes, and persisted metric events.', 'mcp-ai-wpoos' ) . '</p>' .
					'<p>' . esc_html__( 'Every metric is registered through the wp_mcp_ai_register_metrics hook so third-party code can extend the catalogue without forking the plugin.', 'mcp-ai-wpoos' ) . '</p>',
			),
			array(
				'id'      => 'wp_mcp_ai_measurement_metrics',
				'title'   => __( 'Metrics', 'mcp-ai-wpoos' ),
				'content' =>
					'<p>' . esc_html__( 'Stock metric IDs follow the OpenTelemetry-compatible naming convention "subsystem.entity.signal". Each metric declares a direction (higher_is_better / lower_is_better / neutral) and a counter_metric so a single dimension cannot be optimised in isolation.', 'mcp-ai-wpoos' ) . '</p>' .
					'<ul>' .
					'<li><code>tool.execution.count</code> · <code>tool.execution.success.count</code> · <code>tool.execution.error.count</code></li>' .
					'<li><code>chat.turn.tokens.total</code> · <code>chat.turn.cost_usd</code></li>' .
					'<li><code>stream.duration_ms</code> · <code>stream.cancelled.count</code></li>' .
					'<li><code>eval.suite.pass_rate</code> · <code>eval.suite.regression.count</code></li>' .
					'</ul>',
			),
			array(
				'id'      => 'wp_mcp_ai_measurement_privacy',
				'title'   => __( 'Privacy', 'mcp-ai-wpoos' ),
				'content' =>
					'<p>' . esc_html__( 'Each metric is tagged with a privacy tier: public, internal, or sensitive. The persisted-metric store and OTel exporter both honour the tier — sensitive metrics never leave the site without an explicit allow-list.', 'mcp-ai-wpoos' ) . '</p>' .
					'<p>' . esc_html__( 'Metric retention defaults to 30 days. Adjust via the wp_mcp_ai_metric_retention_days filter, or delete on uninstall by enabling "Delete data on uninstall" under Settings.', 'mcp-ai-wpoos' ) . '</p>',
			),
			array(
				'id'      => 'wp_mcp_ai_measurement_cli',
				'title'   => __( 'CLI', 'mcp-ai-wpoos' ),
				'content' =>
					'<p>' . esc_html__( 'Eval suites can be run from CI without the web runtime:', 'mcp-ai-wpoos' ) . '</p>' .
					'<pre><code>wp mcp-ai measurement run my-suite' . "\n" .
					'wp mcp-ai measurement alert-check my-suite --window=10' . "\n" .
					'wp mcp-ai measurement list-runs my-suite</code></pre>' .
					'<p>' . esc_html__( 'alert-check exits 2 on regression. Pair with --webhook=<url> for chat-room or PagerDuty alerts.', 'mcp-ai-wpoos' ) . '</p>',
			),
		);

		/**
		 * Filter the help tabs registered on the Measurement dashboard.
		 *
		 * @since 1.3.0
		 *
		 * @param array<int,array{id:string,title:string,content:string}> $tabs Help-tab definitions.
		 */
		$tabs = apply_filters( 'wp_mcp_ai_measurement_help_tabs', $tabs );
		if ( ! is_array( $tabs ) ) {
			return;
		}

		foreach ( $tabs as $tab ) {
			if ( empty( $tab['id'] ) || empty( $tab['title'] ) ) {
				continue;
			}
			$screen->add_help_tab(
				array(
					'id'      => sanitize_key( (string) $tab['id'] ),
					'title'   => (string) $tab['title'],
					'content' => isset( $tab['content'] ) ? (string) $tab['content'] : '',
				)
			);
		}

		$sidebar  = '<p><strong>' . esc_html__( 'For more information', 'mcp-ai-wpoos' ) . '</strong></p>';
		$sidebar .= '<p><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/measurement/README.md" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Measurement docs', 'mcp-ai-wpoos' ) . '</a></p>';
		$sidebar .= '<p><a href="https://github.com/nvdigitalsolutions/mcp-ai-wpoos/blob/main/docs/reference/measurement/goodhart-checklist.md" target="_blank" rel="noopener noreferrer">' . esc_html__( 'Goodhart checklist', 'mcp-ai-wpoos' ) . '</a></p>';
		$screen->set_help_sidebar( $sidebar );
	}

	/**
	 * Handle writable actions posted from the dashboard. Every branch
	 * enforces capability + nonce before doing any work; unknown actions
	 * bounce back to the dashboard as a no-op so a malformed POST can
	 * never silently drop changes.
	 *
	 * @return void
	 */
	public function handle_admin_post() {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to perform this action.', 'mcp-ai-wpoos' ), '', array( 'response' => 403 ) );
		}
		$nonce  = isset( $_POST['_wpnonce'] ) ? sanitize_text_field( wp_unslash( $_POST['_wpnonce'] ) ) : '';
		$action = isset( $_POST['mcp_ai_action'] ) ? sanitize_key( wp_unslash( $_POST['mcp_ai_action'] ) ) : '';
		if ( ! wp_verify_nonce( $nonce, 'wp_mcp_ai_measurement_action::' . $action ) ) {
			wp_die( esc_html__( 'Security check failed.', 'mcp-ai-wpoos' ), '', array( 'response' => 400 ) );
		}

		$notice = 'ok';
		switch ( $action ) {
			case 'clear_buffer':
				if ( class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
					WP_MCP_AI_Metric_Collector::get_instance()->clear_buffer();
				}
				if ( class_exists( 'WP_MCP_AI_OTel_Exporter' ) ) {
					( new WP_MCP_AI_OTel_Exporter() )->clear_rolling_buffer();
				}
				$notice = 'buffer_cleared';
				break;

			case 'download_export':
				$this->stream_export_json();
				return;

			case 'reset_budget':
				$slug = isset( $_POST['budget_slug'] ) ? sanitize_key( wp_unslash( $_POST['budget_slug'] ) ) : '';
				if ( '' !== $slug && class_exists( 'WP_MCP_AI_Budget_Registry' ) ) {
					$reset  = WP_MCP_AI_Budget_Registry::get_instance()->reset_persistent( $slug );
					$notice = $reset ? 'budget_reset' : 'budget_not_found';
				} else {
					$notice = 'budget_invalid';
				}
				break;

			default:
				$notice = 'unknown_action';
				break;
		}

		$redirect = add_query_arg(
			array(
				'page'          => self::PAGE_SLUG,
				'mcp_ai_notice' => $notice,
			),
			admin_url( 'admin.php' )
		);
		wp_safe_redirect( $redirect );
		exit;
	}

	/**
	 * Stream the current OTel payload as a JSON file download.
	 *
	 * @return void
	 */
	private function stream_export_json() {
		if ( ! class_exists( 'WP_MCP_AI_OTel_Exporter' ) ) {
			wp_die( esc_html__( 'Exporter is unavailable.', 'mcp-ai-wpoos' ), '', array( 'response' => 500 ) );
		}
		$exporter = new WP_MCP_AI_OTel_Exporter();
		$payload  = $exporter->build_payload();
		$filename = 'wp-mcp-ai-measurement-' . gmdate( 'Ymd-His' ) . '.json';

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		echo wp_json_encode( $payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- JSON body, not HTML.
		exit;
	}

	/**
	 * Render the dashboard page.
	 *
	 * @return void
	 */
	public function render_page() {
		if ( ! current_user_can( self::REQUIRED_CAPABILITY ) ) {
			wp_die( esc_html__( 'You do not have permission to view this page.', 'mcp-ai-wpoos' ) );
		}

		$metrics   = $this->get_metrics();
		$verifiers = $this->get_verifiers();
		$rewards   = $this->get_rewards();
		$suites    = $this->get_suites();
		$events    = $this->get_recent_events();
		$budgets   = $this->get_budgets();

		?>
		<div class="wrap wp-mcp-ai-measurement-dashboard">
			<h1><?php esc_html_e( 'NV oOS Measurement', 'mcp-ai-wpoos' ); ?></h1>
			<?php $this->render_notice(); ?>
			<p class="description">
				<?php esc_html_e( 'Overview of registered metrics, verifiers, reward functions, eval suites, budget envelopes, and the most recent events in the in-memory collector buffer.', 'mcp-ai-wpoos' ); ?>
			</p>

			<?php $this->render_actions_panel(); ?>

			<h2><?php esc_html_e( 'Summary', 'mcp-ai-wpoos' ); ?></h2>
			<ul class="wp-mcp-ai-measurement-summary">
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $metrics ) ) ); ?></strong>
					<?php esc_html_e( 'metrics registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $verifiers ) ) ); ?></strong>
					<?php esc_html_e( 'verifiers registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $rewards ) ) ); ?></strong>
					<?php esc_html_e( 'reward functions registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $suites ) ) ); ?></strong>
					<?php esc_html_e( 'eval suites registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $budgets ) ) ); ?></strong>
					<?php esc_html_e( 'budget envelopes registered', 'mcp-ai-wpoos' ); ?>
				</li>
				<li>
					<strong><?php echo esc_html( number_format_i18n( count( $events ) ) ); ?></strong>
					<?php esc_html_e( 'recent events in buffer', 'mcp-ai-wpoos' ); ?>
				</li>
			</ul>

			<?php $this->render_metrics_table( $metrics ); ?>
			<?php $this->render_persisted_metrics_panel( $metrics ); ?>
			<?php $this->render_verifiers_table( $verifiers ); ?>
			<?php $this->render_rewards_table( $rewards ); ?>
			<?php $this->render_budgets_table( $budgets ); ?>
			<?php $this->render_suites_table( $suites ); ?>
			<?php $this->render_events_table( $events ); ?>
		</div>
		<?php
	}

	/**
	 * Render admin notice (post-action).
	 *
	 * @return void
	 */
	private function render_notice() {
		$notice = isset( $_GET['mcp_ai_notice'] ) ? sanitize_key( wp_unslash( $_GET['mcp_ai_notice'] ) ) : ''; // phpcs:ignore WordPress.Security.NonceVerification.Recommended -- notice only.
		if ( '' === $notice ) {
			return;
		}
		$map = array(
			'buffer_cleared'   => array( 'updated', __( 'Collector buffer and rolling OTel buffer cleared.', 'mcp-ai-wpoos' ) ),
			'budget_reset'     => array( 'updated', __( 'Budget envelope accumulator reset.', 'mcp-ai-wpoos' ) ),
			'budget_not_found' => array( 'error', __( 'Budget envelope not found.', 'mcp-ai-wpoos' ) ),
			'budget_invalid'   => array( 'error', __( 'Budget envelope slug missing.', 'mcp-ai-wpoos' ) ),
			'unknown_action'   => array( 'error', __( 'Unknown measurement action.', 'mcp-ai-wpoos' ) ),
			'ok'               => array( 'updated', __( 'Action completed.', 'mcp-ai-wpoos' ) ),
		);
		if ( ! isset( $map[ $notice ] ) ) {
			return;
		}
		list( $level, $message ) = $map[ $notice ];
		printf(
			'<div class="notice notice-%1$s is-dismissible"><p>%2$s</p></div>',
			esc_attr( 'error' === $level ? 'error' : 'success' ),
			esc_html( $message )
		);
	}

	/**
	 * Render the action buttons panel.
	 *
	 * @return void
	 */
	private function render_actions_panel() {
		$endpoint = admin_url( 'admin-post.php' );
		?>
		<h2><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></h2>
		<div class="wp-mcp-ai-measurement-actions" style="display:flex;gap:1em;flex-wrap:wrap;">
			<form method="post" action="<?php echo esc_url( $endpoint ); ?>">
				<input type="hidden" name="action" value="wp_mcp_ai_measurement_action" />
				<input type="hidden" name="mcp_ai_action" value="clear_buffer" />
				<?php wp_nonce_field( 'wp_mcp_ai_measurement_action::clear_buffer' ); ?>
				<?php submit_button( __( 'Clear Buffers', 'mcp-ai-wpoos' ), 'secondary', 'submit', false ); ?>
			</form>
			<form method="post" action="<?php echo esc_url( $endpoint ); ?>">
				<input type="hidden" name="action" value="wp_mcp_ai_measurement_action" />
				<input type="hidden" name="mcp_ai_action" value="download_export" />
				<?php wp_nonce_field( 'wp_mcp_ai_measurement_action::download_export' ); ?>
				<?php submit_button( __( 'Download OTel JSON Export', 'mcp-ai-wpoos' ), 'primary', 'submit', false ); ?>
			</form>
		</div>
		<p class="description">
			<?php esc_html_e( 'Clear Buffers empties the in-memory collector and the rolling OTel buffer. Download OTel JSON Export returns the current buffer serialized as OTLP/JSON for ingestion by an OpenTelemetry Collector.', 'mcp-ai-wpoos' ); ?>
		</p>
		<?php
	}

	/**
	 * Fetch metric definitions.
	 *
	 * @return array
	 */
	private function get_metrics() {
		if ( ! class_exists( 'WP_MCP_AI_Measurement_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Measurement_Registry::get_instance()->all();
	}

	/**
	 * Fetch verifiers.
	 *
	 * @return array
	 */
	private function get_verifiers() {
		if ( ! class_exists( 'WP_MCP_AI_Verifier_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Verifier_Registry::get_instance()->all();
	}

	/**
	 * Fetch reward functions.
	 *
	 * @return array
	 */
	private function get_rewards() {
		if ( ! class_exists( 'WP_MCP_AI_Reward_Function_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Reward_Function_Registry::get_instance()->all();
	}

	/**
	 * Fetch suites.
	 *
	 * @return array
	 */
	private function get_suites() {
		if ( ! class_exists( 'WP_MCP_AI_Eval_Suite_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Eval_Suite_Registry::get_instance()->all();
	}

	/**
	 * Fetch budget snapshot.
	 *
	 * @return array
	 */
	private function get_budgets() {
		if ( ! class_exists( 'WP_MCP_AI_Budget_Registry' ) ) {
			return array();
		}
		return WP_MCP_AI_Budget_Registry::get_instance()->snapshot();
	}

	/**
	 * Fetch recent events.
	 *
	 * @return array
	 */
	private function get_recent_events() {
		if ( ! class_exists( 'WP_MCP_AI_Metric_Collector' ) ) {
			return array();
		}
		$buffered = WP_MCP_AI_Metric_Collector::get_instance()->buffered();
		$buffered = array_slice( $buffered, -1 * self::MAX_RECENT_EVENTS );
		return array_reverse( $buffered );
	}

	/**
	 * Metrics table.
	 *
	 * @param array $metrics Metrics.
	 * @return void
	 */
	private function render_metrics_table( array $metrics ) {
		?>
		<h2><?php esc_html_e( 'Metrics', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $metrics ) ) : ?>
			<p><em><?php esc_html_e( 'No metrics registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'ID', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Type', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Unit', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Direction', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Privacy', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Counter', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $metrics as $id => $def ) : ?>
				<tr>
					<td><code><?php echo esc_html( $id ); ?></code></td>
					<td><?php echo esc_html( isset( $def['type'] ) ? $def['type'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $def['unit'] ) ? $def['unit'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $def['direction'] ) ? $def['direction'] : '' ); ?></td>
					<td><?php echo esc_html( isset( $def['privacy_tier'] ) ? $def['privacy_tier'] : '' ); ?></td>
					<td><?php echo esc_html( ! empty( $def['counter_metric'] ) ? $def['counter_metric'] : '—' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Verifiers table.
	 *
	 * @param array $verifiers Verifiers.
	 * @return void
	 */
	private function render_verifiers_table( array $verifiers ) {
		?>
		<h2><?php esc_html_e( 'Verifiers', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $verifiers ) ) : ?>
			<p><em><?php esc_html_e( 'No verifiers registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Kind', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Disallowed Providers', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Disallowed Models', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $verifiers as $slug => $v ) :
				$profile   = is_object( $v ) && method_exists( $v, 'get_independence_profile' ) ? $v->get_independence_profile() : array();
				$providers = isset( $profile['disallowed_providers'] ) ? (array) $profile['disallowed_providers'] : array();
				$models    = isset( $profile['disallowed_models'] ) ? (array) $profile['disallowed_models'] : array();
				?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( is_object( $v ) && method_exists( $v, 'get_label' ) ? $v->get_label() : '' ); ?></td>
					<td><?php echo esc_html( is_object( $v ) && method_exists( $v, 'get_kind' ) ? $v->get_kind() : '' ); ?></td>
					<td><?php echo esc_html( implode( ', ', $providers ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $models ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Rewards table.
	 *
	 * @param array $rewards Rewards.
	 * @return void
	 */
	private function render_rewards_table( array $rewards ) {
		?>
		<h2><?php esc_html_e( 'Reward Functions', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $rewards ) ) : ?>
			<p><em><?php esc_html_e( 'No reward functions registered.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Output Range', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Counter Metric', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Anti-gaming', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php foreach ( $rewards as $slug => $def ) : ?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( isset( $def['label'] ) ? $def['label'] : '' ); ?></td>
					<td>
						<?php
						echo esc_html(
							sprintf(
								'[%s, %s]',
								isset( $def['output_min'] ) ? (string) $def['output_min'] : '?',
								isset( $def['output_max'] ) ? (string) $def['output_max'] : '?'
							)
						);
						?>
					</td>
					<td><?php echo esc_html( ! empty( $def['counter_metric'] ) ? $def['counter_metric'] : '—' ); ?></td>
					<td><?php echo esc_html( isset( $def['anti_gaming'] ) ? $def['anti_gaming'] : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Budgets table (with reset action per persistent envelope).
	 *
	 * @param array $budgets Snapshot entries.
	 * @return void
	 */
	private function render_budgets_table( array $budgets ) {
		?>
		<h2><?php esc_html_e( 'Budget Envelopes', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $budgets ) ) : ?>
			<p><em><?php esc_html_e( 'No budget envelopes registered. Use the wp_mcp_ai_register_budgets hook to add one.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Scope', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Metrics', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Consumed', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Limit', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Utilization', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'State', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Actions', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			$endpoint = admin_url( 'admin-post.php' );
			foreach ( $budgets as $row ) :
				$env         = $row['envelope'];
				$state       = isset( $row['state'] ) ? (string) $row['state'] : 'ok';
				$state_color = 'ok' === $state ? '#46b450' : ( 'warn' === $state ? '#ffb900' : '#dc3232' );
				?>
				<tr>
					<td><code><?php echo esc_html( $env['slug'] ); ?></code></td>
					<td><?php echo esc_html( $env['label'] ); ?></td>
					<td><?php echo esc_html( $env['scope'] ); ?></td>
					<td><?php echo esc_html( implode( ', ', (array) $env['metric_ids'] ) ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (float) $row['consumed'], 4 ) ); ?><?php echo esc_html( '' !== $env['unit'] ? ' ' . $env['unit'] : '' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (float) $env['limit'], 4 ) ); ?><?php echo esc_html( '' !== $env['unit'] ? ' ' . $env['unit'] : '' ); ?></td>
					<td><?php echo esc_html( number_format_i18n( (float) $row['ratio'] * 100, 1 ) . '%' ); ?></td>
					<td><span style="color:<?php echo esc_attr( $state_color ); ?>;font-weight:600;"><?php echo esc_html( strtoupper( $state ) ); ?></span></td>
					<td>
						<?php if ( 'persistent' === $env['scope'] ) : ?>
							<form method="post" action="<?php echo esc_url( $endpoint ); ?>" style="display:inline;">
								<input type="hidden" name="action" value="wp_mcp_ai_measurement_action" />
								<input type="hidden" name="mcp_ai_action" value="reset_budget" />
								<input type="hidden" name="budget_slug" value="<?php echo esc_attr( $env['slug'] ); ?>" />
								<?php wp_nonce_field( 'wp_mcp_ai_measurement_action::reset_budget' ); ?>
								<?php submit_button( __( 'Reset', 'mcp-ai-wpoos' ), 'small', 'submit', false ); ?>
							</form>
						<?php else : ?>
							<em><?php esc_html_e( 'auto-resets per request', 'mcp-ai-wpoos' ); ?></em>
						<?php endif; ?>
					</td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Suites table.
	 *
	 * @param array $suites Suites.
	 * @return void
	 */
	private function render_suites_table( array $suites ) {
		?>
		<h2><?php esc_html_e( 'Eval Suites', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $suites ) ) : ?>
			<p><em><?php esc_html_e( 'No eval suites registered. Use the wp_mcp_ai_register_eval_suites hook to add one.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Slug', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Label', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Cases', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Tags', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $suites as $slug => $suite ) :
				$arr = $suite->to_array();
				?>
				<tr>
					<td><code><?php echo esc_html( $slug ); ?></code></td>
					<td><?php echo esc_html( $arr['label'] ); ?></td>
					<td><?php echo esc_html( number_format_i18n( $arr['case_count'] ) ); ?></td>
					<td><?php echo esc_html( implode( ', ', $arr['tags'] ) ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * Recent events table.
	 *
	 * @param array $events Events.
	 * @return void
	 */
	private function render_events_table( array $events ) {
		?>
		<h2><?php esc_html_e( 'Recent Events (in-memory buffer)', 'mcp-ai-wpoos' ); ?></h2>
		<?php if ( empty( $events ) ) : ?>
			<p><em><?php esc_html_e( 'The collector buffer is empty on this request. Buffered events are per-request and in-memory only — see the exporter PR for persistent storage.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
endif;
		?>
		<table class="widefat striped">
			<thead>
				<tr>
					<th><?php esc_html_e( 'Metric', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Value', 'mcp-ai-wpoos' ); ?></th>
					<th><?php esc_html_e( 'Recorded', 'mcp-ai-wpoos' ); ?></th>
				</tr>
			</thead>
			<tbody>
			<?php
			foreach ( $events as $event ) :
				$metric_id = isset( $event['id'] ) ? (string) $event['id'] : '';
				$value     = isset( $event['value'] ) ? $event['value'] : '';
				$timestamp = isset( $event['timestamp'] ) ? (int) $event['timestamp'] : 0;
				?>
				<tr>
					<td><code><?php echo esc_html( $metric_id ); ?></code></td>
					<td><?php echo esc_html( is_scalar( $value ) ? (string) $value : wp_json_encode( $value ) ); ?></td>
					<td><?php echo esc_html( $timestamp ? gmdate( 'Y-m-d H:i:s', $timestamp ) . ' UTC' : '' ); ?></td>
				</tr>
			<?php endforeach; ?>
			</tbody>
		</table>
		<?php
	}

	/**
	 * PR 9.1 — Persisted metrics panel.
	 *
	 * Renders a time-range picker, a metric picker, a per-privacy-tier
	 * count summary, and a server-rendered inline SVG sparkline derived
	 * from the persistent event store (`WP_MCP_AI_Metric_Event_Store`).
	 *
	 * Everything is rendered server-side (no JS, no XHR) so the panel
	 * works on sites that keep the admin heavy-JS budget tight.
	 *
	 * Selection is driven by idempotent query args on the dashboard URL,
	 * so links are shareable and the panel is stateless between requests.
	 *
	 * @param array $metrics Registered metric definitions.
	 * @return void
	 */
	private function render_persisted_metrics_panel( array $metrics ) {
		?>
		<h2><?php esc_html_e( 'Persisted Metrics', 'mcp-ai-wpoos' ); ?></h2>
		<?php
		if ( ! class_exists( 'WP_MCP_AI_Metric_Event_Store' ) ) {
			?>
			<p><em><?php esc_html_e( 'Metric event store is unavailable on this site.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
		}

		$store = WP_MCP_AI_Metric_Event_Store::get_instance();
		if ( ! $store->table_exists() ) {
			?>
			<p><em><?php esc_html_e( 'Metric events table has not been installed yet. Deactivate and reactivate the plugin to install the schema.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
		}

		// Inputs — all driven from GET so links are shareable and render
		// is stateless. Nonce not required for read-only queries.
		// phpcs:disable WordPress.Security.NonceVerification.Recommended
		$range_slug = isset( $_GET['mcp_ai_range'] ) ? sanitize_key( wp_unslash( $_GET['mcp_ai_range'] ) ) : self::DEFAULT_RANGE;
		$metric_id  = isset( $_GET['mcp_ai_metric'] ) ? sanitize_text_field( wp_unslash( $_GET['mcp_ai_metric'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		if ( ! isset( self::TIME_RANGES[ $range_slug ] ) ) {
			$range_slug = self::DEFAULT_RANGE;
		}
		$range_secs = (int) self::TIME_RANGES[ $range_slug ];

		$metric_ids = array();
		foreach ( $metrics as $m ) {
			if ( is_array( $m ) && ! empty( $m['id'] ) && is_string( $m['id'] ) ) {
				$metric_ids[] = $m['id'];
			}
		}
		$metric_ids = array_values( array_unique( $metric_ids ) );
		sort( $metric_ids );
		if ( '' === $metric_id || ! in_array( $metric_id, $metric_ids, true ) ) {
			$metric_id = isset( $metric_ids[0] ) ? $metric_ids[0] : '';
		}

		$this->render_persisted_selector( $metric_ids, $metric_id, $range_slug );
		$this->render_privacy_tier_counts( $store );

		if ( '' === $metric_id ) {
			return;
		}
		$until  = time();
		$since  = $until - $range_secs;
		$events = $store->query_by_metric( $metric_id, $since, $until, 5000 );
		$this->render_sparkline( $events, $metric_id, $since, $until );
	}

	/**
	 * Render the time-range + metric picker form.
	 *
	 * @param array<int,string> $metric_ids Registered metric ids.
	 * @param string            $metric_id  Currently-selected metric id.
	 * @param string            $range_slug Currently-selected range slug.
	 * @return void
	 */
	private function render_persisted_selector( array $metric_ids, $metric_id, $range_slug ) {
		$endpoint = admin_url( 'admin.php' );
		$labels   = self::time_range_labels();
		?>
		<form method="get" action="<?php echo esc_url( $endpoint ); ?>" class="wp-mcp-ai-persisted-selector" style="margin:0.5em 0;display:flex;gap:1em;align-items:center;flex-wrap:wrap;">
			<input type="hidden" name="page" value="<?php echo esc_attr( self::PAGE_SLUG ); ?>" />
			<label>
				<?php esc_html_e( 'Metric', 'mcp-ai-wpoos' ); ?>
				<select name="mcp_ai_metric">
					<?php foreach ( $metric_ids as $id ) : ?>
						<option value="<?php echo esc_attr( $id ); ?>" <?php selected( $id, $metric_id ); ?>><?php echo esc_html( $id ); ?></option>
					<?php endforeach; ?>
					<?php if ( empty( $metric_ids ) ) : ?>
						<option value=""><?php esc_html_e( '(no metrics registered)', 'mcp-ai-wpoos' ); ?></option>
					<?php endif; ?>
				</select>
			</label>
			<label>
				<?php esc_html_e( 'Range', 'mcp-ai-wpoos' ); ?>
				<select name="mcp_ai_range">
					<?php foreach ( $labels as $slug => $label ) : ?>
						<option value="<?php echo esc_attr( $slug ); ?>" <?php selected( $slug, $range_slug ); ?>><?php echo esc_html( $label ); ?></option>
					<?php endforeach; ?>
				</select>
			</label>
			<?php submit_button( __( 'Update', 'mcp-ai-wpoos' ), 'secondary', 'submit', false ); ?>
		</form>
		<?php
	}

	/**
	 * Render the per-privacy-tier row counts from the persistent store.
	 *
	 * @param WP_MCP_AI_Metric_Event_Store $store Event store.
	 * @return void
	 */
	private function render_privacy_tier_counts( $store ) {
		$counts = $store->count_by_privacy();
		$total  = 0;
		foreach ( $counts as $c ) {
			$total += (int) $c;
		}
		?>
		<p class="wp-mcp-ai-persisted-counts">
			<?php
			printf(
				/* translators: %s is total number of rows in the event store. */
				esc_html__( '%s rows persisted across privacy tiers:', 'mcp-ai-wpoos' ),
				'<strong>' . esc_html( number_format_i18n( $total ) ) . '</strong>'
			);
			?>
			<?php foreach ( $counts as $tier => $count ) : ?>
				<span class="wp-mcp-ai-persisted-tier" style="margin-left:1em;">
					<code><?php echo esc_html( (string) $tier ); ?></code>:
					<strong><?php echo esc_html( number_format_i18n( (int) $count ) ); ?></strong>
				</span>
			<?php endforeach; ?>
		</p>
		<?php
	}

	/**
	 * Render an inline SVG sparkline bucketed across the requested range.
	 *
	 * Buckets are fixed at `self::SPARKLINE_BUCKETS`. Each bucket value
	 * is the mean of samples that fell inside it — `0` for empty buckets
	 * so the line reads as "quiet" rather than interpolated. The SVG is
	 * rendered server-side with no inline `<script>` so it is safe
	 * against CSP policies that forbid inline JS.
	 *
	 * @param array<int,array<string,mixed>> $events    Rows from `query_by_metric()`.
	 * @param string                         $metric_id Metric id for the heading/label.
	 * @param int                            $since     UTC timestamp lower bound.
	 * @param int                            $until     UTC timestamp upper bound.
	 * @return void
	 */
	private function render_sparkline( array $events, $metric_id, $since, $until ) {
		if ( empty( $events ) ) {
			?>
			<p><em><?php esc_html_e( 'No persisted samples for this metric in the selected range.', 'mcp-ai-wpoos' ); ?></em></p>
			<?php
			return;
		}

		$buckets = self::bucket_events( $events, (int) $since, (int) $until, self::SPARKLINE_BUCKETS );
		$means   = $buckets['means'];
		$counts  = $buckets['counts'];
		$max     = $buckets['max'];
		$min     = $buckets['min'];

		$sample_count = 0;
		foreach ( $counts as $c ) {
			$sample_count += $c;
		}

		// SVG geometry.
		$width   = 520;
		$height  = 80;
		$pad_x   = 4;
		$pad_y   = 6;
		$inner_w = $width - ( 2 * $pad_x );
		$inner_h = $height - ( 2 * $pad_y );
		$range   = $max - $min;
		if ( $range <= 0 ) {
			$range = 1.0; // Flat-line guard.
		}

		$points = array();
		$count  = count( $means );
		for ( $i = 0; $i < $count; $i++ ) {
			$x        = $pad_x + ( $count > 1 ? ( $i * $inner_w / ( $count - 1 ) ) : ( $inner_w / 2 ) );
			$y        = $pad_y + $inner_h - ( ( $means[ $i ] - $min ) / $range * $inner_h );
			$points[] = sprintf( '%0.2f,%0.2f', $x, $y );
		}
		$polyline = implode( ' ', $points );

		?>
		<div class="wp-mcp-ai-sparkline">
			<p class="description">
				<?php
				printf(
					/* translators: 1: metric id; 2: sample count; 3: min value; 4: max value. */
					esc_html__( '%1$s — %2$s samples (min %3$s, max %4$s).', 'mcp-ai-wpoos' ),
					'<code>' . esc_html( $metric_id ) . '</code>',
					'<strong>' . esc_html( number_format_i18n( $sample_count ) ) . '</strong>',
					'<strong>' . esc_html( self::format_number( $min ) ) . '</strong>',
					'<strong>' . esc_html( self::format_number( $max ) ) . '</strong>'
				);
				?>
			</p>
			<svg
				xmlns="http://www.w3.org/2000/svg"
				viewBox="0 0 <?php echo esc_attr( (string) $width ); ?> <?php echo esc_attr( (string) $height ); ?>"
				width="<?php echo esc_attr( (string) $width ); ?>"
				height="<?php echo esc_attr( (string) $height ); ?>"
				role="img"
				aria-label="<?php echo esc_attr( sprintf( /* translators: %s metric id */ __( 'Sparkline for %s', 'mcp-ai-wpoos' ), $metric_id ) ); ?>"
				style="border:1px solid #c3c4c7;background:#fff;"
			>
				<polyline fill="none" stroke="#2271b1" stroke-width="1.5" points="<?php echo esc_attr( $polyline ); ?>" />
			</svg>
			<p class="description" style="display:flex;justify-content:space-between;margin:0;font-variant-numeric:tabular-nums;">
				<span><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $since ) . ' UTC' ); ?></span>
				<span><?php echo esc_html( gmdate( 'Y-m-d H:i', (int) $until ) . ' UTC' ); ?></span>
			</p>
		</div>
		<?php
	}

	/**
	 * Bucket a set of rows into `$bucket_count` equal-width time buckets.
	 *
	 * Each bucket's reported value is the arithmetic mean of the sample
	 * values that fell inside it; empty buckets report `0.0` so the line
	 * reads as flat (rather than interpolated) across quiet stretches.
	 * Returns means, counts, and observed {min,max} across non-empty
	 * buckets for the sparkline scale.
	 *
	 * Public-static so tests can exercise it without instantiating the
	 * dashboard admin class.
	 *
	 * @param array<int,array<string,mixed>> $events       Rows returned by `query_by_metric()`.
	 * @param int                            $since        UTC lower bound.
	 * @param int                            $until        UTC upper bound.
	 * @param int                            $bucket_count Number of buckets.
	 * @return array{means: array<int,float>, counts: array<int,int>, min: float, max: float}
	 */
	public static function bucket_events( array $events, $since, $until, $bucket_count ) {
		$bucket_count = max( 1, (int) $bucket_count );
		$until        = max( (int) $until, (int) $since + 1 );
		$since        = (int) $since;
		$span         = max( 1, $until - $since );

		$sums   = array_fill( 0, $bucket_count, 0.0 );
		$counts = array_fill( 0, $bucket_count, 0 );

		foreach ( $events as $event ) {
			$ts = 0;
			if ( isset( $event['recorded_at'] ) ) {
				if ( is_numeric( $event['recorded_at'] ) ) {
					$ts = (int) $event['recorded_at'];
				} else {
					$ts = (int) strtotime( $event['recorded_at'] . ' UTC' );
				}
			}
			if ( $ts < $since || $ts > $until ) {
				continue;
			}
			$value           = isset( $event['metric_value'] ) ? (float) $event['metric_value'] : 0.0;
			$idx             = (int) floor( ( ( $ts - $since ) / $span ) * $bucket_count );
			$idx             = max( 0, min( $bucket_count - 1, $idx ) );
			$sums[ $idx ]   += $value;
			$counts[ $idx ] += 1;
		}

		$means = array();
		$min   = null;
		$max   = null;
		for ( $i = 0; $i < $bucket_count; $i++ ) {
			$mean    = $counts[ $i ] > 0 ? ( $sums[ $i ] / $counts[ $i ] ) : 0.0;
			$means[] = $mean;
			if ( $counts[ $i ] > 0 ) {
				if ( null === $min || $mean < $min ) {
					$min = $mean;
				}
				if ( null === $max || $mean > $max ) {
					$max = $mean;
				}
			}
		}
		// When every bucket was empty, min/max collapse to 0.
		$min = null === $min ? 0.0 : (float) $min;
		$max = null === $max ? 0.0 : (float) $max;
		// Force a visual gap if min == max on a non-empty uniform series,
		// otherwise the sparkline would collapse to a zero-height line.
		if ( $max === $min ) {
			$max = $min + 1.0;
		}
		return array(
			'means'  => $means,
			'counts' => $counts,
			'min'    => $min,
			'max'    => $max,
		);
	}

	/**
	 * Human-readable labels for the time-range select options.
	 *
	 * @return array<string,string>
	 */
	private static function time_range_labels() {
		return array(
			'1h'  => __( 'Last hour', 'mcp-ai-wpoos' ),
			'24h' => __( 'Last 24 hours', 'mcp-ai-wpoos' ),
			'7d'  => __( 'Last 7 days', 'mcp-ai-wpoos' ),
			'30d' => __( 'Last 30 days', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Format a numeric value for display — small values as floats with
	 * up to 4 decimals, larger values as integers with thousands separators.
	 *
	 * @param float|int $value Value to format.
	 * @return string
	 */
	private static function format_number( $value ) {
		$value = (float) $value;
		if ( abs( $value ) >= 1000 ) {
			return number_format_i18n( $value, 0 );
		}
		if ( abs( $value ) >= 1 ) {
			return number_format_i18n( $value, 2 );
		}
		return number_format_i18n( $value, 4 );
	}
}
