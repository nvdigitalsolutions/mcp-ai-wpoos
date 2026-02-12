<?php
/**
 * Tool for rendering math equations using KaTeX.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-math-response.php';

/**
 * Render LaTeX math equations using KaTeX.
 *
 * This tool leverages KaTeX to provide:
 * - Fast LaTeX math rendering
 * - Server-side or client-side rendering options
 * - Support for complex mathematical notation
 * - Perfect for STEM education and quiz questions
 * - SEO-friendly rendered output
 *
 * @since 1.1.0
 */
class WP_MCP_AI_Tool_Render_Math_Equation implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	use WP_MCP_AI_Tool_Math_Response;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'render_math_equation';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Render Math Equation', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Render LaTeX math equations using KaTeX. Supports both display and inline math modes, complex mathematical notation, and generates SEO-friendly HTML output. Perfect for quiz questions, educational content, and scientific documentation.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'       => 'object',
			'properties' => array(
				'latex'          => array(
					'type'        => 'string',
					'description' => __( 'LaTeX math expression to render (e.g., "x = \\frac{-b \\pm \\sqrt{b^2-4ac}}{2a}")', 'mcp-ai-wpoos-pro' ),
				),
				'display_mode'   => array(
					'type'        => 'boolean',
					'description' => __( 'Use display mode (centered, larger). False for inline mode.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'throw_on_error' => array(
					'type'        => 'boolean',
					'description' => __( 'Throw error on invalid LaTeX. If false, displays error message in output.', 'mcp-ai-wpoos-pro' ),
					'default'     => false,
				),
				'output_format'  => array(
					'type'        => 'string',
					'enum'        => array( 'html', 'mathml', 'html_and_mathml' ),
					'description' => __( 'Output format: html (default), mathml, or html_and_mathml', 'mcp-ai-wpoos-pro' ),
					'default'     => 'html',
				),
				'macros'         => array(
					'type'        => 'object',
					'description' => __( 'Custom LaTeX macros as key-value pairs (e.g., {"\\RR": "\\mathbb{R}"})', 'mcp-ai-wpoos-pro' ),
				),
				'quiz_id'        => array(
					'type'        => 'integer',
					'description' => __( 'Optional quiz ID to associate this equation with', 'mcp-ai-wpoos-pro' ),
					'minimum'     => 1,
				),
				'save_rendered'  => array(
					'type'        => 'boolean',
					'description' => __( 'Cache the rendered HTML for reuse', 'mcp-ai-wpoos-pro' ),
					'default'     => true,
				),
			),
			'required'   => array( 'latex' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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
			'pro',                  // Pro tier tool.
			'read',                 // Primarily read operation (rendering).
			'requires-capability',  // Requires edit_posts capability.
			'external-dependency',  // Requires KaTeX (Node.js).
			'cacheable',            // Results can be cached.
			'idempotent',           // Same input produces same output.
		);
	}

	/**
	 * {@inheritdoc}
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		// Check if quiz system is enabled.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['enable_quiz_system'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'Quiz System is not enabled. Please enable it in settings.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Validate LaTeX input.
		if ( empty( $arguments['latex'] ) ) {
			return array(
				'success' => false,
				'error'   => __( 'LaTeX expression is required.', 'mcp-ai-wpoos-pro' ),
			);
		}

		$latex = wp_kses_post( $arguments['latex'] );

		// Check cache if save_rendered is enabled.
		$save_rendered = isset( $arguments['save_rendered'] ) ? (bool) $arguments['save_rendered'] : true;
		if ( $save_rendered ) {
			$cache_key     = 'katex_' . md5( $latex . serialize( $arguments ) );
			$cached_result = wp_cache_get( $cache_key, 'mcp_ai_katex' );
			if ( $cached_result !== false ) {
				return array(
					'success'     => true,
					'latex'       => $latex,
					'html'        => $cached_result['html'],
					'cached'      => true,
					'render_time' => 0,
				);
			}
		}

		// Check if KaTeX is available.
		$katex_available = $this->check_katex_availability();
		if ( ! $katex_available ) {
			return array(
				'success' => false,
				'error'   => __( 'KaTeX is not available. Please ensure Node.js and KaTeX package are installed. See documentation for setup instructions.', 'mcp-ai-wpoos-pro' ),
			);
		}

		// Build rendering parameters.
		$render_params = array(
			'latex'        => $latex,
			'displayMode'  => isset( $arguments['display_mode'] ) ? (bool) $arguments['display_mode'] : false,
			'throwOnError' => isset( $arguments['throw_on_error'] ) ? (bool) $arguments['throw_on_error'] : false,
			'output'       => isset( $arguments['output_format'] ) ? sanitize_text_field( $arguments['output_format'] ) : 'html',
		);

		// Add custom macros if provided.
		if ( isset( $arguments['macros'] ) && is_array( $arguments['macros'] ) ) {
			$render_params['macros'] = $arguments['macros'];
		}

		// Render with KaTeX.
		$start_time  = microtime( true );
		$result      = $this->render_with_katex( $render_params );
		$render_time = microtime( true ) - $start_time;

		if ( ! $result || isset( $result['error'] ) ) {
			return array(
				'success' => false,
				'error'   => isset( $result['error'] ) ? $result['error'] : __( 'Math equation rendering failed.', 'mcp-ai-wpoos-pro' ),
				'latex'   => $latex,
			);
		}

		// Cache the result if requested.
		if ( $save_rendered && isset( $cache_key ) ) {
			wp_cache_set( $cache_key, array( 'html' => $result['html'] ), 'mcp_ai_katex', 3600 );
		}

		// Associate with quiz if quiz_id provided.
		if ( isset( $arguments['quiz_id'] ) && absint( $arguments['quiz_id'] ) > 0 ) {
			$quiz_id = absint( $arguments['quiz_id'] );
			$this->associate_with_quiz( $quiz_id, $latex, $result['html'] );
		}

		$response = array(
			'success'      => true,
			'text'         => __( 'Math equation rendered successfully with KaTeX.', 'mcp-ai-wpoos-pro' ),
			'latex'        => $latex,
			'html'         => $result['html'],
			'mathml'       => isset( $result['mathml'] ) ? $result['mathml'] : null,
			'render_time'  => round( $render_time * 1000, 2 ) . 'ms',
			'cached'       => false,
			'display_mode' => isset( $arguments['display_mode'] ) ? (bool) $arguments['display_mode'] : false,
		);

		return $this->add_math_html_to_response( $response );
	}

	/**
	 * Check if KaTeX is available.
	 *
	 * @return bool True if KaTeX is available.
	 */
	private function check_katex_availability() {
		// Check if package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/katex/dist/katex.min.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/katex/katex.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		return $process_service->is_command_available( 'node' );
	}

	/**
	 * Render LaTeX equation with KaTeX via Node.js.
	 *
	 * @param array $params Rendering parameters.
	 * @return array|false Rendering result or false on failure.
	 */
	private function render_with_katex( $params ) {
		// In a production implementation, this would:
		// 1. Call a Node.js script that uses KaTeX.
		// 2. Pass parameters as JSON.
		// 3. Return the rendered HTML/MathML.
		//
		// For this implementation, we'll create a placeholder that demonstrates
		// the pattern. In production, you would set up a Node.js service or
		// use exec() to call a Node.js script.

		/**
		 * Filter to allow custom KaTeX rendering implementation.
		 *
		 * @param array|false $result Rendering result or false.
		 * @param array       $params Rendering parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_katex_render_equation', false, $params );

		if ( false === $result ) {
			// Default implementation note.
			return array(
				'error' => __( 'KaTeX rendering requires a Node.js service. Please implement the wp_mcp_ai_katex_render_equation filter or set up a Node.js microservice. See docs/INTEGRATION_BEST_PRACTICES.md for server-side rendering guide.', 'mcp-ai-wpoos-pro' ),
			);
		}

		return $result;
	}

	/**
	 * Associate rendered equation with a quiz.
	 *
	 * @param int    $quiz_id Quiz post ID.
	 * @param string $latex   LaTeX expression.
	 * @param string $html    Rendered HTML.
	 * @return bool True on success.
	 */
	private function associate_with_quiz( $quiz_id, $latex, $html ) {
		// Get existing equations for this quiz.
		$equations = get_post_meta( $quiz_id, '_katex_equations', true );
		if ( ! is_array( $equations ) ) {
			$equations = array();
		}

		// Add new equation.
		$equations[] = array(
			'latex'      => $latex,
			'html'       => $html,
			'created_at' => current_time( 'mysql' ),
		);

		// Save back to quiz meta.
		update_post_meta( $quiz_id, '_katex_equations', $equations );

		return true;
	}
}
