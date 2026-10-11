<?php
/**
 * Static MCP tool-schema auditor and normalizer for the Content Graph AI addon.
 *
 * Namespaced port of the base plugin's
 * `WP_MCP_AI_Tool_Schema_Auditor` (includes/class-wp-mcp-ai-tool-schema-auditor.php,
 * proposal 066) — behaviour-preserving; the base copy is retained permanently
 * (ecosystem port plan D-NOBASE).
 *
 * Encodes the cross-agent schema hazards measured by the M3 "Harness Quirks
 * Matrix" (SineFrame, 2026-10) and the MCP design guidelines:
 *
 * - A single tool whose inputSchema lacks a root `type` can make an agent
 *   client drop the entire tools/list catalog.
 * - Schemas over a client's per-server byte budget (Codex: ~20 KB) get
 *   compacted, which silently drops required nested fields.
 * - Root-level `oneOf`/`anyOf` next to `properties` loses property names on
 *   some clients.
 * - Property names containing brackets (`ids[]`) make Claude Code never call
 *   the tool.
 * - `prefixItems` tuple schemas and enum-inside-`anyOf` on large schemas are
 *   mis-translated by clients that compile JSON Schema to TypeScript.
 * - `integer` fields without an upper bound can exceed 2^53 (IDs, totals,
 *   timestamps) and get rounded by clients.
 * - Tool names longer than 64 characters violate the AWS MCP design
 *   guidelines for fully-qualified names.
 *
 * @package NvoosContentGraphAi\Rest
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Rest;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Audits and normalizes tool schemas for MCP agent clients.
 */
final class ToolSchemaAuditor {

	/**
	 * Finding: inputSchema root has no `type` key.
	 *
	 * @var string
	 */
	const FINDING_ROOT_TYPE_MISSING = 'root_type_missing';

	/**
	 * Finding: root has `oneOf`/`anyOf` alongside `properties`.
	 *
	 * @var string
	 */
	const FINDING_ROOT_COMBINATOR = 'root_combinator_with_properties';

	/**
	 * Finding: a property name contains square brackets.
	 *
	 * @var string
	 */
	const FINDING_BRACKET_PROPERTY = 'bracket_property_name';

	/**
	 * Finding: schema uses the `prefixItems` tuple form.
	 *
	 * @var string
	 */
	const FINDING_PREFIX_ITEMS = 'prefix_items';

	/**
	 * Finding: an `enum` sits inside `anyOf`/`oneOf` on a large schema.
	 *
	 * @var string
	 */
	const FINDING_ENUM_IN_ANYOF_LARGE = 'enum_in_anyof_large_schema';

	/**
	 * Finding: schema byte size exceeds the emit budget.
	 *
	 * @var string
	 */
	const FINDING_OVER_BUDGET = 'schema_over_budget';

	/**
	 * Finding: an unbounded integer field risks 2^53 rounding.
	 *
	 * @var string
	 */
	const FINDING_UNSAFE_INTEGER = 'unsafe_integer_range';

	/**
	 * Finding: tool slug exceeds the maximum length.
	 *
	 * @var string
	 */
	const FINDING_SLUG_TOO_LONG = 'slug_too_long';

	/**
	 * Default per-schema byte budget for tools/list (20 KB).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_SCHEMA_BYTES = 20480;

	/**
	 * Threshold above which enum-inside-combinator findings are reported (5 KB).
	 *
	 * @var int
	 */
	const LARGE_SCHEMA_BYTES = 5120;

	/**
	 * Default maximum tool slug length (AWS MCP design guidelines).
	 *
	 * @var int
	 */
	const DEFAULT_MAX_SLUG_LENGTH = 64;

	/**
	 * Maximum recursion depth for the schema walk.
	 *
	 * @var int
	 */
	const MAX_WALK_DEPTH = 4;

	/**
	 * Integer-like property names that must carry a `maximum`/`enum` bound.
	 *
	 * @var array<int,string>
	 */
	const INTEGER_KEYS = array( 'id', 'ids', 'total', 'amount', 'timestamp', 'price', 'quantity', 'count', 'balance' );

	/**
	 * Check whether a tool slug is safe to advertise on an MCP tools/list.
	 *
	 * @since 1.1.0
	 *
	 * @param string   $slug       Tool slug.
	 * @param int|null $max_length Optional maximum length; defaults to 64.
	 * @return bool True when the slug is safe to advertise.
	 */
	public static function slug_is_mcp_safe( $slug, $max_length = null ) {
		if ( ! is_string( $slug ) || '' === $slug ) {
			return false;
		}

		$max_length = null === $max_length ? self::DEFAULT_MAX_SLUG_LENGTH : absint( $max_length );

		if ( strlen( $slug ) > $max_length ) {
			return false;
		}

		// Slugs must already be sanitized (lowercase a-z0-9, `_`, `-`).
		return (bool) preg_match( '/^[a-z0-9][a-z0-9_-]*$/', $slug );
	}

	/**
	 * Measure a schema's serialized byte size.
	 *
	 * @since 1.1.0
	 *
	 * @param array $schema Tool input schema.
	 * @return int Byte size of the JSON-encoded schema (0 on encode failure).
	 */
	public static function schema_byte_size( $schema ) {
		if ( ! is_array( $schema ) ) {
			return 0;
		}

		$json = function_exists( 'wp_json_encode' ) ? wp_json_encode( $schema ) : json_encode( $schema ); // phpcs:ignore WordPress.WP.AlternativeFunctions.json_encode_json_encode -- Fallback for non-WP contexts.

		return is_string( $json ) ? strlen( $json ) : 0;
	}

	/**
	 * Normalize a tool schema for the MCP tools/list surface.
	 *
	 * Injects the mandatory root `type: object` (and empty `properties` when
	 * absent) so a single non-conforming tool cannot make agent clients drop
	 * the whole catalog. Returns a WP_Error instead of a schema when the tool
	 * must be skipped:
	 *
	 * - `wp_mcp_ai_schema_over_budget`       — over the byte budget.
	 * - `wp_mcp_ai_schema_slug_too_long`     — slug over the length limit.
	 * - `wp_mcp_ai_schema_bracket_property`  — bracketed top-level property.
	 * - `wp_mcp_ai_schema_root_combinator`   — root oneOf/anyOf without a
	 *   `type` (injecting `type: object` beside a root combinator is itself a
	 *   known client hazard, so such tools are skipped and left to the
	 *   auditor for human review).
	 *
	 * @since 1.1.0
	 *
	 * @param string   $slug       Tool slug.
	 * @param array    $schema     Tool input schema.
	 * @param int|null $max_bytes  Optional byte budget; defaults to 20 KB.
	 * @param int|null $max_length Optional slug length limit; defaults to 64.
	 * @return array|\WP_Error Normalized schema, or WP_Error with a skip reason.
	 */
	public static function normalize_for_mcp( $slug, $schema, $max_bytes = null, $max_length = null ) {
		$max_bytes  = null === $max_bytes ? self::DEFAULT_MAX_SCHEMA_BYTES : absint( $max_bytes );
		$max_length = null === $max_length ? self::DEFAULT_MAX_SLUG_LENGTH : absint( $max_length );

		if ( ! self::slug_is_mcp_safe( $slug, $max_length ) ) {
			return new \WP_Error(
				'wp_mcp_ai_schema_slug_too_long',
				sprintf(
					/* translators: %d: maximum allowed slug length */
					__( 'Tool slug exceeds the %d-character MCP limit.', 'nvoos-content-graph-ai' ),
					$max_length
				)
			);
		}

		if ( self::schema_byte_size( $schema ) > $max_bytes ) {
			return new \WP_Error(
				'wp_mcp_ai_schema_over_budget',
				sprintf(
					/* translators: %d: maximum allowed schema size in kilobytes */
					__( 'Tool input schema exceeds the %d KB emit budget.', 'nvoos-content-graph-ai' ),
					(int) floor( $max_bytes / 1024 )
				)
			);
		}

		if ( ! isset( $schema['type'] ) ) {
			if ( isset( $schema['oneOf'] ) || isset( $schema['anyOf'] ) ) {
				return new \WP_Error(
					'wp_mcp_ai_schema_root_combinator',
					__( 'Tool schema uses a root oneOf/anyOf without a root type; left for manual review.', 'nvoos-content-graph-ai' )
				);
			}

			// Inject the root type so the catalog stays valid for clients
			// that reject schemas without one.
			$schema['type'] = 'object';
		}

		if ( ! isset( $schema['properties'] ) || ! is_array( $schema['properties'] ) ) {
			$schema['properties'] = array();
		}

		if ( ! self::property_names_are_safe( $schema['properties'], 0 ) ) {
			return new \WP_Error(
				'wp_mcp_ai_schema_bracket_property',
				__( 'Tool schema has a property name containing square brackets, which agent clients drop silently.', 'nvoos-content-graph-ai' )
			);
		}

		return $schema;
	}

	/**
	 * Audit a single tool schema and return its findings.
	 *
	 * @since 1.1.0
	 *
	 * @param string $slug   Tool slug.
	 * @param array  $schema Tool input schema.
	 * @return array<int,array{code:string,path:string,detail:string}> Findings (empty when clean).
	 */
	public static function audit_schema( $slug, $schema ) {
		$findings = array();

		if ( ! is_array( $schema ) ) {
			$findings[] = array(
				'code'   => self::FINDING_ROOT_TYPE_MISSING,
				'path'   => '$',
				'detail' => __( 'Schema is not an array.', 'nvoos-content-graph-ai' ),
			);
			return $findings;
		}

		if ( strlen( $slug ) > self::DEFAULT_MAX_SLUG_LENGTH ) {
			$findings[] = array(
				'code'   => self::FINDING_SLUG_TOO_LONG,
				'path'   => '$.name',
				'detail' => sprintf(
					/* translators: 1: slug length, 2: maximum allowed slug length */
					__( 'Slug is %1$d characters (limit %2$d).', 'nvoos-content-graph-ai' ),
					strlen( $slug ),
					self::DEFAULT_MAX_SLUG_LENGTH
				),
			);
		}

		if ( self::schema_byte_size( $schema ) > self::DEFAULT_MAX_SCHEMA_BYTES ) {
			$findings[] = array(
				'code'   => self::FINDING_OVER_BUDGET,
				'path'   => '$',
				'detail' => __( 'Schema exceeds the 20 KB emit budget and will be compacted by budgeted clients.', 'nvoos-content-graph-ai' ),
			);
		}

		if ( ! isset( $schema['type'] ) ) {
			$findings[] = array(
				'code'   => self::FINDING_ROOT_TYPE_MISSING,
				'path'   => '$.type',
				'detail' => __( 'Root type missing — clients that reject untyped schemas may drop the whole catalog.', 'nvoos-content-graph-ai' ),
			);
		}

		if ( ( isset( $schema['oneOf'] ) || isset( $schema['anyOf'] ) ) && isset( $schema['properties'] ) ) {
			$findings[] = array(
				'code'   => self::FINDING_ROOT_COMBINATOR,
				'path'   => '$',
				'detail' => __( 'Root oneOf/anyOf next to properties — some clients lose property names when translating the schema.', 'nvoos-content-graph-ai' ),
			);
		}

		// Enum-in-combinator is a client hazard only on large schemas, and
		// the size that matters is the whole tool schema (that is what
		// budgeted clients compact).
		self::walk( $schema, '$', 0, $findings, false, self::schema_byte_size( $schema ) >= self::LARGE_SCHEMA_BYTES );

		// De-duplicate on code so a schema reports each hazard class once.
		$seen = array();
		$kept = array();
		foreach ( $findings as $finding ) {
			if ( isset( $seen[ $finding['code'] ] ) ) {
				continue;
			}
			$seen[ $finding['code'] ] = true;
			$kept[]                   = $finding;
		}

		return $kept;
	}

	/**
	 * Recursively walk a schema node collecting hazard findings.
	 *
	 * @since 1.1.0
	 *
	 * @param mixed  $node          Schema node.
	 * @param string $path          JSON-path of the node.
	 * @param int    $depth         Current depth (0 = root).
	 * @param array  $findings      Findings accumulator (by reference).
	 * @param bool   $in_combinator Whether this subtree sits under oneOf/anyOf.
	 * @param bool   $schema_large  Whether the whole schema exceeds the large-schema threshold.
	 * @return void
	 */
	private static function walk( $node, $path, $depth, array &$findings, $in_combinator = false, $schema_large = false ) {
		if ( ! is_array( $node ) || $depth > self::MAX_WALK_DEPTH ) {
			return;
		}

		if ( isset( $node['prefixItems'] ) ) {
			$findings[] = array(
				'code'   => self::FINDING_PREFIX_ITEMS,
				'path'   => $path . '.prefixItems',
				'detail' => __( 'Tuple-style prefixItems is translated to Array<string> by some clients.', 'nvoos-content-graph-ai' ),
			);
		}

		if ( isset( $node['enum'] ) && $in_combinator && $schema_large ) {
			$findings[] = array(
				'code'   => self::FINDING_ENUM_IN_ANYOF_LARGE,
				'path'   => $path . '.enum',
				'detail' => __( 'Enum nested in a combinator on a large schema is erased by some clients.', 'nvoos-content-graph-ai' ),
			);
		}

		if ( isset( $node['type'] ) && 'integer' === $node['type']
			&& ! isset( $node['maximum'] ) && ! isset( $node['enum'] ) && ! isset( $node['const'] )
			&& self::key_is_integer_like( $path ) ) {
			$findings[] = array(
				'code'   => self::FINDING_UNSAFE_INTEGER,
				'path'   => $path,
				'detail' => __( 'Unbounded integer on an id/total/amount-style field can exceed 2^53 and get rounded by clients; add a maximum or use a string type.', 'nvoos-content-graph-ai' ),
			);
		}

		if ( isset( $node['properties'] ) && is_array( $node['properties'] ) ) {
			if ( ! self::property_names_are_safe( $node['properties'], $depth ) ) {
				$findings[] = array(
					'code'   => self::FINDING_BRACKET_PROPERTY,
					'path'   => $path . '.properties',
					'detail' => __( 'A property name contains square brackets, which agent clients drop silently.', 'nvoos-content-graph-ai' ),
				);
			}

			foreach ( $node['properties'] as $key => $child ) {
				self::walk( $child, $path . '.properties.' . $key, $depth + 1, $findings, false, $schema_large );
			}
		}

		foreach ( array( 'oneOf', 'anyOf' ) as $combinator ) {
			if ( isset( $node[ $combinator ] ) && is_array( $node[ $combinator ] ) ) {
				foreach ( $node[ $combinator ] as $index => $child ) {
					self::walk( $child, $path . '.' . $combinator . '[' . $index . ']', $depth + 1, $findings, true, $schema_large );
				}
			}
		}

		if ( isset( $node['items'] ) && is_array( $node['items'] ) ) {
			self::walk( $node['items'], $path . '.items', $depth + 1, $findings, $in_combinator, $schema_large );
		}
	}

	/**
	 * Check property names for bracket characters.
	 *
	 * @since 1.1.0
	 *
	 * @param array $properties Properties map.
	 * @param int   $depth      Current depth (unused; reserved for future policy).
	 * @return bool True when every property name is safe.
	 */
	private static function property_names_are_safe( $properties, $depth = 0 ) {
		unset( $depth );

		foreach ( array_keys( $properties ) as $key ) {
			if ( is_string( $key ) && ( false !== strpos( $key, '[' ) || false !== strpos( $key, ']' ) ) ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * Check whether a JSON-path terminal key looks like a large integer field.
	 *
	 * @since 1.1.0
	 *
	 * @param string $path JSON-path of the property.
	 * @return bool True when the key is id/total/amount-like.
	 */
	private static function key_is_integer_like( $path ) {
		if ( false === strrpos( $path, '.' ) ) {
			return false;
		}

		$key = strtolower( substr( $path, strrpos( $path, '.' ) + 1 ) );

		foreach ( self::INTEGER_KEYS as $candidate ) {
			if ( $key === $candidate || substr( $key, -strlen( $candidate ) - 1 ) === '_' . $candidate ) {
				return true;
			}
		}

		return false;
	}
}
