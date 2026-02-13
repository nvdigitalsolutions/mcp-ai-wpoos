<?php
/**
 * Email Template Service using MJML
 *
 * Provides responsive email template generation using the MJML NPM package.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MJML Email Template Service class
 *
 * Generates responsive email templates using MJML with:
 * - Mobile-first responsive design
 * - Cross-client compatibility
 * - Component-based layout
 * - HTML output for email clients
 *
 * @since 1.1.0
 */
class WP_MCP_AI_MJML_Service {

	/**
	 * Check if MJML package is available
	 *
	 * @return bool True if available, false otherwise.
	 */
	public function is_available() {
		// Check if mjml package exists in vendor directory (production) or node_modules (development).
		$vendor_path       = WP_MCP_AI_PRO_PATH . 'assets/vendor/mjml/lib/index.js';
		$node_modules_path = WP_MCP_AI_PRO_PATH . 'node_modules/mjml/lib/index.js';

		if ( ! file_exists( $vendor_path ) && ! file_exists( $node_modules_path ) ) {
			return false;
		}

		// Use Process Service to check for Node.js availability.
		$process_service = \WP_MCP_AI\Services\WP_MCP_AI_Process_Service::get_instance();
		return $process_service->is_command_available( 'node' );
	}

	/**
	 * Compile MJML to responsive HTML
	 *
	 * @param string $mjml    MJML markup.
	 * @param array  $options Compilation options.
	 * @return string|WP_Error HTML output or error.
	 */
	public function compile( $mjml, $options = array() ) {
		if ( empty( $mjml ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_mjml',
				__( 'MJML content is empty.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$defaults = array(
			'minify'          => false,     // Minify HTML output.
			'beautify'        => true,      // Beautify HTML output.
			'validationLevel' => 'soft',    // Validation: strict, soft, skip.
		);

		$options = wp_parse_args( $options, $defaults );

		$params = array(
			'action'  => 'compile_mjml',
			'mjml'    => $mjml,
			'options' => $options,
		);

		/**
		 * Filter to allow custom MJML compilation.
		 *
		 * @param string|false $result HTML output or false.
		 * @param array        $params Compilation parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_mjml_compile', false, $params );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_mjml_not_configured',
				__( 'MJML compilation requires Node.js integration. Please implement the wp_mcp_ai_mjml_compile filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
				array(
					'status'  => 501,
					'package' => 'mjml',
				)
			);
		}

		return $result;
	}

	/**
	 * Generate email template from components
	 *
	 * @param array $components Email components.
	 * @param array $options    Generation options.
	 * @return string|WP_Error MJML markup or error.
	 */
	public function generate_template( $components, $options = array() ) {
		if ( empty( $components ) || ! is_array( $components ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_components',
				__( 'Email components are invalid.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$defaults = array(
			'title'            => '',
			'preview_text'     => '',
			'font_family'      => 'Arial, sans-serif',
			'background_color' => '#f4f4f4',
			'container_width'  => '600px',
		);

		$options = wp_parse_args( $options, $defaults );

		// Build MJML structure.
		$mjml  = '<mjml>';
		$mjml .= '<mj-head>';

		if ( ! empty( $options['title'] ) ) {
			$mjml .= '<mj-title>' . esc_html( $options['title'] ) . '</mj-title>';
		}

		if ( ! empty( $options['preview_text'] ) ) {
			$mjml .= '<mj-preview>' . esc_html( $options['preview_text'] ) . '</mj-preview>';
		}

		$mjml .= '<mj-attributes>';
		$mjml .= '<mj-all font-family="' . esc_attr( $options['font_family'] ) . '" />';
		$mjml .= '<mj-body background-color="' . esc_attr( $options['background_color'] ) . '" />';
		$mjml .= '<mj-container background-color="#ffffff" width="' . esc_attr( $options['container_width'] ) . '" />';
		$mjml .= '</mj-attributes>';
		$mjml .= '</mj-head>';

		$mjml .= '<mj-body>';
		$mjml .= '<mj-section>';
		$mjml .= '<mj-column>';

		// Add components.
		foreach ( $components as $component ) {
			$mjml .= $this->render_component( $component );
		}

		$mjml .= '</mj-column>';
		$mjml .= '</mj-section>';
		$mjml .= '</mj-body>';
		$mjml .= '</mjml>';

		return $mjml;
	}

	/**
	 * Render individual email component
	 *
	 * @param array $component Component data.
	 * @return string MJML component markup.
	 */
	private function render_component( $component ) {
		$type = isset( $component['type'] ) ? $component['type'] : 'text';

		switch ( $type ) {
			case 'text':
				return sprintf(
					'<mj-text%s>%s</mj-text>',
					isset( $component['attributes'] ) ? $this->build_attributes( $component['attributes'] ) : '',
					isset( $component['content'] ) ? wp_kses_post( $component['content'] ) : ''
				);

			case 'button':
				return sprintf(
					'<mj-button%s>%s</mj-button>',
					isset( $component['attributes'] ) ? $this->build_attributes( $component['attributes'] ) : '',
					isset( $component['text'] ) ? esc_html( $component['text'] ) : 'Click Here'
				);

			case 'image':
				return sprintf(
					'<mj-image%s />',
					isset( $component['attributes'] ) ? $this->build_attributes( $component['attributes'] ) : ''
				);

			case 'divider':
				return sprintf(
					'<mj-divider%s />',
					isset( $component['attributes'] ) ? $this->build_attributes( $component['attributes'] ) : ''
				);

			case 'spacer':
				return sprintf(
					'<mj-spacer%s />',
					isset( $component['attributes'] ) ? $this->build_attributes( $component['attributes'] ) : ''
				);

			default:
				return '';
		}
	}

	/**
	 * Build HTML attributes string
	 *
	 * @param array $attributes Attributes array.
	 * @return string Attributes string.
	 */
	private function build_attributes( $attributes ) {
		if ( empty( $attributes ) || ! is_array( $attributes ) ) {
			return '';
		}

		$attr_string = '';
		foreach ( $attributes as $key => $value ) {
			$attr_string .= ' ' . esc_attr( $key ) . '="' . esc_attr( $value ) . '"';
		}

		return $attr_string;
	}

	/**
	 * Validate MJML markup
	 *
	 * @param string $mjml MJML markup.
	 * @return bool|WP_Error True if valid, error if invalid.
	 */
	public function validate( $mjml ) {
		if ( empty( $mjml ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_mjml',
				__( 'MJML content is empty.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$params = array(
			'action' => 'validate_mjml',
			'mjml'   => $mjml,
		);

		/**
		 * Filter to allow custom MJML validation.
		 *
		 * @param bool|WP_Error|false $result Validation result or false.
		 * @param array               $params Validation parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_mjml_validate', false, $params );

		if ( false === $result ) {
			// If not implemented, return true (skip validation).
			return true;
		}

		return $result;
	}
}
