<?php
/**
 * Security Posture Service
 *
 * Computes a weighted 0-100 security score from 20+ site-specific signals
 * and surfaces it on the Security Center Overview sub-tab, the dashboard
 * widget, and via the `mcp-ai/v1/security/posture` REST endpoint.
 *
 * Signals are filterable via `wp_mcp_ai_security_posture_signals` so that Pro
 * code (OTel, vector-store, MCP-server token age) can add extra checks without
 * forking this class.
 *
 * @package WP_MCP_AI
 * @since   1.5.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security posture scoring service.
 *
 * Usage:
 *   $posture = new WP_MCP_AI_Security_Posture();
 *   $report  = $posture->get_report(); // ['score'=>72,'grade'=>'B','signals'=>[...]]
 */
class WP_MCP_AI_Security_Posture {

	/**
	 * Transient key for caching the posture report.
	 */
	const CACHE_KEY = 'wp_mcp_ai_security_posture';

	/**
	 * Cache TTL in seconds (5 minutes).
	 */
	const CACHE_TTL = 300;

	/**
	 * Settings array (lazy-loaded).
	 *
	 * @var array|null
	 */
	private $settings = null;

	/**
	 * Return the full posture report.
	 *
	 * @param bool $force_refresh Bypass the cache when true.
	 * @return array {
	 *   @type int    $score       0-100 aggregate score.
	 *   @type string $grade       A/B/C/D/F letter grade.
	 *   @type array  $signals     Individual signal results.
	 *   @type array  $quick_wins  Up to 3 unmet signals ordered by weight desc.
	 *   @type string $computed_at ISO 8601 timestamp.
	 * }
	 */
	public function get_report( $force_refresh = false ) {
		if ( ! $force_refresh ) {
			$cached = get_transient( self::CACHE_KEY );
			if ( is_array( $cached ) ) {
				return $cached;
			}
		}

		$signals = $this->evaluate_signals();

		// The shared catalogue lives in lib/core, which the wp.org base build
		// strips. When it is present every runtime scores the same vocabulary
		// with applicable-only denominators; when it is not, the local maths
		// keeps the plugin self-contained.
		$report = $this->report_via_core( $signals );

		if ( null === $report ) {
			$report = $this->report_locally( $signals );
		}

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * Score through lib/core's shared catalogue and scorer.
	 *
	 * Adds the controls this runtime cannot evaluate as `applicable: false`
	 * (Laravel-only checks) so both runtimes render the same cards, and keeps
	 * any Pro-added signals that are not in the catalogue.
	 *
	 * @param array $signals Raw evaluated signals.
	 * @return array|null Null when lib/core is unavailable.
	 */
	private function report_via_core( $signals ) {
		if ( ! class_exists( '\Nvoos\Core\Application\Security\PostureScorer' )
			|| ! class_exists( '\Nvoos\Core\Domain\Security\SecuritySignalCatalogue' ) ) {
			return null;
		}

		$by_id = array();
		foreach ( $signals as $signal ) {
			if ( isset( $signal['id'] ) ) {
				$by_id[ (string) $signal['id'] ] = $signal;
			}
		}

		$catalogue  = '\Nvoos\Core\Domain\Security\SecuritySignalCatalogue';
		$definition = '\Nvoos\Core\Domain\Security\SecuritySignalDefinition';
		$posture    = '\Nvoos\Core\Domain\ValueObject\PostureSignal';
		$evaluator  = '\Nvoos\Core\Application\Security\PostureEvaluator';
		$evaluated  = array();

		foreach ( $catalogue::all() as $entry ) {
			if ( ! $entry->appliesTo( $catalogue::RUNTIME_WORDPRESS ) ) {
				$evaluated[] = new $posture(
					definition: $entry,
					passed: false,
					applicable: false,
					detail: $catalogue::NOT_APPLICABLE_DETAIL,
				);
				continue;
			}

			$raw = $by_id[ $entry->id ] ?? null;

			$evaluated[] = new $posture(
				definition: $entry,
				passed: ! empty( $raw['passed'] ),
				applicable: true,
				detail: isset( $raw['detail'] ) ? (string) $raw['detail'] : $evaluator::UNEVALUATED_DETAIL,
				label: isset( $raw['label'] ) ? (string) $raw['label'] : '',
			);

			unset( $by_id[ $entry->id ] );
		}

		// Pro additions keep their own definition — the catalogue cannot
		// know about them, but they must keep counting.
		foreach ( $by_id as $id => $raw ) {
			$evaluated[] = new $posture(
				definition: new $definition(
					id: (string) $id,
					label: (string) ( $raw['label'] ?? $id ),
					weight: (int) ( $raw['weight'] ?? 0 ),
					subtab: (string) ( $raw['subtab'] ?? '' ),
					anchor: (string) ( $raw['anchor'] ?? '' ),
					runtimes: array( $catalogue::RUNTIME_WORDPRESS ),
				),
				passed: ! empty( $raw['passed'] ),
				applicable: true,
				detail: (string) ( $raw['detail'] ?? '' ),
				label: (string) ( $raw['label'] ?? '' ),
			);
		}

		$scorer = '\Nvoos\Core\Application\Security\PostureScorer';

		return ( new $scorer() )->score( $evaluated );
	}

	/**
	 * Local scoring for installs without lib/core.
	 *
	 * Same output shape as the shared scorer, minus the not-applicable
	 * entries there is no catalogue to describe.
	 *
	 * @param array $signals Raw evaluated signals.
	 * @return array
	 */
	private function report_locally( $signals ) {
		$total_weight    = 0;
		$achieved_weight = 0;

		foreach ( $signals as $index => $signal ) {
			$weight = (int) ( $signal['weight'] ?? 0 );
			$passed = ! empty( $signal['passed'] );

			$signals[ $index ]['applicable'] = true;
			$signals[ $index ]['status']     = $passed ? 'pass' : 'fail';
			$signals[ $index ]['reference']  = '';

			$total_weight += $weight;

			if ( $passed ) {
				$achieved_weight += $weight;
			}
		}

		$score = 0;
		if ( $total_weight > 0 ) {
			$score = (int) min( 100, round( $achieved_weight / $total_weight * 100 ) );
		}

		$unmet = array_filter(
			$signals,
			function ( $signal ) {
				return empty( $signal['passed'] );
			}
		);
		usort(
			$unmet,
			function ( $a, $b ) {
				return (int) $b['weight'] <=> (int) $a['weight'];
			}
		);

		return array(
			'score'             => $score,
			'grade'             => $this->score_to_grade( $score ),
			'passed_weight'     => $achieved_weight,
			'applicable_weight' => $total_weight,
			'signals'           => array_values( $signals ),
			'quick_wins'        => array_values( array_slice( $unmet, 0, 3 ) ),
			'computed_at'       => gmdate( 'c' ),
		);
	}

	/**
	 * Invalidate the cached posture report.
	 */
	public function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	// ------------------------------------------------------------------ //
	// Private helpers                                                      //
	// ------------------------------------------------------------------ //

	/**
	 * Lazy-load the settings array.
	 *
	 * @return array
	 */
	private function settings() {
		if ( null === $this->settings ) {
			$this->settings = get_option( 'wp_mcp_ai_settings', array() );
		}
		return $this->settings;
	}

	/**
	 * Helper: get a single setting value.
	 *
	 * @param string $key      Setting key.
	 * @param mixed  $fallback Fallback value when key is missing.
	 * @return mixed
	 */
	private function get( $key, $fallback = false ) {
		$s = $this->settings();
		return isset( $s[ $key ] ) ? $s[ $key ] : $fallback;
	}

	/**
	 * Build the signal definitions and evaluate each one.
	 *
	 * Each signal:
	 *   id      – unique string key (matches lib/core's SecuritySignalCatalogue)
	 *   label   – human-readable description
	 *   weight  – relative contribution (the scorer divides by applicable weight)
	 *   passed  – bool result of the check
	 *   detail  – short explanation shown in the UI
	 *   subtab  – which Security Center subtab owns the fix
	 *   anchor  – HTML anchor on that subtab (for deep-link)
	 *
	 * @return array
	 */
	private function evaluate_signals() {
		$s = $this->settings();

		$raw = array(
			// ── Authentication ──────────────────────────────────────────
			array(
				'id'     => 'https_active',
				'label'  => __( 'Site is served over HTTPS', 'mcp-ai-wpoos' ),
				'weight' => 10,
				'passed' => is_ssl(),
				'detail' => is_ssl()
					? __( 'HTTPS is active.', 'mcp-ai-wpoos' )
					: __( 'Configure an SSL certificate and serve the site over HTTPS.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'require_https',
			),
			array(
				'id'     => 'hsts_enabled',
				'label'  => __( 'HTTP Strict Transport Security (HSTS) enabled', 'mcp-ai-wpoos' ),
				'weight' => 5,
				'passed' => ! empty( $s['enable_hsts'] ) && is_ssl(),
				'detail' => ( ! empty( $s['enable_hsts'] ) && is_ssl() )
					? __( 'HSTS is active.', 'mcp-ai-wpoos' )
					: __( 'Enable HSTS under Network → Security Headers (requires HTTPS).', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'enable_hsts',
			),
			array(
				'id'     => 'auth_or_scoped_guests',
				'label'  => __( 'Global auth enabled or guest tokens scoped', 'mcp-ai-wpoos' ),
				'weight' => 10,
				'passed' => ! empty( $s['require_authentication_all'] ),
				'detail' => ! empty( $s['require_authentication_all'] )
					? __( 'Global authentication is required.', 'mcp-ai-wpoos' )
					: __( 'Enable "Require Authentication for All Access" or restrict guest token usage.', 'mcp-ai-wpoos' ),
				'subtab' => 'access',
				'anchor' => 'require_authentication_all',
			),
			array(
				'id'     => 'root_key_set',
				'label'  => __( 'Root security key configured (≥ 32 chars)', 'mcp-ai-wpoos' ),
				'weight' => 10,
				'passed' => ! empty( $s['root_security_key'] ) && strlen( $s['root_security_key'] ) >= 32,
				'detail' => ( ! empty( $s['root_security_key'] ) && strlen( $s['root_security_key'] ) >= 32 )
					? __( 'Root security key is set.', 'mcp-ai-wpoos' )
					: __( 'Set a root security key (≥ 32 characters) under Access & Identity → Advanced Security.', 'mcp-ai-wpoos' ),
				'subtab' => 'access',
				'anchor' => 'root_security_key',
			),
			// ── Rate limiting ────────────────────────────────────────────
			array(
				'id'     => 'rate_limiting_on',
				'label'  => __( 'Rate limiting enabled', 'mcp-ai-wpoos' ),
				'weight' => 8,
				'passed' => ! empty( $s['enable_rate_limiting'] ),
				'detail' => ! empty( $s['enable_rate_limiting'] )
					? __( 'Rate limiting is active.', 'mcp-ai-wpoos' )
					: __( 'Enable rate limiting under Network → Rate Limiting.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'enable_rate_limiting',
			),
			array(
				'id'     => 'rate_limit_by_user',
				'label'  => __( 'Rate limiting tracks users (not IP only)', 'mcp-ai-wpoos' ),
				'weight' => 3,
				'passed' => ! empty( $s['enable_rate_limiting'] ) && in_array( $s['rate_limit_by'] ?? 'user_id', array( 'user_id', 'both' ), true ),
				'detail' => ( ! empty( $s['enable_rate_limiting'] ) && in_array( $s['rate_limit_by'] ?? 'user_id', array( 'user_id', 'both' ), true ) )
					? __( 'Rate limiting tracks by user ID.', 'mcp-ai-wpoos' )
					: __( 'Set "Rate Limit By" to "User ID" or "Both" under Network → Rate Limiting.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'rate_limit_by',
			),
			array(
				'id'     => 'restriction_registry_on',
				'label'  => __( 'Restriction registry flags blocked users', 'mcp-ai-wpoos' ),
				'weight' => 0,
				'passed' => class_exists( 'WP_MCP_AI_Restriction_Registry' ) && method_exists( 'WP_MCP_AI_Restriction_Registry', 'flag' ),
				'detail' => ( class_exists( 'WP_MCP_AI_Restriction_Registry' ) && method_exists( 'WP_MCP_AI_Restriction_Registry', 'flag' ) )
					? __( 'Users blocked by rate limits or token overages are flagged for admin review.', 'mcp-ai-wpoos' )
					: __( 'The restriction registry is unavailable — blocked users cannot be flagged or unblocked from the admin.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => '',
			),
			// ── Audit logging ────────────────────────────────────────────
			array(
				'id'     => 'audit_log_on',
				'label'  => __( 'Security audit log enabled', 'mcp-ai-wpoos' ),
				'weight' => 8,
				'passed' => ! empty( $s['enable_security_audit_log'] ),
				'detail' => ! empty( $s['enable_security_audit_log'] )
					? __( 'Security audit log is enabled.', 'mcp-ai-wpoos' )
					: __( 'Enable "Security Audit Log" under Audit & Compliance.', 'mcp-ai-wpoos' ),
				'subtab' => 'audit',
				'anchor' => 'enable_security_audit_log',
			),
			array(
				'id'     => 'audit_log_retention',
				'label'  => __( 'Audit log retention ≥ 30 days', 'mcp-ai-wpoos' ),
				'weight' => 2,
				'passed' => ! empty( $s['enable_security_audit_log'] ) && ( (int) ( $s['audit_log_retention_days'] ?? 90 ) >= 30 || 0 === (int) ( $s['audit_log_retention_days'] ?? 90 ) ),
				'detail' => ( ! empty( $s['enable_security_audit_log'] ) && ( (int) ( $s['audit_log_retention_days'] ?? 90 ) >= 30 || 0 === (int) ( $s['audit_log_retention_days'] ?? 90 ) ) )
					? __( 'Audit log retention is adequate.', 'mcp-ai-wpoos' )
					: __( 'Set audit log retention to ≥ 30 days (or 0 for unlimited).', 'mcp-ai-wpoos' ),
				'subtab' => 'audit',
				'anchor' => 'audit_log_retention_days',
			),
			// ── Security headers ─────────────────────────────────────────
			array(
				'id'     => 'security_headers_on',
				'label'  => __( 'OWASP security headers enabled', 'mcp-ai-wpoos' ),
				'weight' => 5,
				'passed' => ! empty( $s['enable_security_headers'] ),
				'detail' => ! empty( $s['enable_security_headers'] )
					? __( 'Security headers are active.', 'mcp-ai-wpoos' )
					: __( 'Enable "Security Headers" under Network → Security Headers (OWASP Recommendations).', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'enable_security_headers',
			),
			array(
				'id'     => 'csp_frame_ancestors',
				'label'  => __( 'Clickjacking protection (CSP frame-ancestors) configured', 'mcp-ai-wpoos' ),
				'weight' => 4,
				'passed' => ! empty( $s['enable_security_headers'] ) && ! empty( $s['csp_frame_ancestors'] ),
				'detail' => ( ! empty( $s['enable_security_headers'] ) && ! empty( $s['csp_frame_ancestors'] ) )
					? __( 'CSP frame-ancestors is set.', 'mcp-ai-wpoos' )
					: __( 'Set CSP frame-ancestors under Network → Security Headers.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'csp_frame_ancestors',
			),
			array(
				'id'     => 'require_https_api',
				'label'  => __( 'API requests require HTTPS', 'mcp-ai-wpoos' ),
				'weight' => 3,
				'passed' => ! empty( $s['require_https'] ),
				'detail' => ! empty( $s['require_https'] )
					? __( 'API HTTPS enforcement is on.', 'mcp-ai-wpoos' )
					: __( 'Enable "Require HTTPS for API Requests" under Network.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'require_https',
			),
			// ── IP whitelist consistency ──────────────────────────────────
			array(
				'id'     => 'ip_whitelist_consistent',
				'label'  => __( 'IP whitelist not empty when enabled', 'mcp-ai-wpoos' ),
				'weight' => 5,
				'passed' => empty( $s['enable_ip_whitelist'] ) || ! empty( $s['ip_whitelist'] ),
				'detail' => ( empty( $s['enable_ip_whitelist'] ) || ! empty( $s['ip_whitelist'] ) )
					? __( 'IP whitelist configuration is consistent.', 'mcp-ai-wpoos' )
					: __( 'IP whitelist is enabled but no addresses are listed — this blocks all access.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'ip_whitelist',
			),
			// ── Access controls ───────────────────────────────────────────
			array(
				'id'     => 'minimum_capability',
				'label'  => __( 'Minimum capability requirement set', 'mcp-ai-wpoos' ),
				'weight' => 3,
				'passed' => ! empty( $s['minimum_capability'] ),
				'detail' => ! empty( $s['minimum_capability'] )
					? __( 'Minimum capability is configured.', 'mcp-ai-wpoos' )
					: __( 'Set a minimum capability under Access & Identity → Role & Capability Controls.', 'mcp-ai-wpoos' ),
				'subtab' => 'access',
				'anchor' => 'minimum_capability',
			),
			array(
				'id'     => 'guest_access_controlled',
				'label'  => __( 'Guest access is explicitly configured', 'mcp-ai-wpoos' ),
				'weight' => 3,
				'passed' => isset( $s['allow_guest_access'] ),
				'detail' => isset( $s['allow_guest_access'] )
					? __( 'Guest access setting is explicitly configured.', 'mcp-ai-wpoos' )
					: __( 'Explicitly configure guest access under Access & Identity.', 'mcp-ai-wpoos' ),
				'subtab' => 'access',
				'anchor' => 'allow_guest_access',
			),
			// ── 2FA ───────────────────────────────────────────────────────
			array(
				'id'     => '2fa_or_not_required',
				'label'  => __( 'Two-factor authentication requirement consistent', 'mcp-ai-wpoos' ),
				'weight' => 5,
				'passed' => $this->check_2fa_consistency( $s ),
				'detail' => $this->check_2fa_consistency( $s )
					? __( '2FA requirement is consistent with installed plugins.', 'mcp-ai-wpoos' )
					: __( '"Require 2FA" is enabled but no 2FA plugin was detected. Install WP 2FA or a compatible plugin.', 'mcp-ai-wpoos' ),
				'subtab' => 'access',
				'anchor' => 'enable_2fa_requirement',
			),
			// ── AI Safety ─────────────────────────────────────────────────
			array(
				'id'     => 'prompt_injection_detector',
				'label'  => __( 'Prompt-injection detector enabled', 'mcp-ai-wpoos' ),
				'weight' => 7,
				'passed' => ! empty( $s['enable_prompt_injection_detector'] ),
				'detail' => ! empty( $s['enable_prompt_injection_detector'] )
					? __( 'Prompt-injection detector is active.', 'mcp-ai-wpoos' )
					: __( 'Enable the prompt-injection detector under AI Safety.', 'mcp-ai-wpoos' ),
				'subtab' => 'ai_safety',
				'anchor' => 'enable_prompt_injection_detector',
			),
			array(
				'id'     => 'pii_filter',
				'label'  => __( 'PII filter enabled', 'mcp-ai-wpoos' ),
				'weight' => 5,
				'passed' => ! empty( $s['enable_pii_filter'] ),
				'detail' => ! empty( $s['enable_pii_filter'] )
					? __( 'PII filter is active.', 'mcp-ai-wpoos' )
					: __( 'Enable the PII filter under AI Safety to redact personal data.', 'mcp-ai-wpoos' ),
				'subtab' => 'ai_safety',
				'anchor' => 'enable_pii_filter',
			),
			// ── SSL bypass (local dev vs production) ──────────────────────
			array(
				'id'     => 'ssl_bypass_production',
				'label'  => __( 'Loopback SSL bypass disabled on HTTPS site', 'mcp-ai-wpoos' ),
				'weight' => 4,
				'passed' => ! is_ssl() || empty( $s['enable_loopback_ssl_bypass'] ),
				'detail' => ( ! is_ssl() || empty( $s['enable_loopback_ssl_bypass'] ) )
					? __( 'SSL bypass is not active on this HTTPS site.', 'mcp-ai-wpoos' )
					: __( '"Loopback SSL Bypass" is enabled on an HTTPS site — disable it unless local AI services require it.', 'mcp-ai-wpoos' ),
				'subtab' => 'access',
				'anchor' => 'enable_loopback_ssl_bypass',
			),
			// ── CORS (added after PR #5747 default hardening) ──────────
			array(
				'id'     => 'cors_restricted',
				'label'  => __( 'CORS restricted to same-origin (not open to all domains)', 'mcp-ai-wpoos' ),
				'weight' => 5,
				'passed' => 'star' !== ( $s['cors_allow_origin'] ?? '' ),
				'detail' => 'star' !== ( $s['cors_allow_origin'] ?? '' )
					? __( 'CORS is restricted to same-origin.', 'mcp-ai-wpoos' )
					: __( 'CORS is set to Allow All. Restrict to Same Origin under Network → Security Headers.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'cors_allow_origin',
			),
			// ── Error verbosity (1.2.0) ──────────────────────────────────
			array(
				'id'     => 'error_verbosity_safe',
				'label'  => __( 'API error verbosity set to Safe (production-ready)', 'mcp-ai-wpoos' ),
				'weight' => 4,
				'passed' => 'safe' === ( $s['api_error_verbosity'] ?? 'normal' ),
				'detail' => 'safe' === ( $s['api_error_verbosity'] ?? 'normal' )
					? __( 'API error detail is set to Safe.', 'mcp-ai-wpoos' )
					: __( 'API error verbosity is not Safe. Change to Safe under Network → API Error Disclosure to prevent information leakage.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'api_error_verbosity',
			),
			// ── Auth brute-force protection (1.2.0) ──────────────────────
			array(
				'id'     => 'auth_brute_force_on',
				'label'  => __( 'Authentication brute-force protection enabled', 'mcp-ai-wpoos' ),
				'weight' => 4,
				'passed' => ! empty( $s['enable_auth_rate_limiting'] ),
				'detail' => ! empty( $s['enable_auth_rate_limiting'] )
					? __( 'Auth brute-force protection is active.', 'mcp-ai-wpoos' )
					: __( 'Enable Auth Rate Limiting under Network → Authentication Brute-Force Protection.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'enable_auth_rate_limiting',
			),
			// ── Request body size limit (1.2.0) ──────────────────────────
			array(
				'id'     => 'body_size_limited',
				'label'  => __( 'Request body size limit configured', 'mcp-ai-wpoos' ),
				'weight' => 3,
				'passed' => (int) ( $s['max_request_body_size_kb'] ?? 1024 ) > 0,
				'detail' => (int) ( $s['max_request_body_size_kb'] ?? 1024 ) > 0
					? __( 'Request body size limit is active.', 'mcp-ai-wpoos' )
					: __( 'Request body size is unlimited. Set a limit under Network → Connection & Payload Limits to prevent resource exhaustion.', 'mcp-ai-wpoos' ),
				'subtab' => 'network',
				'anchor' => 'max_request_body_size_kb',
			),
		);

		/**
		 * Filter the posture signal definitions.
		 *
		 * Pro code uses this to add OTel, vector-store, MCP-server-token-age signals.
		 *
		 * @since 1.5.0
		 *
		 * @param array $raw     Array of signal definition arrays.
		 * @param array $settings Current plugin settings.
		 */
		$raw = apply_filters( 'wp_mcp_ai_security_posture_signals', $raw, $s );

		return $raw;
	}

	/**
	 * Local scoring for installs without lib/core.
	 * Convert numeric score to a letter grade.
	 *
	 * Mozilla HTTP Observatory bands, so the grade means the same thing here
	 * and on the Laravel runtime (both score through the same catalogue).
	 *
	 * @param int $score 0-100.
	 * @return string Grade (A+ . . . F).
	 */
	private function score_to_grade( $score ) {
		$bands = array(
			array( 100, 'A+' ),
			array( 90, 'A' ),
			array( 85, 'A-' ),
			array( 80, 'B+' ),
			array( 70, 'B' ),
			array( 65, 'B-' ),
			array( 60, 'C+' ),
			array( 50, 'C' ),
			array( 45, 'C-' ),
			array( 40, 'D+' ),
			array( 30, 'D' ),
			array( 25, 'D-' ),
		);

		foreach ( $bands as $band ) {
			if ( $score >= $band[0] ) {
				return $band[1];
			}
		}

		return 'F';
	}

	/**
	 * Check that the 2FA requirement is consistent with installed plugins.
	 *
	 * Passes if either 2FA is NOT required, OR a known 2FA plugin is active.
	 *
	 * @param array $s Settings array.
	 * @return bool
	 */
	private function check_2fa_consistency( $s ) {
		if ( empty( $s['enable_2fa_requirement'] ) ) {
			return true; // Not required — consistent by default.
		}

		// Check for known 2FA plugins.
		$known_2fa_plugins = array(
			'wp-2fa/wp-2fa.php',
			'two-factor/two-factor.php',
			'google-authenticator/google-authenticator.php',
			'miniorange-2-factor-authentication/miniorange_2_factor_settings.php',
		);

		if ( ! function_exists( 'is_plugin_active' ) ) {
			require_once ABSPATH . 'wp-admin/includes/plugin.php';
		}

		foreach ( $known_2fa_plugins as $plugin ) {
			if ( is_plugin_active( $plugin ) ) {
				return true;
			}
		}

		return false;
	}
}
