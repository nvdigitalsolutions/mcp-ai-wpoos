<?php
/**
 * Helper for preparing structured chat message segments and attachments.
 *
 * @package WP_MCP_AI
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Message_Attachments' ) ) {
	/**
	 * Prepares structured message segments and collects attachment payloads.
	 */
	class WP_MCP_AI_Message_Attachments {
		const MAX_ATTACHMENT_BYTES = 5242880; // 5MB default limit per attachment.
		const OPENAI_FILE_META_KEY = '_wp_mcp_ai_openai_file';

		/**
		 * Track whether cleanup hooks have been registered.
		 *
		 * @var bool
		 */
		protected static $cleanup_hooks_registered = false;

		/**
		 * Track OpenAI file identifiers deleted during the current request.
		 *
		 * @var array
		 */
		protected static $deleted_file_ids = array();

		/**
		 * AI provider for file uploads (openai, gemini, etc.).
		 *
		 * @var string
		 */
		protected $provider = 'openai';

		/**
		 * Model identifier for provider detection.
		 *
		 * @var string
		 */
		protected $model = '';

		/**
		 * Cached attachment payloads keyed by generated file identifier.
		 *
		 * @var array
		 */
		protected $attachments = array();

		/**
		 * Map of attachment post IDs to generated file identifiers.
		 *
		 * @var array
		 */
		protected $attachment_index = array();

		/**
		 * Map of OpenAI file identifiers to attachment post IDs.
		 *
		 * @var array
		 */
		protected $file_id_index = array();

		/**
		 * Constructor.
		 *
		 * @param string $provider Provider name (openai, gemini, etc.). Default 'openai'.
		 * @param string $model    Model identifier for auto-detection. Optional.
		 */
		public function __construct( $provider = 'openai', $model = '' ) {
			$this->provider = sanitize_key( $provider );
			$this->model    = sanitize_text_field( $model );

			// Auto-detect provider from model if model is provided.
			if ( ! empty( $model ) && 'openai' === $this->provider ) {
				if ( ! class_exists( 'WP_MCP_AI_File_Service_Factory' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-service-factory.php';
				}
				$detected = WP_MCP_AI_File_Service_Factory::detect_provider_from_model( $model );
				if ( 'unknown' !== $detected ) {
					$this->provider = $detected;
				}
			}
		}

		/**
		 * Set the AI provider for file uploads.
		 *
		 * @param string $provider Provider name (openai, gemini, etc.).
		 */
		public function set_provider( $provider ) {
			$this->provider = sanitize_key( $provider );
		}

		/**
		 * Get the current AI provider.
		 *
		 * @return string Provider name.
		 */
		public function get_provider() {
			return $this->provider;
		}

		/**
		 * Locate the attachment associated with an OpenAI file identifier.
		 *
		 * @param string $file_id OpenAI file identifier.
		 * @return int Attachment post ID on success, zero otherwise.
		 */
		public function get_attachment_id_for_openai_file( $file_id ) {
			$file_id = sanitize_text_field( (string) $file_id );

			if ( '' === $file_id ) {
				return 0;
			}

			return (int) $this->find_attachment_id_for_file_id( $file_id );
		}

		/**
		 * Persist OpenAI file metadata against an attachment.
		 *
		 * @param int   $attachment_id Attachment identifier.
		 * @param array $metadata      Metadata payload containing file_id and related details.
		 * @return array Normalised metadata stored against the attachment.
		 */
		public function save_openai_file_metadata_for_attachment( $attachment_id, array $metadata ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id ) {
				return array();
			}

			return $this->store_openai_file_metadata( $attachment_id, $metadata );
		}

		/**
		 * Retrieve prepared attachment payloads.
		 *
		 * @return array
		 */
		public function get_attachments() {
			return array_values( $this->attachments );
		}

		/**
		 * Register lifecycle hooks for managing OpenAI files associated with attachments.
		 */
		public static function init() {
			if ( self::$cleanup_hooks_registered ) {
				return;
			}

			add_action( 'delete_attachment', array( __CLASS__, 'handle_delete_attachment' ) );
			add_action( 'deleted_post_meta', array( __CLASS__, 'handle_deleted_post_meta' ), 10, 4 );

			self::$cleanup_hooks_registered = true;
		}

		/**
		 * Reset the cache of deleted OpenAI file identifiers.
		 */
		public static function reset_deleted_file_cache() {
			self::$deleted_file_ids = array();
		}

		/**
		 * Handle attachment deletion events.
		 *
		 * @param int $attachment_id Attachment identifier.
		 */
		public static function handle_delete_attachment( $attachment_id ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id ) {
				return;
			}

			if ( 'attachment' !== get_post_type( $attachment_id ) ) {
				return;
			}

			self::delete_openai_file_for_attachment( $attachment_id );
		}

		/**
		 * Handle attachment metadata removal events.
		 *
		 * @param array  $meta_ids  Deleted meta row identifiers.
		 * @param int    $object_id Attachment identifier.
		 * @param string $meta_key  Meta key being deleted.
		 * @param mixed  $meta_value Stored meta value prior to deletion.
		 */
		public static function handle_deleted_post_meta( $meta_ids, $object_id, $meta_key, $meta_value ) {
			if ( self::OPENAI_FILE_META_KEY !== $meta_key ) {
				return;
			}

			$object_id = absint( $object_id );

			if ( ! $object_id ) {
				return;
			}

			$metadata = array();

			if ( is_array( $meta_value ) ) {
				$metadata = $meta_value;
			} elseif ( is_string( $meta_value ) && '' !== $meta_value ) {
				$maybe_unserialized = maybe_unserialize( $meta_value );

				if ( is_array( $maybe_unserialized ) ) {
					$metadata = $maybe_unserialized;
				} elseif ( is_string( $maybe_unserialized ) && '' !== $maybe_unserialized ) {
					$metadata = array( 'file_id' => $maybe_unserialized );
				}
			}

			self::delete_openai_file_for_attachment( $object_id, $metadata );
		}

		/**
		 * Delete the OpenAI file associated with an attachment.
		 *
		 * @param int        $attachment_id Attachment identifier.
		 * @param array|null $metadata      Optional metadata payload.
		 */
		public static function delete_openai_file_for_attachment( $attachment_id, $metadata = null ) {
			$attachment_id = absint( $attachment_id );

			if ( ! $attachment_id ) {
				return;
			}

			$helper = new self();

			if ( null === $metadata ) {
				$metadata = $helper->get_cached_openai_file_metadata( $attachment_id );
			} elseif ( is_array( $metadata ) ) {
				$metadata = $helper->normalise_openai_file_metadata( $metadata );
			} elseif ( is_string( $metadata ) && '' !== $metadata ) {
				$metadata = $helper->normalise_openai_file_metadata( array( 'file_id' => $metadata ) );
			} else {
				$metadata = array();
			}

			if ( empty( $metadata['file_id'] ) ) {
				return;
			}

			$file_id = $metadata['file_id'];

			if ( isset( self::$deleted_file_ids[ $file_id ] ) ) {
				return;
			}

			$helper->delete_remote_openai_file( $file_id );
		}

		/**
		 * Prepare an input attachment segment (router method).
		 *
		 * Routes to the appropriate method based on segment type.
		 *
		 * @param array $segment Segment definition with type field.
		 * @return array|WP_Error Prepared segment or error.
		 */
		public function prepare_input_attachment_segment( array $segment ) {
			$segment_type = isset( $segment['type'] ) ? $segment['type'] : '';

			if ( 'image_url' === $segment_type || 'image_file' === $segment_type || 'input_image' === $segment_type ) {
				return $this->prepare_input_image_segment( $segment );
			}

			if ( 'audio' === $segment_type || 'file' === $segment_type || 'input_file' === $segment_type ) {
				return $this->prepare_input_file_segment( $segment );
			}

			// If no valid type, treat as text segment.

			if ( isset( $segment['text'] ) ) {
				return $this->prepare_input_text_segment( $segment['text'] );
			}

			return new WP_Error(
				'wp_mcp_ai_invalid_attachment_segment',
				__( 'Attachment segment must have a valid type field.', 'wp-mcp-ai' ),
				array( 'status' => 400 )
			);
		}

		/**
		 * Prepare a text segment.
		 *
		 * @param string $text Raw text.
		 * @return array
		 */
		public function prepare_input_text_segment( $text ) {
			$text = wp_kses_post( (string) $text );
			$text = trim( $text );

			return array(
				'type' => 'text',
				'text' => $text,
			);
		}

		/**
		 * Extract and sanitize filename from segment.
		 *
		 * Helper method to get filename from file_name or name field with consistent sanitization.
		 *
		 * @param array $segment Segment array.
		 * @return string Sanitized filename or empty string.
		 */
		protected function extract_segment_filename( array $segment ) {
			if ( ! empty( $segment['file_name'] ) ) {
				return sanitize_text_field( wp_unslash( $segment['file_name'] ) );
			}
			if ( ! empty( $segment['name'] ) ) {
				return sanitize_text_field( wp_unslash( $segment['name'] ) );
			}
			return '';
		}

		/**
		 * Prepare an input image segment using an attachment or remote URL.
		 *
		 * @param array $segment Segment definition.
		 * @return array|WP_Error
		 */
		public function prepare_input_image_segment( array $segment ) {
			$caption = isset( $segment['caption'] ) ? $this->sanitize_caption( $segment['caption'] ) : '';
			$detail  = isset( $segment['detail'] ) ? $this->sanitize_detail( $segment['detail'] ) : '';

			// Extract URL from various possible formats.

			$url = '';
			if ( ! empty( $segment['url'] ) ) {
				$url = $segment['url'];
			} elseif ( isset( $segment['image_url'] ) ) {
				// Handle image_url as string or object with url property.

				if ( is_string( $segment['image_url'] ) ) {
					$url = $segment['image_url'];
				} elseif ( is_array( $segment['image_url'] ) && isset( $segment['image_url']['url'] ) ) {
					$url = $segment['image_url']['url'];
					// Also extract detail from image_url if not already set.

					if ( empty( $detail ) && isset( $segment['image_url']['detail'] ) ) {
						$detail = $this->sanitize_detail( $segment['image_url']['detail'] );
					}
				}
			}

			if ( ! empty( $url ) ) {
				$url = esc_url_raw( $url );
				if ( empty( $url ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_image_url', __( 'Image segment URL is invalid.', 'wp-mcp-ai' ) );
				}

				$allowed_schemes = apply_filters(
					'wp_mcp_ai_allowed_remote_image_url_schemes',
					array( 'http', 'https' )
				);
				$allowed_schemes = array_unique( array_map( 'strtolower', (array) $allowed_schemes ) );

				$parsed_url = wp_parse_url( $url );
				$scheme     = isset( $parsed_url['scheme'] ) ? strtolower( $parsed_url['scheme'] ) : '';

				if ( empty( $scheme ) || ! in_array( $scheme, $allowed_schemes, true ) ) {
					return new WP_Error(
						'wp_mcp_ai_unsupported_image_url_scheme',
						__( 'Image segment URLs must use an allowed scheme.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}

				$prepared = array(
					'type'      => 'input_image',
					'image_url' => array( 'url' => $url ),
				);

				if ( ! empty( $caption ) ) {
					$prepared['caption'] = $caption;
				}

				if ( ! empty( $detail ) ) {
					$prepared['detail'] = $detail;
				}

				// Preserve file metadata for agentic workflow (following OpenAI image tool pattern).
				// These fields provide context about the file without including large binary data.
				$filename = $this->extract_segment_filename( $segment );
				if ( ! empty( $filename ) ) {
					$prepared['file_name'] = $filename;
				}

				if ( ! empty( $segment['mime_type'] ) ) {
					$prepared['mime_type'] = sanitize_text_field( wp_unslash( $segment['mime_type'] ) );
				}

				if ( isset( $segment['bytes'] ) && is_numeric( $segment['bytes'] ) ) {
					$prepared['bytes'] = absint( $segment['bytes'] );
				}

				// Also include direct URL for agentic workflows that need it.
				$prepared['url'] = $url;

				// Preserve attachment_id if present for agentic workflows
				if ( ! empty( $segment['attachment_id'] ) ) {
					$prepared['attachment_id'] = absint( $segment['attachment_id'] );
				}

				return $prepared;
			}

			$image_file   = isset( $segment['image_file'] ) && is_array( $segment['image_file'] ) ? $segment['image_file'] : array();
			$segment_file = isset( $segment['file'] ) && is_array( $segment['file'] ) ? $segment['file'] : array();

			$file_id = '';

			if ( ! empty( $image_file['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $image_file['file_id'] ) );
			} elseif ( ! empty( $image_file['id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $image_file['id'] ) );
			} elseif ( ! empty( $segment['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $segment['file_id'] ) );
			} elseif ( ! empty( $segment_file['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $segment_file['file_id'] ) );
			}

			if ( ! empty( $file_id ) ) {
				$resolved = $this->resolve_existing_file_reference( $file_id, 'image' );

				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}

				$prepared = array(
					'type'    => 'input_image',
					'file_id' => $file_id,
				);

				if ( empty( $caption ) && isset( $image_file['caption'] ) ) {
					$caption = $this->sanitize_caption( $image_file['caption'] );
				}

				if ( empty( $caption ) ) {
					if ( ! empty( $resolved['caption'] ) ) {
						$caption = $resolved['caption'];
					} elseif ( ! empty( $resolved['title'] ) ) {
						$caption = $resolved['title'];
					}
				}

				if ( ! empty( $caption ) ) {
					$prepared['caption'] = $caption;
				}

				if ( ! empty( $detail ) ) {
					$prepared['detail'] = $detail;
				}

				// Add file metadata for agentic workflow (following OpenAI image tool pattern).
				if ( isset( $resolved['attachment_id'] ) && $resolved['attachment_id'] > 0 ) {
					$attachment_id = absint( $resolved['attachment_id'] );
					// Preserve attachment_id for agentic workflows
					$prepared['attachment_id'] = $attachment_id;
					$image_url                 = wp_get_attachment_url( $attachment_id );
					if ( ! empty( $image_url ) ) {
						$prepared['url'] = esc_url_raw( $image_url );
					}
				}

				if ( isset( $resolved['metadata'] ) && is_array( $resolved['metadata'] ) ) {
					$metadata = $resolved['metadata'];
					if ( ! empty( $metadata['filename'] ) ) {
						$prepared['file_name'] = sanitize_text_field( $metadata['filename'] );
					}
					if ( ! empty( $metadata['mime_type'] ) ) {
						$prepared['mime_type'] = sanitize_text_field( $metadata['mime_type'] );
					}
					if ( isset( $metadata['bytes'] ) && is_numeric( $metadata['bytes'] ) ) {
						$prepared['bytes'] = absint( $metadata['bytes'] );
					}
				}

				return $prepared;
			}

			if ( empty( $segment['attachment_id'] ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_image_attachment', __( 'Image segments must include an attachment ID or URL.', 'wp-mcp-ai' ) );
			}

			$attachment_id       = absint( $segment['attachment_id'] );
			$prepared_attachment = $this->register_attachment( $attachment_id, 'image' );

			if ( is_wp_error( $prepared_attachment ) ) {
				return $prepared_attachment;
			}

			$prepared = array(
				'type'          => 'input_image',
				'file_id'       => $prepared_attachment['file_id'],
				'attachment_id' => $attachment_id,
			);

			// Get the image URL for providers that need it (OpenAI Chat Completions, Gemini).
			// This is essential for vision models that require direct image URLs.
			$image_url = wp_get_attachment_url( $attachment_id );
			if ( ! empty( $image_url ) ) {
				$prepared['image_url'] = array( 'url' => esc_url_raw( $image_url ) );
				// Also add direct url field for agentic workflow.
				$prepared['url'] = esc_url_raw( $image_url );
			} else {
				// Log warning if URL cannot be retrieved, but continue since we have file_id.
				WP_MCP_AI_Logger::log_error(
					'Could not retrieve URL for image attachment.',
					array(
						'attachment_id' => $attachment_id,
						'file_id'       => $prepared_attachment['file_id'],
					)
				);
			}

			$resolved_caption = $caption;

			if ( empty( $resolved_caption ) ) {
				$resolved_caption = $prepared_attachment['caption'];
			}

			if ( empty( $resolved_caption ) ) {
				$resolved_caption = $prepared_attachment['title'];
			}

			if ( ! empty( $resolved_caption ) ) {
				$prepared['caption'] = $resolved_caption;
			}

			if ( ! empty( $detail ) ) {
				$prepared['detail'] = $detail;
			}

			// Add file metadata for agentic workflow (following OpenAI image tool pattern).
			if ( ! empty( $prepared_attachment['filename'] ) ) {
				$prepared['file_name'] = sanitize_text_field( $prepared_attachment['filename'] );
			}
			if ( ! empty( $prepared_attachment['mime_type'] ) ) {
				$prepared['mime_type'] = sanitize_text_field( $prepared_attachment['mime_type'] );
			}
			if ( isset( $prepared_attachment['bytes'] ) && is_numeric( $prepared_attachment['bytes'] ) ) {
				$prepared['bytes'] = absint( $prepared_attachment['bytes'] );
			}

			return $prepared;
		}

		/**
		 * Prepare an input file segment from a permitted attachment.
		 *
		 * @param array $segment Segment definition.
		 * @return array|WP_Error
		 */
		public function prepare_input_file_segment( array $segment ) {
			// Check for direct URL first (for external files or when URL is provided).
			$url = '';
			if ( ! empty( $segment['url'] ) ) {
				$url = $segment['url'];
			}

			if ( ! empty( $url ) ) {
				$url = esc_url_raw( $url );
				if ( empty( $url ) ) {
					return new WP_Error( 'wp_mcp_ai_invalid_file_url', __( 'File segment URL is invalid.', 'wp-mcp-ai' ) );
				}

				$allowed_schemes = apply_filters(
					'wp_mcp_ai_allowed_remote_file_url_schemes',
					array( 'http', 'https' )
				);
				$allowed_schemes = array_unique( array_map( 'strtolower', (array) $allowed_schemes ) );

				$parsed_url = wp_parse_url( $url );
				$scheme     = isset( $parsed_url['scheme'] ) ? strtolower( $parsed_url['scheme'] ) : '';

				if ( empty( $scheme ) || ! in_array( $scheme, $allowed_schemes, true ) ) {
					return new WP_Error(
						'wp_mcp_ai_unsupported_file_url_scheme',
						__( 'File segment URLs must use an allowed scheme.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}

				$prepared = array(
					'type' => 'input_file',
					'url'  => $url,
				)

;

				if ( ! empty( $segment['display_name'] ) ) {
					$prepared['display_name'] = sanitize_text_field( wp_unslash( $segment['display_name'] ) );
				}

				// Preserve file metadata for agentic workflow (following OpenAI file tool pattern).
				$filename = $this->extract_segment_filename( $segment );
				if ( ! empty( $filename ) ) {
					$prepared['file_name'] = $filename;
					$prepared['name']      = $filename; // Compatibility field.
				}

				if ( ! empty( $segment['mime_type'] ) ) {
					$prepared['mime_type'] = sanitize_text_field( wp_unslash( $segment['mime_type'] ) );
				}

				if ( isset( $segment['bytes'] ) && is_numeric( $segment['bytes'] ) ) {
					$prepared['bytes'] = absint( $segment['bytes'] );
				}

				// Preserve attachment_id if present for agentic workflows
				if ( ! empty( $segment['attachment_id'] ) ) {
					$prepared['attachment_id'] = absint( $segment['attachment_id'] );
				}

				return $prepared;
			}

			$segment_file = isset( $segment['file'] ) && is_array( $segment['file'] ) ? $segment['file'] : array();
			$file_id      = '';

			if ( ! empty( $segment['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $segment['file_id'] ) );
			} elseif ( ! empty( $segment_file['file_id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $segment_file['file_id'] ) );
			} elseif ( ! empty( $segment_file['id'] ) ) {
				$file_id = sanitize_text_field( wp_unslash( $segment_file['id'] ) );
			}

			if ( ! empty( $file_id ) ) {
				$resolved = $this->resolve_existing_file_reference( $file_id, 'file' );

				if ( is_wp_error( $resolved ) ) {
					return $resolved;
				}

				$segment_payload = array(
					'type'    => 'input_file',
					'file_id' => $file_id,
				);

				if ( ! empty( $segment['display_name'] ) ) {
					$segment_payload['display_name'] = sanitize_text_field( wp_unslash( $segment['display_name'] ) );
				} elseif ( isset( $segment_file['display_name'] ) ) {
					$segment_payload['display_name'] = sanitize_text_field( wp_unslash( $segment_file['display_name'] ) );
				} elseif ( ! empty( $resolved['title'] ) ) {
					$segment_payload['display_name'] = $resolved['title'];
				} elseif ( isset( $resolved['metadata']['filename'] ) && '' !== $resolved['metadata']['filename'] ) {
					$segment_payload['display_name'] = $resolved['metadata']['filename'];
				}

				// Add file metadata for agentic workflow (following OpenAI file tool pattern).
				if ( isset( $resolved['attachment_id'] ) && $resolved['attachment_id'] > 0 ) {
					$attachment_id = absint( $resolved['attachment_id'] );
					// Preserve attachment_id for agentic workflows
					$segment_payload['attachment_id'] = $attachment_id;
					$file_url                         = wp_get_attachment_url( $attachment_id );
					if ( ! empty( $file_url ) ) {
						$segment_payload['url'] = esc_url_raw( $file_url );
					}
				}

				if ( isset( $resolved['metadata'] ) && is_array( $resolved['metadata'] ) ) {
					$metadata = $resolved['metadata'];
					if ( ! empty( $metadata['filename'] ) ) {
						$filename                       = sanitize_text_field( $metadata['filename'] );
						$segment_payload['file_name']   = $filename;
						$segment_payload['name']        = $filename; // Compatibility field.
					}
					if ( ! empty( $metadata['mime_type'] ) ) {
						$segment_payload['mime_type'] = sanitize_text_field( $metadata['mime_type'] );
					}
					if ( isset( $metadata['bytes'] ) && is_numeric( $metadata['bytes'] ) ) {
						$segment_payload['bytes'] = absint( $metadata['bytes'] );
					}
				}

				return $segment_payload;
			}

			if ( empty( $segment['attachment_id'] ) ) {
				return new WP_Error( 'wp_mcp_ai_missing_file_attachment', __( 'File segments must include an attachment ID.', 'wp-mcp-ai' ) );
			}

			$attachment_id       = absint( $segment['attachment_id'] );
			$prepared_attachment = $this->register_attachment( $attachment_id, 'file' );

			if ( is_wp_error( $prepared_attachment ) ) {
				return $prepared_attachment;
			}

			$segment_payload = array(
				'type'          => 'input_file',
				'file_id'       => $prepared_attachment['file_id'],
				'attachment_id' => $attachment_id,
			);

			if ( ! empty( $segment['display_name'] ) ) {
				$segment_payload['display_name'] = sanitize_text_field( wp_unslash( $segment['display_name'] ) );
			} elseif ( ! empty( $prepared_attachment['title'] ) ) {
				$segment_payload['display_name'] = $prepared_attachment['title'];
			}

			// Add file metadata for agentic workflow (following OpenAI file tool pattern).
			$file_url = wp_get_attachment_url( $attachment_id );
			if ( ! empty( $file_url ) ) {
				$segment_payload['url'] = esc_url_raw( $file_url );
			}

			if ( ! empty( $prepared_attachment['filename'] ) ) {
				$filename                     = sanitize_text_field( $prepared_attachment['filename'] );
				$segment_payload['file_name'] = $filename;
				$segment_payload['name']      = $filename; // Compatibility field.
			}
			if ( ! empty( $prepared_attachment['mime_type'] ) ) {
				$segment_payload['mime_type'] = sanitize_text_field( $prepared_attachment['mime_type'] );
			}
			if ( isset( $prepared_attachment['bytes'] ) && is_numeric( $prepared_attachment['bytes'] ) ) {
				$segment_payload['bytes'] = absint( $prepared_attachment['bytes'] );
			}

			return $segment_payload;
		}

		/**
		 * Register an attachment for inclusion in the OpenAI payload.
		 *
		 * @param int    $attachment_id Attachment post ID.
		 * @param string $usage         Usage context (image|file).
		 * @return array|WP_Error
		 */
		protected function register_attachment( $attachment_id, $usage ) {
			if ( isset( $this->attachment_index[ $attachment_id ] ) ) {
				$file_id = $this->attachment_index[ $attachment_id ];
				$entry   = isset( $this->attachments[ $file_id ] ) ? $this->attachments[ $file_id ] : array();

				return array(
					'file_id'   => $file_id,
					'title'     => isset( $entry['title'] ) ? $entry['title'] : '',
					'caption'   => isset( $entry['caption'] ) ? $entry['caption'] : '',
					'filename'  => isset( $entry['filename'] ) ? $entry['filename'] : '',
					'mime_type' => isset( $entry['mime_type'] ) ? $entry['mime_type'] : '',
					'bytes'     => isset( $entry['bytes'] ) ? (int) $entry['bytes'] : 0,
					'metadata'  => $entry,
				);
			}

			$attachment = get_post( $attachment_id );
			if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
				return new WP_Error( 'wp_mcp_ai_attachment_missing', __( 'Attachment not found.', 'wp-mcp-ai' ) );
			}

			if ( ! $this->current_user_can_access_attachment( $attachment_id ) ) {
				return new WP_Error( 'wp_mcp_ai_attachment_forbidden', __( 'You do not have permission to use the requested attachment.', 'wp-mcp-ai' ) );
			}

			$file_path = get_attached_file( $attachment_id );
			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error( 'wp_mcp_ai_attachment_missing_file', __( 'The attachment file could not be located.', 'wp-mcp-ai' ) );
			}

			$file_size = filesize( $file_path );
			if ( false === $file_size ) {
				return new WP_Error( 'wp_mcp_ai_attachment_size_unknown', __( 'Could not determine attachment size.', 'wp-mcp-ai' ) );
			}

			$max_bytes = apply_filters( 'wp_mcp_ai_max_attachment_bytes', self::MAX_ATTACHMENT_BYTES, $attachment_id, $usage );
			if ( $file_size > $max_bytes ) {
				/* translators: %s: maximum bytes allowed for an attachment. */
				return new WP_Error( 'wp_mcp_ai_attachment_too_large', sprintf( __( 'Attachments must be smaller than %s bytes.', 'wp-mcp-ai' ), number_format_i18n( $max_bytes ) ) );
			}

			$mime_type = get_post_mime_type( $attachment_id );
			if ( ! $this->is_supported_mime_type( $mime_type, $usage ) ) {
				// Provide specific error message for SVG files on OpenAI.
				if ( 'image/svg+xml' === $mime_type && 'openai' === $this->provider ) {
					return new WP_Error(
						'wp_mcp_ai_attachment_unsupported_mime',
						__( 'SVG files are not supported by OpenAI. Please use PNG, JPEG, GIF, or WebP formats instead.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_attachment_unsupported_mime',
					sprintf(
						/* translators: %s: MIME type */
						__( 'The attachment type "%s" is not supported for chat messages with this AI provider.', 'wp-mcp-ai' ),
						$mime_type
					),
					array( 'status' => 400 )
				);
			}

			$purpose = apply_filters( 'wp_mcp_ai_openai_file_purpose', 'assistants', $attachment_id, $usage );
			$purpose = $purpose ? sanitize_key( $purpose ) : 'assistants';

			$file_hash    = $this->hash_file_contents( $file_path );
			$file_modtime = $this->get_file_modified_time( $file_path );

			$cached_metadata = $this->get_cached_openai_file_metadata( $attachment_id );

			$should_reuse = $this->should_reuse_openai_file( $cached_metadata, $purpose, $file_hash, $file_size, $file_modtime );
			$file_id      = '';
			$metadata     = $cached_metadata;

			if ( $should_reuse ) {
				$file_id  = $cached_metadata['file_id'];
				$metadata = $cached_metadata;
			} else {
				// Use file service factory for provider-agnostic uploads.
				if ( ! class_exists( 'WP_MCP_AI_File_Service_Factory' ) ) {
					require_once WP_MCP_AI_PATH . 'includes/services/class-wp-mcp-ai-file-service-factory.php';
				}

				$upload = WP_MCP_AI_File_Service_Factory::upload_file(
					$file_path,
					$mime_type,
					$this->provider,
					array(
						'purpose'       => $purpose,
						'filename'      => wp_basename( $file_path ),
						'display_name'  => get_the_title( $attachment ),
						'attachment_id' => $attachment_id,
					)
				);

				if ( is_wp_error( $upload ) ) {
					return $upload;
				}

				// Extract file ID based on provider.
				$file_id = '';
				if ( 'openai' === $this->provider && isset( $upload['id'] ) ) {
					$file_id = sanitize_text_field( $upload['id'] );
				} elseif ( in_array( $this->provider, array( 'gemini', 'google' ), true ) ) {
					// Gemini returns 'name' field which includes the URI.
					if ( isset( $upload['name'] ) ) {
						$file_id = sanitize_text_field( $upload['name'] );
					} elseif ( isset( $upload['uri'] ) ) {
						$file_id = sanitize_text_field( $upload['uri'] );
					}
				}

				if ( '' === $file_id ) {
					return new WP_Error(
						'wp_mcp_ai_file_upload_missing_id',
						sprintf(
							/* translators: %s: provider name */
							__( '%s did not return a file identifier.', 'wp-mcp-ai' ),
							ucfirst( $this->provider )
						)
					);
				}

				$metadata = array(
					'file_id'    => $file_id,
					'provider'   => $this->provider,
					'created_at' => isset( $upload['created_at'] ) ? (int) $upload['created_at'] : time(),
					'status'     => isset( $upload['status'] ) ? $upload['status'] : ( isset( $upload['state'] ) ? $upload['state'] : '' ),
				);

				// Store provider-specific metadata.
				if ( 'gemini' === $this->provider || 'google' === $this->provider ) {
					if ( isset( $upload['uri'] ) ) {
						$metadata['uri'] = $upload['uri'];
					}
					if ( isset( $upload['mimeType'] ) ) {
						$metadata['mime_type'] = $upload['mimeType'];
					}
				}
			}

			$metadata['hash']      = $file_hash;
			$metadata['bytes']     = (int) $file_size;
			$metadata['purpose']   = $purpose;
			$metadata['filename']  = wp_basename( $file_path );
			$metadata['mime_type'] = $mime_type;
			$metadata['modified']  = $file_modtime;

			$metadata = $this->store_openai_file_metadata( $attachment_id, $metadata );

			$title   = get_the_title( $attachment );
			$caption = wp_strip_all_tags( $attachment->post_excerpt );

			$resolved_file_id = isset( $metadata['file_id'] ) && '' !== $metadata['file_id'] ? $metadata['file_id'] : $file_id;

			$payload = array(
				'id'            => $resolved_file_id,
				'file_id'       => $resolved_file_id,
				'attachment_id' => $attachment_id,
				'filename'      => isset( $metadata['filename'] ) && '' !== $metadata['filename'] ? $metadata['filename'] : wp_basename( $file_path ),
				'mime_type'     => isset( $metadata['mime_type'] ) && '' !== $metadata['mime_type'] ? $metadata['mime_type'] : $mime_type,
				'bytes'         => isset( $metadata['bytes'] ) ? (int) $metadata['bytes'] : (int) $file_size,
				'purpose'       => isset( $metadata['purpose'] ) && '' !== $metadata['purpose'] ? $metadata['purpose'] : $purpose,
			);

			if ( ! empty( $metadata['status'] ) ) {
				$payload['status'] = $metadata['status'];
			}

			if ( ! empty( $metadata['created_at'] ) ) {
				$payload['created_at'] = (int) $metadata['created_at'];
			}

			if ( array_key_exists( 'data', $metadata ) ) {
				$payload['data'] = is_string( $metadata['data'] ) ? $metadata['data'] : '';
			}

			if ( '' !== $title ) {
				$payload['title'] = $title;
			}

			if ( '' !== $caption ) {
				$payload['caption'] = $caption;
			}

			$this->attachments[ $resolved_file_id ]   = $payload;
			$this->attachment_index[ $attachment_id ] = $resolved_file_id;
			$this->file_id_index[ $resolved_file_id ] = $attachment_id;

			return array(
				'file_id'   => $resolved_file_id,
				'title'     => $title,
				'caption'   => $caption,
				'filename'  => isset( $metadata['filename'] ) ? $metadata['filename'] : wp_basename( $file_path ),
				'mime_type' => isset( $metadata['mime_type'] ) ? $metadata['mime_type'] : $mime_type,
				'bytes'     => isset( $metadata['bytes'] ) ? (int) $metadata['bytes'] : (int) $file_size,
				'metadata'  => $metadata,
			);
		}

		/**
		 * Determine if the current user can access an attachment.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return bool
		 */
		protected function current_user_can_access_attachment( $attachment_id ) {
			if ( current_user_can( 'read_post', $attachment_id ) ) {
				return true;
			}

			if ( current_user_can( 'edit_post', $attachment_id ) ) {
				return true;
			}

			$post = get_post( $attachment_id );
			if ( $post && (int) $post->post_author === get_current_user_id() ) {
				return true;
			}

			if ( $post instanceof WP_Post && 'attachment' === $post->post_type && $this->attachment_is_publicly_accessible( $post ) ) {
				return true;
			}

			return apply_filters( 'wp_mcp_ai_can_use_attachment', false, $attachment_id );
		}

		/**
		 * Resolve and validate an existing OpenAI file reference against local attachments.
		 *
		 * @param string $file_id File identifier supplied by the client.
		 * @param string $usage   Usage context (image|file).
		 * @return array|WP_Error Array of attachment context when successful, WP_Error otherwise.
		 */
		protected function resolve_existing_file_reference( $file_id, $usage ) {
			$file_id = (string) $file_id;

			if ( '' === $file_id ) {
				return new WP_Error(
					'wp_mcp_ai_unknown_file_reference',
					__( 'The referenced file could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			if ( isset( self::$deleted_file_ids[ $file_id ] ) ) {
				return new WP_Error(
					'wp_mcp_ai_unknown_file_reference',
					__( 'The referenced file could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$attachment_id = $this->find_attachment_id_for_file_id( $file_id );

			if ( ! $attachment_id ) {
				return new WP_Error(
					'wp_mcp_ai_unknown_file_reference',
					__( 'The referenced file could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			if ( ! $this->current_user_can_access_attachment( $attachment_id ) ) {
				return new WP_Error(
					'wp_mcp_ai_attachment_forbidden',
					__( 'You do not have permission to use the requested attachment.', 'wp-mcp-ai' ),
					array( 'status' => 403 )
				);
			}

			$metadata = $this->get_cached_openai_file_metadata( $attachment_id );

			if ( empty( $metadata['file_id'] ) || (string) $metadata['file_id'] !== $file_id ) {
				return new WP_Error(
					'wp_mcp_ai_unknown_file_reference',
					__( 'The referenced file could not be found.', 'wp-mcp-ai' ),
					array( 'status' => 400 )
				);
			}

			$mime_type = '';

			if ( isset( $metadata['mime_type'] ) && '' !== $metadata['mime_type'] ) {
				$mime_type = $metadata['mime_type'];
			}

			if ( '' === $mime_type ) {
				$mime_type = get_post_mime_type( $attachment_id );
			}

			if ( '' !== $mime_type && ! $this->is_supported_mime_type( $mime_type, $usage ) ) {
				// Provide specific error message for SVG files on OpenAI.
				if ( 'image/svg+xml' === $mime_type && 'openai' === $this->provider ) {
					return new WP_Error(
						'wp_mcp_ai_attachment_unsupported_mime',
						__( 'SVG files are not supported by OpenAI. Please use PNG, JPEG, GIF, or WebP formats instead.', 'wp-mcp-ai' ),
						array( 'status' => 400 )
					);
				}
				return new WP_Error(
					'wp_mcp_ai_attachment_unsupported_mime',
					sprintf(
						/* translators: %s: MIME type */
						__( 'The attachment type "%s" is not supported for chat messages with this AI provider.', 'wp-mcp-ai' ),
						$mime_type
					),
					array( 'status' => 400 )
				);
			}

			$title   = get_the_title( $attachment_id );
			$caption = wp_strip_all_tags( get_post_field( 'post_excerpt', $attachment_id ) );

			$this->file_id_index[ $file_id ] = $attachment_id;

			return array(
				'attachment_id' => $attachment_id,
				'metadata'      => $metadata,
				'title'         => $title,
				'caption'       => $caption,
			);
		}

		/**
		 * Locate the attachment ID associated with an OpenAI file identifier.
		 *
		 * @param string $file_id OpenAI file identifier.
		 * @return int Attachment post ID when found, zero otherwise.
		 */
		protected function find_attachment_id_for_file_id( $file_id ) {
			if ( isset( $this->file_id_index[ $file_id ] ) ) {
				return (int) $this->file_id_index[ $file_id ];
			}

			$direct_match = array_search( $file_id, $this->attachment_index, true );
			if ( false !== $direct_match ) {
				$attachment_id = (int) $direct_match;
				if ( $attachment_id ) {
					$this->file_id_index[ $file_id ] = $attachment_id;
				}

				return $attachment_id;
			}

			$cache_key = 'openai_file_lookup_' . md5( $file_id );
			$cached    = wp_cache_get( $cache_key, 'wp_mcp_ai_message_attachments' );

			if ( false !== $cached ) {
				$cached_id = (int) $cached;
				if ( $cached_id ) {
					$this->file_id_index[ $file_id ] = $cached_id;
				}

				return $cached_id;
			}

			global $wpdb;

			$serialized_pattern = sprintf( '"file_id";s:%d:"%s"', strlen( $file_id ), $file_id );
			$serialized_like    = '%' . $wpdb->esc_like( $serialized_pattern ) . '%';
			// phpcs:ignore WordPress.DB.DirectDatabaseQuery.DirectQuery
			$results            = $wpdb->get_col(
				$wpdb->prepare(
					"SELECT post_id FROM {$wpdb->postmeta} WHERE meta_key = %s AND (meta_value = %s OR meta_value LIKE %s) ORDER BY post_id DESC LIMIT 25",
					self::OPENAI_FILE_META_KEY,
					$file_id,
					$serialized_like
				)
			);

			$attachment_id = 0;

			if ( ! empty( $results ) ) {
				foreach ( $results as $candidate_id ) {
					$candidate_id = (int) $candidate_id;

					if ( ! $candidate_id || 'attachment' !== get_post_type( $candidate_id ) ) {
						continue;
					}

					$metadata = $this->get_cached_openai_file_metadata( $candidate_id );

					if ( ! empty( $metadata['file_id'] ) && (string) $metadata['file_id'] === $file_id ) {
						$attachment_id = $candidate_id;
						break;
					}
				}
			}

			wp_cache_set( $cache_key, $attachment_id, 'wp_mcp_ai_message_attachments', MINUTE_IN_SECONDS );

			if ( $attachment_id ) {
				$this->file_id_index[ $file_id ] = $attachment_id;
			}

			return $attachment_id;
		}

		/**
		 * Public helper for checking attachment access permissions.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return bool
		 */
		public static function user_can_access_attachment( $attachment_id ) {
			$attachment_id = absint( $attachment_id );
			if ( ! $attachment_id ) {
				return false;
			}

			$helper = new self();

			return $helper->current_user_can_access_attachment( $attachment_id );
		}

		/**
		 * Determine whether an attachment is publicly accessible based on its status hierarchy.
		 *
		 * @param WP_Post    $attachment      Attachment post object.
		 * @param array|null $public_statuses Optional list of statuses considered public.
		 * @param array      $visited         Optional list of visited attachment IDs to prevent recursion loops.
		 * @return bool
		 */
		protected function attachment_is_publicly_accessible( WP_Post $attachment, $public_statuses = null, $visited = array() ) {
			if ( null === $public_statuses ) {
				$public_statuses = get_post_stati( array( 'public' => true ) );

				if ( ! is_array( $public_statuses ) ) {
					$public_statuses = array( 'publish' );
				}
			}

			$visited[] = (int) $attachment->ID;

			if ( in_array( $attachment->post_status, $public_statuses, true ) ) {
				return true;
			}

			if ( 'inherit' !== $attachment->post_status ) {
				return false;
			}

			$parent_id = (int) $attachment->post_parent;
			if ( ! $parent_id ) {
				return true;
			}

			$parent = get_post( $parent_id );
			if ( ! $parent ) {
				return true;
			}

			if ( in_array( $parent->post_status, $public_statuses, true ) ) {
				return true;
			}

			if ( 'attachment' === $parent->post_type && ! in_array( (int) $parent->ID, $visited, true ) ) {
				return $this->attachment_is_publicly_accessible( $parent, $public_statuses, $visited );
			}

			if ( 'inherit' === $parent->post_status ) {
				$ancestor_ids = get_post_ancestors( $parent_id );

				foreach ( $ancestor_ids as $ancestor_id ) {
					$ancestor_status = get_post_status( $ancestor_id );

					if ( $ancestor_status && in_array( $ancestor_status, $public_statuses, true ) ) {
						return true;
					}

					if ( ! $ancestor_status || 'inherit' !== $ancestor_status ) {
						break;
					}
				}
			}

			return false;
		}

		/**
		 * Validate whether a MIME type is permitted for the usage context.
		 *
		 * @param string $mime_type MIME type string.
		 * @param string $usage     Usage context.
		 * @return bool
		 */
		protected function is_supported_mime_type( $mime_type, $usage ) {
			$mime_type     = strtolower( (string) $mime_type );
			$allowed_mimes = self::get_allowed_mime_types( $usage, $this->provider );

			if ( empty( $allowed_mimes ) ) {
				return false;
			}

			return in_array( $mime_type, $allowed_mimes, true );
		}

		/**
		 * Retrieve the allowed MIME types for attachments.
		 *
		 * @param string|null $usage    Optional usage context (image|file). Null returns both lists.
		 * @param string      $provider Optional provider name (openai, gemini, etc.). Default 'openai'.
		 * @return array
		 */
		public static function get_allowed_mime_types( $usage = null, $provider = 'openai' ) {
			$provider = strtolower( sanitize_key( $provider ) );

			// Base image MIME types supported by most providers.
			$image_mimes = array(
				'image/jpeg',
				'image/png',
				'image/gif',
				'image/webp',
				'image/heic',
				'image/heif',
				'image/bmp',
			);

			// SVG is only supported by Gemini, not by OpenAI.
			// OpenAI Vision API explicitly does NOT support SVG files.
			if ( in_array( $provider, array( 'gemini', 'google' ), true ) ) {
				$image_mimes[] = 'image/svg+xml';
			}

			$file_mimes = array(
				'text/plain',
				'text/markdown',
				'text/csv',
				'text/tab-separated-values',
				'text/html',
				'application/pdf',
				'application/json',
				'application/x-ndjson',
				'application/jsonl',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
				'application/vnd.openxmlformats-officedocument.wordprocessingml.template',
				'application/vnd.ms-word.document.macroEnabled.12',
				'application/vnd.ms-word.template.macroEnabled.12',
				'application/vnd.openxmlformats-officedocument.presentationml.presentation',
				'application/vnd.openxmlformats-officedocument.presentationml.slideshow',
				'application/vnd.openxmlformats-officedocument.presentationml.template',
				'application/vnd.ms-powerpoint',
				'application/vnd.ms-powerpoint.presentation.macroEnabled.12',
				'application/vnd.ms-powerpoint.slideshow.macroEnabled.12',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
				'application/vnd.openxmlformats-officedocument.spreadsheetml.template',
				'application/vnd.ms-excel',
				'application/vnd.ms-excel.sheet.macroEnabled.12',
				'application/vnd.ms-excel.sheet.binary.macroEnabled.12',
				'application/vnd.ms-excel.template.macroEnabled.12',
				'application/xml',
				'text/xml',
				'audio/aac',
				'audio/flac',
				'audio/m4a',
				'audio/mp3',
				'audio/mpeg',
				'audio/ogg',
				'audio/opus',
				'audio/wav',
				'audio/webm',
				'audio/x-aac',
				'audio/x-flac',
				'audio/x-m4a',
				'audio/x-mp3',
				'audio/x-mpeg',
				'audio/x-ms-wma',
				'audio/x-wav',
				'video/mp4',
				'video/quicktime',
			);

			if ( class_exists( 'WP_MCP_AI_Admin_Settings' ) ) {
				$settings = WP_MCP_AI_Admin_Settings::get_settings();

				if (
				isset( $settings['allowed_image_mimes'] )
				&& is_array( $settings['allowed_image_mimes'] )
				&& ! empty( $settings['allowed_image_mimes'] )
				) {
					$image_mimes = array_values(
						array_unique(
							array_filter(
								array_map( 'trim', $settings['allowed_image_mimes'] )
							)
						)
					);
				}

				if (
				isset( $settings['allowed_file_mimes'] )
				&& is_array( $settings['allowed_file_mimes'] )
				&& ! empty( $settings['allowed_file_mimes'] )
				) {
					$file_mimes = array_values(
						array_unique(
							array_filter(
								array_map( 'trim', $settings['allowed_file_mimes'] )
							)
						)
					);
				}
			}

			$image_mimes = array_values(
				array_unique(
					array_filter(
						array_map( 'strtolower', apply_filters( 'wp_mcp_ai_allowed_image_mimes', $image_mimes, $provider ) )
					)
				)
			);
			$file_mimes  = array_values(
				array_unique(
					array_filter(
						array_map( 'strtolower', apply_filters( 'wp_mcp_ai_allowed_file_mimes', $file_mimes, $provider ) )
					)
				)
			);

			if ( null === $usage ) {
				return array(
					'image' => $image_mimes,
					'file'  => $file_mimes,
				);
			}

			if ( 'image' === $usage ) {
				return $image_mimes;
			}

			if ( 'file' === $usage ) {
				return $file_mimes;
			}

			return array();
		}

		/**
		 * Retrieve cached OpenAI file metadata for an attachment.
		 *
		 * @param int $attachment_id Attachment ID.
		 * @return array
		 */
		protected function get_cached_openai_file_metadata( $attachment_id ) {
			$raw_meta = get_post_meta( $attachment_id, self::OPENAI_FILE_META_KEY, true );

			if ( is_array( $raw_meta ) ) {
				$normalised = $this->normalise_openai_file_metadata( $raw_meta );
			} elseif ( is_string( $raw_meta ) && '' !== $raw_meta ) {
				$normalised = $this->normalise_openai_file_metadata( array( 'file_id' => $raw_meta ) );
			} else {
				$normalised = array();
			}

			if ( empty( $normalised['file_id'] ) ) {
				return array();
			}

			return $normalised;
		}

		/**
		 * Persist OpenAI file metadata for an attachment.
		 *
		 * @param int   $attachment_id Attachment ID.
		 * @param array $metadata      Metadata to store.
		 * @return array Normalised metadata that was stored.
		 */
		protected function store_openai_file_metadata( $attachment_id, array $metadata ) {
			$previous   = $this->get_cached_openai_file_metadata( $attachment_id );
			$normalised = $this->normalise_openai_file_metadata( $metadata );

			if ( empty( $normalised['file_id'] ) ) {
				delete_post_meta( $attachment_id, self::OPENAI_FILE_META_KEY );

				if ( ! empty( $previous['file_id'] ) ) {
					$this->invalidate_file_id_cache( $previous['file_id'] );
				}

				return array();
			}

			update_post_meta( $attachment_id, self::OPENAI_FILE_META_KEY, $normalised );

			$file_id = $normalised['file_id'];

			$this->attachment_index[ $attachment_id ] = $file_id;
			$this->file_id_index[ $file_id ]          = (int) $attachment_id;

			$this->prime_file_id_cache( $file_id, $attachment_id );

			if ( ! empty( $previous['file_id'] ) && $previous['file_id'] !== $file_id ) {
				$this->invalidate_file_id_cache( $previous['file_id'] );
			}

			return $normalised;
		}

		/**
		 * Prime the persistent cache for a file identifier lookup.
		 *
		 * @param string $file_id       OpenAI file identifier.
		 * @param int    $attachment_id Attachment identifier.
		 */
		protected function prime_file_id_cache( $file_id, $attachment_id ) {
			$file_id       = sanitize_text_field( (string) $file_id );
			$attachment_id = (int) $attachment_id;

			if ( '' === $file_id || ! $attachment_id ) {
				return;
			}

			$cache_key = 'openai_file_lookup_' . md5( $file_id );

			wp_cache_set( $cache_key, $attachment_id, 'wp_mcp_ai_message_attachments', MINUTE_IN_SECONDS );
		}

		/**
		 * Remove cached entries for a file identifier lookup.
		 *
		 * @param string $file_id OpenAI file identifier.
		 */
		protected function invalidate_file_id_cache( $file_id ) {
			$file_id = sanitize_text_field( (string) $file_id );

			if ( '' === $file_id ) {
				return;
			}

			unset( $this->file_id_index[ $file_id ] );

			$cache_key = 'openai_file_lookup_' . md5( $file_id );

			wp_cache_delete( $cache_key, 'wp_mcp_ai_message_attachments' );
		}

		/**
		 * Normalise OpenAI file metadata.
		 *
		 * @param array $metadata Raw metadata.
		 * @return array
		 */
		protected function normalise_openai_file_metadata( array $metadata ) {
			$normalised = array();

			if ( isset( $metadata['file_id'] ) ) {
				$normalised['file_id'] = sanitize_text_field( $metadata['file_id'] );
			}

			if ( isset( $metadata['hash'] ) ) {
				$normalised['hash'] = sanitize_text_field( $metadata['hash'] );
			}

			if ( isset( $metadata['bytes'] ) ) {
				$normalised['bytes'] = max( 0, (int) $metadata['bytes'] );
			}

			if ( isset( $metadata['purpose'] ) ) {
				$normalised['purpose'] = sanitize_key( $metadata['purpose'] );
			}

			if ( isset( $metadata['filename'] ) ) {
				$normalised['filename'] = sanitize_file_name( $metadata['filename'] );
			}

			if ( isset( $metadata['mime_type'] ) ) {
				if ( function_exists( 'sanitize_mime_type' ) ) {
					$normalised['mime_type'] = sanitize_mime_type( $metadata['mime_type'] );
				} else {
					$normalised['mime_type'] = sanitize_text_field( $metadata['mime_type'] );
				}
			}

			if ( isset( $metadata['modified'] ) ) {
				$normalised['modified'] = max( 0, (int) $metadata['modified'] );
			}

			if ( isset( $metadata['created_at'] ) ) {
				$normalised['created_at'] = max( 0, (int) $metadata['created_at'] );
			}

			if ( isset( $metadata['status'] ) ) {
				$normalised['status'] = sanitize_key( $metadata['status'] );
			}

			if ( isset( $metadata['data'] ) ) {
				$normalised['data'] = is_string( $metadata['data'] ) ? $metadata['data'] : ''; // Base64 payload is expected.
			}

			return $normalised;
		}

		/**
		 * Determine if cached OpenAI metadata can be reused for the current file.
		 *
		 * @param array  $metadata     Cached metadata.
		 * @param string $purpose      Desired file purpose.
		 * @param string $file_hash    Current file hash.
		 * @param int    $file_size    Current file size.
		 * @param int    $file_modtime Current file modification time.
		 * @return bool
		 */
		protected function should_reuse_openai_file( array $metadata, $purpose, $file_hash, $file_size, $file_modtime ) {
			if ( empty( $metadata['file_id'] ) ) {
				return false;
			}

			$file_id = (string) $metadata['file_id'];

			$looks_remote = 0 === strpos( $file_id, 'file-' );
			/**
			 * Filter whether cached OpenAI metadata should be considered remote.
			 *
			 * @param bool  $looks_remote Whether the stored identifier looks like a remote file.
			 * @param string $file_id      Stored file identifier.
			 * @param array  $metadata     Cached metadata array.
			 */
			$looks_remote = apply_filters( 'wp_mcp_ai_openai_file_looks_remote', $looks_remote, $file_id, $metadata );

			if ( ! $looks_remote ) {
				return false;
			}

			if ( ! empty( $metadata['purpose'] ) && $metadata['purpose'] !== $purpose ) {
				return false;
			}

			$cached_hash = isset( $metadata['hash'] ) ? (string) $metadata['hash'] : '';

			if ( '' !== $cached_hash && '' !== $file_hash && $this->hashes_match( $cached_hash, $file_hash ) ) {
				return true;
			}

			$size_matches = isset( $metadata['bytes'] ) && (int) $metadata['bytes'] === (int) $file_size;
			$time_matches = isset( $metadata['modified'] ) && (int) $metadata['modified'] === (int) $file_modtime;

			return $size_matches && $time_matches;
		}

		/**
		 * Generate a hash for the given file.
		 *
		 * @param string $file_path File path.
		 * @return string
		 */
		protected function hash_file_contents( $file_path ) {
			if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
				return '';
			}

			$hash = md5_file( $file_path ); // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_md5_file

			if ( false === $hash ) {
				return '';
			}

			return (string) $hash;
		}

		/**
		 * Retrieve the file modification time.
		 *
		 * @param string $file_path File path.
		 * @return int
		 */
		protected function get_file_modified_time( $file_path ) {
			if ( ! file_exists( $file_path ) ) {
				return 0;
			}

			$modified = filemtime( $file_path );

			if ( false === $modified ) {
				return 0;
			}

			return (int) $modified;
		}

		/**
		 * Timing-safe comparison for file hashes when available.
		 *
		 * @param string $hash_a First hash.
		 * @param string $hash_b Second hash.
		 * @return bool
		 */
		protected function hashes_match( $hash_a, $hash_b ) {
			$hash_a = (string) $hash_a;
			$hash_b = (string) $hash_b;

			if ( '' === $hash_a || '' === $hash_b ) {
				return false;
			}

			if ( function_exists( 'hash_equals' ) ) {
				return hash_equals( $hash_a, $hash_b );
			}

			return $hash_a === $hash_b;
		}

		/**
		 * Delete a remote OpenAI file associated with an attachment.
		 *
		 * @param string $file_id File identifier.
		 */
		protected function delete_remote_openai_file( $file_id ) {
			$file_id = sanitize_text_field( $file_id );

			if ( '' === $file_id ) {
				return;
			}

			if ( isset( self::$deleted_file_ids[ $file_id ] ) ) {
				return;
			}

			if ( ! class_exists( 'WP_MCP_AI_OpenAI_Client' ) ) {
				require_once WP_MCP_AI_PATH . 'includes/class-openai-client.php';
			}

			$client = new WP_MCP_AI_OpenAI_Client();

			self::$deleted_file_ids[ $file_id ] = true;

			$result = $client->delete_file( $file_id );

			if ( is_wp_error( $result ) ) {
				WP_MCP_AI_Logger::log_error(
					'Failed to delete OpenAI file for attachment.',
					array(
						'file_id' => $file_id,
						'error'   => $result->get_error_message(),
					)
				);

				return;
			}

			WP_MCP_AI_Logger::log_event(
				'openai_file_cleanup',
				'Deleted OpenAI file associated with an attachment.',
				array( 'file_id' => $file_id )
			);
		}

		/**
		 * Read a file from disk.
		 *
		 * @param string $file_path File path.
		 * @return string|false
		 */
		protected function read_file_contents( $file_path ) {
			if ( ! class_exists( 'WP_Filesystem_Base' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			global $wp_filesystem;

			if ( $wp_filesystem instanceof WP_Filesystem_Base ) {
				$contents = $wp_filesystem->get_contents( $file_path );
				if ( is_string( $contents ) ) {
					return $contents;
				}
			}

			if ( ! is_readable( $file_path ) ) {
				return false;
			}

			$contents = file_get_contents( $file_path ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents

			return ( false === $contents ) ? false : (string) $contents;
		}

		/**
		 * Sanitise caption text for segments.
		 *
		 * @param string $caption Raw caption text.
		 * @return string
		 */
		protected function sanitize_caption( $caption ) {
			return trim( wp_strip_all_tags( (string) $caption ) );
		}

		/**
		 * Sanitise the optional image detail hint.
		 *
		 * @param string $detail Raw detail string.
		 * @return string
		 */
		protected function sanitize_detail( $detail ) {
			$detail = sanitize_key( $detail );
			if ( in_array( $detail, array( 'low', 'high', 'auto' ), true ) ) {
				return $detail;
			}

			return '';
		}

		/**
		 * Check if a MIME type corresponds to an image file.
		 *
		 * @param string $mime_type MIME type to check.
		 * @param string $provider  Optional provider name. Default 'openai'.
		 * @return bool True if the MIME type is an image, false otherwise.
		 */
		public static function is_image_mime_type( $mime_type, $provider = 'openai' ) {
			$mime_type   = strtolower( (string) $mime_type );
			$image_mimes = self::get_allowed_mime_types( 'image', $provider );

			return in_array( $mime_type, $image_mimes, true );
		}
	}
}
