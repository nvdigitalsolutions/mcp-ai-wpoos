<?php
/**
 * Calculate Integral Tool
 *
 * Calculate integrals of functions using math.js.
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
 * Calculate integrals of mathematical functions.
 *
 * This tool calculates definite and indefinite integrals.
 * Requires math.js or symbolic computation service.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Calculate_Integral implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Math_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'calculate_integral';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Calculate Integral', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Calculate definite and indefinite integrals of mathematical functions. Supports polynomial, trigonometric, exponential, and logarithmic functions with LaTeX rendering.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'function'    => array(
					'type'        => 'string',
					'description' => __( 'Function to integrate (e.g., "x^2 + 3x" or "sin(x)")', 'mcp-ai-wpoos-pro' ),
				),
				'variable'    => array(
					'type'        => 'string',
					'description' => __( 'Variable of integration (default: x)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'x',
				),
				'type'        => array(
					'type'        => 'string',
					'enum'        => array( 'indefinite', 'definite' ),
					'description' => __( 'Type of integral: indefinite or definite', 'mcp-ai-wpoos-pro' ),
					'default'     => 'indefinite',
				),
				'lower_limit' => array(
					'type'        => 'number',
					'description' => __( 'Lower limit for definite integral', 'mcp-ai-wpoos-pro' ),
				),
				'upper_limit' => array(
					'type'        => 'number',
					'description' => __( 'Upper limit for definite integral', 'mcp-ai-wpoos-pro' ),
				),
				'simplify'    => array(
					'type'        => 'boolean',
					'description' => __( 'Simplify the result', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
				'format'      => array(
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
		$function    = sanitize_text_field( $arguments['function'] );
		$variable    = isset( $arguments['variable'] ) ? sanitize_text_field( $arguments['variable'] ) : 'x';
		$type        = isset( $arguments['type'] ) ? sanitize_text_field( $arguments['type'] ) : 'indefinite';
		$lower_limit = isset( $arguments['lower_limit'] ) ? floatval( $arguments['lower_limit'] ) : null;
		$upper_limit = isset( $arguments['upper_limit'] ) ? floatval( $arguments['upper_limit'] ) : null;
		$simplify    = isset( $arguments['simplify'] ) ? (bool) $arguments['simplify'] : true;
		$format      = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'both';

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

		// Validate definite integral has limits.
		if ( 'definite' === $type && ( null === $lower_limit || null === $upper_limit ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Definite integrals require both lower_limit and upper_limit parameters.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Use math.js via filter hook.
		$math_result = apply_filters(
			'wp_mcp_ai_mathjs_integral',
			false,
			array(
				'function'    => $function,
				'variable'    => $variable,
				'type'        => $type,
				'lower_limit' => $lower_limit,
				'upper_limit' => $upper_limit,
				'simplify'    => $simplify,
			)
		);

		if ( false === $math_result || isset( $math_result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Integral calculation requires math.js service. Please set up Node.js integration. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$integral = isset( $math_result['integral'] ) ? $math_result['integral'] : '';
		$value    = isset( $math_result['value'] ) ? $math_result['value'] : null;

		// Format LaTeX.
		if ( 'definite' === $type ) {
			$latex = '\\int_{' . $lower_limit . '}^{' . $upper_limit . "} ({$function}) \\, d{$variable} = {$value}";
		} else {
			$latex = "\\int ({$function}) \\, d{$variable} = {$integral} + C";
		}

		$result = array(
			'success'  => true,
			'message'  => sprintf( __( 'Integral calculated for: %s', 'mcp-ai-wpoos-pro' ), $function ),
			'text'     => 'definite' === $type ? sprintf( 'Integral = %s', $value ) : sprintf( 'Integral = %s + C', $integral ),
			'function' => $function,
			'variable' => $variable,
			'type'     => $type,
			'integral' => $integral,
			'latex'    => $latex,
		);

		if ( 'definite' === $type ) {
			$result['lower_limit'] = $lower_limit;
			$result['upper_limit'] = $upper_limit;
			$result['value']       = $value;
		}

		if ( 'latex' === $format || 'both' === $format ) {
			$result['display_mode'] = true;
			return $this->add_math_html_to_response( $result );
		}

		return $result;
	}
}
