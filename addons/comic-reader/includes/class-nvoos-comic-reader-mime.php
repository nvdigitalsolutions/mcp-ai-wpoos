<?php
/**
 * NV oOS Comic Reader — MIME Type Registration
 *
 * Registers CBR, CBZ, CB7, and CBT archive formats as allowed upload
 * types in WordPress so users can upload comic files directly through
 * the Media Library and the addon's own REST upload endpoint.
 *
 * @package NV_oOS_Comic_Reader
 * @since   0.2.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * MIME type registration for comic archive formats.
 *
 * @since 0.2.0
 */
class NV_oOS_Comic_Reader_Mime {

	/**
	 * Comic file extensions and their corresponding MIME types.
	 *
	 * @var array<string, string>
	 */
	const COMIC_MIME_MAP = array(
		'cbr' => 'application/vnd.rar',
		'cbz' => 'application/zip',
		'cb7' => 'application/x-7z-compressed',
		'cbt' => 'application/x-tar',
	);

	/**
	 * Known magic-byte signatures for the supported archive formats.
	 *
	 * TAR is handled separately: it has no leading magic bytes and must be
	 * detected by the `ustar` marker at offset 257.
	 *
	 * @var string[]
	 */
	const ARCHIVE_SIGNATURES = array(
		"Rar!\x1A\x07\x00",     // RAR4 signature.
		"Rar!\x1A\x07\x01\x00", // RAR5 signature.
		"PK\x03\x04",           // ZIP local file header.
		"PK\x05\x06",           // ZIP empty archive (EOCD).
		"PK\x07\x08",           // ZIP spanned archive.
		"7z\xBC\xAF\x27\x1C",   // 7-Zip signature.
	);

	/**
	 * Register WordPress hooks.
	 *
	 * @return void
	 */
	public static function init() {
		add_filter( 'upload_mimes', array( __CLASS__, 'allow_comic_mimes' ) );
		add_filter( 'wp_check_filetype_and_ext', array( __CLASS__, 'fix_comic_filetype' ), 10, 5 );
	}

	/**
	 * Add comic archive MIME types to the allowed upload list.
	 *
	 * Mirrors the Algorave Sample Library pattern (allow_audio_mimes).
	 *
	 * @since 0.2.0
	 *
	 * @param array $mimes Existing allowed MIME types keyed by extension.
	 * @return array Modified MIME types.
	 */
	public static function allow_comic_mimes( $mimes ) {
		if ( ! is_array( $mimes ) ) {
			$mimes = array();
		}

		foreach ( self::COMIC_MIME_MAP as $ext => $mime ) {
			if ( ! isset( $mimes[ $ext ] ) ) {
				$mimes[ $ext ] = $mime;
			}
		}

		return $mimes;
	}

	/**
	 * Ensure WordPress correctly detects the MIME type of comic archives
	 * even when the server's finfo returns a generic type like
	 * application/octet-stream.
	 *
	 * @since 0.2.0
	 *
	 * @param array  $data      Filetype data.
	 * @param string $_file     Full path to the file (unused).
	 * @param string $filename  The name of the file.
	 * @param array  $_mimes    Allowed MIME types (unused).
	 * @param string $_real_mime Real MIME type from finfo (unused).
	 * @return array Modified filetype data.
	 */
	public static function fix_comic_filetype( $data, $_file, $filename, $_mimes, $_real_mime ) {
		$ext = strtolower( pathinfo( $filename, PATHINFO_EXTENSION ) );

		if ( ! isset( self::COMIC_MIME_MAP[ $ext ] ) ) {
			return $data;
		}

		// If WordPress couldn't determine the type or got a generic one,
		// override with our known mapping.
		if ( empty( $data['ext'] ) || empty( $data['type'] ) || 'application/octet-stream' === $data['type'] ) {
			$data['ext']  = $ext;
			$data['type'] = self::COMIC_MIME_MAP[ $ext ];
		}

		return $data;
	}

	/**
	 * Get the MIME type for a given comic file extension.
	 *
	 * @since 0.2.0
	 *
	 * @param string $ext File extension (without dot).
	 * @return string MIME type, or application/octet-stream if unknown.
	 */
	public static function get_mime_type( $ext ) {
		$ext = strtolower( $ext );
		return isset( self::COMIC_MIME_MAP[ $ext ] ) ? self::COMIC_MIME_MAP[ $ext ] : 'application/octet-stream';
	}

	/**
	 * Get the list of supported comic extensions.
	 *
	 * @since 0.2.0
	 *
	 * @return string[] List of extensions (without dots).
	 */
	public static function get_supported_extensions() {
		return array_keys( self::COMIC_MIME_MAP );
	}

	/**
	 * Validate that a file on disk is a real comic archive by inspecting its
	 * magic bytes (not just its extension).
	 *
	 * @since 0.2.1
	 *
	 * @param string $path Absolute path to the uploaded file.
	 * @return bool True when the file starts with a known archive signature
	 *              (or a TAR `ustar` marker), false otherwise.
	 */
	public static function has_valid_archive_signature( $path ) {
		if ( ! is_string( $path ) || '' === $path || ! is_readable( $path ) ) {
			return false;
		}

		$handle = fopen( $path, 'rb' ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		if ( ! $handle ) {
			return false;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$head = fread( $handle, 512 );
		fclose( $handle ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose

		if ( false === $head || '' === $head ) {
			return false;
		}

		// TAR: `ustar` magic lives at offset 257 of the 512-byte header block.
		if ( strlen( $head ) >= 262 && 'ustar' === substr( $head, 257, 5 ) ) {
			return true;
		}

		foreach ( self::ARCHIVE_SIGNATURES as $signature ) {
			if ( 0 === strncmp( $head, $signature, strlen( $signature ) ) ) {
				return true;
			}
		}

		return false;
	}
}
