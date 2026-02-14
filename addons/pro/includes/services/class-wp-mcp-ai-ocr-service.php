<?php
/**
 * OCR Service
 *
 * Handles Optical Character Recognition (OCR) for scanned images and PDFs.
 * Supports multiple OCR providers with intelligent fallback.
 *
 * @package WP_MCP_AI_Pro
 * @since 1.3.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * OCR Service class
 *
 * Provides OCR functionality using multiple providers:
 * - OpenAI GPT-4 Vision (primary)
 * - Google Gemini Vision (secondary)
 * - Ollama Vision Models (local fallback)
 * - Tesseract OCR (system fallback)
 *
 * @since 1.3.0
 */
class WP_MCP_AI_OCR_Service {

	/**
	 * Minimum characters to consider PDF as having readable text.
	 *
	 * @var int
	 */
	const MIN_TEXT_THRESHOLD = 50;

	/**
	 * Maximum image dimension for OCR processing.
	 *
	 * @var int
	 */
	const MAX_IMAGE_DIMENSION = 2048;

	/**
	 * Extract text from image using OCR.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    OCR options.
	 * @return string|WP_Error Extracted text or error.
	 */
	public function extract_text_from_image( $image_path, $options = array() ) {
		if ( ! file_exists( $image_path ) ) {
			return new WP_Error( 'file_not_found', __( 'Image file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Default options.
		$defaults = array(
			'provider'    => 'auto', // auto, openai, gemini, ollama, tesseract.
			'preprocess'  => true,   // Apply image preprocessing.
			'language'    => 'eng',  // OCR language.
			'enhance'     => true,   // Enhance image quality.
		);
		$options  = wp_parse_args( $options, $defaults );

		// Preprocess image if enabled.
		if ( $options['preprocess'] ) {
			$processed_image = $this->preprocess_image( $image_path, $options );
			if ( is_wp_error( $processed_image ) ) {
				// Continue with original if preprocessing fails.
				WP_MCP_AI_Logger::log_event(
					'ocr_preprocessing_failed',
					'Image preprocessing failed, using original',
					array( 'error' => $processed_image->get_error_message() )
				);
			} else {
				$image_path = $processed_image;
			}
		}

		// Determine provider.
		$provider = $options['provider'];
		if ( 'auto' === $provider ) {
			$provider = $this->determine_best_provider();
		}

		// Try extraction with selected provider.
		$result = $this->extract_with_provider( $image_path, $provider, $options );

		// If failed and auto mode, try fallback providers.
		if ( is_wp_error( $result ) && 'auto' === $options['provider'] ) {
			$fallback_providers = $this->get_fallback_providers( $provider );
			foreach ( $fallback_providers as $fallback ) {
				$result = $this->extract_with_provider( $image_path, $fallback, $options );
				if ( ! is_wp_error( $result ) ) {
					break;
				}
			}
		}

		// Clean up preprocessed temp file if created.
		if ( isset( $processed_image ) && ! is_wp_error( $processed_image ) && $processed_image !== $image_path ) {
			@unlink( $processed_image );
		}

		return $result;
	}

	/**
	 * Extract text from PDF using OCR.
	 *
	 * Converts PDF pages to images and applies OCR to each page.
	 *
	 * @param string $pdf_path Path to PDF file.
	 * @param array  $options  OCR options.
	 * @return string|WP_Error Extracted text or error.
	 */
	public function extract_text_from_pdf( $pdf_path, $options = array() ) {
		if ( ! file_exists( $pdf_path ) ) {
			return new WP_Error( 'file_not_found', __( 'PDF file not found.', 'mcp-ai-wpoos-pro' ) );
		}

		// Default options.
		$defaults = array(
			'max_pages' => 0,       // 0 = all pages.
			'provider'  => 'auto',
			'dpi'       => 300,     // DPI for PDF to image conversion.
		);
		$options  = wp_parse_args( $options, $defaults );

		// Convert PDF pages to images.
		$images = $this->convert_pdf_to_images( $pdf_path, $options );
		if ( is_wp_error( $images ) ) {
			return $images;
		}

		// Extract text from each image.
		$all_text = array();
		foreach ( $images as $page_num => $image_path ) {
			$text = $this->extract_text_from_image( $image_path, $options );
			
			// Clean up temp image.
			@unlink( $image_path );

			if ( is_wp_error( $text ) ) {
				WP_MCP_AI_Logger::log_event(
					'ocr_page_failed',
					sprintf( 'OCR failed for page %d', $page_num + 1 ),
					array( 'error' => $text->get_error_message() )
				);
				continue;
			}

			$all_text[] = sprintf( "--- Page %d ---\n%s", $page_num + 1, $text );
		}

		if ( empty( $all_text ) ) {
			return new WP_Error( 'ocr_failed', __( 'Failed to extract text from any pages.', 'mcp-ai-wpoos-pro' ) );
		}

		return implode( "\n\n", $all_text );
	}

	/**
	 * Check if PDF appears to be scanned (image-only, no readable text).
	 *
	 * @param string $pdf_path Path to PDF file.
	 * @return bool True if PDF appears to be scanned.
	 */
	public function is_scanned_pdf( $pdf_path ) {
		// Try to extract text using standard method.
		$text = $this->extract_standard_text( $pdf_path );
		
		if ( is_wp_error( $text ) ) {
			// If extraction failed, assume it's scanned.
			return true;
		}

		// Remove whitespace and count characters.
		$clean_text = trim( preg_replace( '/\s+/', '', $text ) );
		
		// If less than threshold characters, consider it scanned.
		return strlen( $clean_text ) < self::MIN_TEXT_THRESHOLD;
	}

	/**
	 * Preprocess image for better OCR results.
	 *
	 * Applies: resizing, grayscale, contrast enhancement, noise reduction.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Processing options.
	 * @return string|WP_Error Path to processed image or error.
	 */
	protected function preprocess_image( $image_path, $options = array() ) {
		// Check if Sharp is available via Node.js.
		$sharp_available = $this->is_sharp_available();
		
		if ( $sharp_available ) {
			return $this->preprocess_with_sharp( $image_path, $options );
		}

		// Fallback to Imagick if available.
		if ( extension_loaded( 'imagick' ) ) {
			return $this->preprocess_with_imagick( $image_path, $options );
		}

		// No preprocessing available.
		return new WP_Error( 'no_preprocessor', __( 'No image preprocessing tools available.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Preprocess image using Sharp (Node.js).
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Processing options.
	 * @return string|WP_Error Path to processed image or error.
	 */
	protected function preprocess_with_sharp( $image_path, $options = array() ) {
		$service_path = WP_MCP_AI_PRO_PATH . 'node-services/image-preprocess-service.js';
		
		if ( ! file_exists( $service_path ) ) {
			return new WP_Error( 'service_not_found', __( 'Image preprocessing service not found.', 'mcp-ai-wpoos-pro' ) );
		}

		$temp_output = tempnam( sys_get_temp_dir(), 'ocr_preprocessed_' ) . '.png';
		
		$args = wp_json_encode(
			array(
				'input'      => $image_path,
				'output'     => $temp_output,
				'maxWidth'   => self::MAX_IMAGE_DIMENSION,
				'maxHeight'  => self::MAX_IMAGE_DIMENSION,
				'grayscale'  => true,
				'normalize'  => true,
				'sharpen'    => true,
			)
		);

		$cmd = sprintf(
			'node %s preprocess %s 2>&1',
			escapeshellarg( $service_path ),
			escapeshellarg( $args )
		);

		exec( $cmd, $output, $return_code );

		if ( 0 !== $return_code ) {
			@unlink( $temp_output );
			return new WP_Error( 'preprocessing_failed', 'Sharp preprocessing failed: ' . implode( "\n", $output ) );
		}

		return $temp_output;
	}

	/**
	 * Preprocess image using Imagick (PHP extension).
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Processing options.
	 * @return string|WP_Error Path to processed image or error.
	 */
	protected function preprocess_with_imagick( $image_path, $options = array() ) {
		try {
			$image = new Imagick( $image_path );
			
			// Resize if too large.
			$width  = $image->getImageWidth();
			$height = $image->getImageHeight();
			if ( $width > self::MAX_IMAGE_DIMENSION || $height > self::MAX_IMAGE_DIMENSION ) {
				$image->thumbnailImage( self::MAX_IMAGE_DIMENSION, self::MAX_IMAGE_DIMENSION, true );
			}

			// Convert to grayscale.
			$image->setImageType( Imagick::IMGTYPE_GRAYSCALE );
			
			// Enhance contrast.
			$image->normalizeImage();
			$image->enhanceImage();
			
			// Sharpen slightly.
			$image->sharpenImage( 0, 1 );
			
			// Reduce noise.
			$image->despeckleImage();

			// Save to temp file.
			$temp_output = tempnam( sys_get_temp_dir(), 'ocr_preprocessed_' ) . '.png';
			$image->setImageFormat( 'png' );
			$image->writeImage( $temp_output );
			$image->clear();
			$image->destroy();

			return $temp_output;
		} catch ( Exception $e ) {
			return new WP_Error( 'imagick_failed', sprintf( 'Imagick preprocessing failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Convert PDF pages to images.
	 *
	 * @param string $pdf_path Path to PDF file.
	 * @param array  $options  Conversion options.
	 * @return array|WP_Error Array of image paths or error.
	 */
	protected function convert_pdf_to_images( $pdf_path, $options = array() ) {
		$dpi       = isset( $options['dpi'] ) ? absint( $options['dpi'] ) : 300;
		$max_pages = isset( $options['max_pages'] ) ? absint( $options['max_pages'] ) : 0;

		// Try Imagick first (preferred for PDF to image).
		if ( extension_loaded( 'imagick' ) ) {
			return $this->convert_pdf_with_imagick( $pdf_path, $dpi, $max_pages );
		}

		// Fallback: try pdftoppm command-line tool.
		$pdftoppm = shell_exec( 'which pdftoppm 2>/dev/null' );
		if ( ! empty( $pdftoppm ) ) {
			return $this->convert_pdf_with_pdftoppm( $pdf_path, $dpi, $max_pages );
		}

		return new WP_Error( 'no_converter', __( 'No PDF to image converter available. Install Imagick extension or poppler-utils.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Convert PDF to images using Imagick.
	 *
	 * @param string $pdf_path  Path to PDF file.
	 * @param int    $dpi       DPI for conversion.
	 * @param int    $max_pages Maximum pages to convert (0 = all).
	 * @return array|WP_Error Array of image paths or error.
	 */
	protected function convert_pdf_with_imagick( $pdf_path, $dpi = 300, $max_pages = 0 ) {
		try {
			$pdf = new Imagick();
			$pdf->setResolution( $dpi, $dpi );
			$pdf->readImage( $pdf_path );

			$images    = array();
			$num_pages = $pdf->getNumberImages();
			$pages_to_process = ( $max_pages > 0 && $max_pages < $num_pages ) ? $max_pages : $num_pages;

			for ( $i = 0; $i < $pages_to_process; $i++ ) {
				$pdf->setIteratorIndex( $i );
				$pdf->setImageFormat( 'png' );
				$pdf->setImageBackgroundColor( 'white' );
				$pdf->setImageAlphaChannel( Imagick::ALPHACHANNEL_REMOVE );
				$pdf->mergeImageLayers( Imagick::LAYERMETHOD_FLATTEN );

				$temp_image = tempnam( sys_get_temp_dir(), 'pdf_page_' . $i . '_' ) . '.png';
				$pdf->writeImage( $temp_image );
				$images[ $i ] = $temp_image;
			}

			$pdf->clear();
			$pdf->destroy();

			return $images;
		} catch ( Exception $e ) {
			return new WP_Error( 'imagick_conversion_failed', sprintf( 'PDF to image conversion failed: %s', $e->getMessage() ) );
		}
	}

	/**
	 * Convert PDF to images using pdftoppm command-line tool.
	 *
	 * @param string $pdf_path  Path to PDF file.
	 * @param int    $dpi       DPI for conversion.
	 * @param int    $max_pages Maximum pages to convert (0 = all).
	 * @return array|WP_Error Array of image paths or error.
	 */
	protected function convert_pdf_with_pdftoppm( $pdf_path, $dpi = 300, $max_pages = 0 ) {
		$temp_prefix = tempnam( sys_get_temp_dir(), 'pdf_page_' );
		$cmd         = sprintf(
			'pdftoppm -png -r %d %s %s %s 2>&1',
			(int) $dpi,
			$max_pages > 0 ? '-l ' . (int) $max_pages : '',
			escapeshellarg( $pdf_path ),
			escapeshellarg( $temp_prefix )
		);

		exec( $cmd, $output, $return_code );

		if ( 0 !== $return_code ) {
			return new WP_Error( 'pdftoppm_failed', 'pdftoppm conversion failed: ' . implode( "\n", $output ) );
		}

		// Find generated images.
		$images = glob( $temp_prefix . '-*.png' );
		if ( empty( $images ) ) {
			return new WP_Error( 'no_images_generated', __( 'No images were generated from PDF.', 'mcp-ai-wpoos-pro' ) );
		}

		// Reindex array starting from 0.
		return array_values( $images );
	}

	/**
	 * Extract text using specified OCR provider.
	 *
	 * @param string $image_path Path to image file.
	 * @param string $provider   Provider name (openai, gemini, ollama, tesseract).
	 * @param array  $options    Extraction options.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_provider( $image_path, $provider, $options = array() ) {
		switch ( $provider ) {
			case 'openai':
				return $this->extract_with_openai( $image_path, $options );
			case 'gemini':
				return $this->extract_with_gemini( $image_path, $options );
			case 'ollama':
				return $this->extract_with_ollama( $image_path, $options );
			case 'tesseract':
				return $this->extract_with_tesseract( $image_path, $options );
			default:
				return new WP_Error( 'unknown_provider', sprintf( 'Unknown OCR provider: %s', $provider ) );
		}
	}

	/**
	 * Extract text using OpenAI Vision API.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Extraction options.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_openai( $image_path, $options = array() ) {
		// Get settings.
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['openai_api_key'] ) ) {
			return new WP_Error( 'no_api_key', __( 'OpenAI API key not configured.', 'mcp-ai-wpoos-pro' ) );
		}

		// Encode image to base64.
		$image_data = file_get_contents( $image_path );
		$base64     = base64_encode( $image_data );
		$mime_type  = mime_content_type( $image_path );

		// Prepare API request.
		$client = new WP_MCP_AI_OpenAI_Client();
		
		$messages = array(
			array(
				'role'    => 'user',
				'content' => array(
					array(
						'type' => 'text',
						'text' => 'Extract all text from this image. Return only the extracted text, maintaining the original layout and structure as much as possible. Do not add any commentary or explanation.',
					),
					array(
						'type'      => 'image_url',
						'image_url' => array(
							'url'    => "data:{$mime_type};base64,{$base64}",
							'detail' => 'high',
						),
					),
				),
			),
		);

		$response = $client->create_chat_completion(
			$messages,
			array(
				'model'      => 'gpt-4o',
				'max_tokens' => 4096,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			return trim( $response['choices'][0]['message']['content'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response from OpenAI API.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Extract text using Google Gemini Vision.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Extraction options.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_gemini( $image_path, $options = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['gemini_api_key'] ) ) {
			return new WP_Error( 'no_api_key', __( 'Gemini API key not configured.', 'mcp-ai-wpoos-pro' ) );
		}

		// Encode image to base64.
		$image_data = file_get_contents( $image_path );
		$base64     = base64_encode( $image_data );
		$mime_type  = mime_content_type( $image_path );

		$client = new WP_MCP_AI_Gemini_Client();
		
		$messages = array(
			array(
				'role'    => 'user',
				'parts'   => array(
					array(
						'text' => 'Extract all text from this image. Return only the extracted text, maintaining the original layout and structure as much as possible.',
					),
					array(
						'inline_data' => array(
							'mime_type' => $mime_type,
							'data'      => $base64,
						),
					),
				),
			),
		);

		$response = $client->create_chat_completion(
			$messages,
			array(
				'model' => 'gemini-1.5-flash',
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return trim( $response['candidates'][0]['content']['parts'][0]['text'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response from Gemini API.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Extract text using Ollama Vision model.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Extraction options.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_ollama( $image_path, $options = array() ) {
		$settings = get_option( 'wp_mcp_ai_settings', array() );
		if ( empty( $settings['ollama_endpoint'] ) ) {
			return new WP_Error( 'no_endpoint', __( 'Ollama endpoint not configured.', 'mcp-ai-wpoos-pro' ) );
		}

		// Encode image to base64.
		$image_data = file_get_contents( $image_path );
		$base64     = base64_encode( $image_data );

		// Use llava or similar vision model.
		$model = 'llava';

		$endpoint = trailingslashit( $settings['ollama_endpoint'] ) . 'api/generate';
		
		$body = wp_json_encode(
			array(
				'model'  => $model,
				'prompt' => 'Extract all text from this image. Return only the extracted text, maintaining the original layout.',
				'images' => array( $base64 ),
				'stream' => false,
			)
		);

		$response = wp_remote_post(
			$endpoint,
			array(
				'body'    => $body,
				'headers' => array( 'Content-Type' => 'application/json' ),
				'timeout' => 60,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( isset( $body['response'] ) ) {
			return trim( $body['response'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response from Ollama.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Extract text using Tesseract OCR.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Extraction options.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_tesseract( $image_path, $options = array() ) {
		$language = isset( $options['language'] ) ? $options['language'] : 'eng';

		// Try Node.js service first (best performance).
		$node_result = $this->extract_with_node_ocr( $image_path, $options );
		if ( ! is_wp_error( $node_result ) ) {
			return $node_result;
		}

		// Try PHP wrapper if available.
		if ( class_exists( '\thiagoalessio\TesseractOCR\TesseractOCR' ) ) {
			try {
				$ocr = new \thiagoalessio\TesseractOCR\TesseractOCR( $image_path );
				$ocr->lang( $language );
				
				// Set optimal PSM for document OCR.
				$ocr->psm( 3 ); // Fully automatic page segmentation.
				
				$text = $ocr->run();
				return trim( $text );
			} catch ( \Exception $e ) {
				// Fall through to command-line method.
				WP_MCP_AI_Logger::log_event(
					'tesseract_wrapper_failed',
					'Tesseract PHP wrapper failed, trying command-line',
					array( 'error' => $e->getMessage() )
				);
			}
		}

		// Fallback to command-line tesseract.
		$tesseract = shell_exec( 'which tesseract 2>/dev/null' );
		if ( empty( $tesseract ) ) {
			return new WP_Error( 'tesseract_not_found', __( 'Tesseract OCR not installed on system. Install via: npm install (for Node.js), apt-get install tesseract-ocr, or composer require thiagoalessio/tesseract_ocr', 'mcp-ai-wpoos-pro' ) );
		}

		$output_file = tempnam( sys_get_temp_dir(), 'ocr_' );

		$cmd = sprintf(
			'tesseract %s %s -l %s 2>&1',
			escapeshellarg( $image_path ),
			escapeshellarg( $output_file ),
			escapeshellarg( $language )
		);

		exec( $cmd, $output, $return_code );

		$text_file = $output_file . '.txt';
		
		if ( 0 === $return_code && file_exists( $text_file ) ) {
			$text = file_get_contents( $text_file );
			@unlink( $text_file );
			@unlink( $output_file );
			return trim( $text );
		}

		@unlink( $text_file );
		@unlink( $output_file );
		
		return new WP_Error( 'tesseract_failed', 'Tesseract OCR failed: ' . implode( "\n", $output ) );
	}

	/**
	 * Extract text using standard PDF parsing (non-OCR).
	 *
	 * @param string $pdf_path Path to PDF file.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_standard_text( $pdf_path ) {
		if ( class_exists( '\Smalot\PdfParser\Parser' ) ) {
			try {
				$parser = new \Smalot\PdfParser\Parser();
				$pdf    = $parser->parseFile( $pdf_path );
				return $pdf->getText();
			} catch ( \Exception $e ) {
				return new WP_Error( 'parse_failed', $e->getMessage() );
			}
		}

		return new WP_Error( 'no_parser', __( 'PDF parser not available.', 'mcp-ai-wpoos-pro' ) );
	}

	/**
	 * Determine the best OCR provider based on availability.
	 *
	 * @return string Provider name.
	 */
	protected function determine_best_provider() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		// Prefer OpenAI if API key is configured.
		if ( ! empty( $settings['openai_api_key'] ) ) {
			return 'openai';
		}

		// Next try Gemini.
		if ( ! empty( $settings['gemini_api_key'] ) ) {
			return 'gemini';
		}

		// Then try Ollama if configured.
		if ( ! empty( $settings['ollama_endpoint'] ) ) {
			return 'ollama';
		}

		// Finally, fall back to Tesseract if available.
		$tesseract = shell_exec( 'which tesseract 2>/dev/null' );
		if ( ! empty( $tesseract ) ) {
			return 'tesseract';
		}

		// Default to OpenAI (will fail if not configured).
		return 'openai';
	}

	/**
	 * Get fallback providers for a given primary provider.
	 *
	 * @param string $primary Primary provider.
	 * @return array Fallback providers in order.
	 */
	protected function get_fallback_providers( $primary ) {
		$all_providers = array( 'openai', 'gemini', 'ollama', 'tesseract' );
		
		// Remove primary from list.
		$fallbacks = array_diff( $all_providers, array( $primary ) );
		
		return array_values( $fallbacks );
	}

	/**
	 * Check if Sharp (Node.js) is available.
	 *
	 * @return bool True if Sharp is available.
	 */
	protected function is_sharp_available() {
		$service_path = WP_MCP_AI_PRO_PATH . 'node-services/image-preprocess-service.js';
		return file_exists( $service_path );
	}

	/**
	 * Extract text using Node.js OCR service (Tesseract.js).
	 *
	 * Offers better performance than PHP-based OCR.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    Extraction options.
	 * @return string|WP_Error Extracted text or error.
	 */
	protected function extract_with_node_ocr( $image_path, $options = array() ) {
		// Check if Node.js OCR service exists.
		$service_path = WP_MCP_AI_PRO_PATH . 'node-services/ocr-service.js';
		if ( ! file_exists( $service_path ) ) {
			return new WP_Error( 'service_not_found', 'Node.js OCR service not found.' );
		}

		$language = isset( $options['language'] ) ? $options['language'] : 'eng';

		// Prepare service arguments.
		$args = wp_json_encode(
			array(
				'path'       => $image_path,
				'language'   => $language,
				'preprocess' => true,
			)
		);

		// Execute Node.js service.
		$cmd = sprintf(
			'node %s image %s 2>&1',
			escapeshellarg( $service_path ),
			escapeshellarg( $args )
		);

		exec( $cmd, $output, $return_code );

		// Check for execution errors.
		if ( 0 !== $return_code ) {
			return new WP_Error(
				'node_ocr_failed',
				'Node.js OCR service failed: ' . implode( "\n", $output )
			);
		}

		// Parse JSON response.
		$result = json_decode( implode( "\n", $output ), true );

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'ocr_error', $result['error'] );
		}

		if ( ! isset( $result['text'] ) ) {
			return new WP_Error( 'invalid_response', 'Invalid response from Node.js OCR service.' );
		}

		// Log confidence if available.
		if ( isset( $result['confidence'] ) ) {
			WP_MCP_AI_Logger::log_event(
				'node_ocr_success',
				'Node.js OCR completed',
				array(
					'confidence' => $result['confidence'],
					'words'      => isset( $result['words'] ) ? $result['words'] : 0,
				)
			);
		}

		return $result['text'];
	}
}
