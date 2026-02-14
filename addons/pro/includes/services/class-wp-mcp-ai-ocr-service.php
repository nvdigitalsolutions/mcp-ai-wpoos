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
	 * Maximum file size for OCR processing (50MB).
	 *
	 * @var int
	 */
	const MAX_FILE_SIZE = 52428800;

	/**
	 * Default timeout for OCR operations (seconds).
	 *
	 * @var int
	 */
	const DEFAULT_TIMEOUT = 300;

	/**
	 * Maximum retry attempts for transient failures.
	 *
	 * @var int
	 */
	const MAX_RETRIES = 3;

	/**
	 * Circuit breaker cooldown period (seconds).
	 *
	 * @var int
	 */
	const CIRCUIT_BREAKER_COOLDOWN = 300;

	/**
	 * Singleton instance of OpenAI client.
	 *
	 * @var WP_MCP_AI_OpenAI_Client|null
	 */
	private static $openai_client = null;

	/**
	 * Singleton instance of Gemini client.
	 *
	 * @var WP_MCP_AI_Gemini_Client|null
	 */
	private static $gemini_client = null;

	/**
	 * Circuit breaker state for providers.
	 *
	 * @var array
	 */
	private static $circuit_breaker = array();

	/**
	 * Extract text from image using OCR.
	 *
	 * @param string $image_path Path to image file.
	 * @param array  $options    OCR options.
	 * @return string|WP_Error Extracted text or error.
	 */
	public function extract_text_from_image( $image_path, $options = array() ) {
		// Validate input.
		$validation = $this->validate_image_input( $image_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
		}

		// Get defaults from settings.
		$doc_settings = get_option( 'wp_mcp_ai_document_generation_settings', array() );
		$defaults     = array(
			'provider'   => 'auto', // auto, openai, gemini, ollama, tesseract.
			'preprocess' => isset( $doc_settings['ocr_preprocessing'] ) ? (bool) $doc_settings['ocr_preprocessing'] : true,
			'language'   => 'eng',  // OCR language.
			'enhance'    => true,   // Enhance image quality.
			'timeout'    => isset( $doc_settings['ocr_timeout'] ) ? absint( $doc_settings['ocr_timeout'] ) : self::DEFAULT_TIMEOUT,
		);
		$options      = wp_parse_args( $options, $defaults );

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
			$primary_error = $result->get_error_message();
			WP_MCP_AI_Logger::log_event(
				'ocr_fallback_triggered',
				sprintf( 'Primary OCR provider (%s) failed, trying fallbacks', $provider ),
				array( 
					'primary_provider' => $provider,
					'error'            => $primary_error,
				)
			);
			
			$fallback_providers = $this->get_fallback_providers( $provider );
			foreach ( $fallback_providers as $fallback ) {
				WP_MCP_AI_Logger::log_event(
					'ocr_trying_fallback',
					sprintf( 'Trying fallback provider: %s', $fallback ),
					array( 'provider' => $fallback )
				);
				
				$result = $this->extract_with_provider( $image_path, $fallback, $options );
				if ( ! is_wp_error( $result ) ) {
					WP_MCP_AI_Logger::log_event(
						'ocr_fallback_success',
						sprintf( 'Fallback provider succeeded: %s', $fallback ),
						array( 'provider' => $fallback )
					);
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
		// Validate input.
		$validation = $this->validate_pdf_input( $pdf_path );
		if ( is_wp_error( $validation ) ) {
			return $validation;
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
		$failed_pages = array();
		foreach ( $images as $page_num => $image_path ) {
			$text = $this->extract_text_from_image( $image_path, $options );
			
			// Clean up temp image.
			@unlink( $image_path );

			if ( is_wp_error( $text ) ) {
				$error_message = $text->get_error_message();
				$failed_pages[] = array(
					'page'  => $page_num + 1,
					'error' => $error_message,
				);
				
				WP_MCP_AI_Logger::log_event(
					'ocr_page_failed',
					sprintf( 'OCR failed for page %d: %s', $page_num + 1, $error_message ),
					array( 
						'page'  => $page_num + 1,
						'error' => $error_message,
					)
				);
				continue;
			}

			$all_text[] = sprintf( "--- Page %d ---\n%s", $page_num + 1, $text );
		}

		if ( empty( $all_text ) ) {
			$error_details = '';
			if ( ! empty( $failed_pages ) ) {
				$error_details = ' Failed pages: ' . wp_json_encode( $failed_pages );
			}
			return new WP_Error( 
				'ocr_failed', 
				__( 'Failed to extract text from any pages.', 'mcp-ai-wpoos-pro' ) . $error_details
			);
		}

		// Add summary if some pages failed.
		if ( ! empty( $failed_pages ) ) {
			$summary = sprintf(
				"\n\n--- OCR Summary ---\nSuccessfully processed: %d page(s)\nFailed: %d page(s)",
				count( $all_text ),
				count( $failed_pages )
			);
			$all_text[] = $summary;
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
		$images = array();
		$pdf = null;

		try {
			$pdf = new Imagick();
			$pdf->setResolution( $dpi, $dpi );
			$pdf->readImage( $pdf_path );

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
			// Clean up any temp files created before the error.
			foreach ( $images as $temp_file ) {
				if ( file_exists( $temp_file ) ) {
					@unlink( $temp_file );
				}
			}

			// Clean up Imagick object if it exists.
			if ( $pdf instanceof Imagick ) {
				$pdf->clear();
				$pdf->destroy();
			}

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
		// Check circuit breaker.
		if ( $this->is_circuit_open( $provider ) ) {
			WP_MCP_AI_Logger::log_event(
				'ocr_provider_skipped',
				sprintf( 'Provider %s skipped due to open circuit breaker', $provider ),
				array( 'provider' => $provider )
			);
			return new WP_Error(
				'provider_unavailable',
				sprintf( __( 'Provider %s is temporarily unavailable', 'mcp-ai-wpoos-pro' ), $provider )
			);
		}

		// Execute with retry logic.
		$result = $this->execute_with_retry(
			function () use ( $image_path, $provider, $options ) {
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
		);

		// Update circuit breaker based on result.
		if ( is_wp_error( $result ) ) {
			$error_code = $result->get_error_code();
			// Open circuit for persistent failures (not validation errors).
			$validation_errors = array( 'no_api_key', 'no_endpoint', 'file_not_found' );
			if ( ! in_array( $error_code, $validation_errors, true ) ) {
				$this->open_circuit( $provider, $result->get_error_message() );
			}
		} else {
			// Success - close circuit if it was open.
			$this->close_circuit( $provider );
		}

		return $result;
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
		$mime_type  = $this->get_mime_type( $image_path );

		// Get singleton client instance.
		if ( null === self::$openai_client ) {
			self::$openai_client = new WP_MCP_AI_OpenAI_Client();
		}
		$client = self::$openai_client;
		
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
			WP_MCP_AI_Logger::log_event(
				'ocr_openai_failed',
				'OpenAI Vision OCR failed',
				array( 'error' => $response->get_error_message() )
			);
			return new WP_Error(
				'ocr_openai_failed',
				sprintf(
					__( 'OpenAI Vision OCR failed: %s. Will try fallback provider.', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		if ( isset( $response['choices'][0]['message']['content'] ) ) {
			return trim( $response['choices'][0]['message']['content'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response from OpenAI API. Will try fallback provider.', 'mcp-ai-wpoos-pro' ) );
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
		$mime_type  = $this->get_mime_type( $image_path );

		// Get singleton client instance.
		if ( null === self::$gemini_client ) {
			self::$gemini_client = new WP_MCP_AI_Gemini_Client();
		}
		$client = self::$gemini_client;
		
		$request = array(
			'contents' => array(
				array(
					'parts' => array(
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
			),
		);

		$response = $client->generate_content( 'gemini-1.5-flash', $request, $settings );

		if ( is_wp_error( $response ) ) {
			WP_MCP_AI_Logger::log_event(
				'ocr_gemini_failed',
				'Gemini Vision OCR failed',
				array( 'error' => $response->get_error_message() )
			);
			return new WP_Error(
				'ocr_gemini_failed',
				sprintf(
					__( 'Gemini Vision OCR failed: %s. Will try fallback provider.', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				)
			);
		}

		if ( isset( $response['candidates'][0]['content']['parts'][0]['text'] ) ) {
			return trim( $response['candidates'][0]['content']['parts'][0]['text'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid response from Gemini API. Will try fallback provider.', 'mcp-ai-wpoos-pro' ) );
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
				'timeout' => self::DEFAULT_TIMEOUT,
			)
		);

		if ( is_wp_error( $response ) ) {
			return $response;
		}

		$body = json_decode( wp_remote_retrieve_body( $response ), true );
		
		if ( isset( $body['response'] ) && ! empty( trim( $body['response'] ) ) ) {
			return trim( $body['response'] );
		}

		return new WP_Error( 'invalid_response', __( 'Invalid or empty response from Ollama.', 'mcp-ai-wpoos-pro' ) );
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
			return new WP_Error(
				'tesseract_not_found',
				__( 'Tesseract OCR is not installed on the system. The plugin includes pre-bundled Node.js OCR service, but it appears unavailable. Please ensure Node.js is installed or install system Tesseract with: apt-get install tesseract-ocr (Linux) or brew install tesseract (macOS).', 'mcp-ai-wpoos-pro' ),
				array(
					'bundled_service' => 'Node.js OCR service (included)',
					'system_install'  => 'apt-get install tesseract-ocr',
				)
			);
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
	 * Checks document generation settings first, then falls back to
	 * detecting available providers from main settings.
	 *
	 * @return string Provider name.
	 */
	protected function determine_best_provider() {
		// Check document generation settings first.
		$doc_settings = get_option( 'wp_mcp_ai_document_generation_settings', array() );
		if ( ! empty( $doc_settings['ocr_provider'] ) && 'auto' !== $doc_settings['ocr_provider'] ) {
			return $doc_settings['ocr_provider'];
		}

		// Auto mode or not configured - detect best available provider.
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
		// Check if a specific fallback is configured.
		$doc_settings = get_option( 'wp_mcp_ai_document_generation_settings', array() );
		if ( ! empty( $doc_settings['ocr_fallback_provider'] ) && 'none' !== $doc_settings['ocr_fallback_provider'] ) {
			$fallback = $doc_settings['ocr_fallback_provider'];
			
			if ( 'auto' === $fallback ) {
				// Auto mode - try all providers except primary.
				$all_providers = array( 'openai', 'gemini', 'ollama', 'tesseract' );
				$fallbacks     = array_diff( $all_providers, array( $primary ) );
				return array_values( $fallbacks );
			} else {
				// Specific fallback configured - use it if different from primary.
				if ( $fallback !== $primary ) {
					return array( $fallback );
				}
				// If same as primary, return empty (no fallback).
				return array();
			}
		}

		// No fallback configured - return empty array.
		if ( ! empty( $doc_settings['ocr_fallback_provider'] ) && 'none' === $doc_settings['ocr_fallback_provider'] ) {
			return array();
		}

		// Default behavior - try all available providers except primary.
		$all_providers = array( 'openai', 'gemini', 'ollama', 'tesseract' );
		$fallbacks     = array_diff( $all_providers, array( $primary ) );
		
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
			return new WP_Error(
				'service_not_found',
				__( 'Pre-bundled Node.js OCR service not found. This is a plugin installation issue.', 'mcp-ai-wpoos-pro' ),
				array( 'expected_path' => $service_path )
			);
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

		// Execute Node.js service with timeout protection.
		$cmd = sprintf(
			'node %s image %s 2>&1',
			escapeshellarg( $service_path ),
			escapeshellarg( $args )
		);

		$result = $this->execute_node_service_with_timeout( $cmd, 120 ); // 2 minute timeout for OCR
		$output = $result['output'];
		$return_code = $result['return_code'];

		// Check for timeout.
		if ( $result['timed_out'] ) {
			return new WP_Error(
				'node_ocr_timeout',
				__( 'Node.js OCR service timed out after 120 seconds. Try processing fewer pages or a smaller image.', 'mcp-ai-wpoos-pro' ),
				array( 'timeout' => 120 )
			);
		}

		// Check for execution errors.
		if ( 0 !== $return_code ) {
			$output_text = implode( "\n", $output );
			
			// Try to parse as JSON error.
			$json_output = json_decode( $output_text, true );
			$error_message = isset( $json_output['error'] ) 
				? $json_output['error'] 
				: $output_text;
			
			return new WP_Error(
				'node_ocr_failed',
				sprintf(
					__( 'Pre-bundled Node.js OCR service failed. Ensure Node.js is installed. Error: %s', 'mcp-ai-wpoos-pro' ),
					$error_message
				),
				array(
					'return_code' => $return_code,
					'output'      => $output,
					'raw_error'   => $output_text,
				)
			);
		}

		// Parse JSON response.
		$output_text = implode( "\n", $output );
		$result = json_decode( $output_text, true );

		if ( null === $result ) {
			return new WP_Error(
				'invalid_json_response',
				sprintf(
					__( 'Node.js OCR service returned invalid JSON. Raw output: %s', 'mcp-ai-wpoos-pro' ),
					substr( $output_text, 0, 200 )
				),
				array( 'raw_output' => $output_text )
			);
		}

		if ( isset( $result['error'] ) ) {
			return new WP_Error( 'ocr_error', $result['error'], $result );
		}

		if ( ! isset( $result['text'] ) ) {
			return new WP_Error(
				'invalid_response',
				__( 'Invalid response from Node.js OCR service. Missing "text" field.', 'mcp-ai-wpoos-pro' ),
				array( 'result' => $result )
			);
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

	/**
	 * Validate image input before processing.
	 *
	 * @param string $image_path Path to image file.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_image_input( $image_path ) {
		// Check file exists.
		if ( ! file_exists( $image_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'Image file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Check file size.
		$file_size = filesize( $image_path );
		if ( $file_size > self::MAX_FILE_SIZE ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					__( 'Image file is too large (%s). Maximum size is %s.', 'mcp-ai-wpoos-pro' ),
					size_format( $file_size ),
					size_format( self::MAX_FILE_SIZE )
				),
				array( 'status' => 413 )
			);
		}

		// Check MIME type.
		$mime_type = $this->get_mime_type( $image_path );
		$allowed_types = array( 'image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp', 'image/tiff' );
		if ( ! in_array( $mime_type, $allowed_types, true ) ) {
			return new WP_Error(
				'invalid_file_type',
				sprintf(
					__( 'Invalid image file type: %s. Allowed types: %s', 'mcp-ai-wpoos-pro' ),
					$mime_type,
					implode( ', ', $allowed_types )
				),
				array( 'status' => 415 )
			);
		}

		// Check file is readable.
		if ( ! is_readable( $image_path ) ) {
			return new WP_Error(
				'file_not_readable',
				__( 'Image file is not readable. Check file permissions.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Validate PDF input before processing.
	 *
	 * @param string $pdf_path Path to PDF file.
	 * @return true|WP_Error True if valid, WP_Error otherwise.
	 */
	protected function validate_pdf_input( $pdf_path ) {
		// Check file exists.
		if ( ! file_exists( $pdf_path ) ) {
			return new WP_Error(
				'file_not_found',
				__( 'PDF file not found.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 404 )
			);
		}

		// Check file size.
		$file_size = filesize( $pdf_path );
		if ( $file_size > self::MAX_FILE_SIZE ) {
			return new WP_Error(
				'file_too_large',
				sprintf(
					__( 'PDF file is too large (%s). Maximum size is %s.', 'mcp-ai-wpoos-pro' ),
					size_format( $file_size ),
					size_format( self::MAX_FILE_SIZE )
				),
				array( 'status' => 413 )
			);
		}

		// Check MIME type.
		$mime_type = $this->get_mime_type( $pdf_path );
		if ( 'application/pdf' !== $mime_type ) {
			return new WP_Error(
				'invalid_file_type',
				sprintf(
					__( 'Invalid file type: %s. Expected application/pdf', 'mcp-ai-wpoos-pro' ),
					$mime_type
				),
				array( 'status' => 415 )
			);
		}

		// Check file is readable.
		if ( ! is_readable( $pdf_path ) ) {
			return new WP_Error(
				'file_not_readable',
				__( 'PDF file is not readable. Check file permissions.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		return true;
	}

	/**
	 * Check circuit breaker state for a provider.
	 *
	 * Implements circuit breaker pattern to prevent cascading failures.
	 *
	 * @param string $provider Provider name.
	 * @return bool True if circuit is open (provider should be skipped).
	 */
	protected function is_circuit_open( $provider ) {
		if ( ! isset( self::$circuit_breaker[ $provider ] ) ) {
			return false;
		}

		$state = self::$circuit_breaker[ $provider ];
		
		// If circuit is closed, provider is available.
		if ( 'closed' === $state['status'] ) {
			return false;
		}

		// Check if cooldown period has passed.
		if ( time() - $state['opened_at'] > self::CIRCUIT_BREAKER_COOLDOWN ) {
			// Reset circuit to half-open state for testing.
			self::$circuit_breaker[ $provider ]['status'] = 'half-open';
			WP_MCP_AI_Logger::log_event(
				'ocr_circuit_half_open',
				sprintf( 'Circuit breaker entering half-open state for provider: %s', $provider ),
				array( 'provider' => $provider )
			);
			return false;
		}

		return true;
	}

	/**
	 * Open circuit breaker for a provider.
	 *
	 * @param string $provider Provider name.
	 * @param string $reason   Reason for opening circuit.
	 */
	protected function open_circuit( $provider, $reason = '' ) {
		self::$circuit_breaker[ $provider ] = array(
			'status'    => 'open',
			'opened_at' => time(),
			'reason'    => $reason,
		);

		WP_MCP_AI_Logger::log_event(
			'ocr_circuit_opened',
			sprintf( 'Circuit breaker opened for provider: %s', $provider ),
			array(
				'provider' => $provider,
				'reason'   => $reason,
			)
		);
	}

	/**
	 * Close circuit breaker for a provider.
	 *
	 * @param string $provider Provider name.
	 */
	protected function close_circuit( $provider ) {
		// Only reset if circuit exists and was open/half-open.
		$was_open = isset( self::$circuit_breaker[ $provider ] ) &&
					in_array( self::$circuit_breaker[ $provider ]['status'], array( 'open', 'half-open' ), true );

		self::$circuit_breaker[ $provider ] = array(
			'status'    => 'closed',
			'opened_at' => 0,
			'reason'    => '',
		);

		if ( $was_open ) {
			WP_MCP_AI_Logger::log_event(
				'ocr_circuit_closed',
				sprintf( 'Circuit breaker closed for provider: %s (recovered from failure)', $provider ),
				array( 'provider' => $provider )
			);
		}
	}

	/**
	 * Execute operation with retry logic.
	 *
	 * Implements exponential backoff for transient failures.
	 *
	 * @param callable $operation Operation to execute.
	 * @param int      $max_retries Maximum retry attempts.
	 * @return mixed Operation result.
	 */
	protected function execute_with_retry( $operation, $max_retries = self::MAX_RETRIES ) {
		$attempt = 0;
		$last_error = null;

		while ( $attempt < $max_retries ) {
			$result = $operation();

			// Success - return result.
			if ( ! is_wp_error( $result ) ) {
				return $result;
			}

			$last_error = $result;
			$error_code = $result->get_error_code();

			// Don't retry on non-transient errors.
			$non_retryable = array(
				'no_api_key',
				'invalid_file_type',
				'file_not_found',
				'file_too_large',
			);

			if ( in_array( $error_code, $non_retryable, true ) ) {
				return $result;
			}

			$attempt++;

			// Exponential backoff: 1s, 2s, 4s, etc.
			// Only sleep if we have retries remaining.
			if ( $attempt < $max_retries ) {
				$wait_time = pow( 2, $attempt - 1 );
				WP_MCP_AI_Logger::log_event(
					'ocr_retry_attempt',
					sprintf( 'Retrying OCR operation (attempt %d/%d) after %ds', $attempt + 1, $max_retries, $wait_time ),
					array(
						'attempt'    => $attempt + 1,
						'max_retries' => $max_retries,
						'wait_time'  => $wait_time,
						'error'      => $result->get_error_message(),
					)
				);
				sleep( $wait_time );
			}
		}

		// All retries exhausted.
		return $last_error;
	}

	/**
	 * Execute Node.js command with timeout protection.
	 *
	 * Uses proc_open() instead of exec() to prevent infinite hangs.
	 *
	 * @param string $command Command to execute.
	 * @param int    $timeout Timeout in seconds.
	 * @return array Array with 'output', 'return_code', and 'timed_out' keys.
	 */
	protected function execute_node_service_with_timeout( $command, $timeout = 60 ) {
		$descriptors = array(
			0 => array( 'pipe', 'r' ), // stdin
			1 => array( 'pipe', 'w' ), // stdout
			2 => array( 'pipe', 'w' ), // stderr
		);

		$process = proc_open( $command, $descriptors, $pipes );

		if ( ! is_resource( $process ) ) {
			return array(
				'output'      => array(),
				'return_code' => -1,
				'timed_out'   => false,
				'error'       => 'Failed to start process',
			);
		}

		// Close stdin.
		fclose( $pipes[0] );

		// Set non-blocking mode for reading.
		stream_set_blocking( $pipes[1], false );
		stream_set_blocking( $pipes[2], false );

		$output       = '';
		$error_output = '';
		$start_time   = time();
		$timed_out    = false;

		// Read output with timeout.
		while ( true ) {
			$elapsed = time() - $start_time;
			if ( $elapsed >= $timeout ) {
				$timed_out = true;
				// Kill the process.
				proc_terminate( $process, 9 ); // SIGKILL
				WP_MCP_AI_Logger::log_event(
					'node_service_timeout',
					sprintf( 'Node.js service timed out after %d seconds', $timeout ),
					array( 'command' => substr( $command, 0, 200 ) )
				);
				break;
			}

			// Check if process is still running.
			$status = proc_get_status( $process );
			if ( ! $status['running'] ) {
				// Process finished, read any remaining output.
				$output .= stream_get_contents( $pipes[1] );
				$error_output .= stream_get_contents( $pipes[2] );
				break;
			}

			// Read available data.
			$read_output = fread( $pipes[1], 8192 );
			if ( false !== $read_output ) {
				$output .= $read_output;
			}

			$read_error = fread( $pipes[2], 8192 );
			if ( false !== $read_error ) {
				$error_output .= $read_error;
			}

			// Small sleep to prevent busy waiting.
			usleep( 100000 ); // 100ms
		}

		// Close pipes.
		fclose( $pipes[1] );
		fclose( $pipes[2] );

		// Get return code.
		$return_code = proc_close( $process );

		// If timed out, return specific error.
		if ( $timed_out ) {
			$return_code = -1;
		}

		// Combine output.
		$combined_output = trim( $output );
		if ( ! empty( $error_output ) && empty( $combined_output ) ) {
			$combined_output = trim( $error_output );
		}

		return array(
			'output'      => explode( "\n", $combined_output ),
			'return_code' => $return_code,
			'timed_out'   => $timed_out,
			'error'       => trim( $error_output ),
		);
	}

	/**
	 * Get MIME type of file using modern finfo API.
	 *
	 * Replaces deprecated mime_content_type() for PHP 9.0 compatibility.
	 *
	 * @param string $file_path Path to file.
	 * @return string|false MIME type or false on failure.
	 */
	protected function get_mime_type( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}

		// Use WordPress function if available.
		if ( function_exists( 'wp_check_filetype' ) ) {
			$filetype = wp_check_filetype( $file_path );
			if ( ! empty( $filetype['type'] ) ) {
				return $filetype['type'];
			}
		}

		// Fallback to finfo (PHP 8.1+ preferred method).
		if ( class_exists( 'finfo' ) ) {
			$finfo = new \finfo( FILEINFO_MIME_TYPE );
			$mime  = $finfo->file( $file_path );
			if ( false !== $mime ) {
				return $mime;
			}
		}

		// Last resort: try deprecated function if still available.
		if ( function_exists( 'mime_content_type' ) ) {
			return mime_content_type( $file_path );
		}

		return false;
	}
}
