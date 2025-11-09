<?php
/**
 * Advanced Metrics Dashboard for WP oOS Orchestration Layer.
 *
 * Provides real-time analytics, trend analysis, and cost optimization
 * recommendations based on orchestration layer performance metrics.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Metrics Dashboard Admin Page class.
 */
class WP_MCP_AI_Admin_Metrics_Dashboard {

	/**
	 * Page hook suffix.
	 *
	 * @var string
	 */
	private $page_hook;

	/**
	 * Constructor.
	 */
	public function __construct() {
		add_action( 'admin_menu', array( $this, 'register_page' ), 20 );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_scripts' ) );
	}

	/**
	 * Register the metrics dashboard page.
	 */
	public function register_page() {
		$this->page_hook = add_submenu_page(
			'wp-mcp-ai-dashboard',
			__( 'Orchestration Metrics', 'wp-mcp-ai' ),
			__( 'Metrics Dashboard', 'wp-mcp-ai' ),
			'manage_options',
			'wp-mcp-ai-metrics-dashboard',
			array( $this, 'render_page' )
		);
	}

	/**
	 * Enqueue scripts and styles for the metrics dashboard.
	 *
	 * @param string $hook Current admin page hook.
	 */
	public function enqueue_scripts( $hook ) {
		if ( $hook !== $this->page_hook ) {
			return;
		}

		// Enqueue Chart.js for visualizations.
		wp_enqueue_script(
			'chartjs',
			'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js',
			array(),
			'4.4.0',
			true
		);

		// Enqueue custom metrics dashboard script.
		wp_enqueue_script(
			'wp-mcp-ai-metrics-dashboard',
			WP_MCP_AI_URL . 'assets/js/admin-metrics-dashboard.js',
			array( 'jquery', 'chartjs' ),
			WP_MCP_AI_VERSION,
			true
		);

		// Localize script with data.
		wp_localize_script(
			'wp-mcp-ai-metrics-dashboard',
			'wpMcpAiMetrics',
			array(
				'ajaxUrl'   => admin_url( 'admin-ajax.php' ),
				'nonce'     => wp_create_nonce( 'wp_mcp_ai_metrics' ),
				'restUrl'   => rest_url( 'mcp-ai/v1/metrics/' ),
				'restNonce' => wp_create_nonce( 'wp_rest' ),
			)
		);

		// Enqueue custom styles.
		wp_enqueue_style(
			'wp-mcp-ai-metrics-dashboard',
			WP_MCP_AI_URL . 'assets/css/admin-metrics-dashboard.css',
			array(),
			WP_MCP_AI_VERSION
		);
	}

	/**
	 * Render the metrics dashboard page.
	 */
	public function render_page() {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have sufficient permissions to access this page.', 'wp-mcp-ai' ) );
		}

		// Get initial metrics data.
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$health_status    = $resource_manager->get_health_status();
		$overview_metrics = $this->get_overview_metrics();

		?>
		<div class="wrap wp-mcp-ai-metrics-dashboard">
			<h1>
				<?php esc_html_e( 'Orchestration Metrics Dashboard', 'wp-mcp-ai' ); ?>
				<button type="button" class="page-title-action" id="refresh-metrics">
					<span class="dashicons dashicons-update"></span>
					<?php esc_html_e( 'Refresh', 'wp-mcp-ai' ); ?>
				</button>
			</h1>

			<?php $this->render_health_banner( $health_status ); ?>

			<div class="metrics-grid">
				<?php
				$this->render_overview_section( $overview_metrics );
				$this->render_trends_section();
				$this->render_assistants_section();
				$this->render_cost_analysis_section();
				$this->render_predictive_insights_section();
				$this->render_export_section();
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render health status banner.
	 *
	 * @param array $health_status Health status data.
	 */
	private function render_health_banner( $health_status ) {
		$health_level = $health_status['overall_health'];
		$health_color = 'healthy' === $health_level ? '#00a32a' : ( 'warning' === $health_level ? '#dba617' : '#d63638' );
		$health_icon  = 'healthy' === $health_level ? 'yes-alt' : ( 'warning' === $health_level ? 'warning' : 'dismiss' );
		?>
		<div class="notice notice-<?php echo 'healthy' === $health_level ? 'success' : ( 'warning' === $health_level ? 'warning' : 'error' ); ?>" style="border-left-color: <?php echo esc_attr( $health_color ); ?>">
			<p>
				<span class="dashicons dashicons-<?php echo esc_attr( $health_icon ); ?>" style="color: <?php echo esc_attr( $health_color ); ?>"></span>
				<strong><?php esc_html_e( 'System Health:', 'wp-mcp-ai' ); ?></strong>
				<?php echo esc_html( ucfirst( $health_level ) ); ?>
				<?php if ( ! empty( $health_status['issues'] ) ) : ?>
					&mdash; <?php echo esc_html( implode( ', ', array_map( 'ucwords', str_replace( '_', ' ', $health_status['issues'] ) ) ) ); ?>
				<?php endif; ?>
			</p>
		</div>
		<?php
	}

	/**
	 * Render overview metrics section.
	 *
	 * @param array $metrics Overview metrics data.
	 */
	private function render_overview_section( $metrics ) {
		?>
		<div class="metrics-section">
			<h2><?php esc_html_e( 'Overview', 'wp-mcp-ai' ); ?></h2>
			<div class="metrics-cards">
				<div class="metric-card">
					<div class="metric-label"><?php esc_html_e( 'Total Requests (24h)', 'wp-mcp-ai' ); ?></div>
					<div class="metric-value"><?php echo esc_html( number_format( $metrics['total_requests'] ) ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-label"><?php esc_html_e( 'Total Tokens (24h)', 'wp-mcp-ai' ); ?></div>
					<div class="metric-value"><?php echo esc_html( number_format( $metrics['total_tokens'] ) ); ?></div>
				</div>
				<div class="metric-card">
					<div class="metric-label"><?php esc_html_e( 'Avg Response Time', 'wp-mcp-ai' ); ?></div>
					<div class="metric-value"><?php echo esc_html( round( $metrics['avg_response_time'], 2 ) ); ?>s</div>
				</div>
				<div class="metric-card">
					<div class="metric-label"><?php esc_html_e( 'Success Rate', 'wp-mcp-ai' ); ?></div>
					<div class="metric-value"><?php echo esc_html( round( $metrics['success_rate'], 1 ) ); ?>%</div>
				</div>
			</div>
		</div>
		<?php
	}

	/**
	 * Render trends analysis section.
	 */
	private function render_trends_section() {
		?>
		<div class="metrics-section">
			<h2><?php esc_html_e( 'Usage Trends', 'wp-mcp-ai' ); ?></h2>
			<div class="chart-container">
				<canvas id="trends-chart"></canvas>
			</div>
			<div class="trend-controls">
				<label>
					<?php esc_html_e( 'Time Period:', 'wp-mcp-ai' ); ?>
					<select id="trends-period">
						<option value="24h"><?php esc_html_e( 'Last 24 Hours', 'wp-mcp-ai' ); ?></option>
						<option value="7d" selected><?php esc_html_e( 'Last 7 Days', 'wp-mcp-ai' ); ?></option>
						<option value="30d"><?php esc_html_e( 'Last 30 Days', 'wp-mcp-ai' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Metric:', 'wp-mcp-ai' ); ?>
					<select id="trends-metric">
						<option value="tokens"><?php esc_html_e( 'Token Usage', 'wp-mcp-ai' ); ?></option>
						<option value="requests"><?php esc_html_e( 'Request Count', 'wp-mcp-ai' ); ?></option>
						<option value="response_time"><?php esc_html_e( 'Response Time', 'wp-mcp-ai' ); ?></option>
						<option value="errors"><?php esc_html_e( 'Error Rate', 'wp-mcp-ai' ); ?></option>
					</select>
				</label>
			</div>
		</div>
		<?php
	}

	/**
	 * Render assistants comparison section.
	 */
	private function render_assistants_section() {
		?>
		<div class="metrics-section">
			<h2><?php esc_html_e( 'Assistant Performance', 'wp-mcp-ai' ); ?></h2>
			<div class="chart-container">
				<canvas id="assistants-chart"></canvas>
			</div>
			<div class="assistants-table-container">
				<table class="wp-list-table widefat striped" id="assistants-metrics-table">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Assistant', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Requests', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Tokens', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Avg Response', 'wp-mcp-ai' ); ?></th>
							<th><?php esc_html_e( 'Success Rate', 'wp-mcp-ai' ); ?></th>
						</tr>
					</thead>
					<tbody>
						<tr>
							<td colspan="5"><?php esc_html_e( 'Loading...', 'wp-mcp-ai' ); ?></td>
						</tr>
					</tbody>
				</table>
			</div>
		</div>
		<?php
	}

	/**
	 * Render cost analysis section.
	 */
	private function render_cost_analysis_section() {
		?>
		<div class="metrics-section">
			<h2><?php esc_html_e( 'Cost Analysis & Optimization', 'wp-mcp-ai' ); ?></h2>
			<div class="cost-summary" id="cost-summary">
				<p><?php esc_html_e( 'Loading cost analysis...', 'wp-mcp-ai' ); ?></p>
			</div>
			<div class="optimization-recommendations" id="optimization-recommendations">
				<!-- Populated via JavaScript -->
			</div>
		</div>
		<?php
	}

	/**
	 * Render predictive insights section.
	 */
	private function render_predictive_insights_section() {
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$prediction       = $resource_manager->predict_requirements( 'chat' );
		?>
		<div class="metrics-section">
			<h2><?php esc_html_e( 'Predictive Insights', 'wp-mcp-ai' ); ?></h2>
			<div class="predictions-container" id="predictions-container">
				<?php if ( $prediction['confidence'] > 0.3 ) : ?>
					<div class="prediction-card">
						<h3><?php esc_html_e( 'Resource Forecast', 'wp-mcp-ai' ); ?></h3>
						<p>
							<?php
							printf(
								/* translators: %1$d: predicted tokens, %2$d: confidence percentage */
								esc_html__( 'Next operations will likely need ~%1$d tokens (%2$d%% confidence)', 'wp-mcp-ai' ),
								$prediction['predicted_tokens'],
								round( $prediction['confidence'] * 100 )
							);
							?>
						</p>
						<?php if ( 'proceed' !== $prediction['recommendation'] ) : ?>
							<div class="prediction-warning">
								<span class="dashicons dashicons-warning"></span>
								<?php echo esc_html( str_replace( '_', ' ', ucwords( $prediction['recommendation'], '_' ) ) ); ?>
							</div>
						<?php endif; ?>
					</div>
				<?php else : ?>
					<p><?php esc_html_e( 'Insufficient data for predictions. More usage history needed.', 'wp-mcp-ai' ); ?></p>
				<?php endif; ?>
			</div>
		</div>
		<?php
	}

	/**
	 * Render export section.
	 */
	private function render_export_section() {
		?>
		<div class="metrics-section">
			<h2><?php esc_html_e( 'Export & Reports', 'wp-mcp-ai' ); ?></h2>
			<div class="export-controls">
				<label>
					<?php esc_html_e( 'Export Format:', 'wp-mcp-ai' ); ?>
					<select id="export-format">
						<option value="csv"><?php esc_html_e( 'CSV', 'wp-mcp-ai' ); ?></option>
						<option value="json"><?php esc_html_e( 'JSON', 'wp-mcp-ai' ); ?></option>
					</select>
				</label>
				<label>
					<?php esc_html_e( 'Time Range:', 'wp-mcp-ai' ); ?>
					<select id="export-range">
						<option value="24h"><?php esc_html_e( 'Last 24 Hours', 'wp-mcp-ai' ); ?></option>
						<option value="7d"><?php esc_html_e( 'Last 7 Days', 'wp-mcp-ai' ); ?></option>
						<option value="30d"><?php esc_html_e( 'Last 30 Days', 'wp-mcp-ai' ); ?></option>
					</select>
				</label>
				<button type="button" class="button button-primary" id="export-metrics">
					<span class="dashicons dashicons-download"></span>
					<?php esc_html_e( 'Export Metrics', 'wp-mcp-ai' ); ?>
				</button>
			</div>
		</div>
		<?php
	}

	/**
	 * Get overview metrics summary.
	 *
	 * @return array Overview metrics.
	 */
	private function get_overview_metrics() {
		$resource_manager = WP_MCP_AI_Resource_Manager::instance();
		$usage_history    = $resource_manager->get_usage_history( 24 );

		$total_requests     = count( $usage_history );
		$total_tokens       = 0;
		$total_response_time = 0;
		$success_count      = 0;

		foreach ( $usage_history as $entry ) {
			$total_tokens       += isset( $entry['tokens_used'] ) ? $entry['tokens_used'] : 0;
			$total_response_time += isset( $entry['execution_time'] ) ? $entry['execution_time'] : 0;
			if ( isset( $entry['status'] ) && 'success' === $entry['status'] ) {
				$success_count++;
			}
		}

		$avg_response_time = $total_requests > 0 ? $total_response_time / $total_requests : 0;
		$success_rate      = $total_requests > 0 ? ( $success_count / $total_requests ) * 100 : 0;

		return array(
			'total_requests'     => $total_requests,
			'total_tokens'       => $total_tokens,
			'avg_response_time'  => $avg_response_time,
			'success_rate'       => $success_rate,
		);
	}
}
