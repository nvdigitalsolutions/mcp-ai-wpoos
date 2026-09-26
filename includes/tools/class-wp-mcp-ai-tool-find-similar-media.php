<?php
/**
 * Tool for finding visually similar images in the media library.
 *
 * The second rung of the non-LLM image identification ladder: perceptual
 * (dHash) lookup against local attachments — answers "have we seen this
 * image before?" for free, without sending pixels anywhere.
 *
 * @package WP_MCP_AI
 * @since   1.1.87
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

require_once WP_MCP_AI_PATH . 'includes/interfaces/interface-wp-mcp-ai-tool.php';
require_once WP_MCP_AI_PATH . 'includes/helpers/class-wp-mcp-ai-image-dhash.php';
require_once WP_MCP_AI_PATH . 'includes/security/class-wp-mcp-ai-url-guard.php';
require_once WP_MCP_AI_PATH . 'includes/traits/trait-wp-mcp-ai-attachment-file-resolver.php';
require_once WP_MCP_AI_PATH . 'includes/tools/trait-wp-mcp-ai-tool-chat-response.php';

/**
 * Provides an assistant tool that finds visually similar images in the
 * WordPress media library using perceptual hashing.
 *
 * Hashes are cached in the `_wp_mcp_ai_image_dhash` post meta and computed
 * lazily on first use. Pure WordPress + GD — no external API calls.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Tool_Find_Similar_Media implements WP_MCP_AI_Tool_Interface, WP_MCP_AI_Tool_Capability_Flags_Interface, WP_MCP_AI_Tool_Usage_Guidance_Interface {
	use WP_MCP_AI_Tool_Chat_Response;
	use WP_MCP_AI_Attachment_File_Resolver;

	const DEFAULT_REQUIRED_CAPABILITY = 'manage_options';

	/**
	 * Default maximum Hamming distance considered "similar" (of 64 bits).
	 */
	const DEFAULT_THRESHOLD = 10;

	/**
	 * Default number of recent attachments scanned per call.
	 */
	const DEFAULT_SCAN_LIMIT = 300;

	/**
	 * {@inheritdoc}
	 */
	public function get_slug() {
		return 'find_similar_media';
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_name() {
		return __( 'Find Similar Media', 'mcp-ai-wpoos' );
	}

	/**
	 * {@inheritdoc}
	 */
	public function get_description() {
		return __( 'Finds visually similar images already in the WordPress media library using a perceptual hash (dHash). Free, local, and deterministic — run this before sending an image to a vision model. Requires the GD PHP extension.', 'mcp-ai-wpoos' );
	}

	/**
	 * Get usage guidance for the tool.
	 *
	 * @return array
	 */
	public function get_usage_guidance() {
		return array(
			'when_to_use'     => __( 'Checking whether an image (or a near-duplicate of it) already exists in the media library, before spending money on external analysis. Ideal first step for "do we have this image already?" questions.', 'mcp-ai-wpoos' ),
			'when_not_to_use' => __( 'Semantic similarity ("pictures of cats", not the same cat photo) — hashing matches visual similarity, not meaning. Use detect_image_content or identify_image for identification questions.', 'mcp-ai-wpoos' ),
			'related_tools'   => array( 'get_image_metadata', 'identify_image', 'detect_image_content', 'search_attachments' ),
			'notes'           => __( 'Hashes are cached per attachment and computed lazily. Larger libraries may need a higher scan_limit; a daily backfill cron keeps the cache warm.', 'mcp-ai-wpoos' ),
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
					'description' => __( 'WordPress attachment ID of the reference image.', 'mcp-ai-wpoos' ),
				),
				'image_url'     => array(
					'type'        => 'string',
					'format'      => 'uri',
					'description' => __( 'URL of the reference image. Remote URLs are fetched server-side (SSRF-guarded).', 'mcp-ai-wpoos' ),
				),
				'threshold'     => array(
					'type'        => 'integer',
					'minimum'     => 0,
					'maximum'     => 64,
					'description' => __( 'Maximum Hamming distance (0-64 bits) considered similar. Lower is stricter. Default 10.', 'mcp-ai-wpoos' ),
				),
				'limit'         => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 20,
					'description' => __( 'Maximum number of matches to return. Default 5.', 'mcp-ai-wpoos' ),
				),
				'scan_limit'    => array(
					'type'        => 'integer',
					'minimum'     => 1,
					'maximum'     => 1000,
					'description' => __( 'Maximum number of recent attachments to scan. Default 300.', 'mcp-ai-wpoos' ),
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
			'wp_mcp_ai_find_similar_media_required_capability',
			self::DEFAULT_REQUIRED_CAPABILITY,
			$context,
			$arguments,
			$this
		);

		if ( $required_capability && ( ! $user_id || ! user_can( $user_id, $required_capability ) ) ) {
			return new WP_Error(
				'wp_mcp_ai_find_similar_media_forbidden',
				__( 'You do not have permission to use Find Similar Media.', 'mcp-ai-wpoos' ),
				array( 'status' => 403 )
			);
		}

		$threshold  = isset( $arguments['threshold'] ) ? min( 64, max( 0, absint( $arguments['threshold'] ) ) ) : self::DEFAULT_THRESHOLD;
		$limit      = isset( $arguments['limit'] ) ? min( 20, max( 1, absint( $arguments['limit'] ) ) ) : 5;
		$scan_limit = isset( $arguments['scan_limit'] ) ? min( 1000, max( 1, absint( $arguments['scan_limit'] ) ) ) : self::DEFAULT_SCAN_LIMIT;

		$target = $this->resolve_target_hash( $arguments );

		if ( is_wp_error( $target ) ) {
			return $target;
		}

		list( $target_hash, $target_attachment_id ) = $target;

		$matches = $this->scan_library( $target_hash, $target_attachment_id, $threshold, $limit, $scan_limit );

		if ( is_wp_error( $matches ) ) {
			return $matches;
		}

		return $this->format_success_response(
			sprintf(
				/* translators: %d: number of matches */
				_n( 'Found %d similar image in the media library.', 'Found %d similar images in the media library.', count( $matches['matches'] ), 'mcp-ai-wpoos' ),
				count( $matches['matches'] )
			),
			$matches
		);
	}

	/**
	 * Resolve the reference image to a hash.
	 *
	 * @param array $arguments Tool arguments.
	 * @return array|WP_Error Two-element array ( hash, attachment_id|0 ), or WP_Error.
	 */
	private function resolve_target_hash( array $arguments ) {
		if ( ! empty( $arguments['attachment_id'] ) ) {
			$attachment_id = absint( $arguments['attachment_id'] );
			$hash          = WP_MCP_AI_Image_DHash::get_attachment_hash( $attachment_id );

			if ( is_wp_error( $hash ) ) {
				return $hash;
			}

			return array( $hash, $attachment_id );
		}

		if ( ! empty( $arguments['image_url'] ) ) {
			$url = esc_url_raw( $arguments['image_url'] );

			// Local media URLs resolve straight to the attachment hash.
			$attachment_id = attachment_url_to_postid( $url );

			if ( $attachment_id > 0 && 'attachment' === get_post_type( $attachment_id ) ) {
				$hash = WP_MCP_AI_Image_DHash::get_attachment_hash( $attachment_id );

				if ( is_wp_error( $hash ) ) {
					return $hash;
				}

				return array( $hash, $attachment_id );
			}

			return array( $this->hash_remote_url( $url ), 0 );
		}

		return new WP_Error(
			'wp_mcp_ai_find_similar_media_missing_image',
			__( 'One of attachment_id or image_url must be provided.', 'mcp-ai-wpoos' ),
			array( 'status' => 400 )
		);
	}

	/**
	 * Download a remote image (SSRF-guarded) and hash it.
	 *
	 * @param string $url Remote image URL.
	 * @return string|WP_Error 16-char hex hash, or WP_Error.
	 */
	private function hash_remote_url( $url ) {
		$guard = WP_MCP_AI_URL_Guard::validate( $url );

		if ( is_wp_error( $guard ) ) {
			return new WP_Error(
				'wp_mcp_ai_find_similar_media_blocked_url',
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
				'wp_mcp_ai_find_similar_media_download_failed',
				sprintf(
					/* translators: %s: error message */
					__( 'Could not download the reference image: %s', 'mcp-ai-wpoos' ),
					$tmp_file->get_error_message()
				),
				array( 'status' => 502 )
			);
		}

		$hash = WP_MCP_AI_Image_DHash::compute( $tmp_file );

		wp_delete_file( $tmp_file );

		return $hash;
	}

	/**
	 * Scan recent image attachments for hashes within the threshold.
	 *
	 * @param string $target_hash          Reference hash.
	 * @param int    $target_attachment_id Reference attachment ID (excluded from matches).
	 * @param int    $threshold            Maximum Hamming distance.
	 * @param int    $limit                Maximum matches to return.
	 * @param int    $scan_limit           Maximum attachments to scan.
	 * @return array|WP_Error Scan summary with matches, or WP_Error.
	 */
	private function scan_library( $target_hash, $target_attachment_id, $threshold, $limit, $scan_limit ) {
		$query = new WP_Query(
			array(
				'post_type'      => 'attachment',
				'post_status'    => 'inherit',
				'post_mime_type' => 'image',
				'posts_per_page' => $scan_limit,
				'orderby'        => 'date',
				'order'          => 'DESC',
				'fields'         => 'ids',
				'no_found_rows'  => true,
			)
		);

		$scanned = 0;
		$skipped = 0;
		$matches = array();

		foreach ( $query->posts as $attachment_id ) {
			if ( absint( $attachment_id ) === absint( $target_attachment_id ) ) {
				continue;
			}

			$hash = WP_MCP_AI_Image_DHash::get_attachment_hash( $attachment_id );

			if ( is_wp_error( $hash ) ) {
				++$skipped;
				continue;
			}

			++$scanned;
			$distance = WP_MCP_AI_Image_DHash::distance( $target_hash, $hash );

			if ( is_wp_error( $distance ) || $distance > $threshold ) {
				continue;
			}

			$matches[] = array(
				'attachment_id' => absint( $attachment_id ),
				'title'         => sanitize_text_field( get_the_title( $attachment_id ) ),
				'url'           => esc_url_raw( (string) wp_get_attachment_url( $attachment_id ) ),
				'distance'      => (int) $distance,
				'similarity'    => round( ( 64 - $distance ) / 64 * 100, 1 ),
			);
		}

		// Best matches first.
		usort(
			$matches,
			static function ( $a, $b ) {
				return $a['distance'] <=> $b['distance'];
			}
		);

		$matches = array_slice( $matches, 0, $limit );

		return array(
			'target_hash' => $target_hash,
			'scanned'     => $scanned,
			'skipped'     => $skipped,
			'threshold'   => $threshold,
			'match_count' => count( $matches ),
			'matches'     => $matches,
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
			'local-only',           // Media-library scan; remote URLs are optional input, not an external API.
			'cacheable',            // Deterministic for a given image; results can be cached.
		);
	}
}
