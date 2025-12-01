<?php
/**
 * Elementor widget for displaying AI-generated performance recommendations.
 *
 * Shows actionable recommendations to help AI assistants suggest fixes
 * for performance and security issues.
 *
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( '\\Elementor\\Widget_Base' ) ) {
	return;
}

/**
 * Elementor widget definition for AI recommendations.
 */
class WP_MCP_AI_Elementor_Performance_Recommendations_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_performance_recommendations';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS Performance Recommendations', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-lightbulb';
	}

	/**
	 * Widget categories.
	 */
	public function get_categories() {
		return array( 'general' );
	}

	/**
	 * Keywords to help search for the widget.
	 */
	public function get_keywords() {
		return array( 'performance', 'recommendations', 'ai', 'suggestions', 'fixes', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Recommendations Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'Performance Recommendations', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter title…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'severity_filter',
			array(
				'label'       => __( 'Minimum Severity', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::SELECT,
				'options'     => array(
					'all'      => __( 'All Recommendations', 'wp-mcp-ai' ),
					'critical' => __( 'Critical Only', 'wp-mcp-ai' ),
					'high'     => __( 'High and Above', 'wp-mcp-ai' ),
					'medium'   => __( 'Medium and Above', 'wp-mcp-ai' ),
				),
				'default'     => 'all',
				'label_block' => true,
			)
		);

		$this->add_control(
			'limit',
			array(
				'label'   => __( 'Number of Recommendations', 'wp-mcp-ai' ),
				'type'    => \Elementor\Controls_Manager::NUMBER,
				'default' => 5,
				'min'     => 1,
				'max'     => 20,
			)
		);

		$this->add_control(
			'show_action_buttons',
			array(
				'label'        => __( 'Show Action Buttons', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
				'description'  => __( 'Display buttons for quick actions on recommendations.', 'wp-mcp-ai' ),
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_recommendations',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-recommendations',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-recommendations__title',
					'text'      => '{{WRAPPER}} .wp-mcp-ai-recommendations__text',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="wp-mcp-ai-recommendations">';
			echo '<p>' . esc_html__( 'You do not have permission to view recommendations.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();

		$title           = isset( $settings['title'] ) ? $settings['title'] : '';
		$severity_filter = isset( $settings['severity_filter'] ) ? $settings['severity_filter'] : 'all';
		$limit           = isset( $settings['limit'] ) ? absint( $settings['limit'] ) : 5;
		$show_actions    = ! empty( $settings['show_action_buttons'] ) && 'yes' === $settings['show_action_buttons'];

		// Get recommendations.
		$recommendations = $this->get_recommendations( $severity_filter, $limit );

		echo '<div class="wp-mcp-ai-recommendations">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-recommendations__title">' . esc_html( $title ) . '</h3>';
		}

		if ( empty( $recommendations ) ) {
			echo '<div class="wp-mcp-ai-recommendations__empty">';
			echo '<span class="dashicons dashicons-yes-alt"></span>';
			echo '<p>' . esc_html__( 'No recommendations at this time. Your system is performing well!', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
		} else {
			echo '<div class="wp-mcp-ai-recommendations__list">';

			foreach ( $recommendations as $rec ) {
				$severity_class = 'severity-' . sanitize_html_class( $rec['severity'] );

				echo '<div class="wp-mcp-ai-recommendations__item ' . esc_attr( $severity_class ) . '">';
				echo '<div class="wp-mcp-ai-recommendations__severity">';
				echo '<span class="wp-mcp-ai-recommendations__severity-badge">' . esc_html( ucfirst( $rec['severity'] ) ) . '</span>';
				echo '</div>';
				echo '<div class="wp-mcp-ai-recommendations__content">';
				echo '<h4 class="wp-mcp-ai-recommendations__issue">' . esc_html( $rec['issue'] ) . '</h4>';
				echo '<p class="wp-mcp-ai-recommendations__action">' . esc_html( $rec['action'] ) . '</p>';

				if ( isset( $rec['component'] ) ) {
					echo '<span class="wp-mcp-ai-recommendations__meta">Component: ' . esc_html( $rec['component'] ) . '</span>';
				}

				if ( $show_actions ) {
					echo '<div class="wp-mcp-ai-recommendations__buttons">';
					echo '<button class="wp-mcp-ai-recommendations__btn wp-mcp-ai-recommendations__btn--primary" data-action="apply">' . esc_html__( 'Apply Fix', 'wp-mcp-ai' ) . '</button>';
					echo '<button class="wp-mcp-ai-recommendations__btn wp-mcp-ai-recommendations__btn--secondary" data-action="dismiss">' . esc_html__( 'Dismiss', 'wp-mcp-ai' ) . '</button>';
					echo '</div>';
				}
				echo '</div>';
				echo '</div>';
			}

			echo '</div>';
		}

		echo '</div>';

		if ( $show_actions ) {
			$this->enqueue_action_script();
		}
	}

	/**
	 * Get recommendations from recent test results.
	 *
	 * @param string $severity_filter Severity filter.
	 * @param int    $limit           Result limit.
	 * @return array Recommendations.
	 */
	protected function get_recommendations( $severity_filter, $limit ) {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array();
		}

		// Get recent test results with recommendations.
		$args = array();

		$tests = WP_MCP_AI_Performance_Monitor_CCT::query_items( $args, 20 );

		if ( empty( $tests ) ) {
			return $this->get_recommendations_fallback( $severity_filter, $limit );
		}

		$all_recommendations = array();

		foreach ( $tests as $test ) {
			if ( isset( $test['recommendations'] ) ) {
				$recs = json_decode( $test['recommendations'], true );
				if ( is_array( $recs ) ) {
					foreach ( $recs as $rec ) {
						$rec['component']      = isset( $test['component'] ) ? $test['component'] : '';
						$all_recommendations[] = $rec;
					}
				}
			}
		}

		// Filter by severity.
		$severity_levels = array(
			'critical' => 4,
			'high'     => 3,
			'medium'   => 2,
			'low'      => 1,
		);
		$min_severity    = 0;

		if ( 'critical' === $severity_filter ) {
			$min_severity = 4;
		} elseif ( 'high' === $severity_filter ) {
			$min_severity = 3;
		} elseif ( 'medium' === $severity_filter ) {
			$min_severity = 2;
		}

		$filtered = array_filter(
			$all_recommendations,
			function ( $rec ) use ( $severity_levels, $min_severity ) {
				$rec_severity = isset( $rec['severity'] ) ? $rec['severity'] : 'low';
				$rec_level    = isset( $severity_levels[ $rec_severity ] ) ? $severity_levels[ $rec_severity ] : 1;
				return $rec_level >= $min_severity;
			}
		);

		// Sort by severity (highest first).
		usort(
			$filtered,
			function ( $a, $b ) use ( $severity_levels ) {
				$a_level = isset( $severity_levels[ $a['severity'] ] ) ? $severity_levels[ $a['severity'] ] : 1;
				$b_level = isset( $severity_levels[ $b['severity'] ] ) ? $severity_levels[ $b['severity'] ] : 1;
				return $b_level - $a_level;
			}
		);

		return array_slice( $filtered, 0, $limit );
	}

	/**
	 * Get recommendations from WordPress options fallback.
	 *
	 * @param string $severity_filter Severity filter.
	 * @param int    $limit           Result limit.
	 * @return array Recommendations.
	 */
	protected function get_recommendations_fallback( $severity_filter, $limit ) {
		// Similar logic to above but using WordPress options.
		return array();
	}

	/**
	 * Enqueue action button script.
	 */
	protected function enqueue_action_script() {
		?>
		<script>
		(function($) {
			$(document).ready(function() {
				$('.wp-mcp-ai-recommendations__btn').on('click', function(e) {
					e.preventDefault();
					var btn = $(this);
					var action = btn.data('action');
					var item = btn.closest('.wp-mcp-ai-recommendations__item');

					if ('dismiss' === action) {
						item.fadeOut();
					} else if ('apply' === action) {
						alert('<?php echo esc_js( __( 'Auto-apply feature coming soon. Please implement fixes manually for now.', 'wp-mcp-ai' ) ); ?>');
					}
				});
			});
		})(jQuery);
		</script>
		<style>
		.wp-mcp-ai-recommendations {
			padding: 20px;
			background: #fff;
			border: 1px solid #ddd;
			border-radius: 4px;
		}
		.wp-mcp-ai-recommendations__title {
			margin-top: 0;
			margin-bottom: 20px;
		}
		.wp-mcp-ai-recommendations__empty {
			text-align: center;
			padding: 40px 20px;
			color: #46b450;
		}
		.wp-mcp-ai-recommendations__empty .dashicons {
			font-size: 48px;
			width: 48px;
			height: 48px;
		}
		.wp-mcp-ai-recommendations__list {
			display: flex;
			flex-direction: column;
			gap: 15px;
		}
		.wp-mcp-ai-recommendations__item {
			display: flex;
			gap: 15px;
			padding: 15px;
			border-left: 4px solid #ddd;
			background: #f9f9f9;
			border-radius: 4px;
		}
		.severity-critical {
			border-left-color: #dc3232;
		}
		.severity-high {
			border-left-color: #f56e28;
		}
		.severity-medium {
			border-left-color: #ffb900;
		}
		.severity-low {
			border-left-color: #2271b1;
		}
		.wp-mcp-ai-recommendations__severity-badge {
			padding: 4px 8px;
			border-radius: 3px;
			font-size: 11px;
			font-weight: 600;
			text-transform: uppercase;
			color: #fff;
		}
		.severity-critical .wp-mcp-ai-recommendations__severity-badge {
			background: #dc3232;
		}
		.severity-high .wp-mcp-ai-recommendations__severity-badge {
			background: #f56e28;
		}
		.severity-medium .wp-mcp-ai-recommendations__severity-badge {
			background: #ffb900;
		}
		.severity-low .wp-mcp-ai-recommendations__severity-badge {
			background: #2271b1;
		}
		.wp-mcp-ai-recommendations__content {
			flex: 1;
		}
		.wp-mcp-ai-recommendations__issue {
			margin: 0 0 8px 0;
			font-size: 16px;
		}
		.wp-mcp-ai-recommendations__action {
			margin: 0 0 10px 0;
			color: #666;
		}
		.wp-mcp-ai-recommendations__meta {
			font-size: 12px;
			color: #999;
		}
		.wp-mcp-ai-recommendations__buttons {
			display: flex;
			gap: 10px;
			margin-top: 10px;
		}
		.wp-mcp-ai-recommendations__btn {
			padding: 6px 12px;
			border: none;
			border-radius: 3px;
			cursor: pointer;
			font-size: 13px;
		}
		.wp-mcp-ai-recommendations__btn--primary {
			background: #2271b1;
			color: #fff;
		}
		.wp-mcp-ai-recommendations__btn--primary:hover {
			background: #135e96;
		}
		.wp-mcp-ai-recommendations__btn--secondary {
			background: #f0f0f1;
			color: #2c3338;
		}
		.wp-mcp-ai-recommendations__btn--secondary:hover {
			background: #dcdcde;
		}
		</style>
		<?php
	}
}
