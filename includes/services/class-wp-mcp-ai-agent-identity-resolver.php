<?php
/**
 * Agent identity resolver — canonicalises memory agent keys.
 *
 * The memory store (`store_agent_context`) and the chat-memory drawer both
 * bucket records by `md5( (string) $agent_id )`. When the storing side passes
 * a virtual / non-numeric key (e.g. `nvoos-pro-spa-memory-drawer`,
 * `virtual_planner_1`) while the UI recalls by the canonical assistant post
 * ID, the records silently land in a different bucket and the drawer looks
 * empty. This resolver bridges the two:
 *
 *  1. On store, a non-numeric agent key is resolved to the canonical
 *     `assistant_id` carried in the tool execution context (or a previously
 *     recorded alias), and the alias mapping is persisted so future lookups
 *     resolve without context.
 *  2. On recall, the reverse lookup supplies every virtual key associated
 *     with a canonical ID so the drawer can merge those buckets too.
 *
 * The alias table is a single site option, bounded, never autoloaded, and
 * each value is sanitised — it is a cache of intent, not a source of truth.
 *
 * @package WP_MCP_AI
 * @since 1.2.0
 */

/**
 * Agent identity resolver.
 */
class WP_MCP_AI_Agent_Identity_Resolver {

	/**
	 * Option key for the alias map: alias => canonical agent id.
	 *
	 * @var string
	 */
	const OPTION_KEY = 'wp_mcp_ai_agent_id_aliases';

	/**
	 * Maximum number of aliases kept. Older, un-referenced mappings are
	 * simply not recorded once the cap is hit — resolution still works for
	 * anything already mapped.
	 *
	 * @var int
	 */
	const MAX_ALIASES = 200;

	/**
	 * Resolve an agent identifier to its canonical form.
	 *
	 * Numeric IDs are already canonical and pass through untouched. A
	 * non-numeric (virtual) key is resolved in order:
	 *
	 *  1. The canonical `assistant_id` from the execution context (the
	 *     agentic loop always knows which assistant post it is running as).
	 *  2. A previously recorded alias mapping.
	 *
	 * When resolution succeeds the alias is (re-)recorded so later stores
	 * and recalls resolve the same way.
	 *
	 * @param int|string $agent_id Raw agent identifier from the caller.
	 * @param array      $context  Tool execution context (may carry
	 *                             `assistant_id`).
	 * @return array {
	 *     @type int|string $agent_id  Canonical agent id to store/recall under.
	 *     @type int|string $original  The caller-supplied identifier.
	 *     @type bool       $resolved  Whether the identifier was remapped.
	 *     @type bool       $canonical Whether `agent_id` is a canonical post ID.
	 * }
	 */
	public static function resolve( $agent_id, array $context = array() ) {
		$original = $agent_id;

		// Canonical numeric post IDs pass straight through.
		if ( is_numeric( $agent_id ) && absint( $agent_id ) > 0 ) {
			return array(
				'agent_id'  => absint( $agent_id ),
				'original'  => (string) $original,
				'resolved'  => false,
				'canonical' => true,
			);
		}

		$agent_id = sanitize_text_field( (string) $agent_id );

		if ( '' === $agent_id ) {
			return array(
				'agent_id'  => '',
				'original'  => (string) $original,
				'resolved'  => false,
				'canonical' => false,
			);
		}

		// 1. The run context knows the canonical assistant post ID.
		$canonical = 0;
		if ( ! empty( $context['assistant_id'] ) && is_numeric( $context['assistant_id'] ) && absint( $context['assistant_id'] ) > 0 ) {
			$canonical = absint( $context['assistant_id'] );
		}

		// 2. Fall back to the recorded alias table.
		if ( ! $canonical ) {
			$recorded = self::get_canonical( $agent_id );
			if ( $recorded ) {
				$canonical = $recorded;
			}
		}

		if ( $canonical ) {
			self::register_alias( $agent_id, $canonical );

			return array(
				'agent_id'  => $canonical,
				'original'  => $agent_id,
				'resolved'  => true,
				'canonical' => true,
			);
		}

		// Unresolvable virtual key — store/recall under it unchanged so no
		// data is ever lost; the drawer can still surface it via stored_under.
		return array(
			'agent_id'  => $agent_id,
			'original'  => $agent_id,
			'resolved'  => false,
			'canonical' => false,
		);
	}

	/**
	 * Record an alias => canonical mapping.
	 *
	 * @param string     $alias     Virtual agent key.
	 * @param int|string $canonical Canonical agent id.
	 * @return bool True when the mapping was persisted.
	 */
	public static function register_alias( $alias, $canonical ) {
		$alias     = sanitize_text_field( (string) $alias );
		$canonical = sanitize_text_field( (string) $canonical );

		if ( '' === $alias || '' === $canonical || $alias === $canonical ) {
			return false;
		}

		$map = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $map ) ) {
			$map = array();
		}

		// Unchanged mapping — nothing to write.
		if ( isset( $map[ $alias ] ) && (string) $map[ $alias ] === $canonical ) {
			return true;
		}

		if ( count( $map ) >= self::MAX_ALIASES && ! isset( $map[ $alias ] ) ) {
			return false;
		}

		$map[ $alias ] = $canonical;

		return update_option( self::OPTION_KEY, $map, false );
	}

	/**
	 * Look up the canonical agent id for a virtual alias.
	 *
	 * @param string $alias Virtual agent key.
	 * @return int|string|null Canonical id or null when unmapped.
	 */
	public static function get_canonical( $alias ) {
		$alias = sanitize_text_field( (string) $alias );
		if ( '' === $alias ) {
			return null;
		}

		$map = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $map ) || ! isset( $map[ $alias ] ) ) {
			return null;
		}

		return $map[ $alias ];
	}

	/**
	 * List every virtual alias mapped to a canonical agent id.
	 *
	 * Used by the recall path to merge buckets stored under legacy virtual
	 * keys into the drawer listing. Capped to keep merge fan-out bounded.
	 *
	 * @param int|string $canonical Canonical agent id.
	 * @param int        $limit     Maximum aliases returned.
	 * @return array<int,string> Alias keys.
	 */
	public static function get_aliases( $canonical, $limit = 5 ) {
		$canonical = (string) $canonical;
		$map       = get_option( self::OPTION_KEY, array() );
		if ( ! is_array( $map ) ) {
			return array();
		}

		$aliases = array();
		foreach ( $map as $alias => $mapped ) {
			if ( (string) $mapped === $canonical ) {
				$aliases[] = $alias;
			}
			if ( count( $aliases ) >= (int) $limit ) {
				break;
			}
		}

		return $aliases;
	}
}
