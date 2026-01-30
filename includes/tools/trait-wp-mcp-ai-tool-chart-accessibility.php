<?php
/**
 * Trait for enhancing Chart.js charts with accessibility features.
 *
 * This trait provides methods to add WCAG-compliant accessibility features
 * to Chart.js charts, including ARIA labels, screen reader text, and keyboard navigation.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Chart_Accessibility
 *
 * Enhances Chart.js charts with accessibility features for WCAG 2.1 AA compliance.
 *
 * Usage:
 * ```php
 * class My_Chart_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Chart_Accessibility;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         $chart_html = $this->generate_chart( $data );
 *
 *         // Enhance with accessibility features
 *         $chart_html = $this->add_chart_accessibility( $chart_html, $data );
 *
 *         return array('message' => $chart_html);
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Chart_Accessibility {

	/**
	 * Add accessibility features to chart HTML.
	 *
	 * Enhances existing Chart.js HTML with ARIA labels, screen reader text,
	 * and keyboard navigation support.
	 *
	 * @param string $chart_html Existing chart HTML.
	 * @param array  $chart_data Chart data array.
	 * @return string Enhanced chart HTML with accessibility features.
	 */
	protected function add_chart_accessibility( $chart_html, array $chart_data = array() ) {
		// Extract chart type and title from data.
		$chart_type  = isset( $chart_data['type'] ) ? sanitize_text_field( $chart_data['type'] ) : 'chart';
		$chart_title = isset( $chart_data['options']['plugins']['title']['text'] ) ? $chart_data['options']['plugins']['title']['text'] : '';

		if ( empty( $chart_title ) && isset( $chart_data['title'] ) ) {
			$chart_title = $chart_data['title'];
		}

		// Generate ARIA label.
		$aria_label = $this->generate_chart_aria_label( $chart_type, $chart_title );

		// Generate screen reader text summary.
		$sr_text = $this->generate_chart_screen_reader_text( $chart_data );

		// Add ARIA attributes to canvas/container.
		$chart_html = $this->inject_aria_attributes( $chart_html, $aria_label );

		// Add screen reader text.
		$chart_html = $this->inject_screen_reader_text( $chart_html, $sr_text );

		// Add keyboard navigation hint.
		$chart_html = $this->inject_keyboard_hint( $chart_html );

		return $chart_html;
	}

	/**
	 * Generate ARIA label for chart.
	 *
	 * @param string $chart_type  Chart type (bar, line, pie, etc.).
	 * @param string $chart_title Chart title.
	 * @return string ARIA label.
	 */
	protected function generate_chart_aria_label( $chart_type, $chart_title ) {
		$type_labels = array(
			'bar'       => __( 'Bar chart', 'mcp-ai-wpoos' ),
			'line'      => __( 'Line chart', 'mcp-ai-wpoos' ),
			'pie'       => __( 'Pie chart', 'mcp-ai-wpoos' ),
			'doughnut'  => __( 'Doughnut chart', 'mcp-ai-wpoos' ),
			'radar'     => __( 'Radar chart', 'mcp-ai-wpoos' ),
			'polarArea' => __( 'Polar area chart', 'mcp-ai-wpoos' ),
			'scatter'   => __( 'Scatter plot', 'mcp-ai-wpoos' ),
			'bubble'    => __( 'Bubble chart', 'mcp-ai-wpoos' ),
		);

		$type_label = isset( $type_labels[ $chart_type ] ) ? $type_labels[ $chart_type ] : __( 'Chart', 'mcp-ai-wpoos' );

		if ( ! empty( $chart_title ) ) {
			/* translators: 1: chart type, 2: chart title */
			return sprintf( __( '%1$s: %2$s', 'mcp-ai-wpoos' ), $type_label, $chart_title );
		}

		return $type_label;
	}

	/**
	 * Generate screen reader text summary of chart data.
	 *
	 * Creates a text description of the chart data for screen readers.
	 *
	 * @param array $chart_data Chart data array.
	 * @return string Screen reader text.
	 */
	protected function generate_chart_screen_reader_text( array $chart_data ) {
		$text = '';

		// Add title if present.
		$title = isset( $chart_data['options']['plugins']['title']['text'] ) ? $chart_data['options']['plugins']['title']['text'] : '';
		if ( empty( $title ) && isset( $chart_data['title'] ) ) {
			$title = $chart_data['title'];
		}

		if ( ! empty( $title ) ) {
			$text .= esc_html( $title ) . '. ';
		}

		// Add data summary.
		if ( isset( $chart_data['data']['labels'] ) && isset( $chart_data['data']['datasets'] ) ) {
			$labels   = $chart_data['data']['labels'];
			$datasets = $chart_data['data']['datasets'];

			if ( ! empty( $labels ) && ! empty( $datasets ) ) {
				$text .= sprintf(
					/* translators: 1: number of data points, 2: number of datasets */
					__( 'Chart with %1$d data points across %2$d dataset(s). ', 'mcp-ai-wpoos' ),
					count( $labels ),
					count( $datasets )
				);

				// Add dataset summaries.
				foreach ( $datasets as $index => $dataset ) {
					/* translators: %d: Dataset index number */
					$label = isset( $dataset['label'] ) ? $dataset['label'] : sprintf( __( 'Dataset %d', 'mcp-ai-wpoos' ), $index + 1 );
					$data  = isset( $dataset['data'] ) ? $dataset['data'] : array();

					if ( ! empty( $data ) ) {
						$min = min( $data );
						$max = max( $data );
						$avg = array_sum( $data ) / count( $data );

						$text .= sprintf(
							/* translators: 1: dataset label, 2: min value, 3: max value, 4: average value */
							__( '%1$s: min %2$.2f, max %3$.2f, average %4$.2f. ', 'mcp-ai-wpoos' ),
							$label,
							$min,
							$max,
							$avg
						);
					}
				}
			}
		}

		return $text;
	}

	/**
	 * Inject ARIA attributes into chart HTML.
	 *
	 * @param string $chart_html Chart HTML.
	 * @param string $aria_label ARIA label text.
	 * @return string Modified HTML with ARIA attributes.
	 */
	protected function inject_aria_attributes( $chart_html, $aria_label ) {
		// Find canvas tag and add ARIA attributes.
		$chart_html = preg_replace(
			'/<canvas([^>]*)>/',
			'<canvas$1 role="img" aria-label="' . esc_attr( $aria_label ) . '">',
			$chart_html,
			1
		);

		return $chart_html;
	}

	/**
	 * Inject screen reader text into chart HTML.
	 *
	 * @param string $chart_html Chart HTML.
	 * @param string $sr_text    Screen reader text.
	 * @return string Modified HTML with screen reader text.
	 */
	protected function inject_screen_reader_text( $chart_html, $sr_text ) {
		if ( empty( $sr_text ) ) {
			return $chart_html;
		}

		// Add visually hidden screen reader text after canvas.
		$sr_html  = '<div class="wp-mcp-ai-chart-sr-only" style="position: absolute; left: -10000px; width: 1px; height: 1px; overflow: hidden;">';
		$sr_html .= esc_html( $sr_text );
		$sr_html .= '</div>';

		// Insert after canvas tag.
		$chart_html = preg_replace(
			'/(<canvas[^>]*><\/canvas>)/',
			'$1' . $sr_html,
			$chart_html,
			1
		);

		return $chart_html;
	}

	/**
	 * Inject keyboard navigation hint.
	 *
	 * @param string $chart_html Chart HTML.
	 * @return string Modified HTML with keyboard hint.
	 */
	protected function inject_keyboard_hint( $chart_html ) {
		$hint  = '<div class="wp-mcp-ai-chart-keyboard-hint" style="font-size: 11px; color: #666; margin-top: 8px; text-align: center;">';
		$hint .= '<span aria-hidden="true">💡</span> ';
		$hint .= esc_html__( 'Tip: Use Tab to navigate chart elements, Enter to interact.', 'mcp-ai-wpoos' );
		$hint .= '</div>';

		// Append to chart container.
		$chart_html .= $hint;

		return $chart_html;
	}

	/**
	 * Add data table alternative for accessibility.
	 *
	 * Creates an HTML table representation of chart data as an accessible alternative.
	 *
	 * @param array $chart_data Chart data array.
	 * @return string HTML table.
	 */
	protected function generate_chart_data_table( array $chart_data ) {
		if ( ! isset( $chart_data['data']['labels'] ) || ! isset( $chart_data['data']['datasets'] ) ) {
			return '';
		}

		$labels   = $chart_data['data']['labels'];
		$datasets = $chart_data['data']['datasets'];

		$html  = '<details class="wp-mcp-ai-chart-data-table" style="margin-top: 15px;">';
		$html .= '<summary style="cursor: pointer; font-size: 12px; color: #666;">' . esc_html__( 'View data table', 'mcp-ai-wpoos' ) . '</summary>';
		$html .= '<div style="overflow-x: auto; margin-top: 10px;">';
		$html .= '<table style="width: 100%; border-collapse: collapse; font-size: 12px;">';

		// Header row.
		$html .= '<thead><tr style="background: #f5f5f5;">';
		$html .= '<th style="border: 1px solid #ddd; padding: 8px; text-align: left;">' . esc_html__( 'Label', 'mcp-ai-wpoos' ) . '</th>';

		foreach ( $datasets as $dataset ) {
			$label = isset( $dataset['label'] ) ? $dataset['label'] : '';
			$html .= '<th style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . esc_html( $label ) . '</th>';
		}

		$html .= '</tr></thead>';

		// Data rows.
		$html .= '<tbody>';

		foreach ( $labels as $index => $label ) {
			$html .= '<tr>';
			$html .= '<td style="border: 1px solid #ddd; padding: 8px;">' . esc_html( $label ) . '</td>';

			foreach ( $datasets as $dataset ) {
				$value = isset( $dataset['data'][ $index ] ) ? $dataset['data'][ $index ] : '';
				$html .= '<td style="border: 1px solid #ddd; padding: 8px; text-align: right;">' . esc_html( $value ) . '</td>';
			}

			$html .= '</tr>';
		}

		$html .= '</tbody>';
		$html .= '</table>';
		$html .= '</div>';
		$html .= '</details>';

		return $html;
	}
}
