<?php
/**
 * Reverse-image web search tool (Bing Visual Search / SerpApi Google Lens).
 *
 * The fourth rung of the non-LLM image identification ladder: identify a
 * product, logo, place, or exact image across the web using official
 * reverse-image search APIs — no vision language model involved.
 *
 * @package WP_MCP_AI_Pro
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions. All rights reserved.
 * @license   Proprietary
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-url-guard.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides an assistant tool that identifies an image through reverse-image
 * web search.
 *
 * Providers:
 * - `bing` — Bing Visual Search API (official; Azure key).
 * - `serpapi` — SerpApi Google Lens (requires a publicly reachable image URL).
 *
 * Image bytes are sent to the selected provider; the tool refuses to run
 * without configured credentials.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Search_Similar_Images implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Bing Visual Search endpoint.
	 */
	const BING_ENDPOINT = 'https://api.bing.microsoft.com/v7.0/images/visualsearch';

	/**
	 * SerpApi Google Lens endpoint.
	 */
	const SERPAPI_ENDPOINT = 'https://serpapi.com/search.json';

	/**
	 * Check whether any reverse-image search provider has credentials.
	 *
	 * Used by identify_image to gate the web-search rung without executing
	 * this tool.
	 *
	 * @return bool True when at least one provider key is configured.
	 */
	public static function has_credentials() {
		$settings = get_option( 'wp_mcp_ai_settings', array() );

		return ( ! empty( $settings['va_bing_visual_search_key'] ) || ! empty( $settings['va_serpapi_api_key'] ) );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'search_similar_images';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Search Similar Images', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Identifies what an image shows by reverse-image web search (Bing Visual Search or SerpApi Google Lens). Returns best-guess labels and visual matches with source URLs. Sends the image to the provider — requires configured credentials.', 'mcp-ai-wpoos-pro' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Identifying products, logos, landmarks, celebrities, or finding where an exact image appears on the web — questions the media library and classic detectors cannot answer.', 'mcp-ai-wpoos-pro' ),
			'when_not_to_use' => __( 'Private or confidential images — bytes are sent to the search provider. Prefer identify_image for routine identification questions.', 'mcp-ai-wpoos-pro' ),
			'related_tools'   => array( 'identify_image', 'detect_image_content', 'vision_product_search', 'analyze_image' ),
			'notes'           => __( 'Bing accepts uploads and URLs; SerpApi Google Lens requires a publicly reachable URL. Configure keys under Pro → Vision Analysis settings.', 'mcp-ai-wpoos-pro' ),
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_parameters_schema() {
		return array(
			'type'                 => 'object',
			'properties'           => array(
				'attachment_id' => array(
					'type'        => array( 'integer', 'string' ),
					'description' => __( 'WordPress attachment ID of the image to search.', 'mcp-ai-wpoos-pro' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the image. Required for SerpApi Google Lens; optional for Bing.', 'mcp-ai-wpoos-pro' ),
				),
				'image_content' => array(
					'type'        => 'string',
					'description' => __( 'Base64-encoded image content (Bing provider only).', 'mcp-ai-wpoos-pro' ),
				),
				'provider'      => array(
					'type'        => 'string',
					'description' => __( 'Search provider. Defaults to the configured preference (auto picks Bing first when its key is present).', 'mcp-ai-wpoos-pro' ),
					'enum'        => array( 'auto', 'bing', 'serpapi' ),
					'default'     => 'auto',
				),
				'max_results'   => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => __( 'Maximum visual matches to return. Default 10.', 'mcp-ai-wpoos-pro' ),
				),
			),
			'required'             => array(),
			'additionalProperties' => false,
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_required_capability() {
		return 'edit_posts';
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

		$required_capability = apply_filters(
			'wp_mcp_ai_search_similar_images_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_forbidden',
				__( 'You do not have permission to use Search Similar Images.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 403 )
			);
		}

		if ( empty( $arguments['attachment_id'] ) && empty( $arguments['image_url'] ) && empty( $arguments['image_content'] ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_missing_input',
				__( 'One of attachment_id, image_url, or image_content must be provided.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$max_results = isset( $arguments['max_results'] ) ? min( 20, max( 1, absint( $arguments['max_results'] ) ) ) : 10;

		$settings = get_option( 'wp_mcp_ai_settings', array() );

		$provider = isset( $arguments['provider'] ) ? sanitize_key( $arguments['provider'] ) : 'auto';
		if ( 'auto' === $provider ) {
			$provider = isset( $settings['va_reverse_search_provider'] ) ? sanitize_key( $settings['va_reverse_search_provider'] ) : 'auto';
		}

		if ( 'auto' === $provider ) {
			$provider = ! empty( $settings['va_bing_visual_search_key'] ) ? 'bing' : ( ! empty( $settings['va_serpapi_api_key'] ) ? 'serpapi' : '' );
		}

		if ( 'bing' === $provider ) {
			$api_key = isset( $settings['va_bing_visual_search_key'] ) ? sanitize_text_field( $settings['va_bing_visual_search_key'] ) : '';
		} elseif ( 'serpapi' === $provider ) {
			$api_key = isset( $settings['va_serpapi_api_key'] ) ? sanitize_text_field( $settings['va_serpapi_api_key'] ) : '';
		} else {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_no_credentials',
				__( 'No reverse-image search credentials are configured. Add a Bing Visual Search or SerpApi API key under Pro → Vision Analysis settings.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		if ( empty( $api_key ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_missing_api_key',
				sprintf(
					/* translators: %s: provider name */
					__( 'The selected provider (%s) has no configured API key.', 'mcp-ai-wpoos-pro' ),
					$provider
				),
				array( 'status' => 400 )
			);
		}

		if ( 'bing' === $provider ) {
			return $this->run_bing_search( $arguments, $api_key, $max_results );
		}

		return $this->run_serpapi_search( $arguments, $api_key, $max_results );
	}

	/**
	 * Run a Bing Visual Search request (multipart image upload).
	 *
	 * @param array  $arguments   Tool arguments.
	 * @param string $api_key     Azure key.
	 * @param int    $max_results Match cap.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	private function run_bing_search( array $arguments, $api_key, $max_results ) {
		$file = $this->resolve_local_file( $arguments );

		if ( is_wp_error( $file ) ) {
			return $file;
		}

		list( $file_path, $is_temp ) = $file;

		if ( ! function_exists( 'curl_file_create' ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_curl_required',
				__( 'Multipart uploads require the cURL extension.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 501 )
			);
		}

		$filetype = wp_check_filetype( $file_path );
		$mime     = ! empty( $filetype['type'] ) ? $filetype['type'] : 'application/octet-stream';

		$postfields = array(
			// phpcs:ignore WordPress.WP.AlternativeFunctions.curl_curl_file_create -- cURL streaming multipart upload; see sidecar trait pattern.
			'image' => curl_file_create( $file_path, $mime, basename( $file_path ) ),
		);

		// phpcs:disable WordPress.WP.AlternativeFunctions.curl_curl_init,WordPress.WP.AlternativeFunctions.curl_curl_setopt,WordPress.WP.AlternativeFunctions.curl_curl_exec,WordPress.WP.AlternativeFunctions.curl_curl_errno,WordPress.WP.AlternativeFunctions.curl_curl_error,WordPress.WP.AlternativeFunctions.curl_curl_getinfo,WordPress.WP.AlternativeFunctions.curl_curl_close -- Streaming multipart upload to Bing Visual Search; mirrors the sidecar upload pattern.
		$ch = curl_init( add_query_arg( 'mkt', 'en-us', self::BING_ENDPOINT ) );
		if ( false === $ch ) {
			return new WP_Error( 'wp_mcp_ai_search_similar_images_curl_failed', __( 'Failed to initialise cURL.', 'mcp-ai-wpoos-pro' ) );
		}

		curl_setopt( $ch, CURLOPT_POST, true );
		curl_setopt( $ch, CURLOPT_POSTFIELDS, $postfields );
		curl_setopt( $ch, CURLOPT_RETURNTRANSFER, true );
		curl_setopt( $ch, CURLOPT_TIMEOUT, 45 );
		curl_setopt( $ch, CURLOPT_CONNECTTIMEOUT, 15 );
		curl_setopt(
			$ch,
			CURLOPT_HTTPHEADER,
			array(
				'Ocp-Apim-Subscription-Key: ' . $api_key,
				'Content-Type: multipart/form-data',
			)
		);

		$raw = curl_exec( $ch );
		if ( false === $raw ) {
			$error = curl_error( $ch );
			curl_close( $ch );

			if ( $is_temp && file_exists( $file_path ) ) {
				wp_delete_file( $file_path );
			}

			return new WP_Error(
				'wp_mcp_ai_search_similar_images_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Bing Visual Search request failed: %s', 'mcp-ai-wpoos-pro' ),
					$error
				),
				array( 'status' => 502 )
			);
		}

		$status = (int) curl_getinfo( $ch, CURLINFO_RESPONSE_CODE );
		curl_close( $ch );
		// phpcs:enable

		if ( $is_temp && file_exists( $file_path ) ) {
			wp_delete_file( $file_path );
		}

		$decoded = json_decode( $raw, true );

		if ( $status >= 400 || ! is_array( $decoded ) ) {
			$message = __( 'Bing Visual Search returned an error.', 'mcp-ai-wpoos-pro' );
			if ( is_array( $decoded ) && isset( $decoded['errors'][0]['message'] ) ) {
				$message = $decoded['errors'][0]['message'];
			}

			return new WP_Error(
				'wp_mcp_ai_search_similar_images_api_error',
				$message,
				array( 'status' => $status )
			);
		}

		return $this->normalize_bing_response( $decoded, $max_results );
	}

	/**
	 * Run a SerpApi Google Lens search (requires a public URL).
	 *
	 * @param array  $arguments   Tool arguments.
	 * @param string $api_key     SerpApi key.
	 * @param int    $max_results Match cap.
	 * @return array|WP_Error Canonical envelope or error.
	 */
	private function run_serpapi_search( array $arguments, $api_key, $max_results ) {
		$url = '';

		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$url           = (string) wp_get_attachment_url( $attachment_id );
		} elseif ( ! empty( $arguments['image_url'] ) ) {
			$url = esc_url_raw( $arguments['image_url'] );
		}

		if ( empty( $url ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_serpapi_requires_url',
				__( 'SerpApi Google Lens requires a publicly reachable image URL. Provide image_url or an attachment_id, or switch to the Bing provider for uploads.', 'mcp-ai-wpoos-pro' ),
				array( 'status' => 400 )
			);
		}

		$guard = WP_MCP_AI_URL_Guard::validate( $url );
		if ( is_wp_error( $guard ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_blocked_url',
				$guard->get_error_message(),
				array( 'status' => 403 )
			);
		}

		$response = wp_remote_get(
			add_query_arg(
				array(
					'engine'  => 'google_lens',
					'url'     => rawurlencode( $url ),
					'api_key' => $api_key,
				),
				self::SERPAPI_ENDPOINT
			),
			array( 'timeout' => 30 )
		);

		if ( is_wp_error( $response ) ) {
			return new WP_Error(
				'wp_mcp_ai_search_similar_images_request_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'SerpApi request failed: %s', 'mcp-ai-wpoos-pro' ),
					$response->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$status  = wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( wp_remote_retrieve_body( $response ), true );

		if ( $status >= 400 || ! is_array( $decoded ) ) {
			$message = __( 'SerpApi returned an error.', 'mcp-ai-wpoos-pro' );
			if ( is_array( $decoded ) && isset( $decoded['error'] ) ) {
				$message = sanitize_text_field( $decoded['error'] );
			}

			return new WP_Error(
				'wp_mcp_ai_search_similar_images_api_error',
				$message,
				array( 'status' => $status )
			);
		}

		return $this->normalize_serpapi_response( $decoded, $max_results );
	}

	/**
	 * Normalize a Bing Visual Search response into the common shape.
	 *
	 * @param array $decoded     Bing response.
	 * @param int   $max_results Match cap.
	 * @return array Canonical envelope.
	 */
	private function normalize_bing_response( array $decoded, $max_results ) {
		$best_guess = '';
		$matches    = array();

		foreach ( $decoded['tags'] as $tag ) {
			if ( '' !== $best_guess && empty( $tag['displayName'] ) ) {
				continue;
			}

			if ( '' === $best_guess && ! empty( $tag['displayName'] ) ) {
				$best_guess = sanitize_text_field( $tag['displayName'] );
			}

			if ( empty( $tag['actions'] ) ) {
				continue;
			}

			foreach ( $tag['actions'] as $action ) {
				if ( empty( $action['data']['value'] ) ) {
					continue;
				}

				foreach ( $action['data']['value'] as $item ) {
					if ( empty( $item['hostPageUrl'] ) && empty( $item['webSearchUrl'] ) ) {
						continue;
					}

					$matches[] = array(
						'title'     => sanitize_text_field( isset( $item['name'] ) ? $item['name'] : '' ),
						'url'       => esc_url_raw( isset( $item['hostPageUrl'] ) ? $item['hostPageUrl'] : $item['webSearchUrl'] ),
						'source'    => sanitize_text_field( isset( $item['hostPageDisplayUrl'] ) ? $item['hostPageDisplayUrl'] : '' ),
						'thumbnail' => esc_url_raw( isset( $item['thumbnailUrl'] ) ? $item['thumbnailUrl'] : '' ),
					);

					if ( count( $matches ) >= $max_results ) {
						break 3;
					}
				}
			}
		}

		return $this->compose_envelope( 'bing', $best_guess, $matches );
	}

	/**
	 * Normalize a SerpApi Google Lens response into the common shape.
	 *
	 * @param array $decoded     SerpApi response.
	 * @param int   $max_results Match cap.
	 * @return array Canonical envelope.
	 */
	private function normalize_serpapi_response( array $decoded, $max_results ) {
		$best_guess = '';
		$matches    = array();

		if ( ! empty( $decoded['knowledge_graph'] ) ) {
			$titles = array_filter(
				array(
					isset( $decoded['knowledge_graph']['title'] ) ? $decoded['knowledge_graph']['title'] : '',
					isset( $decoded['knowledge_graph']['type'] ) ? $decoded['knowledge_graph']['type'] : '',
				)
			);
			if ( ! empty( $titles ) ) {
				$best_guess = sanitize_text_field( implode( ' — ', $titles ) );
			}
		}

		if ( empty( $best_guess ) && ! empty( $decoded['exact_matches'][0]['title'] ) ) {
			$best_guess = sanitize_text_field( $decoded['exact_matches'][0]['title'] );
		}

		if ( ! empty( $decoded['visual_matches'] ) ) {
			foreach ( $decoded['visual_matches'] as $item ) {
				if ( empty( $item['link'] ) ) {
					continue;
				}

				$matches[] = array(
					'title'     => sanitize_text_field( isset( $item['title'] ) ? $item['title'] : '' ),
					'url'       => esc_url_raw( $item['link'] ),
					'source'    => sanitize_text_field( isset( $item['source'] ) ? $item['source'] : '' ),
					'thumbnail' => esc_url_raw( isset( $item['thumbnail'] ) ? $item['thumbnail'] : '' ),
				);

				if ( count( $matches ) >= $max_results ) {
					break;
				}
			}
		}

		return $this->compose_envelope( 'serpapi', $best_guess, $matches );
	}

	/**
	 * Compose the canonical success envelope.
	 *
	 * @param string $provider   Provider slug.
	 * @param string $best_guess Best-guess label.
	 * @param array  $matches    Normalized matches.
	 * @return array Canonical envelope.
	 */
	private function compose_envelope( $provider, $best_guess, array $matches ) {
		return $this->format_success_response(
			sprintf(
				/* translators: 1: provider, 2: match count */
				__( 'Reverse-image search via %1$s returned %2$d matches.', 'mcp-ai-wpoos-pro' ),
				$provider,
				count( $matches )
			),
			array(
				'provider'    => $provider,
				'best_guess'  => $best_guess,
				'match_count' => count( $matches ),
				'matches'     => $matches,
				'note'        => __( 'Image bytes were sent to the search provider. Results reflect the web as indexed by that provider and may change over time.', 'mcp-ai-wpoos-pro' ),
			)
		);
	}

	/**
	 * Resolve the input image to a local file for upload.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Two-element array ( path, is_temp ), or WP_Error.
	 */
	private function resolve_local_file( array $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$file_path     = get_attached_file( $attachment_id );

			if ( ! $file_path || ! file_exists( $file_path ) ) {
				return new WP_Error(
					'wp_mcp_ai_search_similar_images_missing_file',
					sprintf(
						/* translators: %d: attachment ID */
						__( 'No local file found for attachment ID %d.', 'mcp-ai-wpoos-pro' ),
						$attachment_id
					),
					array( 'status' => 404 )
				);
			}

			return array( $file_path, false );
		}

		if ( ! empty( $arguments['image_content'] ) ) {
			$content = sanitize_text_field( $arguments['image_content'] );

			// phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions.obfuscation_base64_decode -- Decoding caller-provided image content for upload; no secret handling involved.
			$decoded = base64_decode( $content, true );

			if ( false === $decoded || '' === $decoded ) {
				return new WP_Error(
					'wp_mcp_ai_search_similar_images_invalid_content',
					__( 'image_content is not valid base64-encoded image data.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 400 )
				);
			}

			$tmp_file = wp_tempnam( 'wpoos-visual-search' );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- Writing temporary upload for the multipart request.
			if ( false === file_put_contents( $tmp_file, $decoded ) ) {
				return new WP_Error(
					'wp_mcp_ai_search_similar_images_temp_write_failed',
					__( 'Could not write the image content to a temporary file.', 'mcp-ai-wpoos-pro' ),
					array( 'status' => 500 )
				);
			}

			return array( $tmp_file, true );
		}

		if ( ! empty( $arguments['image_url'] ) ) {
			$url = esc_url_raw( $arguments['image_url'] );

			$attachment_id = attachment_url_to_postid( $url );
			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$file_path = get_attached_file( $attachment_id );

				if ( $file_path && file_exists( $file_path ) ) {
					return array( $file_path, false );
				}
			}

			$guard = WP_MCP_AI_URL_Guard::validate( $url );
			if ( is_wp_error( $guard ) ) {
				return new WP_Error(
					'wp_mcp_ai_search_similar_images_blocked_url',
					$guard->get_error_message(),
					array( 'status' => 403 )
				);
			}

			if ( ! function_exists( 'download_url' ) ) {
				require_once ABSPATH . 'wp-admin/includes/file.php';
			}

			$tmp_file = download_url( $url, 30 );

			if ( is_wp_error( $tmp_file ) ) {
				return new WP_Error(
					'wp_mcp_ai_search_similar_images_download_failed',
					sprintf(
						/* translators: %s: error message */
						__( 'Could not download the image: %s', 'mcp-ai-wpoos-pro' ),
						$tmp_file->get_error_message()
					),
					array( 'status' => 502 )
				);
			}

			return array( $tmp_file, true );
		}

		return new WP_Error(
			'wp_mcp_ai_search_similar_images_missing_input',
			__( 'One of attachment_id, image_url, or image_content must be provided.', 'mcp-ai-wpoos-pro' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Get extended tool definition including toolkit metadata.
	 *
	 * @since 1.1.87
	 *
	 * @return array Tool definition with metadata.
	 */
	public function get_definition() {
		return array(
			'name'                  => $this->get_name(),
			'description'           => $this->get_description(),
			'toolkit'               => 'media_processing',
			'pattern_compatibility' => array( 'sequential' ),
			'profession_tags'       => array( 'data_scientist', 'researcher' ),
			'risk_level'            => 'info',
		);
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_capability_flags() {
		return array(
			'read-only',            // Only reads data, does not modify state.
			'requires-capability',  // Requires user capabilities.
			'external-api',         // Bing / SerpApi.
			'non-deterministic',    // Web results change over time.
		);
	}
}
