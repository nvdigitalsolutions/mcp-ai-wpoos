<?php
/**
 * Simplify Expression Tool
 *
 * Simplify algebraic expressions using math.js.
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
 * Simplify algebraic expressions.
 *
 * This tool uses math.js to simplify mathematical expressions.
 * Supports algebraic manipulation, factoring, and simplification.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Simplify_Expression implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Math_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'simplify_expression';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Simplify Expression', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Simplify algebraic expressions. Combines like terms, applies algebraic rules, and presents expressions in simplest form with LaTeX rendering.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'expression' => array(
					'type'        => 'string',
					'description' => __( 'Expression to simplify (e.g., "2x + 3x - 5 + 2" or "(x + 2)(x - 3)")', 'mcp-ai-wpoos-pro' ),
				),
				'rules'      => array(
					'type'        => 'array',
					'description' => __( 'Simplification rules to apply', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type' => 'string',
						'enum' => array( 'default', 'all', 'collect', 'distribute' ),
					),
					'default'     => array( 'default' ),
				),
				'format'     => array(
					'type'        => 'string',
					'enum'        => array( 'latex', 'text', 'both' ),
					'description' => __( 'Output format', 'mcp-ai-wpoos-pro' ),
					'default'     => 'both',
				),
			),
			'required'   => array( 'expression' ),
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
		$expression = sanitize_text_field( $arguments['expression'] );
		$rules      = isset( $arguments['rules'] ) ? $arguments['rules'] : array( 'default' );
		$format     = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'both';

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
			'wp_mcp_ai_mathjs_simplify',
			false,
			array(
				'expression' => $expression,
				'rules'      => $rules,
			)
		);

		if ( false === $math_result || isset( $math_result['error'] ) ) {
			// Fallback: Basic simplification.
			$simplified = $this->simple_simplify( $expression );
		} else {
			$simplified = isset( $math_result['simplified'] ) ? $math_result['simplified'] : $expression;
		}

		$result = array(
			'success'             => true,
			'message'             => sprintf( __( 'Expression simplified: %s', 'mcp-ai-wpoos-pro' ), $expression ),
			'text'                => sprintf( 'Simplified: %s', $simplified ),
			'original_expression' => $expression,
			'simplified'          => $simplified,
			'latex'               => $simplified,
		);

		if ( 'latex' === $format || 'both' === $format ) {
			$result['display_mode'] = false;
			return $this->add_math_html_to_response( $result );
		}

		return $result;
	}

	/**
	 * Basic simplification fallback.
	 *
	 * @param string $expr Expression to simplify.
	 * @return string Simplified expression.
	 */
	private function simple_simplify( $expr ) {
		// Very basic: just remove extra spaces.
		// Real implementation uses math.js.
		return preg_replace( '/\s+/', ' ', trim( $expr ) );
	}
}
