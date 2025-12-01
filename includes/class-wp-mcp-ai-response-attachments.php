<?php
/**
 * Persist assistant response files to the Media Library.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Response_Attachments' ) ) {
	/**
	 * Downloads OpenAI response files and registers them as attachments.
	 */
	class WP_MCP_AI_Response_Attachments {

		/**
		 * Track whether hooks have been registered.
		 *
		 * @var bool
		 */
		protected static $initialised = false;

		/**
		 * Bootstrap the response attachment handler.
		 */
		public static function init() {
			if ( self::$initialised ) {
				return;
			}

			self::$initialised = true;

			add_action( 'wp_mcp_ai_after_chat_response', array( __CLASS__, 'handle_chat_response' ), 10, 3 );
		}

		/**
		 * Inspect the assistant response and download any referenced files.
		 *
		 * @param int             $assistant_id Assistant identifier.
		 * @param array           $response     Chat response payload.
		 * @param WP_REST_Request $request      REST request instance.
		 */
		public static function handle_chat_response( $assistant_id, $response, $request ) {
			if ( empty( $response ) || ! is_array( $response ) ) {
				return;
			}

			$segments = self::collect_file_segments_from_response( $response );

			if ( empty( $segments ) ) {
				return;
			}

			$helper    = new WP_MCP_AI_Message_Attachments();
			$client    = new WP_MCP_AI_OpenAI_Client();
			$processed = array();

			foreach ( $segments as $segment ) {
				if ( ! is_array( $segment ) ) {
					continue;
				}

				$file_id = self::extract_file_id_from_segment( $segment );

				if ( '' === $file_id || isset( $processed[ $file_id ] ) ) {
					continue;
				}

				$existing_attachment = $helper->get_attachment_id_for_openai_file( $file_id );
				if ( $existing_attachment ) {
					$processed[ $file_id ] = $existing_attachment;
					continue;
				}

				$download = $client->download_file( $file_id );
				if ( is_wp_error( $download ) ) {
					WP_MCP_AI_Logger::log_error(
						'Failed to download response file from OpenAI.',
						array(
							'file_id' => $file_id,
							'error'   => $download->get_error_message(),
						)
					);
					continue;
				}

				$stored = self::store_downloaded_file( $download, $segment );

				if ( is_wp_error( $stored ) ) {
					WP_MCP_AI_Logger::log_error(
						'Failed to save OpenAI response file to the Media Library.',
						array(
							'file_id' => $file_id,
							'error'   => $stored->get_error_message(),
						)
					);
					continue;
				}

				$metadata = array(
					'file_id'    => $file_id,
					'filename'   => $stored['file_name'],
					'mime_type'  => $stored['mime_type'],
					'bytes'      => $stored['bytes'],
					'hash'       => $stored['hash'],
					'purpose'    => 'assistants',
					'created_at' => time(),
				);

				$helper->save_openai_file_metadata_for_attachment( $stored['attachment_id'], $metadata );
				WP_MCP_AI_Logger::log_event(
					'response_file_downloaded',
					'Stored OpenAI response file as a media attachment.',
					array(
						'file_id'       => $file_id,
						'attachment_id' => $stored['attachment_id'],
						'mime_type'     => $stored['mime_type'],
					)
				);

				$processed[ $file_id ] = $stored['attachment_id'];
			}

			// Intentionally avoid mutating the request payload. Persisted attachments.
			// are discoverable via the Media Library metadata.
		}

		/**
		 * Collect content segments that reference OpenAI files.
		 *
		 * @param array $response Chat response payload.
		 * @return array
		 */
		protected static function collect_file_segments_from_response( array $response ) {
			$segments = array();

			if ( isset( $response['response'] ) && is_array( $response['response'] ) ) {
				$segments = array_merge( $segments, self::collect_file_segments_from_response( $response['response'] ) );
			}

			if ( isset( $response['choices'] ) && is_array( $response['choices'] ) ) {
				foreach ( $response['choices'] as $choice ) {
					if ( isset( $choice['message'] ) && is_array( $choice['message'] ) ) {
						if ( isset( $choice['message']['content'] ) ) {
							$segments = array_merge( $segments, self::collect_file_segments_from_content( $choice['message']['content'] ) );
						}
					}

					if ( isset( $choice['content'] ) ) {
						$segments = array_merge( $segments, self::collect_file_segments_from_content( $choice['content'] ) );
					}

					if ( isset( $choice['delta']['content'] ) ) {
						$segments = array_merge( $segments, self::collect_file_segments_from_content( $choice['delta']['content'] ) );
					}
				}
			}

			if ( isset( $response['output'] ) ) {
				$segments = array_merge( $segments, self::collect_file_segments_from_content( $response['output'] ) );
			}

			return $segments;
		}

		/**
		 * Normalise a content payload into an array of potential file segments.
		 *
		 * @param mixed $content Message content payload.
		 * @return array
		 */
		protected static function collect_file_segments_from_content( $content ) {
			if ( $content instanceof Traversable ) {
				$content = iterator_to_array( $content );
			}

			if ( is_object( $content ) ) {
				$content = (array) $content;
			}

			if ( is_string( $content ) || is_numeric( $content ) || empty( $content ) ) {
				return array();
			}

			$segments = array();

			if ( is_array( $content ) && self::looks_like_segment( $content ) ) {
				$segments[] = $content;

				if ( isset( $content['content'] ) ) {
					$segments = array_merge( $segments, self::collect_file_segments_from_content( $content['content'] ) );
				}

				if ( isset( $content['metadata'] ) ) {
					$segments = array_merge( $segments, self::collect_file_segments_from_content( $content['metadata'] ) );
				}

				return $segments;
			}

			if ( is_array( $content ) ) {
				foreach ( $content as $entry ) {
					$segments = array_merge( $segments, self::collect_file_segments_from_content( $entry ) );
				}
			}

			return $segments;
		}

		/**
		 * Determine whether the array resembles a content segment.
		 *
		 * @param array $value Candidate segment.
		 * @return bool
		 */
		protected static function looks_like_segment( array $value ) {
			return isset( $value['type'] ) || isset( $value['file_id'] ) || isset( $value['image'] ) || isset( $value['image_file'] ) || isset( $value['file'] );
		}

		/**
		 * Extract the OpenAI file identifier from a content segment.
		 *
		 * @param array $segment Content segment payload.
		 * @return string
		 */
		protected static function extract_file_id_from_segment( array $segment ) {
			$candidates = array();

			foreach ( array( 'file_id', 'id' ) as $key ) {
				if ( isset( $segment[ $key ] ) && is_string( $segment[ $key ] ) ) {
					$candidates[] = $segment[ $key ];
				}
			}

			$nested_sources = array( 'file', 'image', 'image_file', 'file_path', 'audio' );

			foreach ( $nested_sources as $source ) {
				if ( empty( $segment[ $source ] ) ) {
					continue;
				}

				$candidate = $segment[ $source ];

				if ( is_array( $candidate ) ) {
					if ( isset( $candidate['file_id'] ) ) {
						$candidates[] = $candidate['file_id'];
					} elseif ( isset( $candidate['id'] ) ) {
						$candidates[] = $candidate['id'];
					}
				} elseif ( is_string( $candidate ) ) {
					$candidates[] = $candidate;
				}
			}

			if ( isset( $segment['annotations'] ) ) {
				$candidates = array_merge( $candidates, self::extract_file_ids_from_nested( $segment['annotations'] ) );
			}

			foreach ( $candidates as $candidate ) {
				$candidate = sanitize_text_field( (string) $candidate );

				if ( '' !== $candidate && false !== strpos( $candidate, 'file-' ) ) {
					return $candidate;
				}
			}

			return '';
		}

		/**
		 * Extract potential file identifiers from nested annotation payloads.
		 *
		 * @param mixed $payload Annotation payload.
		 * @return array
		 */
		protected static function extract_file_ids_from_nested( $payload ) {
			if ( $payload instanceof Traversable ) {
				$payload = iterator_to_array( $payload );
			}

			if ( is_object( $payload ) ) {
				$payload = (array) $payload;
			}

			if ( empty( $payload ) ) {
				return array();
			}

			$collected = array();

			if ( is_array( $payload ) && isset( $payload['file_id'] ) ) {
				$collected[] = $payload['file_id'];
			}

			if ( is_array( $payload ) ) {
				foreach ( $payload as $value ) {
					if ( is_array( $value ) || is_object( $value ) ) {
						$collected = array_merge( $collected, self::extract_file_ids_from_nested( $value ) );
					}
				}
			}

			return $collected;
		}

		/**
		 * Store the downloaded file on disk and register it as a media attachment.
		 *
		 * @param array $download Download response from OpenAI.
		 * @param array $segment  Original content segment.
		 * @return array|WP_Error
		 */
		protected static function store_downloaded_file( array $download, array $segment ) {
			$data = isset( $download['body'] ) ? $download['body'] : '';

			if ( '' === $data ) {
				return new WP_Error( 'wp_mcp_ai_response_file_empty', __( 'The downloaded response file was empty.', 'wp-mcp-ai' ) );
			}

			$filename = '';
			if ( ! empty( $download['filename'] ) ) {
				$filename = sanitize_file_name( $download['filename'] );
			}

			if ( '' === $filename ) {
				$filename = self::extract_filename_from_segment( $segment );
			}

			$content_type = isset( $download['content_type'] ) ? sanitize_text_field( $download['content_type'] ) : '';

			$filename = self::ensure_filename_extension( $filename, $content_type );

			if ( '' === $filename ) {
				$filename = self::generate_fallback_filename( $content_type );
			}

			if ( ! function_exists( 'wp_upload_bits' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$upload = wp_upload_bits( $filename, null, $data );

			if ( ! empty( $upload['error'] ) ) {
				return new WP_Error( 'wp_mcp_ai_response_file_upload_failed', $upload['error'] );
			}

			$file_path = isset( $upload['file'] ) ? $upload['file'] : '';

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error( 'wp_mcp_ai_response_file_missing', __( 'The response file could not be written to disk.', 'wp-mcp-ai' ) );
			}

			$mime_type = self::normalise_mime_type( $content_type, $file_path );

			$attachment = array(
				'post_mime_type' => $mime_type,
				'post_title'     => self::generate_attachment_title( $filename ),
				'post_content'   => '',
				'post_status'    => 'inherit',
			);

			$author_id = get_current_user_id();
			if ( $author_id ) {
				$attachment['post_author'] = $author_id;
			}

			$attachment_id = wp_insert_attachment( $attachment, $file_path );

			if ( is_wp_error( $attachment_id ) ) {
				self::delete_file_safely( $file_path );

				return $attachment_id;
			}

			if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
				require_once ABSPATH . 'wp-admin/includes/image.php';
			}

			$metadata = wp_generate_attachment_metadata( $attachment_id, $file_path );
			if ( is_array( $metadata ) && ! empty( $metadata ) ) {
				wp_update_attachment_metadata( $attachment_id, $metadata );
			}

			$bytes = file_exists( $file_path ) ? filesize( $file_path ) : 0;
			$hash  = is_readable( $file_path ) ? md5_file( $file_path ) : '';

			return array(
				'attachment_id' => (int) $attachment_id,
				'file_path'     => $file_path,
				'file_name'     => wp_basename( $file_path ),
				'mime_type'     => $mime_type,
				'bytes'         => $bytes ? (int) $bytes : 0,
				'hash'          => $hash,
			);
		}

		/**
		 * Extract a suggested filename from the response segment.
		 *
		 * @param array $segment Content segment payload.
		 * @return string
		 */
		protected static function extract_filename_from_segment( array $segment ) {
			$candidates = array();

			foreach ( array( 'filename', 'display_name', 'name' ) as $key ) {
				if ( isset( $segment[ $key ] ) && is_string( $segment[ $key ] ) ) {
					$candidates[] = $segment[ $key ];
				}
			}

			$nested = array( 'file', 'image_file', 'image', 'file_path' );

			foreach ( $nested as $key ) {
				if ( isset( $segment[ $key ] ) && is_array( $segment[ $key ] ) ) {
					foreach ( array( 'filename', 'display_name', 'name' ) as $nested_key ) {
						if ( isset( $segment[ $key ][ $nested_key ] ) && is_string( $segment[ $key ][ $nested_key ] ) ) {
							$candidates[] = $segment[ $key ][ $nested_key ];
						}
					}
				}
			}

			foreach ( $candidates as $candidate ) {
				$candidate = sanitize_file_name( $candidate );
				if ( '' !== $candidate ) {
					return $candidate;
				}
			}

			return '';
		}

		/**
		 * Ensure a filename has an appropriate extension for the supplied MIME type.
		 *
		 * @param string $filename     Raw filename.
		 * @param string $content_type MIME type returned by the API.
		 * @return string
		 */
		protected static function ensure_filename_extension( $filename, $content_type ) {
			$filename = sanitize_file_name( (string) $filename );

			$extension = pathinfo( $filename, PATHINFO_EXTENSION );
			if ( '' !== $extension ) {
				return $filename;
			}

			$mapped_extension = self::map_mime_type_to_extension( $content_type );

			if ( '' !== $mapped_extension ) {
				return $filename ? $filename . '.' . $mapped_extension : 'openai-file.' . $mapped_extension;
			}

			return $filename;
		}

		/**
		 * Generate a fallback filename when none was provided.
		 *
		 * @param string $content_type MIME type string.
		 * @return string
		 */
		protected static function generate_fallback_filename( $content_type ) {
			$extension = self::map_mime_type_to_extension( $content_type );

			if ( '' === $extension ) {
				$extension = 'bin';
			}

			return sprintf( 'openai-file-%s.%s', gmdate( 'Ymd-His' ), $extension );
		}

		/**
		 * Determine the most appropriate MIME type for the saved file.
		 *
		 * @param string $content_type MIME type reported by the API.
		 * @param string $file_path    Absolute file path.
		 * @return string
		 */
		protected static function normalise_mime_type( $content_type, $file_path ) {
			$content_type = sanitize_text_field( (string) $content_type );

			if ( '' !== $content_type && 'application/octet-stream' !== strtolower( $content_type ) ) {
				return $content_type;
			}

			$file_info = wp_check_filetype( wp_basename( $file_path ), null );

			if ( ! empty( $file_info['type'] ) ) {
				return $file_info['type'];
			}

			return 'application/octet-stream';
		}

		/**
		 * Convert a MIME type into a likely file extension.
		 *
		 * @param string $content_type MIME type string.
		 * @return string
		 */
		protected static function map_mime_type_to_extension( $content_type ) {
			$content_type = strtolower( trim( (string) $content_type ) );

			if ( '' === $content_type ) {
				return '';
			}

			$lookup = wp_get_mime_types();

			$map = array();
			foreach ( $lookup as $extensions => $mime ) {
				$variants = explode( '|', $extensions );
				foreach ( $variants as $ext ) {
					$map[ strtolower( $mime ) ] = $ext;
				}
			}

			if ( isset( $map[ $content_type ] ) ) {
				return $map[ $content_type ];
			}

			if ( false !== strpos( $content_type, ';' ) ) {
				$primary = trim( strstr( $content_type, ';', true ) );
				if ( '' !== $primary && isset( $map[ $primary ] ) ) {
					return $map[ $primary ];
				}
			}

			return '';
		}

		/**
		 * Generate a human readable attachment title from the filename.
		 *
		 * @param string $filename Stored filename.
		 * @return string
		 */
		protected static function generate_attachment_title( $filename ) {
			$filename = wp_basename( $filename );
			$title    = preg_replace( '/\.[^.]+$/', '', $filename );
			$title    = str_replace( array( '-', '_' ), ' ', $title );
			$title    = trim( $title );

			return $title ? ucwords( $title ) : __( 'Assistant file', 'wp-mcp-ai' );
		}

		/**
		 * Delete a temporary file from disk.
		 *
		 * @param string $file_path Absolute path to the file on disk.
		 */
		protected static function delete_file_safely( $file_path ) {
			$file_path = (string) $file_path;

			if ( '' === $file_path || ! file_exists( $file_path ) ) {
				return;
			}

			if ( function_exists( 'wp_delete_file' ) ) {
				wp_delete_file( $file_path );
				return;
			}

			unlink( $file_path );
		}
	}
}
