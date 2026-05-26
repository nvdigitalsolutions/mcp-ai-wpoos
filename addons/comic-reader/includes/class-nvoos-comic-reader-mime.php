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
	 * @param array  $data     Filetype data.
	 * @param string $file     Full path to the file.
	 * @param string $filename The name of the file.
	 * @param array  $mimes    Allowed MIME types.
	 * @param string $real_mime Real MIME type from finfo.
	 * @return array Modified filetype data.
	 */
	public static function fix_comic_filetype( $data, $file, $filename, $mimes, $real_mime ) {
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
}
