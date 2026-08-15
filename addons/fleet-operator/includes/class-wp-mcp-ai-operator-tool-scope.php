<?php
/**
 * Tool allowlist resolution for the Fleet Operator addon.
 *
 * Resolves operator allowlists (tool slugs, fnmatch globs, and
 * `group:<toolkit>` entries) against the NV oOS tool registry, filters
 * MCP tools/list payloads, and classifies tools as write-capable.
 *
 * @package WP_MCP_AI
 * @since 1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resolves and applies operator tool allowlists.
 */
class WP_MCP_AI_Operator_Tool_Scope {

	/**
	 * Sanitize a single allowlist entry.
	 *
	 * Keeps slugs, glob characters (* ?), and the group: prefix while
	 * stripping anything else. sanitize_key() is not used because it would
	 * strip the asterisk and colon characters that globs and groups need.
	 *
	 * @param string $entry Raw entry.
	 * @return string Sanitized entry (may be empty).
	 */
	public static function sanitize_entry( $entry ) {
		$entry = trim( (string) $entry );
		if ( '' === $entry || strlen( $entry ) > 200 ) {
			return '';
		}

		// Reject entries containing anything outside the allowed character
		// set rather than stripping it: stripping could turn a malicious
		// string into a valid-looking slug.
		if ( preg_match( '/[^a-zA-Z0-9_*?:\-]/', $entry ) ) {
			return '';
		}

		return $entry;
	}

	/**
	 * Expand an allowlist: resolve group:<toolkit> entries into tool slugs.
	 *
	 * @param array $entries Raw allowlist entries.
	 * @return array Unique resolved entries (slugs, globs, leftover groups).
	 */
	public static function expand_allowlist( $entries ) {
		$resolved = array();
		$registry = WP_MCP_AI_Tool_Registry::get_instance();

		foreach ( (array) $entries as $entry ) {
			$entry = self::sanitize_entry( $entry );
			if ( '' === $entry ) {
				continue;
			}

			if ( 0 !== strpos( $entry, 'group:' ) ) {
				$resolved[] = $entry;
				continue;
			}

			$group = strtolower( substr( $entry, 6 ) );
			foreach ( $registry->get_tools() as $tool ) {
				$slug = $tool->get_slug();

				// The tool's own get_definition() carries toolkit metadata; the
				// registry's normalised get_tool_definition() does not.
				$definition = method_exists( $tool, 'get_definition' ) ? $tool->get_definition() : array();
				$toolkit    = isset( $definition['toolkit'] ) ? strtolower( (string) $definition['toolkit'] ) : '';
				if ( '' !== $toolkit && $toolkit === $group ) {
					$resolved[] = $slug;
				}
			}
		}

		return array_values( array_unique( $resolved ) );
	}

	/**
	 * Whether a tool slug matches an (expanded or raw) allowlist.
	 *
	 * @param string $slug      Tool slug.
	 * @param array  $allowlist Allowlist entries (slugs, globs, group:...).
	 * @return bool True when allowed.
	 */
	public static function is_tool_allowed( $slug, $allowlist ) {
		$expanded = self::expand_allowlist( $allowlist );

		foreach ( $expanded as $entry ) {
			if ( $slug === $entry ) {
				return true;
			}

			if ( false !== strpos( $entry, '*' ) || false !== strpos( $entry, '?' ) ) {
				if ( fnmatch( $entry, $slug ) ) {
					return true;
				}
			}
		}

		return false;
	}

	/**
	 * Filter an MCP-format tools/list payload down to an allowlist.
	 *
	 * @param array $mcp_tools MCP tool entries (each with a "name" key).
	 * @param array $allowlist Allowlist entries.
	 * @return array Filtered entries.
	 */
	public static function filter_tools_list( $mcp_tools, $allowlist ) {
		$expanded = self::expand_allowlist( $allowlist );

		return array_values(
			array_filter(
				(array) $mcp_tools,
				function ( $tool_entry ) use ( $expanded ) {
					$name = isset( $tool_entry['name'] ) ? (string) $tool_entry['name'] : '';
					foreach ( $expanded as $entry ) {
						if ( $name === $entry ) {
							return true;
						}
						if ( ( false !== strpos( $entry, '*' ) || false !== strpos( $entry, '?' ) ) && fnmatch( $entry, $name ) ) {
							return true;
						}
					}
					return false;
				}
			)
		);
	}

	/**
	 * Whether a tool is write-capable (state-changing).
	 *
	 * Consulted for "read"-mode operator credentials, which must be denied
	 * from invoking any write tool even when it is allowlisted.
	 *
	 * @param string $slug Tool slug.
	 * @return bool True when the tool mutates state.
	 */
	public static function tool_is_write( $slug ) {
		$registry = WP_MCP_AI_Tool_Registry::get_instance();
		$tool     = $registry->get_tool( $slug );

		$flags = array();
		if ( $tool && method_exists( $tool, 'get_capability_flags' ) ) {
			$flags = (array) $tool->get_capability_flags();
		} elseif ( $tool && method_exists( $tool, 'get_definition' ) ) {
			$definition = $tool->get_definition();
			if ( is_array( $definition ) && isset( $definition['capability_flags'] ) ) {
				$flags = (array) $definition['capability_flags'];
			}
		}

		return in_array( 'write', $flags, true ) || in_array( 'state-changing', $flags, true );
	}
}
