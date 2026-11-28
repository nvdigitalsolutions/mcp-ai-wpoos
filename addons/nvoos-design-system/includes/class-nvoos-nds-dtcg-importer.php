<?php
/**
 * NV oOS Design System — DTCG Importer.
 *
 * Imports a W3C Design Tokens Community Group (2025.10) document into the
 * flat NDS token registry. The importer is intentionally strict:
 *
 * - a document must be a JSON object of token groups;
 * - every token must declare (or inherit) a `$type`;
 * - a `$type` must be compatible with the registry token it targets;
 * - aliases are resolved (with cycle detection) and preserved as metadata;
 * - `$extends` groups are deep-merged before token extraction;
 * - the import is all-or-nothing: any error means no value is written.
 *
 * Legacy string values (the pre-1.0 exporter shape) are accepted alongside
 * spec-shaped objects, so existing user exports keep importing.
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 * @since   1.0.0 Codec-backed, alias-aware, spec-shaped.
 *
 * @see https://www.designtokens.org/tr/2025.10/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a DTCG JSON document into the token registry.
 *
 * @since 0.2.0
 */
class NV_oOS_Design_System_DTCG_Importer {

	/**
	 * Maximum accepted payload size in bytes (256 KB).
	 *
	 * @var int
	 */
	const MAX_PAYLOAD_BYTES = 262144;

	/**
	 * Token registry instance.
	 *
	 * @var NV_oOS_Design_System_Token_Registry
	 */
	private $registry;

	/**
	 * Map of DTCG `$type` values to the NDS token types they may import into.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static $allowed_types = array(
		'color'      => array( 'color' ),
		'dimension'  => array( 'size', 'spacing' ),
		'fontFamily' => array( 'font' ),
		'fontWeight' => array( 'font', 'size' ),
		'number'     => array( 'size' ),
		'duration'   => array( 'transition', 'size' ),
		'shadow'     => array( 'shadow' ),
		'string'     => array( '*' ),
	);

	/**
	 * Constructor.
	 *
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry.
	 */
	public function __construct( $registry ) {
		$this->registry = $registry;
	}

	/**
	 * Parse and validate a DTCG JSON document without applying anything.
	 *
	 * @param string                              $json     Raw JSON document.
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry used to resolve token ids.
	 * @return array{
	 *     imported: array<int, string>,
	 *     skipped: array<int, array{id: string, reason: string}>,
	 *     errors: array<int, string>,
	 *     warnings: array<int, string>
	 * }
	 */
	public static function prepare( $json, $registry ) {
		$analysis = self::analyze( $json, $registry );
		return $analysis['report'];
	}

	/**
	 * Parse, validate, and apply a DTCG JSON document.
	 *
	 * All-or-nothing: when the report carries any error, no token value is
	 * touched.
	 *
	 * @param string $json Raw JSON document.
	 * @return array The prepare() report plus an `applied` flag.
	 */
	public function import( $json ) {
		$analysis = self::analyze( $json, $this->registry );
		$report   = $analysis['report'];

		$report['applied'] = false;
		if ( empty( $report['errors'] ) && ! empty( $analysis['values'] ) ) {
			foreach ( $analysis['values'] as $id => $value ) {
				$this->registry->set( $id, $value );
			}
			$this->registry->save();

			foreach ( $analysis['meta'] as $id => $meta ) {
				$this->registry->set_meta( $id, $meta );
			}
			$this->registry->save_meta();

			$report['applied'] = true;
		}

		return $report;
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	/**
	 * Parse and validate a DTCG JSON document, returning the report plus the
	 * validated id => value and id => meta maps.
	 *
	 * @param string                              $json     Raw JSON document.
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry.
	 * @return array{
	 *     report: array{
	 *         imported: array<int, string>,
	 *         skipped: array<int, array{id: string, reason: string}>,
	 *         errors: array<int, string>,
	 *         warnings: array<int, string>
	 *     },
	 *     values: array<string, string>,
	 *     meta: array<string, array<string, mixed>>
	 * }
	 */
	private static function analyze( $json, $registry ) {
		$report = array(
			'imported' => array(),
			'skipped'  => array(),
			'errors'   => array(),
			'warnings' => array(),
		);
		$values = array();
		$meta   = array();

		if ( ! is_string( $json ) ) {
			$report['errors'][] = 'Import payload must be a JSON string.';
			return self::analysis_result( $report, $values, $meta );
		}

		if ( strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			$report['errors'][] = 'Payload exceeds the 256 KB size limit.';
			return self::analysis_result( $report, $values, $meta );
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$report['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
			return self::analysis_result( $report, $values, $meta );
		}

		if ( ! is_array( $decoded ) || self::is_json_list( $decoded ) ) {
			$report['errors'][] = 'Top level of the document must be a JSON object of token groups.';
			return self::analysis_result( $report, $values, $meta );
		}

		$resolved = NV_oOS_Design_System_DTCG_Codec::resolve_document( $decoded );
		foreach ( $resolved['errors'] as $error ) {
			$report['errors'][] = $error;
		}

		foreach ( $resolved['groups'] as $group => $tokens ) {
			foreach ( $tokens as $id => $entry ) {
				$id = (string) $id;

				if ( null === $registry->get( $id ) ) {
					$report['skipped'][] = array(
						'id'     => $id,
						'reason' => 'unknown_token',
					);
					continue;
				}

				if ( ! is_array( $entry ) || ! array_key_exists( '$value', $entry ) ) {
					$report['errors'][] = sprintf( '%s/%s: token entry must be a JSON object with $value.', $group, $id );
					continue;
				}

				$dtcg_type = isset( $entry['$type'] ) ? (string) $entry['$type'] : '';
				if ( '' === $dtcg_type ) {
					$report['errors'][] = sprintf( '%s/%s: missing $type.', $group, $id );
					continue;
				}

				$type_error = self::validate_type( $dtcg_type, $registry->get( $id )->type );
				if ( '' !== $type_error ) {
					$report['errors'][] = sprintf( '%s/%s: %s', $group, $id, $type_error );
					continue;
				}

				$converted = NV_oOS_Design_System_DTCG_Codec::from_dtcg_value( $dtcg_type, $entry['$value'] );
				if ( ! empty( $converted['errors'] ) ) {
					foreach ( $converted['errors'] as $error ) {
						$report['errors'][] = sprintf( '%s/%s: %s', $group, $id, $error );
					}
					continue;
				}

				$css_value = (string) $converted['value'];
				if ( '' === $css_value && '' !== trim( (string) ( is_scalar( $entry['$value'] ) ? $entry['$value'] : '' ) ) ) {
					$report['errors'][] = sprintf( '%s/%s: value could not be converted to CSS.', $group, $id );
					continue;
				}

				$values[ $id ]        = $css_value;
				$report['imported'][] = $id;

				$entry_meta = array();
				if ( ! empty( $entry['$extensions'][ NV_oOS_Design_System_DTCG_Codec::EXTENSION_KEY ] ) && is_array( $entry['$extensions'][ NV_oOS_Design_System_DTCG_Codec::EXTENSION_KEY ] ) ) {
					$entry_meta = self::sanitize_meta( $entry['$extensions'][ NV_oOS_Design_System_DTCG_Codec::EXTENSION_KEY ] );
				}
				if ( ! empty( $entry['$aliasOf'] ) ) {
					$entry_meta['aliasOf'] = sanitize_text_field( (string) $entry['$aliasOf'] );
				}
				if ( array_key_exists( '$deprecated', $entry ) ) {
					$entry_meta['deprecated'] = is_string( $entry['$deprecated'] ) ? sanitize_text_field( $entry['$deprecated'] ) : (bool) $entry['$deprecated'];
				}
				$entry_meta['dtcgType'] = sanitize_text_field( $dtcg_type );

				if ( ! empty( $entry_meta ) ) {
					$meta[ $id ] = $entry_meta;
				}
			}
		}

		if ( empty( $report['errors'] ) && empty( $report['imported'] ) ) {
			$report['warnings'][] = 'No recognised tokens were found in the document; nothing was changed.';
		}

		return self::analysis_result( $report, $values, $meta );
	}

	/**
	 * Sanitize imported token metadata before it is stored.
	 *
	 * Keys keep their case (DTCG extension keys are camelCase); values are
	 * text-sanitized, bounded in depth and length, and only scalars survive.
	 *
	 * @since 1.0.0
	 *
	 * @param array $meta  Raw metadata.
	 * @param int   $depth Current recursion depth.
	 * @return array
	 */
	private static function sanitize_meta( $meta, $depth = 0 ) {
		if ( ! is_array( $meta ) || $depth > 3 ) {
			return array();
		}

		$clean = array();
		$count = 0;

		foreach ( $meta as $key => $value ) {
			++$count;
			if ( $count > 64 ) {
				break;
			}

			$key = sanitize_text_field( (string) $key );
			if ( '' === $key ) {
				continue;
			}

			if ( is_array( $value ) ) {
				$clean[ $key ] = self::sanitize_meta( $value, $depth + 1 );
			} elseif ( is_bool( $value ) || is_int( $value ) || is_float( $value ) ) {
				$clean[ $key ] = $value;
			} elseif ( is_scalar( $value ) ) {
				$text          = sanitize_text_field( (string) $value );
				$text          = function_exists( 'mb_substr' ) ? mb_substr( $text, 0, 500 ) : substr( $text, 0, 500 );
				$clean[ $key ] = $text;
			}
		}

		return $clean;
	}

	/**
	 * Build the analysis return shape.
	 *
	 * @param array                $report Report.
	 * @param array<string,string> $values Values.
	 * @param array<string,array>  $meta   Metadata.
	 * @return array
	 */
	private static function analysis_result( $report, $values, $meta ) {
		return array(
			'report' => $report,
			'values' => $values,
			'meta'   => $meta,
		);
	}

	/**
	 * Validate a DTCG `$type` value against an NDS token type.
	 *
	 * @param string $dtcg_type DTCG `$type` value.
	 * @param string $nds_type  NDS token type from the registry.
	 * @return string Empty when valid, otherwise a human-readable problem.
	 */
	private static function validate_type( $dtcg_type, $nds_type ) {
		if ( ! isset( self::$allowed_types[ $dtcg_type ] ) ) {
			return sprintf( 'unsupported DTCG $type "%s".', $dtcg_type );
		}

		$allowed = self::$allowed_types[ $dtcg_type ];
		if ( ! in_array( '*', $allowed, true ) && ! in_array( $nds_type, $allowed, true ) ) {
			return sprintf( 'DTCG $type "%s" does not match token type "%s".', $dtcg_type, $nds_type );
		}

		return '';
	}

	/**
	 * Whether a decoded value is a JSON array rather than a JSON object.
	 *
	 * @param array $value Decoded JSON value.
	 * @return bool
	 */
	private static function is_json_list( $value ) {
		if ( array() === $value ) {
			return false;
		}
		return array_keys( $value ) === range( 0, count( $value ) - 1 );
	}
}
