<?php
/**
 * NV oOS Media Studio — Marketplace-Compliant Output Pipeline
 *
 * Phase 3 of the fashion photography enhancement plan. Turns raw AI outputs
 * into marketplace-ready assets:
 *  - Dimension profiles (amazon / woocommerce / social / web) with
 *    minimum-side enforcement and center-crop squares (Amazon main image:
 *    1600px+ square, JPEG, pure white RGB 255,255,255).
 *  - Format conversion via WP_Image_Editor (GD/Imagick) with a JPEG
 *    fallback when the target MIME is unsupported.
 *  - Provenance naming (`<base>-<profile>-<variant>.<ext>`, never IMG_xxxx)
 *    and derived-asset meta (`_nvoos_ai_derived_from`, `_nvoos_ai_output_profile`).
 *  - Optional auto alt text through the core `generate_image_alt_text` tool
 *    (filter seam `nvoos_media_studio_alt_text` for sidecars/tests).
 *
 * @package NV_oOS_Media_Studio
 * @since   0.4.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Output pipeline service.
 *
 * @since 0.4.0
 */
class NV_oOS_Media_Studio_Output_Pipeline {

	/**
	 * Derived-asset provenance meta keys.
	 *
	 * @var string
	 */
	const META_DERIVED_FROM = '_nvoos_ai_derived_from';
	const META_PROFILE      = '_nvoos_ai_output_profile';
	const META_UPSCALED     = '_nvoos_ai_upscaled';

	/**
	 * Upscaling beyond this factor is refused (quality ceiling).
	 *
	 * @var float
	 */
	const MAX_UPSCALE_FACTOR = 2.0;

	/**
	 * Dimension profiles.
	 *
	 * @var array
	 */
	const PROFILES = array(
		'amazon'      => array(
			'label'    => 'Amazon',
			'min_side' => 1600,
			'square'   => true,
			'format'   => 'image/jpeg',
			'white_bg' => true,
		),
		'woocommerce' => array(
			'label'    => 'WooCommerce',
			'min_side' => 800,
			'square'   => false,
			'format'   => 'image/webp',
			'white_bg' => false,
		),
		'social'      => array(
			'label'    => 'Social',
			'min_side' => 1080,
			'square'   => false,
			'format'   => 'image/webp',
			'white_bg' => false,
		),
		'web'         => array(
			'label'    => 'Web',
			'min_side' => 2000,
			'square'   => false,
			'format'   => 'image/webp',
			'white_bg' => false,
		),
	);

	/**
	 * The dimension profiles (REST/serializable shape).
	 *
	 * @return array
	 */
	public static function get_profiles() {
		$profiles = array();
		foreach ( self::PROFILES as $slug => $profile ) {
			$profiles[ $slug ] = array(
				'label'    => sanitize_text_field( $profile['label'] ),
				'min_side' => absint( $profile['min_side'] ),
				'square'   => (bool) $profile['square'],
				'format'   => sanitize_text_field( $profile['format'] ),
				'white_bg' => (bool) $profile['white_bg'],
			);
		}
		return $profiles;
	}

	/**
	 * Whether a profile slug is valid.
	 *
	 * @param string $profile Profile slug.
	 * @return bool
	 */
	public static function is_valid_profile( $profile ) {
		return isset( self::PROFILES[ sanitize_key( $profile ) ] );
	}

	/**
	 * Build a provenance-friendly derived file name (never IMG_xxxx).
	 *
	 * @param int    $attachment_id Source attachment ID.
	 * @param string $profile       Profile slug.
	 * @param int    $variant       Variant ordinal.
	 * @return string
	 */
	public static function build_file_name( $attachment_id, $profile, $variant = 0 ) {
		$source_path = get_attached_file( absint( $attachment_id ) );
		$base        = $source_path ? pathinfo( $source_path, PATHINFO_FILENAME ) : 'media-studio';
		$base        = sanitize_file_name( $base );
		$base        = preg_replace( '/^(img|image|photo|dsc)[-_]?\d+$/i', 'media-studio', $base );
		$variant     = max( 0, absint( $variant ) );

		return sprintf(
			'%s-%s-%d',
			$base,
			sanitize_key( $profile ),
			$variant
		);
	}

	/**
	 * Read the current dimensions and profile fit of an attachment.
	 *
	 * @param int    $attachment_id Attachment ID.
	 * @param string $profile       Profile slug.
	 * @return array|WP_Error Array{width:int,height:int,min_side:int,meets:bool,factor:float} or error.
	 */
	public static function validate_dimensions( $attachment_id, $profile ) {
		if ( ! self::is_valid_profile( $profile ) ) {
			return new WP_Error( 'nvoos_ms_invalid_profile', __( 'Unknown output profile.', 'nvoos-media-studio' ), array( 'status' => 400 ) );
		}

		$path = get_attached_file( absint( $attachment_id ) );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Image file not found.', 'nvoos-media-studio' ) );
		}

		$size = getimagesize( $path );
		if ( ! $size || empty( $size[0] ) || empty( $size[1] ) ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Unsupported image format.', 'nvoos-media-studio' ) );
		}

		$profile_def = self::PROFILES[ sanitize_key( $profile ) ];
		$width       = (int) $size[0];
		$height      = (int) $size[1];
		$min_side    = min( $width, $height );
		$factor      = (float) $profile_def['min_side'] / max( 1, $min_side );

		return array(
			'width'    => $width,
			'height'   => $height,
			'min_side' => $min_side,
			'meets'    => $min_side >= $profile_def['min_side'],
			'factor'   => $factor,
		);
	}

	/**
	 * Resize an attachment to a profile's dimensions.
	 *
	 * @param int    $attachment_id Source attachment ID.
	 * @param string $profile       Profile slug.
	 * @return array|WP_Error Array{file:string,temporary:bool,upscaled:bool,editor:?WP_Image_Editor} or error.
	 */
	public static function resize_to_profile( $attachment_id, $profile ) {
		$dimensions = self::validate_dimensions( $attachment_id, $profile );
		if ( is_wp_error( $dimensions ) ) {
			return $dimensions;
		}

		$profile_def = self::PROFILES[ sanitize_key( $profile ) ];
		$target      = (int) $profile_def['min_side'];
		$source_path = get_attached_file( $attachment_id );

		$upscaled     = false;
		$temporary    = false;
		$working_path = $source_path;

		if ( ! $dimensions['meets'] ) {
			if ( $dimensions['factor'] > self::MAX_UPSCALE_FACTOR ) {
				return new WP_Error(
					'nvoos_ms_insufficient_resolution',
					sprintf(
						/* translators: 1: profile label, 2: required pixels, 3: current pixels */
						__( 'Source resolution is too low for the %1$s profile (needs %2$dpx, has %3$dpx).', 'nvoos-media-studio' ),
						$profile_def['label'],
						$target,
						$dimensions['min_side']
					),
					array( 'status' => 409 )
				);
			}

			// WP 6.9 removed the image_resize_upscale filter, so bounded
			// upscaling is done with GD directly (sampled resize).
			$upscaled     = true;
			$new_w        = (int) round( $dimensions['width'] * $dimensions['factor'] );
			$new_h        = (int) round( $dimensions['height'] * $dimensions['factor'] );
			$working_path = self::gd_resize( $source_path, $new_w, $new_h );
			if ( is_wp_error( $working_path ) ) {
				return $working_path;
			}
			$temporary = true;
		}

		$editor = null;
		if ( $profile_def['square'] ) {
			$editor = wp_get_image_editor( $working_path );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
			$resized = $editor->resize( $target, $target, true );
			if ( is_wp_error( $resized ) ) {
				return $resized;
			}
		}

		return array(
			'file'      => $working_path,
			'temporary' => $temporary,
			'upscaled'  => $upscaled,
			'editor'    => $editor,
		);
	}

	/**
	 * Resample an image file with GD (used for bounded upscaling).
	 *
	 * @param string $path  Source file path.
	 * @param int    $new_w Target width.
	 * @param int    $new_h Target height.
	 * @return string|WP_Error New PNG file path or error.
	 */
	protected static function gd_resize( $path, $new_w, $new_h ) {
		if ( ! function_exists( 'imagecreatetruecolor' ) ) {
			return new WP_Error( 'nvoos_ms_gd_missing', __( 'GD image library is not available.', 'nvoos-media-studio' ) );
		}

		$mime = wp_check_filetype( $path );
		$mime = isset( $mime['type'] ) ? $mime['type'] : 'image/png';
		$img  = false;
		if ( 'image/jpeg' === $mime ) {
			$img = imagecreatefromjpeg( $path );
		} elseif ( 'image/png' === $mime ) {
			$img = imagecreatefrompng( $path );
		} elseif ( 'image/webp' === $mime && function_exists( 'imagecreatefromwebp' ) ) {
			$img = imagecreatefromwebp( $path );
		}
		if ( ! $img ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Unsupported image format.', 'nvoos-media-studio' ) );
		}

		$width  = imagesx( $img );
		$height = imagesy( $img );
		$dst    = imagecreatetruecolor( $new_w, $new_h );
		imagealphablending( $dst, false );
		imagesavealpha( $dst, true );
		imagecopyresampled( $dst, $img, 0, 0, 0, 0, $new_w, $new_h, $width, $height );
		imagedestroy( $img );

		$out = wp_tempnam() . '.png';
		imagepng( $dst, $out );
		imagedestroy( $dst );

		return $out;
	}

	/**
	 * Convert an image file to the target format.
	 *
	 * @param string          $path      Source file path.
	 * @param string          $target    Target MIME type.
	 * @param string          $dest_path Destination path (extension must match).
	 * @param WP_Image_Editor $editor Optional existing editor instance.
	 * @return array|WP_Error Array{path:string,mime_type:string} or error.
	 */
	public static function convert_format( $path, $target, $dest_path, $editor = null ) {
		if ( null === $editor ) {
			$editor = wp_get_image_editor( $path );
			if ( is_wp_error( $editor ) ) {
				return $editor;
			}
		}

		$fallback = array(
			'image/webp' => 'image/jpeg',
			'image/png'  => 'image/jpeg',
			'image/jpeg' => 'image/jpeg',
		);

		$attempts      = array( $target );
		$fallback_mime = isset( $fallback[ $target ] ) ? $fallback[ $target ] : 'image/jpeg';
		if ( $target !== $fallback_mime ) {
			$attempts[] = $fallback_mime;
		}

		foreach ( $attempts as $mime ) {
			if ( ! $editor::supports_mime_type( $mime ) ) {
				continue;
			}
			$ext   = 'image/jpeg' === $mime ? 'jpg' : substr( $mime, 6 );
			$file  = preg_replace( '/\.[a-z0-9]+$/i', '', $dest_path ) . '.' . $ext;
			$saved = $editor->save( $file, $mime );
			if ( ! is_wp_error( $saved ) && ! empty( $saved['path'] ) ) {
				return array(
					'path'      => $saved['path'],
					'mime_type' => isset( $saved['mime-type'] ) ? $saved['mime-type'] : $mime,
				);
			}
		}

		return new WP_Error( 'nvoos_ms_format_unsupported', __( 'No supported encoder for the target format.', 'nvoos-media-studio' ), array( 'status' => 415 ) );
	}

	/**
	 * Generate alt text for an attachment (AI-backed, graceful).
	 *
	 * @param int $attachment_id Attachment ID.
	 * @param int $user_id       Acting user ID.
	 * @return string Alt text or empty string.
	 */
	public static function generate_alt_text( $attachment_id, $user_id = 0 ) {
		$alt = apply_filters( 'nvoos_media_studio_alt_text', null, $attachment_id, $user_id );
		if ( null === $alt && class_exists( 'WP_MCP_AI_Tool_Registry' ) ) {
			$registry = WP_MCP_AI_Tool_Registry::get_instance();
			if ( null !== $registry->get_tool( 'generate_image_alt_text' ) ) {
				$result = $registry->execute_tool(
					'generate_image_alt_text',
					array( 'attachment_id' => absint( $attachment_id ) ),
					array( 'user_id' => $user_id )
				);
				if ( ! is_wp_error( $result ) && is_array( $result ) && ! empty( $result['alt_text'] ) ) {
					$alt = sanitize_text_field( $result['alt_text'] );
				}
			}
		}
		return is_string( $alt ) ? $alt : '';
	}

	/**
	 * Run the full output pipeline for one attachment + profile.
	 *
	 * @param int    $attachment_id Source attachment ID.
	 * @param string $profile       Profile slug.
	 * @param array  $args          Array{alt_text:bool,variant:int}.
	 * @param int    $user_id       Acting user ID.
	 * @return array|WP_Error Derived attachment payload or error.
	 */
	public static function process( $attachment_id, $profile, $args = array(), $user_id = 0 ) {
		$profile = sanitize_key( $profile );
		if ( ! self::is_valid_profile( $profile ) ) {
			return new WP_Error( 'nvoos_ms_invalid_profile', __( 'Unknown output profile.', 'nvoos-media-studio' ), array( 'status' => 400 ) );
		}

		$attachment_id = absint( $attachment_id );
		$attachment    = get_post( $attachment_id );
		if ( ! $attachment || 'attachment' !== $attachment->post_type ) {
			return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Source image not found.', 'nvoos-media-studio' ), array( 'status' => 404 ) );
		}

		$profile_def = self::PROFILES[ $profile ];
		$variant     = isset( $args['variant'] ) ? absint( $args['variant'] ) : 0;
		$user_id     = $user_id ? absint( $user_id ) : get_current_user_id();

		$resized = self::resize_to_profile( $attachment_id, $profile );
		if ( is_wp_error( $resized ) ) {
			return $resized;
		}

		$dir      = wp_upload_dir();
		$dest_dir = $dir['path'];
		if ( ! wp_mkdir_p( $dest_dir ) ) {
			return new WP_Error( 'nvoos_ms_export_failed', __( 'Could not create the upload directory.', 'nvoos-media-studio' ), array( 'status' => 500 ) );
		}

		$dest_base = self::build_file_name( $attachment_id, $profile, $variant );
		$converted = self::convert_format( $resized['file'], $profile_def['format'], $dest_dir . '/' . $dest_base, isset( $resized['editor'] ) && is_object( $resized['editor'] ) ? $resized['editor'] : null );

		// Intermediate resample files are temporary — never linger.
		if ( ! empty( $resized['temporary'] ) && get_attached_file( $attachment_id ) !== $resized['file'] ) {
			wp_delete_file( $resized['file'] );
		}

		if ( is_wp_error( $converted ) ) {
			return $converted;
		}

		$derived_id = wp_insert_attachment(
			array(
				'post_mime_type' => $converted['mime_type'],
				'post_title'     => sprintf(
					/* translators: 1: source title, 2: profile label */
					__( '%1$s — %2$s', 'nvoos-media-studio' ),
					get_the_title( $attachment_id ),
					$profile_def['label']
				),
				'post_status'    => 'inherit',
			),
			$converted['path']
		);
		if ( is_wp_error( $derived_id ) || 0 === $derived_id ) {
			return new WP_Error( 'nvoos_ms_export_failed', __( 'Could not create the derived attachment.', 'nvoos-media-studio' ), array( 'status' => 500 ) );
		}

		if ( ! function_exists( 'wp_generate_attachment_metadata' ) ) {
			require_once ABSPATH . 'wp-admin/includes/image.php';
		}
		wp_update_attachment_metadata( $derived_id, wp_generate_attachment_metadata( $derived_id, $converted['path'] ) );

		update_post_meta( $derived_id, self::META_DERIVED_FROM, $attachment_id );
		update_post_meta( $derived_id, self::META_PROFILE, $profile );
		if ( $resized['upscaled'] ) {
			update_post_meta( $derived_id, self::META_UPSCALED, 1 );
		}

		// Alt text: opt-out per call; source alt text carries over otherwise.
		$alt_text = '';
		if ( ! array_key_exists( 'alt_text', $args ) || ! empty( $args['alt_text'] ) ) {
			$alt_text = self::generate_alt_text( $derived_id, $user_id );
		}
		if ( '' === $alt_text ) {
			$alt_text = sanitize_text_field( (string) get_post_meta( $attachment_id, '_wp_attachment_image_alt', true ) );
		}
		if ( '' !== $alt_text ) {
			update_post_meta( $derived_id, '_wp_attachment_image_alt', $alt_text );
		}

		// Amazon main-image rule: white background validated on the output.
		$white_check = null;
		if ( ! empty( $profile_def['white_bg'] ) && class_exists( 'NV_oOS_Media_Studio_AI_Service' ) ) {
			$white_check = NV_oOS_Media_Studio_AI_Service::validate_white_background( $derived_id );
			if ( is_wp_error( $white_check ) ) {
				$white_check = null;
			}
		}

		// Phase 4: derived-asset provenance (XMP after all pixel work).
		$provenance = array(
			'xmp_embedded' => false,
			'c2pa_signed'  => false,
		);
		if ( class_exists( 'NV_oOS_Media_Studio_Provenance' ) ) {
			$provenance = NV_oOS_Media_Studio_Provenance::record_derived( $derived_id, $user_id );
		}

		return array(
			'attachment_id'    => $derived_id,
			'url'              => esc_url_raw( wp_get_attachment_url( $derived_id ) ),
			'mime_type'        => sanitize_text_field( $converted['mime_type'] ),
			'profile'          => $profile,
			'source_id'        => $attachment_id,
			'upscaled'         => (bool) $resized['upscaled'],
			'alt_text'         => $alt_text,
			'xmp_embedded'     => ! empty( $provenance['xmp_embedded'] ),
			'c2pa_signed'      => ! empty( $provenance['c2pa_signed'] ),
			'white_background' => $white_check,
		);
	}
}
