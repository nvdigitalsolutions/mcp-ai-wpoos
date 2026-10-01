<?php
/**
 * NV oOS Media Studio — XMP Writer (IPTC 2025.1 AI fields)
 *
 * Phase 4 of the fashion photography enhancement plan. Embeds machine-readable
 * AI provenance into image files per the IPTC Photo Metadata Standard 2025.1:
 *  - Iptc4xmpExt:DigitalSourceType (trainedAlgorithmicMedia / compositeSynthetic)
 *  - Iptc4xmpExt:AISystemUsed / AISystemVersionUsed
 *  - Iptc4xmpExt:AIPromptInformation (optional, privacy-aware)
 *  - Iptc4xmpExt:AIPromptWriterName
 * plus photoshop:Credit / photoshop:Source describing the generation.
 *
 * Embedding targets:
 *  - JPEG  — APP1 segment ("http://ns.adobe.com/xap/1.0/")
 *  - PNG   — iTXt chunk (keyword "XML:com.adobe.xmp")
 *  - WebP  — "XMP " RIFF chunk (VP8X chunk inserted when absent)
 *
 * C2PA note: writing XMP invalidates any existing C2PA signature, so a signing
 * step (when configured) must run AFTER this writer (see the Provenance class).
 *
 * @package NV_oOS_Media_Studio
 * @since   0.5.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * XMP packet writer.
 *
 * @since 0.5.0
 */
class NV_oOS_Media_Studio_XMP_Writer {

	/**
	 * XMP namespace URIs.
	 *
	 * @var string
	 */
	const NS_IPTC4XMPEXT = 'http://iptc.org/std/Iptc4xmpExt/2008-02-29/';
	const NS_PHOTOSHOP   = 'http://ns.adobe.com/photoshop/1.0/';
	const NS_XMP_RIGHTS  = 'http://ns.adobe.com/xap/1.0/rights/';
	const NS_DC          = 'http://purl.org/dc/elements/1.1/';

	/**
	 * DigitalSourceType vocabulary terms.
	 *
	 * @var string
	 */
	const DST_TRAINED_ALGORITHMIC = 'http://cv.iptc.org/newscodes/digitalsourcetype/trainedAlgorithmicMedia';
	const DST_COMPOSITE_SYNTHETIC = 'http://cv.iptc.org/newscodes/digitalsourcetype/compositeSynthetic';

	/**
	 * XMP identifier strings per container format.
	 *
	 * @var string
	 */
	const XMP_NS_IDENTIFIER = 'http://ns.adobe.com/xap/1.0/';
	const PNG_XMP_KEYWORD   = 'XML:com.adobe.xmp';
	const WEBP_XMP_FOURCC   = 'XMP ';

	/**
	 * Whether the writer can embed into a given MIME type.
	 *
	 * @param string $mime MIME type.
	 * @return bool
	 */
	public static function supports_mime( $mime ) {
		return in_array( $mime, array( 'image/jpeg', 'image/png', 'image/webp' ), true );
	}

	/**
	 * Escape a value for XML attribute interpolation.
	 *
	 * @param string $value Raw value.
	 * @return string
	 */
	public static function escape( $value ) {
		return htmlspecialchars( (string) $value, ENT_QUOTES | ENT_XML1, 'UTF-8' );
	}

	/**
	 * Build a full XMP packet from a fields array.
	 *
	 * @param array $fields Field name => value (without namespace prefixes).
	 * @return string
	 */
	public static function build_packet( $fields ) {
		$attributes = array(
			'rdf:about'         => '',
			'xmlns:Iptc4xmpExt' => self::NS_IPTC4XMPEXT,
			'xmlns:photoshop'   => self::NS_PHOTOSHOP,
			'xmlns:xmpRights'   => self::NS_XMP_RIGHTS,
			'xmlns:dc'          => self::NS_DC,
		);

		foreach ( $fields as $name => $value ) {
			if ( '' === $name || null === $value || '' === (string) $value ) {
				continue;
			}
			// Allow callers to pass fully-qualified names (Iptc4xmpExt:AISystemUsed)
			// or bare names (AISystemUsed → Iptc4xmpExt:AISystemUsed).
			if ( false === strpos( $name, ':' ) ) {
				$name = 'Iptc4xmpExt:' . $name;
			}
			$attributes[ $name ] = $value;
		}

		$parts = array();
		foreach ( $attributes as $name => $value ) {
			$parts[] = $name . '="' . self::escape( $value ) . '"';
		}

		$packet  = '<?xpacket begin="' . "\xEF\xBB\xBF" . '" id="W5M0MpCehiHzreSzNTczkc9d"?>' . "\n";
		$packet .= '<x:xmpmeta xmlns:x="adobe:ns:meta/" x:xmptk="NV oOS Media Studio">' . "\n";
		$packet .= '  <rdf:RDF xmlns:rdf="http://www.w3.org/1999/02/22-rdf-syntax-ns#">' . "\n";
		$packet .= '    <rdf:Description ' . implode( ' ', $parts ) . '/>' . "\n";
		$packet .= '  </rdf:RDF>' . "\n";
		$packet .= '</x:xmpmeta>' . "\n";
		$packet .= '<?xpacket end="w"?>';

		/**
		 * Filter the generated XMP packet (custom extensions, privacy stripping).
		 *
		 * @param string $packet XMP packet.
		 * @param array  $fields Fields array.
		 */
		return apply_filters( 'nvoos_media_studio_xmp_packet', $packet, $fields );
	}

	/**
	 * Embed an XMP packet into an attachment's file.
	 *
	 * @param int   $attachment_id Attachment ID.
	 * @param array $fields        Field name => value.
	 * @return bool|WP_Error True on success.
	 */
	public static function embed( $attachment_id, $fields ) {
		$attachment_id = absint( $attachment_id );
		$path          = get_attached_file( $attachment_id );
		if ( ! $path || ! file_exists( $path ) ) {
			return new WP_Error( 'nvoos_ms_attachment_not_found', __( 'Image file not found.', 'nvoos-media-studio' ) );
		}

		$mime   = get_post_mime_type( $attachment_id );
		$packet = self::build_packet( $fields );

		if ( 'image/jpeg' === $mime ) {
			$result = self::embed_jpeg( $path, $packet );
		} elseif ( 'image/png' === $mime ) {
			$result = self::embed_png( $path, $packet );
		} elseif ( 'image/webp' === $mime ) {
			$result = self::embed_webp( $path, $packet );
		} else {
			return new WP_Error( 'nvoos_ms_xmp_unsupported', __( 'XMP embedding is not supported for this image format.', 'nvoos-media-studio' ) );
		}

		if ( is_wp_error( $result ) ) {
			return $result;
		}
		if ( ! $result ) {
			return new WP_Error( 'nvoos_ms_xmp_write_failed', __( 'Could not write XMP metadata into the image file.', 'nvoos-media-studio' ) );
		}

		// Refresh the recorded filesize (dimensions unchanged).
		$metadata = wp_get_attachment_metadata( $attachment_id );
		if ( is_array( $metadata ) ) {
			$metadata['filesize'] = (int) filesize( $path );
			wp_update_attachment_metadata( $attachment_id, $metadata );
		}

		return true;
	}

	/**
	 * Embed an XMP packet into a JPEG file (APP1 segment).
	 *
	 * @param string $path   File path.
	 * @param string $packet XMP packet.
	 * @return bool|WP_Error
	 */
	protected static function embed_jpeg( $path, $packet ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local binary read.
		$data = file_get_contents( $path );
		if ( false === $data || strlen( $data ) < 4 || "\xFF\xD8" !== substr( $data, 0, 2 ) ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Invalid JPEG file.', 'nvoos-media-studio' ) );
		}

		$position = 2;
		$segments = array();
		$length   = strlen( $data );

		while ( $position + 4 <= $length ) {
			if ( "\xFF" !== $data[ $position ] ) {
				break; // Entropy-coded data.
			}
			$marker = $data[ $position + 1 ];
			// Standalone markers (no length).
			if ( in_array( ord( $marker ), array( 0x01, 0xD0, 0xD1, 0xD2, 0xD3, 0xD4, 0xD5, 0xD6, 0xD7, 0xD8, 0xD9 ), true ) ) {
				$position += 2;
				continue;
			}
			if ( 0xDA === ord( $marker ) ) {
				break; // SOS — image data follows.
			}
			$seg_length = unpack( 'n', substr( $data, $position + 2, 2 ) );
			$seg_length = $seg_length[1];
			if ( $seg_length < 2 ) {
				return new WP_Error( 'nvoos_ms_invalid_image', __( 'Corrupt JPEG segment table.', 'nvoos-media-studio' ) );
			}
			$segments[] = array( $position, $seg_length );
			$position  += 2 + $seg_length;
		}

		$xmp_segment = "\xFF\xE1" . pack( 'n', strlen( self::XMP_NS_IDENTIFIER ) + strlen( $packet ) + 3 ) . self::XMP_NS_IDENTIFIER . "\0" . $packet;
		if ( 1 === strlen( $xmp_segment ) % 2 ) {
			$xmp_segment .= "\0"; // APP segments are word-aligned.
		}

		$out = substr( $data, 0, 2 );
		foreach ( $segments as $segment ) {
			$existing = substr( $data, $segment[0], 2 + $segment[1] );
			// Drop any pre-existing XMP segment (idempotent embedding).
			if ( "\xFF\xE1" === substr( $existing, 0, 2 ) && 0 === strpos( substr( $existing, 4 ), self::XMP_NS_IDENTIFIER ) ) {
				continue;
			}
			$out .= $existing;
		}
		$out .= $xmp_segment . substr( $data, $position );

		return false !== file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local binary write.
	}

	/**
	 * Embed an XMP packet into a PNG file (iTXt chunk).
	 *
	 * @param string $path   File path.
	 * @param string $packet XMP packet.
	 * @return bool|WP_Error
	 */
	protected static function embed_png( $path, $packet ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local binary read.
		$data = file_get_contents( $path );
		if ( false === $data || strlen( $data ) < 8 || "\x89PNG\r\n\x1A\n" !== substr( $data, 0, 8 ) ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Invalid PNG file.', 'nvoos-media-studio' ) );
		}

		$position = 8;
		$length   = strlen( $data );
		$ihdr_end = false;

		while ( $position + 12 <= $length ) {
			$chunk_length = unpack( 'N', substr( $data, $position, 4 ) );
			$chunk_length = $chunk_length[1];
			$type         = substr( $data, $position + 4, 4 );
			if ( 'IEND' === $type ) {
				break;
			}
			if ( 'IHDR' === $type ) {
				$ihdr_end = $position + 12 + $chunk_length;
				break;
			}
			$position += 12 + $chunk_length;
		}

		if ( false === $ihdr_end ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'PNG IHDR chunk not found.', 'nvoos-media-studio' ) );
		}

		$text  = self::PNG_XMP_KEYWORD . "\0\0\0\0\0" . $packet; // keyword, compression 0, method 0, empty language + translated keyword.
		$type  = 'iTXt';
		$chunk = pack( 'N', strlen( $text ) ) . $type . $text;
		$crc   = pack( 'N', crc32( $type . $text ) );
		$out   = substr( $data, 0, $ihdr_end ) . $chunk . $crc . substr( $data, $ihdr_end );

		return false !== file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local binary write.
	}

	/**
	 * Embed an XMP packet into a WebP file (RIFF "XMP " chunk).
	 *
	 * Inserts a VP8X chunk when absent so readers honor the XMP flag bit.
	 *
	 * @param string $path   File path.
	 * @param string $packet XMP packet.
	 * @return bool|WP_Error
	 */
	protected static function embed_webp( $path, $packet ) {
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- local binary read.
		$data = file_get_contents( $path );
		if ( false === $data || strlen( $data ) < 12 || 'RIFF' !== substr( $data, 0, 4 ) || 'WEBP' !== substr( $data, 8, 4 ) ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Invalid WebP file.', 'nvoos-media-studio' ) );
		}

		$position = 12;
		$length   = strlen( $data );
		$vp8x     = false;
		$vp8x_pos = 0;

		while ( $position + 8 <= $length ) {
			$fourcc = substr( $data, $position, 4 );
			$size   = unpack( 'V', substr( $data, $position + 4, 4 ) );
			$size   = $size[1];
			if ( 'VP8X' === $fourcc ) {
				$vp8x     = true;
				$vp8x_pos = $position;
				break;
			}
			$position += 8 + $size + ( $size % 2 );
		}

		$xmp_payload = $packet;
		if ( 1 === strlen( $xmp_payload ) % 2 ) {
			$xmp_payload .= "\0"; // RIFF chunks are word-aligned.
		}
		$xmp_chunk = self::WEBP_XMP_FOURCC . pack( 'V', strlen( $xmp_payload ) ) . $xmp_payload;

		if ( $vp8x ) {
			// Flip the XMP flag bit in the existing VP8X header.
			$flags                 = ord( $data[ $vp8x_pos + 8 ] ) | 0x10;
			$data[ $vp8x_pos + 8 ] = chr( $flags );
			$out                   = substr( $data, 0, $vp8x_pos + 18 ) . $xmp_chunk . substr( $data, $vp8x_pos + 18 );
		} else {
			$vp8x_chunk = self::build_vp8x_chunk( $data );
			if ( is_wp_error( $vp8x_chunk ) ) {
				return $vp8x_chunk;
			}
			$out = substr( $data, 0, 12 ) . $vp8x_chunk . $xmp_chunk . substr( $data, 12 );
		}

		// Rewrite the RIFF size field.
		$out = substr( $out, 0, 4 ) . pack( 'V', strlen( $out ) - 8 ) . substr( $out, 8 );

		return false !== file_put_contents( $path, $out ); // phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_file_put_contents -- local binary write.
	}

	/**
	 * Build a VP8X chunk (10-byte payload) with canvas dimensions parsed from
	 * the VP8 / VP8L bitstream.
	 *
	 * @param string $data Full WebP file data.
	 * @return string|WP_Error VP8X chunk bytes or error.
	 */
	protected static function build_vp8x_chunk( $data ) {
		$position = 12;
		$length   = strlen( $data );
		$width    = 0;
		$height   = 0;

		while ( $position + 8 <= $length ) {
			$fourcc        = substr( $data, $position, 4 );
			$size          = unpack( 'V', substr( $data, $position + 4, 4 ) );
			$size          = $size[1];
			$payload_start = $position + 8;

			if ( 'VP8 ' === $fourcc && $size >= 10 ) {
				// Lossy VP8: 3-byte frame tag, then (keyframes) the 3-byte sync
				// code 9D 01 2A, then width/height (14 bits each).
				$offset = 3;
				if ( substr( $data, $payload_start + 3, 3 ) === "\x9D\x01\x2A" ) {
					$offset = 6;
				}
				$frame  = substr( $data, $payload_start + $offset, 4 );
				$dims   = unpack( 'v2', $frame );
				$width  = $dims[1] & 0x3FFF;
				$height = $dims[2] & 0x3FFF;
				break;
			}
			if ( 'VP8L' === $fourcc && $size >= 5 ) {
				$bits   = unpack( 'V', substr( $data, $payload_start + 1, 4 ) );
				$bits   = $bits[1];
				$width  = ( $bits & 0x3FFF ) + 1;
				$height = ( ( $bits >> 14 ) & 0x3FFF ) + 1;
				break;
			}

			$position += 8 + $size + ( $size % 2 );
		}

		if ( $width < 1 || $height < 1 ) {
			return new WP_Error( 'nvoos_ms_invalid_image', __( 'Could not parse WebP canvas dimensions.', 'nvoos-media-studio' ) );
		}

		$payload  = chr( 0x10 );             // Flags: XMP present.
		$payload .= "\0\0\0";              // Reserved.
		$payload .= substr( pack( 'V', $width - 1 ), 0, 3 );  // Canvas width-1 (24-bit LE).
		$payload .= substr( pack( 'V', $height - 1 ), 0, 3 ); // Canvas height-1 (24-bit LE).

		return 'VP8X' . pack( 'V', strlen( $payload ) ) . $payload;
	}
}
