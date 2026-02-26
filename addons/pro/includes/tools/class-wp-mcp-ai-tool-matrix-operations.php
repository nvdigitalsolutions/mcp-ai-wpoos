<?php
/**
 * Matrix Operations Tool
 *
 * Perform linear algebra operations on matrices using math.js.
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
 * Perform matrix operations and linear algebra.
 *
 * This tool uses math.js to perform matrix operations including
 * addition, multiplication, inversion, determinant, eigenvalues, etc.
 *
 * @since 1.2.0
 */
class WP_MCP_AI_Tool_Matrix_Operations implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Math_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'matrix_operations';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Matrix Operations', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Perform linear algebra and matrix operations. Supports addition, multiplication, transpose, inverse, determinant, eigenvalues, and more with LaTeX rendering.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'operation' => array(
					'type'        => 'string',
					'enum'        => array( 'add', 'subtract', 'multiply', 'transpose', 'inverse', 'determinant', 'eigenvalues', 'rank', 'trace' ),
					'description' => __( 'Matrix operation to perform', 'mcp-ai-wpoos-pro' ),
				),
				'matrix_a'  => array(
					'type'        => 'array',
					'description' => __( 'First matrix (2D array, e.g., [[1,2],[3,4]])', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'number' ),
					),
				),
				'matrix_b'  => array(
					'type'        => 'array',
					'description' => __( 'Second matrix for binary operations (add, subtract, multiply)', 'mcp-ai-wpoos-pro' ),
					'items'       => array(
						'type'  => 'array',
						'items' => array( 'type' => 'number' ),
					),
				),
				'format'    => array(
					'type'        => 'string',
					'enum'        => array( 'latex', 'text', 'both' ),
					'description' => __( 'Output format', 'mcp-ai-wpoos-pro' ),
					'default'     => 'both',
				),
			),
			'required'   => array( 'operation', 'matrix_a' ),
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
		$operation = sanitize_text_field( $arguments['operation'] );
		$matrix_a  = $arguments['matrix_a'];
		$matrix_b  = isset( $arguments['matrix_b'] ) ? $arguments['matrix_b'] : null;
		$format    = isset( $arguments['format'] ) ? sanitize_text_field( $arguments['format'] ) : 'both';

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

		// Validate binary operations have matrix_b.
		$binary_ops = array( 'add', 'subtract', 'multiply' );
		if ( in_array( $operation, $binary_ops, true ) && null === $matrix_b ) {
			return array(
				'success' => false,
				'error'   => sprintf( __( 'Operation "%s" requires matrix_b parameter.', 'mcp-ai-wpoos-pro' ), $operation ),
			);
		}

		// Use math.js via filter hook.
		$math_result = apply_filters(
			'wp_mcp_ai_mathjs_matrix',
			false,
			array(
				'operation' => $operation,
				'matrix_a'  => $matrix_a,
				'matrix_b'  => $matrix_b,
			)
		);

		if ( false === $math_result || isset( $math_result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Matrix operations require math.js service. Please set up Node.js integration. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$result_matrix = isset( $math_result['result'] ) ? $math_result['result'] : null;

		// Format result for LaTeX.
		$latex_a      = $this->matrix_to_latex( $matrix_a );
		$latex_result = is_array( $result_matrix ) ? $this->matrix_to_latex( $result_matrix ) : (string) $result_matrix;

		$operation_symbols = array(
			'add'         => '+',
			'subtract'    => '-',
			'multiply'    => '\\times',
			'transpose'   => '^T',
			'inverse'     => '^{-1}',
			'determinant' => '\\det',
			'eigenvalues' => '\\lambda',
			'rank'        => '\\text{rank}',
			'trace'       => '\\text{tr}',
		);

		$symbol = isset( $operation_symbols[ $operation ] ) ? $operation_symbols[ $operation ] : '';

		if ( in_array( $operation, $binary_ops, true ) ) {
			$latex_b = $this->matrix_to_latex( $matrix_b );
			$latex   = "{$latex_a} {$symbol} {$latex_b} = {$latex_result}";
		} elseif ( 'transpose' === $operation || 'inverse' === $operation ) {
			$latex = "{$latex_a}{$symbol} = {$latex_result}";
		} else {
			$latex = "{$symbol}({$latex_a}) = {$latex_result}";
		}

		$result = array(
			'success'   => true,
			'message'   => sprintf( __( 'Matrix operation completed: %s', 'mcp-ai-wpoos-pro' ), $operation ),
			'text'      => sprintf( 'Result: %s', json_encode( $result_matrix ) ),
			'operation' => $operation,
			'matrix_a'  => $matrix_a,
			'result'    => $result_matrix,
			'latex'     => $latex,
		);

		if ( $matrix_b ) {
			$result['matrix_b'] = $matrix_b;
		}

		if ( 'latex' === $format || 'both' === $format ) {
			$result['display_mode'] = true;
			return $this->add_math_html_to_response( $result );
		}

		return $result;
	}

	/**
	 * Convert matrix to LaTeX format.
	 *
	 * @param array $matrix 2D array representing matrix.
	 * @return string LaTeX representation.
	 */
	private function matrix_to_latex( $matrix ) {
		$rows = array();
		foreach ( $matrix as $row ) {
			$rows[] = implode( ' & ', array_map( array( $this, 'format_number' ), $row ) );
		}
		return '\\begin{bmatrix} ' . implode( ' \\\\ ', $rows ) . ' \\end{bmatrix}';
	}

	/**
	 * Format number for display.
	 *
	 * @param float $num Number to format.
	 * @return string Formatted number.
	 */
	public function format_number( $num ) {
		if ( is_float( $num ) && fmod( $num, 1 ) !== 0.0 ) {
			return number_format( $num, 2, '.', '' );
		}
		return (string) $num;
	}
}
