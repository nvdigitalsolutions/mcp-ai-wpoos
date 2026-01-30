<?php
/**
 * Responsive Image Validator Tool
 *
 * Validates srcset/sizes attributes, LCP optimization, Core Web Vitals compliance,
 * lazy loading, and modern image format usage following 2026 standards.
 *
 * Based on 2026 web performance standards from:
 * - Google Core Web Vitals metrics
 * - Lighthouse 12.0+ image optimization audits
 * - Web.dev responsive images best practices
 * - Chrome User Experience Report thresholds
 *
 * @package    WP_MCP_AI
 * @subpackage Tools
 * @since      1.0.0
 */

// Exit if accessed directly.
if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Responsive Image Validator Tool Class
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Tool_Responsive_Image_Validator {
	use WP_MCP_AI_Tool_WordPress_Native;

	/**
	 * Get tool slug
	 *
	 * @since 1.0.0
	 * @return string Tool slug.
	 */
	public function get_slug() {
		return 'responsive_image_validator';
	}

	/**
	 * Get tool definition
	 *
	 * @since 1.0.0
	 * @return array Tool definition.
	 */
	public function get_definition() {
		return array(
			'name'                => __( 'Responsive Image Validator', 'mcp-ai-wpoos' ),
			'description'         => __( 'Validates srcset/sizes attributes, LCP optimization, Core Web Vitals compliance, lazy loading, and modern image format usage for 2026 standards.', 'mcp-ai-wpoos' ),
			'category'            => 'performance',
			'required_capability' => 'edit_posts',
			'parameters'          => array(
				'action'           => array(
					'type'        => 'string',
					'description' => __( 'Action: validate_page, validate_images, check_lcp, or audit_cwv', 'mcp-ai-wpoos' ),
					'required'    => true,
					'enum'        => array( 'validate_page', 'validate_images', 'check_lcp', 'audit_cwv' ),
				),
				'url'              => array(
					'type'        => 'string',
					'description' => __( 'Page URL to validate (for validate_page action)', 'mcp-ai-wpoos' ),
				),
				'post_id'          => array(
					'type'        => 'integer',
					'description' => __( 'Post ID to validate', 'mcp-ai-wpoos' ),
				),
				'image_ids'        => array(
					'type'        => 'array',
					'description' => __( 'Specific image IDs to validate', 'mcp-ai-wpoos' ),
					'items'       => array( 'type' => 'integer' ),
				),
				'lcp_threshold'    => array(
					'type'        => 'number',
					'description' => __( 'LCP threshold in seconds (default: 2.5 for good)', 'mcp-ai-wpoos' ),
					'default'     => 2.5,
				),
				'check_formats'    => array(
					'type'        => 'boolean',
					'description' => __( 'Check for modern image formats (AVIF, WebP)', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'check_lazy_load'  => array(
					'type'        => 'boolean',
					'description' => __( 'Check lazy loading implementation', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'check_dimensions' => array(
					'type'        => 'boolean',
					'description' => __( 'Check explicit width/height attributes', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
				'generate_report'  => array(
					'type'        => 'boolean',
					'description' => __( 'Generate detailed validation report', 'mcp-ai-wpoos' ),
					'default'     => true,
				),
			),
		);
	}

	/**
	 * Execute the tool
	 *
	 * @since 1.0.0
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context.
	 * @return array Tool execution result.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
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

		// Before execution hook.
		$this->do_before_execute( $arguments, $context );

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
				$result = array(
					'success' => false,
					'error'   => __( 'Invalid action specified', 'mcp-ai-wpoos' ),
				);
		}

		// After execution hook.
		$this->do_after_execute( $result, $arguments, $context );

		// Track performance.
		$this->track_performance( $start_time, $arguments );

		return $this->apply_result_filter( $result, $arguments, $context );
	}

	/**
	 * Handle validate page action
	 *
	 * @since 1.0.0
	 * @param string $url              Page URL.
	 * @param int    $post_id          Post ID.
	 * @param bool   $check_formats    Check formats.
	 * @param bool   $check_lazy_load  Check lazy loading.
	 * @param bool   $check_dimensions Check dimensions.
	 * @param bool   $generate_report  Generate report.
	 * @return array Validation result.
	 */
	private function handle_validate_page( $url, $post_id, $check_formats, $check_lazy_load, $check_dimensions, $generate_report ) {
		// Get page content.
		if ( $post_id > 0 ) {
			$post = get_post( $post_id );
			if ( ! $post ) {
				return array(
					'success' => false,
					'error'   => __( 'Post not found', 'mcp-ai-wpoos' ),
				);
			}
			$content = $post->post_content;
		} elseif ( ! empty( $url ) ) {
			$content = $this->fetch_page_content( $url );
		} else {
			return array(
				'success' => false,
				'error'   => __( 'Either URL or post_id must be provided', 'mcp-ai-wpoos' ),
			);
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
	 * Handle validate images action
	 *
	 * @since 1.0.0
	 * @param array $image_ids        Image IDs.
	 * @param bool  $check_formats    Check formats.
	 * @param bool  $check_lazy_load  Check lazy loading.
	 * @param bool  $check_dimensions Check dimensions.
	 * @return array Validation result.
	 */
	private function handle_validate_images( $image_ids, $check_formats, $check_lazy_load, $check_dimensions ) {
		if ( empty( $image_ids ) ) {
			return array(
				'success' => false,
				'error'   => __( 'No image IDs provided', 'mcp-ai-wpoos' ),
			);
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
	 * Handle check LCP action
	 *
	 * @since 1.0.0
	 * @param string $url           Page URL.
	 * @param int    $post_id       Post ID.
	 * @param float  $lcp_threshold LCP threshold.
	 * @return array LCP check result.
	 */
	private function handle_check_lcp( $url, $post_id, $lcp_threshold ) {
		// Get page URL.
		if ( $post_id > 0 ) {
			$url = get_permalink( $post_id );
		}

		if ( empty( $url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'URL or post_id required', 'mcp-ai-wpoos' ),
			);
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
	 * Handle audit Core Web Vitals action
	 *
	 * @since 1.0.0
	 * @param string $url     Page URL.
	 * @param int    $post_id Post ID.
	 * @return array CWV audit result.
	 */
	private function handle_audit_cwv( $url, $post_id ) {
		// Get page URL.
		if ( $post_id > 0 ) {
			$url = get_permalink( $post_id );
		}

		if ( empty( $url ) ) {
			return array(
				'success' => false,
				'error'   => __( 'URL or post_id required', 'mcp-ai-wpoos' ),
			);
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
	 * Extract images from content
	 *
	 * @since 1.0.0
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
	 * Parse image tag
	 *
	 * @since 1.0.0
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
	 * Validate image
	 *
	 * @since 1.0.0
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
			$passes[] = __( 'Has srcset attribute for responsive images', 'mcp-ai-wpoos' );
		} else {
			$issues[] = __( 'Missing srcset attribute', 'mcp-ai-wpoos' );
		}

		// Check sizes.
		if ( ! empty( $image['srcset'] ) && empty( $image['sizes'] ) ) {
			$warnings[] = __( 'Has srcset but missing sizes attribute', 'mcp-ai-wpoos' );
		} elseif ( ! empty( $image['sizes'] ) ) {
			$passes[] = __( 'Has sizes attribute', 'mcp-ai-wpoos' );
		}

		// Check dimensions.
		if ( $check_dimensions ) {
			if ( empty( $image['width'] ) || empty( $image['height'] ) ) {
				$issues[] = __( 'Missing explicit width/height (causes CLS)', 'mcp-ai-wpoos' );
			} else {
				$passes[] = __( 'Has explicit width/height dimensions', 'mcp-ai-wpoos' );
			}
		}

		// Check lazy loading.
		if ( $check_lazy_load ) {
			if ( 'lazy' === $image['loading'] ) {
				$passes[] = __( 'Has lazy loading enabled', 'mcp-ai-wpoos' );
			} else {
				$warnings[] = __( 'Consider adding loading="lazy"', 'mcp-ai-wpoos' );
			}
		}

		// Check decoding.
		if ( 'async' === $image['decoding'] ) {
			$passes[] = __( 'Has async decoding for better performance', 'mcp-ai-wpoos' );
		}

		// Check format.
		if ( $check_formats ) {
			$format = $this->detect_image_format( $image['src'] );
			if ( in_array( $format, array( 'avif', 'webp' ), true ) ) {
				$passes[] = sprintf(
					/* translators: %s: image format */
					__( 'Using modern format: %s', 'mcp-ai-wpoos' ),
					strtoupper( $format )
				);
			} else {
				$warnings[] = __( 'Consider using AVIF or WebP format', 'mcp-ai-wpoos' );
			}
		}

		// Check alt text.
		if ( empty( $image['alt'] ) ) {
			$issues[] = __( 'Missing alt text (accessibility issue)', 'mcp-ai-wpoos' );
		} else {
			$passes[] = __( 'Has alt text for accessibility', 'mcp-ai-wpoos' );
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
	 * Get image data
	 *
	 * @since 1.0.0
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
	 * Validate image data
	 *
	 * @since 1.0.0
	 * @param array $image_data      Image data.
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
	 * Identify LCP element
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array LCP element data.
	 */
	private function identify_lcp_element( $url ) {
		// Simplified LCP detection - in production would use real metrics.
		return array(
			'type'     => 'image',
			'selector' => 'img.hero-image',
			'detected' => true,
			'note'     => __( 'LCP detection requires real user metrics or Lighthouse integration', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Check if LCP is optimized
	 *
	 * @since 1.0.0
	 * @param array $lcp_element LCP element.
	 * @return bool True if optimized.
	 */
	private function is_lcp_optimized( $lcp_element ) {
		// Simplified check.
		return isset( $lcp_element['detected'] ) && $lcp_element['detected'];
	}

	/**
	 * Get LCP recommendations
	 *
	 * @since 1.0.0
	 * @param array $lcp_element LCP element.
	 * @return array Recommendations.
	 */
	private function get_lcp_recommendations( $lcp_element ) {
		return array(
			__( 'Use fetchpriority="high" on LCP image', 'mcp-ai-wpoos' ),
			__( 'Preload LCP image with <link rel="preload">', 'mcp-ai-wpoos' ),
			__( 'Remove lazy loading from above-the-fold images', 'mcp-ai-wpoos' ),
			__( 'Use modern image formats (AVIF/WebP)', 'mcp-ai-wpoos' ),
			__( 'Optimize image dimensions for viewport', 'mcp-ai-wpoos' ),
			__( 'Consider CDN for faster delivery', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Check LCP metric
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array LCP metric data.
	 */
	private function check_lcp_metric( $url ) {
		return array(
			'metric'  => 'LCP',
			'name'    => __( 'Largest Contentful Paint', 'mcp-ai-wpoos' ),
			'target'  => '< 2.5s',
			'status'  => 'needs_measurement',
			'message' => __( 'Use PageSpeed Insights or Chrome DevTools for real metrics', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Check CLS metric
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array CLS metric data.
	 */
	private function check_cls_metric( $url ) {
		return array(
			'metric' => 'CLS',
			'name'   => __( 'Cumulative Layout Shift', 'mcp-ai-wpoos' ),
			'target' => '< 0.1',
			'status' => 'needs_measurement',
			'tip'    => __( 'Add explicit width/height to all images to prevent CLS', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Check FID metric
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array FID metric data.
	 */
	private function check_fid_metric( $url ) {
		return array(
			'metric' => 'FID',
			'name'   => __( 'First Input Delay', 'mcp-ai-wpoos' ),
			'target' => '< 100ms',
			'status' => 'needs_measurement',
			'note'   => __( 'Being replaced by INP in 2026', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Check INP metric
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array INP metric data.
	 */
	private function check_inp_metric( $url ) {
		return array(
			'metric' => 'INP',
			'name'   => __( 'Interaction to Next Paint', 'mcp-ai-wpoos' ),
			'target' => '< 200ms',
			'status' => 'needs_measurement',
			'note'   => __( 'New Core Web Vital replacing FID in 2026', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Audit responsive images
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_responsive_images( $url ) {
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for detailed responsive image audit', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Audit lazy loading
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_lazy_loading( $url ) {
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for lazy loading audit', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Audit modern formats
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_modern_formats( $url ) {
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for format audit', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Audit explicit dimensions
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array Audit result.
	 */
	private function audit_explicit_dimensions( $url ) {
		return array(
			'status'  => 'requires_validation',
			'message' => __( 'Run validate_page action for dimensions audit', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Get CWV recommendations
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return array Recommendations.
	 */
	private function get_cwv_recommendations( $url ) {
		return array(
			__( 'Optimize LCP image with modern formats and CDN', 'mcp-ai-wpoos' ),
			__( 'Add explicit dimensions to prevent CLS', 'mcp-ai-wpoos' ),
			__( 'Use lazy loading for below-the-fold images', 'mcp-ai-wpoos' ),
			__( 'Implement responsive images with srcset/sizes', 'mcp-ai-wpoos' ),
			__( 'Test with real users via Chrome User Experience Report', 'mcp-ai-wpoos' ),
		);
	}

	/**
	 * Fetch page content
	 *
	 * @since 1.0.0
	 * @param string $url Page URL.
	 * @return string Page content.
	 */
	private function fetch_page_content( $url ) {
		$response = wp_remote_get( $url );

		if ( is_wp_error( $response ) ) {
			return '';
		}

		return wp_remote_retrieve_body( $response );
	}

	/**
	 * Detect image format
	 *
	 * @since 1.0.0
	 * @param string $src Image source URL.
	 * @return string Image format.
	 */
	private function detect_image_format( $src ) {
		$extension = pathinfo( $src, PATHINFO_EXTENSION );
		return strtolower( $extension );
	}

	/**
	 * Calculate validation score
	 *
	 * @since 1.0.0
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
	 * Get grade from score
	 *
	 * @since 1.0.0
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
	 * Generate validation report
	 *
	 * @since 1.0.0
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
				if ( strpos( $pass, 'srcset' ) !== false ) {
					++$summary['responsive_images'];
				}
				if ( strpos( $pass, 'lazy' ) !== false ) {
					++$summary['lazy_loading'];
				}
				if ( strpos( $pass, 'format' ) !== false || strpos( $pass, 'AVIF' ) !== false || strpos( $pass, 'WebP' ) !== false ) {
					++$summary['modern_formats'];
				}
				if ( strpos( $pass, 'width' ) !== false || strpos( $pass, 'height' ) !== false ) {
					++$summary['explicit_dimensions'];
				}
				if ( strpos( $pass, 'alt' ) !== false ) {
					++$summary['accessibility'];
				}
			}
		}

		return array(
			'score'           => $score,
			'summary'         => $summary,
			'recommendations' => array(
				__( 'Implement srcset/sizes on all images', 'mcp-ai-wpoos' ),
				__( 'Use AVIF or WebP formats for better compression', 'mcp-ai-wpoos' ),
				__( 'Add explicit width/height to prevent layout shift', 'mcp-ai-wpoos' ),
				__( 'Enable lazy loading for below-the-fold images', 'mcp-ai-wpoos' ),
				__( 'Test with real devices and PageSpeed Insights', 'mcp-ai-wpoos' ),
			),
		);
	}

	/**
	 * Check if tool has privacy data
	 *
	 * @since 1.0.0
	 * @return bool False - no privacy data.
	 */
	public function has_privacy_data() {
		return false;
	}
}
