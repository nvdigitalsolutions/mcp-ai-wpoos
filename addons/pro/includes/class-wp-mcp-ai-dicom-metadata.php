<?php
/**
 * DICOM Metadata Extractor
 *
 * Parses key DICOM tags from a `.dcm` file using PHP's native binary
 * reading capabilities.  This is a lightweight, dependency-free parser
 * that handles the most common DICOM tags needed for study/series/instance
 * indexing without relying on Imebra or any external Composer package.
 *
 * Supported transfer syntaxes:
 *  - Explicit Little Endian (1.2.840.10008.1.2.1) – most common
 *  - Implicit Little Endian (1.2.840.10008.1.2)
 *  - Explicit Big Endian   (1.2.840.10008.1.2.2)
 *
 * Tags extracted:
 *  (0008,0016) SOPClassUID
 *  (0008,0018) SOPInstanceUID
 *  (0008,0020) StudyDate
 *  (0008,0030) StudyTime
 *  (0008,0060) Modality
 *  (0008,103E) SeriesDescription
 *  (0010,0010) PatientName
 *  (0010,0020) PatientID
 *  (0020,000D) StudyInstanceUID
 *  (0020,000E) SeriesInstanceUID
 *  (0020,0013) InstanceNumber
 *  (0028,0010) Rows
 *  (0028,0011) Columns
 *  (0028,0030) PixelSpacing
 *  (0054,0016) RadiopharmaceuticalInformationSequence (PET SUV metadata)
 *
 * @package WP_MCP_AI_Pro
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Lightweight DICOM tag reader (no external PHP libraries required).
 */
class WP_MCP_AI_DICOM_Metadata {

	/**
	 * Tags we want to extract, keyed as "GGGGEEEE" hex strings.
	 *
	 * @var array<string,string>
	 */
	const TAGS_OF_INTEREST = array(
		'00080016' => 'sop_class_uid',
		'00080018' => 'sop_instance_uid',
		'00080020' => 'study_date',
		'00080030' => 'study_time',
		'00080060' => 'modality',
		'0008103e' => 'series_description',
		'00100010' => 'patient_name',
		'00100020' => 'patient_id',
		'0020000d' => 'study_instance_uid',
		'0020000e' => 'series_instance_uid',
		'00200013' => 'instance_number',
		'00280010' => 'rows',
		'00280011' => 'columns',
		'00280030' => 'pixel_spacing',
		'00540016' => 'radiopharmaceutical_info',
	);

	/**
	 * DICOM preamble length (128 bytes) + "DICM" magic bytes.
	 *
	 * @var int
	 */
	const PREAMBLE_LENGTH = 132;

	/**
	 * Extract DICOM metadata from a file.
	 *
	 * @param string $file_path Absolute path to a .dcm file.
	 * @return array|WP_Error Associative array of metadata or WP_Error on failure.
	 */
	public static function extract( $file_path ) {
		if ( ! file_exists( $file_path ) || ! is_readable( $file_path ) ) {
			return new WP_Error( 'dicom_file_not_found', __( 'DICOM file not found or not readable.', 'mcp-ai-wpoos-pro' ) );
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $file_path, 'rb' );
		if ( false === $fh ) {
			return new WP_Error( 'dicom_open_failed', __( 'Failed to open DICOM file.', 'mcp-ai-wpoos-pro' ) );
		}

		// Verify DICOM magic.
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$preamble = fread( $fh, self::PREAMBLE_LENGTH );
		if ( false === $preamble || strlen( $preamble ) < self::PREAMBLE_LENGTH ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return new WP_Error( 'dicom_too_short', __( 'File is too short to be a valid DICOM.', 'mcp-ai-wpoos-pro' ) );
		}

		if ( 'DICM' !== substr( $preamble, 128, 4 ) ) {
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
			fclose( $fh );
			return new WP_Error( 'dicom_invalid_magic', __( 'File does not have a valid DICOM header (missing DICM magic bytes).', 'mcp-ai-wpoos-pro' ) );
		}

		$metadata         = array();
		$transfer_syntax  = '1.2.840.10008.1.2.1'; // Default: Explicit Little Endian.
		$explicit         = true;
		$big_endian       = false;
		$meta_group_ended = false;

		// phpcs:ignore Generic.CodeAnalysis.AssignmentInCondition.FoundInWhileCondition
		while ( ! feof( $fh ) ) {
			// Read group and element numbers (2 bytes each).
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$grp_raw = fread( $fh, 2 );
			// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
			$elm_raw = fread( $fh, 2 );

			if ( false === $grp_raw || false === $elm_raw || strlen( $grp_raw ) < 2 || strlen( $elm_raw ) < 2 ) {
				break;
			}

			$unpack_fmt = $big_endian ? 'n' : 'v';
			$grp        = current( unpack( $unpack_fmt, $grp_raw ) );
			$elm        = current( unpack( $unpack_fmt, $elm_raw ) );

			// Detect end of meta-information group (0002,xxxx).
			if ( ! $meta_group_ended && 0x0002 !== $grp ) {
				$meta_group_ended = true;
				// Switch to transfer syntax determined from (0002,0010).
				if ( '1.2.840.10008.1.2' === $transfer_syntax ) {
					$explicit = false; // Implicit Little Endian.
				} elseif ( '1.2.840.10008.1.2.2' === $transfer_syntax ) {
					$big_endian = true; // Explicit Big Endian.
				}
			}

			$tag_key = sprintf( '%04x%04x', $grp, $elm );

			// --- Value Representation & Length ---
			$vr     = '';
			$length = 0;

			if ( $explicit ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$vr_raw = fread( $fh, 2 );
				if ( false === $vr_raw || strlen( $vr_raw ) < 2 ) {
					break;
				}
				$vr = $vr_raw;

				if ( in_array( $vr, array( 'OB', 'OW', 'OF', 'SQ', 'UC', 'UR', 'UT', 'UN' ), true ) ) {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					fread( $fh, 2 ); // Reserved 2 bytes.
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$len_raw = fread( $fh, 4 );
					if ( false === $len_raw || strlen( $len_raw ) < 4 ) {
						break;
					}
					$length = current( unpack( $big_endian ? 'N' : 'V', $len_raw ) );
				} else {
					// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
					$len_raw = fread( $fh, 2 );
					if ( false === $len_raw || strlen( $len_raw ) < 2 ) {
						break;
					}
					$length = current( unpack( $big_endian ? 'n' : 'v', $len_raw ) );
				}
			} else {
				// Implicit: 4-byte length, no VR in file.
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$len_raw = fread( $fh, 4 );
				if ( false === $len_raw || strlen( $len_raw ) < 4 ) {
					break;
				}
				$length = current( unpack( 'V', $len_raw ) );
			}

			// Undefined length (0xFFFFFFFF) — skip this element for now.
			if ( 0xFFFFFFFF === $length ) {
				break;
			}

			// Read value.
			$value = '';
			if ( $length > 0 && $length < 65536 ) {
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
				$value = fread( $fh, $length );
			} elseif ( $length >= 65536 ) {
				// Skip large values (pixel data, etc.).
				// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fseek
				fseek( $fh, $length, SEEK_CUR );
				continue;
			}

			// Capture transfer syntax from meta group.
			if ( '00020010' === $tag_key ) {
				$transfer_syntax = trim( $value );
			}

			// Extract tags we care about.
			if ( isset( self::TAGS_OF_INTEREST[ $tag_key ] ) ) {
				$field_name               = self::TAGS_OF_INTEREST[ $tag_key ];
				$metadata[ $field_name ]  = self::sanitize_tag_value( $value, $vr );
			}
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );

		$metadata['transfer_syntax'] = trim( $transfer_syntax );
		$metadata['file_size']       = filesize( $file_path );

		return $metadata;
	}

	/**
	 * Sanitize a raw DICOM tag value for safe storage.
	 *
	 * @param string $value Raw bytes.
	 * @param string $vr    Value Representation (may be empty for implicit).
	 * @return string Clean UTF-8 string.
	 */
	private static function sanitize_tag_value( $value, $vr ) {
		// Trim DICOM padding characters (space 0x20 and null 0x00).
		$clean = rtrim( $value, " \0" );

		// For binary VRs, hex-encode instead of returning raw bytes.
		if ( in_array( $vr, array( 'OB', 'OW', 'OF', 'UN' ), true ) ) {
			return bin2hex( substr( $clean, 0, 64 ) ); // First 64 bytes max.
		}

		// Ensure valid UTF-8.
		if ( function_exists( 'mb_convert_encoding' ) ) {
			$clean = mb_convert_encoding( $clean, 'UTF-8', 'UTF-8' );
		}

		return sanitize_text_field( $clean );
	}

	/**
	 * Check whether a file appears to be a DICOM file.
	 *
	 * Reads only the first 132 bytes to verify the magic string.
	 *
	 * @param string $file_path Absolute path to file.
	 * @return bool
	 */
	public static function is_dicom( $file_path ) {
		if ( ! file_exists( $file_path ) ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fopen
		$fh = fopen( $file_path, 'rb' );
		if ( ! $fh ) {
			return false;
		}
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fread
		$header = fread( $fh, 132 );
		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_system_operations_fclose
		fclose( $fh );
		return strlen( $header ) >= 132 && 'DICM' === substr( $header, 128, 4 );
	}
}
