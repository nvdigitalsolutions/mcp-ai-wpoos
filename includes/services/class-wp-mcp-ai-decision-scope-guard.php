<?php
/**
 * Decision Scope Guard Service.
 *
 * Structural gate in front of every decision-model dispatch (TypeSafe Jev
 * and the OpenRouter decisions bridge). Each dispatch declares a *domain*
 * (what kind of thing is being decided) and an *authority ceiling*
 * (inform → suggest → act). Domains where the thing decided is a human
 * being or a moral value fail closed to a caller-supplied deterministic
 * fallback — the formula never runs.
 *
 * The guard answers "one thought too many" (Bernard Williams; the I, Robot
 * drowning scene) structurally rather than by convention: not "is the
 * calculation correct?" but "does a calculation belong here at all?".
 *
 * The domain is declared by the integration author, not inferred — the same
 * trust model as Rust's `unsafe`. The `WPMCPAI.Decisions.ScopeDeclared`
 * PHPCS sniff makes the declaration mechanical (severity-5 CI error), so an
 * undeclared dispatch cannot land.
 *
 * @package WP_MCP_AI
 * @since   2026.10
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Gates decision-model dispatches by declared domain and authority ceiling.
 *
 * @since 2026.10
 */
class WP_MCP_AI_Decision_Scope_Guard {

	/**
	 * Assistant-facing verdicts: reported to the caller, never executed by
	 * the plugin itself.
	 *
	 * @var string
	 */
	const DOMAIN_ADVISORY = 'advisory';

	/**
	 * Classification, moderation, relevance, and re-ranking of content.
	 *
	 * @var string
	 */
	const DOMAIN_CONTENT = 'content';

	/**
	 * Internal routing and selection of resources (models, skills, tiers).
	 *
	 * @var string
	 */
	const DOMAIN_OPERATIONS = 'operations';

	/**
	 * Claim/citation verification against source text.
	 *
	 * @var string
	 */
	const DOMAIN_VERIFICATION = 'verification';

	/**
	 * Domains where a formula may never be the final word. The thing being
	 * decided is a human being or a moral value: survival, worth, desert,
	 * potential, identity. The gate fails closed to the fallback before any
	 * client sees the request, and act-grants can never override this list.
	 *
	 * @var string[]
	 */
	const BANNED_DOMAINS = array( 'life', 'people', 'ethics', 'identity' );

	/**
	 * Verdict is reported; no plugin action derives from it.
	 *
	 * @var string
	 */
	const AUTHORITY_INFORM = 'inform';

	/**
	 * Verdict may shape output the caller still controls.
	 *
	 * @var string
	 */
	const AUTHORITY_SUGGEST = 'suggest';

	/**
	 * Verdict may drive automatic action. Denied for every domain unless the
	 * domain is explicitly listed by the `wp_mcp_ai_decision_act_domains`
	 * filter.
	 *
	 * @var string
	 */
	const AUTHORITY_ACT = 'act';

	/**
	 * All authority levels, ascending.
	 *
	 * @var string[]
	 */
	const AUTHORITIES = array( 'inform', 'suggest', 'act' );

	/**
	 * Gate a decision dispatch before it reaches any client.
	 *
	 * Returns `true` when the dispatch is allowed. Otherwise returns the
	 * fallback's result: a callable is invoked (and its result returned), a
	 * non-callable value is returned as-is, and `null` resolves to a
	 * `wp_mcp_ai_decision_scope_gated` WP_Error. The guard never invents a
	 * fallback — the caller owns its own fail-closed behavior.
	 *
	 * @since 2026.10
	 *
	 * @param string         $domain    Declared decision domain. One of the
	 *                                  DOMAIN_* constants. Banned domains
	 *                                  always fail closed.
	 * @param string         $authority Highest authority any verdict from
	 *                                 this dispatch may carry. One of the
	 *                                 AUTHORITY_* constants.
	 * @param callable|mixed $fallback Fail-closed result: callable to invoke
	 *                                 or a value to return as-is.
	 * @return true|mixed True when allowed; the fallback result otherwise.
	 */
	public static function gate( $domain, $authority, $fallback = null ) {
		$domain    = is_string( $domain ) ? $domain : '';
		$authority = is_string( $authority ) ? $authority : '';

		// Banned domains fail closed before anything else — a formula must
		// never run here, and no grant can override that.
		if ( '' === $domain || in_array( $domain, self::BANNED_DOMAINS, true ) ) {
			return self::resolve_fallback( $fallback );
		}

		// An unknown authority is an undeclared intent: fail closed rather
		// than guessing at the level.
		if ( ! in_array( $authority, self::AUTHORITIES, true ) ) {
			return self::resolve_fallback( $fallback );
		}

		// Act-level authority requires an explicit per-domain grant.
		if ( self::AUTHORITY_ACT === $authority && ! self::domain_grants_act( $domain ) ) {
			return self::resolve_fallback( $fallback );
		}

		return true;
	}

	/**
	 * Whether the given domain is explicitly granted act-level authority.
	 *
	 * The grant list is empty by default: no domain may act until an operator
	 * opts one in via the `wp_mcp_ai_decision_act_domains` filter. Banned
	 * domains are rejected by {@see gate()} before this check runs.
	 *
	 * @since 2026.10
	 *
	 * @param string $domain Decision domain slug.
	 * @return bool True when the domain is explicitly granted.
	 */
	public static function domain_grants_act( $domain ) {
		$domain = is_string( $domain ) ? $domain : '';

		/**
		 * Filter the decision domains allowed to carry act-level authority.
		 *
		 * Defaults to an empty list: no Jev verdict may drive automatic
		 * action anywhere. Banned domains (life, people, ethics, identity)
		 * are rejected regardless of this filter.
		 *
		 * @since 2026.10
		 *
		 * @param string[] $granted_domains Domain slugs allowed to act.
		 */
		$granted = apply_filters( 'wp_mcp_ai_decision_act_domains', array() );
		$granted = is_array( $granted ) ? array_map( 'sanitize_key', $granted ) : array();

		return in_array( $domain, $granted, true );
	}

	/**
	 * Resolve the fail-closed fallback.
	 *
	 * @since 2026.10
	 *
	 * @param callable|mixed $fallback Callable to invoke or value to return.
	 * @return mixed The fallback result, or a WP_Error when none supplied.
	 */
	private static function resolve_fallback( $fallback ) {
		if ( is_callable( $fallback ) ) {
			return $fallback();
		}

		if ( null !== $fallback ) {
			return $fallback;
		}

		return new WP_Error(
			'wp_mcp_ai_decision_scope_gated',
			__( 'This decision path is gated by the decision-scope guard.', 'mcp-ai-wpoos' )
		);
	}
}
