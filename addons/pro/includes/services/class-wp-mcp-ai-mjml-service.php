<?php
/**
 * Email Template Service using MJML
 *
 * Provides responsive email template generation using the MJML NPM package.
 *
 * @package WP_MCP_AI
 * @since 1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 *
 * SPDX-License-Identifier: LicenseRef-Proprietary
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
	use WP_MCP_AI_Media_Worker_Client;

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

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured).
		$sidecar = $this->sidecar_request(
			'/api/email/compile-mjml',
			array(
				'mjml'    => $mjml,
				'options' => $options,
			)
		);
		if ( ! is_wp_error( $sidecar ) && isset( $sidecar['html'] ) ) {
			return $sidecar['html'];
		}

		/**
		 * Filter to allow custom MJML compilation.
		 *
		 * Runs after the sidecar attempt: legacy local-Node handlers only
		 * execute when a local Node.js is installed, and custom
		 * implementations keep working when no sidecar is configured.
		 *
		 * @param string|false $result HTML output or false.
		 * @param array        $params Compilation parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_mjml_compile', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		// Fall back to local Node.js (retained for non-Docker environments).
		if ( $this->is_available() ) {
			$local = apply_filters( 'wp_mcp_ai_mjml_compile_local', false, $params );
			if ( false !== $local ) {
				return $local;
			}
		}

		return new WP_Error(
			'wp_mcp_ai_mjml_not_configured',
			__( 'MJML compilation requires Node.js integration. Configure the Media Worker sidecar or implement the wp_mcp_ai_mjml_compile filter. See docs/INTEGRATION_BEST_PRACTICES.md for setup guide.', 'mcp-ai-wpoos-pro' ),
			array(
				'status'  => 501,
				'package' => 'mjml',
			)
		);
	}

	/**
	 * Generate email template from components
	 *
	 * Markup building lives in `Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder`
	 * so the Laravel runtime can use it too. This method keeps the WordPress
	 * concerns: the empty-input guard and rich-text sanitisation.
	 *
	 * @since 1.1.0
	 * @since 1.2.20 Delegates markup building to lib/core.
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

		// Rich text is sanitised here, at the WordPress boundary: the core
		// builder injects component content verbatim by design.
		$sanitised = array();

		foreach ( $components as $component ) {
			if ( is_array( $component ) && isset( $component['content'] ) ) {
				$component['content'] = wp_kses_post( (string) $component['content'] );
			}

			$sanitised[] = $component;
		}

		$builder = new \Nvoos\Core\Domain\Service\Mjml\MjmlTemplateBuilder();

		return $builder->build( $sanitised, wp_parse_args( $options, array() ) );
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

		// Try the Media Worker sidecar first (opt-in routing — fails fast
		// when no sidecar URL is configured).
		$sidecar = $this->sidecar_request(
			'/api/email/compile-mjml',
			array(
				'mjml'    => $mjml,
				'options' => array( 'validationLevel' => 'strict' ),
			)
		);
		if ( ! is_wp_error( $sidecar ) && isset( $sidecar['errors'] ) ) {
			// The worker compiles with validationLevel=strict, which throws
			// on hard errors — a 200 response with no reported errors means
			// the markup is valid.
			return empty( $sidecar['errors'] );
		}

		/**
		 * Filter to allow custom MJML validation.
		 *
		 * @param bool|WP_Error|false $result Validation result or false.
		 * @param array               $params Validation parameters.
		 */
		$result = apply_filters( 'wp_mcp_ai_mjml_validate', false, $params );
		if ( false !== $result ) {
			return $result;
		}

		// Fall back to local Node.js.
		if ( $this->is_available() ) {
			$local = apply_filters( 'wp_mcp_ai_mjml_validate_local', false, $params );
			if ( false !== $local ) {
				return $local;
			}
		}

		// If not implemented, return true (skip validation).
		return true;
	}
}
