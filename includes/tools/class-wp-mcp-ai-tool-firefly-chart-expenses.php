<?php
/**
 * Tool that creates an expense breakdown chart from Firefly III data.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Prevent parse errors on PHP < 7.4 by exiting before class definition.
if ( version_compare( PHP_VERSION, '7.4.0', '<' ) ) {
	return;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-firefly-client.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-logger.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chart-accessibility.php';

/**
 * Creates a Chart.js pie/doughnut chart showing expense breakdown by category from Firefly III.
 */
class WP_MCP_AI_Tool_Firefly_Chart_Expenses implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Tool_Chart_Accessibility;

	const CHARTJS_VERSION = '4.4.1';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'firefly_chart_expenses';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Firefly III Expense Chart', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates an interactive Chart.js pie or doughnut chart showing expense breakdown by category from Firefly III. Fetches withdrawal transactions and groups them by category for visualization. Perfect for understanding spending patterns and budget allocation.', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'connection_id' => array(
					'type'        => 'string',
					'description' => __( 'Optional Remote Sites connection ID for Firefly III. If not provided, will use settings-based configuration.', 'mcp-ai-wpoos' ),
				),
				'start'         => array(
					'type'        => 'string',
					'description' => __( 'Start date for analysis in YYYY-MM-DD format. Defaults to 30 days ago.', 'mcp-ai-wpoos' ),
				),
				'end'           => array(
					'type'        => 'string',
					'description' => __( 'End date for analysis in YYYY-MM-DD format. Defaults to today.', 'mcp-ai-wpoos' ),
				),
				'chart_type'    => array(
					'type'        => 'string',
					'description' => __( 'Chart visualization type.', 'mcp-ai-wpoos' ),
					'enum'        => array( 'pie', 'doughnut' ),
					'default'     => 'doughnut',
				),
				'width'         => array(
					'type'        => 'integer',
					'description' => __( 'Chart width in pixels (200-1200).', 'mcp-ai-wpoos' ),
					'minimum'     => 200,
					'maximum'     => 1200,
					'default'     => 600,
				),
				'height'        => array(
					'type'        => 'integer',
					'description' => __( 'Chart height in pixels (200-800).', 'mcp-ai-wpoos' ),
					'minimum'     => 200,
					'maximum'     => 800,
					'default'     => 400,
				),
				'title'         => array(
					'type'        => 'string',
					'description' => __( 'Optional chart title.', 'mcp-ai-wpoos' ),
					'default'     => 'Expense Breakdown by Category',
				),
				'limit'         => array(
					'type'        => 'integer',
					'description' => __( 'Maximum number of transactions to fetch (1-100).', 'mcp-ai-wpoos' ),
					'minimum'     => 1,
					'maximum'     => 100,
					'default'     => 100,
				),
				'timeout'       => array(
					'type'        => 'integer',
					'description' => __( 'Request timeout in seconds (5-60).', 'mcp-ai-wpoos' ),
					'minimum'     => 5,
					'maximum'     => 60,
					'default'     => 30,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id   = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;
		$has_token = ! empty( $context['token_authenticated'] );

		if ( ! $user_id && ! $has_token ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You must be authenticated to generate Firefly III charts.', 'mcp-ai-wpoos' ), array( 'status' => rest_authorization_required_code() ) );
		}

		if ( $user_id ) {
			// Require edit_posts or manage_options capability.
			if ( ! user_can( $user_id, 'edit_posts' ) && ! user_can( $user_id, 'manage_options' ) ) {
				return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to generate financial charts.', 'mcp-ai-wpoos' ) );
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'mcp-ai-wpoos' ) );
			}
		}

		// Get connection_id if provided.
		$connection_id = isset( $arguments['connection_id'] ) ? sanitize_key( $arguments['connection_id'] ) : null;

		// Validate connection if provided.
		if ( ! empty( $connection_id ) && class_exists( 'WP_MCP_AI_Pro_Remote_Site_Manager' ) ) {
			$connection = WP_MCP_AI_Pro_Remote_Site_Manager::get_connection( $connection_id );

			if ( null === $connection ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_not_found',
					__( 'Connection not found. Please check the connection ID.', 'mcp-ai-wpoos' )
				);
			}

			// Validate connection type.
			if ( empty( $connection['connection_type'] ) || 'firefly' !== $connection['connection_type'] ) {
				return new WP_Error(
					'wp_mcp_ai_pro_wrong_connection_type',
					__( 'This connection is not a Firefly III connection.', 'mcp-ai-wpoos' )
				);
			}

			// Check if connection is enabled.
			if ( empty( $connection['enabled'] ) ) {
				return new WP_Error(
					'wp_mcp_ai_pro_connection_disabled',
					__( 'This connection is disabled. Please enable it in Remote Sites settings.', 'mcp-ai-wpoos' )
				);
			}
		}

		// Set default dates (last 30 days).
		$end_date   = isset( $arguments['end'] ) ? sanitize_text_field( $arguments['end'] ) : gmdate( 'Y-m-d' );
		$start_date = isset( $arguments['start'] ) ? sanitize_text_field( $arguments['start'] ) : gmdate( 'Y-m-d', strtotime( '-30 days' ) );

		$client  = new WP_MCP_AI_Firefly_Client( $connection_id );
		$options = array(
			'type'  => 'withdrawal', // Only expenses.
			'start' => $start_date,
			'end'   => $end_date,
		);

		if ( isset( $arguments['limit'] ) ) {
			$options['limit'] = max( 1, min( 100, absint( $arguments['limit'] ) ) );
		}
		if ( isset( $arguments['timeout'] ) ) {
			$options['timeout'] = max( 5, min( 60, absint( $arguments['timeout'] ) ) );
		}

		$result = $client->get_transactions( $options );

		if ( is_wp_error( $result ) ) {
			return $result;
		}

		// Aggregate expenses by category.
		$category_totals = array();
		$uncategorized   = 0.0;

		if ( isset( $result['data'] ) && is_array( $result['data'] ) ) {
			foreach ( $result['data'] as $transaction ) {
				if ( ! isset( $transaction['attributes'] ) ) {
					continue;
				}

				$attrs    = $transaction['attributes'];
				$amount   = isset( $attrs['amount'] ) ? abs( floatval( $attrs['amount'] ) ) : 0.0;
				$category = isset( $attrs['category_name'] ) && ! empty( $attrs['category_name'] ) ? $attrs['category_name'] : 'Uncategorized';

				if ( ! isset( $category_totals[ $category ] ) ) {
					$category_totals[ $category ] = 0.0;
				}

				$category_totals[ $category ] += $amount;
			}
		}

		// Sort by total descending.
		arsort( $category_totals );

		// Prepare Chart.js data.
		$labels = array_keys( $category_totals );
		$data   = array_values( $category_totals );

		// Generate vibrant colors for each category.
		$colors = $this->generate_colors( count( $labels ) );

		$chart_type = isset( $arguments['chart_type'] ) ? sanitize_text_field( $arguments['chart_type'] ) : 'doughnut';
		$width      = isset( $arguments['width'] ) ? max( 200, min( 1200, absint( $arguments['width'] ) ) ) : 600;
		$height     = isset( $arguments['height'] ) ? max( 200, min( 800, absint( $arguments['height'] ) ) ) : 400;
		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : 'Expense Breakdown by Category';

		// Generate unique chart ID.
		$chart_id = 'firefly-chart-' . wp_generate_password( 8, false );

		// Build Chart.js configuration.
		$chart_config = array(
			'type' => $chart_type,
			'data' => array(
				'labels'   => $labels,
				'datasets' => array(
					array(
						'label'           => 'Expenses',
						'data'            => $data,
						'backgroundColor' => $colors,
						'borderWidth'     => 2,
						'borderColor'     => '#ffffff',
					),
				),
			),
			'options' => array(
				'responsive'          => true,
				'maintainAspectRatio' => false,
				'plugins'             => array(
					'legend' => array(
						'display'  => true,
						'position' => 'right',
					),
					'title'  => array(
						'display' => ! empty( $title ),
						'text'    => $title,
						'font'    => array(
							'size' => 16,
						),
					),
					'tooltip' => array(
						'callbacks' => array(
							'label' => array(
								'_raw' => 'function(context) { 
									let label = context.label || "";
									if (label) { label += ": "; }
									label += new Intl.NumberFormat("en-US", { 
										style: "currency", 
										currency: "USD" 
									}).format(context.parsed);
									return label;
								}',
							),
						),
					),
				),
			),
		);

		// Convert the callback function placeholder to actual JavaScript.
		$chart_config_json = wp_json_encode( $chart_config );
		// Replace the placeholder with actual JS function.
		$chart_config_json = preg_replace(
			'/"_raw":"(.*?)"/',
			'$1',
			$chart_config_json
		);

		// Generate HTML output with embedded Chart.js.
		$html = sprintf(
			'<div style="width: %dpx; height: %dpx; max-width: 100%%; margin: 0 auto;">
				<canvas id="%s" width="%d" height="%d" role="img" aria-label="%s"></canvas>
			</div>
			<script src="https://cdn.jsdelivr.net/npm/chart.js@%s"></script>
			<script>
				(function() {
					const ctx = document.getElementById("%s");
					if (ctx) {
						new Chart(ctx, %s);
					}
				})();
			</script>',
			$width,
			$height,
			esc_attr( $chart_id ),
			$width,
			$height,
			esc_attr( $title ),
			self::CHARTJS_VERSION,
			esc_js( $chart_id ),
			$chart_config_json
		);

		// Add summary for frontend display.
		$total_expenses = array_sum( $data );
		$summary        = sprintf(
			/* translators: 1: total expenses, 2: number of categories, 3: date range */
			__( 'Created expense breakdown chart: $%.2f across %d categories from %s to %s', 'mcp-ai-wpoos' ),
			$total_expenses,
			count( $labels ),
			$start_date,
			$end_date
		);

		$response = array(
			'message'         => $summary,
			'summary'         => $summary,
			'html'            => $html,
			'chart_id'        => $chart_id,
			'total_expenses'  => $total_expenses,
			'category_count'  => count( $labels ),
			'categories'      => $category_totals,
			'date_range'      => array(
				'start' => $start_date,
				'end'   => $end_date,
			),
		);

		/**
		 * Allow third parties to filter the Firefly III expense chart result.
		 *
		 * @param array $response  Final response payload.
		 * @param array $arguments Original tool arguments.
		 * @param array $context   Invocation context.
		 */
		$response = apply_filters( 'wp_mcp_ai_firefly_chart_expenses_result', $response, $arguments, $context );

		return $response;
	}

	/**
	 * Generate an array of vibrant colors for chart segments.
	 *
	 * @param int $count Number of colors needed.
	 * @return array Array of color strings in rgba format.
	 */
	protected function generate_colors( $count ) {
		$colors = array();
		$hue    = 0;
		$step   = 360 / max( $count, 1 );

		for ( $i = 0; $i < $count; $i++ ) {
			$colors[] = sprintf( 'hsla(%d, 70%%, 60%%, 0.8)', round( $hue ) );
			$hue     += $step;
		}

		return $colors;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'pro',                  // Pro tier tool.
			'external-api',         // Makes external API calls.
			'requires-credentials', // Requires Firefly III API credentials.
			'requires-capability',  // Requires user capabilities.
			'read-only',            // Only reads data, does not modify state.
			'rate-limited',         // Subject to Firefly III API rate limits.
			'pii-data',             // Contains personally identifiable information.
			'cacheable',            // Results can be cached.
		);
	}
}
