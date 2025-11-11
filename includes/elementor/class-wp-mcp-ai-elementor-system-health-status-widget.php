<?php
/**
 * Elementor widget for displaying system health status overview.
 *
 * Provides a quick snapshot of overall system health to help AI assistants
 * rapidly identify critical issues.
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
 * Elementor widget definition for system health status.
 */
class WP_MCP_AI_Elementor_System_Health_Status_Widget extends \Elementor\Widget_Base {
	use WP_MCP_AI_Elementor_Text_Formatting;

	/**
	 * Widget slug.
	 */
	public function get_name() {
		return 'wp_mcp_ai_system_health_status';
	}

	/**
	 * Widget title shown in the Elementor editor.
	 */
	public function get_title() {
		return __( 'WP oOS System Health Status', 'wp-mcp-ai' );
	}

	/**
	 * Widget icon for Elementor panel.
	 */
	public function get_icon() {
		return 'eicon-heart-o';
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
		return array( 'system', 'health', 'status', 'overview', 'monitoring', 'mcp' );
	}

	/**
	 * Register controls for the widget settings.
	 */
	protected function register_controls() {
		$this->start_controls_section(
			'section_content',
			array(
				'label' => __( 'Health Status Settings', 'wp-mcp-ai' ),
			)
		);

		$this->add_control(
			'title',
			array(
				'label'       => __( 'Title', 'wp-mcp-ai' ),
				'type'        => \Elementor\Controls_Manager::TEXT,
				'default'     => __( 'System Health Status', 'wp-mcp-ai' ),
				'placeholder' => __( 'Enter title…', 'wp-mcp-ai' ),
				'label_block' => true,
			)
		);

		$this->add_control(
			'show_component_breakdown',
			array(
				'label'        => __( 'Show Component Breakdown', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->add_control(
			'show_critical_issues',
			array(
				'label'        => __( 'Show Critical Issues Count', 'wp-mcp-ai' ),
				'type'         => \Elementor\Controls_Manager::SWITCHER,
				'label_on'     => __( 'Yes', 'wp-mcp-ai' ),
				'label_off'    => __( 'No', 'wp-mcp-ai' ),
				'return_value' => 'yes',
				'default'      => 'yes',
			)
		);

		$this->end_controls_section();

		$this->register_theme_style_controls(
			array(
				'section_id' => 'section_style_health',
				'selectors'  => array(
					'container' => '{{WRAPPER}} .wp-mcp-ai-system-health',
					'heading'   => '{{WRAPPER}} .wp-mcp-ai-system-health__title',
					'text'      => '{{WRAPPER}} .wp-mcp-ai-system-health__text',
				),
			)
		);
	}

	/**
	 * Render the widget on the front-end.
	 */
	protected function render() {
		if ( ! current_user_can( 'manage_options' ) ) {
			echo '<div class="wp-mcp-ai-system-health">';
			echo '<p>' . esc_html__( 'You do not have permission to view system health.', 'wp-mcp-ai' ) . '</p>';
			echo '</div>';
			return;
		}

		$settings = $this->get_settings_for_display();

		$title                = isset( $settings['title'] ) ? $settings['title'] : '';
		$show_breakdown       = ! empty( $settings['show_component_breakdown'] ) && 'yes' === $settings['show_component_breakdown'];
		$show_critical_issues = ! empty( $settings['show_critical_issues'] ) && 'yes' === $settings['show_critical_issues'];

		// Get health data.
		$health = $this->calculate_system_health();

		echo '<div class="wp-mcp-ai-system-health">';

		if ( ! empty( $title ) ) {
			echo '<h3 class="wp-mcp-ai-system-health__title">' . esc_html( $title ) . '</h3>';
		}

		// Overall status.
		$status_class = 'status-' . sanitize_html_class( $health['overall_status'] );
		$status_icon  = $this->get_status_icon( $health['overall_status'] );

		echo '<div class="wp-mcp-ai-system-health__overall ' . esc_attr( $status_class ) . '">';
		echo '<div class="wp-mcp-ai-system-health__status-icon">';
		echo '<span class="dashicons dashicons-' . esc_attr( $status_icon ) . '"></span>';
		echo '</div>';
		echo '<div class="wp-mcp-ai-system-health__status-content">';
		echo '<div class="wp-mcp-ai-system-health__status-label">' . esc_html( $this->get_status_label( $health['overall_status'] ) ) . '</div>';
		echo '<div class="wp-mcp-ai-system-health__status-score">' . esc_html__( 'Health Score:', 'wp-mcp-ai' ) . ' ' . esc_html( $health['health_score'] ) . '%</div>';
		echo '</div>';
		echo '</div>';

		// Critical issues.
		if ( $show_critical_issues && $health['critical_issues'] > 0 ) {
			echo '<div class="wp-mcp-ai-system-health__critical-alert">';
			echo '<span class="dashicons dashicons-warning"></span>';
			echo '<span>' . sprintf(
				/* translators: %d: Number of critical issues */
				_n( '%d critical issue requires immediate attention', '%d critical issues require immediate attention', $health['critical_issues'], 'wp-mcp-ai' ),
				$health['critical_issues']
			) . '</span>';
			echo '</div>';
		}

		// Component breakdown.
		if ( $show_breakdown && ! empty( $health['components'] ) ) {
			echo '<div class="wp-mcp-ai-system-health__components">';
			echo '<h4>' . esc_html__( 'Component Status', 'wp-mcp-ai' ) . '</h4>';
			echo '<div class="wp-mcp-ai-system-health__components-grid">';

			foreach ( $health['components'] as $component_key => $component_data ) {
				$component_status_class = 'status-' . sanitize_html_class( $component_data['status'] );

				echo '<div class="wp-mcp-ai-system-health__component ' . esc_attr( $component_status_class ) . '">';
				echo '<div class="wp-mcp-ai-system-health__component-name">' . esc_html( $component_data['name'] ) . '</div>';
				echo '<div class="wp-mcp-ai-system-health__component-indicator"></div>';
				if ( isset( $component_data['last_test'] ) ) {
					echo '<div class="wp-mcp-ai-system-health__component-meta">' . esc_html( $component_data['last_test'] ) . '</div>';
				}
				echo '</div>';
			}

			echo '</div>';
			echo '</div>';
		}

		echo '</div>';
	}

	/**
	 * Calculate overall system health based on recent test results.
	 *
	 * @return array Health data.
	 */
	protected function calculate_system_health() {
		if ( ! class_exists( 'WP_MCP_AI_Performance_Monitor_CCT' ) ) {
			return array(
				'overall_status'  => 'unknown',
				'health_score'    => 0,
				'critical_issues' => 0,
				'components'      => array(),
			);
		}

		$components = array(
			'rest_api'      => __( 'REST API', 'wp-mcp-ai' ),
			'chat_ui'       => __( 'Chat UI', 'wp-mcp-ai' ),
			'mcp_core'      => __( 'MCP Core', 'wp-mcp-ai' ),
			'elementor'     => __( 'Elementor', 'wp-mcp-ai' ),
			'cpt_ai_peer'   => __( 'AI Peer CPT', 'wp-mcp-ai' ),
			'cpt_assistant' => __( 'Assistant CPT', 'wp-mcp-ai' ),
		);

		$component_health = array();
		$total_score      = 0;
		$component_count  = 0;
		$critical_issues  = 0;

		foreach ( $components as $key => $name ) {
			$trends = WP_MCP_AI_Performance_Monitor_CCT::get_performance_trends( $key, '-24 hours' );

			if ( ! isset( $trends['status_distribution'] ) || empty( $trends['status_distribution'] ) ) {
				$component_health[ $key ] = array(
					'name'   => $name,
					'status' => 'unknown',
				);
				continue;
			}

			$passed  = isset( $trends['status_distribution']['passed'] ) ? $trends['status_distribution']['passed'] : 0;
			$warning = isset( $trends['status_distribution']['warning'] ) ? $trends['status_distribution']['warning'] : 0;
			$failed  = isset( $trends['status_distribution']['failed'] ) ? $trends['status_distribution']['failed'] : 0;
			$total   = $passed + $warning + $failed;

			if ( $total === 0 ) {
				$component_health[ $key ] = array(
					'name'   => $name,
					'status' => 'unknown',
				);
				continue;
			}

			$score        = ( ( $passed * 100 ) + ( $warning * 50 ) ) / $total;
			$total_score += $score;
			++$component_count;

			if ( $failed > 0 ) {
				$critical_issues += $failed;
			}

			// Determine status.
			if ( $failed > $passed ) {
				$status = 'critical';
			} elseif ( $warning > $passed ) {
				$status = 'warning';
			} elseif ( $passed > 0 ) {
				$status = 'good';
			} else {
				$status = 'unknown';
			}

			$component_health[ $key ] = array(
				'name'      => $name,
				'status'    => $status,
				'last_test' => sprintf( __( '%d tests', 'wp-mcp-ai' ), $total ),
			);
		}

		// Calculate overall score.
		$health_score = $component_count > 0 ? round( $total_score / $component_count ) : 0;

		// Determine overall status.
		if ( $health_score >= 80 && $critical_issues === 0 ) {
			$overall_status = 'good';
		} elseif ( $health_score >= 60 || $critical_issues === 0 ) {
			$overall_status = 'warning';
		} else {
			$overall_status = 'critical';
		}

		return array(
			'overall_status'  => $overall_status,
			'health_score'    => $health_score,
			'critical_issues' => $critical_issues,
			'components'      => $component_health,
		);
	}

	/**
	 * Get status icon name.
	 *
	 * @param string $status Status.
	 * @return string Icon name.
	 */
	protected function get_status_icon( $status ) {
		switch ( $status ) {
			case 'good':
				return 'yes-alt';
			case 'warning':
				return 'warning';
			case 'critical':
				return 'dismiss';
			default:
				return 'info';
		}
	}

	/**
	 * Get status label.
	 *
	 * @param string $status Status.
	 * @return string Label.
	 */
	protected function get_status_label( $status ) {
		switch ( $status ) {
			case 'good':
				return __( 'System is healthy', 'wp-mcp-ai' );
			case 'warning':
				return __( 'Some issues detected', 'wp-mcp-ai' );
			case 'critical':
				return __( 'Critical issues require attention', 'wp-mcp-ai' );
			default:
				return __( 'Status unknown', 'wp-mcp-ai' );
		}
	}
}
?>
<style>
.wp-mcp-ai-system-health {
	padding: 20px;
	background: #fff;
	border: 1px solid #ddd;
	border-radius: 4px;
}
.wp-mcp-ai-system-health__title {
	margin-top: 0;
	margin-bottom: 20px;
}
.wp-mcp-ai-system-health__overall {
	display: flex;
	align-items: center;
	gap: 20px;
	padding: 20px;
	border-radius: 8px;
	margin-bottom: 20px;
}
.status-good {
	background: #d4edda;
	border: 2px solid #46b450;
}
.status-warning {
	background: #fff3cd;
	border: 2px solid #ffb900;
}
.status-critical {
	background: #f8d7da;
	border: 2px solid #dc3232;
}
.status-unknown {
	background: #f0f0f1;
	border: 2px solid #999;
}
.wp-mcp-ai-system-health__status-icon {
	font-size: 48px;
	line-height: 1;
}
.status-good .dashicons {
	color: #46b450;
}
.status-warning .dashicons {
	color: #ffb900;
}
.status-critical .dashicons {
	color: #dc3232;
}
.status-unknown .dashicons {
	color: #999;
}
.wp-mcp-ai-system-health__status-label {
	font-size: 20px;
	font-weight: 600;
	margin-bottom: 5px;
}
.wp-mcp-ai-system-health__status-score {
	font-size: 14px;
	opacity: 0.8;
}
.wp-mcp-ai-system-health__critical-alert {
	display: flex;
	align-items: center;
	gap: 10px;
	padding: 12px;
	background: #f8d7da;
	border-left: 4px solid #dc3232;
	border-radius: 4px;
	margin-bottom: 20px;
	color: #721c24;
	font-weight: 500;
}
.wp-mcp-ai-system-health__critical-alert .dashicons {
	color: #dc3232;
}
.wp-mcp-ai-system-health__components h4 {
	margin-top: 0;
	margin-bottom: 15px;
}
.wp-mcp-ai-system-health__components-grid {
	display: grid;
	grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
	gap: 15px;
}
.wp-mcp-ai-system-health__component {
	padding: 15px;
	border: 1px solid #ddd;
	border-radius: 4px;
	position: relative;
}
.wp-mcp-ai-system-health__component.status-good {
	background: #f0f9f1;
	border-color: #46b450;
}
.wp-mcp-ai-system-health__component.status-warning {
	background: #fffbf0;
	border-color: #ffb900;
}
.wp-mcp-ai-system-health__component.status-critical {
	background: #fef5f5;
	border-color: #dc3232;
}
.wp-mcp-ai-system-health__component-name {
	font-weight: 600;
	margin-bottom: 5px;
}
.wp-mcp-ai-system-health__component-indicator {
	width: 10px;
	height: 10px;
	border-radius: 50%;
	position: absolute;
	top: 15px;
	right: 15px;
}
.status-good .wp-mcp-ai-system-health__component-indicator {
	background: #46b450;
}
.status-warning .wp-mcp-ai-system-health__component-indicator {
	background: #ffb900;
}
.status-critical .wp-mcp-ai-system-health__component-indicator {
	background: #dc3232;
}
.status-unknown .wp-mcp-ai-system-health__component-indicator {
	background: #999;
}
.wp-mcp-ai-system-health__component-meta {
	font-size: 12px;
	color: #666;
}
</style>
