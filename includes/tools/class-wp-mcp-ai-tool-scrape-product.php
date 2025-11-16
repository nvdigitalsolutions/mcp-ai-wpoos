<?php
/**
 * Tool that scrapes product information from a URL and downloads images.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Scrapes product information from URLs and downloads product images to WordPress media library.
 */
class WP_MCP_AI_Tool_Scrape_Product implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface {
	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'scrape_product';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Scrape Product', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Scrapes product information (title, subtitle, description, images) from a product URL or saved HTML file and downloads highest resolution images to WordPress media library.', 'wp-mcp-ai' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'url'                  => array(
					'type'        => 'string',
					'description' => __( 'The product page URL to scrape. Either url or html_file is required.', 'wp-mcp-ai' ),
					'format'      => 'uri',
				),
				'html_file'            => array(
					'type'        => 'string',
					'description' => __( 'Path to a saved HTML file to parse instead of fetching from URL. Either url or html_file is required.', 'wp-mcp-ai' ),
				),
				'title_selector'       => array(
					'type'        => 'string',
					'description' => __( 'CSS selector for product title (default: .swa-product-information__title.swa-label-sans--default-strong).', 'wp-mcp-ai' ),
					'default'     => '.swa-product-information__title.swa-label-sans--default-strong',
				),
				'subtitle_selector'    => array(
					'type'        => 'string',
					'description' => __( 'CSS selector for product subtitle (default: .swa-product-information__subtitle.swa-label-sans--default).', 'wp-mcp-ai' ),
					'default'     => '.swa-product-information__subtitle.swa-label-sans--default',
				),
				'description_selector' => array(
					'type'        => 'string',
					'description' => __( 'CSS selector for product description (default: .swa-cms-copy__body.swa-content-accordion__copy-body.swa-content-accordion__panel-inner.js-swa-content-accordion-panel-inner p).', 'wp-mcp-ai' ),
					'default'     => '.swa-cms-copy__body.swa-content-accordion__copy-body.swa-content-accordion__panel-inner.js-swa-content-accordion-panel-inner p',
				),
				'images_selector'      => array(
					'type'        => 'string',
					'description' => __( 'CSS selector or pattern for product images containers. Use "splide-slides" to match all div[id^="splide"] slide containers (default), or provide a custom selector.', 'wp-mcp-ai' ),
					'default'     => 'splide-slides',
				),
				'download_images'      => array(
					'type'        => 'boolean',
					'description' => __( 'Whether to download images to WordPress media library (default: true).', 'wp-mcp-ai' ),
					'default'     => true,
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * Execute the tool.
	 *
	 * @param array $arguments Tool arguments.
	 * @param array $context   Execution context including user_id.
	 * @return array|WP_Error Tool results or error.
	 */
	public function execute( array $arguments = array(), array $context = array() ) {
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : get_current_user_id();

		if ( ! $user_id || ! user_can( $user_id, 'upload_files' ) ) {
			return new WP_Error( 'wp_mcp_ai_forbidden', __( 'You do not have permission to scrape products and upload files.', 'wp-mcp-ai' ) );
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error( 'wp_mcp_ai_wrong_site', __( 'You do not have access to this site.', 'wp-mcp-ai' ) );
		}

		$url       = isset( $arguments['url'] ) ? esc_url_raw( trim( $arguments['url'] ) ) : '';
		$html_file = isset( $arguments['html_file'] ) ? sanitize_text_field( trim( $arguments['html_file'] ) ) : '';

		// Validate that at least one input method is provided.
		if ( empty( $url ) && empty( $html_file ) ) {
			return new WP_Error( 'wp_mcp_ai_missing_input', __( 'Either a product URL or HTML file path is required.', 'wp-mcp-ai' ) );
		}

		// Get selectors with defaults.
		$title_selector       = isset( $arguments['title_selector'] ) ? sanitize_text_field( $arguments['title_selector'] ) : '.swa-product-information__title.swa-label-sans--default-strong';
		$subtitle_selector    = isset( $arguments['subtitle_selector'] ) ? sanitize_text_field( $arguments['subtitle_selector'] ) : '.swa-product-information__subtitle.swa-label-sans--default';
		$description_selector = isset( $arguments['description_selector'] ) ? sanitize_text_field( $arguments['description_selector'] ) : '.swa-cms-copy__body.swa-content-accordion__copy-body.swa-content-accordion__panel-inner.js-swa-content-accordion-panel-inner p';
		$images_selector      = isset( $arguments['images_selector'] ) ? sanitize_text_field( $arguments['images_selector'] ) : 'splide-slides';
		$download_images      = isset( $arguments['download_images'] ) ? (bool) $arguments['download_images'] : true;

		// Get HTML content from either URL or file.
		if ( ! empty( $html_file ) ) {
			$html = $this->read_html_file( $html_file );
		} else {
			// Validate URL scheme.
			$parts = wp_parse_url( $url );
			if ( false === $parts || empty( $parts['scheme'] ) || ! in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_url', __( 'Invalid URL provided. Only HTTP and HTTPS URLs are supported.', 'wp-mcp-ai' ) );
			}
			$html = $this->fetch_url_content( $url );
		}

		if ( is_wp_error( $html ) ) {
			return $html;
		}

		// Parse the HTML.
		$product_data = $this->parse_product_data(
			$html,
			$title_selector,
			$subtitle_selector,
			$description_selector,
			$images_selector
		);

		if ( is_wp_error( $product_data ) ) {
			return $product_data;
		}

		// Download images if requested.
		if ( $download_images && ! empty( $product_data['image_urls'] ) ) {
			$media_result = $this->download_images_to_media( $product_data['image_urls'], $product_data['title'] );

			if ( is_wp_error( $media_result ) ) {
				$product_data['images_download_error'] = $media_result->get_error_message();
				$product_data['media_ids']             = array();
			} else {
				$product_data['media_ids'] = $media_result;
			}
		}

		return $product_data;
	}

	/**
	 * Fetch HTML content from a URL.
	 *
	 * @param string $url URL to fetch.
	 * @return string|WP_Error HTML content or error.
	 */
	protected function fetch_url_content( $url ) {
		$response = wp_remote_get(
			$url,
			array(
				'timeout'     => 30,
				'redirection' => 5,
				'user-agent'  => 'WP-MCP-AI-Product-Scraper/1.0 (+' . home_url( '/' ) . ')',
				'headers'     => array(
					'Accept' => 'text/html,application/xhtml+xml,application/xml;q=0.9,*/*;q=0.8',
				),
			)
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_fetch_failed',
				__( 'Failed to fetch the product page.', 'wp-mcp-ai' ),
				array( 'error' => $response->get_error_message() )
			);
		}

		$status_code = wp_remote_retrieve_response_code( $response );

		if ( 200 !== $status_code ) {
			return new WP_Error(
				'wp_mcp_ai_http_error',
				sprintf(
					/* translators: %d: HTTP status code */
					__( 'The product page returned an unexpected HTTP status: %d.', 'wp-mcp-ai' ),
					$status_code
				)
			);
		}

		$html = wp_remote_retrieve_body( $response );

		if ( empty( $html ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_response', __( 'The product page returned an empty response.', 'wp-mcp-ai' ) );
		}

		return $html;
	}

	/**
	 * Read HTML content from a local file.
	 *
	 * @param string $file_path Path to HTML file.
	 * @return string|WP_Error HTML content or error.
	 */
	protected function read_html_file( $file_path ) {
		// Security: Validate file path to prevent directory traversal.
		$file_path = realpath( $file_path );

		if ( false === $file_path ) {
			return new WP_Error( 'wp_mcp_ai_file_not_found', __( 'The specified HTML file does not exist.', 'wp-mcp-ai' ) );
		}

		// Security: Restrict file access to safe directories.
		$allowed_in_safe_directory = $this->is_path_in_safe_directory( $file_path );
		if ( is_wp_error( $allowed_in_safe_directory ) ) {
			return $allowed_in_safe_directory;
		}

		// Security: Ensure file is readable.
		if ( ! is_readable( $file_path ) ) {
			return new WP_Error( 'wp_mcp_ai_file_not_readable', __( 'The specified HTML file is not readable.', 'wp-mcp-ai' ) );
		}

		// Security: Validate file extension.
		$allowed_extensions = array( 'html', 'htm' );
		$file_extension     = strtolower( pathinfo( $file_path, PATHINFO_EXTENSION ) );

		if ( ! in_array( $file_extension, $allowed_extensions, true ) ) {
			return new WP_Error( 'wp_mcp_ai_invalid_file_type', __( 'Only HTML files (.html, .htm) are allowed.', 'wp-mcp-ai' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for local file reading.
		$html = file_get_contents( $file_path );

		if ( false === $html ) {
			return new WP_Error( 'wp_mcp_ai_file_read_failed', __( 'Failed to read the HTML file.', 'wp-mcp-ai' ) );
		}

		if ( empty( $html ) ) {
			return new WP_Error( 'wp_mcp_ai_empty_file', __( 'The HTML file is empty.', 'wp-mcp-ai' ) );
		}

		return $html;
	}

	/**
	 * Check if a file path is within safe directories.
	 *
	 * @param string $file_path Resolved absolute file path.
	 * @return true|WP_Error True if path is safe, WP_Error otherwise.
	 */
	protected function is_path_in_safe_directory( $file_path ) {
		// Normalize the file path for comparison.
		$normalized_path = wp_normalize_path( $file_path );

		// Define safe base directories.
		$safe_directories = array();

		// Allow WordPress uploads directory.
		$upload_dir = wp_upload_dir();
		if ( ! empty( $upload_dir['basedir'] ) ) {
			$safe_directories[] = wp_normalize_path( $upload_dir['basedir'] );
		}

		// Allow WordPress content directory.
		if ( defined( 'WP_CONTENT_DIR' ) ) {
			$safe_directories[] = wp_normalize_path( WP_CONTENT_DIR );
		}

		// Allow a filter for custom safe directories.
		$safe_directories = apply_filters( 'wp_mcp_ai_scrape_product_safe_directories', $safe_directories );

		// Check if the file path starts with any safe directory.
		foreach ( $safe_directories as $safe_dir ) {
			if ( 0 === strpos( $normalized_path, $safe_dir ) ) {
				return true;
			}
		}

		// Path is not in any safe directory.
		return new WP_Error(
			'wp_mcp_ai_unsafe_file_path',
			__( 'File path is not within allowed directories. Files must be in the WordPress uploads or content directory.', 'wp-mcp-ai' )
		);
	}

	/**
	 * Parse product data from HTML.
	 *
	 * @param string $html                HTML content.
	 * @param string $title_selector      CSS selector for title.
	 * @param string $subtitle_selector   CSS selector for subtitle.
	 * @param string $description_selector CSS selector for description.
	 * @param string $images_selector     CSS selector for images container.
	 * @return array|WP_Error Parsed product data or error.
	 */
	protected function parse_product_data( $html, $title_selector, $subtitle_selector, $description_selector, $images_selector ) {
		if ( ! class_exists( 'DOMDocument' ) ) {
			return new WP_Error( 'wp_mcp_ai_no_dom', __( 'DOMDocument class is not available for HTML parsing.', 'wp-mcp-ai' ) );
		}

		$dom = new DOMDocument();
		libxml_use_internal_errors( true );
		$loaded = $dom->loadHTML( mb_convert_encoding( $html, 'HTML-ENTITIES', 'UTF-8' ) );
		libxml_clear_errors();

		if ( ! $loaded ) {
			return new WP_Error( 'wp_mcp_ai_parse_failed', __( 'Failed to parse the product page HTML.', 'wp-mcp-ai' ) );
		}

		$xpath = new DOMXPath( $dom );

		// Extract product title.
		$title = $this->extract_text_by_selector( $xpath, $title_selector );

		// Extract product subtitle.
		$subtitle = $this->extract_text_by_selector( $xpath, $subtitle_selector );

		// Extract product description.
		$description = $this->extract_description_by_selector( $xpath, $description_selector );

		// Extract image URLs.
		$image_urls = $this->extract_image_urls( $xpath, $images_selector );

		return array(
			'title'       => $title,
			'subtitle'    => $subtitle,
			'description' => $description,
			'image_urls'  => $image_urls,
		);
	}

	/**
	 * Extract text content by CSS selector.
	 *
	 * @param DOMXPath $xpath    XPath object.
	 * @param string   $selector CSS selector.
	 * @return string Extracted text or empty string.
	 */
	protected function extract_text_by_selector( $xpath, $selector ) {
		$xpath_query = $this->css_to_xpath( $selector );
		$nodes       = $xpath->query( $xpath_query );

		if ( $nodes && $nodes->length > 0 ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			return trim( $nodes->item( 0 )->textContent );
		}

		return '';
	}

	/**
	 * Extract description from multiple paragraph elements.
	 *
	 * @param DOMXPath $xpath    XPath object.
	 * @param string   $selector CSS selector.
	 * @return string Extracted description or empty string.
	 */
	protected function extract_description_by_selector( $xpath, $selector ) {
		$xpath_query = $this->css_to_xpath( $selector );
		$nodes       = $xpath->query( $xpath_query );

		if ( ! $nodes || 0 === $nodes->length ) {
			return '';
		}

		$paragraphs = array();
		foreach ( $nodes as $node ) {
			// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- DOM property.
			$text = trim( $node->textContent );
			if ( ! empty( $text ) ) {
				$paragraphs[] = $text;
			}
		}

		return implode( "\n\n", $paragraphs );
	}

	/**
	 * Extract image URLs from a container.
	 *
	 * @param DOMXPath $xpath    XPath object.
	 * @param string   $selector CSS selector or pattern for images container.
	 * @return array Array of image URLs (highest resolution preferred).
	 */
	protected function extract_image_urls( $xpath, $selector ) {
		// Handle special pattern for all splide slides.
		if ( 'splide-slides' === $selector ) {
			// Find all div elements with id starting with "splide" and containing "-slide".
			$xpath_query = '//div[starts-with(@id, "splide") and contains(@id, "-slide")]';
		} else {
			$xpath_query = $this->css_to_xpath( $selector );
		}

		$containers = $xpath->query( $xpath_query );

		if ( ! $containers || 0 === $containers->length ) {
			return array();
		}

		$image_urls = array();

		// Find all img tags within the containers.
		foreach ( $containers as $container ) {
			$images = $container->getElementsByTagName( 'img' );

			foreach ( $images as $img ) {
				$candidate_urls = array();

				// Collect all possible image URLs from various attributes.
				$src = $img->getAttribute( 'src' );
				if ( ! empty( $src ) ) {
					$candidate_urls[] = $src;
				}

				// Check data-src for lazy-loaded images.
				$data_src = $img->getAttribute( 'data-src' );
				if ( ! empty( $data_src ) ) {
					$candidate_urls[] = $data_src;
				}

				// Check data-splide-lazy for Splide.js lazy-loaded images.
				$data_splide = $img->getAttribute( 'data-splide-lazy' );
				if ( ! empty( $data_splide ) ) {
					$candidate_urls[] = $data_splide;
				}

				// Check srcset for responsive images.
				$srcset = $img->getAttribute( 'srcset' );
				if ( ! empty( $srcset ) ) {
					// Parse all URLs from srcset.
					$srcset_parts = explode( ',', $srcset );
					foreach ( $srcset_parts as $srcset_entry ) {
						$entry_parts = preg_split( '/\s+/', trim( $srcset_entry ) );
						if ( ! empty( $entry_parts[0] ) ) {
							$candidate_urls[] = $entry_parts[0];
						}
					}
				}

				// Select the highest resolution URL from candidates.
				$selected_url = $this->select_highest_resolution_url( $candidate_urls );

				if ( ! empty( $selected_url ) ) {
					$selected_url = esc_url_raw( $selected_url );
					if ( ! empty( $selected_url ) && ! in_array( $selected_url, $image_urls, true ) ) {
						$image_urls[] = $selected_url;
					}
				}
			}
		}

		return $image_urls;
	}

	/**
	 * Select the highest resolution URL from a list of candidate URLs.
	 *
	 * Prioritizes URLs containing resolution patterns like "1000x1000".
	 *
	 * @param array $urls Array of candidate URLs.
	 * @return string Best URL or empty string.
	 */
	protected function select_highest_resolution_url( $urls ) {
		if ( empty( $urls ) ) {
			return '';
		}

		// Remove empty URLs and normalize.
		$urls = array_filter( array_map( 'trim', $urls ) );

		if ( empty( $urls ) ) {
			return '';
		}

		// If only one URL, return it.
		if ( 1 === count( $urls ) ) {
			return reset( $urls );
		}

		// Score each URL based on resolution indicators.
		$scored_urls = array();

		foreach ( $urls as $url ) {
			$score = 0;

			// Extract resolution from URL patterns like "1000x1000", "800x800", etc.
			if ( preg_match( '/(\d+)x(\d+)/i', $url, $matches ) ) {
				$width  = intval( $matches[1] );
				$height = intval( $matches[2] );
				// Score based on total pixel count.
				$score = $width * $height;

				// Bonus for square images (width == height).
				if ( $width === $height ) {
					$score += 10000;
				}
			}

			// Prefer URLs with "1000x1000" explicitly.
			if ( stripos( $url, '1000x1000' ) !== false ) {
				$score += 1000000; // High priority.
			}

			// Prefer larger dimension indicators in general.
			if ( stripos( $url, 'large' ) !== false || stripos( $url, 'xl' ) !== false ) {
				$score += 50000;
			}

			// Penalize thumbnails.
			if ( stripos( $url, 'thumb' ) !== false || stripos( $url, 'small' ) !== false ) {
				$score -= 100000;
			}

			$scored_urls[ $url ] = $score;
		}

		// Sort by score (highest first).
		arsort( $scored_urls );

		// Return the URL with the highest score.
		return key( $scored_urls );
	}

	/**
	 * Convert a simple CSS selector to XPath.
	 *
	 * This is a basic converter supporting common selectors used for product scraping.
	 *
	 * @param string $selector CSS selector.
	 * @return string XPath query.
	 */
	protected function css_to_xpath( $selector ) {
		// Remove extra spaces.
		$selector = trim( $selector );

		// Replace descendant combinator (space) with XPath descendant axis.
		$selector = preg_replace( '/\s+/', ' ', $selector );

		// Handle ID selector (#id).
		$selector = preg_replace( '/#([a-zA-Z0-9_-]+)/', '*[@id="$1"]', $selector );

		// Handle class selector (.class).
		// Support multiple classes by converting to contains.
		$selector = preg_replace_callback(
			'/\.([a-zA-Z0-9_-]+)/',
			function ( $matches ) {
				return '*[contains(concat(" ", normalize-space(@class), " "), " ' . $matches[1] . ' ")]';
			},
			$selector
		);

		// Convert element.class or element#id patterns.
		$selector = preg_replace( '/([a-zA-Z][a-zA-Z0-9]*)\*/', '$1', $selector );

		// Convert space separator to // (descendant).
		$selector = str_replace( ' ', '//', $selector );

		// Prepend // if not already present.
		if ( 0 !== strpos( $selector, '//' ) ) {
			$selector = '//' . $selector;
		}

		return $selector;
	}

	/**
	 * Download images to WordPress media library.
	 *
	 * @param array  $image_urls Array of image URLs.
	 * @param string $title      Product title for alt text.
	 * @return array|WP_Error Array of attachment IDs or error.
	 */
	protected function download_images_to_media( $image_urls, $title = '' ) {
		if ( empty( $image_urls ) ) {
			return array();
		}

		if ( ! function_exists( 'media_sideload_image' ) ) {
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$attachment_ids = array();
		$errors         = array();

		foreach ( $image_urls as $index => $url ) {
			// Generate a descriptive filename.
			$filename = ! empty( $title ) ? sanitize_file_name( $title ) . '-' . ( $index + 1 ) : 'product-image-' . ( $index + 1 );

			// Try to download the image.
			$attachment_id = media_sideload_image( $url, 0, $title, 'id' );

			if ( is_wp_error( $attachment_id ) ) {
				$errors[] = sprintf(
					/* translators: 1: Image URL, 2: Error message */
					__( 'Failed to download image %1$s: %2$s', 'wp-mcp-ai' ),
					$url,
					$attachment_id->get_error_message()
				);
				continue;
			}

			$attachment_ids[] = $attachment_id;
		}

		if ( empty( $attachment_ids ) && ! empty( $errors ) ) {
			return new WP_Error(
				'wp_mcp_ai_download_failed',
				__( 'Failed to download any images.', 'wp-mcp-ai' ),
				array( 'errors' => $errors )
			);
		}

		return $attachment_ids;
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',           // Only reads remote data.
			'write',               // Creates media attachments.
			'state-changing',      // Modifies database (media library).
			'external-api',        // Makes external HTTP requests.
			'requires-capability', // Requires upload_files capability.
			'network-dependent',   // Requires internet connectivity.
		);
	}
}
