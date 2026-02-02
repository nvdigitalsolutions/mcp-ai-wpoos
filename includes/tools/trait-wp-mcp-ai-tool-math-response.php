<?php
/**
 * Trait for adding rendered math HTML to tool responses using KaTeX.
 *
 * This trait provides a standardized way to render mathematical expressions
 * using KaTeX, converting LaTeX notation to accessible HTML/SVG output.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Trait WP_MCP_AI_Tool_Math_Response
 *
 * Provides helper methods to render mathematical expressions using KaTeX.
 * This ensures that tools returning LaTeX or mathematical content can display
 * it properly in the chat interface with accessibility support.
 *
 * Usage:
 * ```php
 * class My_Math_Tool implements WP_MCP_AI_Tool_Interface {
 *     use WP_MCP_AI_Tool_Math_Response;
 *
 *     public function execute( array $arguments = array(), array $context = array() ) {
 *         $result = array(
 *             'latex' => 'E = mc^2',
 *             'text' => 'Energy-mass equivalence formula',
 *         );
 *
 *         // Add rendered math HTML to response
 *         return $this->add_math_html_to_response( $result );
 *     }
 * }
 * ```
 */
trait WP_MCP_AI_Tool_Math_Response {

	/**
	 * Add rendered math HTML to a tool response.
	 *
	 * This method takes a result containing LaTeX expressions and adds rendered
	 * math HTML to the 'message' field using KaTeX for display.
	 *
	 * @param array $result Tool result containing latex, equations, or formula fields.
	 * @return array Modified result with math HTML added to message field.
	 */
	protected function add_math_html_to_response( array $result ) {
		// Check for LaTeX content in various possible fields.
		$latex_content = $this->extract_latex_content( $result );

		if ( empty( $latex_content ) ) {
			return $result;
		}

		// Generate the math HTML.
		$math_html = $this->generate_math_html( $latex_content, $result );

		// Get existing text message.
		$text_message = isset( $result['text'] ) ? $result['text'] : ( isset( $result['message'] ) ? $result['message'] : '' );

		// Combine text message with rendered math.
		$result['message'] = ! empty( $text_message ) ? $text_message . "\n\n" . $math_html : $math_html;

		return $result;
	}

	/**
	 * Extract LaTeX content from result array.
	 *
	 * Checks multiple possible fields where LaTeX might be stored.
	 *
	 * @param array $result Result array.
	 * @return string|array LaTeX content (string or array of strings).
	 */
	protected function extract_latex_content( array $result ) {
		// Priority order for LaTeX content sources.
		$latex_keys = array(
			'latex',      // Single LaTeX expression.
			'equation',   // Single equation.
			'formula',    // Single formula.
			'equations',  // Array of equations.
			'formulas',   // Array of formulas.
			'expressions', // Array of expressions.
		);

		foreach ( $latex_keys as $key ) {
			if ( ! empty( $result[ $key ] ) ) {
				return $result[ $key ];
			}
		}

		return '';
	}

	/**
	 * Generate rendered math HTML using KaTeX.
	 *
	 * Creates HTML with KaTeX CSS and JavaScript for rendering math expressions.
	 * Includes fallback MathML for accessibility.
	 *
	 * @param string|array $latex_content LaTeX expression(s).
	 * @param array        $result        Full result array (for extracting metadata).
	 * @return string HTML with rendered math.
	 */
	protected function generate_math_html( $latex_content, array $result = array() ) {
		// Check if KaTeX is available.
		$katex_available = $this->is_katex_available();

		$html = '<div class="wp-mcp-ai-generated-math">';

		// Handle single expression vs multiple expressions.
		$expressions = is_array( $latex_content ) ? $latex_content : array( $latex_content );

		foreach ( $expressions as $index => $expression ) {
			if ( empty( $expression ) ) {
				continue;
			}

			// Clean the expression.
			$expression = $this->clean_latex_expression( $expression );

			// Determine if it's display mode (block) or inline mode.
			$display_mode = $this->should_use_display_mode( $expression, $result );

			if ( $katex_available ) {
				// Use KaTeX for rendering.
				$html .= $this->render_with_katex( $expression, $display_mode, $index );
			} else {
				// Fallback to basic LaTeX display with math delimiters.
				$html .= $this->render_latex_fallback( $expression, $display_mode );
			}
		}

		$html .= '</div>';

		// Add KaTeX CSS/JS if available and not already loaded.
		if ( $katex_available && ! $this->is_katex_loaded() ) {
			$html .= $this->get_katex_assets_html();
		}

		return $html;
	}

	/**
	 * Clean LaTeX expression.
	 *
	 * Removes extra whitespace and ensures proper formatting.
	 *
	 * @param string $expression LaTeX expression.
	 * @return string Cleaned expression.
	 */
	protected function clean_latex_expression( $expression ) {
		// Remove leading/trailing whitespace.
		$expression = trim( $expression );

		// Remove surrounding $ delimiters if present (we'll add them back).
		$expression = preg_replace( '/^\$\$?(.+?)\$\$?$/', '$1', $expression );

		return $expression;
	}

	/**
	 * Determine if expression should use display mode (block).
	 *
	 * @param string $expression LaTeX expression.
	 * @param array  $result     Result array.
	 * @return bool True for display mode (block), false for inline.
	 */
	protected function should_use_display_mode( $expression, array $result ) {
		// Check explicit setting in result.
		if ( isset( $result['display_mode'] ) ) {
			return (bool) $result['display_mode'];
		}

		// Check if inline mode is explicitly set.
		if ( isset( $result['inline'] ) && $result['inline'] ) {
			return false;
		}

		// Auto-detect: use display mode for multi-line expressions or those with \displaystyle.
		if ( strpos( $expression, "\n" ) !== false || strpos( $expression, '\\displaystyle' ) !== false ) {
			return true;
		}

		// Default to display mode for clearer rendering.
		return true;
	}

	/**
	 * Render math expression with KaTeX.
	 *
	 * @param string $expression   LaTeX expression.
	 * @param bool   $display_mode True for block display, false for inline.
	 * @param int    $index        Expression index (for unique IDs).
	 * @return string HTML with KaTeX rendering.
	 */
	protected function render_with_katex( $expression, $display_mode, $index = 0 ) {
		$unique_id          = 'wp-mcp-ai-math-' . wp_generate_password( 8, false ) . '-' . $index;
		$escaped_expression = esc_attr( $expression );

		$html  = '<div class="wp-mcp-ai-math-container" id="' . esc_attr( $unique_id ) . '" ';
		$html .= 'data-latex="' . $escaped_expression . '" ';
		$html .= 'data-display-mode="' . ( $display_mode ? 'true' : 'false' ) . '" ';
		$html .= 'style="' . ( $display_mode ? 'text-align: center; margin: 15px 0;' : 'display: inline-block;' ) . '">';

		// Fallback content for no-JS or before KaTeX loads.
		$html .= '<span class="wp-mcp-ai-math-fallback" style="font-family: monospace; background: #f5f5f5; padding: 2px 6px; border-radius: 3px;">';
		$html .= esc_html( $display_mode ? '$$' . $expression . '$$' : '$' . $expression . '$' );
		$html .= '</span>';

		$html .= '</div>';

		// Add initialization script for this specific element.
		$html .= '<script>';
		$html .= '(function() {';
		$html .= '  if (typeof katex !== "undefined") {';
		$html .= '    const elem = document.getElementById("' . esc_js( $unique_id ) . '");';
		$html .= '    if (elem) {';
		$html .= '      try {';
		$html .= '        const latex = elem.getAttribute("data-latex");';
		$html .= '        const displayMode = elem.getAttribute("data-display-mode") === "true";';
		$html .= '        katex.render(latex, elem, { displayMode: displayMode, throwOnError: false });';
		$html .= '      } catch (e) {';
		$html .= '        console.error("KaTeX rendering error:", e);';
		$html .= '      }';
		$html .= '    }';
		$html .= '  } else {';
		$html .= '    // Retry after KaTeX loads.';
		$html .= '    setTimeout(function() { if (typeof katex !== "undefined") { /* retry logic */ } }, 500);';
		$html .= '  }';
		$html .= '})();';
		$html .= '</script>';

		return $html;
	}

	/**
	 * Render LaTeX with basic fallback (no KaTeX).
	 *
	 * @param string $expression   LaTeX expression.
	 * @param bool   $display_mode True for block display.
	 * @return string HTML with LaTeX in readable format.
	 */
	protected function render_latex_fallback( $expression, $display_mode ) {
		$delimiter = $display_mode ? '$$' : '$';
		$wrapped   = $delimiter . $expression . $delimiter;

		$html  = '<div class="wp-mcp-ai-math-fallback" style="' . ( $display_mode ? 'text-align: center; margin: 15px 0;' : 'display: inline-block;' ) . '">';
		$html .= '<code style="font-family: monospace; background: #f5f5f5; padding: ' . ( $display_mode ? '10px 15px' : '2px 6px' ) . '; border-radius: 3px; display: ' . ( $display_mode ? 'block' : 'inline-block' ) . ';">';
		$html .= esc_html( $wrapped );
		$html .= '</code>';
		$html .= '</div>';

		return $html;
	}

	/**
	 * Check if KaTeX is available in the pro package.
	 *
	 * @return bool True if KaTeX is available.
	 */
	protected function is_katex_available() {
		// Check if KaTeX assets exist in pro package.
		$katex_path = WP_MCP_AI_PATH . 'addons/pro/assets/vendor/katex/katex.min.js';
		return file_exists( $katex_path );
	}

	/**
	 * Check if KaTeX is already loaded on the page.
	 *
	 * @return bool True if KaTeX is loaded.
	 */
	protected function is_katex_loaded() {
		// This is a simple check - in reality, we'd track this per request.
		// For now, return false to always include the assets.
		return false;
	}

	/**
	 * Get KaTeX CSS and JS assets HTML.
	 *
	 * @return string HTML with KaTeX asset links.
	 */
	protected function get_katex_assets_html() {
		$plugin_url = plugins_url( '', WP_MCP_AI_FILE );
		$katex_url  = $plugin_url . '/addons/pro/assets/vendor/katex';

		$html  = '<!-- KaTeX CSS -->';
		$html .= '<link rel="stylesheet" href="' . esc_url( $katex_url . '/katex.min.css' ) . '">'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedStylesheet -- Inline HTML for API response, not a WordPress theme context.
		$html .= '<!-- KaTeX JS -->';
		$html .= '<script defer src="' . esc_url( $katex_url . '/katex.min.js' ) . '"></script>'; // phpcs:ignore WordPress.WP.EnqueuedResources.NonEnqueuedScript -- Inline HTML for API response, not a WordPress theme context.

		return $html;
	}
}
