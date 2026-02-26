<?php
/**
 * Calculate Derivative Tool
 *
 * Calculate derivatives of functions using math.js.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

// Load math response trait.
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-math-response.php';

/**
 * Calculate derivatives of mathematical functions.
 *
 * This tool uses math.js to compute derivatives symbolically.
 * Supports single and multiple variable derivatives.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Calculate_Derivative implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Math_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'calculate_derivative';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Calculate Derivative', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate derivatives of mathematical functions symbolically. Supports polynomial, trigonometric, exponential, and logarithmic functions with LaTeX rendering.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'function' => array(
					'type'        => 'string',
					'description' => __( 'Function to differentiate (e.g., "x^2 + 3x + 5" or "sin(x) * e^x")', 'mcp-ai-wpoos-pro' ),
				),
				'variable' => array(
					'type'        => 'string',
					'description' => __( 'Variable to differentiate with respect to (default: x)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'x',
				),
				'order'    => array(
					'type'        => 'integer',
					'description' => __( 'Order of derivative (1 = first derivative, 2 = second derivative, etc.)', 'mcp-ai-wpoos-pro' ),
					'default'     => 1,
					'minimum'     => 1,
					'maximum'     => 5,
				),
				'simplify' => array(
					'type'        => 'boolean',
					'description' => __( 'Simplify the result', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'format'   => array(
					'type'        => 'string',
					'enum'        => array( 'latex', 'text', 'both' ),
					'description' => __( 'Output format', 'mcp-ai-wpoos-pro' ),
					'default'     => 'both',
				),
			),
			'required'   => array( 'function' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'read';
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
			'read',
			'idempotent',
			'external-dependency',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$function = sanitize_text_field( $arguments['function'] );
		$variable = isset( $arguments['variable'] ) ? sanitize_text_field( $arguments['variable'] ) : 'x';
		$order    = isset( $arguments['order'] ) ? absint( $arguments['order'] ) : 1;
		$simplify = isset( $arguments['simplify'] ) ? (bool) $arguments['simplify'] : true;
		$format   = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'both';

		// Check if Math.js package is available.
		if ( function_exists( 'wp_mcp_ai_is_npm_package_available' ) && ! wp_mcp_ai_is_npm_package_available( 'mathjs' ) ) {
			return new WP_Error(
				'wp_mcp_ai_package_not_available',
				__( 'Math.js package is not available. Please ensure Node.js and Math.js are properly installed. Visit the Pro Packages settings page for installation instructions.', 'mcp-ai-wpoos-pro' ),
				array(
					'package'      => 'mathjs',
					'settings_url' => admin_url( 'admin.php?page=wp-mcp-ai-pro-packages-settings' ),
				)
			);
		}

		// Use math.js via filter hook.
		$math_result = apply_filters(
			'wp_mcp_ai_mathjs_derivative',
			false,
			array(
				'function' => $function,
				'variable' => $variable,
				'order'    => $order,
				'simplify' => $simplify,
			)
		);

		if ( false === $math_result || isset( $math_result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Derivative calculation requires math.js service. Please set up Node.js integration. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$derivative = isset( $math_result['derivative'] ) ? $math_result['derivative'] : '';

		// Format notation.
		$notation = $order === 1 ? "\\frac{d}{d{$variable}}" : "\\frac{d^{$order}}{d{$variable}^{$order}}";
		$latex    = "{$notation} ({$function}) = {$derivative}";

		$result = array(
			'success'    => true,
			'message'    => sprintf( __( 'Derivative calculated for: %s', 'mcp-ai-wpoos-pro' ), $function ),
			'text'       => sprintf( 'd/d%s(%s) = %s', $variable, $function, $derivative ),
			'function'   => $function,
			'variable'   => $variable,
			'order'      => $order,
			'derivative' => $derivative,
			'latex'      => $latex,
		);

		if ( 'latex' === $format || 'both' === $format ) {
			$result['display_mode'] = true; // Block display for derivatives.
			return $this->add_math_html_to_response( $result );
		}

		return $result;
	}
}
