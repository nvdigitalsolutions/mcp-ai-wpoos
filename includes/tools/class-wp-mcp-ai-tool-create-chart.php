<?php
/**
 * Tool for creating charts using Chart.js library.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/class-wp-mcp-ai-media-url-utils.php';

/**
 * Creates charts using Chart.js and returns HTML/JavaScript or saves as attachment.
 *
 * This tool follows separation of concerns principles:
 * - Tool Logic: Validates and processes chart configuration
 * - Data Layer: Handles chart data structure and validation
 * - Rendering Layer: Generates Chart.js configuration and HTML
 * - Storage Layer: Optionally saves chart as HTML file attachment
 */
class WP_MCP_AI_Tool_Create_Chart implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Shortcuts_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Rules_Interface {

	const CHARTJS_VERSION = '4.4.0';
	const CHARTJS_CDN_URL = 'https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.js';

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'create_chart';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Create Chart', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Creates interactive charts using Chart.js. Supports bar, line, pie, doughnut, radar, and polar area charts. Returns HTML/JavaScript or saves as attachment.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'type'               => array(
					'type'        => 'string',
					'description' => __( 'Chart type: bar, line, pie, doughnut, radar, polarArea, scatter, bubble.', 'wp-mcp-ai' ),
					'enum'        => array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea', 'scatter', 'bubble' ),
				),
				'data'               => array(
					'type'        => 'object',
					'description' => __( 'Chart data object with labels and datasets.', 'wp-mcp-ai' ),
					'properties'  => array(
						'labels'   => array(
							'type'        => 'array',
							'description' => __( 'Labels for the chart data points.', 'wp-mcp-ai' ),
							'items'       => array(
								'type' => 'string',
							),
						),
						'datasets' => array(
							'type'        => 'array',
							'description' => __( 'Array of dataset objects.', 'wp-mcp-ai' ),
							'items'       => array(
								'type'       => 'object',
								'properties' => array(
									'label'           => array(
										'type'        => 'string',
										'description' => __( 'Dataset label.', 'wp-mcp-ai' ),
									),
									'data'            => array(
										'type'        => 'array',
										'description' => __( 'Data values for this dataset.', 'wp-mcp-ai' ),
										'items'       => array(
											'type' => 'number',
										),
									),
									'backgroundColor' => array(
										'description' => __( 'Background color(s) for the dataset.', 'wp-mcp-ai' ),
										'anyOf'       => array(
											array(
												'type' => 'string',
											),
											array(
												'type'  => 'array',
												'items' => array(
													'type' => 'string',
												),
											),
										),
									),
									'borderColor'     => array(
										'description' => __( 'Border color(s) for the dataset.', 'wp-mcp-ai' ),
										'anyOf'       => array(
											array(
												'type' => 'string',
											),
											array(
												'type'  => 'array',
												'items' => array(
													'type' => 'string',
												),
											),
										),
									),
									'borderWidth'     => array(
										'type'        => 'number',
										'description' => __( 'Border width in pixels.', 'wp-mcp-ai' ),
									),
								),
							),
						),
					),
				),
				'options'            => array(
					'type'        => 'object',
					'description' => __( 'Chart.js options object for customizing the chart appearance and behavior.', 'wp-mcp-ai' ),
				),
				'title'              => array(
					'type'        => 'string',
					'description' => __( 'Chart title (optional).', 'wp-mcp-ai' ),
				),
				'width'              => array(
					'type'        => 'integer',
					'description' => __( 'Chart canvas width in pixels (default: 800).', 'wp-mcp-ai' ),
					'default'     => 800,
					'minimum'     => 100,
					'maximum'     => 2000,
				),
				'height'             => array(
					'type'        => 'integer',
					'description' => __( 'Chart canvas height in pixels (default: 400).', 'wp-mcp-ai' ),
					'default'     => 400,
					'minimum'     => 100,
					'maximum'     => 2000,
				),
				'save_as_attachment' => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to save the chart as an HTML file attachment (default: false).', 'wp-mcp-ai' ),
					'default'     => false,
				),
				'file_name'          => array(
					'type'        => 'string',
					'description' => __( 'Optional base file name for the saved HTML attachment.', 'wp-mcp-ai' ),
				),
			),
			'required'             => array( 'type', 'data' ),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_shortcut_tasks() {
		return array(
			array(
				'label'   => __( 'create_chart', 'wp-mcp-ai' ),
				'payload' => __( 'create_chart', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Create bar chart from data', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `create_chart` tool to create a bar chart. Ask for the data values and labels, then generate the chart configuration.', 'wp-mcp-ai' ),
			),
			array(
				'label'   => __( 'Create pie chart visualization', 'wp-mcp-ai' ),
				'payload' => __( 'Use the `create_chart` tool to create a pie chart. Ask for the data categories and their values, then generate the chart.', 'wp-mcp-ai' ),
			),
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
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to create charts.', 'wp-mcp-ai' ),
				array( 'status' => rest_authorization_required_code() )
			);
		}

		if ( $user_id ) {
			if ( ! user_can( $user_id, 'read' ) ) {
				return new WP_Error(
					'wp_mcp_ai_forbidden',
					__( 'You do not have permission to create charts.', 'wp-mcp-ai' )
				);
			}

			if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
				return new WP_Error(
					'wp_mcp_ai_wrong_site',
					__( 'You do not have access to this site.', 'wp-mcp-ai' )
				);
			}
		}

		// Validate chart type.
		$chart_type  = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : '';
		$valid_types = array( 'bar', 'line', 'pie', 'doughnut', 'radar', 'polarArea', 'scatter', 'bubble' );

		if ( ! in_array( $chart_type, $valid_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_chart_type',
				sprintf(
					/* translators: %s: valid chart types */
					__( 'Invalid chart type. Must be one of: %s', 'wp-mcp-ai' ),
					implode( ', ', $valid_types )
				),
				array( 'status' => 400 )
			);
		}

		// Validate chart data.
		if ( ! isset( $arguments['data'] ) || ! is_array( $arguments['data'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_data',
				__( 'Chart data is required.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$chart_data = $this->validate_and_sanitize_chart_data( $arguments['data'] );
		if ( is_wp_error( $chart_data ) ) {
			return $chart_data;
		}

		// Get chart dimensions.
		$width  = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 800;
		$height = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 400;

		$width  = max( 100, min( 2000, $width ) );
		$height = max( 100, min( 2000, $height ) );

		// Get chart options.
		$options = isset( $arguments['options'] ) && is_array( $arguments['options'] )
			? $arguments['options']
			: array();

		// Add title to options if provided.
		if ( ! empty( $arguments['title'] ) ) {
			$title = sanitize_text_field( $arguments['title'] );
			if ( ! isset( $options['plugins'] ) ) {
				$options['plugins'] = array();
			}
			if ( ! isset( $options['plugins']['title'] ) ) {
				$options['plugins']['title'] = array();
			}
			$options['plugins']['title']['display'] = true;
			$options['plugins']['title']['text']    = $title;
		}

		// Generate chart configuration.
		$chart_config = $this->build_chart_config( $chart_type, $chart_data, $options );

		// Generate HTML.
		$html = $this->generate_chart_html( $chart_config, $width, $height );

		// Determine if we should save as attachment.
		$save_as_attachment = isset( $arguments['save_as_attachment'] ) && $arguments['save_as_attachment'];

		if ( $save_as_attachment ) {
			$file_name = isset( $arguments['file_name'] ) ? $arguments['file_name'] : '';
			$storage   = $this->save_chart_as_attachment( $html, $file_name, $chart_type, $user_id );

			if ( is_wp_error( $storage ) ) {
				return $storage;
			}

			return array(
				'chart_type'    => $chart_type,
				'attachment_id' => $storage['attachment_id'],
				'url'           => $storage['url'],
				'file_path'     => $storage['file'],
				'file_name'     => $storage['file_name'],
				'html'          => $html,
				'chart_config'  => $chart_config,
				'saved_as_file' => true,
				'output_format' => 'chart', // Enables chart embedding in chat client via iframe rendering.
			);
		}

		return array(
			'chart_type'    => $chart_type,
			'html'          => $html,
			'chart_config'  => $chart_config,
			'width'         => $width,
			'height'        => $height,
			'saved_as_file' => false,
			'output_format' => 'chart', // Enables chart embedding in chat client via iframe rendering.
		);
	}

	/**
	 * Validate and sanitize chart data.
	 *
	 * @param array $data Raw chart data.
	 * @return array|WP_Error Sanitized data or error.
	 */
	protected function validate_and_sanitize_chart_data( array $data ) {
		$sanitized = array();

		// Validate labels.
		if ( isset( $data['labels'] ) && is_array( $data['labels'] ) ) {
			$sanitized['labels'] = array_map( 'sanitize_text_field', $data['labels'] );
		} else {
			$sanitized['labels'] = array();
		}

		// Validate datasets.
		if ( ! isset( $data['datasets'] ) || ! is_array( $data['datasets'] ) || empty( $data['datasets'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_datasets',
				__( 'Chart data must include at least one dataset.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		$sanitized['datasets'] = array();
		foreach ( $data['datasets'] as $dataset ) {
			if ( ! is_array( $dataset ) ) {
				continue;
			}

			$sanitized_dataset = array();

			// Label.
			if ( isset( $dataset['label'] ) ) {
				$sanitized_dataset['label'] = sanitize_text_field( $dataset['label'] );
			}

			// Data values.
			if ( isset( $dataset['data'] ) && is_array( $dataset['data'] ) ) {
				$sanitized_dataset['data'] = array_map( 'floatval', $dataset['data'] );
			} else {
				continue; // Skip datasets without data.
			}

			// Colors and styling.
			if ( isset( $dataset['backgroundColor'] ) ) {
				$sanitized_dataset['backgroundColor'] = $this->sanitize_color_value( $dataset['backgroundColor'] );
			}

			if ( isset( $dataset['borderColor'] ) ) {
				$sanitized_dataset['borderColor'] = $this->sanitize_color_value( $dataset['borderColor'] );
			}

			if ( isset( $dataset['borderWidth'] ) ) {
				$sanitized_dataset['borderWidth'] = absint( $dataset['borderWidth'] );
			}

			// Allow other Chart.js dataset properties (tension, fill, etc.).
			$allowed_properties = array(
				'tension',
				'fill',
				'pointRadius',
				'pointHoverRadius',
				'pointBackgroundColor',
				'pointBorderColor',
				'pointBorderWidth',
				'lineTension',
				'stepped',
				'showLine',
				'spanGaps',
			);

			foreach ( $allowed_properties as $prop ) {
				if ( isset( $dataset[ $prop ] ) ) {
					$sanitized_dataset[ $prop ] = $dataset[ $prop ];
				}
			}

			$sanitized['datasets'][] = $sanitized_dataset;
		}

		if ( empty( $sanitized['datasets'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_valid_datasets',
				__( 'No valid datasets found in chart data.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		return $sanitized;
	}

	/**
	 * Sanitize color value (string or array of strings).
	 *
	 * @param mixed $color Color value.
	 * @return string|array Sanitized color value.
	 */
	protected function sanitize_color_value( $color ) {
		if ( is_array( $color ) ) {
			return array_map( 'sanitize_text_field', $color );
		}
		return sanitize_text_field( $color );
	}

	/**
	 * Build Chart.js configuration object.
	 *
	 * Separation of concerns: This method handles configuration structure,
	 * not HTML generation.
	 *
	 * @param string $type    Chart type.
	 * @param array  $data    Sanitized chart data.
	 * @param array  $options Chart options.
	 * @return array Chart.js configuration.
	 */
	protected function build_chart_config( $type, array $data, array $options ) {
		return array(
			'type'    => $type,
			'data'    => $data,
			'options' => $options,
		);
	}

	/**
	 * Generate HTML with embedded Chart.js code.
	 *
	 * Separation of concerns: This method handles rendering only,
	 * not business logic or data validation.
	 *
	 * @param array $config Chart.js configuration.
	 * @param int   $width  Canvas width.
	 * @param int   $height Canvas height.
	 * @return string Complete HTML document.
	 */
	protected function generate_chart_html( array $config, $width, $height ) {
		$chart_id    = 'chart-' . wp_generate_password( 8, false );
		$config_json = wp_json_encode( $config, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES );
		$chartjs_url = esc_url( self::CHARTJS_CDN_URL );

		$html = <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Chart</title>
    <script src="{$chartjs_url}"></script>
    <style>
        body {
            margin: 0;
            padding: 20px;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Oxygen-Sans, Ubuntu, Cantarell, "Helvetica Neue", sans-serif;
            background-color: #f5f5f5;
        }
        .chart-container {
            max-width: 100%;
            margin: 0 auto;
            background: white;
            padding: 20px;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }
        canvas {
            max-width: 100%;
        }
    </style>
</head>
<body>
    <div class="chart-container">
        <canvas id="{$chart_id}" width="{$width}" height="{$height}"></canvas>
    </div>
    <script>
        (function() {
            function initChart() {
                if (typeof Chart === 'undefined') {
                    setTimeout(initChart, 50);
                    return;
                }
                const ctx = document.getElementById('{$chart_id}').getContext('2d');
                const chartConfig = {$config_json};
                new Chart(ctx, chartConfig);
            }
            
            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initChart);
            } else {
                initChart();
            }
        })();
    </script>
</body>
</html>
HTML;

		return $html;
	}

	/**
	 * Save chart HTML as a WordPress attachment.
	 *
	 * Separation of concerns: This method handles storage logic only.
	 *
	 * @param string $html      Complete HTML content.
	 * @param string $file_name Optional preferred file name.
	 * @param string $chart_type Chart type for naming.
	 * @param int    $user_id   Acting user ID.
	 * @return array|WP_Error Attachment data or error.
	 */
	protected function save_chart_as_attachment( $html, $file_name, $chart_type, $user_id ) {
		$file_stem = $this->normalize_file_stem( $file_name, $chart_type );
		$file_name = sprintf( '%s-%s.html', $file_stem, gmdate( 'Ymd-His' ) );

		if ( ! function_exists( 'wp_upload_bits' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		$upload = wp_upload_bits( $file_name, null, $html );

		if ( ! empty( $upload['error'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_chart_upload_failed',
				__( 'Failed to save the chart HTML file.', 'wp-mcp-ai' ),
				array( 'error' => $upload['error'] )
			);
		}

		$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_chart_upload_failed',
				__( 'Failed to write the chart HTML file to disk.', 'wp-mcp-ai' )
			);
		}

		$title = $this->generate_attachment_title( $chart_type );

		$attachment = array(
			'post_mime_type' => 'text/html',
			'post_title'     => $title,
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		if ( $user_id ) {
			$attachment['post_author'] = $user_id;
		}

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			$this->delete_file_safely( $file_path );

			return new WP_Error(
				'wp_mcp_ai_attachment_error',
				__( 'Failed to register the chart HTML as an attachment.', 'wp-mcp-ai' ),
				array( 'error' => $attachment_id )
			);
		}

		$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;

		// Get local WordPress URL using utility class for SoC compliance.
		$local_url = WP_MCP_AI_Media_URL_Utils::get_local_upload_url( $upload, $attachment_id );

		return array(
			'attachment_id' => (int) $attachment_id,
			'file'          => $file_path,
			'file_name'     => wp_basename( $file_path ),
			'url'           => $local_url,
			'mime_type'     => 'text/html',
			'bytes'         => $bytes ? (int) $bytes : 0,
			'title'         => $title,
		);
	}

	/**
	 * Normalize a file stem for chart attachments.
	 *
	 * @param string $file_name  Raw file name input.
	 * @param string $chart_type Chart type for fallback name.
	 * @return string Normalized file stem.
	 */
	protected function normalize_file_stem( $file_name, $chart_type ) {
		$file_name = sanitize_file_name( (string) $file_name );

		if ( '' === $file_name ) {
			return sprintf( 'chart-%s', sanitize_key( $chart_type ) );
		}

		$info = pathinfo( $file_name );
		$stem = isset( $info['filename'] ) ? $info['filename'] : $file_name;
		$stem = sanitize_title( $stem );

		if ( '' === $stem ) {
			return sprintf( 'chart-%s', sanitize_key( $chart_type ) );
		}

		return $stem;
	}

	/**
	 * Generate a human-readable attachment title.
	 *
	 * @param string $chart_type Chart type.
	 * @return string Attachment title.
	 */
	protected function generate_attachment_title( $chart_type ) {
		$type_labels = array(
			'bar'       => __( 'Bar Chart', 'wp-mcp-ai' ),
			'line'      => __( 'Line Chart', 'wp-mcp-ai' ),
			'pie'       => __( 'Pie Chart', 'wp-mcp-ai' ),
			'doughnut'  => __( 'Doughnut Chart', 'wp-mcp-ai' ),
			'radar'     => __( 'Radar Chart', 'wp-mcp-ai' ),
			'polarArea' => __( 'Polar Area Chart', 'wp-mcp-ai' ),
			'scatter'   => __( 'Scatter Chart', 'wp-mcp-ai' ),
			'bubble'    => __( 'Bubble Chart', 'wp-mcp-ai' ),
		);

		$label = isset( $type_labels[ $chart_type ] ) ? $type_labels[ $chart_type ] : __( 'Chart', 'wp-mcp-ai' );

		return $label;
	}

	/**
	 * Delete a generated file from disk safely when an error occurs.
	 *
	 * @param string $file_path Absolute file path.
	 */
	protected function delete_file_safely( $file_path ) {
		$file_path = (string) $file_path;

		if ( '' === $file_path || ! file_exists( $file_path ) ) {
			return;
		}

		if ( ! function_exists( 'wp_delete_file' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
		}

		wp_delete_file( $file_path );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',       // Does not modify site data (unless saving attachment).
			'requires-capability',  // Requires user capabilities.
			'write',           // Can create attachments when save_as_attachment is true.
			'local-only',      // Works entirely locally, Chart.js loaded from CDN.
			'external-api',    // Loads Chart.js from CDN.
			'network-dependent', // Requires internet for Chart.js CDN.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_tool_rules() {
		return array(
			'parameter_constraints' => array(
				'required_fields' => array( 'type', 'data' ),
				'optional_fields' => array( 'options', 'title', 'width', 'height', 'save_as_attachment', 'file_name' ),
				'max_datasets'    => 10,  // Maximum datasets per chart.
				'max_data_points' => 1000, // Maximum data points per dataset.
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 30,  // Chart generation is fast.
				'max_execution_time'  => 60,  // Maximum time for file operations.
			),
			'response_constraints'  => array(
				'max_size'            => 2097152, // 2MB max HTML size.
				'supports_streaming'  => false,
				'supports_pagination' => false,
			),
			'dependencies'          => array(
				'required_extensions' => array(), // No PHP extensions required.
				'external_services'   => array(
					'chartjs_cdn' => array(
						'url'      => self::CHARTJS_CDN_URL,
						'required' => true,
						'purpose'  => 'Chart.js library loading',
					),
				),
			),
			'orchestration_hints'   => array(
				'can_run_parallel' => true,   // Multiple charts can be generated simultaneously.
				'requires_lock'    => false,  // No exclusive resources needed.
				'cache_ttl'        => 0,      // Don't cache - each chart is unique.
				'retry_strategy'   => 'simple', // Simple retry on failure.
				'max_retries'      => 2,
				'idempotent'       => true,   // Same input always produces same output.
			),
			'resource_usage'        => array(
				'memory_intensive' => false,  // Minimal memory usage.
				'cpu_intensive'    => false,  // Minimal CPU usage.
				'io_intensive'     => false,  // Only I/O when saving attachment.
			),
		);
	}
}
