<?php
/**
 * Solve Equation Tool
 *
 * Solve algebraic equations symbolically using math.js.
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
 * Solve algebraic equations symbolically.
 *
 * This tool uses math.js to solve equations for a given variable.
 * Supports linear, quadratic, and polynomial equations.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Solve_Equation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Math_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'solve_equation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Solve Equation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Solve algebraic equations symbolically. Supports linear, quadratic, and polynomial equations. Returns solutions with step-by-step explanation when available.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'equation'   => array(
					'type'        => 'string',
					'description' => __( 'Equation to solve (e.g., "2x + 5 = 15" or "x^2 - 4 = 0")', 'mcp-ai-wpoos-pro' ),
				),
				'variable'   => array(
					'type'        => 'string',
					'description' => __( 'Variable to solve for (default: x)', 'mcp-ai-wpoos-pro' ),
					'default'     => 'x',
				),
				'show_steps' => array(
					'type'        => 'boolean',
					'description' => __( 'Show solution steps when available', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'format'     => array(
					'type'        => 'string',
					'enum'        => array( 'latex', 'text', 'both' ),
					'description' => __( 'Output format: latex (formatted), text (plain), or both', 'mcp-ai-wpoos-pro' ),
					'default'     => 'both',
				),
			),
			'required'   => array( 'equation' ),
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
			'external-dependency', // Requires math.js.
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$equation   = sanitize_text_field( $arguments['equation'] );
		$variable   = isset( $arguments['variable'] ) ? sanitize_text_field( $arguments['variable'] ) : 'x';
		$show_steps = isset( $arguments['show_steps'] ) ? (bool) $arguments['show_steps'] : false;
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

		// Parse equation (split by = sign).
		$parts = explode( '=', $equation );
		if ( count( $parts ) !== 2 ) {
			return array(
				'success' => false,
				'error'   => __( 'Invalid equation format. Please use format: expression = expression (e.g., "2x + 5 = 15")', 'mcp-ai-wpoos-pro' ),
			);
		}

		$left_side  = trim( $parts[0] );
		$right_side = trim( $parts[1] );

		// Use math.js via filter hook (requires Node.js service).
		$math_result = apply_filters(
			'wp_mcp_ai_mathjs_solve',
			false,
			array(
				'left'     => $left_side,
				'right'    => $right_side,
				'variable' => $variable,
			)
		);

		if ( false === $math_result || isset( $math_result['error'] ) ) {
			// Fallback: Basic solving for simple linear equations.
			$solution = $this->solve_simple_linear( $left_side, $right_side, $variable );
			if ( is_wp_error( $solution ) ) {
				return array(
					'success' => false,
					'error'   => $solution->get_error_message(),
				);
			}
			$math_result = array( 'solutions' => array( $solution ) );
		}

		$solutions = isset( $math_result['solutions'] ) ? $math_result['solutions'] : array();

		if ( empty( $solutions ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No solutions found for the given equation.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Format solutions.
		$latex_output = '';
		$text_output  = '';

		if ( count( $solutions ) === 1 ) {
			$latex_output = sprintf( '%s = %s', $variable, $this->format_number( $solutions[0] ) );
			$text_output  = sprintf( '%s = %s', $variable, $this->format_number( $solutions[0] ) );
		} else {
			$latex_solutions = array();
			$text_solutions  = array();
			foreach ( $solutions as $i => $sol ) {
				$latex_solutions[] = sprintf( '%s_%d = %s', $variable, $i + 1, $this->format_number( $sol ) );
				$text_solutions[]  = sprintf( '%s%d = %s', $variable, $i + 1, $this->format_number( $sol ) );
			}
			$latex_output = implode( ', \quad ', $latex_solutions );
			$text_output  = implode( ', ', $text_solutions );
		}

		$result = array(
			'success'       => true,
			'message'       => sprintf( __( 'Equation solved: %s', 'mcp-ai-wpoos-pro' ), $equation ),
			'text'          => sprintf( 'Solutions: %s', $text_output ),
			'equation'      => $equation,
			'variable'      => $variable,
			'solutions'     => $solutions,
			'latex'         => $latex_output,
			'solution_text' => $text_output,
		);

		// Add rendered math if requested.
		if ( 'latex' === $format || 'both' === $format ) {
			$result['display_mode'] = false; // Inline display for solutions.
			return $this->add_math_html_to_response( $result );
		}

		return $result;
	}

	/**
	 * Solve simple linear equation (fallback).
	 *
	 * Handles equations like "2x + 5 = 15" → "x = 5".
	 *
	 * @param string $left  Left side of equation.
	 * @param string $right Right side of equation.
	 * @param string $var   Variable to solve for.
	 * @return float|WP_Error Solution or error.
	 */
	private function solve_simple_linear( $left, $right, $var ) {
		// Very basic parser for ax + b = c format.
		// This is a fallback - real implementation should use math.js.

		// Check if right side is a number.
		if ( ! is_numeric( $right ) ) {
			return new WP_Error( 'complex_equation', __( 'This equation requires math.js service. Please set up Node.js integration for advanced solving.', 'mcp-ai-wpoos-pro' ) );
		}

		$c = floatval( $right );

		// Parse left side (very simplified).
		// Look for patterns like "2x + 5" or "x - 3".
		// Handle implicit coefficient of 1 for bare variable.
		$pattern = '/([+-]?\d*\.?\d*)\s*' . preg_quote( $var, '/' ) . '\s*([+-]?\d+\.?\d*)?/';
		if ( preg_match( $pattern, $left, $matches ) ) {
			$a_str = isset( $matches[1] ) ? trim( $matches[1] ) : '';
			// Handle empty coefficient (implicit 1), explicit coefficient, or negative sign.
			if ( '' === $a_str || '+' === $a_str ) {
				$a = 1;
			} elseif ( '-' === $a_str ) {
				$a = -1;
			} else {
				$a = floatval( $a_str );
			}
			$b = isset( $matches[2] ) && '' !== $matches[2] ? floatval( $matches[2] ) : 0;

			if ( 0 === $a ) {
				return new WP_Error( 'invalid_equation', __( 'Invalid equation: coefficient cannot be zero.', 'mcp-ai-wpoos-pro' ) );
			}

			// Solve ax + b = c → x = (c - b) / a.
			$solution = ( $c - $b ) / $a;
			return $solution;
		}

		return new WP_Error( 'parse_error', __( 'Could not parse equation. Please use math.js service for complex equations.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Format number for display.
	 *
	 * @param float $num Number to format.
	 * @return string Formatted number.
	 */
	private function format_number( $num ) {
		if ( is_float( $num ) && fmod( $num, 1 ) !== 0.0 ) {
			return number_format( $num, 4, '.', '' );
		}
		return (string) $num;
	}
}
