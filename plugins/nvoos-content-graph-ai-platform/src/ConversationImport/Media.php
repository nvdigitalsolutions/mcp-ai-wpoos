<?php
/**
 * Media sideloading for imported conversations (Wave E4, sub-cluster 6).
 *
 * Aligned port of the base plugin's
 * `WP_MCP_AI_Conversation_Import_Media`: byte-identical per-run image
 * cap (50), the `sediment://file_*` pointer pattern, the allowed
 * extension whitelist, the per-instance resolved-URL cache, the
 * `file_XXXX-sanitized` / `file_XXXX` location fallbacks, the
 * `media_handle_sideload` media-library import, and the
 * `with_messages()` placeholder rewrite that preserves the dedupe
 * hash.
 *
	 * Documented deviations:
	 *  - Class name/namespace — the platform's PSR-4 tree.
	 *  - The conversation parameter is deliberately untyped on the
	 *    public surface: monolith installs flow the base
	 *    `WP_MCP_AI_Conversation_Import_Conversation` class through the
	 *    manager (base adapters), so the boundary validates via
	 *    `is_conversation_instance()` — the per-mode seam — instead of
	 *    a fixed type hint (same rationale as the detector's
	 *    `is_adapter_instance()`).
	 *  - `WP_Error` is fully qualified (`\WP_Error`).
 *
 * @since 2.1.0
 * @package NvoosContentGraphAiPlatform\ConversationImport
 */

declare(strict_types=1);

namespace NvoosContentGraphAiPlatform\ConversationImport;

/**
 * Sideloads referenced export images into the media library.
 *
 * @since 2.1.0
 */
class Media {

	const MAX_IMAGES_PER_RUN = 50;
	const POINTER_PATTERN    = '/\[Image: sediment:\/\/(file_[A-Za-z0-9_]+)\]/';
	const ALLOWED_EXTENSIONS = array( 'jpg', 'jpeg', 'png', 'gif', 'webp' );

	/**
	 * Resolved attachment URL cache, keyed by file ID.
	 *
	 * @var array
	 */
	protected $resolved = array();

	/**
	 * Images sideloaded so far (safety cap).
	 *
	 * @var int
	 */
	protected $count = 0;

	/**
	 * Sideload images referenced by a conversation and rewrite placeholders.
	 *
	 * @param Conversation|\WP_MCP_AI_Conversation_Import_Conversation $conversation Canonical conversation (platform class standalone, base class monolith).
	 * @param string                                                    $payload_dir  Directory holding the export payload and sibling files.
	 * @return Conversation|\WP_MCP_AI_Conversation_Import_Conversation|\WP_Error Conversation with rewritten placeholders
	 *         (possibly the same instance when nothing changed).
	 */
	public function sideload( $conversation, $payload_dir ) {
		if ( ! $this->is_conversation_instance( $conversation ) ) {
			return new \WP_Error(
				'wp_mcp_ai_import_invalid_conversation',
				__( 'The canonical conversation is invalid for this install mode.', 'nvoos-content-graph-ai-platform' )
			);
		}

		$messages = $conversation->get_messages();
		$changed  = false;

		foreach ( $messages as $index => $message ) {
			if ( ! preg_match_all( self::POINTER_PATTERN, $message['content'], $matches, PREG_SET_ORDER ) ) {
				continue;
			}

			foreach ( $matches as $match ) {
				if ( $this->count >= self::MAX_IMAGES_PER_RUN ) {
					break 2;
				}

				$file_id = sanitize_key( $match[1] );
				$url     = $this->resolve_image( $file_id, $payload_dir );

				if ( '' !== $url ) {
					$messages[ $index ]['content'] = str_replace( $match[0], '[Image: ' . $url . ']', $messages[ $index ]['content'] );
					$changed                       = true;
				}
			}
		}

		if ( ! $changed ) {
			return $conversation;
		}

		$updated = $conversation->with_messages( $messages );
		if ( is_wp_error( $updated ) ) {
			return $conversation;
		}

		return $updated;
	}

	/**
	 * Whether a candidate satisfies the canonical conversation class for
	 * this install mode.
	 *
	 * The discriminator is `defined( 'WP_MCP_AI_PATH' )` — never bare
	 * `instanceof` — because monolith installs flow the base conversation
	 * class (base adapters) while standalone installs flow this package's
	 * class. Both share a byte-identical method surface.
	 *
	 * @param object $candidate Candidate conversation instance.
	 * @return bool
	 */
	protected function is_conversation_instance( $candidate ): bool {
		if ( defined( 'WP_MCP_AI_PATH' ) ) {
			return $candidate instanceof \WP_MCP_AI_Conversation_Import_Conversation;
		}

		return $candidate instanceof Conversation;
	}

	/**
	 * Resolve a file ID to an attachment URL, sideloading when needed.
	 *
	 * @param string $file_id     Sanitized asset file ID (e.g. "file_abc123").
	 * @param string $payload_dir Directory containing the export files.
	 * @return string Attachment URL, or '' when unresolved.
	 */
	protected function resolve_image( $file_id, $payload_dir ) {
		if ( isset( $this->resolved[ $file_id ] ) ) {
			return $this->resolved[ $file_id ];
		}

		$source = $this->locate_file( $file_id, $payload_dir );
		if ( '' === $source ) {
			$this->resolved[ $file_id ] = '';

			return '';
		}

		$attachment_id = $this->sideload_file( $source, $file_id );
		if ( is_wp_error( $attachment_id ) ) {
			$this->resolved[ $file_id ] = '';

			return '';
		}

		$url = wp_get_attachment_url( $attachment_id );
		if ( ! is_string( $url ) || '' === $url ) {
			$this->resolved[ $file_id ] = '';

			return '';
		}

		++$this->count;
		$this->resolved[ $file_id ] = $url;

		return $url;
	}

	/**
	 * Locate a candidate image file for a file ID.
	 *
	 * ChatGPT exports ship images as `file_XXXX-sanitized.ext` (or without the
	 * `-sanitized` suffix) next to `conversations.json`.
	 *
	 * @param string $file_id     Sanitized asset file ID.
	 * @param string $payload_dir Directory containing the export files.
	 * @return string Absolute path, or '' when not found.
	 */
	protected function locate_file( $file_id, $payload_dir ) {
		$payload_dir = wp_normalize_path( (string) $payload_dir );

		$names = array(
			$file_id . '-sanitized',
			$file_id,
		);

		foreach ( $names as $name ) {
			foreach ( self::ALLOWED_EXTENSIONS as $extension ) {
				$candidate = $payload_dir . DIRECTORY_SEPARATOR . $name . '.' . $extension;
				if ( file_exists( $candidate ) && is_readable( $candidate ) ) {
					return $candidate;
				}
			}
		}

		return '';
	}

	/**
	 * Sideload a local file into the media library.
	 *
	 * @param string $source_path Absolute source path.
	 * @param string $file_id     Asset file ID (used for the attachment title).
	 * @return int|\WP_Error Attachment ID, or a WP_Error.
	 */
	protected function sideload_file( $source_path, $file_id ) {
		if ( ! function_exists( 'media_handle_sideload' ) ) {
			require_once ABSPATH . 'wp-admin/includes/file.php';
			require_once ABSPATH . 'wp-admin/includes/media.php';
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}

		$file = array(
			'name'     => $file_id . '.' . strtolower( (string) pathinfo( $source_path, PATHINFO_EXTENSION ) ),
			'tmp_name' => $source_path,
		);

		return media_handle_sideload( $file, 0, $file_id );
	}
}
