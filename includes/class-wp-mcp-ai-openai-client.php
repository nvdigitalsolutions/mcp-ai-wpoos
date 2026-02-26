<?php
/**
 * OpenAI API client wrapper.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
	/**
	 * Provides a small wrapper around OpenAI's Chat Completions HTTP endpoint.
	 */
	class WP_MCP_AI_OpenAI_Client {
		const CHAT_COMPLETIONS_ENDPOINT     = 'https://api.openai.com/v1/chat/completions';
		const RESPONSES_ENDPOINT            = 'https://api.openai.com/v1/responses';
		const FILES_ENDPOINT                = 'https://api.openai.com/v1/files';
		const AUDIO_SPEECH_ENDPOINT         = 'https://api.openai.com/v1/audio/speech';
		const AUDIO_TRANSCRIPTIONS_ENDPOINT = 'https://api.openai.com/v1/audio/transcriptions';
		const AUDIO_TRANSLATIONS_ENDPOINT   = 'https://api.openai.com/v1/audio/translations';
		const IMAGES_ENDPOINT               = 'https://api.openai.com/v1/images/generations';
		const IMAGES_EDITS_ENDPOINT         = 'https://api.openai.com/v1/images/edits';
		const IMAGES_VARIATIONS_ENDPOINT    = 'https://api.openai.com/v1/images/variations';
		const MODERATIONS_ENDPOINT          = 'https://api.openai.com/v1/moderations';
		const VECTOR_STORES_ENDPOINT        = 'https://api.openai.com/v1/vector_stores';
		const BATCHES_ENDPOINT              = 'https://api.openai.com/v1/batches';
		const CHAT_APPROX_CHARS_PER_TOKEN   = 4; // Heuristic for estimating tokens from character count.

		/**
		 * Determine whether a given image model accepts the response_format parameter.
		 *
		 * @param string $model Image model identifier.
		 * @return bool
		 */
		public static function image_model_supports_response_format( $model ) {
			$model = sanitize_text_field( $model );

			// The gpt-image-1 and gpt-image-1.5 models do NOT support the response_format parameter.
			// Only DALL·E variants (dall-e-2, dall-e-3) support this parameter.
			// Default to true for backward compatibility, but explicitly block gpt-image-1/1.5.
			$supported = true;

			// Check if this is the gpt-image-1 or gpt-image-1.5 model (case-insensitive).
			$model_lower = strtolower( $model );
			if ( 'gpt-image-1' === $model_lower || 'gpt-image-1.5' === $model_lower ) {
				$supported = false;
			}
			/**
			 * Filter whether the supplied image model supports the response_format parameter.
			 *
			 * @param bool   $supported Whether the response_format parameter is supported.
			 * @param string $model     Model identifier.
			 */
			return (bool) apply_filters( 'wp_mcp_ai_image_model_supports_response_format', $supported, $model );
		}

		/**
		 * Determine whether a given image model accepts the style parameter.
		 *
		 * The style parameter (natural or vivid) is only supported by DALL-E 3.
		 * Other models (gpt-image-1, gpt-image-1.5, dall-e-2) do not support this parameter.
		 *
		 * @param string $model Image model identifier.
		 * @return bool
		 */
		public static function image_model_supports_style( $model ) {
			$model = sanitize_text_field( $model );

			// Only DALL-E 3 supports the style parameter.
			$model_lower = strtolower( $model );
			$supported   = ( 'dall-e-3' === $model_lower );

			/**
			 * Filter whether the supplied image model supports the style parameter.
			 *
			 * @param bool   $supported Whether the style parameter is supported.
			 * @param string $model     Model identifier.
			 */
			return (bool) apply_filters( 'wp_mcp_ai_image_model_supports_style', $supported, $model );
		}

		/**
		 * Retrieve the configured API key.
		 *
		 * @return string
		 */
		public function get_api_key() {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			return isset( $settings['openai_api_key'] ) ? $settings['openai_api_key'] : '';
		}

		/**
		 * Upload a file to the OpenAI Files API.
		 *
		 * @param string $file_path Absolute file path on disk.
		 * @param array  $args      Optional arguments (purpose, filename, mime_type, timeout).
		 * @return array|WP_Error
		 */
		public function upload_file( $file_path, array $args = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_path = (string) $file_path;

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_file_upload_missing_file',
					__( 'The file to upload could not be located.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			$purpose   = isset( $args['purpose'] ) ? sanitize_key( $args['purpose'] ) : '';
			$filename  = isset( $args['filename'] ) ? sanitize_file_name( $args['filename'] ) : '';
			$mime_type = isset( $args['mime_type'] ) ? sanitize_mime_type( $args['mime_type'] ) : '';

			if ( '' === $purpose ) {
				$purpose = 'assistants';
			}

			if ( '' === $filename ) {
				$filename = wp_basename( $file_path );
			}

			if ( '' === $mime_type ) {
				$mime_type = 'application/octet-stream';
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

			if ( false === $file_contents ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI file upload failed to read file.',
					array(
						'file_path' => $file_path,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_file_upload_read_failed',
					__( 'The file to upload could not be read.', 'mcp-ai-wpoos' )
				);
			}

			$boundary = 'wp-mcp-ai-' . wp_generate_password( 24, false, false );

			$request_headers = array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
			);

			$request_body = $this->build_multipart_body(
				array( 'purpose' => $purpose ),
				array(
					'name'         => 'file',
					'filename'     => $filename,
					'content_type' => $mime_type,
					'contents'     => $file_contents,
				),
				$boundary
			);

			WP_MCP_AI_Logger::log_event(
				'openai_file_upload',
				'Uploading file to OpenAI.',
				array(
					'purpose'  => $purpose,
					'filename' => $filename,
				)
			);

			$request_args = array(
				'method'  => 'POST',
				'headers' => $request_headers,
				'body'    => $request_body,
				'timeout' => $timeout,
			);

			$response = $this->dispatch_http_request( self::FILES_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI file upload failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_file_upload_http_error',
					__( 'The OpenAI file upload failed to complete.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI file upload response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_file_upload_invalid_response', __( 'OpenAI returned malformed JSON for the file upload.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI file upload returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file upload failed.', 'mcp-ai-wpoos' );

				// Enhance error message with actionable guidance.
				$enhanced_message = $message;
				$error_type       = isset( $decoded['error']['type'] ) ? $decoded['error']['type'] : '';

				// Check for common error patterns and provide helpful guidance.
				$is_invalid_file    = stripos( $message, 'invalid file' ) !== false;
				$is_unsupported     = stripos( $message, 'unsupported' ) !== false;
				$is_invalid_request = stripos( $error_type, 'invalid_request' ) !== false;

				if ( $is_invalid_file || $is_unsupported || $is_invalid_request ) {
					$file_ext = wp_check_filetype( $file_path );
					if ( in_array( strtolower( $file_ext['ext'] ), array( 'csv', 'xlsx', 'xls', 'pptx', 'ppt' ), true ) ) {
						$enhanced_message .= ' ' . sprintf(
							/* translators: %s: file extension */
							__( 'Note: %s files are unreliable for vector stores. Convert to PDF or TXT format first for best results.', 'mcp-ai-wpoos' ),
							strtoupper( $file_ext['ext'] )
						);
					} else {
						$enhanced_message .= ' ' . __( 'Ensure file is in a supported format (PDF, TXT, DOCX, MD, JSON, HTML) and is properly formatted.', 'mcp-ai-wpoos' );
					}
				} elseif ( stripos( $message, 'size' ) !== false || stripos( $message, 'too large' ) !== false ) {
					$enhanced_message .= ' ' . __( 'Try reducing file size by removing images, compressing content, or splitting into smaller files.', 'mcp-ai-wpoos' );
				} elseif ( stripos( $message, 'parse' ) !== false || stripos( $message, 'encoding' ) !== false ) {
					$enhanced_message .= ' ' . __( 'Check file encoding (should be UTF-8) and ensure text is properly formatted.', 'mcp-ai-wpoos' );
				}

				return new WP_Error(
					'wp_mcp_ai_file_upload_error',
					$enhanced_message,
					array(
						'status'           => $code,
						'response'         => $decoded,
						'original_message' => $message,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_file_uploaded',
				'OpenAI file upload completed.',
				array(
					'file_id'  => isset( $decoded['id'] ) ? $decoded['id'] : '',
					'purpose'  => $purpose,
					'filename' => $filename,
				)
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Delete a file from the OpenAI Files API.
		 *
		 * @param string $file_id OpenAI file identifier.
		 * @return array|WP_Error
		 */
		public function delete_file( $file_id ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_id = sanitize_text_field( (string) $file_id );

			if ( '' === $file_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'mcp-ai-wpoos' ) );
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : 0;
			$timeout  = max( 5, $timeout );

			$endpoint = trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( $file_id );

			WP_MCP_AI_Logger::log_event(
				'openai_file_delete',
				'Deleting OpenAI file.',
				array( 'file_id' => $file_id )
			);

			$request_args = array(
				'method'  => 'DELETE',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI file deletion failed.',
					array(
						'file_id' => $file_id,
						'error'   => $response->get_error_message(),
					)
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_file_delete_http_error',
					__( 'The OpenAI file deletion request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI file deletion response.',
					array(
						'file_id' => $file_id,
						'body'    => $body,
					)
				);

				return new WP_Error( 'wp_mcp_ai_file_delete_invalid_response', __( 'OpenAI returned malformed JSON for the file deletion.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI file deletion returned an error.',
					array(
						'file_id' => $file_id,
						'code'    => $code,
						'body'    => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file deletion failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_file_delete_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_file_deleted',
				'OpenAI file deletion completed.',
				array( 'file_id' => $file_id )
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Download a stored file from the OpenAI Files API.
		 *
		 * @param string $file_id OpenAI file identifier.
		 * @param array  $args    Optional arguments (timeout).
		 * @return array|WP_Error Array containing body, headers, and metadata or WP_Error on failure.
		 */
		public function download_file( $file_id, array $args = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_id = sanitize_text_field( (string) $file_id );

			if ( '' === $file_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'mcp-ai-wpoos' ) );
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( $file_id ) . '/content';

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_file_download',
				'Downloading file from OpenAI.',
				array( 'file_id' => $file_id )
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI file download request failed.',
					array(
						'file_id' => $file_id,
						'error'   => $response->get_error_message(),
					)
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The OpenAI file could not be downloaded.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				$decoded = json_decode( $body, true );
				$message = __( 'OpenAI returned an unexpected response while downloading the file.', 'mcp-ai-wpoos' );

				if ( isset( $decoded['error']['message'] ) && is_string( $decoded['error']['message'] ) && '' !== $decoded['error']['message'] ) {
					$message = $decoded['error']['message'];
				}

				WP_MCP_AI_Logger::log_error(
					'OpenAI file download returned an error.',
					array(
						'file_id' => $file_id,
						'status'  => $status_code,
						'body'    => is_array( $decoded ) ? $decoded : $body,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_file_download_failed',
					$message,
					array(
						'status'  => $status_code,
						'file_id' => $file_id,
						'body'    => $decoded,
					)
				);
			}

			if ( '' === $body ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI file download returned an empty body.',
					array( 'file_id' => $file_id )
				);

				return new WP_Error( 'wp_mcp_ai_file_download_empty', __( 'The downloaded OpenAI file was empty.', 'mcp-ai-wpoos' ) );
			}

			$headers = wp_remote_retrieve_headers( $response );
			if ( $headers instanceof WP_HTTP_Headers ) {
				$headers = $headers->getAll();
			}

			$normalised_headers = array();
			if ( is_array( $headers ) ) {
				foreach ( $headers as $key => $value ) {
					$key                        = strtolower( (string) $key );
					$value                      = is_array( $value ) ? implode( ',', $value ) : (string) $value;
					$normalised_headers[ $key ] = $value;
				}
			}

			$content_type = isset( $normalised_headers['content-type'] ) ? $normalised_headers['content-type'] : 'application/octet-stream';
			$filename     = '';

			if ( isset( $normalised_headers['content-disposition'] ) ) {
				$filename = $this->parse_content_disposition_filename( $normalised_headers['content-disposition'] );
			}

			return array(
				'body'         => $body,
				'headers'      => $normalised_headers,
				'content_type' => $content_type,
				'filename'     => $filename,
				'status_code'  => $status_code,
				'file_id'      => $file_id,
			);
		}

		/**
		 * List files from the OpenAI Files API.
		 *
		 * @param array $args Optional arguments (purpose, limit, order, after, timeout).
		 * @return array|WP_Error Array containing files list or WP_Error on failure.
		 */
		public function list_files( array $args = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			// Build query parameters.
			$query_params = array();

			if ( isset( $args['purpose'] ) && '' !== $args['purpose'] ) {
				$query_params['purpose'] = sanitize_key( $args['purpose'] );
			}

			if ( isset( $args['limit'] ) && '' !== $args['limit'] ) {
				$query_params['limit'] = absint( $args['limit'] );
			}

			if ( isset( $args['order'] ) && '' !== $args['order'] ) {
				$query_params['order'] = in_array( $args['order'], array( 'asc', 'desc' ), true ) ? $args['order'] : 'desc';
			}

			if ( isset( $args['after'] ) && '' !== $args['after'] ) {
				$query_params['after'] = sanitize_text_field( $args['after'] );
			}

			$endpoint = self::FILES_ENDPOINT;
			if ( ! empty( $query_params ) ) {
				$endpoint = add_query_arg( $query_params, $endpoint );
			}

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_list_files',
				'Listing files from OpenAI.',
				$query_params
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI list files request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_list_files_http_error',
					__( 'The OpenAI files list request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI list files response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_list_files_invalid_response', __( 'OpenAI returned malformed JSON for the files list.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI list files returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI files list request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_list_files_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_files_listed',
				'OpenAI files list retrieved successfully.',
				array(
					'count' => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? count( $decoded['data'] ) : 0,
				)
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Retrieve metadata for a file from the OpenAI Files API.
		 *
		 * @param string $file_id OpenAI file identifier.
		 * @param array  $args    Optional arguments (timeout).
		 * @return array|WP_Error Array containing file metadata or WP_Error on failure.
		 */
		public function retrieve_file( $file_id, array $args = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_id = sanitize_text_field( (string) $file_id );

			if ( '' === $file_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_file_id', __( 'A file identifier must be supplied.', 'mcp-ai-wpoos' ) );
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = trailingslashit( self::FILES_ENDPOINT ) . rawurlencode( $file_id );

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_retrieve_file',
				'Retrieving file metadata from OpenAI.',
				array( 'file_id' => $file_id )
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI retrieve file request failed.',
					array(
						'file_id' => $file_id,
						'error'   => $response->get_error_message(),
					)
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_retrieve_file_http_error',
					__( 'The OpenAI file metadata request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI retrieve file response.',
					array(
						'file_id' => $file_id,
						'body'    => $body,
					)
				);

				return new WP_Error( 'wp_mcp_ai_retrieve_file_invalid_response', __( 'OpenAI returned malformed JSON for the file metadata.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI retrieve file returned an error.',
					array(
						'file_id' => $file_id,
						'code'    => $code,
						'body'    => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI file metadata request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_retrieve_file_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_file_retrieved',
				'OpenAI file metadata retrieved successfully.',
				array( 'file_id' => $file_id )
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * List available models from OpenAI.
		 *
		 * @param array $args Optional arguments (timeout, bypass_cache).
		 * @return array|WP_Error Array containing models list or WP_Error on failure.
		 */
		public function list_models( array $args = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Check if caching is enabled.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$use_cache    = ! empty( $settings['enable_openai_api_caching'] );
			$bypass_cache = isset( $args['bypass_cache'] ) && $args['bypass_cache'];

			// Allow disabling via constant.
			if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
				$use_cache = false;
			}

			/**
			* Filter whether to cache OpenAI model list requests.
			*
			* @param bool  $use_cache Whether to use caching.
			* @param array $args      Request arguments.
			*/
			$use_cache = apply_filters( 'wp_mcp_ai_cache_openai_models', $use_cache, $args );

			if ( $use_cache && ! $bypass_cache ) {
				$cache_key = 'openai_models_list';

				// Get cache TTL from settings or use default (12 hours).
				$cache_ttl = isset( $settings['openai_model_list_cache_ttl'] ) ? absint( $settings['openai_model_list_cache_ttl'] ) : 12 * HOUR_IN_SECONDS;

				/**
				* Filter the cache TTL for OpenAI model list.
				*
				* @param int $cache_ttl Cache TTL in seconds.
				*/
				$cache_ttl = apply_filters( 'wp_mcp_ai_openai_model_list_ttl', $cache_ttl );

				return WP_MCP_AI_Cache_Helper::remember(
					$cache_key,
					function () use ( $api_key, $args ) {
						return $this->fetch_models_from_api( $api_key, $args );
					},
					$cache_ttl
				);
			}

			return $this->fetch_models_from_api( $api_key, $args );
		}

		/**
		 * Fetch models from OpenAI API (internal method).
		 *
		 * @param string $api_key OpenAI API key.
		 * @param array  $args    Optional arguments.
		 * @return array|WP_Error Array of models or WP_Error on failure.
		 */
		private function fetch_models_from_api( $api_key, $args ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = 'https://api.openai.com/v1/models';

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event( 'openai_list_models', 'Listing models from OpenAI.' );

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI list models request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_list_models_http_error',
					__( 'The OpenAI models list request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI list models response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_list_models_invalid_response', __( 'OpenAI returned malformed JSON for the models list.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI list models returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI models list request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_list_models_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_models_listed',
				'OpenAI models list retrieved successfully.',
				array(
					'count' => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? count( $decoded['data'] ) : 0,
				)
			);

			return is_array( $decoded ) ? $decoded : array();
		}


		/**
		 * Retrieve information about a specific model.
		 *
		 * @param string $model_id Model identifier (e.g., 'gpt-4').
		 * @param array  $args     Optional arguments (timeout).
		 * @return array|WP_Error Array containing model information or WP_Error on failure.
		 */
		public function get_model( $model_id, array $args = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$model_id = sanitize_text_field( (string) $model_id );

			if ( '' === $model_id ) {
				return new WP_Error( 'wp_mcp_ai_missing_model_id', __( 'A model identifier must be supplied.', 'mcp-ai-wpoos' ) );
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $args['timeout'] ) && '' !== $args['timeout'] ? absint( $args['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = 'https://api.openai.com/v1/models/' . rawurlencode( $model_id );

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_get_model',
				'Retrieving model information from OpenAI.',
				array( 'model_id' => $model_id )
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI get model request failed.',
					array(
						'model_id' => $model_id,
						'error'    => $response->get_error_message(),
					)
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_get_model_http_error',
					__( 'The OpenAI model information request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI get model response.',
					array(
						'model_id' => $model_id,
						'body'     => $body,
					)
				);

				return new WP_Error( 'wp_mcp_ai_get_model_invalid_response', __( 'OpenAI returned malformed JSON for the model information.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI get model returned an error.',
					array(
						'model_id' => $model_id,
						'code'     => $code,
						'body'     => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI model information request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_get_model_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_model_retrieved',
				'OpenAI model information retrieved successfully.',
				array( 'model_id' => $model_id )
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Generate embeddings for text input.
		 *
		 * @param string|array $input   Text string or array of strings to embed.
		 * @param array        $options Optional parameters (model, encoding_format, dimensions, user, timeout, bypass_cache).
		 * @return array|WP_Error Array containing embeddings or WP_Error on failure.
		 */
		public function create_embeddings( $input, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( empty( $input ) || ( is_string( $input ) && '' === trim( $input ) ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_input', __( 'Input text must be provided for embeddings.', 'mcp-ai-wpoos' ) );
			}

			// Check if caching is enabled.
			$settings     = WP_MCP_AI_Admin_Settings::get_settings();
			$use_cache    = ! empty( $settings['enable_openai_api_caching'] );
			$bypass_cache = isset( $options['bypass_cache'] ) && $options['bypass_cache'];

			// Allow disabling via constant.
			if ( defined( 'WP_MCP_AI_DISABLE_API_CACHE' ) && WP_MCP_AI_DISABLE_API_CACHE ) {
				$use_cache = false;
			}

			/**
			 * Filter whether to cache OpenAI embeddings requests.
			 *
			 * @param bool         $use_cache Whether to use caching.
			 * @param string|array $input     Input text.
			 * @param array        $options   Request options.
			 */
			$use_cache = apply_filters( 'wp_mcp_ai_cache_openai_embeddings', $use_cache, $input, $options );

			if ( $use_cache && ! $bypass_cache ) {
				// Build cache key from input and relevant options.
				$model           = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'text-embedding-3-small';
				$encoding_format = isset( $options['encoding_format'] ) && '' !== $options['encoding_format'] ? sanitize_text_field( $options['encoding_format'] ) : '';
				$dimensions      = isset( $options['dimensions'] ) && '' !== $options['dimensions'] ? absint( $options['dimensions'] ) : 0;

				$cache_key_data = array(
					'input'           => $input,
					'model'           => $model,
					'encoding_format' => $encoding_format,
					'dimensions'      => $dimensions,
				);

				$cache_key = 'openai_embedding_' . md5( wp_json_encode( $cache_key_data ) );

				// Get cache TTL from settings or use default (24 hours).
				$cache_ttl = isset( $settings['openai_embedding_cache_ttl'] ) ? absint( $settings['openai_embedding_cache_ttl'] ) : 24 * HOUR_IN_SECONDS;

				/**
				 * Filter the cache TTL for OpenAI embeddings.
				 *
				 * @param int   $cache_ttl Cache TTL in seconds.
				 * @param array $options   Request options.
				 */
				$cache_ttl = apply_filters( 'wp_mcp_ai_openai_embedding_ttl', $cache_ttl, $options );

				return WP_MCP_AI_Cache_Helper::remember(
					$cache_key,
					function () use ( $api_key, $input, $options ) {
						return $this->fetch_embeddings_from_api( $api_key, $input, $options );
					},
					$cache_ttl
				);
			}

			return $this->fetch_embeddings_from_api( $api_key, $input, $options );
		}

		/**
		 * Fetch embeddings from OpenAI API (internal method).
		 *
		 * @param string       $api_key OpenAI API key.
		 * @param string|array $input   Text input.
		 * @param array        $options Optional parameters.
		 * @return array|WP_Error Array of embeddings or WP_Error on failure.
		 */
		private function fetch_embeddings_from_api( $api_key, $input, $options ) {
			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			// Default model.
			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'text-embedding-3-small';

			$payload = array(
				'model' => $model,
				'input' => $input,
			);

			// Optional parameters.
			if ( isset( $options['encoding_format'] ) && '' !== $options['encoding_format'] ) {
				$payload['encoding_format'] = sanitize_text_field( $options['encoding_format'] );
			}

			if ( isset( $options['dimensions'] ) && '' !== $options['dimensions'] ) {
				$payload['dimensions'] = absint( $options['dimensions'] );
			}

			if ( isset( $options['user'] ) && '' !== $options['user'] ) {
				$payload['user'] = sanitize_text_field( $options['user'] );
			}

			$endpoint = 'https://api.openai.com/v1/embeddings';

			$request_args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_create_embeddings',
				'Creating embeddings with OpenAI.',
				array(
					'model'        => $model,
					'input_type'   => is_array( $input ) ? 'array' : 'string',
					'input_length' => is_array( $input ) ? count( $input ) : strlen( $input ),
				)
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI embeddings request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_embeddings_http_error',
					__( 'The OpenAI embeddings request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI embeddings response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_embeddings_invalid_response', __( 'OpenAI returned malformed JSON for the embeddings.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI embeddings returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI embeddings request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_embeddings_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_embeddings_created',
				'OpenAI embeddings created successfully.',
				array(
					'model'            => $model,
					'embeddings_count' => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? count( $decoded['data'] ) : 0,
				)
			);

			return is_array( $decoded ) ? $decoded : array();
		}


		/**
		 * Moderate content using OpenAI's Moderation API.
		 *
		 * Analyzes text and/or image inputs for potentially harmful content across multiple
		 * violation categories including sexual, hate, harassment, self-harm, and violence.
		 *
		 * @since 1.0.0
		 *
		 * @param string|array $input   Content to moderate. Can be a string, array of strings,
		 *                               or for multimodal: array of input objects with text/image.
		 * @param array        $options Optional configuration:
		 *                               - model: 'omni-moderation-latest' (default) or 'text-moderation-latest'
		 *                               - timeout: Request timeout in seconds (default: from settings).
		 * @return array|WP_Error Array containing moderation results or WP_Error on failure.
		 *                        Result structure:
		 *                        - id: Unique moderation request ID
		 *                        - model: Model used
		 *                        - results: Array of result objects, each containing:
		 *                          - flagged: Boolean indicating if content was flagged
		 *                          - categories: Object mapping category names to booleans
		 *                          - category_scores: Object mapping category names to confidence scores (0-1)
		 *                          - category_applied_input_types: Which input types triggered each category
		 */
		public function moderate_content( $input, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Validate input.
			if ( empty( $input ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_input',
					__( 'Input content must be provided for moderation.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Validate string input is not empty.
			if ( is_string( $input ) && '' === trim( $input ) ) {
				return new WP_Error(
					'wp_mcp_ai_empty_input',
					__( 'Input text cannot be empty.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			// Default to latest omni-moderation model (supports text + images).
			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'omni-moderation-latest';

			$payload = array(
				'model' => $model,
				'input' => $input,
			);

			$request_args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_moderate_content',
				'Moderating content with OpenAI.',
				array(
					'model'        => $model,
					'input_type'   => is_array( $input ) ? 'array' : 'string',
					'input_length' => is_array( $input ) ? count( $input ) : strlen( $input ),
				)
			);

			$response = $this->dispatch_http_request( self::MODERATIONS_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI moderation request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_moderation_http_error',
					__( 'The OpenAI moderation request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI moderation response.',
					array( 'body' => $body )
				);

				return new WP_Error(
					'wp_mcp_ai_moderation_invalid_response',
					__( 'OpenAI returned malformed JSON for the moderation.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI moderation returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI moderation request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_moderation_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			// Extract violation summary for logging.
			$flagged_count = 0;
			$flagged_cats  = array();
			$results       = isset( $decoded['results'] ) && is_array( $decoded['results'] ) ? $decoded['results'] : array();

			foreach ( $results as $result ) {
				if ( isset( $result['flagged'] ) && $result['flagged'] ) {
					++$flagged_count;

					if ( isset( $result['categories'] ) && is_array( $result['categories'] ) ) {
						foreach ( $result['categories'] as $category => $is_flagged ) {
							if ( $is_flagged && ! in_array( $category, $flagged_cats, true ) ) {
								$flagged_cats[] = $category;
							}
						}
					}
				}
			}

			WP_MCP_AI_Logger::log_event(
				'openai_moderation_completed',
				'OpenAI moderation completed successfully.',
				array(
					'model'              => $model,
					'results_count'      => count( $results ),
					'flagged_count'      => $flagged_count,
					'flagged_categories' => $flagged_cats,
				)
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Dispatch an HTTP request while honouring preemptive short-circuit filters.
		 *
		 * @param string $url  Target URL.
		 * @param array  $args Request arguments.
		 * @return array|WP_Error
		 */
		protected function dispatch_http_request( $url, array $args ) {
			$url  = esc_url_raw( $url );
			$args = apply_filters( 'http_request_args', $args, $url );

			$preempt = $this->maybe_preempt_http_request( $url, $args );
			if ( null !== $preempt ) {
				return $preempt;
			}

			$method = isset( $args['method'] ) ? strtoupper( $args['method'] ) : 'POST';

			if ( 'POST' === $method ) {
				return wp_remote_post( $url, $args );
			}

			return wp_remote_request( $url, $args );
		}

		/**
		 * Build a multipart/form-data request body for a file upload.
		 *
		 * @param array  $fields   Associative array of scalar form fields.
		 * @param array  $file     File payload arguments (name, filename, content_type, contents).
		 * @param string $boundary Multipart boundary token.
		 * @return string
		 */
		protected function build_multipart_body( array $fields, array $file, $boundary ) {
			$eol      = "\r\n";
			$boundary = (string) $boundary;
			$body     = '';

			foreach ( $fields as $name => $value ) {
				$name = (string) $name;

				// Handle array values by JSON-encoding them for OpenAI API compatibility.
				if ( is_array( $value ) ) {
					$value = wp_json_encode( $value );
				} else {
					$value = (string) $value;
				}

				$body .= '--' . $boundary . $eol;
				$body .= 'Content-Disposition: form-data; name="' . $name . '"' . $eol . $eol;
				$body .= $value . $eol;
			}

			if ( isset( $file['contents'] ) && '' !== $file['contents'] ) {
				$field_name   = isset( $file['name'] ) ? (string) $file['name'] : 'file';
				$filename     = isset( $file['filename'] ) ? (string) $file['filename'] : 'file';
				$content_type = isset( $file['content_type'] ) && '' !== $file['content_type'] ? (string) $file['content_type'] : 'application/octet-stream';
				$contents     = (string) $file['contents'];

				$body .= '--' . $boundary . $eol;
				$body .= 'Content-Disposition: form-data; name="' . $field_name . '"; filename="' . $filename . '"' . $eol;
				$body .= 'Content-Type: ' . $content_type . $eol . $eol;
				$body .= $contents . $eol;
			}

			$body .= '--' . $boundary . '--' . $eol;

			return $body;
		}

		/**
		 * Execute the `pre_http_request` filters and return the first short-circuit response.
		 *
		 * @param string $url  Target URL.
		 * @param array  $args Prepared request arguments.
		 * @return array|WP_Error|null
		 */
		protected function maybe_preempt_http_request( $url, array $args ) {
			if ( ! isset( $GLOBALS['wp_filter']['pre_http_request'] ) ) {
				return null;
			}

			$hook = $GLOBALS['wp_filter']['pre_http_request'];
			if ( ! $hook instanceof WP_Hook ) {
				return null;
			}

			$pre = false;

			foreach ( $hook->callbacks as $priority => $callbacks ) {
				foreach ( $callbacks as $callback ) {
					$accepted_args = isset( $callback['accepted_args'] ) ? (int) $callback['accepted_args'] : 1;
					$params        = array();

					if ( $accepted_args >= 1 ) {
						$params[] = $pre;
					}

					if ( $accepted_args >= 2 ) {
						$params[] = $args;
					}

					if ( $accepted_args >= 3 ) {
						$params[] = $url;
					}

					$result = call_user_func_array( $callback['function'], $params );

					if ( false !== $result ) {
						return $result;
					}

					$pre = $result;
				}
			}

			return null;
		}

		/**
		 * Generate an image using the OpenAI Images API.
		 *
		 * @param string $prompt  Text prompt describing the desired image.
		 * @param array  $options Optional overrides (model, size, quality, format, timeout).
		 * @return array|WP_Error Array containing the image payload and metadata or WP_Error on failure.
		 */
		public function generate_image( $prompt, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$prompt = sanitize_textarea_field( $prompt );

			if ( '' === $prompt ) {
				return new WP_Error(
					'wp_mcp_ai_missing_image_prompt',
					__( 'A text prompt must be supplied to generate an image.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$default_model           = isset( $settings['openai_image_model'] ) && '' !== $settings['openai_image_model'] ? sanitize_text_field( $settings['openai_image_model'] ) : 'gpt-image-1.5';
			$default_size            = isset( $settings['openai_image_size'] ) && '' !== $settings['openai_image_size'] ? sanitize_text_field( $settings['openai_image_size'] ) : '1024x1024';
			$default_response_format = isset( $settings['openai_image_response_format'] ) && '' !== $settings['openai_image_response_format'] ? sanitize_key( $settings['openai_image_response_format'] ) : 'b64_json';

			// Determine model-specific default quality.
			// gpt-image-1/1.5 models default to 'medium', DALL-E models default to 'standard'.
			$model_for_quality = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
			$model_for_quality = strtolower( $model_for_quality );
			$default_quality   = ( 'gpt-image-1' === $model_for_quality || 'gpt-image-1.5' === $model_for_quality ) ? 'medium' : 'standard';

			// Allow settings to override if a valid quality is configured.
			if ( isset( $settings['openai_image_quality'] ) && '' !== $settings['openai_image_quality'] ) {
				$default_quality = sanitize_key( $settings['openai_image_quality'] );
			}

			if ( ! in_array( $default_response_format, array( 'b64_json', 'url' ), true ) ) {
				$default_response_format = 'b64_json';
			}

			$model   = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
			$size    = isset( $options['size'] ) && '' !== $options['size'] ? sanitize_text_field( $options['size'] ) : $default_size;
			$quality = isset( $options['quality'] ) && '' !== $options['quality'] ? sanitize_key( $options['quality'] ) : $default_quality;

			// Normalize quality values based on the model being used.
			// Different OpenAI image models accept different quality parameter values:
			// - gpt-image-1/1.5 accept: 'low', 'medium', 'high', 'auto'
			// - DALL-E 2/3 accept: 'standard', 'hd'
			$model_lower = strtolower( $model );
			$is_gpt_image_model = ( 'gpt-image-1' === $model_lower || 'gpt-image-1.5' === $model_lower );

			if ( $is_gpt_image_model ) {
				// For gpt-image models, validate and use quality values directly.
				$valid_gpt_qualities = array( 'low', 'medium', 'high', 'auto' );
				
				// If quality is a DALL-E value, map it to gpt-image values.
				if ( 'standard' === $quality ) {
					$quality = 'medium';
				} elseif ( 'hd' === $quality ) {
					$quality = 'high';
				} elseif ( ! in_array( $quality, $valid_gpt_qualities, true ) ) {
					// Default to 'medium' if not a valid gpt-image quality value.
					$quality = 'medium';
				}
			} else {
				// For DALL-E models, map quality values to 'standard' or 'hd'.
				$quality_map = array(
					'low'    => 'standard',
					'medium' => 'standard',
					'auto'   => 'standard',
					'high'   => 'hd',
				);

				if ( isset( $quality_map[ $quality ] ) ) {
					$quality = $quality_map[ $quality ];
				} elseif ( ! in_array( $quality, array( 'standard', 'hd' ), true ) ) {
					// Default to 'standard' if not a valid DALL-E quality value.
					$quality = 'standard';
				}
			}

			$requested_format = isset( $options['format'] ) && '' !== $options['format'] ? sanitize_key( $options['format'] ) : 'png';
			$response_format  = isset( $options['response_format'] ) && '' !== $options['response_format'] ? sanitize_key( $options['response_format'] ) : $default_response_format;

			$model_supports_response_format = self::image_model_supports_response_format( $model );

			if ( ! in_array( $response_format, array( 'b64_json', 'url' ), true ) ) {
				$response_format = $default_response_format;
			}

			if ( ! $model_supports_response_format ) {
				$response_format = 'b64_json';
			}

			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout = max( 5, $timeout );

			$payload = array(
				'model'   => $model,
				'prompt'  => $prompt,
				'size'    => $size,
				'quality' => $quality,
				'n'       => 1,
			);

			// Add style parameter only for models that support it (DALL-E 3).
			if ( isset( $options['style'] ) && '' !== $options['style'] ) {
				$style = sanitize_key( $options['style'] );
				if ( in_array( $style, array( 'natural', 'vivid' ), true ) && self::image_model_supports_style( $model ) ) {
					$payload['style'] = $style;
				}
			}

			if ( $model_supports_response_format && '' !== $response_format ) {
				$payload['response_format'] = $response_format;
			}

			/**
			 * Allow third parties to filter the OpenAI image payload prior to dispatch.
			 *
			 * @param array $payload Prepared request payload.
			 * @param array $options Original method options.
			 */
			$payload = apply_filters( 'wp_mcp_ai_openai_image_payload', $payload, $options );

			$encoded_payload = wp_json_encode( $payload );
			if ( false === $encoded_payload ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the OpenAI request payload.', 'mcp-ai-wpoos' ) );
			}

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => $timeout,
				'body'    => $encoded_payload,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_image_request',
				'Sending image generation request to OpenAI.',
				array(
					'model'            => $model,
					'size'             => $size,
					'quality'          => $quality,
					'requested_format' => $requested_format,
					'response_format'  => $response_format,
				)
			);

			$response = wp_remote_post( self::IMAGES_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI image request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The OpenAI API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$status_code  = wp_remote_retrieve_response_code( $response );
			$body         = wp_remote_retrieve_body( $response );
			$headers      = wp_remote_retrieve_headers( $response );
			$content_type = $this->extract_content_type( $headers );

			if ( $status_code < 200 || $status_code >= 300 ) {
				$decoded    = json_decode( $body, true );
				$json_error = json_last_error();

				if ( JSON_ERROR_NONE === $json_error && isset( $decoded['error']['message'] ) ) {
					$message = $decoded['error']['message'];
				} else {
					/* translators: %d: HTTP status code */
					$message = sprintf( __( 'The OpenAI image request failed with status %d.', 'mcp-ai-wpoos' ), $status_code );
				}

				WP_MCP_AI_Logger::log_error(
					'OpenAI image request returned an error.',
					array(
						'code'        => $status_code,
						'message'     => $message,
						'body'        => JSON_ERROR_NONE === $json_error ? $decoded : $body,
						'contentType' => $content_type,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_image_error',
					$message,
					array(
						'status'   => $status_code,
						'response' => JSON_ERROR_NONE === $json_error ? $decoded : array( 'body' => $body ),
					)
				);
			}

			$is_json_response  = $this->is_json_content_type( $content_type );
			$decoded           = array();
			$image_data        = '';
			$response_mime     = '';
			$response_format   = '';
			$response_created  = 0;
			$response_model    = $model;
			$response_revision = '';

			if ( $is_json_response || '' === $content_type ) {
				$decoded    = json_decode( $body, true );
				$json_error = json_last_error();

				if ( JSON_ERROR_NONE !== $json_error ) {
					// Not JSON, fall through to binary detection below.
					$decoded = array();
				}
			}

			if ( ! empty( $decoded ) ) {
				if ( empty( $decoded['data'] ) || empty( $decoded['data'][0] ) || ! is_array( $decoded['data'][0] ) ) {
					WP_MCP_AI_Logger::log_error( 'OpenAI image response missing payload data.', array( 'response' => $decoded ) );

					return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'mcp-ai-wpoos' ) );
				}

				$image_response = $decoded['data'][0];

				if ( isset( $image_response['b64_json'] ) && '' !== $image_response['b64_json'] ) {
					$image_data = base64_decode( $image_response['b64_json'], true );

					if ( false === $image_data ) {
						WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI image payload.', array( 'response' => $decoded ) );

						return new WP_Error( 'wp_mcp_ai_image_decode_error', __( 'OpenAI returned an invalid image payload.', 'mcp-ai-wpoos' ) );
					}

					$response_format = $this->detect_format_from_binary( $image_data );
					if ( '' === $response_format ) {
						$response_format = 'png';
					}
					$response_mime = $this->normalise_image_mime_type( $response_format );
				} elseif ( isset( $image_response['url'] ) && '' !== $image_response['url'] ) {
					$image_url = esc_url_raw( $image_response['url'] );

					if ( '' === $image_url ) {
						WP_MCP_AI_Logger::log_error( 'OpenAI image response returned an invalid URL.', array( 'url' => $image_response['url'] ) );

						return new WP_Error( 'wp_mcp_ai_image_invalid_url', __( 'OpenAI returned an invalid image URL.', 'mcp-ai-wpoos' ) );
					}

					$downloaded_image = $this->download_image_from_url( $image_url, $timeout );

					if ( is_wp_error( $downloaded_image ) ) {
						return $downloaded_image;
					}

					$image_data      = $downloaded_image['body'];
					$content_type    = $downloaded_image['content_type'];
					$response_format = $this->detect_format_from_mime_type( $content_type );

					if ( '' === $response_format ) {
						$response_format = $this->detect_format_from_binary( $image_data );
					}

					if ( '' === $response_format ) {
						$response_format = 'png';
					}

					$response_mime = '' !== $content_type ? $content_type : $this->normalise_image_mime_type( $response_format );
				} else {
					WP_MCP_AI_Logger::log_error( 'OpenAI image response missing supported payload keys.', array( 'response' => $decoded ) );

					return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'mcp-ai-wpoos' ) );
				}

				if ( '' === $image_data ) {
					WP_MCP_AI_Logger::log_error( 'OpenAI image response contained no data.', array( 'response' => $decoded ) );

					return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'mcp-ai-wpoos' ) );
				}

				$response_created  = isset( $decoded['created'] ) ? intval( $decoded['created'] ) : 0;
				$response_model    = isset( $decoded['model'] ) ? sanitize_text_field( $decoded['model'] ) : $model;
				$response_revision = isset( $image_response['revised_prompt'] ) ? (string) $image_response['revised_prompt'] : '';
			} elseif ( $this->is_image_content_type( $content_type ) || 'application/octet-stream' === $content_type ) {
				$image_data = $body;

				if ( '' === $image_data ) {
					WP_MCP_AI_Logger::log_error( 'OpenAI image response contained no data.', array( 'contentType' => $content_type ) );

					return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'mcp-ai-wpoos' ) );
				}

				$response_format = $this->detect_format_from_mime_type( $content_type );

				if ( '' === $response_format ) {
					$response_format = $this->detect_format_from_binary( $image_data );
				}

				if ( '' === $response_format ) {
					$response_format = 'png';
				}

				$response_mime = '' !== $content_type ? $content_type : $this->normalise_image_mime_type( $response_format );
			} else {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI image response.',
					array(
						'body'        => $body,
						'contentType' => $content_type,
					)
				);

				return new WP_Error( 'wp_mcp_ai_image_invalid_response', __( 'OpenAI returned an unexpected image response format.', 'mcp-ai-wpoos' ) );
			}

			$result = array(
				'image'          => $image_data,
				'format'         => $response_format,
				'mime_type'      => $response_mime,
				'model'          => $response_model,
				'prompt'         => $prompt,
				'size'           => $size,
				'quality'        => $quality,
				'created'        => $response_created,
				'revised_prompt' => $response_revision,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_image_response',
				'OpenAI image generation completed.',
				array(
					'model'   => $response_model,
					'size'    => $size,
					'quality' => $quality,
					'format'  => $response_format,
				)
			);

			return $result;
		}

		/**
		 * Download an image from a remote URL.
		 *
		 * @param string $url     Image URL.
		 * @param int    $timeout Request timeout.
		 * @return array|WP_Error Array containing body and content_type on success.
		 */
		protected function download_image_from_url( $url, $timeout ) {
			$request_args = array(
				'timeout' => max( 5, absint( $timeout ) ),
			);

			$response = wp_remote_get( $url, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to download image from OpenAI URL.',
					array(
						'url'   => $url,
						'error' => $response->get_error_message(),
					)
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_image_download_http_error',
					__( 'The generated image could not be downloaded from OpenAI.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );
			$headers     = wp_remote_retrieve_headers( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI image download returned an error.',
					array(
						'url'    => $url,
						'code'   => $status_code,
						'body'   => $body,
						'header' => $headers,
					)
				);

				return new WP_Error(
					'wp_mcp_ai_image_download_error',
					/* translators: %d: HTTP status code */
					sprintf( __( 'OpenAI returned an HTTP %d while downloading the generated image.', 'mcp-ai-wpoos' ), $status_code ),
					array(
						'status'   => $status_code,
						'response' => $body,
					)
				);
			}

			if ( '' === $body ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI image download returned empty content.', array( 'url' => $url ) );

				return new WP_Error( 'wp_mcp_ai_image_empty', __( 'OpenAI returned an empty image response.', 'mcp-ai-wpoos' ) );
			}

			return array(
				'body'         => $body,
				'content_type' => $this->extract_content_type( $headers ),
			);
		}

		/**
		 * Extract a filename from a Content-Disposition header when available.
		 *
		 * @param string $header Raw Content-Disposition header value.
		 * @return string Sanitised filename or empty string when unavailable.
		 */
		protected function parse_content_disposition_filename( $header ) {
			$header = (string) $header;

			if ( '' === $header ) {
				return '';
			}

			$filename = '';

			if ( preg_match( "/filename\\*=([^\\s]+)''([^;]+)/i", $header, $matches ) ) {
				$filename = rawurldecode( $matches[2] );
			} elseif ( preg_match( '/filename="?([^";]+)"?/i', $header, $matches ) ) {
				$filename = $matches[1];
			}

			$filename = trim( wp_strip_all_tags( (string) $filename ) );

			if ( '' === $filename ) {
				return '';
			}

			return sanitize_file_name( $filename );
		}

		/**
		 * Normalise the image MIME type based on the requested format.
		 *
		 * @param string $format Requested format.
		 * @return string
		 */
		protected function normalise_image_mime_type( $format ) {
			$format = sanitize_key( $format );

			switch ( $format ) {
				case 'jpeg':
				case 'jpg':
					return 'image/jpeg';
				case 'webp':
					return 'image/webp';
				case 'png':
				default:
					return 'image/png';
			}
		}

		/**
		 * Extract the content type header from an HTTP response.
		 *
		 * @param array|ArrayAccess $headers Response headers.
		 * @return string
		 */
		protected function extract_content_type( $headers ) {
			if ( empty( $headers ) ) {
				return '';
			}

			$content_type = '';

			if ( is_array( $headers ) ) {
				$content_type = isset( $headers['content-type'] ) ? $headers['content-type'] : '';
			} elseif ( is_object( $headers ) && $headers instanceof ArrayAccess ) {
				if ( $headers->offsetExists( 'content-type' ) ) {
					$content_type = $headers->offsetGet( 'content-type' );
				}
			} elseif ( is_object( $headers ) && method_exists( $headers, 'getAll' ) ) {
				$all_headers = $headers->getAll();
				if ( isset( $all_headers['content-type'] ) ) {
					$content_type = $all_headers['content-type'];
				}
			}

			if ( is_array( $content_type ) ) {
				$content_type = reset( $content_type );
			}

			$content_type = explode( ';', (string) $content_type );
			$content_type = trim( strtolower( $content_type[0] ) );

			return $content_type;
		}

		/**
		 * Determine whether the provided content type represents JSON.
		 *
		 * @param string $content_type Header value.
		 * @return bool
		 */
		protected function is_json_content_type( $content_type ) {
			if ( '' === $content_type ) {
				return false;
			}

			return false !== strpos( $content_type, 'application/json' ) || false !== strpos( $content_type, 'text/json' );
		}

		/**
		 * Determine whether the provided content type represents an image payload.
		 *
		 * @param string $content_type Header value.
		 * @return bool
		 */
		protected function is_image_content_type( $content_type ) {
			if ( '' === $content_type ) {
				return false;
			}

			return 0 === strpos( $content_type, 'image/' );
		}

		/**
		 * Attempt to detect an image format from a MIME type.
		 *
		 * @param string $mime_type MIME type string.
		 * @return string
		 */
		protected function detect_format_from_mime_type( $mime_type ) {
			$mime_type = strtolower( (string) $mime_type );

			switch ( $mime_type ) {
				case 'image/jpeg':
				case 'image/jpg':
					return 'jpeg';
				case 'image/webp':
					return 'webp';
				case 'image/png':
					return 'png';
				default:
					return '';
			}
		}

		/**
		 * Attempt to detect an image format from raw binary data.
		 *
		 * @param string $image_data Raw image bytes.
		 * @return string
		 */
		protected function detect_format_from_binary( $image_data ) {
			if ( '' === $image_data ) {
				return '';
			}

			if ( function_exists( 'getimagesizefromstring' ) ) {
				$details = @getimagesizefromstring( $image_data ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

				if ( is_array( $details ) && ! empty( $details['mime'] ) ) {
					$format = $this->detect_format_from_mime_type( $details['mime'] );

					if ( '' !== $format ) {
						return $format;
					}
				}
			}

			if ( function_exists( 'finfo_buffer' ) && defined( 'FILEINFO_MIME_TYPE' ) && class_exists( 'finfo' ) ) {
				$finfo = new finfo( FILEINFO_MIME_TYPE );
				$mime  = $finfo->buffer( $image_data );

				if ( $mime ) {
					$format = $this->detect_format_from_mime_type( $mime );

					if ( '' !== $format ) {
						return $format;
					}
				}
			}

			$signature = substr( $image_data, 0, 12 );

			if ( 0 === strncmp( $signature, "\x89PNG", 4 ) ) {
				return 'png';
			}

			if ( 0 === strncmp( $signature, "\xFF\xD8\xFF", 3 ) ) {
				return 'jpeg';
			}

			if ( 0 === strncmp( $signature, 'RIFF', 4 ) && 0 === strncmp( substr( $signature, 8, 4 ), 'WEBP', 4 ) ) {
				return 'webp';
			}

			return '';
		}

		/**
		 * Edit an image using OpenAI's image editing API (DALL-E).
		 *
		 * @param string $image_path Path to the image file to edit.
		 * @param string $prompt     Description of desired edits.
		 * @param array  $options    Optional parameters (mask_path, model, n, size, response_format, timeout).
		 * @return array|WP_Error    Array containing the edited image data or WP_Error on failure.
		 */
		public function edit_image( $image_path, $prompt, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$image_path = (string) $image_path;
			if ( '' === $image_path || ! file_exists( $image_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_image_not_found',
					__( 'The image file to edit could not be located.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			$prompt = sanitize_textarea_field( $prompt );
			if ( '' === $prompt ) {
				return new WP_Error(
					'wp_mcp_ai_missing_image_prompt',
					__( 'A text prompt must be supplied to edit the image.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 30, $timeout ); // Image editing needs more time.

			$model           = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'dall-e-2';
			$n               = isset( $options['n'] ) ? absint( $options['n'] ) : 1;
			$n               = max( 1, min( 10, $n ) );
			$size            = isset( $options['size'] ) && '' !== $options['size'] ? sanitize_text_field( $options['size'] ) : '1024x1024';
			$response_format = isset( $options['response_format'] ) && '' !== $options['response_format'] ? sanitize_key( $options['response_format'] ) : 'b64_json';

			if ( ! in_array( $response_format, array( 'b64_json', 'url' ), true ) ) {
				$response_format = 'b64_json';
			}

			// Prepare multipart form data.
			$boundary = wp_generate_password( 24, false );
			$body     = '';

			// Add image file.
			$image_filename  = basename( $image_path );
			$image_mime_type = mime_content_type( $image_path );
			$image_data      = file_get_contents( $image_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"image\"; filename=\"{$image_filename}\"\r\n";
			$body .= "Content-Type: {$image_mime_type}\r\n\r\n";
			$body .= $image_data . "\r\n";

			// Add mask file if provided.
			if ( isset( $options['mask_path'] ) && '' !== $options['mask_path'] && file_exists( $options['mask_path'] ) ) {
				$mask_filename  = basename( $options['mask_path'] );
				$mask_mime_type = mime_content_type( $options['mask_path'] );
				$mask_data      = file_get_contents( $options['mask_path'] ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

				$body .= "--{$boundary}\r\n";
				$body .= "Content-Disposition: form-data; name=\"mask\"; filename=\"{$mask_filename}\"\r\n";
				$body .= "Content-Type: {$mask_mime_type}\r\n\r\n";
				$body .= $mask_data . "\r\n";
			}

			// Add other parameters.
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"prompt\"\r\n\r\n";
			$body .= $prompt . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
			$body .= $model . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"n\"\r\n\r\n";
			$body .= $n . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"size\"\r\n\r\n";
			$body .= $size . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n";
			$body .= $response_format . "\r\n";

			$body .= "--{$boundary}--\r\n";

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'timeout' => $timeout,
				'body'    => $body,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_image_edit_request',
				'Sending image edit request to OpenAI.',
				array(
					'model' => $model,
					'size'  => $size,
					'n'     => $n,
				)
			);

			$response = wp_remote_post( self::IMAGES_EDITS_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_event( 'openai_image_edit_error', 'Image edit request failed.', array( 'error' => $response->get_error_message() ) );
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI image edit request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				WP_MCP_AI_Logger::log_event(
					'openai_image_edit_error',
					'Image edit failed with HTTP code ' . $http_code,
					array( 'response' => $decoded )
				);

				return new WP_Error( 'wp_mcp_ai_openai_image_edit_error', $error_message, array( 'status' => $http_code ) );
			}

			if ( ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid response from OpenAI image edit API.', 'mcp-ai-wpoos' ) );
			}

			WP_MCP_AI_Logger::log_event( 'openai_image_edit_success', 'Image edit completed successfully.' );

			return array(
				'success' => true,
				'data'    => $decoded['data'],
				'created' => isset( $decoded['created'] ) ? $decoded['created'] : time(),
			);
		}

		/**
		 * Create variations of an existing image using OpenAI's API (DALL-E).
		 *
		 * @param string $image_path Path to the image file.
		 * @param array  $options    Optional parameters (model, n, size, response_format, timeout).
		 * @return array|WP_Error    Array containing the image variations or WP_Error on failure.
		 */
		public function create_image_variation( $image_path, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$image_path = (string) $image_path;
			if ( '' === $image_path || ! file_exists( $image_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_image_not_found',
					__( 'The image file could not be located.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 30, $timeout ); // Image generation needs more time.

			$model           = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'dall-e-2';
			$n               = isset( $options['n'] ) ? absint( $options['n'] ) : 1;
			$n               = max( 1, min( 10, $n ) );
			$size            = isset( $options['size'] ) && '' !== $options['size'] ? sanitize_text_field( $options['size'] ) : '1024x1024';
			$response_format = isset( $options['response_format'] ) && '' !== $options['response_format'] ? sanitize_key( $options['response_format'] ) : 'b64_json';

			if ( ! in_array( $response_format, array( 'b64_json', 'url' ), true ) ) {
				$response_format = 'b64_json';
			}

			// Prepare multipart form data.
			$boundary = wp_generate_password( 24, false );
			$body     = '';

			// Add image file.
			$image_filename  = basename( $image_path );
			$image_mime_type = mime_content_type( $image_path );
			$image_data      = file_get_contents( $image_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"image\"; filename=\"{$image_filename}\"\r\n";
			$body .= "Content-Type: {$image_mime_type}\r\n\r\n";
			$body .= $image_data . "\r\n";

			// Add other parameters.
			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"model\"\r\n\r\n";
			$body .= $model . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"n\"\r\n\r\n";
			$body .= $n . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"size\"\r\n\r\n";
			$body .= $size . "\r\n";

			$body .= "--{$boundary}\r\n";
			$body .= "Content-Disposition: form-data; name=\"response_format\"\r\n\r\n";
			$body .= $response_format . "\r\n";

			$body .= "--{$boundary}--\r\n";

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'timeout' => $timeout,
				'body'    => $body,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_image_variation_request',
				'Sending image variation request to OpenAI.',
				array(
					'model' => $model,
					'size'  => $size,
					'n'     => $n,
				)
			);

			$response = wp_remote_post( self::IMAGES_VARIATIONS_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_event( 'openai_image_variation_error', 'Image variation request failed.', array( 'error' => $response->get_error_message() ) );
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI image variation request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				WP_MCP_AI_Logger::log_event(
					'openai_image_variation_error',
					'Image variation failed with HTTP code ' . $http_code,
					array( 'response' => $decoded )
				);

				return new WP_Error( 'wp_mcp_ai_openai_image_variation_error', $error_message, array( 'status' => $http_code ) );
			}

			if ( ! isset( $decoded['data'] ) || ! is_array( $decoded['data'] ) ) {
				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'Invalid response from OpenAI image variation API.', 'mcp-ai-wpoos' ) );
			}

			WP_MCP_AI_Logger::log_event( 'openai_image_variation_success', 'Image variation completed successfully.' );

			return array(
				'success' => true,
				'data'    => $decoded['data'],
				'created' => isset( $decoded['created'] ) ? $decoded['created'] : time(),
			);
		}

		/**
		 * Generate speech audio from text using the OpenAI Text-to-Speech API.
		 *
		 * @param string $input   Text that should be converted to speech.
		 * @param array  $options Optional overrides (model, voice, format, speed, timeout).
		 * @return array|WP_Error Array containing the audio payload and metadata or WP_Error on failure.
		 */
		public function generate_speech( $input, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$input = sanitize_textarea_field( $input );

			if ( '' === $input ) {
				return new WP_Error(
					'wp_mcp_ai_missing_speech_input',
					__( 'A text prompt must be supplied to generate speech.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$default_model  = ! empty( $settings['openai_speech_model'] ) ? sanitize_text_field( $settings['openai_speech_model'] ) : 'tts-1';
			$default_voice  = isset( $settings['openai_speech_voice'] ) ? sanitize_key( $settings['openai_speech_voice'] ) : 'alloy';
			$default_format = isset( $settings['openai_speech_format'] ) ? sanitize_key( $settings['openai_speech_format'] ) : 'mp3';

			if ( '' === $default_voice ) {
				$default_voice = 'alloy';
			}

			if ( '' === $default_format ) {
				$default_format = 'mp3';
			}

			if ( '' === $default_model ) {
				$default_model = 'tts-1';
			}

			$model   = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : $default_model;
			$voice   = isset( $options['voice'] ) && '' !== $options['voice'] ? sanitize_key( $options['voice'] ) : $default_voice;
			$format  = isset( $options['format'] ) && '' !== $options['format'] ? sanitize_key( $options['format'] ) : $default_format;
			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout = max( 5, $timeout );

			$payload = array(
				'model'  => $model,
				'input'  => $input,
				'voice'  => $voice,
				'format' => $format,
			);

			if ( isset( $options['speed'] ) && '' !== $options['speed'] ) {
				$speed            = floatval( $options['speed'] );
				$speed            = max( 0.25, min( 4, $speed ) );
				$payload['speed'] = $speed;
			} else {
				$speed = null;
			}

			/**
			 * Allow third parties to filter the OpenAI speech payload prior to dispatch.
			 *
			 * @param array $payload Prepared request payload.
			 * @param array $options Original method options.
			 */
			$payload = apply_filters( 'wp_mcp_ai_openai_speech_payload', $payload, $options );

			$encoded_payload = wp_json_encode( $payload );
			if ( false === $encoded_payload ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the OpenAI request payload.', 'mcp-ai-wpoos' ) );
			}

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'timeout' => $timeout,
				'body'    => $encoded_payload,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_tts_request',
				'Sending text-to-speech request to OpenAI.',
				array(
					'model'  => $model,
					'voice'  => $voice,
					'format' => $format,
					'speed'  => $speed,
				)
			);

			$response = wp_remote_post( self::AUDIO_SPEECH_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI text-to-speech request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The OpenAI API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				$decoded = json_decode( $body, true );
				$error   = json_last_error();

				if ( JSON_ERROR_NONE === $error && isset( $decoded['error']['message'] ) ) {
					$message = $decoded['error']['message'];
				} else {
					$message = __( 'Unexpected response from OpenAI.', 'mcp-ai-wpoos' );
				}

				WP_MCP_AI_Logger::log_error(
					'OpenAI text-to-speech request returned an error.',
					array(
						'status'   => $status_code,
						'response' => JSON_ERROR_NONE === $error ? $decoded : $body,
					)
				);

				return new WP_Error( 'wp_mcp_ai_api_error', $message, array( 'status' => $status_code ) );
			}

			if ( '' === $body ) {
				return new WP_Error( 'wp_mcp_ai_empty_audio', __( 'OpenAI returned an empty audio response.', 'mcp-ai-wpoos' ) );
			}

			$headers = wp_remote_retrieve_headers( $response );
			$type    = isset( $headers['content-type'] ) ? sanitize_text_field( $headers['content-type'] ) : '';

			return array(
				'audio'        => $body,
				'format'       => $format,
				'model'        => $model,
				'voice'        => $voice,
				'speed'        => $speed,
				'content_type' => $type,
			);
		}

		/**
		 * Transcribe or translate an audio file using the OpenAI Audio API.
		 *
		 * @param string $file_path Absolute path to the audio file on disk.
		 * @param array  $options   Optional overrides (model, translate, prompt, temperature, response_format, timeout, language, filename, mime_type).
		 * @return array|WP_Error   Array containing the transcription payload or WP_Error on failure.
		 */
		public function transcribe_audio( $file_path, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$file_path = (string) $file_path;

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_transcription_missing_file',
					__( 'The audio file to transcribe could not be located.', 'mcp-ai-wpoos' ),
					array( 'status' => 404 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();

			$model = isset( $options['model'] ) && '' !== $options['model'] ? sanitize_text_field( $options['model'] ) : 'whisper-1';
			if ( '' === $model ) {
				$model = 'whisper-1';
			}

			$translate = false;

			if ( isset( $options['translate'] ) ) {
				$translate = (bool) $options['translate'];
			}

			if ( isset( $options['task'] ) ) {
				$task = strtolower( sanitize_text_field( $options['task'] ) );

				if ( 'translate' === $task ) {
					$translate = true;
				} elseif ( 'transcribe' === $task ) {
					$translate = false;
				}
			}

			$timeout = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout = max( 5, $timeout );

			$response_format = isset( $options['response_format'] ) && '' !== $options['response_format'] ? strtolower( sanitize_key( $options['response_format'] ) ) : 'verbose_json';
			$allowed_formats = array( 'json', 'verbose_json', 'text', 'srt', 'vtt' );

			if ( ! in_array( $response_format, $allowed_formats, true ) ) {
				$response_format = 'verbose_json';
			}

			$fields = array(
				'model'           => $model,
				'response_format' => $response_format,
			);

			if ( isset( $options['prompt'] ) && '' !== $options['prompt'] ) {
				$fields['prompt'] = sanitize_textarea_field( $options['prompt'] );
			}

			if ( isset( $options['temperature'] ) && '' !== $options['temperature'] ) {
				$temperature           = floatval( $options['temperature'] );
				$temperature           = max( 0, min( 1, $temperature ) );
				$fields['temperature'] = $temperature;
			}

			if ( ! $translate && isset( $options['language'] ) && '' !== $options['language'] ) {
				$fields['language'] = sanitize_text_field( $options['language'] );
			}

			// Add timestamp_granularities parameter for word or segment level timestamps.
			if ( isset( $options['timestamp_granularities'] ) ) {
				$granularities = $options['timestamp_granularities'];
				if ( is_string( $granularities ) ) {
					$granularities = array( $granularities );
				}
				if ( is_array( $granularities ) && ! empty( $granularities ) ) {
					$valid_granularities = array();
					foreach ( $granularities as $granularity ) {
						$granularity = strtolower( sanitize_key( $granularity ) );
						if ( in_array( $granularity, array( 'word', 'segment' ), true ) ) {
							$valid_granularities[] = $granularity;
						}
					}
					if ( ! empty( $valid_granularities ) ) {
						$fields['timestamp_granularities'] = array_values( array_unique( $valid_granularities ) );
					}
				}
			}

			$filename = isset( $options['filename'] ) && '' !== $options['filename'] ? sanitize_file_name( $options['filename'] ) : wp_basename( $file_path );

			$mime_type = isset( $options['mime_type'] ) && '' !== $options['mime_type'] ? sanitize_mime_type( $options['mime_type'] ) : '';

			if ( '' === $mime_type ) {
				$filetype = wp_check_filetype( $filename );
				if ( $filetype && ! empty( $filetype['type'] ) ) {
					$mime_type = $filetype['type'];
				}
			}

			if ( '' === $mime_type ) {
				$mime_type = 'application/octet-stream';
			}

			$file_contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_read_readfile

			if ( false === $file_contents ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI audio transcription failed to read file.',
					array( 'file_path' => $file_path )
				);

				return new WP_Error(
					'wp_mcp_ai_transcription_read_failed',
					__( 'The audio file could not be read from disk.', 'mcp-ai-wpoos' )
				);
			}

			$boundary = 'wp-mcp-ai-' . wp_generate_password( 24, false, false );

			$request_body = $this->build_multipart_body(
				$fields,
				array(
					'name'         => 'file',
					'filename'     => $filename,
					'content_type' => $mime_type,
					'contents'     => $file_contents,
				),
				$boundary
			);

			$request_args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'multipart/form-data; boundary=' . $boundary,
				),
				'timeout' => $timeout,
				'body'    => $request_body,
			);

			$endpoint = $translate ? self::AUDIO_TRANSLATIONS_ENDPOINT : self::AUDIO_TRANSCRIPTIONS_ENDPOINT;

			WP_MCP_AI_Logger::log_event(
				'openai_audio_transcription_request',
				'Sending audio transcription request to OpenAI.',
				array(
					'model'           => $model,
					'translate'       => $translate,
					'response_format' => $response_format,
					'filename'        => $filename,
				)
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI audio transcription request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The OpenAI API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$status_code = wp_remote_retrieve_response_code( $response );
			$body        = wp_remote_retrieve_body( $response );

			if ( $status_code < 200 || $status_code >= 300 ) {
				$decoded = json_decode( $body, true );
				$error   = json_last_error();

				if ( JSON_ERROR_NONE === $error && isset( $decoded['error']['message'] ) ) {
					$message = $decoded['error']['message'];
				} else {
					$message = __( 'Unexpected response from OpenAI.', 'mcp-ai-wpoos' );
				}

				WP_MCP_AI_Logger::log_error(
					'OpenAI audio transcription request returned an error.',
					array(
						'status'   => $status_code,
						'response' => JSON_ERROR_NONE === $error ? $decoded : $body,
					)
				);

				return new WP_Error( 'wp_mcp_ai_api_error', $message, array( 'status' => $status_code ) );
			}

			$decoded = json_decode( $body, true );
			$error   = json_last_error();

			if ( JSON_ERROR_NONE !== $error || ! is_array( $decoded ) ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI audio transcription response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_transcription_invalid_response', __( 'OpenAI returned malformed JSON for the audio transcription.', 'mcp-ai-wpoos' ) );
			}

			$text = isset( $decoded['text'] ) ? (string) $decoded['text'] : '';

			if ( '' === $text ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI audio transcription response did not include text.', array( 'response' => $decoded ) );

				return new WP_Error( 'wp_mcp_ai_transcription_empty_text', __( 'OpenAI did not return any transcription text.', 'mcp-ai-wpoos' ) );
			}

			$result = array(
				'text'       => $text,
				'model'      => $model,
				'translated' => $translate,
				'format'     => $response_format,
			);

			if ( isset( $decoded['language'] ) ) {
				$result['language'] = (string) $decoded['language'];
			}

			if ( isset( $decoded['duration'] ) && '' !== $decoded['duration'] ) {
				$result['duration'] = floatval( $decoded['duration'] );
			}

			if ( isset( $decoded['segments'] ) && is_array( $decoded['segments'] ) ) {
				$result['segments'] = $decoded['segments'];
			}

			return $result;
		}

		/**
		 * Perform a chat completion request.
		 *
		 * @param array $messages Message payload to send to OpenAI.
		 * @param array $options  Additional options (model, temperature, tools, timeout).
		 * @return array|WP_Error
		 */
		public function create_chat_completion( array $messages, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$settings        = WP_MCP_AI_Admin_Settings::get_settings();
			$model           = ! empty( $options['model'] ) ? $options['model'] : $settings['default_model'];
			$resource_mgr    = WP_MCP_AI_Resource_Manager::instance();
			$default_timeout = ! empty( $settings['request_timeout'] ) ? absint( $settings['request_timeout'] ) : $resource_mgr->get_request_timeout();
			$timeout         = ! empty( $options['timeout'] ) ? absint( $options['timeout'] ) : $default_timeout;
			$timeout         = max( 5, $timeout );
			$attachments     = $this->extract_attachments_from_options( $options );
			$payload         = array(
				'model' => $model,
			);

			if ( ! empty( $attachments ) ) {
				$options['attachments'] = $attachments;
			}

			$messages = $this->filter_tool_messages_for_payload( $messages );

			$should_use_responses_api = $this->should_use_responses_api( $messages, $options );

			// Use Responses API as a backup when conversation is clean (no tool calls).
			// This allows non-image attachments to work when the conversation doesn't require tool support.
			// If there are tool calls in the conversation, stick with Chat Completions API.

			// which supports tool_calls/tool_call_id mechanism.
			if ( ! empty( $attachments ) && ! $should_use_responses_api && ! $this->has_tool_calls_in_messages( $messages ) ) {
				// For non-image documents without tool calls, use Responses API.

				if ( ! $this->are_all_attachments_images( $attachments ) ) {
					$should_use_responses_api = true;
				}
				// For images without tool calls, we can use either API.

				// Prefer Chat Completions API to maintain consistency and allow tools to work.

			}

			$chat_messages     = $this->normalise_messages_for_payload( $messages );
			$attachment_lookup = array();

			if ( $should_use_responses_api ) {
				$attachment_lookup = $this->index_attachments_by_id( $attachments );
				$payload['input']  = $this->prepare_responses_input( $messages, $chat_messages, $attachments );
			} else {
				// When using Chat Completions API, convert input_image segments to image_url format.

				// This is necessary because Chat Completions API doesn't support input_image type.

				if ( ! empty( $attachments ) && $this->are_all_attachments_images( $attachments ) ) {
					$attachment_lookup = $this->index_attachments_by_id( $attachments );
				}
				// Always run conversion to handle input_image segments from conversation history.

				$chat_messages       = $this->convert_image_files_to_image_url( $chat_messages, $attachment_lookup );
				$payload['messages'] = $chat_messages;
			}

			$message_key = $should_use_responses_api ? 'input' : 'messages';

			if ( empty( $payload[ $message_key ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_messages',
					__( 'No chat messages were provided for the request.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'review_request_payload' => __( 'Provide at least one user or system message before calling the API.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			if ( isset( $options['temperature'] ) && null !== $options['temperature'] && '' !== $options['temperature'] ) {
				$temperature            = floatval( $options['temperature'] );
				$payload['temperature'] = max( 0, min( 2, $temperature ) );
			}

			$system_messages = array();

			if ( ! empty( $options['system_prompt'] ) ) {
				$system_messages[] = array(
					'role'    => 'system',
					'content' => array(
						array(
							'type' => 'text',
							'text' => (string) $options['system_prompt'],
						),
					),
				);
			}

			$memory_messages = $this->build_memory_messages_from_options( $options );

			if ( ! empty( $memory_messages ) ) {
				$system_messages = array_merge( $system_messages, $memory_messages );
			}

			if ( ! empty( $system_messages ) ) {
				if ( $should_use_responses_api ) {
					foreach ( $system_messages as &$system_message ) {
						if ( ! isset( $system_message['content'] ) ) {
							continue;
						}

						if ( is_array( $system_message['content'] ) ) {
							$system_message['content'] = $this->normalise_responses_content_segments( $system_message['content'], $attachment_lookup, isset( $system_message['role'] ) ? $system_message['role'] : 'system' );
						} else {
							$system_message['content'] = $this->normalise_responses_content_segments(
								array(
									array(
										'type' => 'text',
										'text' => (string) $system_message['content'],
									),
								),
								$attachment_lookup,
								isset( $system_message['role'] ) ? $system_message['role'] : 'system'
							);
						}
					}
					unset( $system_message );
				}

				$payload[ $message_key ] = array_merge( $system_messages, $payload[ $message_key ] );
			}

			if ( ! empty( $options['tools'] ) ) {
				$payload['tools'] = $this->normalise_tools_for_payload( $options['tools'] );
			}

			if ( ! empty( $options['response_format'] ) && is_array( $options['response_format'] ) ) {
				$payload['response_format'] = $options['response_format'];
			}

			// Apply resource-aware max_tokens if not explicitly set.
			if ( ! isset( $options['max_tokens'] ) && ! isset( $options['max_completion_tokens'] ) && ! isset( $options['max_output_tokens'] ) ) {
				$max_tokens = $resource_mgr->get_max_tokens();

				/**
				 * Filter the maximum tokens for OpenAI requests.
				 *
				 * @param int   $max_tokens The maximum tokens to use.
				 * @param array $options    Request options.
				 */
				$max_tokens = apply_filters( 'wp_mcp_ai_openai_max_tokens', $max_tokens, $options );

				if ( $max_tokens > 0 ) {
					// Use max_output_tokens for Responses API, max_completion_tokens for Chat Completions.
					if ( $should_use_responses_api ) {
						$payload['max_output_tokens'] = $max_tokens;
					} else {
						$payload['max_completion_tokens'] = $max_tokens;
					}
				}
			} elseif ( isset( $options['max_tokens'] ) ) {
				$payload['max_tokens'] = absint( $options['max_tokens'] );
			} elseif ( isset( $options['max_output_tokens'] ) ) {
				// Responses API uses max_output_tokens.
				$payload['max_output_tokens'] = absint( $options['max_output_tokens'] );
			} elseif ( isset( $options['max_completion_tokens'] ) ) {
				// Chat Completions API uses max_completion_tokens.
				// When using Responses API, convert to max_output_tokens.
				if ( $should_use_responses_api ) {
					$payload['max_output_tokens'] = absint( $options['max_completion_tokens'] );
				} else {
					$payload['max_completion_tokens'] = absint( $options['max_completion_tokens'] );
				}
			}

			$request_headers = array(
				'Authorization' => 'Bearer ' . $api_key,
				'Content-Type'  => 'application/json',
			);

			if ( $should_use_responses_api ) {
				$request_headers['OpenAI-Beta'] = 'responses=v1';
			}

			$request_args = array(
				'headers' => $request_headers,
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event( 'openai_request', 'Sending request to OpenAI.', array( 'payload' => $this->obfuscate_request_for_log( $payload ) ) );

			$endpoint = $should_use_responses_api ? self::RESPONSES_ENDPOINT : self::CHAT_COMPLETIONS_ENDPOINT;
			$response = wp_remote_post( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error( 'OpenAI request failed.', array( 'error' => $response->get_error_message() ) );

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_http_error',
					__( 'The OpenAI API request failed to complete.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code     = wp_remote_retrieve_response_code( $response );
			$body     = wp_remote_retrieve_body( $response );
			$decoded  = json_decode( $body, true );
			$json_err = json_last_error();

			if ( JSON_ERROR_NONE !== $json_err ) {
				WP_MCP_AI_Logger::log_error( 'Failed to decode OpenAI response.', array( 'body' => $body ) );

				return new WP_Error( 'wp_mcp_ai_invalid_response', __( 'The OpenAI API returned malformed JSON.', 'mcp-ai-wpoos' ) );
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI returned an error response.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'Unexpected response from OpenAI.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_api_error',
					$message,
					array(
						'status' => $code,
						'body'   => $decoded,
					)
				);
			}

			if ( $should_use_responses_api ) {
				$decoded = $this->convert_responses_result_to_chat_completion( $decoded );
			}

			if ( is_array( $decoded ) ) {
				if ( ! isset( $decoded['provider'] ) ) {
					$decoded['provider'] = 'openai';
				}

				if ( ! isset( $decoded['model'] ) && ! empty( $model ) ) {
					$decoded['model'] = $model;
				}
			}

			WP_MCP_AI_Logger::log_event( 'openai_response', 'OpenAI request completed.', array( 'response' => $decoded ) );

			return $decoded;
		}

		/**
		 * Prepare chat messages for the OpenAI Chat Completions payload.
		 *
		 * The REST layer represents text-only messages as arrays of segments so
		 * attachments and tool calls can be normalised consistently. Older OpenAI
		 * models (for example, gpt-3.5-turbo) only accept plain strings for the
		 * `content` field which causes those requests to fail. To remain compatible
		 * we collapse text-only segment arrays back into strings while preserving
		 * multimodal payloads that rely on structured segments.
		 *
		 * @param array $messages Sanitised chat messages.
		 * @return array
		 */
		protected function normalise_messages_for_payload( array $messages ) {
			$normalised = array();

			foreach ( $messages as $message ) {
				if ( ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
					$normalised[] = $message;
					continue;
				}

				$segments = array_values( $message['content'] );

				if ( empty( $segments ) ) {
					$message['content'] = '';
					$normalised[]       = $message;
					continue;
				}

				$all_text   = true;
				$text_parts = array();

				foreach ( $segments as $segment ) {
					if ( ! is_array( $segment ) ) {
						$all_text = false;
						break;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

					if ( 'text' !== $type ) {
						$all_text = false;
						break;
					}

					$text_parts[] = isset( $segment['text'] ) ? (string) $segment['text'] : '';
				}

				if ( $all_text ) {
					$text_parts         = array_filter(
						$text_parts,
						static function ( $part ) {
							return '' !== trim( $part );
						}
					);
					$message['content'] = implode( "\n\n", $text_parts );
				} else {
					$message['content'] = $segments;
				}

				$normalised[] = $message;
			}

			return $normalised;
		}

		/**
		 * Drop tool role messages that are not associated with the most recent assistant tool call.
		 *
		 * OpenAI requires tool responses to immediately follow the assistant message that emitted the
		 * corresponding tool call. When intervening messages appear between those entries the request
		 * is rejected with an "Invalid parameter" error. This normaliser filters out any tool messages
		 * that no longer have a matching pending call so the payload remains valid.
		 *
		 * @param array $messages Chat history supplied by the caller.
		 * @return array
		 */
		protected function filter_tool_messages_for_payload( array $messages ) {
			if ( empty( $messages ) ) {
				return $messages;
			}

			$filtered                = array();
			$pending_calls           = array();
			$awaiting_tool_responses = false;

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

				if ( '' === $role ) {
					continue;
				}

				if ( in_array( $role, array( 'system', 'user' ), true ) ) {
					$pending_calls           = array();
					$awaiting_tool_responses = false;
					$filtered[]              = $message;
					continue;
				}

				if ( 'assistant' === $role ) {
					$pending_calls           = array();
					$awaiting_tool_responses = false;

					if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
						foreach ( $message['tool_calls'] as $tool_call ) {
							if ( ! is_array( $tool_call ) ) {
								continue;
							}

							$call_id = isset( $tool_call['id'] ) ? sanitize_text_field( (string) $tool_call['id'] ) : '';

							if ( '' === $call_id ) {
								continue;
							}

							$pending_calls[ $call_id ] = true;
						}
					}

					if ( ! empty( $pending_calls ) ) {
						$awaiting_tool_responses = true;
					}

					$filtered[] = $message;
					continue;
				}

				if ( 'tool' === $role ) {
					$tool_call_id = isset( $message['tool_call_id'] ) ? sanitize_text_field( (string) $message['tool_call_id'] ) : '';

					if ( '' === $tool_call_id || ! $awaiting_tool_responses || ! isset( $pending_calls[ $tool_call_id ] ) ) {
						WP_MCP_AI_Logger::log_event(
							'dropped_tool_message',
							'Dropping tool message without matching tool call before OpenAI request.',
							array(
								'tool_call_id' => $tool_call_id,
								'reason'       => '' === $tool_call_id ? 'missing_tool_call_id' : ( $awaiting_tool_responses ? 'tool_call_not_found' : 'no_pending_tool_calls' ),
							)
						);

						continue;
					}

					unset( $pending_calls[ $tool_call_id ] );

					if ( empty( $pending_calls ) ) {
						$awaiting_tool_responses = false;
					}

					$filtered[] = $message;
					continue;
				}

				$pending_calls           = array();
				$awaiting_tool_responses = false;
				$filtered[]              = $message;
			}

			return array_values( $filtered );
		}

		/**
		 * Build additional system messages from memory documents.
		 *
		 * @param array $options Chat request options.
		 * @return array
		 */
		protected function build_memory_messages_from_options( array $options ) {
			if ( empty( $options['memory_documents'] ) || ! is_array( $options['memory_documents'] ) ) {
				return array();
			}

			$messages = array();

			foreach ( $options['memory_documents'] as $document ) {
				if ( empty( $document['chunks'] ) || ! is_array( $document['chunks'] ) ) {
					continue;
				}

				$title      = isset( $document['title'] ) && '' !== $document['title'] ? $document['title'] : __( 'Document', 'mcp-ai-wpoos' );
				$chunks     = array_values( array_filter( array_map( 'strval', $document['chunks'] ) ) );
				$parts      = count( $chunks );
				$part_index = 0;

				foreach ( $chunks as $chunk ) {
					++$part_index;

					$label = $title;

					if ( $parts > 1 ) {
						/* translators: %1$s: document title, %2$d: chunk number. */
						$label = sprintf( __( '%1$s (Part %2$d)', 'mcp-ai-wpoos' ), $title, $part_index );
					}

					$messages[] = array(
						'role'    => 'system',
						'content' => array(
							array(
								'type' => 'text',
								/* translators: %1$s: document title, %2$s: extracted text snippet. */
								'text' => sprintf( __( 'Reference document "%1$s": %2$s', 'mcp-ai-wpoos' ), $label, $chunk ),
							),
						),
					);
				}
			}

			return $messages;
		}

		/**
		 * Normalise tool definitions to satisfy the OpenAI payload schema.
		 *
		 * @param array $tools Tool definitions sourced from the REST layer.
		 * @return array
		 */
		protected function normalise_tools_for_payload( $tools ) {
			if ( $tools instanceof \Traversable ) {
				$tools = iterator_to_array( $tools );
			}

			if ( is_object( $tools ) ) {
				$tools = (array) $tools;
			}

			if ( ! is_array( $tools ) ) {
				return array();
			}

			$normalised = array();

			foreach ( $tools as $tool ) {
				if ( $tool instanceof \Traversable ) {
					$tool = iterator_to_array( $tool );
				}

				if ( is_object( $tool ) ) {
					$tool = (array) $tool;
				}

				if ( ! is_array( $tool ) || empty( $tool ) ) {
					continue;
				}

				$type = isset( $tool['type'] ) ? sanitize_key( $tool['type'] ) : '';

				if ( 'function' === $type ) {
					if ( isset( $tool['function'] ) && is_array( $tool['function'] ) ) {
						if ( isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
							$tool['name'] = (string) $tool['function']['name'];
						}
					}
				}

				if ( ! isset( $tool['name'] ) || '' === $tool['name'] ) {
					if ( isset( $tool['function'] ) && is_array( $tool['function'] ) && isset( $tool['function']['name'] ) && '' !== $tool['function']['name'] ) {
						$tool['name'] = (string) $tool['function']['name'];
					} elseif ( isset( $tool['slug'] ) && '' !== $tool['slug'] ) {
						$tool['name'] = (string) $tool['slug'];
					} elseif ( isset( $tool['id'] ) && '' !== $tool['id'] ) {
						$tool['name'] = (string) $tool['id'];
					}
				}

				if ( ! isset( $tool['name'] ) || '' === trim( (string) $tool['name'] ) ) {
					continue;
				}

				$tool['name'] = (string) $tool['name'];

				// Sanitize the parameters schema to ensure OpenAI compatibility.
				if ( isset( $tool['function'] ) && is_array( $tool['function'] ) && isset( $tool['function']['parameters'] ) && is_array( $tool['function']['parameters'] ) ) {
					$tool['function']['parameters'] = $this->sanitize_parameters_for_openai( $tool['function']['parameters'] );
				} elseif ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
					$tool['parameters'] = $this->sanitize_parameters_for_openai( $tool['parameters'] );
				}

				$normalised[] = $tool;
			}

			return array_values( $normalised );
		}

		/**
		 * Sanitize parameter schema to satisfy OpenAI function calling requirements.
		 *
		 * OpenAI requires that function parameters schema:
		 * - Must have type 'object' at the root level
		 * - Cannot use 'oneOf', 'anyOf', 'allOf', or 'not' at the root level
		 * - However, these composition keywords ARE allowed in nested property definitions
		 *
		 * This method removes unsupported composition keywords at the root level only,
		 * while preserving all property definitions and nested composition keywords.
		 * Validation logic should be handled in the tool's execute() method.
		 *
		 * @param array  $schema     JSON Schema object to sanitize.
		 * @param string $parent_key The parent key to determine context (internal use).
		 * @return array Sanitized schema compatible with OpenAI API.
		 */
		protected function sanitize_parameters_for_openai( array $schema, $parent_key = '' ) {
			$sanitized = array();

			// At the root level (empty parent_key), remove composition keywords.
			// These are not allowed at the top level of OpenAI function parameters.
			// Note: Composition keywords ARE allowed in nested property schemas.
			// For example: "field": {"anyOf": [{"type": "string"}, {"type": "number"}]} is valid.
			if ( '' === $parent_key ) {
				$root_unsupported = array( 'oneOf', 'anyOf', 'allOf', 'not' );
				foreach ( $root_unsupported as $keyword ) {
					if ( isset( $schema[ $keyword ] ) ) {
						// Log the removal for debugging.
						WP_MCP_AI_Logger::log_event(
							'openai_schema_sanitization',
							"Removed unsupported top-level keyword: {$keyword}",
							array(
								'keyword' => $keyword,
								'context' => 'root_level',
							)
						);
						unset( $schema[ $keyword ] );
					}
				}

				// Ensure type is 'object' at the root level.
				if ( ! isset( $schema['type'] ) ) {
					$schema['type'] = 'object';
				}
			}

			// Recursively process the schema.
			// When we recurse into nested structures (properties, items, etc.),
			// the parent_key becomes non-empty, so composition keywords are preserved.
			foreach ( $schema as $key => $value ) {
				if ( is_array( $value ) ) {
					// Recursively sanitize nested structures.
					$sanitized[ $key ] = $this->sanitize_parameters_for_openai( $value, $key );
				} else {
					$sanitized[ $key ] = $value;
				}
			}

			return $sanitized;
		}

		/**
		 * Remove large message payloads when logging requests.
		 *
		 * @param array $payload The payload that will be logged.
		 * @return array
		 */
		protected function obfuscate_request_for_log( array $payload ) {
			$message_key = null;

			if ( isset( $payload['messages'] ) && is_array( $payload['messages'] ) ) {
				$message_key = 'messages';
			} elseif ( isset( $payload['input'] ) && is_array( $payload['input'] ) ) {
				$message_key = 'input';
			}

			if ( null !== $message_key ) {
				$trimmed_messages = array();
				foreach ( $payload[ $message_key ] as $message ) {
					if ( isset( $message['content'] ) && is_array( $message['content'] ) ) {
						$trimmed_segments = array();

						foreach ( $message['content'] as $segment ) {
							if ( ! is_array( $segment ) ) {
								continue;
							}

							$segment_copy = $segment;
							$type         = isset( $segment['type'] ) ? $segment['type'] : '';

							if ( in_array( $type, array( 'text', 'input_text', 'output_text' ), true ) && isset( $segment['text'] ) ) {
								$content              = (string) $segment['text'];
								$length               = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
								$slice                = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
								$segment_copy['text'] = $slice . ( $length > 200 ? '…' : '' );
							}

							if ( 'input_image' === $type ) {
								if ( isset( $segment['image_url']['url'] ) ) {
									$segment_copy['image_url']['url'] = esc_url_raw( $segment['image_url']['url'] );
								} elseif ( isset( $segment['image_url'] ) && is_string( $segment['image_url'] ) ) {
									$segment_copy['image_url'] = esc_url_raw( $segment['image_url'] );
								}

								if ( isset( $segment['file_id'] ) ) {
									$segment_copy['file_id'] = (string) $segment['file_id'];
								} elseif ( isset( $segment['image_file']['file_id'] ) ) {
									$segment_copy['file_id'] = (string) $segment['image_file']['file_id'];
								} elseif ( isset( $segment['image']['file_id'] ) ) {
									$segment_copy['file_id'] = (string) $segment['image']['file_id'];
								}
							}

							if ( 'input_file' === $type ) {
								$segment_copy = array( 'type' => 'input_file' );

								if ( isset( $segment['file_id'] ) ) {
									$segment_copy['file_id'] = $segment['file_id'];
								}

								if ( isset( $segment['file'] ) && is_array( $segment['file'] ) ) {
									$file_entry = array();

									if ( isset( $segment['file']['id'] ) ) {
										$file_entry['id'] = $segment['file']['id'];
									} elseif ( isset( $segment['file']['file_id'] ) ) {
										$file_entry['id'] = $segment['file']['file_id'];
									}

									if ( ! empty( $file_entry ) ) {
										$segment_copy['file'] = $file_entry;
									}
								}

								if ( isset( $segment['display_name'] ) ) {
									$segment_copy['display_name'] = $segment['display_name'];
								}

								if ( isset( $segment['filename'] ) ) {
									$segment_copy['filename'] = $segment['filename'];
								}

								if ( isset( $segment['file_data'] ) ) {
									$segment_copy['file_data'] = '[redacted]';
								}
							}

							$trimmed_segments[] = $segment_copy;
						}

						$message['content'] = $trimmed_segments;
					} elseif ( isset( $message['content'] ) ) {
						$content            = (string) $message['content'];
						$length             = function_exists( 'mb_strlen' ) ? mb_strlen( $content ) : strlen( $content );
						$slice              = function_exists( 'mb_substr' ) ? mb_substr( $content, 0, 200 ) : substr( $content, 0, 200 );
						$message['content'] = $slice . ( $length > 200 ? '…' : '' );
					}

					$trimmed_messages[] = $message;
				}
				$payload[ $message_key ] = $trimmed_messages;
			}

			if ( isset( $payload['attachments'] ) && is_array( $payload['attachments'] ) ) {
				$scrubbed = array();

				foreach ( $payload['attachments'] as $attachment ) {
					if ( ! is_array( $attachment ) ) {
						continue;
					}

					if ( isset( $attachment['data'] ) ) {
						$attachment['data'] = '[redacted]';
					}

					$scrubbed[] = $attachment;
				}

				$payload['attachments'] = $scrubbed;
			}

			return $payload;
		}

		/**
		 * Extract attachments from the options payload in a consistent array format.
		 *
		 * @param array $options Prepared request options.
		 * @return array
		 */
		protected function extract_attachments_from_options( array $options ) {
			if ( empty( $options['attachments'] ) ) {
				return array();
			}

			$attachments = $options['attachments'];

			if ( $attachments instanceof \Traversable ) {
				$attachments = iterator_to_array( $attachments );
			}

			if ( is_object( $attachments ) ) {
				$attachments = (array) $attachments;
			}

			if ( ! is_array( $attachments ) ) {
				return array();
			}

			$normalised = array();

			foreach ( $attachments as $attachment ) {
				if ( $attachment instanceof \Traversable ) {
					$attachment = iterator_to_array( $attachment );
				}

				if ( is_object( $attachment ) ) {
					$attachment = (array) $attachment;
				}

				if ( ! is_array( $attachment ) || empty( $attachment ) ) {
					continue;
				}

				$normalised[] = $attachment;
			}

			return array_values( $normalised );
		}

		/**
		 * Check if messages contain tool calls or tool role messages.
		 *
		 * @param array $messages Sanitized chat messages.
		 * @return bool True if tool calls or tool messages are present.
		 */
		protected function has_tool_calls_in_messages( array $messages ) {
			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				$role = isset( $message['role'] ) ? sanitize_key( $message['role'] ) : '';

				// Check for tool role messages or assistant messages with tool_calls.
				if ( 'tool' === $role ) {
					return true;
				}

				if ( 'assistant' === $role && ! empty( $message['tool_calls'] ) ) {
					return true;
				}
			}

			return false;
		}

		/**
		 * Check whether a model identifier refers to an OpenAI Codex model.
		 *
		 * Codex models (e.g. gpt-5.3-codex, gpt-5.3-codex-spark) require the
		 * Responses API endpoint; the Chat Completions endpoint is deprecated
		 * for these models and may return "model does not exist" errors.
		 *
		 * @param string $model Model identifier.
		 * @return bool
		 */
		public static function is_codex_model( $model ) {
			return (bool) preg_match( '/\bcodex\b/', $model );
		}

		/**
		 * Determine whether the OpenAI Responses API should be used for the request.
		 *
		 * @param array $messages Sanitized chat messages.
		 * @param array $options  Prepared request options.
		 * @return bool
		 */
		protected function should_use_responses_api( array $messages, array $options ) {
			// Codex models require the Responses API — Chat Completions is deprecated for them.
			if ( ! empty( $options['model'] ) && self::is_codex_model( $options['model'] ) ) {
				return true;
			}

			// Don't use Responses API when there are tool calls or tool messages in the conversation.
			// The Responses API doesn't support the tool_calls/tool_call_id mechanism used by Chat Completions.
			if ( $this->has_tool_calls_in_messages( $messages ) ) {
				return false;
			}

			// Check if attachments are present in options.

			if ( ! empty( $options['attachments'] ) && is_array( $options['attachments'] ) ) {
				// If all attachments are images, use Chat Completions API with image_url.

				// This allows tool calling to work with images.

				if ( $this->are_all_attachments_images( $options['attachments'] ) ) {
					return false;
				}
				// Otherwise, use Responses API for non-image documents.

				return true;
			}

			// Check for file references in message content.

			$has_file_reference = false;

			foreach ( $messages as $message ) {
				if ( empty( $message['content'] ) || ! is_array( $message['content'] ) ) {
					continue;
				}

				foreach ( $message['content'] as $segment ) {
					if ( ! is_array( $segment ) ) {
						continue;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

					// Check for input_file type (non-image documents).

					if ( 'input_file' === $type ) {
						$has_file_reference = true;
					}

					// Check for file_id in various locations.

					// Only count as file reference if it's not an input_image type.

					if ( isset( $segment['file_id'] ) && 'input_image' !== $type ) {
						$has_file_reference = true;
					}

					// Image-related file references don't require Responses API.

					// Skip checking isset for image/image_file as those are handled by type check above.

					if ( strpos( $type, 'input_' ) === 0 && 'input_file' === $type && isset( $segment['file_id'] ) ) {
						$has_file_reference = true;
					}
				}
			}

			// Only use Responses API if there are non-image file references.

			return $has_file_reference;
		}

		/**
		 * Prepare the payload used when calling the Responses API.
		 *
		 * @param array $original_messages   Original chat messages.
		 * @param array $normalised_messages Messages after normalisation.
		 * @param array $attachments         Attachment data (optional).
		 * @return array
		 */
		protected function prepare_responses_input( array $original_messages, array $normalised_messages, array $attachments = array() ) {
			$prepared          = array();
			$attachment_lookup = $this->index_attachments_by_id( $attachments );

			foreach ( $normalised_messages as $index => $message ) {
				$entry = $message;
				$role  = isset( $entry['role'] ) ? $entry['role'] : '';

				$original_content = isset( $original_messages[ $index ]['content'] ) ? $original_messages[ $index ]['content'] : null;

				if ( isset( $entry['content'] ) && ! is_array( $entry['content'] ) ) {
					$content_string   = (string) $entry['content'];
					$entry['content'] = array(
						array(
							'type' => 'text',
							'text' => $content_string,
						),
					);
				} elseif ( isset( $entry['content'] ) && is_array( $entry['content'] ) ) {
					$entry['content'] = array_values( $entry['content'] );
				} elseif ( is_array( $original_content ) ) {
					$entry['content'] = array_values( $original_content );
				} else {
					$entry['content'] = array();
				}

				if ( isset( $entry['content'] ) && is_array( $entry['content'] ) ) {
					$entry['content'] = $this->normalise_responses_content_segments( $entry['content'], $attachment_lookup, $role );
				}

				// The Responses API doesn't support tool_calls or tool_call_id in the input array.
				// Remove them if present to ensure compliance with the API specification.
				if ( isset( $entry['tool_calls'] ) ) {
					unset( $entry['tool_calls'] );
				}
				if ( isset( $entry['tool_call_id'] ) ) {
					unset( $entry['tool_call_id'] );
				}

				$prepared[] = $entry;
			}

			return $prepared;
		}

		/**
		 * Normalise content segments for the Responses API payload.
		 *
		 * The REST layer stores text-only segments using the generic `text` type so
		 * they remain compatible with the Chat Completions API. The Responses API
		 * expects those entries to be labelled as `input_text`, so we convert them
		 * here while preserving multimodal payloads (input_file, input_image, etc.).
		 *
		 * @param array  $segments    Content segments for a single message.
		 * @param array  $attachments Attachment lookup keyed by file identifier.
		 * @param string $role        Message role used to determine the text segment mode.
		 * @return array
		 */
		protected function normalise_responses_content_segments( array $segments, array $attachments = array(), $role = '' ) {
			$normalised   = array();
			$role_key     = sanitize_key( $role );
			$output_roles = array( 'assistant', 'tool', 'function' );

			foreach ( $segments as $segment ) {
				if ( $segment instanceof \Traversable ) {
					$segment = iterator_to_array( $segment );
				}

				if ( is_object( $segment ) ) {
					$segment = (array) $segment;
				}

				if ( ! is_array( $segment ) ) {
					$segment = array(
						'type' => 'text',
						'text' => is_scalar( $segment ) ? (string) $segment : '',
					);
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

				if ( isset( $segment['display_name'] ) ) {
					unset( $segment['display_name'] );
				}

				$is_output_role = in_array( $role_key, $output_roles, true );

				if ( '' === $type || 'text' === $type || 'input_text' === $type || 'output_text' === $type ) {
					$segment['type'] = $is_output_role ? 'output_text' : 'input_text';

					if ( isset( $segment['content'] ) && ! isset( $segment['text'] ) ) {
						$segment['text'] = is_scalar( $segment['content'] ) ? (string) $segment['content'] : '';
						unset( $segment['content'] );
					}

					if ( ! isset( $segment['text'] ) ) {
						$segment['text'] = '';
					}

					if ( isset( $segment['mode'] ) ) {
						unset( $segment['mode'] );
					}

					$normalised[] = $segment;
					continue;
				} elseif ( 'input_image' === $type ) {
					$caption_text = $this->extract_responses_image_caption( $segment, $attachments );
					$segment      = $this->populate_responses_image_segment( $segment, $attachments );

					if ( isset( $segment['mode'] ) ) {
						unset( $segment['mode'] );
					}

					if ( '' !== $caption_text ) {
						$normalised[] = array(
							'type' => $is_output_role ? 'output_text' : 'input_text',
							'text' => $caption_text,
						);
					}

					$normalised[] = $segment;
					continue;
				} elseif ( 'input_file' === $type ) {
					$segment = $this->populate_responses_file_segment( $segment, $attachments );

					// Skip segments that have no file_id or file_data — the Responses API requires one
					// of these fields and would reject a bare input_file entry (e.g. a video attached
					// via URL that has not been uploaded to OpenAI).
					if ( ! isset( $segment['file_id'] ) && ! isset( $segment['file_data'] ) ) {
						WP_MCP_AI_Logger::log_error(
							'Skipping input_file segment without file_id or file_data for Responses API.',
							array( 'segment_type' => $type )
						);
						continue;
					}
				}

				if ( isset( $segment['mode'] ) ) {
					unset( $segment['mode'] );
				}

				$normalised[] = $segment;
			}

			return $normalised;
		}

		/**
		 * Extract a caption value for an image segment.
		 *
		 * @param array $segment     Original segment definition.
		 * @param array $attachments Attachment lookup keyed by file identifier.
		 * @return string
		 */
		protected function extract_responses_image_caption( array $segment, array $attachments ) {
			$caption = '';

			if ( isset( $segment['caption'] ) ) {
				$caption = $this->normalise_responses_segment_caption( $segment['caption'] );
			}

			if ( '' !== $caption ) {
				return $caption;
			}

			$file_id = '';

			if ( isset( $segment['file_id'] ) ) {
				$file_id = (string) $segment['file_id'];
			} elseif ( isset( $segment['image']['file_id'] ) ) {
				$file_id = (string) $segment['image']['file_id'];
			} elseif ( isset( $segment['image_file']['file_id'] ) ) {
				$file_id = (string) $segment['image_file']['file_id'];
			}

			if ( '' === $file_id || ! isset( $attachments[ $file_id ] ) ) {
				return '';
			}

			$attachment = $attachments[ $file_id ];

			if ( isset( $attachment['caption'] ) ) {
				$caption = $this->normalise_responses_segment_caption( $attachment['caption'] );
			}

			if ( '' === $caption && isset( $attachment['title'] ) ) {
				$caption = $this->normalise_responses_segment_caption( $attachment['title'] );
			}

			return $caption;
		}

		/**
		 * Build a lookup of attachments keyed by their generated identifier.
		 *
		 * @param array $attachments Attachment payloads.
		 * @return array
		 */
		protected function index_attachments_by_id( array $attachments ) {
			$indexed = array();

			foreach ( $attachments as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}

				$id = '';

				if ( isset( $attachment['id'] ) ) {
					$id = (string) $attachment['id'];
				} elseif ( isset( $attachment['file_id'] ) ) {
					$id = (string) $attachment['file_id'];
				}

				if ( '' === $id ) {
					continue;
				}

				$indexed[ $id ] = $attachment;
			}

			return $indexed;
		}

		/**
		 * Hydrate an image segment with inline attachment data when available.
		 *
		 * @param array $segment     Segment definition.
		 * @param array $attachments Attachment lookup keyed by file identifier.
		 * @return array
		 */
		protected function populate_responses_image_segment( array $segment, array $attachments ) {
			$file_id = '';

			if ( isset( $segment['file_id'] ) ) {
				$file_id = (string) $segment['file_id'];
			} elseif ( isset( $segment['image']['file_id'] ) ) {
				$file_id = (string) $segment['image']['file_id'];
			} elseif ( isset( $segment['image_file']['file_id'] ) ) {
				$file_id = (string) $segment['image_file']['file_id'];
			}

			if ( $file_id && isset( $attachments[ $file_id ] ) ) {
				$attachment = $attachments[ $file_id ];

				$openai_file_id = $file_id;

				if ( isset( $attachment['openai_file'] ) ) {
					if ( is_array( $attachment['openai_file'] ) && isset( $attachment['openai_file']['id'] ) ) {
						$openai_file_id = (string) $attachment['openai_file']['id'];
					} elseif ( is_string( $attachment['openai_file'] ) && '' !== $attachment['openai_file'] ) {
						$openai_file_id = (string) $attachment['openai_file'];
					}
				} elseif ( isset( $attachment['file_id'] ) && '' !== $attachment['file_id'] ) {
					$openai_file_id = (string) $attachment['file_id'];
				}

				$segment['file_id'] = $openai_file_id;
				$file_id            = $openai_file_id;

				if ( isset( $segment['image'] ) ) {
					unset( $segment['image'] );
				}

				if ( isset( $segment['image_url'] ) ) {
					unset( $segment['image_url'] );
				}
			} elseif ( isset( $segment['image_url']['url'] ) ) {
				// For Responses API, image_url should be a string URL, not an object.

				// Preserve detail if present in the nested object before extracting URL.

				if ( isset( $segment['image_url']['detail'] ) && ! isset( $segment['detail'] ) ) {
					$segment['detail'] = sanitize_key( $segment['image_url']['detail'] );
				}
				$segment['image_url'] = esc_url_raw( (string) $segment['image_url']['url'] );
			} elseif ( isset( $segment['image_url'] ) && is_string( $segment['image_url'] ) ) {
				$segment['image_url'] = esc_url_raw( $segment['image_url'] );
			}

			if ( isset( $segment['image'] ) && is_array( $segment['image'] ) ) {
				if ( isset( $segment['image']['file_id'] ) && '' === $file_id ) {
					$segment['file_id'] = (string) $segment['image']['file_id'];
				}

				unset( $segment['image'] );
			}

			if ( isset( $segment['image_file'] ) && is_array( $segment['image_file'] ) ) {
				if ( isset( $segment['image_file']['file_id'] ) && '' === $file_id ) {
					$segment['file_id'] = (string) $segment['image_file']['file_id'];
				}

				unset( $segment['image_file'] );
			}

			if ( empty( $segment['detail'] ) ) {
				$segment['detail'] = 'auto';
			}

			if ( isset( $segment['caption'] ) ) {
				unset( $segment['caption'] );
			}

			// Remove agentic workflow metadata fields that are not part of the OpenAI Responses API spec.
			// These fields (url, attachment_id, file_name, mime_type, bytes) are added by
			// WP_MCP_AI_Message_Attachments for internal use but should not be sent to the API.
			$metadata_fields = array( 'url', 'attachment_id', 'file_name', 'mime_type', 'bytes' );
			foreach ( $metadata_fields as $field ) {
				if ( isset( $segment[ $field ] ) ) {
					unset( $segment[ $field ] );
				}
			}

			return $segment;
		}

		/**
		 * Normalise a caption payload for safe inclusion alongside Responses segments.
		 *
		 * @param mixed $caption Raw caption payload.
		 * @return string
		 */
		protected function normalise_responses_segment_caption( $caption ) {
			if ( is_string( $caption ) || is_numeric( $caption ) ) {
				$caption_text = (string) $caption;
			} elseif ( is_array( $caption ) || is_object( $caption ) ) {
				$caption_text = $this->normalise_responses_text_value( $caption );
			} else {
				$caption_text = '';
			}

			if ( '' === $caption_text ) {
				return '';
			}

			return trim( wp_strip_all_tags( $caption_text ) );
		}

		/**
		 * Ensure a file segment references an uploaded OpenAI file identifier.
		 *
		 * @param array $segment     Segment definition.
		 * @param array $attachments Attachment lookup keyed by file identifier.
		 * @return array
		 */
		protected function populate_responses_file_segment( array $segment, array $attachments ) {
			$file_id = isset( $segment['file_id'] ) ? (string) $segment['file_id'] : '';

			if ( '' === $file_id && isset( $segment['file']['id'] ) ) {
				$file_id            = (string) $segment['file']['id'];
				$segment['file_id'] = $file_id;
			}

			if ( isset( $segment['file_data'] ) ) {
				unset( $segment['file_data'] );
			}

			if ( '' === $file_id ) {
				// Remove internal metadata fields that are not part of the OpenAI Responses API spec.
				// Fields like url, attachment_id, file_name, mime_type, bytes are used internally but
				// cause "unknown parameter" errors when sent to the API without a matching file_id.
				$metadata_fields = array( 'url', 'attachment_id', 'name', 'file_name', 'mime_type', 'bytes', 'display_name' );
				foreach ( $metadata_fields as $field ) {
					if ( isset( $segment[ $field ] ) ) {
						unset( $segment[ $field ] );
					}
				}
				return $segment;
			}

			if ( isset( $attachments[ $file_id ] ) ) {
				$attachment = $attachments[ $file_id ];

				$openai_file_id = $file_id;

				if ( isset( $attachment['openai_file'] ) ) {
					if ( is_array( $attachment['openai_file'] ) && isset( $attachment['openai_file']['id'] ) ) {
						$openai_file_id = (string) $attachment['openai_file']['id'];
					} elseif ( is_string( $attachment['openai_file'] ) ) {
						$openai_file_id = (string) $attachment['openai_file'];
					}
				} elseif ( isset( $attachment['file_id'] ) ) {
					$openai_file_id = (string) $attachment['file_id'];
				}

				$segment['file_id'] = $openai_file_id;

				if ( empty( $segment['filename'] ) && ! empty( $attachment['filename'] ) ) {
					$segment['filename'] = $attachment['filename'];
				}
			}

			if ( isset( $segment['file'] ) ) {
				unset( $segment['file'] );
			}

			if ( isset( $segment['file_id'] ) && '' !== $segment['file_id'] && isset( $segment['filename'] ) ) {
				unset( $segment['filename'] );
			}

			// Remove agentic workflow metadata fields that are not part of the OpenAI Responses API spec.
			// These fields (url, attachment_id, file_name, name, mime_type, bytes, display_name) are added by
			// WP_MCP_AI_Message_Attachments for internal use but should not be sent to the API.
			// PDFs (and other files) rely on this cleanup to avoid "unknown parameter" errors.
			$metadata_fields = array( 'url', 'attachment_id', 'file_name', 'name', 'mime_type', 'bytes', 'display_name' );
			foreach ( $metadata_fields as $field ) {
				if ( isset( $segment[ $field ] ) ) {
					unset( $segment[ $field ] );
				}
			}

			return $segment;
		}

		/**
		 * Convert a Responses API result into a shape that matches chat completions.
		 *
		 * @param array $response Raw Responses API payload.
		 * @return array
		 */
		protected function convert_responses_result_to_chat_completion( array $response ) {
			if ( isset( $response['response'] ) && is_array( $response['response'] ) ) {
				$nested_response = $this->convert_responses_result_to_chat_completion( $response['response'] );

				$response['response'] = $nested_response;

				if ( ! isset( $response['choices'] ) && isset( $nested_response['choices'] ) ) {
					$response['choices'] = $nested_response['choices'];
				}
			}

			if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
				$normalised = array();

				foreach ( $response['choices'] as $index => $choice ) {
					if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
						if ( isset( $choice['message']['content'] ) && is_array( $choice['message']['content'] ) ) {
							$content_text = $this->extract_text_from_response_content( $choice['message']['content'] );

							if ( '' !== $content_text ) {
								$choice['message']['content'] = $content_text;
							} else {
								$choice['message']['content'] = array_values( $choice['message']['content'] );
							}
						}

						if ( ! isset( $choice['index'] ) ) {
							$choice['index'] = $index;
						}

						$normalised[] = $choice;
						continue;
					}

					$content = isset( $choice['content'] ) ? $choice['content'] : array();
					$role    = isset( $choice['role'] ) ? sanitize_key( $choice['role'] ) : 'assistant';

					$normalised_choice            = $choice;
					$content_text                 = $this->extract_text_from_response_content( $content );
					$content_fallback             = is_array( $content ) ? array_values( $content ) : $content;
					$normalised_choice['message'] = array(
						'role'    => $role,
						'content' => '' !== $content_text ? $content_text : $content_fallback,
					);

					if ( isset( $normalised_choice['role'] ) ) {
						unset( $normalised_choice['role'] );
					}

					if ( isset( $normalised_choice['content'] ) ) {
						unset( $normalised_choice['content'] );
					}

					if ( ! isset( $normalised_choice['index'] ) ) {
						$normalised_choice['index'] = $index;
					}

					$normalised[] = $normalised_choice;
				}

				$response['choices'] = $normalised;

				return $response;
			}

			$choices = array();

			if ( isset( $response['output'] ) && is_array( $response['output'] ) ) {
				foreach ( $response['output'] as $index => $item ) {
					if ( ! is_array( $item ) ) {
						continue;
					}

					$content_payload = array();

					if ( isset( $item['content'] ) ) {
						$content_payload = $item['content'];
					} elseif ( isset( $item['text'] ) ) {
						$content_payload = $item['text'];
					}

					$content_text     = $this->extract_text_from_response_content( $content_payload );
					$content_fallback = is_array( $content_payload ) ? array_values( $content_payload ) : $content_payload;

					$choices[] = array(
						'index'         => $index,
						'message'       => array(
							'role'    => isset( $item['role'] ) ? sanitize_key( $item['role'] ) : 'assistant',
							'content' => '' !== $content_text ? $content_text : $content_fallback,
						),
						'finish_reason' => isset( $item['finish_reason'] ) ? $item['finish_reason'] : 'stop',
					);
				}
			}

			if ( empty( $choices ) && isset( $response['output_text'] ) ) {
				$output_text = $response['output_text'];

				if ( is_array( $output_text ) ) {
					$output_text = array_filter(
						array_map(
							static function ( $item ) {
								if ( is_string( $item ) || is_numeric( $item ) ) {
									return (string) $item;
								}

								return '';
							},
							$output_text
						),
						static function ( $part ) {
							return '' !== trim( $part );
						}
					);

					$output_text = implode( "\n\n", $output_text );
				}

				$output_text = is_string( $output_text ) || is_numeric( $output_text ) ? (string) $output_text : '';

				if ( '' !== $output_text ) {
					$choices[] = array(
						'index'         => 0,
						'message'       => array(
							'role'    => 'assistant',
							'content' => $output_text,
						),
						'finish_reason' => 'stop',
					);
				}
			}

			if ( empty( $choices ) ) {
				return $response;
			}

			$response['choices'] = $choices;

			return $response;
		}

		/**
		 * Extract a plain text representation from a Responses API content payload.
		 *
		 * @param mixed $content Content payload from the Responses API.
		 * @return string
		 */
		protected function extract_text_from_response_content( $content ) {
			if ( is_string( $content ) ) {
				return $content;
			}

			if ( ! is_array( $content ) ) {
				return '';
			}

			$text_segments = array();

			foreach ( $content as $segment ) {
				if ( is_string( $segment ) ) {
					$text_segments[] = $segment;
					continue;
				}

				if ( ! is_array( $segment ) ) {
					continue;
				}

				$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

				if ( isset( $segment['text'] ) && in_array( $type, array( 'output_text', 'text', 'input_text' ), true ) ) {
					$text_segments[] = $this->normalise_responses_text_value( $segment['text'] );
					continue;
				}

				if ( isset( $segment['content'] ) && is_string( $segment['content'] ) ) {
					$text_segments[] = $segment['content'];
				}
			}

			$text_segments = array_filter(
				$text_segments,
				static function ( $part ) {
					return '' !== trim( $part );
				}
			);

			return implode( "\n\n", $text_segments );
		}

		/**
		 * Normalise a Responses API text value into a scalar string.
		 *
		 * @param mixed $value Raw text payload from the Responses API.
		 * @return string
		 */
		protected function normalise_responses_text_value( $value ) {
			if ( is_string( $value ) || is_numeric( $value ) ) {
				return (string) $value;
			}

			if ( is_array( $value ) ) {
				if ( isset( $value['value'] ) && is_string( $value['value'] ) ) {
					return $value['value'];
				}

				if ( isset( $value['text'] ) && is_string( $value['text'] ) ) {
					return $value['text'];
				}

				$scalars = array();

				foreach ( $value as $key => $item ) {
					if ( is_string( $key ) && in_array( $key, array( 'type', 'annotations', 'mode', 'status', 'finish_reason', 'id' ), true ) ) {
						continue;
					}

					$normalised_item = $this->normalise_responses_text_value( $item );

					if ( '' !== $normalised_item ) {
						$scalars[] = $normalised_item;
					}
				}

				if ( ! empty( $scalars ) ) {
					return implode( "\n\n", $scalars );
				}
			}

			return '';
		}

		/**
		 * Check if all attachments in the provided array are images.
		 *
		 * @param array $attachments Array of attachment payloads.
		 * @return bool True if all attachments are images, false otherwise.
		 */
		protected function are_all_attachments_images( array $attachments ) {
			if ( empty( $attachments ) ) {
				return false;
			}

			foreach ( $attachments as $attachment ) {
				if ( ! is_array( $attachment ) ) {
					continue;
				}

				$mime_type = '';

				// Check for mime_type in the attachment metadata.

				if ( isset( $attachment['mime_type'] ) && '' !== $attachment['mime_type'] ) {
					$mime_type = $attachment['mime_type'];
				} elseif ( isset( $attachment['metadata']['mime_type'] ) && '' !== $attachment['metadata']['mime_type'] ) {
					$mime_type = $attachment['metadata']['mime_type'];
				} elseif ( isset( $attachment['attachment_id'] ) ) {
					// Try to get mime type from WordPress attachment.

					$mime_type = get_post_mime_type( absint( $attachment['attachment_id'] ) );
				} elseif ( isset( $attachment['id'] ) && is_numeric( $attachment['id'] ) ) {
					// Try to get mime type from WordPress attachment using id field.

					$mime_type = get_post_mime_type( absint( $attachment['id'] ) );
				}

				if ( '' === $mime_type ) {
					// If we can't determine mime type, assume it's not an image (safer default).

					return false;
				}

				if ( ! WP_MCP_AI_Message_Attachments::is_image_mime_type( $mime_type ) ) {
					return false;
				}
			}

			return true;
		}

		/**
		 * Convert image file segments to image_url format for Chat Completions API.
		 *
		 * This allows images to work with tool calling by using the Chat Completions API
		 * with image_url content type instead of the Responses API.
		 *
		 * @param array $messages          Array of chat messages.
		 * @param array $attachment_lookup Indexed attachments by file_id.
		 * @return array Modified messages with image_url format for images.
		 */
		protected function convert_image_files_to_image_url( array $messages, array $attachment_lookup ) {
			$converted = array();

			foreach ( $messages as $message ) {
				if ( ! isset( $message['content'] ) || ! is_array( $message['content'] ) ) {
					$converted[] = $message;
					continue;
				}

				$converted_segments = array();

				foreach ( $message['content'] as $segment ) {
					if ( ! is_array( $segment ) ) {
						$converted_segments[] = $segment;
						continue;
					}

					$type = isset( $segment['type'] ) ? sanitize_key( $segment['type'] ) : '';

					// Convert input_image segments to image_url format for Chat Completions API.

					if ( 'input_image' === $type ) {
						// Check if the segment already has an image_url (from external URLs).

						if ( isset( $segment['image_url'] ) ) {
							// Extract URL from image_url structure.

							$image_url = '';
							if ( is_array( $segment['image_url'] ) && isset( $segment['image_url']['url'] ) ) {
								$image_url = esc_url_raw( (string) $segment['image_url']['url'] );
							} elseif ( is_string( $segment['image_url'] ) ) {
								$image_url = esc_url_raw( $segment['image_url'] );
							}

							if ( '' !== $image_url ) {
								// Convert to image_url type with proper structure.

								$converted_segment = array(
									'type'      => 'image_url',
									'image_url' => array( 'url' => $image_url ),
								);

								// Preserve detail level if present.

								if ( isset( $segment['detail'] ) && '' !== $segment['detail'] ) {
									$converted_segment['image_url']['detail'] = sanitize_key( $segment['detail'] );
								} elseif ( is_array( $segment['image_url'] ) && isset( $segment['image_url']['detail'] ) ) {
									$converted_segment['image_url']['detail'] = sanitize_key( $segment['image_url']['detail'] );
								}

								// Note: OpenAI's Chat Completions API only accepts 'type' and 'image_url' fields
								// in image_url content parts. Additional metadata fields (attachment_id, url,
								// file_name, mime_type, bytes) cause "Unexpected keys in a message content image dict" errors.
								// Tools can receive these fields directly as tool arguments instead.

								$converted_segments[] = $converted_segment;
								continue;
							}
						}

						// Handle input_image segments with file_id.

						if ( ! isset( $segment['file_id'] ) ) {
							// input_image without file_id or image_url cannot be converted - skip it.

							WP_MCP_AI_Logger::log_error(
								'Skipping input_image segment without file_id or image_url for Chat Completions API.',
								array(
									'segment' => $segment,
								)
							);
							continue;
						}

						$file_id = (string) $segment['file_id'];

						// Try to get the attachment data.

						$attachment_id = 0;
						if ( isset( $attachment_lookup[ $file_id ] ) && isset( $attachment_lookup[ $file_id ]['attachment_id'] ) ) {
							$attachment_id = absint( $attachment_lookup[ $file_id ]['attachment_id'] );
						}

						// If we can get a URL for the image, use image_url format.

						if ( $attachment_id > 0 ) {
							// Verify attachment exists and get its post status.

							$attachment_post = get_post( $attachment_id );
							$can_use_url     = false;

							if ( $attachment_post && 'attachment' === $attachment_post->post_type ) {
								$public_statuses = get_post_stati( array( 'public' => true ) );
								if ( ! is_array( $public_statuses ) ) {
									$public_statuses = array( 'publish' );
								}

								// Check if attachment or its parent is publicly accessible.

								if ( in_array( $attachment_post->post_status, $public_statuses, true ) || 'inherit' === $attachment_post->post_status ) {
									$can_use_url = true;
								}
							}

							if ( $can_use_url ) {
								$image_url = wp_get_attachment_url( $attachment_id );
								if ( $image_url ) {
									$converted_segment = array(
										'type'      => 'image_url',
										'image_url' => array( 'url' => esc_url_raw( $image_url ) ),
									);

									// Preserve detail level if present.

									if ( isset( $segment['detail'] ) && '' !== $segment['detail'] ) {
										$converted_segment['image_url']['detail'] = sanitize_key( $segment['detail'] );
									}

									// Note: OpenAI's Chat Completions API only accepts 'type' and 'image_url' fields
									// in image_url content parts. Additional metadata fields (attachment_id, url,
									// file_name, mime_type, bytes) cause "Unexpected keys in a message content image dict" errors.
									// Tools can receive these fields directly as tool arguments instead.

									$converted_segments[] = $converted_segment;
									continue;
								}
							}
						}

						// If we reach here, the input_image could not be converted to image_url.
						// Skip it since Chat Completions API doesn't support input_image type.
						WP_MCP_AI_Logger::log_error(
							'Skipping input_image segment that could not be converted to image_url for Chat Completions API.',
							array(
								'file_id'       => $file_id,
								'attachment_id' => $attachment_id,
							)
						);
						continue;
					}

					// Keep all other segments as-is.

					$converted_segments[] = $segment;
				}

				// Handle messages that have no content after filtering.

				if ( empty( $converted_segments ) ) {
					// Add a fallback text segment to preserve the message in chat UI.

					// This prevents empty content errors and maintains conversation continuity.

					$converted_segments[] = array(
						'type' => 'text',
						'text' => '[Image could not be loaded]',
					);
					WP_MCP_AI_Logger::log_error(
						'Replaced empty message content with fallback text after input_image conversion for Chat Completions API.',
						array(
							'role' => isset( $message['role'] ) ? $message['role'] : 'unknown',
						)
					);
				}

				$message['content'] = $converted_segments;
				$converted[]        = $message;
			}

			return $converted;
		}

		/**
		 * Count tokens for a given message payload using OpenAI's API or estimation.
		 *
		 * This provides pre-flight token counting to validate requests before sending.
		 *
		 * @param array $messages Message payload to count tokens for.
		 * @param array $options  Additional options (model).
		 * @return int|WP_Error Token count or WP_Error on failure.
		 */
		public function count_tokens( array $messages, array $options = array() ) { // phpcs:ignore Generic.CodeAnalysis.UnusedFunctionParameter.Found -- Parameter reserved for model-specific token counting.
			// For OpenAI, we don't have a direct token counting API endpoint,.

			// so we use estimation based on character count.
			// This is a reasonable heuristic: ~4 characters per token for English text.

			$total_chars = 0;

			foreach ( $messages as $message ) {
				if ( ! is_array( $message ) ) {
					continue;
				}

				// Count role.

				if ( isset( $message['role'] ) ) {
					$total_chars += strlen( (string) $message['role'] );
				}

				// Count content.

				if ( isset( $message['content'] ) ) {
					if ( is_string( $message['content'] ) ) {
						$total_chars += strlen( $message['content'] );
					} elseif ( is_array( $message['content'] ) ) {
						foreach ( $message['content'] as $segment ) {
							if ( is_string( $segment ) ) {
								$total_chars += strlen( $segment );
							} elseif ( is_array( $segment ) && isset( $segment['text'] ) ) {
								$total_chars += strlen( (string) $segment['text'] );
							}
						}
					}
				}

				// Count tool calls.

				if ( isset( $message['tool_calls'] ) && is_array( $message['tool_calls'] ) ) {
					$encoded = wp_json_encode( $message['tool_calls'] );
					if ( false !== $encoded ) {
						$total_chars += strlen( $encoded );
					}
				}

				// Count tool responses.

				if ( isset( $message['tool_call_id'] ) ) {
					$total_chars += strlen( (string) $message['tool_call_id'] );
				}
				if ( isset( $message['name'] ) ) {
					$total_chars += strlen( (string) $message['name'] );
				}
			}

			// Apply the ~4 characters per token heuristic.

			$estimated_tokens = (int) ceil( $total_chars / self::CHAT_APPROX_CHARS_PER_TOKEN );

			WP_MCP_AI_Logger::log_event(
				'openai_token_count_estimated',
				'Estimated token count for OpenAI request.',
				array(
					'total_chars'      => $total_chars,
					'estimated_tokens' => $estimated_tokens,
					'message_count'    => count( $messages ),
				)
			);

			return $estimated_tokens;
		}

		/**
		 * Create a new vector store.
		 *
		 * @param string $name Vector store name.
		 * @param array  $options Optional parameters (file_ids, expires_after, metadata).
		 * @return array|WP_Error Vector store data or error.
		 */
		public function create_vector_store( $name, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$name = sanitize_text_field( $name );
			if ( empty( $name ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_name',
					__( 'A name is required to create a vector store.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$payload = array(
				'name' => $name,
			);

			if ( ! empty( $options['file_ids'] ) && is_array( $options['file_ids'] ) ) {
				$payload['file_ids'] = array_map( 'sanitize_text_field', $options['file_ids'] );
			}

			if ( ! empty( $options['expires_after'] ) && is_array( $options['expires_after'] ) ) {
				$payload['expires_after'] = $options['expires_after'];
			}

			if ( ! empty( $options['metadata'] ) && is_array( $options['metadata'] ) ) {
				$payload['metadata'] = $options['metadata'];
			}

			$encoded_payload = wp_json_encode( $payload );
			if ( false === $encoded_payload ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the request payload.', 'mcp-ai-wpoos' ) );
			}

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
				'body'    => $encoded_payload,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_vector_store_create',
				'Creating vector store.',
				array( 'name' => $name )
			);

			$response = wp_remote_post( self::VECTOR_STORES_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_event( 'openai_vector_store_create_error', 'Vector store creation failed.', array( 'error' => $response->get_error_message() ) );
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI vector store creation failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				WP_MCP_AI_Logger::log_event(
					'openai_vector_store_create_error',
					'Vector store creation failed with HTTP code ' . $http_code,
					array( 'response' => $decoded )
				);

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			WP_MCP_AI_Logger::log_event( 'openai_vector_store_create_success', 'Vector store created successfully.' );

			return $decoded;
		}

		/**
		 * List vector stores.
		 *
		 * @param array $options Optional parameters (limit, order, after, before).
		 * @return array|WP_Error Vector stores list or error.
		 */
		public function list_vector_stores( array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['order'] ) ) {
				$query_params['order'] = sanitize_key( $options['order'] );
			}

			if ( isset( $options['after'] ) ) {
				$query_params['after'] = sanitize_text_field( $options['after'] );
			}

			if ( isset( $options['before'] ) ) {
				$query_params['before'] = sanitize_text_field( $options['before'] );
			}

			$endpoint = self::VECTOR_STORES_ENDPOINT;
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
			);

			$response = wp_remote_get( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI list vector stores request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			return $decoded;
		}

		/**
		 * Retrieve a vector store.
		 *
		 * @param string $vector_store_id Vector store ID.
		 * @return array|WP_Error Vector store data or error.
		 */
		public function retrieve_vector_store( $vector_store_id ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$vector_store_id = sanitize_text_field( $vector_store_id );
			if ( empty( $vector_store_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_id',
					__( 'A vector store ID is required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id;

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
			);

			$response = wp_remote_get( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI retrieve vector store request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			return $decoded;
		}

		/**
		 * Delete a vector store.
		 *
		 * @param string $vector_store_id Vector store ID.
		 * @return array|WP_Error Deletion confirmation or error.
		 */
		public function delete_vector_store( $vector_store_id ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$vector_store_id = sanitize_text_field( $vector_store_id );
			if ( empty( $vector_store_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_id',
					__( 'A vector store ID is required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id;

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
				'method'  => 'DELETE',
			);

			$response = wp_remote_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI delete vector store request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			return $decoded;
		}

		/**
		 * Add files to a vector store.
		 *
		 * @param string $vector_store_id Vector store ID.
		 * @param array  $file_ids Array of file IDs to add.
		 * @return array|WP_Error Operation result or error.
		 */
		public function add_vector_store_files( $vector_store_id, array $file_ids ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$vector_store_id = sanitize_text_field( $vector_store_id );
			if ( empty( $vector_store_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_id',
					__( 'A vector store ID is required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			if ( empty( $file_ids ) || ! is_array( $file_ids ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_file_ids',
					__( 'File IDs are required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id . '/files';

			$payload = array(
				'file_id' => sanitize_text_field( $file_ids[0] ), // OpenAI API accepts one file at a time.
			);

			$encoded_payload = wp_json_encode( $payload );
			if ( false === $encoded_payload ) {
				return new WP_Error( 'wp_mcp_ai_encoding_error', __( 'Failed to encode the request payload.', 'mcp-ai-wpoos' ) );
			}

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
				'body'    => $encoded_payload,
			);

			$response = wp_remote_post( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI add vector store files request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			return $decoded;
		}

		/**
		 * List files in a vector store.
		 *
		 * @param string $vector_store_id Vector store ID.
		 * @param array  $options Optional parameters (limit, order, after, before).
		 * @return array|WP_Error Files list or error.
		 */
		public function list_vector_store_files( $vector_store_id, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$vector_store_id = sanitize_text_field( $vector_store_id );
			if ( empty( $vector_store_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_id',
					__( 'A vector store ID is required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$query_params = array();

			if ( isset( $options['limit'] ) ) {
				$query_params['limit'] = absint( $options['limit'] );
			}

			if ( isset( $options['order'] ) ) {
				$query_params['order'] = sanitize_key( $options['order'] );
			}

			if ( isset( $options['after'] ) ) {
				$query_params['after'] = sanitize_text_field( $options['after'] );
			}

			if ( isset( $options['before'] ) ) {
				$query_params['before'] = sanitize_text_field( $options['before'] );
			}

			$endpoint = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id . '/files';
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
			);

			$response = wp_remote_get( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI list vector store files request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			return $decoded;
		}

		/**
		 * Remove a file from a vector store.
		 *
		 * @param string $vector_store_id Vector store ID.
		 * @param string $file_id File ID to remove.
		 * @return array|WP_Error Operation result or error.
		 */
		public function remove_vector_store_file( $vector_store_id, $file_id ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$vector_store_id = sanitize_text_field( $vector_store_id );
			$file_id         = sanitize_text_field( $file_id );

			if ( empty( $vector_store_id ) || empty( $file_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_id',
					__( 'Vector store ID and file ID are required.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = self::VECTOR_STORES_ENDPOINT . '/' . $vector_store_id . '/files/' . $file_id;

			$request_args = array(
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
					'OpenAI-Beta'   => 'assistants=v2',
				),
				'timeout' => $timeout,
				'method'  => 'DELETE',
			);

			$response = wp_remote_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				return $response;
			}

			$http_code     = wp_remote_retrieve_response_code( $response );
			$response_body = wp_remote_retrieve_body( $response );
			$decoded       = json_decode( $response_body, true );

			if ( 200 !== $http_code ) {
				$error_message = __( 'OpenAI remove vector store file request failed.', 'mcp-ai-wpoos' );
				if ( isset( $decoded['error']['message'] ) ) {
					$error_message = sanitize_text_field( $decoded['error']['message'] );
				}

				return new WP_Error( 'wp_mcp_ai_openai_vector_store_error', $error_message, array( 'status' => $http_code ) );
			}

			return $decoded;
		}

		/**
		 * Create a batch processing job for asynchronous operations.
		 *
		 * The Batch API allows you to process large jobs asynchronously with 50% cost reduction
		 * compared to synchronous calls. Supported endpoints: /v1/chat/completions, /v1/embeddings,
		 * /v1/moderations.
		 *
		 * @param string $input_file_id  The ID of the uploaded input file containing batch requests (JSONL format).
		 * @param string $endpoint       The OpenAI endpoint to use for the batch (e.g., '/v1/chat/completions').
		 * @param array  $options        Optional parameters:
		 *                               - completion_window: Time frame within which the batch should be processed.
		 *                                 Default: "24h" (currently only "24h" is supported by OpenAI).
		 *                               - metadata: Custom metadata as key-value pairs (up to 16 pairs).
		 *                               - timeout: Request timeout in seconds.
		 * @return array|WP_Error Array containing batch job details or WP_Error on failure.
		 *                        Success structure:
		 *                        - id: Unique batch job ID
		 *                        - object: "batch"
		 *                        - endpoint: The endpoint used
		 *                        - input_file_id: Input file ID
		 *                        - completion_window: Completion window
		 *                        - status: Current status (validating, in_progress, completed, failed, etc.)
		 *                        - output_file_id: Output file ID (available when completed)
		 *                        - error_file_id: Error file ID (available if errors occurred)
		 *                        - created_at: Creation timestamp
		 *                        - metadata: Custom metadata
		 */
		public function create_batch( $input_file_id, $endpoint, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			// Validate input_file_id.
			$input_file_id = sanitize_text_field( (string) $input_file_id );
			if ( '' === $input_file_id ) {
				return new WP_Error(
					'wp_mcp_ai_missing_input_file_id',
					__( 'Input file ID must be provided.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Validate endpoint.
			$endpoint          = sanitize_text_field( (string) $endpoint );
			$allowed_endpoints = array( '/v1/chat/completions', '/v1/embeddings', '/v1/moderations' );
			if ( ! in_array( $endpoint, $allowed_endpoints, true ) ) {
				return new WP_Error(
					'wp_mcp_ai_invalid_batch_endpoint',
					sprintf(
						/* translators: %s: comma-separated list of allowed endpoints */
						__( 'Invalid batch endpoint. Allowed endpoints: %s', 'mcp-ai-wpoos' ),
						implode( ', ', $allowed_endpoints )
					),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			// Build payload.
			$payload = array(
				'input_file_id'     => $input_file_id,
				'endpoint'          => $endpoint,
				'completion_window' => isset( $options['completion_window'] ) && '' !== $options['completion_window'] ? sanitize_text_field( $options['completion_window'] ) : '24h',
			);

			// Add optional metadata.
			if ( isset( $options['metadata'] ) && is_array( $options['metadata'] ) && ! empty( $options['metadata'] ) ) {
				$metadata = array();
				foreach ( $options['metadata'] as $key => $value ) {
					$sanitized_key   = sanitize_text_field( $key );
					$sanitized_value = sanitize_text_field( $value );
					if ( '' !== $sanitized_key && '' !== $sanitized_value ) {
						$metadata[ $sanitized_key ] = $sanitized_value;
					}
				}
				if ( ! empty( $metadata ) ) {
					$payload['metadata'] = $metadata;
				}
			}

			$request_args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
					'Content-Type'  => 'application/json',
				),
				'body'    => wp_json_encode( $payload ),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_create_batch',
				'Creating batch job with OpenAI.',
				array(
					'endpoint'      => $endpoint,
					'input_file_id' => $input_file_id,
				)
			);

			$response = $this->dispatch_http_request( self::BATCHES_ENDPOINT, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI create batch request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_create_batch_http_error',
					__( 'The OpenAI create batch request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI create batch response.',
					array( 'body' => $body )
				);

				return new WP_Error(
					'wp_mcp_ai_create_batch_invalid_response',
					__( 'OpenAI returned malformed JSON for the batch creation.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI create batch returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI create batch request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_create_batch_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_batch_created',
				'Batch job created successfully.',
				array(
					'batch_id' => isset( $decoded['id'] ) ? $decoded['id'] : '',
					'status'   => isset( $decoded['status'] ) ? $decoded['status'] : '',
				)
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Retrieve information about a specific batch job.
		 *
		 * @param string $batch_id The ID of the batch to retrieve.
		 * @param array  $options  Optional parameters (timeout).
		 * @return array|WP_Error Array containing batch job details or WP_Error on failure.
		 */
		public function retrieve_batch( $batch_id, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$batch_id = sanitize_text_field( (string) $batch_id );
			if ( '' === $batch_id ) {
				return new WP_Error(
					'wp_mcp_ai_missing_batch_id',
					__( 'Batch ID must be provided.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = self::BATCHES_ENDPOINT . '/' . rawurlencode( $batch_id );

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_retrieve_batch',
				'Retrieving batch job from OpenAI.',
				array( 'batch_id' => $batch_id )
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI retrieve batch request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_retrieve_batch_http_error',
					__( 'The OpenAI retrieve batch request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI retrieve batch response.',
					array( 'body' => $body )
				);

				return new WP_Error(
					'wp_mcp_ai_retrieve_batch_invalid_response',
					__( 'OpenAI returned malformed JSON for the batch retrieval.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI retrieve batch returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI retrieve batch request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_retrieve_batch_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Cancel a batch processing job.
		 *
		 * @param string $batch_id The ID of the batch to cancel.
		 * @param array  $options  Optional parameters (timeout).
		 * @return array|WP_Error Array containing updated batch details or WP_Error on failure.
		 */
		public function cancel_batch( $batch_id, array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$batch_id = sanitize_text_field( (string) $batch_id );
			if ( '' === $batch_id ) {
				return new WP_Error(
					'wp_mcp_ai_missing_batch_id',
					__( 'Batch ID must be provided.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			$endpoint = self::BATCHES_ENDPOINT . '/' . rawurlencode( $batch_id ) . '/cancel';

			$request_args = array(
				'method'  => 'POST',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_cancel_batch',
				'Cancelling batch job with OpenAI.',
				array( 'batch_id' => $batch_id )
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI cancel batch request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_cancel_batch_http_error',
					__( 'The OpenAI cancel batch request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI cancel batch response.',
					array( 'body' => $body )
				);

				return new WP_Error(
					'wp_mcp_ai_cancel_batch_invalid_response',
					__( 'OpenAI returned malformed JSON for the batch cancellation.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI cancel batch returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI cancel batch request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_cancel_batch_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_batch_cancelled',
				'Batch job cancelled successfully.',
				array( 'batch_id' => $batch_id )
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * List batch processing jobs with optional filtering.
		 *
		 * @param array $options Optional parameters:
		 *                       - after: Batch ID cursor for pagination (retrieve batches after this ID).
		 *                       - limit: Maximum number of batches to return (1-100, default 20).
		 *                       - timeout: Request timeout in seconds.
		 * @return array|WP_Error Array containing list of batch jobs or WP_Error on failure.
		 *                        Success structure:
		 *                        - object: "list"
		 *                        - data: Array of batch job objects
		 *                        - first_id: ID of the first batch in the list
		 *                        - last_id: ID of the last batch in the list
		 *                        - has_more: Whether there are more batches available
		 */
		public function list_batches( array $options = array() ) {
			$api_key = $this->get_api_key();

			if ( empty( $api_key ) ) {
				return new WP_Error(
					'wp_mcp_ai_missing_api_key',
					__( 'No OpenAI API key has been configured.', 'mcp-ai-wpoos' ),
					array(
						'status'  => 400,
						'actions' => array(
							'configure_openai_api_key' => __( 'Add an OpenAI API key in the NV oOS settings.', 'mcp-ai-wpoos' ),
						),
					)
				);
			}

			$settings = WP_MCP_AI_Admin_Settings::get_settings();
			$timeout  = isset( $options['timeout'] ) && '' !== $options['timeout'] ? absint( $options['timeout'] ) : absint( $settings['request_timeout'] );
			$timeout  = max( 5, $timeout );

			// Build query parameters.
			$query_params = array();

			if ( isset( $options['after'] ) && '' !== $options['after'] ) {
				$query_params['after'] = sanitize_text_field( $options['after'] );
			}

			if ( isset( $options['limit'] ) && '' !== $options['limit'] ) {
				$limit                 = absint( $options['limit'] );
				$limit                 = max( 1, min( 100, $limit ) ); // Clamp between 1 and 100.
				$query_params['limit'] = $limit;
			}

			$endpoint = self::BATCHES_ENDPOINT;
			if ( ! empty( $query_params ) ) {
				$endpoint .= '?' . http_build_query( $query_params );
			}

			$request_args = array(
				'method'  => 'GET',
				'headers' => array(
					'Authorization' => 'Bearer ' . $api_key,
				),
				'timeout' => $timeout,
			);

			WP_MCP_AI_Logger::log_event(
				'openai_list_batches',
				'Listing batch jobs from OpenAI.',
				array( 'params' => $query_params )
			);

			$response = $this->dispatch_http_request( $endpoint, $request_args );

			if ( is_wp_error( $response ) ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI list batches request failed.',
					array( 'error' => $response->get_error_message() )
				);

				return WP_MCP_AI_HTTP::prepare_transport_error(
					$response,
					'wp_mcp_ai_list_batches_http_error',
					__( 'The OpenAI list batches request failed.', 'mcp-ai-wpoos' ),
					__( 'OpenAI', 'mcp-ai-wpoos' )
				);
			}

			$code    = wp_remote_retrieve_response_code( $response );
			$body    = wp_remote_retrieve_body( $response );
			$decoded = json_decode( $body, true );

			if ( JSON_ERROR_NONE !== json_last_error() ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to decode OpenAI list batches response.',
					array( 'body' => $body )
				);

				return new WP_Error(
					'wp_mcp_ai_list_batches_invalid_response',
					__( 'OpenAI returned malformed JSON for the batches list.', 'mcp-ai-wpoos' ),
					array( 'status' => 500 )
				);
			}

			if ( $code < 200 || $code >= 300 ) {
				WP_MCP_AI_Logger::log_error(
					'OpenAI list batches returned an error.',
					array(
						'code' => $code,
						'body' => $decoded,
					)
				);

				$message = isset( $decoded['error']['message'] ) ? $decoded['error']['message'] : __( 'The OpenAI list batches request failed.', 'mcp-ai-wpoos' );

				return new WP_Error(
					'wp_mcp_ai_list_batches_error',
					$message,
					array(
						'status'   => $code,
						'response' => $decoded,
					)
				);
			}

			WP_MCP_AI_Logger::log_event(
				'openai_batches_listed',
				'Batch jobs list retrieved successfully.',
				array( 'count' => isset( $decoded['data'] ) && is_array( $decoded['data'] ) ? count( $decoded['data'] ) : 0 )
			);

			return is_array( $decoded ) ? $decoded : array();
		}

		/**
		 * Execute a chat completion with tools and recursive tool execution.
		 *
		 * This method provides automatic tool execution in a loop, similar to the Cloudflare
		 * implementation. It will:
		 * 1. Send messages with tool definitions to OpenAI
		 * 2. If tools are called, execute them and add results to conversation
		 * 3. Repeat until no more tools are needed or max recursion is reached
		 *
		 * @since 1.0.0
		 *
		 * @param array $messages Array of conversation messages.
		 * @param array $tools    Array of tool definitions with executable functions.
		 * @param array $options  Optional configuration:
		 *                        - strictValidation (bool): Validate arguments before execution. Default: true.
		 *                        - maxRecursiveToolRuns (int): Maximum recursion depth. Default: 5.
		 *                        - streamFinalResponse (bool): Enable streaming (not implemented for PHP). Default: false.
		 *                        - verbose (bool): Detailed logging. Default: false.
		 *                        - autoTrimTools (bool): Context-based tool selection. Default: false.
		 *                        - maxTools (int): Max tools when trimming. Default: 10.
		 *                        - model, temperature, timeout, etc.
		 * @return array|WP_Error Final response or error.
		 * @throws Exception When tool function is not callable.
		 */
		public function run_with_tools( array $messages, array $tools = array(), array $options = array() ) {
			// Configuration options with defaults.
			$strict_validation     = isset( $options['strictValidation'] ) ? (bool) $options['strictValidation'] : true;
			$max_recursive_runs    = isset( $options['maxRecursiveToolRuns'] ) ? absint( $options['maxRecursiveToolRuns'] ) : 5;
			$stream_final_response = isset( $options['streamFinalResponse'] ) ? (bool) $options['streamFinalResponse'] : false;
			$verbose               = isset( $options['verbose'] ) ? (bool) $options['verbose'] : false;
			$auto_trim_tools       = isset( $options['autoTrimTools'] ) ? (bool) $options['autoTrimTools'] : false;

			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					'openai_run_with_tools_start',
					'Starting OpenAI embedded function calling.',
					array(
						'message_count'      => count( $messages ),
						'tool_count'         => count( $tools ),
						'strict_validation'  => $strict_validation,
						'max_recursive_runs' => $max_recursive_runs,
						'auto_trim_tools'    => $auto_trim_tools,
					)
				);
			}

			// Validate tools array.
			if ( empty( $tools ) ) {
				return new WP_Error(
					'wp_mcp_ai_no_tools',
					__( 'At least one tool must be provided for embedded function calling.', 'mcp-ai-wpoos' ),
					array( 'status' => 400 )
				);
			}

			// Auto-trim tools if enabled.
			if ( $auto_trim_tools ) {
				$tools = $this->auto_trim_tools( $messages, $tools, $options );
				if ( $verbose ) {
					WP_MCP_AI_Logger::log_event(
						'openai_auto_trim_tools',
						'Automatically trimmed tools based on context.',
						array( 'remaining_tool_count' => count( $tools ) )
					);
				}
			}

			// Convert tools to OpenAI format and create tool lookup.
			$tool_definitions = array();
			$tool_functions   = array();

			foreach ( $tools as $tool ) {
				if ( ! isset( $tool['name'] ) || ! isset( $tool['function'] ) ) {
					continue;
				}

				$tool_name = sanitize_text_field( $tool['name'] );

				// Build tool definition for API.
				$definition = array(
					'name'        => $tool_name,
					'description' => isset( $tool['description'] ) ? sanitize_text_field( $tool['description'] ) : '',
				);

				if ( isset( $tool['parameters'] ) && is_array( $tool['parameters'] ) ) {
					$definition['parameters'] = $tool['parameters'];
				}

				$tool_definitions[] = array(
					'type'     => 'function',
					'function' => $definition,
				);

				// Store executable function.
				$tool_functions[ $tool_name ] = $tool['function'];
			}

			// Prepare options with tools.
			$request_options          = $options;
			$request_options['tools'] = $tool_definitions;

			// Execute recursive tool calling loop.
			$conversation_messages = $messages;
			$recursion_count       = 0;

			while ( $recursion_count < $max_recursive_runs ) {
				++$recursion_count;

				if ( $verbose ) {
					WP_MCP_AI_Logger::log_event(
						'openai_tool_run_iteration',
						sprintf( 'Tool execution iteration %d/%d', $recursion_count, $max_recursive_runs ),
						array( 'message_count' => count( $conversation_messages ) )
					);
				}

				// Make API request.
				$response = $this->create_chat_completion( $conversation_messages, $request_options );

				if ( is_wp_error( $response ) ) {
					return $response;
				}

				// Check if model wants to call any tools.
				$tool_calls = array();
				if ( isset( $response['choices'][0]['message']['tool_calls'] ) ) {
					$tool_calls = $response['choices'][0]['message']['tool_calls'];
				}

				// If no tool calls, we're done.
				if ( empty( $tool_calls ) ) {
					if ( $verbose ) {
						WP_MCP_AI_Logger::log_event(
							'openai_run_with_tools_complete',
							'Completed without tool calls.',
							array( 'iterations' => $recursion_count )
						);
					}

					// Return final response (streaming not implemented for PHP).
					return $response;
				}

				// Add assistant's tool call message to conversation.
				$conversation_messages[] = $response['choices'][0]['message'];

				// Execute each tool call.
				foreach ( $tool_calls as $tool_call ) {
					if ( ! isset( $tool_call['function']['name'] ) ) {
						continue;
					}

					$function_name = $tool_call['function']['name'];
					$tool_call_id  = isset( $tool_call['id'] ) ? $tool_call['id'] : uniqid( 'tool-', true );

					// Check if function exists.
					if ( ! isset( $tool_functions[ $function_name ] ) ) {
						$error_message = sprintf(
							/* translators: %s: function name */
							__( 'Tool function "%s" not found.', 'mcp-ai-wpoos' ),
							$function_name
						);

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => wp_json_encode( array( 'error' => $error_message ) ),
						);

						WP_MCP_AI_Logger::log_error(
							'OpenAI tool function not found.',
							array(
								'function_name' => $function_name,
								'tool_call_id'  => $tool_call_id,
							)
						);
						continue;
					}

					// Parse arguments.
					$arguments = array();
					if ( isset( $tool_call['function']['arguments'] ) ) {
						$args_json = $tool_call['function']['arguments'];
						if ( is_string( $args_json ) ) {
							$arguments = json_decode( $args_json, true );
							if ( JSON_ERROR_NONE !== json_last_error() ) {
								$arguments = array();
							}
						} elseif ( is_array( $args_json ) ) {
							$arguments = $args_json;
						}
					}

					// Validate arguments if strict validation is enabled.
					if ( $strict_validation ) {
						$validation_error = $this->validate_tool_arguments( $function_name, $arguments, $tool_definitions );
						if ( is_wp_error( $validation_error ) ) {
							$conversation_messages[] = array(
								'role'         => 'tool',
								'tool_call_id' => $tool_call_id,
								'name'         => $function_name,
								'content'      => wp_json_encode( array( 'error' => $validation_error->get_error_message() ) ),
							);

							WP_MCP_AI_Logger::log_error(
								'OpenAI tool argument validation failed.',
								array(
									'function_name' => $function_name,
									'error'         => $validation_error->get_error_message(),
								)
							);
							continue;
						}
					}

					// Execute the tool function.
					try {
						$function_callable = $tool_functions[ $function_name ];

						if ( ! is_callable( $function_callable ) ) {
							throw new Exception( 'Tool function is not callable.' );
						}

						$result = call_user_func( $function_callable, $arguments );

						// Convert result to JSON string.
						$result_content = is_string( $result ) ? $result : wp_json_encode( $result );

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => $result_content,
						);

						if ( $verbose ) {
							WP_MCP_AI_Logger::log_event(
								'openai_tool_executed',
								sprintf( 'Executed tool: %s', $function_name ),
								array(
									'function_name' => $function_name,
									'tool_call_id'  => $tool_call_id,
									'result_length' => strlen( $result_content ),
								)
							);
						}
					} catch ( Exception $e ) {
						$error_message = $e->getMessage();

						$conversation_messages[] = array(
							'role'         => 'tool',
							'tool_call_id' => $tool_call_id,
							'name'         => $function_name,
							'content'      => wp_json_encode( array( 'error' => $error_message ) ),
						);

						WP_MCP_AI_Logger::log_error(
							'OpenAI tool execution failed.',
							array(
								'function_name' => $function_name,
								'error'         => $error_message,
							)
						);
					}
				}
			}

			// Max recursion reached.
			if ( $verbose ) {
				WP_MCP_AI_Logger::log_event(
					'openai_max_recursion_reached',
					'Maximum recursive tool runs reached.',
					array( 'max_runs' => $max_recursive_runs )
				);
			}

			return new WP_Error(
				'wp_mcp_ai_max_tool_recursion',
				__( 'Maximum recursive tool runs reached without completion.', 'mcp-ai-wpoos' ),
				array(
					'status'         => 500,
					'max_runs'       => $max_recursive_runs,
					'final_messages' => $conversation_messages,
				)
			);
		}

		/**
		 * Validate tool arguments against the tool definition schema.
		 *
		 * @since 1.0.0
		 *
		 * @param string $function_name    Name of the function being called.
		 * @param array  $arguments        Arguments provided by the model.
		 * @param array  $tool_definitions Array of tool definitions.
		 * @return true|WP_Error True if valid, WP_Error otherwise.
		 */
		protected function validate_tool_arguments( $function_name, $arguments, $tool_definitions ) {
			// Find the tool definition.
			$tool_schema = null;
			foreach ( $tool_definitions as $tool_def ) {
				if ( isset( $tool_def['function']['name'] ) && $tool_def['function']['name'] === $function_name ) {
					$tool_schema = isset( $tool_def['function']['parameters'] ) ? $tool_def['function']['parameters'] : null;
					break;
				}
			}

			if ( null === $tool_schema ) {
				return true; // No schema to validate against.
			}

			// Check required parameters.
			if ( isset( $tool_schema['required'] ) && is_array( $tool_schema['required'] ) ) {
				foreach ( $tool_schema['required'] as $required_param ) {
					if ( ! isset( $arguments[ $required_param ] ) ) {
						return new WP_Error(
							'wp_mcp_ai_missing_required_param',
							sprintf(
								/* translators: %1$s: parameter name, %2$s: function name */
								__( 'Required parameter "%1$s" missing for tool "%2$s".', 'mcp-ai-wpoos' ),
								$required_param,
								$function_name
							),
							array( 'parameter' => $required_param )
						);
					}
				}
			}

			// Validate parameter types if schema includes type definitions.
			if ( isset( $tool_schema['properties'] ) && is_array( $tool_schema['properties'] ) ) {
				foreach ( $arguments as $param_name => $param_value ) {
					if ( ! isset( $tool_schema['properties'][ $param_name ] ) ) {
						// Ignore extra parameters (non-strict mode).
						continue;
					}

					$param_schema = $tool_schema['properties'][ $param_name ];
					if ( ! isset( $param_schema['type'] ) ) {
						continue;
					}

					$expected_type = $param_schema['type'];
					$actual_type   = gettype( $param_value );

					// Map PHP types to JSON Schema types.
					$type_map = array(
						'boolean' => 'boolean',
						'integer' => 'number',
						'double'  => 'number',
						'string'  => 'string',
						'array'   => 'array',
						'object'  => 'object',
						'NULL'    => 'null',
					);

					$mapped_type = isset( $type_map[ $actual_type ] ) ? $type_map[ $actual_type ] : $actual_type;

					// Allow integer for number type.
					if ( 'number' === $expected_type && in_array( $mapped_type, array( 'number', 'integer' ), true ) ) {
						continue;
					}

					if ( $expected_type !== $mapped_type ) {
						return new WP_Error(
							'wp_mcp_ai_invalid_param_type',
							sprintf(
								/* translators: %1$s: parameter name, %2$s: expected type, %3$s: actual type */
								__( 'Parameter "%1$s" expected type "%2$s" but got "%3$s".', 'mcp-ai-wpoos' ),
								$param_name,
								$expected_type,
								$mapped_type
							),
							array(
								'parameter'     => $param_name,
								'expected_type' => $expected_type,
								'actual_type'   => $mapped_type,
							)
						);
					}
				}
			}

			return true;
		}

		/**
		 * Automatically trim tools based on context to reduce token usage.
		 *
		 * This is a simplified implementation that keeps tools relevant to the conversation.
		 * Uses keyword matching to score tool relevance based on name and description.
		 *
		 * @since 1.0.0
		 *
		 * @param array $messages Message history.
		 * @param array $tools    Array of tool definitions.
		 * @param array $options  Request options.
		 * @return array Trimmed tools array.
		 */
		protected function auto_trim_tools( $messages, $tools, $options = array() ) {
			// Get the last user message to determine relevance.
			$last_user_message = '';
			for ( $i = count( $messages ) - 1; $i >= 0; $i-- ) {
				if ( isset( $messages[ $i ]['role'] ) && 'user' === $messages[ $i ]['role'] ) {
					$last_user_message = isset( $messages[ $i ]['content'] ) ? strtolower( (string) $messages[ $i ]['content'] ) : '';
					break;
				}
			}

			if ( empty( $last_user_message ) || empty( $tools ) ) {
				return $tools;
			}

			// Score each tool based on relevance.
			$scored_tools = array();
			foreach ( $tools as $tool ) {
				$score = 0;

				// Check name relevance.
				if ( isset( $tool['name'] ) ) {
					$tool_name  = strtolower( str_replace( array( '-', '_' ), ' ', $tool['name'] ) );
					$name_words = explode( ' ', $tool_name );
					foreach ( $name_words as $word ) {
						if ( ! empty( $word ) && false !== strpos( $last_user_message, $word ) ) {
							$score += 3; // Higher weight for name match.
						}
					}
				}

				// Check description relevance.
				if ( isset( $tool['description'] ) ) {
					$tool_desc  = strtolower( $tool['description'] );
					$desc_words = explode( ' ', $tool_desc );
					foreach ( $desc_words as $word ) {
						if ( strlen( $word ) > 3 && false !== strpos( $last_user_message, $word ) ) {
							++$score;
						}
					}
				}

				$scored_tools[] = array(
					'tool'  => $tool,
					'score' => $score,
				);
			}

			// Sort by score (descending).
			usort(
				$scored_tools,
				function ( $a, $b ) {
					return $b['score'] - $a['score'];
				}
			);

			// Keep top tools (limit to maxTools option).
			$max_tools     = isset( $options['maxTools'] ) ? absint( $options['maxTools'] ) : 10;
			$trimmed_tools = array();

			foreach ( array_slice( $scored_tools, 0, $max_tools ) as $scored ) {
				// Only include tools with a relevance score.
				if ( $scored['score'] > 0 || count( $trimmed_tools ) < 3 ) {
					// Always keep at least 3 tools even if score is 0.
					$trimmed_tools[] = $scored['tool'];
				}
			}

			// If no tools passed the relevance test, keep all original tools.
			if ( empty( $trimmed_tools ) ) {
				return $tools;
			}

			return $trimmed_tools;
		}
	}
}
