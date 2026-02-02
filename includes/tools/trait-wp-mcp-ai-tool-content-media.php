<?php
/**
 * Trait for embedding images and charts in CPT content.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait for adding images and charts to post content.
 */
trait WP_MCP_AI_Tool_Content_Media {
	/**
	 * Add images and charts parameters to schema.
	 *
	 * @return array Additional parameters for content media.
	 */
	protected function get_content_media_parameters() {
		return array(
			'content_images' => array(
				'type'        => 'array',
				'description' => __( 'Array of images to embed in the content. Maximum 5 images. Each can be an attachment ID or URL.', 'mcp-ai-wpoos' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'source'   => array(
							'description' => __( 'Image source - either attachment ID (integer) or URL (string)', 'mcp-ai-wpoos' ),
							'anyOf'       => array(
								array( 'type' => 'integer' ),
								array( 'type' => 'string' ),
							),
						),
						'caption'  => array(
							'type'        => 'string',
							'description' => __( 'Optional caption for the image', 'mcp-ai-wpoos' ),
						),
						'alt'      => array(
							'type'        => 'string',
							'description' => __( 'Optional alt text for accessibility', 'mcp-ai-wpoos' ),
						),
						'position' => array(
							'type'        => 'string',
							'description' => __( 'Position in content: start, middle, end', 'mcp-ai-wpoos' ),
							'enum'        => array( 'start', 'middle', 'end' ),
							'default'     => 'middle',
						),
					),
					'required'   => array( 'source' ),
				),
				'maxItems'    => 5,
			),
			'content_charts' => array(
				'type'        => 'array',
				'description' => __( 'Array of charts to embed in the content. Maximum 3 charts. Uses Chart.js for rendering.', 'mcp-ai-wpoos' ),
				'items'       => array(
					'type'       => 'object',
					'properties' => array(
						'type'     => array(
							'type'        => 'string',
							'description' => __( 'Chart type', 'mcp-ai-wpoos' ),
							'enum'        => array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea' ),
						),
						'title'    => array(
							'type'        => 'string',
							'description' => __( 'Chart title', 'mcp-ai-wpoos' ),
						),
						'data'     => array(
							'type'        => 'object',
							'description' => __( 'Chart data with labels and datasets', 'mcp-ai-wpoos' ),
							'properties'  => array(
								'labels'   => array(
									'type'  => 'array',
									'items' => array( 'type' => 'string' ),
								),
								'datasets' => array(
									'type'  => 'array',
									'items' => array(
										'type'       => 'object',
										'properties' => array(
											'label' => array( 'type' => 'string' ),
											'data'  => array(
												'type'  => 'array',
												'items' => array( 'type' => 'number' ),
											),
										),
									),
								),
							),
						),
						'position' => array(
							'type'        => 'string',
							'description' => __( 'Position in content: start, middle, end', 'mcp-ai-wpoos' ),
							'enum'        => array( 'start', 'middle', 'end' ),
							'default'     => 'middle',
						),
					),
					'required'   => array( 'type', 'data' ),
				),
				'maxItems'    => 3,
			),
		);
	}

	/**
	 * Embed images and charts into content.
	 *
	 * @param string $content Base content.
	 * @param array  $arguments Arguments containing content_images and content_charts.
	 * @return string Enhanced content with embedded media.
	 */
	protected function embed_content_media( $content, $arguments ) {
		$start_content  = '';
		$middle_content = '';
		$end_content    = '';

		// Process images.
		if ( isset( $arguments['content_images'] ) && is_array( $arguments['content_images'] ) ) {
			$images = array_slice( $arguments['content_images'], 0, 5 ); // Limit to 5 images.
			foreach ( $images as $image ) {
				$image_html = $this->generate_image_html( $image );
				if ( $image_html ) {
					$position = isset( $image['position'] ) ? $image['position'] : 'middle';
					if ( 'start' === $position ) {
						$start_content .= $image_html;
					} elseif ( 'end' === $position ) {
						$end_content .= $image_html;
					} else {
						$middle_content .= $image_html;
					}
				}
			}
		}

		// Process charts.
		if ( isset( $arguments['content_charts'] ) && is_array( $arguments['content_charts'] ) ) {
			$charts = array_slice( $arguments['content_charts'], 0, 3 ); // Limit to 3 charts.
			foreach ( $charts as $chart ) {
				$chart_html = $this->generate_chart_html( $chart );
				if ( $chart_html ) {
					$position = isset( $chart['position'] ) ? $chart['position'] : 'middle';
					if ( 'start' === $position ) {
						$start_content .= $chart_html;
					} elseif ( 'end' === $position ) {
						$end_content .= $chart_html;
					} else {
						$middle_content .= $chart_html;
					}
				}
			}
		}

		// Combine content.
		$enhanced_content = $start_content;

		if ( ! empty( $content ) && ! empty( $middle_content ) ) {
			// Insert middle content at roughly the middle of the text.
			$paragraphs = explode( "\n\n", $content );
			$mid_point  = (int) floor( count( $paragraphs ) / 2 );

			if ( $mid_point > 0 ) {
				$before            = array_slice( $paragraphs, 0, $mid_point );
				$after             = array_slice( $paragraphs, $mid_point );
				$enhanced_content .= implode( "\n\n", $before ) . "\n\n" . $middle_content . "\n\n" . implode( "\n\n", $after );
			} else {
				$enhanced_content .= $content . "\n\n" . $middle_content;
			}
		} else {
			$enhanced_content .= $content . "\n\n" . $middle_content;
		}

		$enhanced_content .= "\n\n" . $end_content;

		return trim( $enhanced_content );
	}

	/**
	 * Generate HTML for an image.
	 *
	 * @param array $image Image configuration.
	 * @return string Image HTML.
	 */
	private function generate_image_html( $image ) {
		if ( empty( $image['source'] ) ) {
			return '';
		}

		$source = $image['source'];
		$url    = '';

		// Handle attachment ID.
		if ( is_numeric( $source ) ) {
			$attachment_id = absint( $source );
			$url           = wp_get_attachment_image_url( $attachment_id, 'large' );

			if ( ! $url ) {
				return '';
			}
		} elseif ( is_string( $source ) ) {
			// Handle URL.
			$url = esc_url( $source );
		}

		if ( empty( $url ) ) {
			return '';
		}

		$alt     = isset( $image['alt'] ) ? esc_attr( $image['alt'] ) : '';
		$caption = isset( $image['caption'] ) ? wp_kses_post( $image['caption'] ) : '';

		// Generate WordPress-style image block.
		$html  = '<!-- wp:image -->' . "\n";
		$html .= '<figure class="wp-block-image">';
		$html .= '<img src="' . $url . '" alt="' . $alt . '" class="wp-image-' . ( is_numeric( $source ) ? $source : '' ) . '"/>';

		if ( ! empty( $caption ) ) {
			$html .= '<figcaption>' . $caption . '</figcaption>';
		}

		$html .= '</figure>' . "\n";
		$html .= '<!-- /wp:image -->' . "\n\n";

		return $html;
	}

	/**
	 * Generate HTML for a chart.
	 *
	 * @param array $chart Chart configuration.
	 * @return string Chart HTML with Chart.js.
	 */
	private function generate_chart_html( $chart ) {
		if ( empty( $chart['type'] ) || empty( $chart['data'] ) ) {
			return '';
		}

		$chart_id = 'chart-' . wp_generate_password( 8, false );
		$title    = isset( $chart['title'] ) ? sanitize_text_field( $chart['title'] ) : '';

		// Sanitize chart data.
		$chart_data = wp_json_encode( $chart['data'] );
		$chart_type = sanitize_key( $chart['type'] );

		// Generate Chart.js HTML block.
		$html  = '<!-- wp:html -->' . "\n";
		$html .= '<div class="wp-mcp-ai-chart-container" style="max-width: 600px; margin: 20px auto;">' . "\n";

		if ( ! empty( $title ) ) {
			$html .= '<h4 style="text-align: center; margin-bottom: 10px;">' . esc_html( $title ) . '</h4>' . "\n";
		}

		$html       .= '<canvas id="' . esc_attr( $chart_id ) . '"></canvas>' . "\n";
		$html       .= '</div>' . "\n";
		$chartjs_url = esc_url( plugins_url( 'assets/js/vendor/chart.min.js', WP_MCP_AI_FILE ) );
		// phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Building HTML string with inline scripts
		$html .= '<script src="' . $chartjs_url . '"></script>' . "\n";
		$html .= '<script>' . "\n";
		$html .= 'document.addEventListener("DOMContentLoaded", function() {' . "\n";
		$html .= '  const ctx = document.getElementById("' . esc_js( $chart_id ) . '");' . "\n";
		$html .= '  if (ctx) {' . "\n";
		$html .= '    new Chart(ctx, {' . "\n";
		$html .= '      type: "' . esc_js( $chart_type ) . '",' . "\n";
		$html .= '      data: ' . $chart_data . ',' . "\n";
		$html .= '      options: { responsive: true, maintainAspectRatio: true }' . "\n";
		$html .= '    });' . "\n";
		$html .= '  }' . "\n";
		$html .= '});' . "\n";
		$html .= '</script>' . "\n";
		$html .= '<!-- /wp:html -->' . "\n\n";

		return $html;
	}
}
