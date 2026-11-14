<?php

/**
 * The shared security-posture signal catalogue.
 *
 * One control vocabulary for every runtime. Before this catalogue each
 * runtime owned its own list with its own ids — WordPress shipped 23 checks,
 * Laravel shipped 8 unrelated ones — so the console could not line them up
 * and a runtime's score depended on which cards happened to exist.
 *
 * Grounding for the shape and the weights:
 *  - OWASP ASVS 5.0 (2025-05-30) supplies the control taxonomy and the
 *    subtab grouping.
 *  - The OWASP Secure Headers Project supplies the header controls.
 *  - Mozilla HTTP Observatory supplies the weighted-score + letter-grade
 *    presentation (the scorer implements its bands).
 *  - Applicable-only denominators follow AWS Security Hub / CIS-CAT
 *    practice: a control that cannot be evaluated is excluded from both the
 *    numerator and the denominator instead of counting as a failure.
 *
 * Weights are relative, not percentages: the scorer divides by the
 * applicable weight, so a runtime that cannot see a control is unaffected by
 * its weight.
 *
 * @package Nvoos\Core
 * @since   1.5.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Security;

final class SecuritySignalCatalogue
{
	public const RUNTIME_WORDPRESS = 'wordpress';
	public const RUNTIME_LARAVEL = 'laravel';

	/**
	 * Runtime-specific signals carry a reason so the console can explain the
	 * card instead of hiding it.
	 */
	public const NOT_APPLICABLE_DETAIL = 'Not applicable on this runtime.';

	/**
	 * The catalogue. Order is the console's display order.
	 *
	 * `runtimes` lists where the control can actually be evaluated. A
	 * WordPress-only control is not a Laravel gap to be stubbed: the runtime
	 * has no such primitive, so the honest answer is "not applicable".
	 */
	private const DEFINITIONS = array(
		// ── Network ────────────────────────────────────────────────────────
		array(
			'id'        => 'https_active',
			'label'     => 'Site is served over HTTPS',
			'weight'    => 10,
			'subtab'    => 'network',
			'anchor'    => 'require_https',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP ASVS 5.0 — Secure Communication',
		),
		array(
			'id'        => 'hsts_enabled',
			'label'     => 'HTTP Strict Transport Security (HSTS) enabled',
			'weight'    => 5,
			'subtab'    => 'network',
			'anchor'    => 'enable_hsts',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'RFC 6797 (HSTS)',
		),
		array(
			'id'        => 'rate_limiting_on',
			'label'     => 'Rate limiting enabled',
			'weight'    => 8,
			'subtab'    => 'network',
			'anchor'    => 'enable_rate_limiting',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP API Security Top 10 — API4:2023 Unrestricted Resource Consumption',
		),
		array(
			'id'        => 'rate_limit_by_user',
			'label'     => 'Rate limiting tracks users (not IP only)',
			'weight'    => 3,
			'subtab'    => 'network',
			'anchor'    => 'rate_limit_by',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP API Security Top 10 — API4:2023',
		),
		array(
			'id'        => 'restriction_registry_on',
			'label'     => 'Restriction registry flags blocked users',
			'weight'    => 0,
			'subtab'    => 'network',
			'anchor'    => '',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => '',
		),
		array(
			'id'        => 'security_headers_on',
			'label'     => 'OWASP security headers enabled',
			'weight'    => 5,
			'subtab'    => 'network',
			'anchor'    => 'enable_security_headers',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP Secure Headers Project',
		),
		array(
			'id'        => 'csp_frame_ancestors',
			'label'     => 'Clickjacking protection (CSP frame-ancestors) configured',
			'weight'    => 4,
			'subtab'    => 'network',
			'anchor'    => 'csp_frame_ancestors',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'CSP Level 3 — frame-ancestors; OWASP Secure Headers Project',
		),
		array(
			'id'        => 'require_https_api',
			'label'     => 'API requests require HTTPS',
			'weight'    => 3,
			'subtab'    => 'network',
			'anchor'    => 'require_https',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'RFC 9110 (HTTP Semantics); OWASP ASVS 5.0 — Secure Communication',
		),
		array(
			'id'        => 'ip_whitelist_consistent',
			'label'     => 'IP whitelist not empty when enabled',
			'weight'    => 5,
			'subtab'    => 'network',
			'anchor'    => 'ip_whitelist',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => 'NIST SP 800-53 — AC-17 Remote Access',
		),
		array(
			'id'        => 'cors_restricted',
			'label'     => 'CORS restricted to known origins (not open to all domains)',
			'weight'    => 5,
			'subtab'    => 'network',
			'anchor'    => 'cors_allow_origin',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP HTML5 Security Cheat Sheet — CORS',
		),
		array(
			'id'        => 'error_verbosity_safe',
			'label'     => 'API error verbosity set to Safe (production-ready)',
			'weight'    => 4,
			'subtab'    => 'network',
			'anchor'    => 'api_error_verbosity',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'CWE-209; OWASP ASVS 5.0 — Logging and Error Handling',
		),
		array(
			'id'        => 'auth_brute_force_on',
			'label'     => 'Authentication brute-force protection enabled',
			'weight'    => 4,
			'subtab'    => 'network',
			'anchor'    => 'enable_auth_rate_limiting',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'NIST SP 800-63B — Rate Limiting',
		),
		array(
			'id'        => 'body_size_limited',
			'label'     => 'Request body size limit configured',
			'weight'    => 3,
			'subtab'    => 'network',
			'anchor'    => 'max_request_body_size_kb',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP ASVS 5.0 — Validation and Business Logic',
		),

		// ── Access & identity ──────────────────────────────────────────────
		array(
			'id'        => 'auth_or_scoped_guests',
			'label'     => 'Global auth enabled or guest tokens scoped',
			'weight'    => 10,
			'subtab'    => 'access',
			'anchor'    => 'require_authentication_all',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => 'OWASP ASVS 5.0 — Authentication',
		),
		array(
			'id'        => 'root_key_set',
			'label'     => 'Root security key configured (≥ 32 chars)',
			'weight'    => 10,
			'subtab'    => 'access',
			'anchor'    => 'root_security_key',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => 'OWASP ASVS 5.0 — Cryptography',
		),
		array(
			'id'        => 'minimum_capability',
			'label'     => 'Minimum capability requirement set',
			'weight'    => 3,
			'subtab'    => 'access',
			'anchor'    => 'minimum_capability',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP ASVS 5.0 — Authorization',
		),
		array(
			'id'        => 'guest_access_controlled',
			'label'     => 'Guest access is explicitly configured',
			'weight'    => 3,
			'subtab'    => 'access',
			'anchor'    => 'allow_guest_access',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => 'OWASP ASVS 5.0 — Authentication',
		),
		array(
			'id'        => '2fa_or_not_required',
			'label'     => 'Two-factor authentication requirement consistent',
			'weight'    => 5,
			'subtab'    => 'access',
			'anchor'    => 'enable_2fa_requirement',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => 'NIST SP 800-63B — Authenticator Assurance Level 2',
		),
		array(
			'id'        => 'ssl_bypass_production',
			'label'     => 'Loopback SSL bypass disabled on HTTPS site',
			'weight'    => 4,
			'subtab'    => 'access',
			'anchor'    => 'enable_loopback_ssl_bypass',
			'runtimes'  => array( self::RUNTIME_WORDPRESS ),
			'reference' => '',
		),
		array(
			'id'        => 'csrf',
			'label'     => 'CSRF protection active',
			'weight'    => 10,
			'subtab'    => 'access',
			'anchor'    => '',
			'runtimes'  => array( self::RUNTIME_LARAVEL ),
			'reference' => 'CWE-352; OWASP ASVS 5.0 — Authentication',
		),
		array(
			'id'        => 'secure_cookies',
			'label'     => 'Secure session cookies',
			'weight'    => 10,
			'subtab'    => 'access',
			'anchor'    => '',
			'runtimes'  => array( self::RUNTIME_LARAVEL ),
			'reference' => 'RFC 6265 (Cookies); OWASP Secure Headers Project',
		),

		// ── Audit ──────────────────────────────────────────────────────────
		array(
			'id'        => 'audit_log_on',
			'label'     => 'Security audit log enabled',
			'weight'    => 8,
			'subtab'    => 'audit',
			'anchor'    => 'enable_security_audit_log',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP ASVS 5.0 — Logging and Error Handling',
		),
		array(
			'id'        => 'audit_log_retention',
			'label'     => 'Audit log retention ≥ 30 days',
			'weight'    => 2,
			'subtab'    => 'audit',
			'anchor'    => 'audit_log_retention_days',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP ASVS 5.0 — Logging and Error Handling',
		),

		// ── AI safety ──────────────────────────────────────────────────────
		array(
			'id'        => 'prompt_injection_detector',
			'label'     => 'Prompt-injection detector enabled',
			'weight'    => 7,
			'subtab'    => 'ai_safety',
			'anchor'    => 'enable_prompt_injection_detector',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP Top 10 for LLM Applications — LLM01 Prompt Injection',
		),
		array(
			'id'        => 'pii_filter',
			'label'     => 'PII filter enabled',
			'weight'    => 5,
			'subtab'    => 'ai_safety',
			'anchor'    => 'enable_pii_filter',
			'runtimes'  => array( self::RUNTIME_WORDPRESS, self::RUNTIME_LARAVEL ),
			'reference' => 'OWASP Top 10 for LLM Applications — LLM02 Sensitive Information Disclosure',
		),
		array(
			'id'        => 'provider_keys',
			'label'     => 'Provider credentials present',
			'weight'    => 15,
			'subtab'    => 'ai_safety',
			'anchor'    => '',
			'runtimes'  => array( self::RUNTIME_LARAVEL ),
			'reference' => '',
		),
		array(
			'id'        => 'memory_controls',
			'label'     => 'Memory controls available',
			'weight'    => 10,
			'subtab'    => 'ai_safety',
			'anchor'    => '',
			'runtimes'  => array( self::RUNTIME_LARAVEL ),
			'reference' => '',
		),
	);

	/**
	 * Every definition, in display order.
	 *
	 * @return array<int, SecuritySignalDefinition>
	 */
	public static function all(): array
    {
		return array_map(
			static fn (array $definition): SecuritySignalDefinition => new SecuritySignalDefinition(
				id: $definition['id'],
				label: $definition['label'],
				weight: $definition['weight'],
				subtab: $definition['subtab'],
				anchor: $definition['anchor'],
				runtimes: $definition['runtimes'],
				reference: $definition['reference'],
			),
			self::DEFINITIONS
		);
	}

	/**
	 * Definitions evaluable on one runtime.
	 *
	 * @return array<int, SecuritySignalDefinition>
	 */
	public static function forRuntime(string $runtime): array
    {
		return array_values(
			array_filter(
				self::all(),
				static fn (SecuritySignalDefinition $definition): bool => $definition->appliesTo($runtime)
			)
		);
	}

	/**
	 * Definitions that exist on a runtime but not on another.
	 *
	 * @return array<int, SecuritySignalDefinition>
	 */
	public static function onlyOn(string $runtime): array
    {
		return array_values(
			array_filter(
				self::all(),
				static fn (SecuritySignalDefinition $definition): bool =>
					1 === count($definition->runtimes) && $definition->appliesTo($runtime)
			)
		);
	}

	public static function get(string $id): ?SecuritySignalDefinition
    {
		foreach (self::all() as $definition) {
			if ($definition->id === $id) {
				return $definition;
			}
		}

		return null;
	}
}
