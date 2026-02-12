<?php
/**
 * Graph Function Tool
 *
 * Generate function graphs using Chart.js or D3.js.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load image response trait (graphs are images).
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-image-response.php';

/**
 * Generate graphs of mathematical functions.
 *
 * This tool creates 2D function graphs with proper axis labels,
 * grid lines, and accessibility features.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Graph_Function implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Image_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'graph_function';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Graph Function', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Generate 2D graphs of mathematical functions. Supports polynomial, trigonometric, exponential functions with customizable ranges, labels, and accessibility features.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'function'   => array(
					'type'        => 'string',
					'description' => __( 'Function to graph (e.g., "x^2" or "sin(x)")', 'mcp-ai-wpoos-pro' ),
				),
				'x_range'    => array(
					'type'        => 'array',
					'description' => __( 'X-axis range [min, max] (default: [-10, 10])', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'number' ),
					'minItems'    => 2,
					'maxItems'    => 2,
					'default'     => array( -10, 10 ),
				),
				'y_range'    => array(
					'type'        => 'array',
					'description' => __( 'Y-axis range [min, max] (auto if not provided)', 'mcp-ai-wpoos-pro' ),
					'items'       => array( 'type' => 'number' ),
					'minItems'    => 2,
					'maxItems'    => 2,
				),
				'title'      => array(
					'type'        => 'string',
					'description' => __( 'Graph title', 'mcp-ai-wpoos-pro' ),
				),
				'width'      => array(
					'type'        => 'integer',
					'description' => __( 'Graph width in pixels (default: 800)', 'mcp-ai-wpoos-pro' ),
					'default'     => 800,
					'minimum'     => 400,
					'maximum'     => 2000,
				),
				'height'     => array(
					'type'        => 'integer',
					'description' => __( 'Graph height in pixels (default: 600)', 'mcp-ai-wpoos-pro' ),
					'default'     => 600,
					'minimum'     => 300,
					'maximum'     => 1500,
				),
				'show_grid'  => array(
					'type'        => 'boolean',
					'description' => __( 'Show grid lines', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'line_color' => array(
					'type'        => 'string',
					'description' => __( 'Function line color (hex format)', 'mcp-ai-wpoos-pro' ),
					'default'     => '#0066cc',
				),
			),
			'required'   => array( 'function' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'upload_files';
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
			'pro',
			'write',
			'requires-capability',
			'external-dependency',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$function   = sanitize_text_field( $arguments['function'] );
		$x_range    = isset( $arguments['x_range'] ) ? $arguments['x_range'] : array( -10, 10 );
		$y_range    = isset( $arguments['y_range'] ) ? $arguments['y_range'] : null;
		$title      = isset( $arguments['title'] ) ? sanitize_text_field( $arguments['title'] ) : sprintf( 'Graph of %s', $function );
		$width      = isset( $arguments['width'] ) ? absint( $arguments['width'] ) : 800;
		$height     = isset( $arguments['height'] ) ? absint( $arguments['height'] ) : 600;
		$show_grid  = isset( $arguments['show_grid'] ) ? (bool) $arguments['show_grid'] : true;
		$line_color = isset( $arguments['line_color'] ) ? sanitize_hex_color( $arguments['line_color'] ) : '#0066cc';

		// Use graphing service via filter hook (requires Chart.js or D3.js rendering service).
		$graph_result = apply_filters(
			'wp_mcp_ai_graph_function',
			false,
			array(
				'function'   => $function,
				'x_range'    => $x_range,
				'y_range'    => $y_range,
				'title'      => $title,
				'width'      => $width,
				'height'     => $height,
				'show_grid'  => $show_grid,
				'line_color' => $line_color,
			)
		);

		if ( false === $graph_result || isset( $graph_result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Function graphing requires a graphing service (Chart.js/D3.js). Please implement the wp_mcp_ai_graph_function filter or set up a graphing microservice. See docs/NPM_INTEGRATION_GUIDE.md in the pro addon for implementation guide.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$attachment_id = isset( $graph_result['attachment_id'] ) ? $graph_result['attachment_id'] : null;
		$url           = isset( $graph_result['url'] ) ? $graph_result['url'] : null;

		if ( ! $attachment_id && ! $url ) {
			return array(
				'success' => false,
				'error'   => __( 'Graph generation did not return an image.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$result = array(
			'success'       => true,
			'message'       => sprintf( __( 'Graph generated for function: %s', 'mcp-ai-wpoos-pro' ), $function ),
			'text'          => sprintf( 'Function graphed: %s', $function ),
			'function'      => $function,
			'attachment_id' => $attachment_id,
			'url'           => $url,
			'width'         => $width,
			'height'        => $height,
			'prompt'        => sprintf( 'Graph of %s from x=%s to x=%s', $function, $x_range[0], $x_range[1] ),
		);

		// Add image HTML to response.
		return $this->add_image_html_to_response( $result );
	}
}
