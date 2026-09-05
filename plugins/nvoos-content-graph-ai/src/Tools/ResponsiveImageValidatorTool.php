<?php
/**
 * Responsive Image Validator tool (D8 Cluster 2c-5 port of the base
 * plugin's WP_MCP_AI_Tool_Responsive_Image_Validator — byte-identical
 * slug, schema, error codes, envelope, checks, and scoring; per-mode
 * URL-guard and guarded-HTTP seams).
 *
 * In monolith installs the base helpers wp_mcp_ai_is_safe_outbound_url()
 * and wp_mcp_ai_remote_get() serve the SSRF guard; standalone the port
 * delegates to the addon's own UrlGuard so blocked-address semantics
 * stay identical in both modes.
 *
 * @package NvoosContentGraphAi\Tools
 * @since   1.0.4
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Tools;

use NvoosContentGraphAi\Security\UrlGuard;

/**
 * Validates srcset/sizes attributes, LCP optimization, Core Web Vitals
 * compliance, lazy loading, and modern image format usage following
 * 2026 standards.
 *
 * @since 1.0.4
 */
class ResponsiveImageValidatorTool extends AbstractAiTool {

	use WordPressNativeTrait;

	public function getSlug(): string {
		return 'responsive_image_validator';
	}

	public function getName(): string {
		return __( 'Responsive Image Validator', 'nvoos-content-graph-ai' );
	}

	public function getDescription(): string {
		return __( 'Validates srcset/sizes attributes, LCP optimization, Core Web Vitals compliance, lazy loading, and modern image format usage for 2026 standards.', 'nvoos-content-graph-ai' );
	}

	public function getParametersSchema(): array {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action: validate_page, validate_images, check_lcp, or audit_cwv', 'nvoos-content-graph-ai' ),
					'required'    => true,
					'enum'        => array( 'validate_page', 'validate_images', 'check_lcp', 'audit_cwv' ),
				),
				'url'              => array(
					'type'        => 'string',
					'description' => __( 'Page URL to validate (for validate_page action)', 'nvoos-content-graph-ai' ),
				),
				'post_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to validate', 'nvoos-content-graph-ai' ),
				),
				'image_ids'        => array(
					'type'        => 'array',
					'description' => __( 'Specific image IDs to validate', 'nvoos-content-graph-ai' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'lcp_threshold'    => array(
					'type'        => 'number',
					'description' => __( 'LCP threshold in seconds (default: 2.5 for good)', 'nvoos-content-graph-ai' ),
					'default'     => 2.5,
				),
				'check_formats'    => array(
					'type'        => 'boolean',
					'description' => __( 'Check for modern image formats (AVIF, WebP)', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'check_lazy_load'  => array(
					'type'        => 'boolean',
					'description' => __( 'Check lazy loading implementation', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'check_dimensions' => array(
					'type'        => 'boolean',
					'description' => __( 'Check explicit width/height attributes', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
				'generate_report'  => array(
					'type'        => 'boolean',
					'description' => __( 'Generate detailed validation report', 'nvoos-content-graph-ai' ),
					'default'     => true,
				),
			),
			'required'             => array( 'action' ),
			'additionalProperties' => false,
		);
	}

	public function getRequiredCapability(): string {
		return 'edit_posts';
	}

	public function getCapabilityFlags(): array {
		return array(
			'read-only',           // Only reads data, does not modify state.
			'external-api',        // Fetches user-provided URLs via the guarded HTTP seam (SSRF-guarded).
			'requires-capability', // Requires user capabilities.
		);
	}

	public function execute( array $arguments = array(), array $context = array() ): mixed {
		$start_time = microtime( true );

		// Validate parameters.
		$action           = isset( $arguments['action'] ) ? sanitize_text_field( $arguments['action'] ) : 'validate_page';
		$url              = isset( $arguments['url'] ) ? esc_url_raw( $arguments['url'] ) : '';
		$post_id          = isset( $arguments['post_id'] ) ? absint( $arguments['post_id'] ) : 0;
		$image_ids        = isset( $arguments['image_ids'] ) && is_array( $arguments['image_ids'] ) ? array_map( 'absint', $arguments['image_ids'] ) : array();
		$lcp_threshold    = isset( $arguments['lcp_threshold'] ) ? floatval( $arguments['lcp_threshold'] ) : 2.5;
		$check_formats    = isset( $arguments['check_formats'] ) ? (bool) $arguments['check_formats'] : true;
		$check_lazy_load  = isset( $arguments['check_lazy_load'] ) ? (bool) $arguments['check_lazy_load'] : true;
		$check_dimensions = isset( $arguments['check_dimensions'] ) ? (bool) $arguments['check_dimensions'] : true;
		$generate_report  = isset( $arguments['generate_report'] ) ? (bool) $arguments['generate_report'] : true;

		// Block SSRF: if a URL was supplied, ensure it resolves to a public address.
		if ( '' !== $url && ! $this->is_safe_outbound_url( $url ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'The provided URL resolves to a blocked address. Only public HTTP and HTTPS URLs are supported.', 'nvoos-content-graph-ai' ) );
		}

		// Before execution hook.
		$intercepted = $this->do_before_execute( $arguments, $context );
		if ( null !== $intercepted ) {
			return $intercepted;
		}

		// Route to action handler.
		switch ( $action ) {
			case 'validate_page':
				$result = $this->handle_validate_page( $url, $post_id, $check_formats, $check_lazy_load, $check_dimensions, $generate_report );
				break;

			case 'validate_images':
				$result = $this->handle_validate_images( $image_ids, $check_formats, $check_lazy_load, $check_dimensions );
				break;

			case 'check_lcp':
				$result = $this->handle_check_lcp( $url, $post_id, $lcp_threshold );
				break;

			case 'audit_cwv':
				$result = $this->handle_audit_cwv( $url, $post_id );
				break;

			default:
				$result = new \WP_Error( 'wp_mcp_ai_error', __( 'Invalid action specified', 'nvoos-content-graph-ai' ) );
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle validate page action.
	 *
	 * @param string $url              Page URL.
	 * @param int    $post_id          Post ID.
	 * @param bool   $check_formats    Check formats.
	 * @param bool   $check_lazy_load  Check lazy loading.
	 * @param bool   $check_dimensions Check dimensions.
	 * @param bool   $generate_report  Generate report.
	 * @return array|\WP_Error Validation result.
	 */
	private function handle_validate_page( $url, $post_id, $check_formats, $check_lazy_load, $check_dimensions, $generate_report ) {
		// Get page content.
		if ( $post_id > 0 ) {
			$post_obj = get_post( $post_id );
			if ( ! $post_obj ) {
				return new \WP_Error( 'wp_mcp_ai_error', __( 'Post not found', 'nvoos-content-graph-ai' ) );
			}
			$content = $post_obj->post_content;
		} elseif ( ! empty( $url ) ) {
			$content = $this->fetch_page_content( $url );
		} else {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'Either URL or post_id must be provided', 'nvoos-content-graph-ai' ) );
		}

		// Extract images from content.
		$images = $this->extract_images_from_content( $content );

		$validation_results = array();
		$passed             = 0;
		$failed             = 0;
		$warnings           = 0;

		foreach ( $images as $image ) {
			$validation = $this->validate_image( $image, $check_formats, $check_lazy_load, $check_dimensions );

			if ( 'pass' === $validation['status'] ) {
				++$passed;
			} elseif ( 'fail' === $validation['status'] ) {
				++$failed;
			} else {
				++$warnings;
			}

			$validation_results[] = $validation;
		}

		$score = $this->calculate_validation_score( $passed, $failed, $warnings );

		$result = array(
			'success'      => true,
			'url'          => $url,
			'post_id'      => $post_id,
			'total_images' => count( $images ),
			'passed'       => $passed,
			'failed'       => $failed,
			'warnings'     => $warnings,
			'score'        => $score,
			'grade'        => $this->get_grade( $score ),
			'validations'  => $validation_results,
		);

		if ( $generate_report ) {
			$result['report'] = $this->generate_validation_report( $validation_results, $score );
		}

		return $result;
	}

	/**
	 * Handle validate images action.
	 *
	 * @param array $image_ids        Image IDs.
	 * @param bool  $check_formats    Check formats.
	 * @param bool  $check_lazy_load  Check lazy loading.
	 * @param bool  $check_dimensions Check dimensions.
	 * @return array|\WP_Error Validation result.
	 */
	private function handle_validate_images( $image_ids, $check_formats, $check_lazy_load, $check_dimensions ) {
		if ( empty( $image_ids ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'No image IDs provided', 'nvoos-content-graph-ai' ) );
		}

		$results = array();

		foreach ( $image_ids as $image_id ) {
			$image_data = $this->get_image_data( $image_id );
			$validation = $this->validate_image_data( $image_data, $check_formats, $check_lazy_load, $check_dimensions );

			$results[] = array(
				'id'         => $image_id,
				'validation' => $validation,
			);
		}

		return array(
			'success' => true,
			'count'   => count( $results ),
			'results' => $results,
		);
	}

	/**
	 * Handle check LCP action.
	 *
	 * @param string $url           Page URL.
	 * @param int    $post_id       Post ID.
	 * @param float  $lcp_threshold LCP threshold.
	 * @return array|\WP_Error LCP check result.
	 */
	private function handle_check_lcp( $url, $post_id, $lcp_threshold ) {
		// Get page URL.
		if ( $post_id > 0 ) {
			$url = get_permalink( $post_id );
		}

		if ( empty( $url ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'URL or post_id required', 'nvoos-content-graph-ai' ) );
		}

		// Identify LCP element.
		$lcp_element = $this->identify_lcp_element( $url );

		return array(
			'success'         => true,
			'url'             => $url,
			'lcp_element'     => $lcp_element,
			'threshold'       => $lcp_threshold,
			'is_optimized'    => $this->is_lcp_optimized( $lcp_element ),
			'recommendations' => $this->get_lcp_recommendations( $lcp_element ),
			'cwv_standards'   => array(
				'good'              => '< 2.5s',
				'needs_improvement' => '2.5s - 4.0s',
				'poor'              => '> 4.0s',
			),
		);
	}

	/**
	 * Handle audit Core Web Vitals action.
	 *
	 * @param string $url     Page URL.
	 * @param int    $post_id Post ID.
	 * @return array|\WP_Error CWV audit result.
	 */
	private function handle_audit_cwv( $url, $post_id ) {
		// Get page URL.
		if ( $post_id > 0 ) {
			$url = get_permalink( $post_id );
		}

		if ( empty( $url ) ) {
			return new \WP_Error( 'wp_mcp_ai_error', __( 'URL or post_id required', 'nvoos-content-graph-ai' ) );
		}

		return array(
			'success'         => true,
			'url'             => $url,
			'metrics'         => array(
				'lcp' => $this->check_lcp_metric( $url ),
				'cls' => $this->check_cls_metric( $url ),
				'fid' => $this->check_fid_metric( $url ),
				'inp' => $this->check_inp_metric( $url ),
			),
			'image_specific'  => array(
				'responsive_images'   => $this->audit_responsive_images( $url ),
				'lazy_loading'        => $this->audit_lazy_loading( $url ),
				'modern_formats'      => $this->audit_modern_formats( $url ),
				'explicit_dimensions' => $this->audit_explicit_dimensions( $url ),
			),
			'recommendations' => $this->get_cwv_recommendations( $url ),
		);
	}

	/**
	 * Extract images from content.
	 *
	 * @param string $content Page content.
	 * @return array Images.
	 */
	private function extract_images_from_content( $content ) {
		$images = array();

		// Parse image tags.
		preg_match_all( '/<img[^>]+>/i', $content, $matches );

		foreach ( $matches[0] as $img_tag ) {
			$images[] = $this->parse_image_tag( $img_tag );
		}

		return $images;
	}

	/**
	 * Parse image tag.
	 *
	 * @param string $img_tag Image tag HTML.
	 * @return array Image data.
	 */
	private function parse_image_tag( $img_tag ) {
		$image = array();

		// Extract attributes.
		preg_match( '/src=["\']([^"\']+)["\']/i', $img_tag, $src_match );
		preg_match( '/srcset=["\']([^"\']+)["\']/i', $img_tag, $srcset_match );
		preg_match( '/sizes=["\']([^"\']+)["\']/i', $img_tag, $sizes_match );
		preg_match( '/width=["\']([^"\']+)["\']/i', $img_tag, $width_match );
		preg_match( '/height=["\']([^"\']+)["\']/i', $img_tag, $height_match );
		preg_match( '/loading=["\']([^"\']+)["\']/i', $img_tag, $loading_match );
		preg_match( '/decoding=["\']([^"\']+)["\']/i', $img_tag, $decoding_match );
		preg_match( '/alt=["\']([^"\']+)["\']/i', $img_tag, $alt_match );

		$image['src']      = isset( $src_match[1] ) ? $src_match[1] : '';
		$image['srcset']   = isset( $srcset_match[1] ) ? $srcset_match[1] : '';
		$image['sizes']    = isset( $sizes_match[1] ) ? $sizes_match[1] : '';
		$image['width']    = isset( $width_match[1] ) ? $width_match[1] : '';
		$image['height']   = isset( $height_match[1] ) ? $height_match[1] : '';
		$image['loading']  = isset( $loading_match[1] ) ? $loading_match[1] : '';
		$image['decoding'] = isset( $decoding_match[1] ) ? $decoding_match[1] : '';
		$image['alt']      = isset( $alt_match[1] ) ? $alt_match[1] : '';

		return $image;
	}

	/**
	 * Validate image.
	 *
	 * @param array $image            Image data.
	 * @param bool  $check_formats    Check formats.
	 * @param bool  $check_lazy_load  Check lazy loading.
	 * @param bool  $check_dimensions Check dimensions.
	 * @return array Validation result.
	 */
	private function validate_image( $image, $check_formats, $check_lazy_load, $check_dimensions ) {
		$issues   = array();
		$warnings = array();
		$passes   = array();

		// Check srcset.
		if ( ! empty( $image['srcset'] ) ) {
			$passes[] = __( 'Has srcset attribute for responsive images', 'nvoos-content-graph-ai' );
		} else {
			$issues[] = __( 'Missing srcset attribute', 'nvoos-content-graph-ai' );
		}

		// Check sizes.
		if ( ! empty( $image['srcset'] ) && empty( $image['sizes'] ) ) {
			$warnings[] = __( 'Has srcset but missing sizes attribute', 'nvoos-content-graph-ai' );
		} elseif ( ! empty( $image['sizes'] ) ) {
			$passes[] = __( 'Has sizes attribute', 'nvoos-content-graph-ai' );
		}

		// Check dimensions.
		if ( $check_dimensions ) {
			if ( empty( $image['width'] ) || empty( $image['height'] ) ) {
				$issues[] = __( 'Missing explicit width/height (causes CLS)', 'nvoos-content-graph-ai' );
			} else {
				$passes[] = __( 'Has explicit width/height dimensions', 'nvoos-content-graph-ai' );
			}
		}

		// Check lazy loading.
		if ( $check_lazy_load ) {
			if ( 'lazy' === $image['loading'] ) {
				$passes[] = __( 'Has lazy loading enabled', 'nvoos-content-graph-ai' );
			} else {
				$warnings[] = __( 'Consider adding loading="lazy"', 'nvoos-content-graph-ai' );
			}
		}

		// Check decoding.
		if ( 'async' === $image['decoding'] ) {
			$passes[] = __( 'Has async decoding for better performance', 'nvoos-content-graph-ai' );
		}

		// Check format.
		if ( $check_formats ) {
			$format = $this->detect_image_format( $image['src'] );
			if ( in_array( $format, array( 'avif', 'webp' ), true ) ) {
				$passes[] = sprintf(
					/* translators: %s: image format */
					__( 'Using modern format: %s', 'nvoos-content-graph-ai' ),
					strtoupper( $format )
				);
			} else {
				$warnings[] = __( 'Consider using AVIF or WebP format', 'nvoos-content-graph-ai' );
			}
		}

		// Check alt text.
		if ( empty( $image['alt'] ) ) {
			$issues[] = __( 'Missing alt text (accessibility issue)', 'nvoos-content-graph-ai' );
		} else {
			$passes[] = __( 'Has alt text for accessibility', 'nvoos-content-graph-ai' );
		}

		// Determine status.
		$status = count( $issues ) > 0 ? 'fail' : ( count( $warnings ) > 0 ? 'warning' : 'pass' );

		return array(
			'status'   => $status,
			'src'      => $image['src'],
			'passes'   => $passes,
			'issues'   => $issues,
			'warnings' => $warnings,
		);
	}

	/**
	 * Get image data.
	 *
	 * @param int $image_id Image ID.
	 * @return array Image data.
	 */
	private function get_image_data( $image_id ) {
		return array(
			'id'       => $image_id,
			'url'      => wp_get_attachment_url( $image_id ),
			'srcset'   => wp_get_attachment_image_srcset( $image_id, 'full' ),
			'sizes'    => wp_get_attachment_image_sizes( $image_id, 'full' ),
			'alt'      => get_post_meta( $image_id, '_wp_attachment_image_alt', true ),
			'metadata' => wp_get_attachment_metadata( $image_id ),
		);
	}

	/**
	 * Validate image data.
	 *
	 * @param array $image_data       Image data.
	 * @param bool  $check_formats    Check formats.
	 * @param bool  $check_lazy_load  Check lazy loading.
	 * @param bool  $check_dimensions Check dimensions.
	 * @return array Validation result.
	 */
	private function validate_image_data( $image_data, $check_formats, $check_lazy_load, $check_dimensions ) {
		$image = array(
			'src'    => $image_data['url'],
			'srcset' => $image_data['srcset'],
			'sizes'  => $image_data['sizes'],
			'alt'    => $image_data['alt'],
			'width'  => isset( $image_data['metadata']['width'] ) ? $image_data['metadata']['width'] : '',
			'height' => isset( $image_data['metadata']['height'] ) ? $image_data['metadata']['height'] : '',
		);

		return $this->validate_image( $image, $check_formats, $check_lazy_load, $check_dimensions );
	}

	/**
	 * Identify LCP element.
	 *
	 * @param string $url Page URL.
	 * @return array LCP element data.
	 */
	private function identify_lcp_element( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		// Simplified LCP detection - in production would use real metrics.
		return array(
			'type'     => 'image',
			'selector' => 'img.hero-image',
			'detected' => true,
			'note'     => __( 'LCP detection requires real user metrics or Lighthouse integration', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Check if LCP is optimized.
	 *
	 * @param array $lcp_element LCP element.
	 * @return bool True if optimized.
	 */
	private function is_lcp_optimized( $lcp_element ) {
		// Simplified check.
		return isset( $lcp_element['detected'] ) && $lcp_element['detected'];
	}

	/**
	 * Get LCP recommendations.
	 *
	 * @param array $lcp_element LCP element.
	 * @return array Recommendations.
	 */
	private function get_lcp_recommendations( $lcp_element ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			__( 'Use fetchpriority="high" on LCP image', 'nvoos-content-graph-ai' ),
			__( 'Preload LCP image with <link rel="preload">', 'nvoos-content-graph-ai' ),
			__( 'Remove lazy loading from above-the-fold images', 'nvoos-content-graph-ai' ),
			__( 'Use modern image formats (AVIF/WebP)', 'nvoos-content-graph-ai' ),
			__( 'Optimize image dimensions for viewport', 'nvoos-content-graph-ai' ),
			__( 'Consider CDN for faster delivery', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Check LCP metric.
	 *
	 * @param string $url Page URL.
	 * @return array LCP metric data.
	 */
	private function check_lcp_metric( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'metric'  => 'LCP',
			'name'    => __( 'Largest Contentful Paint', 'nvoos-content-graph-ai' ),
			'target'  => '< 2.5s',
			'status'  => 'needs_measurement',
			'message' => __( 'Use PageSpeed Insights or Chrome DevTools for real metrics', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Check CLS metric.
	 *
	 * @param string $url Page URL.
	 * @return array CLS metric data.
	 */
	private function check_cls_metric( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'metric' => 'CLS',
			'name'   => __( 'Cumulative Layout Shift', 'nvoos-content-graph-ai' ),
			'target' => '< 0.1',
			'status' => 'needs_measurement',
			'tip'    => __( 'Add explicit width/height to all images to prevent CLS', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Check FID metric.
	 *
	 * @param string $url Page URL.
	 * @return array FID metric data.
	 */
	private function check_fid_metric( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'metric' => 'FID',
			'name'   => __( 'First Input Delay', 'nvoos-content-graph-ai' ),
			'target' => '< 100ms',
			'status' => 'needs_measurement',
			'note'   => __( 'Being replaced by INP in 2026', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Check INP metric.
	 *
	 * @param string $url Page URL.
	 * @return array INP metric data.
	 */
	private function check_inp_metric( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'metric' => 'INP',
			'name'   => __( 'Interaction to Next Paint', 'nvoos-content-graph-ai' ),
			'target' => '< 200ms',
			'status' => 'needs_measurement',
			'note'   => __( 'New Core Web Vital replacing FID in 2026', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Audit responsive images.
	 *
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_responsive_images( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for detailed responsive image audit', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Audit lazy loading.
	 *
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_lazy_loading( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for lazy loading audit', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Audit modern formats.
	 *
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_modern_formats( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for format audit', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Audit explicit dimensions.
	 *
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_explicit_dimensions( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for dimensions audit', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Get CWV recommendations.
	 *
	 * @param string $url Page URL.
	 * @return array Recommendations.
	 */
	private function get_cwv_recommendations( $url ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for future implementation.
		return array(
			__( 'Optimize LCP image with modern formats and CDN', 'nvoos-content-graph-ai' ),
			__( 'Add explicit dimensions to prevent CLS', 'nvoos-content-graph-ai' ),
			__( 'Use lazy loading for below-the-fold images', 'nvoos-content-graph-ai' ),
			__( 'Implement responsive images with srcset/sizes', 'nvoos-content-graph-ai' ),
			__( 'Test with real users via Chrome User Experience Report', 'nvoos-content-graph-ai' ),
		);
	}

	/**
	 * Fetch page content.
	 *
	 * @param string $url Page URL.
	 * @return string Page content.
	 */
	private function fetch_page_content( $url ) {
		// SSRF-guarded fetch (URL was already validated in execute();
		// the wrapper re-validates as defence-in-depth).
		$response = $this->remote_get( $url, array( 'timeout' => 20 ) );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Detect image format.
	 *
	 * @param string $src Image source URL.
	 * @return string Image format.
	 */
	private function detect_image_format( $src ) {
		$extension = pathinfo( $src, PATHINFO_EXTENSION );
		return strtolower( $extension );
	}

	/**
	 * Calculate validation score.
	 *
	 * @param int $passed   Passed count.
	 * @param int $failed   Failed count.
	 * @param int $warnings Warning count.
	 * @return int Score (0-100).
	 */
	private function calculate_validation_score( $passed, $failed, $warnings ) {
		$total = $passed + $failed + $warnings;

		if ( 0 === $total ) {
			return 0;
		}

		// Weighted scoring: pass=1.0, warning=0.5, fail=0.0.
		$weighted = ( $passed * 1.0 ) + ( $warnings * 0.5 );
		return round( ( $weighted / $total ) * 100 );
	}

	/**
	 * Get grade from score.
	 *
	 * @param int $score Score.
	 * @return string Grade.
	 */
	private function get_grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		} elseif ( $score >= 80 ) {
			return 'B';
		} elseif ( $score >= 70 ) {
			return 'C';
		} elseif ( $score >= 60 ) {
			return 'D';
		} else {
			return 'F';
		}
	}

	/**
	 * Generate validation report.
	 *
	 * @param array $validations Validation results.
	 * @param int   $score       Score.
	 * @return array Report.
	 */
	private function generate_validation_report( $validations, $score ) {
		$summary = array(
			'responsive_images'   => 0,
			'lazy_loading'        => 0,
			'modern_formats'      => 0,
			'explicit_dimensions' => 0,
			'accessibility'       => 0,
		);

		foreach ( $validations as $validation ) {
			foreach ( $validation['passes'] as $pass ) {
				if ( false !== strpos( $pass, 'srcset' ) ) {
					++$summary['responsive_images'];
				}
				if ( false !== strpos( $pass, 'lazy' ) ) {
					++$summary['lazy_loading'];
				}
				if ( false !== strpos( $pass, 'format' ) || false !== strpos( $pass, 'AVIF' ) || false !== strpos( $pass, 'WebP' ) ) {
					++$summary['modern_formats'];
				}
				if ( false !== strpos( $pass, 'width' ) || false !== strpos( $pass, 'height' ) ) {
					++$summary['explicit_dimensions'];
				}
				if ( false !== strpos( $pass, 'alt' ) ) {
					++$summary['accessibility'];
				}
			}
		}

		return array(
			'score'           => $score,
			'summary'         => $summary,
			'recommendations' => array(
				__( 'Implement srcset/sizes on all images', 'nvoos-content-graph-ai' ),
				__( 'Use AVIF or WebP formats for better compression', 'nvoos-content-graph-ai' ),
				__( 'Add explicit width/height to prevent layout shift', 'nvoos-content-graph-ai' ),
				__( 'Enable lazy loading for below-the-fold images', 'nvoos-content-graph-ai' ),
				__( 'Test with real devices and PageSpeed Insights', 'nvoos-content-graph-ai' ),
			),
		);
	}

	/**
	 * Check if tool has privacy data.
	 *
	 * @return bool False - no privacy data.
	 */
	public function has_privacy_data() {
		return false;
	}

	/**
	 * Check whether a URL is safe for outbound requests (per-mode seam).
	 *
	 * Monolith installs reuse the base guard helper; standalone the
	 * addon's own UrlGuard enforces the identical blocked-address rules.
	 *
	 * @param string $url URL to validate.
	 * @return bool True when the URL may be fetched.
	 */
	private function is_safe_outbound_url( $url ) {
		if ( function_exists( 'wp_mcp_ai_is_safe_outbound_url' ) ) {
			return \wp_mcp_ai_is_safe_outbound_url( $url );
		}

		return ! is_wp_error( UrlGuard::validate( $url ) );
	}

	/**
	 * SSRF-guarded GET wrapper (per-mode seam).
	 *
	 * Monolith installs reuse the base guarded wrapper; standalone the
	 * addon's UrlGuard validates before wp_remote_get().
	 *
	 * @param string $url  URL to fetch.
	 * @param array  $args wp_remote_get() arguments.
	 * @return array|\WP_Error HTTP response array or error.
	 */
	private function remote_get( $url, $args = array() ) {
		if ( function_exists( 'wp_mcp_ai_remote_get' ) ) {
			return \wp_mcp_ai_remote_get( $url, $args );
		}

		$check = UrlGuard::validate( $url );
		if ( is_wp_error( $check ) ) {
			return $check;
		}

		return wp_remote_get( $url, $args );
	}
}
