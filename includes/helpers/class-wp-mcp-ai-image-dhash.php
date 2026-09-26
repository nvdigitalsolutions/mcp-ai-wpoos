<?php
/**
 * Perceptual (difference) hashing for images using GD.
 *
 * A pure-PHP 64-bit dHash implementation — no external binaries, no npm
 * packages, no sidecar. Used by the media-library lookup rung of the
 * non-LLM image identification ladder to answer "have we seen this image
 * before?" without sending pixels anywhere.
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

/**
 * Computes and compares 64-bit difference hashes (dHash) of images.
 *
 * DHashing downscales an image to 9×8 grayscale and records whether each
 * pixel is brighter than its right neighbour, producing a compact fingerprint
 * that survives resizing and minor recompression. Identical images compare
 * at distance 0; the distance between unrelated images approaches 32 on
 * average. The helper is deterministic for a given file.
 *
 * @since 1.1.87
 */
class WP_MCP_AI_Image_DHash {

	/**
	 * Post-meta key caching a computed hash on an attachment.
	 */
	const HASH_META_KEY = '_wp_mcp_ai_image_dhash';

	/**
	 * Expected hex length of a computed hash (64 bits).
	 */
	const HASH_HEX_LENGTH = 16;

	/**
	 * Compute a 64-bit difference hash for an image file.
	 *
	 * @param string $file_path Absolute path to the image file.
	 * @return string|WP_Error 16-char hex hash, or WP_Error.
	 */
	public static function compute( $file_path ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) || ! function_exists( 'imagecreatefromstring' ) ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_gd_missing',
				__( 'The GD image extension is required for image hashing but is not available on this server.', 'mcp-ai-wpoos' ),
				array( 'status' => 501 )
			);
		}

		if ( ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_file_missing',
				sprintf(
					/* translators: %s: file path */
					__( 'Image file not found: %s', 'mcp-ai-wpoos' ),
					$file_path
				),
				array( 'status' => 404 )
			);
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents,WordPress.PHP.NoSilencedErrors.Discouraged -- Reading a local attachment for hashing; WP_Filesystem is not available in this non-admin context, and GD-safe failure handling follows.
		$data = @file_get_contents( $file_path );
		if ( false === $data ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_unreadable',
				__( 'The image file could not be read for hashing.', 'mcp-ai-wpoos' ),
				array( 'status' => 500 )
			);
		}

		// phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- GD emits a warning on invalid image data; the false return is handled below.
		$img = @imagecreatefromstring( $data );
		if ( false === $img ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_invalid_image',
				__( 'The file is not a valid image format supported by GD.', 'mcp-ai-wpoos' ),
				array( 'status' => 415 )
			);
		}

		// Downscale to 9×8 so every image produces the same-sized fingerprint.
		$width  = imagesx( $img );
		$height = imagesy( $img );

		$small = imagecreatetruecolor( 9, 8 );
		imagecopyresampled( $small, $img, 0, 0, 0, 0, 9, 8, $width, $height );
		imagefilter( $small, IMG_FILTER_GRAYSCALE );
		imagedestroy( $img );

		$bits = '';

		for ( $y = 0; $y < 8; $y++ ) {
			for ( $x = 0; $x < 8; $x++ ) {
				$left  = imagecolorat( $small, $x, $y ) >> 16 & 0xFF;
				$right = imagecolorat( $small, $x + 1, $y ) >> 16 & 0xFF;

				$bits .= ( $left > $right ) ? '1' : '0';
			}
		}

		imagedestroy( $small );

		return self::bits_to_hex( $bits );
	}

	/**
	 * Compute the Hamming distance between two hashes.
	 *
	 * @param string $hash_a First 16-char hex hash.
	 * @param string $hash_b Second 16-char hex hash.
	 * @return int|WP_Error Distance in bits (0–64), or WP_Error for malformed input.
	 */
	public static function distance( $hash_a, $hash_b ) {
		if ( ! self::is_valid_hash( $hash_a ) || ! self::is_valid_hash( $hash_b ) ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_invalid_hash',
				__( 'Hashes must be 16-character hexadecimal strings.', 'mcp-ai-wpoos' ),
				array( 'status' => 400 )
			);
		}

		$distance = 0;
		$bits_a   = self::hex_to_bits( $hash_a );
		$bits_b   = self::hex_to_bits( $hash_b );

		for ( $i = 0; $i < 64; $i++ ) {
			if ( $bits_a[ $i ] !== $bits_b[ $i ] ) {
				++$distance;
			}
		}

		return $distance;
	}

	/**
	 * Resolve a cached (or newly computed) hash for a WordPress attachment.
	 *
	 * Computes and caches the hash in post meta on first use.
	 *
	 * @param int $attachment_id Attachment ID.
	 * @return string|WP_Error 16-char hex hash, or WP_Error.
	 */
	public static function get_attachment_hash( $attachment_id ) {
		$attachment_id = absint( $attachment_id );

		if ( $attachment_id <= 0 || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_invalid_attachment',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'Attachment ID %d does not exist or is not an attachment.', 'mcp-ai-wpoos' ),
					$attachment_id
				),
				array( 'status' => 404 )
			);
		}

		$cached = get_post_meta( $attachment_id, self::HASH_META_KEY, true );

		if ( is_string( $cached ) && self::is_valid_hash( $cached ) ) {
			return strtolower( $cached );
		}

		$file_path = get_attached_file( $attachment_id );

		if ( ! $file_path || ! file_exists( $file_path ) ) {
			return new WP_Error(
				'wp_mcp_ai_dhash_file_missing',
				sprintf(
					/* translators: %d: attachment ID */
					__( 'No local file found for attachment ID %d.', 'mcp-ai-wpoos' ),
					$attachment_id
				),
				array( 'status' => 404 )
			);
		}

		$hash = self::compute( $file_path );

		if ( is_wp_error( $hash ) ) {
			return $hash;
		}

		update_post_meta( $attachment_id, self::HASH_META_KEY, $hash );

		return $hash;
	}

	/**
	 * Check whether a string is a valid 16-char hex hash.
	 *
	 * @param mixed $hash Candidate hash.
	 * @return bool
	 */
	public static function is_valid_hash( $hash ) {
		return is_string( $hash ) && 1 === preg_match( '/^[0-9a-fA-F]{16}$/', $hash );
	}

	/**
	 * Pack a 64-character bit string into 16 hex chars.
	 *
	 * @param string $bits Binary string of exactly 64 chars.
	 * @return string 16-char hex hash.
	 */
	private static function bits_to_hex( $bits ) {
		$hex = '';

		for ( $i = 0; $i < 64; $i += 4 ) {
			$hex .= dechex( (int) bindec( substr( $bits, $i, 4 ) ) );
		}

		return $hex;
	}

	/**
	 * Expand a 16-char hex hash into a 64-character bit string.
	 *
	 * @param string $hash 16-char hex hash.
	 * @return string Binary string of 64 chars.
	 */
	private static function hex_to_bits( $hash ) {
		$bits = '';

		for ( $i = 0; $i < self::HASH_HEX_LENGTH; $i++ ) {
			$bits .= str_pad( decbin( hexdec( $hash[ $i ] ) ), 4, '0', STR_PAD_LEFT );
		}

		return $bits;
	}
}
