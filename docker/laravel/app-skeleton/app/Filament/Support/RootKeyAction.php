<?php

namespace App\Filament\Support;

use App\Support\Security\RootKeyGate;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Notifications\Notification;

/**
 * Ask for the root security key inside the action that needs it — Proposal 149 §189.
 *
 * §188 gated operator *settings* writes. The field the control comes from is
 * broader than that: it promises a "second password for high-risk actions", and
 * the panel has eight actions that destroy or downgrade something without going
 * anywhere near `SettingsService`. Those are exactly the actions the field
 * describes.
 *
 * ## Why the modal, not a refusal
 *
 * A guard that only refuses would make the operator hunt for an unlock button and
 * then repeat the click. OWASP ASVS 6.4 asks for re-authentication *at the point
 * of* a sensitive operation, and the action's own confirmation modal is that
 * point. The key field is added there, so the flow is one click, one key, done.
 *
 * ## Why `before()` and `halt()`
 *
 * Filament evaluates `before` after the modal validates and before the action's
 * own closure runs. `halt()` aborts without performing the work, and it aborts
 * the same way for a crafted Livewire call as for a real click — the delete never
 * happens because the closure it lives in is never reached. A check inside a page
 * method would not cover `DeleteAction`, whose work is registered by the action
 * itself.
 *
 * ## The rule this helper depends on
 *
 * `schema()` **replaces** an action's modal components. When an action already
 * has fields, they must be passed in `$fields` or they disappear while the gate is
 * armed — a rotation modal that silently loses its "Valid for" selector is a
 * worse bug than the one this guard fixes. `Credentials::rotationFields()` is the
 * one place that matters today, and it is shared by both call sites for that
 * reason.
 *
 * ## When nothing happens
 *
 * The gate switched off, or this session already proved possession within
 * `RootKeyGate::TTL_MINUTES`, returns the action untouched: no field, no
 * `before`, no prompt. Step-up means "prove it again", not "prove it always".
 */
final class RootKeyAction
{
    /**
     * Add a root-key challenge to a destructive action when the gate demands one.
     *
     * @param Action            $action The action to protect.
     * @param array<int, mixed> $fields The action's own modal fields, if it has any.
     */
    public static function guard(Action $action, array $fields = array()): Action
    {
        $gate = app(RootKeyGate::class);

        if (! $gate->required() || $gate->unlocked(request())) {
            return $action;
        }

        return $action
            ->schema(array_merge($fields, array(
                TextInput::make(RootKeyGate::ENTRY_FIELD)
                    ->label('Root security key')
                    ->password()
                    ->autocomplete('off')
                    ->required()
                    ->helperText(sprintf(
                        'Operator actions need the root key. Verifying it here unlocks the panel for %d minutes.',
                        RootKeyGate::TTL_MINUTES
                    )),
            )))
            ->before(static function (Action $action) use ($gate): void {
                $candidate = (string) ($action->getData()[RootKeyGate::ENTRY_FIELD] ?? '');

                if ($gate->attempt(request(), $candidate)) {
                    return;
                }

                Notification::make()
                    ->danger()
                    ->title('Root key rejected')
                    ->body('The attempt has been recorded and the action was not performed.')
                    ->send();

                $action->halt();
            });
    }
}
