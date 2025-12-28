<?php
/**
 * Gutenberg blocks integration for NV oOS Performance Monitoring.
 *
 * Registers performance monitoring blocks for use in the block editor.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Handles Gutenberg block registration for performance monitoring.
 */
class WP_MCP_AI_Performance_Blocks {

	/**
	 * Initialize the blocks integration.
	 */
	public static function init() {
		add_action( 'init', array( __CLASS__, 'register_blocks' ) );
		add_action( 'enqueue_block_editor_assets', array( __CLASS__, 'enqueue_block_editor_assets' ) );
	}

	/**
	 * Register performance monitoring blocks.
	 */
	public static function register_blocks() {
		if ( ! function_exists( 'register_block_type' ) ) {
			return;
		}

		// Performance Test Runner Block.
		register_block_type(
			'wp-mcp-ai/performance-test-runner',
			array(
				'render_callback' => array( __CLASS__, 'render_test_runner_block' ),
				'attributes'      => array(
					'title'        => array(
						'type'    => 'string',
						'default' => __( 'Performance Test Runner', 'wp-mcp-ai' ),
					),
					'enabledTests' => array(
						'type'    => 'array',
						'default' => array( 'stress', 'security', 'speed', 'optimization' ),
					),
				),
			)
		);

		// Performance Metrics Block.
		register_block_type(
			'wp-mcp-ai/performance-metrics',
			array(
				'render_callback' => array( __CLASS__, 'render_metrics_block' ),
				'attributes'      => array(
					'title'      => array(
						'type'    => 'string',
						'default' => __( 'Performance Metrics', 'wp-mcp-ai' ),
					),
					'component'  => array(
						'type'    => 'string',
						'default' => '',
					),
					'timePeriod' => array(
						'type'    => 'string',
						'default' => '-24 hours',
					),
				),
			)
		);

		// System Health Status Block.
		register_block_type(
			'wp-mcp-ai/system-health-status',
			array(
				'render_callback' => array( __CLASS__, 'render_health_status_block' ),
				'attributes'      => array(
					'title'         => array(
						'type'    => 'string',
						'default' => __( 'System Health Status', 'wp-mcp-ai' ),
					),
					'showBreakdown' => array(
						'type'    => 'boolean',
						'default' => true,
					),
				),
			)
		);

		// Test Results Table Block.
		register_block_type(
			'wp-mcp-ai/test-results-table',
			array(
				'render_callback' => array( __CLASS__, 'render_test_results_block' ),
				'attributes'      => array(
					'title'    => array(
						'type'    => 'string',
						'default' => __( 'Test Results', 'wp-mcp-ai' ),
					),
					'testType' => array(
						'type'    => 'string',
						'default' => '',
					),
					'limit'    => array(
						'type'    => 'number',
						'default' => 10,
					),
				),
			)
		);

		// Performance Recommendations Block.
		register_block_type(
			'wp-mcp-ai/performance-recommendations',
			array(
				'render_callback' => array( __CLASS__, 'render_recommendations_block' ),
				'attributes'      => array(
					'title'    => array(
						'type'    => 'string',
						'default' => __( 'Performance Recommendations', 'wp-mcp-ai' ),
					),
					'severity' => array(
						'type'    => 'string',
						'default' => 'all',
					),
					'limit'    => array(
						'type'    => 'number',
						'default' => 5,
					),
				),
			)
		);

		// Performance Trends Chart Block.
		register_block_type(
			'wp-mcp-ai/performance-trends',
			array(
				'render_callback' => array( __CLASS__, 'render_trends_block' ),
				'attributes'      => array(
					'title'      => array(
						'type'    => 'string',
						'default' => __( 'Performance Trends', 'wp-mcp-ai' ),
					),
					'component'  => array(
						'type'    => 'string',
						'default' => 'rest_api',
					),
					'timePeriod' => array(
						'type'    => 'string',
						'default' => '-7 days',
					),
				),
			)
		);
	}

	/**
	 * Enqueue block editor assets.
	 */
	public static function enqueue_block_editor_assets() {
		wp_enqueue_script(
			'wp-mcp-ai-performance-blocks',
			WP_MCP_AI_URL . 'assets/js/performance-blocks.js',
			array( 'wp-blocks', 'wp-element', 'wp-editor', 'wp-components' ),
			WP_MCP_AI_VERSION,
			true
		);

		wp_localize_script(
			'wp-mcp-ai-performance-blocks',
			'wpMcpAiPerformanceBlocks',
			array(
				'ajaxUrl' => admin_url( 'admin-ajax.php' ),
				'nonce'   => wp_create_nonce( 'wp_mcp_ai_performance' ),
			)
		);
	}

	/**
	 * Render the performance test runner block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_test_runner_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to run performance tests.', 'wp-mcp-ai' ) . '</p>';
		}

		$title         = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Performance Test Runner', 'wp-mcp-ai' );
		$enabled_tests = isset( $attributes['enabledTests'] ) ? $attributes['enabledTests'] : array();

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-performance-test-runner">
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="test-runner-controls">
				<?php foreach ( $enabled_tests as $test_type ) : ?>
					<button class="button button-primary test-runner-btn" data-test-type="<?php echo esc_attr( $test_type ); ?>">
						<?php echo esc_html( ucfirst( $test_type ) . ' ' . __( 'Test', 'wp-mcp-ai' ) ); ?>
					</button>
				<?php endforeach; ?>
			</div>
			<div class="test-runner-results" style="display:none;"></div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the performance metrics block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_metrics_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view performance metrics.', 'wp-mcp-ai' ) . '</p>';
		}

		$title       = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Performance Metrics', 'wp-mcp-ai' );
		$component   = isset( $attributes['component'] ) ? $attributes['component'] : '';
		$time_period = isset( $attributes['timePeriod'] ) ? $attributes['timePeriod'] : '-24 hours';

		$metrics = self::get_performance_metrics( $component, $time_period );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-performance-metrics">
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="metrics-grid">
				<div class="metric-card">
					<span class="metric-label"><?php esc_html_e( 'Avg Response Time', 'wp-mcp-ai' ); ?></span>
					<span class="metric-value"><?php echo esc_html( number_format( $metrics['avg_response_time'], 2 ) ); ?> ms</span>
				</div>
				<div class="metric-card">
					<span class="metric-label"><?php esc_html_e( 'Avg Memory', 'wp-mcp-ai' ); ?></span>
					<span class="metric-value"><?php echo esc_html( number_format( $metrics['avg_memory_usage'], 2 ) ); ?> MB</span>
				</div>
				<div class="metric-card">
					<span class="metric-label"><?php esc_html_e( 'Avg DB Queries', 'wp-mcp-ai' ); ?></span>
					<span class="metric-value"><?php echo esc_html( number_format( $metrics['avg_db_queries'], 0 ) ); ?></span>
				</div>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the system health status block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_health_status_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view system health.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'System Health Status', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-system-health-status">
			<h3><?php echo esc_html( $title ); ?></h3>
			<div class="health-status-content">
				<p><?php esc_html_e( 'System health monitoring active. Check Elementor widgets for detailed view.', 'wp-mcp-ai' ); ?></p>
			</div>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the test results block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_test_results_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view test results.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Test Results', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-test-results-table">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'Test results displayed here. Use Elementor widget for full table view.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the recommendations block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_recommendations_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view recommendations.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Performance Recommendations', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-performance-recommendations">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'AI-generated recommendations displayed here.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Render the trends block.
	 *
	 * @param array $attributes Block attributes.
	 * @return string Block HTML.
	 */
	public static function render_trends_block( $attributes ) {
		if ( ! current_user_can( 'manage_options' ) ) {
			return '<p>' . esc_html__( 'You do not have permission to view performance trends.', 'wp-mcp-ai' ) . '</p>';
		}

		$title = isset( $attributes['title'] ) ? $attributes['title'] : __( 'Performance Trends', 'wp-mcp-ai' );

		ob_start();
		?>
		<div class="wp-block-wp-mcp-ai-performance-trends">
			<h3><?php echo esc_html( $title ); ?></h3>
			<p><?php esc_html_e( 'Performance trends chart. Use Elementor widget for full chart view.', 'wp-mcp-ai' ); ?></p>
		</div>
		<?php
		return ob_get_clean();
	}

	/**
	 * Get performance metrics.
	 *
	 * @param string $component   Component filter.
	 * @param string $time_period Time period.
	 * @return array Metrics data.
	 */
	protected static function get_performance_metrics( $component, $time_period ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array(
				'avg_response_time' => 0,
				'avg_memory_usage'  => 0,
				'avg_db_queries'    => 0,
			);
		}

		if ( empty( $component ) ) {
			$component = 'rest_api';
		}

		$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $component, $time_period );

		return array(
			'avg_response_time' => isset( $trends['avg_response_time'] ) ? $trends['avg_response_time'] : 0,
			'avg_memory_usage'  => isset( $trends['avg_memory_usage'] ) ? $trends['avg_memory_usage'] : 0,
			'avg_db_queries'    => isset( $trends['avg_db_queries'] ) ? $trends['avg_db_queries'] : 0,
		);
	}
}

// Initialize the blocks.
WP_MCP_AI_Performance_Blocks::init();
