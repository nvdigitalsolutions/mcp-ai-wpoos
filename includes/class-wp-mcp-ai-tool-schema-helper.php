<?php
/**
 * Reusable JSON Schema fragments for tool input schemas.
 *
 * Centralises the integer bounds the MCP schema auditor
 * ({@see WP_MCP_AI_Tool_Schema_Auditor}, proposal 066) requires on
 * id/total/amount-style fields so tool authors never hand-roll divergent
 * ceilings. The auditor flags any `integer` field whose key matches
 * `id`/`ids`/`total`/`amount`/`timestamp`/`price`/`quantity`/`count`/
 * `balance` (or a `_<key>` suffix) and that carries no `maximum` — values
 * above 2^53 cannot be represented exactly by JSON clients (IEEE-754
 * doubles), so every bound advertised here tops out at
 * {@see WP_MCP_AI_Tool_Schema_Helper::MAX_SAFE_INTEGER}.
 *
 * @package WP_MCP_AI
 * @since 1.2.12
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Schema fragment helpers for tool parameter declarations.
 *
 * @since 1.2.12
 */
final class WP_MCP_AI_Tool_Schema_Helper {

	/**
	 * Largest integer every JSON client can represent exactly (2^53 - 1).
	 *
	 * The honest ceiling for id-like fields: no bound below it is implied by
	 * the field itself, and no value above it survives a JSON round-trip
	 * through JavaScript clients without rounding. When a tool's server-side
	 * validation enforces a smaller semantic cap (page size, quantity), that
	 * smaller value should be declared instead.
	 *
	 * @since 1.2.12
	 * @var int
	 */
	const MAX_SAFE_INTEGER = 9007199254740991;

	/**
	 * A positive WordPress object ID (post, attachment, user, term).
	 *
	 * Minimum 1 because 0 is not a valid persisted object ID. Do not use
	 * this fragment for fields where 0 is a sentinel ("all", "auto",
	 * "create new") — use {@see WP_MCP_AI_Tool_Schema_Helper::integer()}
	 * there and document the sentinel in the description.
	 *
	 * @since 1.2.12
	 *
	 * @param string $description Human-readable description shown to the model.
	 * @return array Schema fragment.
	 */
	public static function object_id( $description ) {
		return array(
			'type'        => 'integer',
			'minimum'     => 1,
			'maximum'     => self::MAX_SAFE_INTEGER,
			'description' => $description,
		);
	}

	/**
	 * An integer bounded only by the JSON exact-representation ceiling.
	 *
	 * Sentinel-safe: imposes no `minimum`, so fields that treat 0 (or
	 * negative values) as meaningful keep accepting them. Use for
	 * timestamps, counts, quantities and id-like fields that have a
	 * sentinel; when a real semantic bound exists, declare it explicitly.
	 *
	 * @since 1.2.12
	 *
	 * @param string $description Human-readable description shown to the model.
	 * @return array Schema fragment.
	 */
	public static function integer( $description ) {
		return array(
			'type'        => 'integer',
			'maximum'     => self::MAX_SAFE_INTEGER,
			'description' => $description,
		);
	}

	/**
	 * A Unix-style timestamp in seconds (sentinel-safe integer bound).
	 *
	 * Alias of {@see WP_MCP_AI_Tool_Schema_Helper::integer()} kept for
	 * readability at declaration sites; timestamps in milliseconds also
	 * fit comfortably below MAX_SAFE_INTEGER.
	 *
	 * @since 1.2.12
	 *
	 * @param string $description Human-readable description shown to the model.
	 * @return array Schema fragment.
	 */
	public static function timestamp( $description ) {
		return self::integer( $description );
	}
}
