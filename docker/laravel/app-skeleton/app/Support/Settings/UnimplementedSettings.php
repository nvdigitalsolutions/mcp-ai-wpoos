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
 * remains here is unbuilt but real: four keys.)
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
     * Three different situations share one state, which is why the reason is
     * per-key rather than a single blanket sentence:
     *
     *  - **unbuilt behaviour** — Auth0 verification (I3) and magic-link (I4)
     *    are ordered slices, not omissions;
     *  - **absent software** — the Simple JWT Login and WordPress.com/Gravatar
     *    bridges configure WordPress plugins and identities this runtime does
     *    not have;
     *  - **re-homed intent** — the six REST/tool policies are API policy rather
     *    than identity, and one of them (destructive-op confirmation) is already
     *    satisfied by the approvals queue under a different name.
     *
     * @var array<string, string>
     */
    public const REASONS = array(
        // The assistant REST gates. Deliberately still here rather than hidden:
        // they DO have a counterpart (`/api/v1/assistants`), but honouring them
        // means telling a first-party console call from a third-party token call
        // on the same routes. The WordPress plugin gets that for free because
        // its console does not use its REST API; this one does. That is a design
        // decision, not an absence — see Proposal 149 \u00a7104.
        'rest_enable_assistant_list'           => 'Needs a first-party vs token-call split on /api/v1/assistants.',
        'rest_enable_assistant_create'         => 'Needs a first-party vs token-call split on /api/v1/assistants.',
        'rest_enable_assistant_delete'         => 'Needs a first-party vs token-call split on /api/v1/assistants.',

        // Guest access has no counterpart here yet — the console is an
        // authenticated surface, so there is no anonymous chat to bound.
        'guest_token_lifetime'                 => 'No guest-token path exists in this runtime.',
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
