<?php
/**
 * Lookup Product Price Tool - Pro add-on tool for multi-source price discovery.
 *
 * Provides industry-standard product price lookup from images, documents, or URLs
 * similar to Google Lens, Amazon Visual Search, and browser price comparison extensions.
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Tool for product price lookup and comparison.
 *
 * Provides functionality to:
 * - Identify products from images using Google Vision
 * - Extract product data from URLs via Crawl4AI
 * - Process invoices/quotes from documents
 * - Compare prices across multiple retailers
 * - Normalize pricing data (currency, availability)
 *
 * @since 1.0.0
 */
class WP_MCP_AI_Pro_Tool_Lookup_Product_Price implements WP_MCP_AI_Core_Tool_Interface, WP_MCP_AI_Core_Tool_Capability_Flags_Interface, WP_MCP_AI_Core_Tool_Rules_Interface {

	/**
	 * Check if this tool is available.
	 *
	 * @since 1.0.0
	 *
	 * @return bool True if dependencies are met.
	 */
	public static function is_available() {
		// Requires Crawl4AI for web scraping.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job' ) ) {
			return false;
		}

		// Check if Crawl4AI is configured.
		return WP_MCP_AI_Tool_Run_Crawl4AI_Job::is_available();
	}

	/**
	 * Get the reason why this tool is unavailable.
	 *
	 * @since 1.0.0
	 *
	 * @return string Reason message.
	 */
	public static function get_unavailable_reason() {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job' ) ) {
			return __( 'Product price lookup tool requires the Crawl4AI integration.', 'wp-mcp-ai-pro' );
		}

		return WP_MCP_AI_Tool_Run_Crawl4AI_Job::get_unavailable_reason();
	}

	/**
	 * Get the tool slug.
	 *
	 * @return string
	 */
	public function get_slug() {
		return 'lookup_product_price';
	}

	/**
	 * Get the tool name.
	 *
	 * @return string
	 */
	public function get_name() {
		return __( 'Lookup Product Price', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the tool description.
	 *
	 * @return string
	 */
	public function get_description() {
		return __( 'Find current online prices for products from images, documents, or URLs. Works like Google Lens Shopping or browser price comparison extensions. Supports image recognition, document parsing (invoices/quotes), single URL lookup, or batch URL comparison across multiple retailers.', 'wp-mcp-ai-pro' );
	}

	/**
	 * Get the parameters schema.
	 *
	 * @return array
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'image_attachment_id'    => array(
					'type'        => 'integer',
					'description' => __( 'WordPress Media Library attachment ID of a product image. The tool will use Google Vision to identify the product and search for prices.', 'wp-mcp-ai-pro' ),
				),
				'document_attachment_id' => array(
					'type'        => 'integer',
					'description' => __( 'WordPress Media Library attachment ID of a document (invoice, quote, spec sheet PDF). The tool will extract line items and look up prices for each.', 'wp-mcp-ai-pro' ),
				),
				'urls'                   => array(
					'type'        => 'array',
					'items'       => array(
						'type'   => 'string',
						'format' => 'uri',
					),
					'description' => __( 'Array of product page URLs to compare prices. The tool will extract product information and search for better prices elsewhere.', 'wp-mcp-ai-pro' ),
					'maxItems'    => 20,
				),
				'url'                    => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'Convenience parameter for a single product URL when not using the urls array.', 'wp-mcp-ai-pro' ),
				),
				'max_results_per_item'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 10,
					'default'     => 5,
					'description' => __( 'Maximum number of price offers to return per product.', 'wp-mcp-ai-pro' ),
				),
				'preferred_retailers'    => array(
					'type'        => 'array',
					'items'       => array(
						'type' => 'string',
					),
					'description' => __( 'Optional list of retailer domains to prioritize (e.g., ["amazon.com", "walmart.com"]).', 'wp-mcp-ai-pro' ),
				),
				'currency'               => array(
					'type'        => 'string',
					'description' => __( 'Preferred currency code for results (e.g., "USD", "EUR"). If provided, prices in other currencies may be converted.', 'wp-mcp-ai-pro' ),
					'pattern'     => '^[A-Z]{3}$',
				),
				'locale'                 => array(
					'type'        => 'string',
					'description' => __( 'Locale for search results (e.g., "en-US", "en-GB"). Affects which region\'s retailers are searched.', 'wp-mcp-ai-pro' ),
					'pattern'     => '^[a-z]{2}-[A-Z]{2}$',
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
		$user_id = isset( $context['user_id'] ) ? absint( $context['user_id'] ) : 0;

		// Authentication check.
		if ( ! $user_id ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You must be authenticated to use product price lookup.', 'wp-mcp-ai-pro' ),
				array( 'status' => 403 )
			);
		}

		// Capability check.
		if ( ! user_can( $user_id, 'read' ) ) {
			return new WP_Error(
				'wp_mcp_ai_forbidden',
				__( 'You do not have permission to look up product prices.', 'wp-mcp-ai-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( is_multisite() && ! is_user_member_of_blog( $user_id, get_current_blog_id() ) ) {
			return new WP_Error(
				'wp_mcp_ai_wrong_site',
				__( 'You do not have access to this site.', 'wp-mcp-ai-pro' ),
				array( 'status' => 403 )
			);
		}

		// Validate that at least one input is provided.
		$image_id    = isset( $arguments['image_attachment_id'] ) ? absint( $arguments['image_attachment_id'] ) : 0;
		$document_id = isset( $arguments['document_attachment_id'] ) ? absint( $arguments['document_attachment_id'] ) : 0;
		$urls        = $this->extract_urls( $arguments );

		if ( ! $image_id && ! $document_id && empty( $urls ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_input',
				__( 'At least one input source is required: image_attachment_id, document_attachment_id, or urls.', 'wp-mcp-ai-pro' ),
				array( 'status' => 400 )
			);
		}

		// Get optional parameters.
		$max_results         = isset( $arguments['max_results_per_item'] ) ? absint( $arguments['max_results_per_item'] ) : 5;
		$max_results         = max( 1, min( 10, $max_results ) );
		$preferred_retailers = isset( $arguments['preferred_retailers'] ) && is_array( $arguments['preferred_retailers'] ) ? array_map( 'sanitize_text_field', $arguments['preferred_retailers'] ) : array();
		$currency            = isset( $arguments['currency'] ) ? sanitize_text_field( $arguments['currency'] ) : '';
		$locale              = isset( $arguments['locale'] ) ? sanitize_text_field( $arguments['locale'] ) : '';

		$items = array();

		// Process image input.
		if ( $image_id ) {
			$image_result = $this->process_image_input( $image_id, $max_results, $preferred_retailers, $currency, $locale, $context );
			if ( is_wp_error( $image_result ) ) {
				return $image_result;
			}
			$items = array_merge( $items, $image_result );
		}

		// Process document input.
		if ( $document_id ) {
			$document_result = $this->process_document_input( $document_id, $max_results, $preferred_retailers, $currency, $locale, $context );
			if ( is_wp_error( $document_result ) ) {
				return $document_result;
			}
			$items = array_merge( $items, $document_result );
		}

		// Process URL input(s).
		if ( ! empty( $urls ) ) {
			$urls_result = $this->process_urls_input( $urls, $max_results, $preferred_retailers, $currency, $locale, $context );
			if ( is_wp_error( $urls_result ) ) {
				return $urls_result;
			}
			$items = array_merge( $items, $urls_result );
		}

		return array(
			'items'    => $items,
			'metadata' => array(
				'total_items'          => count( $items ),
				'max_results_per_item' => $max_results,
				'currency'             => $currency,
				'locale'               => $locale,
				'timestamp'            => current_time( 'mysql', true ),
			),
		);
	}

	/**
	 * Extract and normalize URLs from arguments.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array Array of sanitized URLs.
	 */
	protected function extract_urls( array $arguments ) {
		$urls = array();

		// Check urls array.
		if ( isset( $arguments['urls'] ) && is_array( $arguments['urls'] ) ) {
			foreach ( $arguments['urls'] as $url ) {
				$sanitized = esc_url_raw( trim( $url ) );
				if ( ! empty( $sanitized ) && $this->is_valid_url( $sanitized ) ) {
					$urls[] = $sanitized;
				}
			}
		}

		// Check single url parameter.
		if ( empty( $urls ) && isset( $arguments['url'] ) && is_string( $arguments['url'] ) ) {
			$sanitized = esc_url_raw( trim( $arguments['url'] ) );
			if ( ! empty( $sanitized ) && $this->is_valid_url( $sanitized ) ) {
				$urls[] = $sanitized;
			}
		}

		// Limit to 20 URLs.
		return array_slice( array_unique( $urls ), 0, 20 );
	}

	/**
	 * Validate URL format.
	 *
	 * @param string $url URL to validate.
	 * @return bool True if valid.
	 */
	protected function is_valid_url( $url ) {
		$parts = wp_parse_url( $url );
		if ( false === $parts || empty( $parts['scheme'] ) || empty( $parts['host'] ) ) {
			return false;
		}
		return in_array( strtolower( $parts['scheme'] ), array( 'http', 'https' ), true );
	}

	/**
	 * Process image input to find product prices.
	 *
	 * @param int    $image_id            Attachment ID.
	 * @param int    $max_results         Maximum results per item.
	 * @param array  $preferred_retailers Preferred retailer domains.
	 * @param string $currency            Preferred currency.
	 * @param string $locale              Locale.
	 * @param array  $context             Execution context.
	 * @return array|WP_Error Array of items or error.
	 */
	protected function process_image_input( $image_id, $max_results, $preferred_retailers, $currency, $locale, $context ) {
		// Validate attachment exists.
		$file_path = get_attached_file( $image_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_image',
				__( 'Invalid image attachment ID - file not found.', 'wp-mcp-ai-pro' ),
				array( 'status' => 400 )
			);
		}

		// Get image URL for Vision API.
		$image_url = wp_get_attachment_url( $image_id );
		if ( ! $image_url ) {
			return new WP_Error(
				'wp_mcp_ai_no_image_url',
				__( 'Unable to get URL for image attachment.', 'wp-mcp-ai-pro' ),
				array( 'status' => 500 )
			);
		}

		// Use Vision Product Search to identify the product.
		$vision_result = $this->identify_product_from_image( $image_url, $context );
		if ( is_wp_error( $vision_result ) ) {
			return $vision_result;
		}

		// Build search query from vision results.
		$search_query = $this->build_search_query_from_vision( $vision_result );

		// Find prices using the search query.
		$offers = $this->discover_prices( $search_query, $max_results, $preferred_retailers, $currency, $locale, $context );

		$item = array(
			'query_source'       => 'image',
			'source_ref'         => 'attachment:' . $image_id,
			'identified_product' => $vision_result['product'],
			'offers'             => $offers,
		);

		return array( $item );
	}

	/**
	 * Process document input to extract line items and find prices.
	 *
	 * @param int    $document_id         Attachment ID.
	 * @param int    $max_results         Maximum results per item.
	 * @param array  $preferred_retailers Preferred retailer domains.
	 * @param string $currency            Preferred currency.
	 * @param string $locale              Locale.
	 * @param array  $context             Execution context.
	 * @return array|WP_Error Array of items or error.
	 */
	protected function process_document_input( $document_id, $max_results, $preferred_retailers, $currency, $locale, $context ) {
		// Validate attachment exists.
		$file_path = get_attached_file( $document_id );
		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_document',
				__( 'Invalid document attachment ID - file not found.', 'wp-mcp-ai-pro' ),
				array( 'status' => 400 )
			);
		}

		// Extract text from document using Crawl4AI's file processing.
		$text_content = $this->extract_text_from_document( $file_path, $context );
		if ( is_wp_error( $text_content ) ) {
			return $text_content;
		}

		// Use LLM to extract line items from the document.
		$line_items = $this->extract_line_items_from_text( $text_content, $context );
		if ( is_wp_error( $line_items ) ) {
			return $line_items;
		}

		// For each line item, discover prices.
		$items = array();
		foreach ( $line_items as $index => $line_item ) {
			$search_query = $this->build_search_query_from_line_item( $line_item );
			$offers       = $this->discover_prices( $search_query, $max_results, $preferred_retailers, $currency, $locale, $context );

			$items[] = array(
				'query_source'       => 'document',
				'source_ref'         => 'attachment:' . $document_id . ' (line ' . ( $index + 1 ) . ')',
				'identified_product' => $line_item,
				'offers'             => $offers,
			);
		}

		return $items;
	}

	/**
	 * Process URL input(s) to extract product data and find prices.
	 *
	 * @param array  $urls                Product URLs.
	 * @param int    $max_results         Maximum results per item.
	 * @param array  $preferred_retailers Preferred retailer domains.
	 * @param string $currency            Preferred currency.
	 * @param string $locale              Locale.
	 * @param array  $context             Execution context.
	 * @return array|WP_Error Array of items or error.
	 */
	protected function process_urls_input( $urls, $max_results, $preferred_retailers, $currency, $locale, $context ) {
		$items = array();

		foreach ( $urls as $url ) {
			// Use Crawl4AI to extract product information from the URL.
			$product_data = $this->extract_product_from_url( $url, $context );
			if ( is_wp_error( $product_data ) ) {
				// Log error but continue with other URLs.
				$items[] = array(
					'query_source' => 'url',
					'source_ref'   => $url,
					'error'        => $product_data->get_error_message(),
					'offers'       => array(),
				);
				continue;
			}

			// Build search query from extracted product data.
			$search_query = $this->build_search_query_from_product_data( $product_data );

			// Discover prices from other retailers.
			$offers = $this->discover_prices( $search_query, $max_results, $preferred_retailers, $currency, $locale, $context );

			// Include the original URL's offer if price was extracted.
			if ( isset( $product_data['price'] ) && ! empty( $product_data['price'] ) ) {
				$original_offer = array(
					'retailer'     => $this->extract_domain_name( $url ),
					'url'          => $url,
					'price'        => $product_data['price'],
					'currency'     => isset( $product_data['currency'] ) ? $product_data['currency'] : $currency,
					'availability' => isset( $product_data['availability'] ) ? $product_data['availability'] : 'unknown',
					'last_checked' => current_time( 'mysql', true ),
					'source'       => 'original',
				);
				array_unshift( $offers, $original_offer );
			}

			$items[] = array(
				'query_source'       => 'url',
				'source_ref'         => $url,
				'identified_product' => $product_data,
				'offers'             => $offers,
			);
		}

		return $items;
	}

	/**
	 * Identify product from image using Vision API.
	 *
	 * @param string $image_url Image URL.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Product identification result or error.
	 */
	protected function identify_product_from_image( $image_url, $context ) {
		// Check if Vision Product Search tool exists.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Vision_Product_Search' ) ) {
			// Fallback: use Vision Object Localization to detect objects.
			return $this->identify_product_from_image_fallback( $image_url, $context );
		}

		$vision_tool = new WP_MCP_AI_Tool_Vision_Product_Search();
		$vision_args = array(
			'image_url'   => $image_url,
			'max_results' => 5,
		);

		$vision_result = $vision_tool->execute( $vision_args, $context );
		if ( is_wp_error( $vision_result ) ) {
			// Try fallback method.
			return $this->identify_product_from_image_fallback( $image_url, $context );
		}

		// Parse Vision API response to extract product information.
		return $this->parse_vision_response( $vision_result );
	}

	/**
	 * Fallback method for image product identification using Vision Object Localization.
	 *
	 * @param string $image_url Image URL.
	 * @param array  $context   Execution context.
	 * @return array|WP_Error Product identification result or error.
	 */
	protected function identify_product_from_image_fallback( $image_url, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Vision_Object_Localization' ) ) {
			return new WP_Error(
				'wp_mcp_ai_no_vision_tool',
				__( 'No Vision API tool available for image product identification.', 'wp-mcp-ai-pro' )
			);
		}

		$vision_tool = new WP_MCP_AI_Tool_Vision_Object_Localization();
		$vision_args = array(
			'image_url' => $image_url,
		);

		$vision_result = $vision_tool->execute( $vision_args, $context );
		if ( is_wp_error( $vision_result ) ) {
			return $vision_result;
		}

		// Extract object labels and use as product description.
		$labels = array();
		if ( isset( $vision_result['responses'][0]['localizedObjectAnnotations'] ) ) {
			foreach ( $vision_result['responses'][0]['localizedObjectAnnotations'] as $annotation ) {
				if ( isset( $annotation['name'] ) ) {
					$labels[] = $annotation['name'];
				}
			}
		}

		$title = ! empty( $labels ) ? implode( ' ', $labels ) : __( 'Unknown product', 'wp-mcp-ai-pro' );

		return array(
			'product' => array(
				'title'       => $title,
				'brand'       => '',
				'model'       => '',
				'identifiers' => array(),
			),
		);
	}

	/**
	 * Parse Vision API response to extract product information.
	 *
	 * @param array $vision_result Vision API result.
	 * @return array Product information.
	 */
	protected function parse_vision_response( $vision_result ) {
		// Extract product data from Vision API response structure.
		// The actual structure depends on Google Vision Product Search API response.
		$title       = '';
		$brand       = '';
		$model       = '';
		$identifiers = array();

		// Check for product search results.
		if ( isset( $vision_result['responses'][0]['productSearchResults']['results'] ) ) {
			$results = $vision_result['responses'][0]['productSearchResults']['results'];
			if ( ! empty( $results ) ) {
				$first_result = $results[0];
				if ( isset( $first_result['product']['displayName'] ) ) {
					$title = $first_result['product']['displayName'];
				}
				if ( isset( $first_result['product']['productLabels'] ) ) {
					foreach ( $first_result['product']['productLabels'] as $label ) {
						if ( isset( $label['key'] ) && isset( $label['value'] ) ) {
							if ( 'brand' === strtolower( $label['key'] ) ) {
								$brand = $label['value'];
							} elseif ( 'model' === strtolower( $label['key'] ) ) {
								$model = $label['value'];
							} elseif ( in_array( strtolower( $label['key'] ), array( 'gtin', 'ean', 'upc', 'asin' ), true ) ) {
								$identifiers[ strtolower( $label['key'] ) ] = $label['value'];
							}
						}
					}
				}
			}
		}

		// Fallback: try to extract from labels if product search didn't work.
		if ( empty( $title ) && isset( $vision_result['responses'][0]['labelAnnotations'] ) ) {
			$labels = array();
			foreach ( $vision_result['responses'][0]['labelAnnotations'] as $annotation ) {
				if ( isset( $annotation['description'] ) ) {
					$labels[] = $annotation['description'];
				}
			}
			$title = ! empty( $labels ) ? implode( ' ', array_slice( $labels, 0, 3 ) ) : __( 'Unknown product', 'wp-mcp-ai-pro' );
		}

		return array(
			'product' => array(
				'title'       => $title,
				'brand'       => $brand,
				'model'       => $model,
				'identifiers' => $identifiers,
			),
		);
	}

	/**
	 * Build search query from Vision API result.
	 *
	 * @param array $vision_result Parsed vision result.
	 * @return string Search query.
	 */
	protected function build_search_query_from_vision( $vision_result ) {
		$product = isset( $vision_result['product'] ) ? $vision_result['product'] : array();
		$parts   = array();

		if ( ! empty( $product['brand'] ) ) {
			$parts[] = $product['brand'];
		}
		if ( ! empty( $product['model'] ) ) {
			$parts[] = $product['model'];
		} elseif ( ! empty( $product['title'] ) ) {
			$parts[] = $product['title'];
		}

		return implode( ' ', $parts );
	}

	/**
	 * Extract text from document file.
	 *
	 * @param string $file_path Document file path.
	 * @param array  $context   Execution context.
	 * @return string|WP_Error Text content or error.
	 */
	protected function extract_text_from_document( $file_path, $context ) {
		// Validate file exists and is readable.
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_file_not_readable',
				__( 'Document file is not readable.', 'wp-mcp-ai-pro' )
			);
		}

		// Get file type.
		$file_type = wp_check_filetype( $file_path );
		$mime_type = isset( $file_type['type'] ) ? $file_type['type'] : '';

		// Check if it's a supported document type.
		$supported_types = array(
			'application/pdf',
			'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // .docx
			'application/msword', // .doc
			'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', // .xlsx
			'application/vnd.ms-excel', // .xls
			'text/plain',
			'text/csv',
		);

		if ( ! in_array( $mime_type, $supported_types, true ) ) {
			return new WP_Error(
				'wp_mcp_ai_unsupported_file_type',
				sprintf(
					/* translators: %s: mime type */
					__( 'Unsupported document type: %s. Supported types: PDF, Word, Excel, TXT, CSV.', 'wp-mcp-ai-pro' ),
					$mime_type
				)
			);
		}

		// For text files, read directly.
		if ( 'text/plain' === $mime_type || 'text/csv' === $mime_type ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- Required for document processing.
			$content = file_get_contents( $file_path );
			if ( false === $content ) {
				return new WP_Error(
					'wp_mcp_ai_read_failed',
					__( 'Failed to read document file.', 'wp-mcp-ai-pro' )
				);
			}
			return $content;
		}

		// For binary documents (PDF, Word, Excel), use submit_document_prompt tool.
		// This leverages the LLM's native document processing capabilities.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Submit_Document_Prompt' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_tool',
				__( 'Document processing tool is not available. Text extraction requires submit_document_prompt tool.', 'wp-mcp-ai-pro' )
			);
		}

		// Get attachment ID from file path.
		$attachment_id = $this->get_attachment_id_from_path( $file_path );
		if ( ! $attachment_id ) {
			return new WP_Error(
				'wp_mcp_ai_no_attachment_id',
				__( 'Could not determine attachment ID from file path.', 'wp-mcp-ai-pro' )
			);
		}

		// Use submit_document_prompt to extract text.
		$doc_tool = new WP_MCP_AI_Tool_Submit_Document_Prompt();
		$doc_args = array(
			'attachment_id' => $attachment_id,
			'prompt'        => 'Extract all text content from this document. Return only the raw text without any formatting or commentary.',
		);

		$doc_result = $doc_tool->execute( $doc_args, $context );
		if ( is_wp_error( $doc_result ) ) {
			return $doc_result;
		}

		// Extract text from response.
		if ( isset( $doc_result['text'] ) ) {
			return $doc_result['text'];
		} elseif ( isset( $doc_result['content'] ) ) {
			return $doc_result['content'];
		} elseif ( isset( $doc_result['response'] ) ) {
			return $doc_result['response'];
		}

		return new WP_Error(
			'wp_mcp_ai_no_text_extracted',
			__( 'No text content could be extracted from the document.', 'wp-mcp-ai-pro' )
		);
	}

	/**
	 * Extract line items from document text using LLM.
	 *
	 * @param string $text    Document text.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Array of line items or error.
	 */
	protected function extract_line_items_from_text( $text, $context ) {
		if ( empty( $text ) ) {
			return new WP_Error(
				'wp_mcp_ai_empty_text',
				__( 'No text content provided for line item extraction.', 'wp-mcp-ai-pro' )
			);
		}

		// Use submit_document_prompt tool to analyze text with LLM.
		if ( ! class_exists( 'WP_MCP_AI_Tool_Submit_Document_Prompt' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_tool',
				__( 'Line item extraction requires submit_document_prompt tool.', 'wp-mcp-ai-pro' )
			);
		}

		// Create a temporary text file with the content.
		$temp_file = $this->create_temp_text_file( $text );
		if ( is_wp_error( $temp_file ) ) {
			return $temp_file;
		}

		// Upload as attachment temporarily.
		$attachment_id = $this->create_temp_attachment( $temp_file, 'document-extract.txt' );
		if ( is_wp_error( $attachment_id ) ) {
			// Clean up temp file.
			wp_delete_file( $temp_file );
			return $attachment_id;
		}

		// Prepare extraction prompt.
		$extraction_prompt = 'Analyze this document and extract all product line items. For each line item, provide:
- description: product name or description
- brand: brand name (if mentioned)
- model: model number or SKU (if mentioned)
- quantity: quantity ordered (if mentioned)
- unit_price: price per unit (if mentioned)
- gtin: GTIN, UPC, or EAN code (if mentioned)

Return ONLY a JSON array of objects, with each object representing one line item. Example format:
[
  {
    "description": "Apple AirPods Pro",
    "brand": "Apple",
    "model": "A2931",
    "quantity": 2,
    "unit_price": 199.99,
    "gtin": "194253482175"
  }
]

If no line items are found, return an empty array [].';

		$doc_tool = new WP_MCP_AI_Tool_Submit_Document_Prompt();
		$doc_args = array(
			'attachment_id' => $attachment_id,
			'prompt'        => $extraction_prompt,
		);

		$doc_result = $doc_tool->execute( $doc_args, $context );

		// Clean up temporary attachment.
		wp_delete_attachment( $attachment_id, true );
		wp_delete_file( $temp_file );

		if ( is_wp_error( $doc_result ) ) {
			return $doc_result;
		}

		// Parse the response to extract JSON.
		$response_text = '';
		if ( isset( $doc_result['text'] ) ) {
			$response_text = $doc_result['text'];
		} elseif ( isset( $doc_result['content'] ) ) {
			$response_text = $doc_result['content'];
		} elseif ( isset( $doc_result['response'] ) ) {
			$response_text = $doc_result['response'];
		}

		if ( empty( $response_text ) ) {
			return array(); // No line items found.
		}

		// Try to parse JSON from response.
		$line_items = $this->parse_json_from_text( $response_text );
		if ( is_wp_error( $line_items ) ) {
			return $line_items;
		}

		// Validate and sanitize line items.
		$validated_items = array();
		foreach ( $line_items as $item ) {
			if ( ! is_array( $item ) ) {
				continue;
			}

			// Require at least a description.
			if ( empty( $item['description'] ) ) {
				continue;
			}

			$validated_item = array(
				'description' => sanitize_text_field( $item['description'] ),
				'brand'       => isset( $item['brand'] ) ? sanitize_text_field( $item['brand'] ) : '',
				'model'       => isset( $item['model'] ) ? sanitize_text_field( $item['model'] ) : '',
				'quantity'    => isset( $item['quantity'] ) ? absint( $item['quantity'] ) : 1,
				'unit_price'  => isset( $item['unit_price'] ) ? floatval( $item['unit_price'] ) : 0,
				'gtin'        => isset( $item['gtin'] ) ? sanitize_text_field( $item['gtin'] ) : '',
			);

			$validated_items[] = $validated_item;
		}

		return $validated_items;
	}

	/**
	 * Build search query from line item data.
	 *
	 * @param array $line_item Line item data.
	 * @return string Search query.
	 */
	protected function build_search_query_from_line_item( $line_item ) {
		$parts = array();

		if ( ! empty( $line_item['brand'] ) ) {
			$parts[] = $line_item['brand'];
		}
		if ( ! empty( $line_item['model'] ) ) {
			$parts[] = $line_item['model'];
		} elseif ( ! empty( $line_item['description'] ) ) {
			$parts[] = $line_item['description'];
		}

		return implode( ' ', $parts );
	}

	/**
	 * Extract product data from URL using Crawl4AI.
	 *
	 * @param string $url     Product URL.
	 * @param array  $context Execution context.
	 * @return array|WP_Error Product data or error.
	 */
	protected function extract_product_from_url( $url, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'Crawl4AI tool is required for URL processing.', 'wp-mcp-ai-pro' )
			);
		}

		$crawl_tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$crawl_args = array(
			'url'                 => $url,
			'wait_for_completion' => true,
			'timeout'             => 60,
		);

		$crawl_result = $crawl_tool->execute( $crawl_args, $context );
		if ( is_wp_error( $crawl_result ) ) {
			return $crawl_result;
		}

		// Parse crawl result to extract product information.
		return $this->parse_crawl_result_for_product( $crawl_result, $url );
	}

	/**
	 * Parse Crawl4AI result to extract product information.
	 *
	 * @param array  $crawl_result Crawl4AI result.
	 * @param string $url          Source URL.
	 * @return array Product data.
	 */
	protected function parse_crawl_result_for_product( $crawl_result, $url ) {
		$product = array(
			'title'        => '',
			'brand'        => '',
			'model'        => '',
			'identifiers'  => array(),
			'price'        => null,
			'currency'     => '',
			'availability' => 'unknown',
		);

		// Check if results contain markdown content we can parse.
		if ( isset( $crawl_result['results'][0]['markdown'] ) ) {
			$markdown = $crawl_result['results'][0]['markdown'];
			// Simple heuristic: extract first heading as title.
			if ( preg_match( '/^#\s+(.+)$/m', $markdown, $matches ) ) {
				$product['title'] = trim( $matches[1] );
			}
		}

		// Try to extract from HTML/text if available.
		if ( empty( $product['title'] ) && isset( $crawl_result['results'][0]['text'] ) ) {
			$text = $crawl_result['results'][0]['text'];
			// Take first non-empty line as title.
			$lines = explode( "\n", $text );
			foreach ( $lines as $line ) {
				$line = trim( $line );
				if ( ! empty( $line ) ) {
					$product['title'] = $line;
					break;
				}
			}
		}

		// Try to extract price using common patterns.
		if ( isset( $crawl_result['results'][0]['markdown'] ) || isset( $crawl_result['results'][0]['text'] ) ) {
			$content    = isset( $crawl_result['results'][0]['markdown'] ) ? $crawl_result['results'][0]['markdown'] : $crawl_result['results'][0]['text'];
			$price_data = $this->extract_price_from_content( $content );
			if ( $price_data ) {
				$product['price']    = $price_data['price'];
				$product['currency'] = $price_data['currency'];
			}
		}

		return $product;
	}

	/**
	 * Extract price from content text.
	 *
	 * @param string $content Content to parse.
	 * @return array|null Price data or null.
	 */
	protected function extract_price_from_content( $content ) {
		// Common price patterns.
		$patterns = array(
			'/\$\s*([0-9,]+\.?\d{0,2})\s*USD/i',      // $123.45 USD.
			'/\$\s*([0-9,]+\.?\d{0,2})/i',            // $123.45.
			'/([0-9,]+\.?\d{0,2})\s*USD/i',           // 123.45 USD.
			'/€\s*([0-9,]+\.?\d{0,2})/i',             // €123.45.
			'/£\s*([0-9,]+\.?\d{0,2})/i',             // £123.45.
		);

		$currency_map = array(
			'$' => 'USD',
			'€' => 'EUR',
			'£' => 'GBP',
		);

		foreach ( $patterns as $pattern ) {
			if ( preg_match( $pattern, $content, $matches ) ) {
				$price_str = str_replace( ',', '', $matches[1] );
				$price     = floatval( $price_str );

				// Detect currency from pattern.
				$currency = 'USD'; // Default.
				if ( stripos( $matches[0], 'USD' ) !== false ) {
					$currency = 'USD';
				} elseif ( strpos( $matches[0], '€' ) !== false ) {
					$currency = 'EUR';
				} elseif ( strpos( $matches[0], '£' ) !== false ) {
					$currency = 'GBP';
				}

				return array(
					'price'    => $price,
					'currency' => $currency,
				);
			}
		}

		return null;
	}

	/**
	 * Build search query from product data extracted from URL.
	 *
	 * @param array $product_data Product data.
	 * @return string Search query.
	 */
	protected function build_search_query_from_product_data( $product_data ) {
		$parts = array();

		if ( ! empty( $product_data['brand'] ) ) {
			$parts[] = $product_data['brand'];
		}
		if ( ! empty( $product_data['model'] ) ) {
			$parts[] = $product_data['model'];
		} elseif ( ! empty( $product_data['title'] ) ) {
			// Use title but limit length.
			$title_words = explode( ' ', $product_data['title'] );
			$parts[]     = implode( ' ', array_slice( $title_words, 0, 5 ) );
		}

		return implode( ' ', $parts );
	}

	/**
	 * Discover prices for a product across retailers.
	 *
	 * @param string $search_query        Search query.
	 * @param int    $max_results         Maximum results.
	 * @param array  $preferred_retailers Preferred retailer domains.
	 * @param string $currency            Preferred currency.
	 * @param string $locale              Locale.
	 * @param array  $context             Execution context.
	 * @return array Array of offers.
	 */
	protected function discover_prices( $search_query, $max_results, $preferred_retailers, $currency, $locale, $context ) {
		// Build list of retailer URLs to search.
		$retailer_urls = $this->build_retailer_search_urls( $search_query, $preferred_retailers, $locale );

		$offers = array();

		// For each retailer URL, crawl and extract pricing.
		foreach ( $retailer_urls as $retailer_url ) {
			if ( count( $offers ) >= $max_results ) {
				break;
			}

			$offer = $this->extract_offer_from_search_url( $retailer_url, $context );
			if ( $offer && ! is_wp_error( $offer ) ) {
				$offers[] = $offer;
			}
		}

		return $offers;
	}

	/**
	 * Build search URLs for retailers.
	 *
	 * @param string $query               Search query.
	 * @param array  $preferred_retailers Preferred retailer domains.
	 * @param string $locale              Locale.
	 * @return array Array of search URLs.
	 */
	protected function build_retailer_search_urls( $query, $preferred_retailers, $locale ) {
		$encoded_query = rawurlencode( $query );
		$urls          = array();

		// Map of retailer domains to search URL patterns.
		$retailer_patterns = array(
			'amazon.com'  => 'https://www.amazon.com/s?k=' . $encoded_query,
			'walmart.com' => 'https://www.walmart.com/search?q=' . $encoded_query,
			'ebay.com'    => 'https://www.ebay.com/sch/i.html?_nkw=' . $encoded_query,
			'target.com'  => 'https://www.target.com/s?searchTerm=' . $encoded_query,
		);

		// Prioritize preferred retailers.
		foreach ( $preferred_retailers as $retailer ) {
			if ( isset( $retailer_patterns[ $retailer ] ) ) {
				$urls[] = $retailer_patterns[ $retailer ];
			}
		}

		// Add remaining retailers.
		foreach ( $retailer_patterns as $domain => $url_pattern ) {
			if ( ! in_array( $domain, $preferred_retailers, true ) ) {
				$urls[] = $url_pattern;
			}
		}

		// Limit to 5 retailers to avoid excessive crawling.
		return array_slice( $urls, 0, 5 );
	}

	/**
	 * Extract offer from a retailer search URL.
	 *
	 * @param string $search_url Retailer search URL.
	 * @param array  $context    Execution context.
	 * @return array|WP_Error Offer data or error.
	 */
	protected function extract_offer_from_search_url( $search_url, $context ) {
		if ( ! class_exists( 'WP_MCP_AI_Tool_Run_Crawl4AI_Job' ) ) {
			return new WP_Error(
				'wp_mcp_ai_missing_dependency',
				__( 'Crawl4AI tool is required.', 'wp-mcp-ai-pro' )
			);
		}

		$crawl_tool = new WP_MCP_AI_Tool_Run_Crawl4AI_Job();
		$crawl_args = array(
			'url'                 => $search_url,
			'wait_for_completion' => true,
			'timeout'             => 30,
		);

		$crawl_result = $crawl_tool->execute( $crawl_args, $context );
		if ( is_wp_error( $crawl_result ) ) {
			return $crawl_result;
		}

		// Parse the search results to find the first product and extract price.
		$product = $this->parse_search_results( $crawl_result, $search_url );
		if ( ! $product || ! isset( $product['price'] ) ) {
			return null;
		}

		return array(
			'retailer'     => $this->extract_domain_name( $search_url ),
			'url'          => isset( $product['url'] ) ? $product['url'] : $search_url,
			'price'        => $product['price'],
			'currency'     => isset( $product['currency'] ) ? $product['currency'] : 'USD',
			'availability' => isset( $product['availability'] ) ? $product['availability'] : 'in_stock',
			'last_checked' => current_time( 'mysql', true ),
		);
	}

	/**
	 * Parse search results from crawl data.
	 *
	 * @param array  $crawl_result Crawl4AI result.
	 * @param string $search_url   Source search URL.
	 * @return array|null Product data or null.
	 */
	protected function parse_search_results( $crawl_result, $search_url ) {
		// Extract price from the first result in search page.
		// This is a simplified implementation.
		if ( ! isset( $crawl_result['results'][0] ) ) {
			return null;
		}

		$content    = isset( $crawl_result['results'][0]['markdown'] ) ? $crawl_result['results'][0]['markdown'] : '';
		$price_data = $this->extract_price_from_content( $content );

		if ( ! $price_data ) {
			return null;
		}

		return array(
			'price'    => $price_data['price'],
			'currency' => $price_data['currency'],
		);
	}

	/**
	 * Extract domain name from URL.
	 *
	 * @param string $url URL.
	 * @return string Domain name.
	 */
	protected function extract_domain_name( $url ) {
		$parts = wp_parse_url( $url );
		if ( isset( $parts['host'] ) ) {
			// Remove www. prefix.
			return preg_replace( '/^www\./i', '', $parts['host'] );
		}
		return 'unknown';
	}

	/**
	 * Get attachment ID from file path.
	 *
	 * @param string $file_path File path.
	 * @return int|false Attachment ID or false.
	 */
	protected function get_attachment_id_from_path( $file_path ) {
		global $wpdb;

		// Normalize path.
		$file_path = wp_normalize_path( $file_path );

		// Query for attachment by file path.
		// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for file path lookup.
		$attachment_id = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
				basename( $file_path )
			)
		);

		if ( $attachment_id ) {
			return absint( $attachment_id );
		}

		// Try with full path relative to uploads directory.
		$upload_dir = wp_upload_dir();
		$base_dir   = wp_normalize_path( $upload_dir['basedir'] );
		if ( 0 === strpos( $file_path, $base_dir ) ) {
			$relative_path = str_replace( trailingslashit( $base_dir ), '', $file_path );
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery, WordPress.DB.DirectDatabaseQuery.NoCaching -- Required for file path lookup.
			$attachment_id = $wpdb->get_var(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = '_wp_attached_file' AND meta_value = %s LIMIT 1",
					$relative_path
				)
			);

			if ( $attachment_id ) {
				return absint( $attachment_id );
			}
		}

		return false;
	}

	/**
	 * Create temporary text file.
	 *
	 * @param string $content Text content.
	 * @return string|WP_Error File path or error.
	 */
	protected function create_temp_text_file( $content ) {
		$upload_dir = wp_upload_dir();
		$temp_dir   = trailingslashit( $upload_dir['basedir'] ) . 'wp-mcp-ai-temp';

		if ( ! file_exists( $temp_dir ) ) {
			wp_mkdir_p( $temp_dir );
		}

		$temp_file = trailingslashit( $temp_dir ) . 'extract-' . wp_generate_password( 12, false ) . '.txt';

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Required for temp file creation.
		$result = file_put_contents( $temp_file, $content );

		if ( false === $result ) {
			return new WP_Error(
				'wp_mcp_ai_temp_file_failed',
				__( 'Failed to create temporary file for text extraction.', 'wp-mcp-ai-pro' )
			);
		}

		return $temp_file;
	}

	/**
	 * Create temporary attachment from file.
	 *
	 * @param string $file_path File path.
	 * @param string $filename  Filename.
	 * @return int|WP_Error Attachment ID or error.
	 */
	protected function create_temp_attachment( $file_path, $filename ) {
		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		if ( ! function_exists( 'wp_insert_attachment' ) ) {
			require_once ABSPATH . 'wp-admin/includes/post.php';
		}

		$filetype = wp_check_filetype( $filename );

		$attachment = array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => sanitize_file_name( $filename ),
			'post_content'   => '',
			'post_status'    => 'inherit',
		);

		$attachment_id = wp_insert_attachment( $attachment, $file_path );

		if ( is_wp_error( $attachment_id ) ) {
			return $attachment_id;
		}

		return $attachment_id;
	}

	/**
	 * Parse JSON from text response.
	 *
	 * @param string $text Text containing JSON.
	 * @return array|WP_Error Parsed array or error.
	 */
	protected function parse_json_from_text( $text ) {
		// Try to find JSON array in the text.
		// Look for array pattern: [...].
		if ( preg_match( '/\[\s*\{.*\}\s*\]/s', $text, $matches ) ) {
			$json_text = $matches[0];
		} elseif ( preg_match( '/\[\s*\]/s', $text, $matches ) ) {
			// Empty array.
			return array();
		} else {
			// Try the whole text.
			$json_text = trim( $text );
		}

		// Decode JSON.
		$decoded = json_decode( $json_text, true );

		if ( null === $decoded && JSON_ERROR_NONE !== json_last_error() ) {
			return new WP_Error(
				'wp_mcp_ai_json_parse_failed',
				sprintf(
					/* translators: %s: JSON error message */
					__( 'Failed to parse JSON from LLM response: %s', 'wp-mcp-ai-pro' ),
					json_last_error_msg()
				)
			);
		}

		if ( ! is_array( $decoded ) ) {
			return new WP_Error(
				'wp_mcp_ai_invalid_json',
				__( 'LLM response did not contain a valid JSON array.', 'wp-mcp-ai-pro' )
			);
		}

		return $decoded;
	}

	/**
	 * Get capability flags.
	 *
	 * @return array<string>
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data from external sources.
			'external-api',         // Makes external API calls (Vision, Crawl4AI).
			'requires-capability',  // Requires read capability.
			'network-dependent',    // Requires internet connectivity.
			'long-running',         // Can take significant time for multiple URLs.
			'may-timeout',          // Crawling multiple retailers can exceed timeout.
			'async-capable',        // Can execute asynchronously for large batches.
		);
	}

	/**
	 * Get tool rules.
	 *
	 * @return array
	 */
	public function get_tool_rules() {
		return array(
			'model_requirements'    => array(
				'providers'    => array( 'any' ),
				'capabilities' => array(),
				'required'     => false,
			),
			'parameter_constraints' => array(
				'at_least_one_of' => array( 'image_attachment_id', 'document_attachment_id', 'urls', 'url' ),
			),
			'rate_limits'           => array(
				'requests_per_minute' => 10,
				'requests_per_hour'   => 100,
				'concurrent_requests' => 3,
			),
			'timeout_constraints'   => array(
				'recommended_timeout' => 60,  // Single item.
				'max_execution_time'  => 300, // Batch mode with multiple URLs.
			),
			'dependencies'          => array(
				'required_tools' => array( 'run_crawl4ai_job' ),
				'optional_tools' => array( 'vision_product_search', 'vision_object_localization' ),
			),
			'orchestration_hints'   => array(
				'can_run_parallel' => true,
				'requires_lock'    => false,
				'cache_ttl'        => 900, // Cache results for 15 minutes.
				'retry_strategy'   => 'exponential_backoff',
				'max_retries'      => 2,
			),
		);
	}
}
