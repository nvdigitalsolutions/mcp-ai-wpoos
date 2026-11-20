<?php

namespace App\Support\Auth;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * One estate's email-domain allowlist — Proposal 149 §110, decision 158 D3.
 *
 * The allowlist answers exactly one question: *may a just-in-time login from this
 * address provision a membership here?* It is not an email filter and not a
 * brand claim; it is the trust boundary that 156 D4 said provisioning must have.
 *
 * ## Four rules, each closing a specific bypass
 *
 * 1. **Normalise before storing and before comparing.** Lowercase, then IDN to
 *    A-label (punycode) where `intl` is available. One domain therefore has one
 *    spelling: `München.de` and `xn--mnchen-3ya.de` cannot both be added, and a
 *    mixed-case assertion cannot slip past a lowercase store.
 * 2. **Exact match on the domain after the last `@`.** Never a substring, never a
 *    suffix — `notacme.com` must not satisfy `acme.com`, which is the classic
 *    lookalike-address auto-join the JIT audit literature names as the primary
 *    failure mode.
 * 3. **No subdomain inheritance by default.** A claim on `acme.com` does not
 *    cover `eng.acme.com`. Inheriting hands routing authority to anyone in the
 *    organisation who can create a subdomain, so 158 D3 makes it an explicit
 *    per-estate flag — `enable_jit_subdomain_inheritance`, default off. When an
 *    estate turns it on, a **verified** parent domain admits its subdomains;
 *    see {@see self::parents()}.
 * 4. **`verified_at` gates.** An unverified row is a proposal, not a permission.
 *    It is stored (so an operator can see what was asked for) and ignored by
 *    {@see self::allows()}.
 */
final class EstateDomains
{
    /** Longest legal DNS name, and the column width. */
    public const MAX_DOMAIN = 255;

    /**
     * Whether an address's domain is on this estate's *verified* allowlist.
     *
     * `$includeSubdomains` is the estate's `enable_jit_subdomain_inheritance`
     * flag, passed in rather than read here: this class has no settings reader
     * on purpose, because it is constructed in tests and on the auth path alike.
     *
     * Inheritance is expressed as a set of **exact** comparisons over the
     * address's parent domains, never as a suffix match and never as `LIKE`. A
     * pattern match is the lookalike bypass this class exists to refuse —
     * `notacme.com` satisfies `%.acme.com` — and `LIKE` also needs a
     * dialect-specific `ESCAPE`, which SQLite and MySQL disagree about.
     */
    public function allows(string $tenantId, string $email, bool $includeSubdomains = false): bool
    {
        if (! Schema::hasTable('nvoos_estate_domains')) {
            return false;
        }

        $domain = self::domainOf($email);

        if ('' === $domain) {
            return false;
        }

        $candidates = array($domain);

        if ($includeSubdomains) {
            $candidates = array_merge($candidates, self::parents($domain));
        }

        return DB::table('nvoos_estate_domains')
            ->where('tenant_id', $tenantId)
            ->whereIn('domain', $candidates)
            ->whereNotNull('verified_at')
            ->exists();
    }

    /**
     * Every parent of a domain, nearest first, stopping at two labels.
     *
     * `eng.acme.com` yields `acme.com`; `a.b.acme.com` yields `b.acme.com` and
     * `acme.com`. The walk stops at two labels because a domain needs at least
     * two to be stored at all ({@see self::normalise()}), so a bare TLD can
     * never be a candidate — inheriting `com` would admit everyone.
     *
     * @return list<string>
     */
    private static function parents(string $domain): array
    {
        $labels = explode('.', $domain);
        $parents = array();

        while (count($labels) > 2) {
            array_shift($labels);
            $parents[] = implode('.', $labels);
        }

        return $parents;
    }

    /**
     * Add a domain for an estate.
     *
     * A row added by an operator starts **unverified**: the operator is asserting
     * the domain, and {@see self::setVerified()} is the separate act that makes it
     * binding. That split is what lets a multi-person estate review a proposal
     * without the proposer being able to provision themselves.
     *
     * @return array{ok: bool, error: ?string, domain: ?string}
     */
    public function add(string $tenantId, mixed $domain, ?int $createdBy = null): array
    {
        if (! Schema::hasTable('nvoos_estate_domains')) {
            return array('ok' => false, 'error' => 'The allowlist table is not installed.', 'domain' => null);
        }

        $normalised = self::normalise($domain);

        if ('' === $normalised) {
            return array('ok' => false, 'error' => 'That is not a usable domain name.', 'domain' => null);
        }

        $exists = DB::table('nvoos_estate_domains')
            ->where('tenant_id', $tenantId)
            ->where('domain', $normalised)
            ->exists();

        if ($exists) {
            return array('ok' => false, 'error' => 'That domain is already on the list.', 'domain' => $normalised);
        }

        DB::table('nvoos_estate_domains')->insert(array(
            'tenant_id' => $tenantId,
            'domain' => $normalised,
            'verified_at' => null,
            'created_by' => $createdBy,
            'created_at' => now(),
            'updated_at' => now(),
        ));

        return array('ok' => true, 'error' => null, 'domain' => $normalised);
    }

    /**
     * Mark a domain verified, or withdraw the mark. Scoped by estate.
     */
    public function setVerified(string $tenantId, int $id, bool $verified): bool
    {
        if (! Schema::hasTable('nvoos_estate_domains')) {
            return false;
        }

        return 1 === DB::table('nvoos_estate_domains')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->update(array(
                'verified_at' => $verified ? now() : null,
                'updated_at' => now(),
            ));
    }

    /**
     * Remove a domain. Scoped by estate, so a foreign id deletes nothing.
     */
    public function remove(string $tenantId, int $id): bool
    {
        if (! Schema::hasTable('nvoos_estate_domains')) {
            return false;
        }

        return 1 === DB::table('nvoos_estate_domains')
            ->where('tenant_id', $tenantId)
            ->where('id', $id)
            ->delete();
    }

    /**
     * This estate's rows, newest first.
     *
     * @return list<array{id: int, domain: string, verified: bool, created_at: string}>
     */
    public function rows(string $tenantId): array
    {
        if (! Schema::hasTable('nvoos_estate_domains')) {
            return array();
        }

        $rows = DB::table('nvoos_estate_domains')
            ->where('tenant_id', $tenantId)
            ->orderByDesc('id')
            ->get(array('id', 'domain', 'verified_at', 'created_at'));

        return array_map(
            static fn (object $row): array => array(
                'id' => (int) $row->id,
                'domain' => (string) $row->domain,
                'verified' => null !== $row->verified_at,
                'created_at' => (string) ($row->created_at ?? ''),
            ),
            $rows->all(),
        );
    }

    /**
     * The domain part of an address, normalised — or `''` when there is none.
     *
     * `strrchr` because an address may contain `@` in its local part only when
     * quoted, which this runtime rejects at the IdP anyway; taking the last `@`
     * is the same rule every mailer applies.
     */
    public static function domainOf(string $email): string
    {
        $email = trim($email);
        $at = strrpos($email, '@');

        if (false === $at) {
            return '';
        }

        return self::normalise(substr($email, $at + 1));
    }

    /**
     * Lowercase A-label form, or `''` when the input cannot be a domain.
     *
     * `idn_to_ascii()` is used when available. When it is not, the lowercase form
     * is stored instead — which is still single-spelling for ASCII domains, and
     * the reason this class does not pretend to detect homographs: a Unicode
     * label stored verbatim would compare equal only to the same bytes, so it
     * cannot widen access, only fail to match. Refusing outright would be safer
     * still, and is what happens when `intl` reports the label malformed.
     */
    public static function normalise(mixed $raw): string
    {
        $domain = strtolower(trim((string) $raw));

        // People paste "@acme.com" from a signature, and an FQDN may arrive with
        // a trailing dot. Both mean the same domain.
        $domain = ltrim($domain, '@');
        $domain = rtrim($domain, '.');

        if ('' === $domain || strlen($domain) > self::MAX_DOMAIN) {
            return '';
        }

        // A domain has at least two labels, and neither address punctuation nor
        // a path may appear in one.
        if (! str_contains($domain, '.') || str_contains($domain, '@') || str_contains($domain, '/') || str_contains($domain, ' ')) {
            return '';
        }

        // A bare IPv4 literal is not an email domain.
        if (false !== filter_var($domain, FILTER_VALIDATE_IP)) {
            return '';
        }

        if (function_exists('idn_to_ascii') && defined('INTL_IDNA_VARIANT_UTS46')) {
            $ascii = idn_to_ascii($domain, IDNA_DEFAULT, INTL_IDNA_VARIANT_UTS46);

            if (false === $ascii || '' === $ascii) {
                return '';
            }

            return strtolower($ascii);
        }

        return $domain;
    }
}
