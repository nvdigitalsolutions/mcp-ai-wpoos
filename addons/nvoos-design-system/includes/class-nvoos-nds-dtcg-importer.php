<?php
/**
 * NV oOS Design System — DTCG Importer
 *
 * Imports design tokens in the W3C Design Tokens Community Group (DTCG)
 * format (2025.10 spec) back into the flat NDS token registry. Accepts both
 * the addon's own exporter output and the Astro Design System (ADS)
 * nds_export tool output — the two share the same document shape:
 *
 *     {
 *         "<group>": {
 *             "<token_id>": { "$type": "...", "$value": "...", "$description": "..." }
 *         }
 *     }
 *
 * @package NV_oOS_Design_System
 * @since   0.2.0
 *
 * @see https://www.designtokens.org/tr/2025.10/
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Imports a DTCG JSON document into the token registry.
 *
 * NDS keeps a flat token registry — there is no reference resolution — so
 * DTCG reference values (e.g. `{core.x}`) are rejected instead of being
 * imported as broken CSS. Unknown token ids are tolerated and skipped, while
 * structural and type-mismatch problems are reported as errors. Imports are
 * all-or-nothing: any error means nothing is applied.
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
	 * Map of DTCG $type values to the NDS token types they may import into.
	 *
	 * `dimension` covers both NDS `size` and `spacing` tokens because the
	 * exporter maps both to `dimension`. `string` is the exporter's fallback
	 * for future unknown NDS types and is accepted for any token type.
	 *
	 * @var array<string, array<int, string>>
	 */
	private static $reverse_type_map = array(
		'color'      => array( 'color' ),
		'dimension'  => array( 'size', 'spacing' ),
		'fontFamily' => array( 'font' ),
		'shadow'     => array( 'shadow' ),
		'duration'   => array( 'transition' ),
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
	 * Pure logic: no WordPress functions are called, so this also runs under
	 * plain PHP CLI as long as a registry instance is supplied.
	 *
	 * @param string                              $json     Raw JSON document.
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry used to resolve token ids.
	 * @return array{
	 *     imported: array<int, string>,
	 *     skipped: array<int, array{id: string, reason: string}>,
	 *     errors: array<int, string>
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
	 * touched. Valid tokens are applied through the registry
	 * (`set_all()` followed by `save()`) in a single step.
	 *
	 * @param string $json Raw JSON document.
	 * @return array The prepare() report plus an `applied` flag (true when
	 *               values were written to the registry and persisted).
	 */
	public function import( $json ) {
		$analysis = self::analyze( $json, $this->registry );
		$report   = $analysis['report'];

		$report['applied'] = false;
		if ( empty( $report['errors'] ) && ! empty( $analysis['values'] ) ) {
			$this->registry->set_all( $analysis['values'] );
			$this->registry->save();
			$report['applied'] = true;
		}

		return $report;
	}

	// -----------------------------------------------------------------------
	// Internals.
	// -----------------------------------------------------------------------

	/**
	 * Parse and validate a DTCG JSON document, returning the report plus the
	 * validated id => value map.
	 *
	 * @param string                              $json     Raw JSON document.
	 * @param NV_oOS_Design_System_Token_Registry $registry Token registry used to resolve token ids.
	 * @return array{
	 *     report: array{
	 *         imported: array<int, string>,
	 *         skipped: array<int, array{id: string, reason: string}>,
	 *         errors: array<int, string>
	 *     },
	 *     values: array<string, string>
	 * }
	 */
	private static function analyze( $json, $registry ) {
		$report = array(
			'imported' => array(),
			'skipped'  => array(),
			'errors'   => array(),
		);
		$values = array();

		if ( ! is_string( $json ) ) {
			$report['errors'][] = 'Import payload must be a JSON string.';
			return array(
				'report' => $report,
				'values' => $values,
			);
		}

		if ( strlen( $json ) > self::MAX_PAYLOAD_BYTES ) {
			$report['errors'][] = 'Payload exceeds the 256 KB size limit.';
			return array(
				'report' => $report,
				'values' => $values,
			);
		}

		$decoded = json_decode( $json, true );
		if ( JSON_ERROR_NONE !== json_last_error() ) {
			$report['errors'][] = 'Invalid JSON: ' . json_last_error_msg();
			return array(
				'report' => $report,
				'values' => $values,
			);
		}

		if ( ! is_array( $decoded ) || self::is_json_list( $decoded ) ) {
			$report['errors'][] = 'Top level of the document must be a JSON object of token groups.';
			return array(
				'report' => $report,
				'values' => $values,
			);
		}

		foreach ( $decoded as $group => $group_tokens ) {
			if ( ! is_array( $group_tokens ) ) {
				$report['errors'][] = sprintf( 'Group "%s" must be a JSON object.', (string) $group );
				continue;
			}

			foreach ( $group_tokens as $id => $entry ) {
				$id = (string) $id;

				if ( ! is_array( $entry ) ) {
					$report['errors'][] = sprintf( '%s/%s: token entry must be a JSON object.', $group, $id );
					continue;
				}

				// Unknown ids are tolerated: recorded as skipped, never fatal.
				if ( null === $registry->get( $id ) ) {
					$report['skipped'][] = array(
						'id'     => $id,
						'reason' => 'unknown_token',
					);
					continue;
				}

				$value = isset( $entry['$value'] ) ? $entry['$value'] : null;
				if ( ! is_string( $value ) ) {
					$report['errors'][] = sprintf( '%s/%s: $value must be a string.', $group, $id );
					continue;
				}

				// NDS is a flat registry with no reference resolution, so
				// DTCG references would import as broken CSS values.
				if ( preg_match( '/\{[^}]+\}/', $value ) ) {
					$report['errors'][] = sprintf(
						'%s/%s: DTCG reference values (e.g. "{core.x}") are not supported.',
						$group,
						$id
					);
					continue;
				}

				$type_error = self::validate_type(
					isset( $entry['$type'] ) ? $entry['$type'] : null,
					$registry->get( $id )->type
				);
				if ( '' !== $type_error ) {
					$report['errors'][] = sprintf( '%s/%s: %s', $group, $id, $type_error );
					continue;
				}

				$values[ $id ]        = $value;
				$report['imported'][] = $id;
			}
		}

		return array(
			'report' => $report,
			'values' => $values,
		);
	}

	/**
	 * Validate a DTCG $type value against an NDS token type.
	 *
	 * @param string|null $dtcg_type DTCG $type value from the document.
	 * @param string      $nds_type  NDS token type from the registry.
	 * @return string Empty when valid, otherwise a human-readable problem.
	 */
	private static function validate_type( $dtcg_type, $nds_type ) {
		if ( ! is_string( $dtcg_type ) || '' === $dtcg_type ) {
			return 'missing or invalid $type.';
		}

		if ( ! isset( self::$reverse_type_map[ $dtcg_type ] ) ) {
			return sprintf( 'unsupported DTCG $type "%s".', $dtcg_type );
		}

		$allowed = self::$reverse_type_map[ $dtcg_type ];
		if ( ! in_array( '*', $allowed, true ) && ! in_array( $nds_type, $allowed, true ) ) {
			return sprintf( 'DTCG $type "%s" does not match token type "%s".', $dtcg_type, $nds_type );
		}

		return '';
	}

	/**
	 * Whether a decoded value is a JSON array rather than a JSON object.
	 *
	 * An empty decoded array is ambiguous (`{}` and `[]` both decode to an
	 * empty PHP array) and is treated as an object — it simply imports
	 * nothing.
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
