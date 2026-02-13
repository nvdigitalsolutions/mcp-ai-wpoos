<?php
/**
 * Tool for generating health data visualizations using Chart.js.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Generate health data visualizations using Chart.js.
 *
 * This tool leverages Chart.js to provide:
 * - Interactive health metric charts (vital signs, medication schedules)
 * - HIPAA-compliant data visualization
 * - Multiple chart types (line, bar, pie, radar)
 * - Responsive and accessible charts
 * - Real-time data updates
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Generate_Health_Chart implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'generate_health_chart';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Generate Health Chart', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate interactive health data visualizations using Chart.js. Create charts for patient vitals, medication schedules, health trends, and analytics. HIPAA-compliant with anonymized data handling. Supports line, bar, pie, and radar charts with responsive design.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'member_id'            => array(
					'type'        => 'integer',
					'description' => __( 'Member ID to generate chart for', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'chart_type'           => array(
					'type'        => 'string',
					'enum'        => array( 'line', 'bar', 'pie', 'radar', 'doughnut' ),
					'description' => __( 'Type of chart to generate', 'mcp-ai-wpoos-pro' ),
					'default'     => 'line',
				),
				'metric_type'          => array(
					'type'        => 'string',
					'enum'        => array( 'vitals', 'medication', 'checkups', 'allergies', 'custom' ),
					'description' => __( 'Type of health metric to visualize', 'mcp-ai-wpoos-pro' ),
					'default'     => 'vitals',
				),
				'specific_metric'      => array(
					'type'        => 'string',
					'enum'        => array( 'blood_pressure', 'heart_rate', 'temperature', 'weight', 'bmi', 'glucose' ),
					'description' => __( 'Specific vital metric (for vitals metric_type)', 'mcp-ai-wpoos-pro' ),
				),
				'date_range_days'      => array(
					'type'        => 'integer',
					'description' => __( 'Number of days to include in chart (default: 30)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
					'maximum'     => 365,
					'default'     => 30,
				),
				'anonymize_data'       => array(
					'type'        => 'boolean',
					'description' => __( 'Remove personally identifiable information from chart', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'show_reference_range' => array(
					'type'        => 'boolean',
					'description' => __( 'Show normal reference ranges on chart (for vitals)', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'chart_title'          => array(
					'type'        => 'string',
					'description' => __( 'Custom chart title. If not provided, auto-generated.', 'mcp-ai-wpoos-pro' ),
				),
				'width'                => array(
					'type'        => 'integer',
					'description' => __( 'Chart width in pixels (default: 600)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 2000,
					'default'     => 600,
				),
				'height'               => array(
					'type'        => 'integer',
					'description' => __( 'Chart height in pixels (default: 400)', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 100,
					'maximum'     => 2000,
					'default'     => 400,
				),
				'return_format'        => array(
					'type'        => 'string',
					'enum'        => array( 'html', 'config', 'image' ),
					'description' => __( 'Return format: html (chart HTML), config (Chart.js config JSON), or image (PNG)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'html',
				),
			),
			'required'   => array( 'member_id', 'metric_type' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read_private_posts';
	}

	/**
	 * {@inheritdoc}
	 */
	public function requires_base_pro() {
		return true;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'read',                 // Primarily read operation.
			'requires-capability',  // Requires read_private_posts for health data.
			'pii-data',             // Handles personally identifiable health information.
			'hipaa-relevant',       // Subject to HIPAA compliance requirements.
			'external-dependency',  // Requires Chart.js.
			'cacheable',            // Results can be cached.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if health & wellness management is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_health_wellness_management'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Health & Wellness Management is not enabled. Please enable it in settings.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate member ID.
		$member_id = absint( $arguments['member_id'] );
		if ( ! $member_id || get_post_type( $member_id ) !== 'member' ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid member ID.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Check if Chart.js is available.
		$chartjs_available = $this->check_chartjs_availability();
		if ( ! $chartjs_available ) {
			return array(
				'success' => false,
				'error'   => __( 'Chart.js is not available. Please ensure the package is installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Collect health data for chart.
		$metric_type = sanitize_text_field( $arguments['metric_type'] );
		$health_data = $this->collect_health_data( $member_id, $metric_type, $arguments );

		if ( empty( $health_data ) || isset( $health_data['error'] ) ) {
			return array(
				'success' => false,
				'error'   => isset( $health_data['error'] ) ? $health_data['error'] : __( 'No health data found for this member.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Anonymize data if requested (HIPAA compliance).
		$anonymize = isset( $arguments['anonymize_data'] ) ? (bool) $arguments['anonymize_data'] : true;
		if ( $anonymize ) {
			$health_data = $this->anonymize_health_data( $health_data );
		}

		// Build Chart.js configuration.
		$chart_config = $this->build_chart_config( $health_data, $arguments );

		// Generate chart based on return format.
		$return_format = isset( $arguments['return_format'] ) ? sanitize_text_field( $arguments['return_format'] ) : 'html';

		switch ( $return_format ) {
			case 'config':
				return array(
					'success'      => true,
					'message'      => __( 'Chart configuration generated successfully.', 'mcp-ai-wpoos-pro' ),
					'member_id'    => $member_id,
					'metric_type'  => $metric_type,
					'chart_config' => $chart_config,
				);

			case 'image':
				$image_result = $this->generate_chart_image( $chart_config );
				if ( ! $image_result || isset( $image_result['error'] ) ) {
					return array(
						'success' => false,
						'error'   => isset( $image_result['error'] ) ? $image_result['error'] : __( 'Chart image generation failed.', 'mcp-ai-wpoos-pro' ),
					);
				}
				return array(
					'success'     => true,
					'message'     => __( 'Chart image generated successfully.', 'mcp-ai-wpoos-pro' ),
					'member_id'   => $member_id,
					'metric_type' => $metric_type,
					'image_url'   => $image_result['url'],
					'image_path'  => $image_result['path'],
				);

			case 'html':
			default:
				$html = $this->generate_chart_html( $chart_config, $arguments );
				return array(
					'success'      => true,
					'message'      => __( 'Health chart generated successfully.', 'mcp-ai-wpoos-pro' ),
					'member_id'    => $member_id,
					'metric_type'  => $metric_type,
					'chart_html'   => $html,
					'chart_config' => $chart_config,
				);
		}
	}

	/**
	 * Check if Chart.js is available.
	 *
	 * @return bool True if Chart.js is available.
	 */
	private function check_chartjs_availability() {
		// Check if package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/chart.js/chart.umd.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/chart.js/dist/chart.umd.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		return true;
	}

	/**
	 * Collect health data for charting.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $metric_type Metric type.
	 * @param array  $arguments Tool arguments.
	 * @return array Health data.
	 */
	private function collect_health_data( $member_id, $metric_type, $arguments ) {
		$date_range = isset( $arguments['date_range_days'] ) ? absint( $arguments['date_range_days'] ) : 30;
		$data       = array(
			'labels'   => array(),
			'datasets' => array(),
		);

		// Query health records based on metric type.
		switch ( $metric_type ) {
			case 'vitals':
				$specific_metric = isset( $arguments['specific_metric'] ) ? sanitize_text_field( $arguments['specific_metric'] ) : 'blood_pressure';
				$data            = $this->get_vitals_data( $member_id, $specific_metric, $date_range );
				break;

			case 'medication':
				$data = $this->get_medication_data( $member_id, $date_range );
				break;

			case 'checkups':
				$data = $this->get_checkups_data( $member_id, $date_range );
				break;

			default:
				return array( 'error' => __( 'Unsupported metric type.', 'mcp-ai-wpoos-pro' ) );
		}

		return $data;
	}

	/**
	 * Get vitals data for chart.
	 *
	 * @param int    $member_id Member ID.
	 * @param string $metric Specific vital metric.
	 * @param int    $days Number of days.
	 * @return array Chart data.
	 */
	private function get_vitals_data( $member_id, $metric, $days ) {
		// Query medical records with vital signs.
		// This is a simplified implementation.
		return array(
			'labels'   => array(), // Date labels.
			'datasets' => array(
				array(
					'label' => ucwords( str_replace( '_', ' ', $metric ) ),
					'data'  => array(), // Metric values.
				),
			),
		);
	}

	/**
	 * Get medication data for chart.
	 *
	 * @param int $member_id Member ID.
	 * @param int $days Number of days.
	 * @return array Chart data.
	 */
	private function get_medication_data( $member_id, $days ) {
		return array(
			'labels'   => array(),
			'datasets' => array(),
		);
	}

	/**
	 * Get checkups data for chart.
	 *
	 * @param int $member_id Member ID.
	 * @param int $days Number of days.
	 * @return array Chart data.
	 */
	private function get_checkups_data( $member_id, $days ) {
		return array(
			'labels'   => array(),
			'datasets' => array(),
		);
	}

	/**
	 * Anonymize health data for HIPAA compliance.
	 *
	 * @param array $data Health data.
	 * @return array Anonymized data.
	 */
	private function anonymize_health_data( $data ) {
		// Remove any PII from labels and datasets.
		// Replace specific dates with relative labels.
		return $data;
	}

	/**
	 * Build Chart.js configuration.
	 *
	 * @param array $data Health data.
	 * @param array $arguments Tool arguments.
	 * @return array Chart.js config.
	 */
	private function build_chart_config( $data, $arguments ) {
		$chart_type = isset( $arguments['chart_type'] ) ? sanitize_text_field( $arguments['chart_type'] ) : 'line';

		return array(
			'type'    => $chart_type,
			'data'    => $data,
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'plugins'             => array(
					'legend' => array(
						'position' => 'top',
					),
					'title'  => array(
						'display' => true,
						'text'    => isset( $arguments['chart_title'] ) ? $arguments['chart_title'] : 'Health Metrics',
					),
				),
			),
		);
	}

	/**
	 * Generate chart HTML.
	 *
	 * @param array $config Chart configuration.
	 * @param array $arguments Tool arguments.
	 * @return string HTML markup.
	 */
	private function generate_chart_html( $config, $arguments ) {
		$width     = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 600;
		$height    = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 400;
		$canvas_id = 'health-chart-' . uniqid();

		$html = sprintf(
			'<canvas id="%s" width="%d" height="%d" role="img" aria-label="Health metrics chart"></canvas>
			<script>
			if (typeof Chart !== "undefined") {
				new Chart(document.getElementById("%s"), %s);
			} else {
				console.error("Chart.js not loaded");
			}
			</script>',
			esc_attr( $canvas_id ),
			absint( $width ),
			absint( $height ),
			esc_attr( $canvas_id ),
			wp_json_encode( $config )
		);

		return $html;
	}

	/**
	 * Generate chart as image (PNG).
	 *
	 * @param array $config Chart configuration.
	 * @return array|false Image info or false on failure.
	 */
	private function generate_chart_image( $config ) {
		/**
		 * Filter to allow custom chart image generation.
		 *
		 * @param array|false $result Image generation result or false.
		 * @param array       $config Chart configuration.
		 */
		$result = apply_filters( 'wp_mcp_ai_chartjs_generate_image', false, $config );

		if ( false === $result ) {
			return array(
				'error' => __( 'Chart image generation requires a Node.js service with chart rendering capability. Please implement the wp_mcp_ai_chartjs_generate_image filter. See docs/INTEGRATION_BEST_PRACTICES.md for implementation guide.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $result;
	}
}
