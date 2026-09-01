<?php
/**
 * Security Posture Service for the Content Graph AI addon.
 *
 * Ported 1:1 from the base plugin's
 * `includes/security/class-wp-mcp-ai-security-posture.php` (behaviour-
 * preserving; base copy retained permanently — ecosystem port plan
 * D-NOBASE). Cache key/TTL, the 23-signal definition set, weighting,
 * grading thresholds, quick-win selection, and the
 * `wp_mcp_ai_security_posture_signals` filter keep their base names and
 * semantics.
 *
 * Decoupling (documented, additive):
 * - Settings read from the base `wp_mcp_ai_settings` option in monolith
 *   installs and the content-graph `nvoos_content_graph_settings` option
 *   standalone (the two stores use different key vocabularies; the
 *   posture keys follow the base vocabulary until the CG-AI settings
 *   shell lands in a later wave).
 * - The restriction-registry signal only resolves the base
 *   `WP_MCP_AI_Restriction_Registry` in monolith installs.
 *
 * @package NvoosContentGraphAi\Security
 * @since   1.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   Proprietary (commercial license required)
 */

declare(strict_types=1);

namespace NvoosContentGraphAi\Security;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Security posture scoring service.
 *
 * Usage:
 *   $posture = new SecurityPosture();
 *   $report  = $posture->get_report(); // ['score'=>72,'grade'=>'B','signals'=>[...]]
 *
 * @since 1.1.0
 */
class SecurityPosture {

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
		$score   = $this->compute_score( $signals );
		$grade   = $this->score_to_grade( $score );

		// Up to 3 quick wins: unmet signals with highest weight.
		$unmet = array_filter(
			$signals,
			function ( $s ) {
				return ! $s['passed'];
			}
		);
		usort(
			$unmet,
			function ( $a, $b ) {
				return $b['weight'] <=> $a['weight'];
			}
		);
		$quick_wins = array_values( array_slice( $unmet, 0, 3 ) );

		$report = array(
			'score'       => $score,
			'grade'       => $grade,
			'signals'     => $signals,
			'quick_wins'  => $quick_wins,
			'computed_at' => gmdate( 'c' ),
		);

		set_transient( self::CACHE_KEY, $report, self::CACHE_TTL );

		return $report;
	}

	/**
	 * Invalidate the cached posture report.
	 *
	 * @return void
	 */
	public function invalidate_cache() {
		delete_transient( self::CACHE_KEY );
	}

	// ------------------------------------------------------------------ //
	// Private helpers                                                      //
	// ------------------------------------------------------------------ //

	/**
	 * Lazy-load the settings array (per-install-mode seam).
	 *
	 * @return array
	 */
	private function settings() {
		if ( null === $this->settings ) {
			$option        = defined( 'WP_MCP_AI_PATH' ) ? 'wp_mcp_ai_settings' : 'nvoos_content_graph_settings';
			$loaded        = get_option( $option, array() );
			$this->settings = is_array( $loaded ) ? $loaded : array();
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
	 *   id      – unique string key
	 *   label   – human-readable description
	 *   weight  – contribution to the total score (all weights sum to 100)
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
				'label'  => __( 'Site is served over HTTPS', 'nvoos-content-graph-ai' ),
				'weight' => 10,
				'passed' => is_ssl(),
				'detail' => is_ssl()
					? __( 'HTTPS is active.', 'nvoos-content-graph-ai' )
					: __( 'Configure an SSL certificate and serve the site over HTTPS.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'require_https',
			),
			array(
				'id'     => 'hsts_enabled',
				'label'  => __( 'HTTP Strict Transport Security (HSTS) enabled', 'nvoos-content-graph-ai' ),
				'weight' => 5,
				'passed' => ! empty( $s['enable_hsts'] ) && is_ssl(),
				'detail' => ( ! empty( $s['enable_hsts'] ) && is_ssl() )
					? __( 'HSTS is active.', 'nvoos-content-graph-ai' )
					: __( 'Enable HSTS under Network → Security Headers (requires HTTPS).', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'enable_hsts',
			),
			array(
				'id'     => 'auth_or_scoped_guests',
				'label'  => __( 'Global auth enabled or guest tokens scoped', 'nvoos-content-graph-ai' ),
				'weight' => 10,
				'passed' => ! empty( $s['require_authentication_all'] ),
				'detail' => ! empty( $s['require_authentication_all'] )
					? __( 'Global authentication is required.', 'nvoos-content-graph-ai' )
					: __( 'Enable "Require Authentication for All Access" or restrict guest token usage.', 'nvoos-content-graph-ai' ),
				'subtab' => 'access',
				'anchor' => 'require_authentication_all',
			),
			array(
				'id'     => 'root_key_set',
				'label'  => __( 'Root security key configured (≥ 32 chars)', 'nvoos-content-graph-ai' ),
				'weight' => 10,
				'passed' => ! empty( $s['root_security_key'] ) && strlen( $s['root_security_key'] ) >= 32,
				'detail' => ( ! empty( $s['root_security_key'] ) && strlen( $s['root_security_key'] ) >= 32 )
					? __( 'Root security key is set.', 'nvoos-content-graph-ai' )
					: __( 'Set a root security key (≥ 32 characters) under Access & Identity → Advanced Security.', 'nvoos-content-graph-ai' ),
				'subtab' => 'access',
				'anchor' => 'root_security_key',
			),
			// ── Rate limiting ────────────────────────────────────────────
			array(
				'id'     => 'rate_limiting_on',
				'label'  => __( 'Rate limiting enabled', 'nvoos-content-graph-ai' ),
				'weight' => 8,
				'passed' => ! empty( $s['enable_rate_limiting'] ),
				'detail' => ! empty( $s['enable_rate_limiting'] )
					? __( 'Rate limiting is active.', 'nvoos-content-graph-ai' )
					: __( 'Enable rate limiting under Network → Rate Limiting.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'enable_rate_limiting',
			),
			array(
				'id'     => 'rate_limit_by_user',
				'label'  => __( 'Rate limiting tracks users (not IP only)', 'nvoos-content-graph-ai' ),
				'weight' => 3,
				'passed' => ! empty( $s['enable_rate_limiting'] ) && in_array( $s['rate_limit_by'] ?? 'user_id', array( 'user_id', 'both' ), true ),
				'detail' => ( ! empty( $s['enable_rate_limiting'] ) && in_array( $s['rate_limit_by'] ?? 'user_id', array( 'user_id', 'both' ), true ) )
					? __( 'Rate limiting tracks by user ID.', 'nvoos-content-graph-ai' )
					: __( 'Set "Rate Limit By" to "User ID" or "Both" under Network → Rate Limiting.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'rate_limit_by',
			),
			array(
				'id'     => 'restriction_registry_on',
				'label'  => __( 'Restriction registry flags blocked users', 'nvoos-content-graph-ai' ),
				'weight' => 0,
				'passed' => defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Restriction_Registry' ) && method_exists( 'WP_MCP_AI_Restriction_Registry', 'flag' ),
				'detail' => ( defined( 'WP_MCP_AI_PATH' ) && class_exists( 'WP_MCP_AI_Restriction_Registry' ) && method_exists( 'WP_MCP_AI_Restriction_Registry', 'flag' ) )
					? __( 'Users blocked by rate limits or token overages are flagged for admin review.', 'nvoos-content-graph-ai' )
					: __( 'The restriction registry is unavailable — blocked users cannot be flagged or unblocked from the admin.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => '',
			),
			// ── Audit logging ────────────────────────────────────────────
			array(
				'id'     => 'audit_log_on',
				'label'  => __( 'Security audit log enabled', 'nvoos-content-graph-ai' ),
				'weight' => 8,
				'passed' => ! empty( $s['enable_security_audit_log'] ),
				'detail' => ! empty( $s['enable_security_audit_log'] )
					? __( 'Security audit log is enabled.', 'nvoos-content-graph-ai' )
					: __( 'Enable "Security Audit Log" under Audit & Compliance.', 'nvoos-content-graph-ai' ),
				'subtab' => 'audit',
				'anchor' => 'enable_security_audit_log',
			),
			array(
				'id'     => 'audit_log_retention',
				'label'  => __( 'Audit log retention ≥ 30 days', 'nvoos-content-graph-ai' ),
				'weight' => 2,
				'passed' => ! empty( $s['enable_security_audit_log'] ) && ( (int) ( $s['audit_log_retention_days'] ?? 90 ) >= 30 || 0 === (int) ( $s['audit_log_retention_days'] ?? 90 ) ),
				'detail' => ( ! empty( $s['enable_security_audit_log'] ) && ( (int) ( $s['audit_log_retention_days'] ?? 90 ) >= 30 || 0 === (int) ( $s['audit_log_retention_days'] ?? 90 ) ) )
					? __( 'Audit log retention is adequate.', 'nvoos-content-graph-ai' )
					: __( 'Set audit log retention to ≥ 30 days (or 0 for unlimited).', 'nvoos-content-graph-ai' ),
				'subtab' => 'audit',
				'anchor' => 'audit_log_retention_days',
			),
			// ── Security headers ─────────────────────────────────────────
			array(
				'id'     => 'security_headers_on',
				'label'  => __( 'OWASP security headers enabled', 'nvoos-content-graph-ai' ),
				'weight' => 5,
				'passed' => ! empty( $s['enable_security_headers'] ),
				'detail' => ! empty( $s['enable_security_headers'] )
					? __( 'Security headers are active.', 'nvoos-content-graph-ai' )
					: __( 'Enable "Security Headers" under Network → Security Headers (OWASP Recommendations).', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'enable_security_headers',
			),
			array(
				'id'     => 'csp_frame_ancestors',
				'label'  => __( 'Clickjacking protection (CSP frame-ancestors) configured', 'nvoos-content-graph-ai' ),
				'weight' => 4,
				'passed' => ! empty( $s['enable_security_headers'] ) && ! empty( $s['csp_frame_ancestors'] ),
				'detail' => ( ! empty( $s['enable_security_headers'] ) && ! empty( $s['csp_frame_ancestors'] ) )
					? __( 'CSP frame-ancestors is set.', 'nvoos-content-graph-ai' )
					: __( 'Set CSP frame-ancestors under Network → Security Headers.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'csp_frame_ancestors',
			),
			array(
				'id'     => 'require_https_api',
				'label'  => __( 'API requests require HTTPS', 'nvoos-content-graph-ai' ),
				'weight' => 3,
				'passed' => ! empty( $s['require_https'] ),
				'detail' => ! empty( $s['require_https'] )
					? __( 'API HTTPS enforcement is on.', 'nvoos-content-graph-ai' )
					: __( 'Enable "Require HTTPS for API Requests" under Network.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'require_https',
			),
			// ── IP whitelist consistency ──────────────────────────────────
			array(
				'id'     => 'ip_whitelist_consistent',
				'label'  => __( 'IP whitelist not empty when enabled', 'nvoos-content-graph-ai' ),
				'weight' => 5,
				'passed' => empty( $s['enable_ip_whitelist'] ) || ! empty( $s['ip_whitelist'] ),
				'detail' => ( empty( $s['enable_ip_whitelist'] ) || ! empty( $s['ip_whitelist'] ) )
					? __( 'IP whitelist configuration is consistent.', 'nvoos-content-graph-ai' )
					: __( 'IP whitelist is enabled but no addresses are listed — this blocks all access.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'ip_whitelist',
			),
			// ── Access controls ───────────────────────────────────────────
			array(
				'id'     => 'minimum_capability',
				'label'  => __( 'Minimum capability requirement set', 'nvoos-content-graph-ai' ),
				'weight' => 3,
				'passed' => ! empty( $s['minimum_capability'] ),
				'detail' => ! empty( $s['minimum_capability'] )
					? __( 'Minimum capability is configured.', 'nvoos-content-graph-ai' )
					: __( 'Set a minimum capability under Access & Identity → Role & Capability Controls.', 'nvoos-content-graph-ai' ),
				'subtab' => 'access',
				'anchor' => 'minimum_capability',
			),
			array(
				'id'     => 'guest_access_controlled',
				'label'  => __( 'Guest access is explicitly configured', 'nvoos-content-graph-ai' ),
				'weight' => 3,
				'passed' => isset( $s['allow_guest_access'] ),
				'detail' => isset( $s['allow_guest_access'] )
					? __( 'Guest access setting is explicitly configured.', 'nvoos-content-graph-ai' )
					: __( 'Explicitly configure guest access under Access & Identity.', 'nvoos-content-graph-ai' ),
				'subtab' => 'access',
				'anchor' => 'allow_guest_access',
			),
			// ── 2FA ───────────────────────────────────────────────────────
			array(
				'id'     => '2fa_or_not_required',
				'label'  => __( 'Two-factor authentication requirement consistent', 'nvoos-content-graph-ai' ),
				'weight' => 5,
				'passed' => $this->check_2fa_consistency( $s ),
				'detail' => $this->check_2fa_consistency( $s )
					? __( '2FA requirement is consistent with installed plugins.', 'nvoos-content-graph-ai' )
					: __( '"Require 2FA" is enabled but no 2FA plugin was detected. Install WP 2FA or a compatible plugin.', 'nvoos-content-graph-ai' ),
				'subtab' => 'access',
				'anchor' => 'enable_2fa_requirement',
			),
			// ── AI Safety ─────────────────────────────────────────────────
			array(
				'id'     => 'prompt_injection_detector',
				'label'  => __( 'Prompt-injection detector enabled', 'nvoos-content-graph-ai' ),
				'weight' => 7,
				'passed' => ! empty( $s['enable_prompt_injection_detector'] ),
				'detail' => ! empty( $s['enable_prompt_injection_detector'] )
					? __( 'Prompt-injection detector is active.', 'nvoos-content-graph-ai' )
					: __( 'Enable the prompt-injection detector under AI Safety.', 'nvoos-content-graph-ai' ),
				'subtab' => 'ai_safety',
				'anchor' => 'enable_prompt_injection_detector',
			),
			array(
				'id'     => 'pii_filter',
				'label'  => __( 'PII filter enabled', 'nvoos-content-graph-ai' ),
				'weight' => 5,
				'passed' => ! empty( $s['enable_pii_filter'] ),
				'detail' => ! empty( $s['enable_pii_filter'] )
					? __( 'PII filter is active.', 'nvoos-content-graph-ai' )
					: __( 'Enable the PII filter under AI Safety to redact personal data.', 'nvoos-content-graph-ai' ),
				'subtab' => 'ai_safety',
				'anchor' => 'enable_pii_filter',
			),
			// ── SSL bypass (local dev vs production) ──────────────────────
			array(
				'id'     => 'ssl_bypass_production',
				'label'  => __( 'Loopback SSL bypass disabled on HTTPS site', 'nvoos-content-graph-ai' ),
				'weight' => 4,
				'passed' => ! is_ssl() || empty( $s['enable_loopback_ssl_bypass'] ),
				'detail' => ( ! is_ssl() || empty( $s['enable_loopback_ssl_bypass'] ) )
					? __( 'SSL bypass is not active on this HTTPS site.', 'nvoos-content-graph-ai' )
					: __( '"Loopback SSL Bypass" is enabled on an HTTPS site — disable it unless local AI services require it.', 'nvoos-content-graph-ai' ),
				'subtab' => 'access',
				'anchor' => 'enable_loopback_ssl_bypass',
			),
			// ── CORS (added after PR #5747 default hardening) ──────────
			array(
				'id'     => 'cors_restricted',
				'label'  => __( 'CORS restricted to same-origin (not open to all domains)', 'nvoos-content-graph-ai' ),
				'weight' => 5,
				'passed' => 'star' !== ( $s['cors_allow_origin'] ?? '' ),
				'detail' => 'star' !== ( $s['cors_allow_origin'] ?? '' )
					? __( 'CORS is restricted to same-origin.', 'nvoos-content-graph-ai' )
					: __( 'CORS is set to Allow All. Restrict to Same Origin under Network → Security Headers.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'cors_allow_origin',
			),
			// ── Error verbosity ──────────────────────────────────────────
			array(
				'id'     => 'error_verbosity_safe',
				'label'  => __( 'API error verbosity set to Safe (production-ready)', 'nvoos-content-graph-ai' ),
				'weight' => 4,
				'passed' => 'safe' === ( $s['api_error_verbosity'] ?? 'normal' ),
				'detail' => 'safe' === ( $s['api_error_verbosity'] ?? 'normal' )
					? __( 'API error detail is set to Safe.', 'nvoos-content-graph-ai' )
					: __( 'API error verbosity is not Safe. Change to Safe under Network → API Error Disclosure to prevent information leakage.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'api_error_verbosity',
			),
			// ── Auth brute-force protection ──────────────────────────────
			array(
				'id'     => 'auth_brute_force_on',
				'label'  => __( 'Authentication brute-force protection enabled', 'nvoos-content-graph-ai' ),
				'weight' => 4,
				'passed' => ! empty( $s['enable_auth_rate_limiting'] ),
				'detail' => ! empty( $s['enable_auth_rate_limiting'] )
					? __( 'Auth brute-force protection is active.', 'nvoos-content-graph-ai' )
					: __( 'Enable Auth Rate Limiting under Network → Authentication Brute-Force Protection.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'enable_auth_rate_limiting',
			),
			// ── Request body size limit ──────────────────────────────────
			array(
				'id'     => 'body_size_limited',
				'label'  => __( 'Request body size limit configured', 'nvoos-content-graph-ai' ),
				'weight' => 3,
				'passed' => (int) ( $s['max_request_body_size_kb'] ?? 1024 ) > 0,
				'detail' => (int) ( $s['max_request_body_size_kb'] ?? 1024 ) > 0
					? __( 'Request body size limit is active.', 'nvoos-content-graph-ai' )
					: __( 'Request body size is unlimited. Set a limit under Network → Connection & Payload Limits to prevent resource exhaustion.', 'nvoos-content-graph-ai' ),
				'subtab' => 'network',
				'anchor' => 'max_request_body_size_kb',
			),
		);

		/**
		 * Filter the posture signal definitions.
		 *
		 * Pro code uses this to add OTel, vector-store, MCP-server-token-age signals.
		 *
		 * @param array $raw     Array of signal definition arrays.
		 * @param array $settings Current plugin settings.
		 */
		$raw = apply_filters( 'wp_mcp_ai_security_posture_signals', $raw, $s );

		return $raw;
	}

	/**
	 * Compute aggregate score from evaluated signals.
	 *
	 * Score = sum of weights of passing signals / total weight × 100, capped at 100.
	 *
	 * @param array $signals Evaluated signals.
	 * @return int 0-100.
	 */
	private function compute_score( $signals ) {
		$total_weight    = 0;
		$achieved_weight = 0;

		foreach ( $signals as $signal ) {
			$w             = (int) ( $signal['weight'] ?? 0 );
			$total_weight += $w;
			if ( ! empty( $signal['passed'] ) ) {
				$achieved_weight += $w;
			}
		}

		if ( 0 === $total_weight ) {
			return 0;
		}

		return (int) min( 100, round( $achieved_weight / $total_weight * 100 ) );
	}

	/**
	 * Convert numeric score to letter grade.
	 *
	 * @param int $score 0-100.
	 * @return string A/B/C/D/F.
	 */
	private function score_to_grade( $score ) {
		if ( $score >= 90 ) {
			return 'A';
		}
		if ( $score >= 75 ) {
			return 'B';
		}
		if ( $score >= 60 ) {
			return 'C';
		}
		if ( $score >= 40 ) {
			return 'D';
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
