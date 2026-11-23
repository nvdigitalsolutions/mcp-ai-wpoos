<?php

namespace App\Support\Settings;

/**
 * The settings this runtime renders but does not implement — Proposal 156 §6 (I2).
 *
 * All the keys below reach `/admin/settings` in the Authentication tab and are
 * tenant scope, so `forSurface('admin')` shows them. None is read by any code in
 * `app/`, `config/`, `routes/`, `database/` or `resources/views/`: a saved value
 * lands in a settings row that nothing consults. (The list was nineteen keys when
 * slice I2 shipped. I3 made the Auth0 trio real (§102), I4 made magic-link real
 * (§103), and §104 moved the ten keys that configure absent software to
 * `InapplicableSettings`, where they are hidden rather than disabled. What
 * remains here is unbuilt but real: one key.)
 *
 * That is the failure `SettingsSchema` calls a UI fiction — "conflating what a
 * surface shows with what it does". This class is the fix at the boundary: the
 * keys still render (so an administrator can see that the capability exists
 * upstream, which is the S-1 reasoning), they render *disabled*, they are
 * excluded from `writableFor('admin')` so a crafted Livewire payload cannot
 * write them either, and each one names why it is inert.
 *
 * WHY A RUNTIME LIST AND NOT A CATALOGUE FIELD
 *
 * `resources/settings/catalogue.json` is generated from the WordPress plugin's
 * own section classes. The plugin cannot know what this runtime implements, and
 * a marker written into the catalogue would be erased by the next regeneration.
 * So the list lives here, in code, next to the behaviour it describes.
 *
 * THE TEST THAT KEEPS IT HONEST
 *
 * `tests/Feature/UnimplementedSettingsTest.php` greps the runtime for every key
 * below and fails if one appears. Implementing a key is therefore a two-part
 * act: add the reader, remove the entry. The list cannot rot into a stale claim
 * that something is unimplemented after it has shipped.
 *
 * Each reason is rendered as the field's helper text, because "disabled" without
 * "why" is the question the administrator is left holding.
 *
 * @see \App\Support\SettingsSchema::writableFor()
 * @see \App\Filament\Concerns\BuildsSettingsForm::component()
 */
final class UnimplementedSettings
{
    /**
     * The shared first sentence. Reasons are appended to it.
     */
    public const HINT = 'Not implemented in this runtime — nothing reads this value.';

    /**
     * Every key that renders but does nothing, and why.
     *
     * Three situations remain, and the reason is per-key rather than a blanket
     * sentence because they are not the same shape:
     *
     *  - **absent capability** — guest access has no counterpart in this runtime,
     *    so `guest_token_lifetime` has no behaviour to bound and
     *    `allow_guest_access` has no surface to open.
     *  - **absent mechanism** — `enable_2fa_requirement` asks for a second factor
     *    this runtime does not have. §183 added it after the coverage sweep found
     *    it writable and read by nothing: OWASP ASVS 5.0 (V6.3.3) makes MFA a
     *    requirement at L2, so a toggle that reports compliance while enforcing
     *    nothing is worse than no toggle — it is the false assurance the class
     *    exists to prevent. Where Auth0 is the identity provider, MFA is that
     *    provider's policy and a local switch would be a second truth.
     *
     * Earlier slices retired the rest: Auth0 verification (I3), magic-link (I4),
     * the ten absent-software keys (149 §104), and — in Proposal 159 slice 2 —
     * the three assistant REST gates, which now have a reader in
     * `EnsureAssistantRestEnabled`.
     *
     * @var array<string, string>
     */
    public const REASONS = array(
        // Guest access has no counterpart here yet — the console is an
        // authenticated surface, so there is no anonymous chat to bound.
        'guest_token_lifetime'                 => 'No guest-token path exists in this runtime.',
        'allow_guest_access'                   => 'No guest surface exists here; the console and its API are authenticated.',
        // No second factor is enrolled or verified by this runtime.
        'enable_2fa_requirement'               => 'No second factor exists to require; with Auth0, MFA is the provider\'s policy.',
    );

    /**
     * The inert keys, in catalogue order is irrelevant — callers filter.
     *
     * @return list<string>
     */
    public static function keys(): array
    {
        return array_keys(self::REASONS);
    }

    public static function isInert(string $key): bool
    {
        return array_key_exists($key, self::REASONS);
    }

    /**
     * The helper text for a key: the shared sentence plus its reason.
     */
    public static function hintFor(string $key): string
    {
        $reason = self::REASONS[ $key ] ?? '';

        return '' === $reason ? self::HINT : self::HINT.' '.$reason;
    }
}
