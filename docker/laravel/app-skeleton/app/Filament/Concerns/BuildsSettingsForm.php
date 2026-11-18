<?php

namespace App\Filament\Concerns;

use App\Support\Admin\AdminAuditLogger;
use App\Support\Settings\SettingsService;
use App\Support\Settings\SettingsText;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Notifications\Notification;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;

/**
 * Builds a settings form from `SettingsSchema` — Proposal 149 §17.
 *
 * The form is generated, never hand-written. A hand-written form drifts the
 * first time a field is added, and the drift is invisible: the field silently
 * stops being editable on one of the two panels. Generating from the catalogue
 * means "add a field" is one edit, and the surface projections
 * (`forSurface()` / `writableFor()`) apply to the form for free.
 *
 * TWO PRESENTATIONS, ONE RULEBOOK:
 *
 * - `/admin` renders the estate's full catalogue. Operator-scope fields are
 *   rendered DISABLED with a hint (S-1) rather than omitted, so an administrator
 *   can see that the setting exists and who owns it. Disabled is not a security
 *   control — the component sets `dehydrated(false)` and `SettingsService`
 *   refuses the key anyway, because a crafted Livewire payload never passes
 *   through a component.
 * - `/ops` renders operator-scope fields only. That panel has no estate
 *   selected, so a tenant field there would silently edit the `default` estate.
 *
 * Secrets are never hydrated into the form. A password input hides characters;
 * it does not remove the value from the HTML or from Livewire's snapshot. The
 * stored value is blanked after hydration and dehydrated only when something was
 * typed — the same rule `EstateResource` documents for the licence key.
 */
trait BuildsSettingsForm
{
    use InteractsWithForms;

    /**
     * The S-1 hint. Deliberately names the owner rather than saying "read-only",
     * because the administrator's next question is "then who do I ask?".
     */
    public const OPERATOR_SCOPE_HINT = 'Managed by NV Digital — contact support to change this.';

    /** The Livewire state bucket the form binds to. */
    public ?array $data = [];

    /**
     * Which surface this page presents — `SettingsService::SURFACE_ADMIN` or
     * `SURFACE_OPS`.
     */
    abstract protected function settingsSurface(): string;

    public function mount(): void
    {
        $this->form->fill($this->settings()->values($this->settingsSurface()));
    }

    public function form(Schema $schema): Schema
    {
        $tabs = [];

        foreach (SettingsSchema::tabsForSurface($this->settingsSurface()) as $tabId => $tabLabel) {
            $tabs[] = Tab::make($tabLabel)->schema($this->sectionsFor($tabId));
        }

        return $schema
            ->components([
                Tabs::make('Settings')->tabs($tabs),
            ])
            ->statePath('data');
    }

    /**
     * Save through the one writer.
     *
     * The count is reported rather than the values: a notification is a durable
     * record (cache-backed mailbox) and settings include provider credentials.
     */
    public function save(): void
    {
        $applied = $this->settings()->write(
            (array) $this->form->getState(),
            $this->settingsSurface()
        );

        $this->form->fill($this->settings()->values($this->settingsSurface()));

        $this->recordAudit($applied);

        Notification::make()
            ->success()
            ->title([] === $applied ? 'No changes' : 'Settings saved')
            ->body([] === $applied
                ? 'Nothing in that payload is writable on this surface.'
                : sprintf('%d setting(s) updated.', count($applied)))
            ->send();
    }

    /**
     * Group one tab's fields into sections, preserving catalogue order.
     *
     * @return list<Section>
     */
    private function sectionsFor(string $tabId): array
    {
        $sections = [];

        foreach (SettingsSchema::forSurface($this->settingsSurface()) as $field) {
            if ($tabId !== $field['tab']) {
                continue;
            }

            $sectionId = (string) $field['section_id'];

            $sections[$sectionId] ??= [
                'label' => SettingsText::fromHtml((string) $field['section']),
                'fields' => [],
            ];

            $sections[$sectionId]['fields'][] = $field;
        }

        return array_values(array_map(
            fn (array $section): Section => Section::make($section['label'])
                ->schema(array_map(
                    fn (array $field): Field => $this->component($field),
                    $section['fields']
                ))
                ->columns(2),
            $sections
        ));
    }

    /**
     * @param array<string, mixed> $field
     */
    private function component(array $field): Field
    {
        $component = match ($field['type']) {
            'select' => $this->selectField($field),
            'checkbox' => Toggle::make($field['key']),
            'number' => $this->numberField($field),
            'password' => TextInput::make($field['key'])
                ->password()
                ->revealable()
                ->afterStateHydrated(static function (TextInput $component): void {
                    $component->state(null);
                })
                ->dehydrated(static fn (?string $state): bool => filled($state)),
            'url' => TextInput::make($field['key'])->url(),
            'email' => TextInput::make($field['key'])->email(),
            'textarea' => Textarea::make($field['key'])->rows(3),
            default => TextInput::make($field['key']),
        };

        $component->label(SettingsText::fromHtml((string) $field['label']));

        if (! $this->isWritableHere($field)) {
            // Read-only ON THIS SURFACE, not read-only in the abstract: an
            // operator-scope field is the writable one on /ops and the managed
            // one on /admin. Deriving the state from the surface's write
            // projection is what keeps the two pages from needing two rules.
            $component
                ->disabled()
                ->dehydrated(false)
                ->helperText(self::OPERATOR_SCOPE_HINT);

            return $component;
        }

        if (isset($field['description'])) {
            $component->helperText(SettingsText::fromHtml((string) $field['description']));
        }

        return $component;
    }

    /**
     * A select whose current value is always one of its options.
     *
     * The catalogue's option list comes from the plugin, and a runtime can hold
     * a value that predates it — a retired model slug, for instance. A plain
     * `Select` would reject that value and make the whole form unsaveable, so an
     * unrelated field cannot be edited until someone fixes a setting they did
     * not touch. Carrying the stored value as an extra option keeps the control
     * honest in both directions: it shows what is stored, and it does not
     * pretend the value is still offered.
     *
     * @param array<string, mixed> $field
     */
    private function selectField(array $field): Select
    {
        $options = array_map(
            static fn ($label): string => SettingsText::fromHtml((string) $label),
            $field['options'] ?? array()
        );
        $current = $this->data[ $field['key'] ] ?? null;

        if (is_string($current) && '' !== $current && ! array_key_exists($current, $options)) {
            $options[ $current ] = $current.' (current value)';
        }

        return Select::make($field['key'])->options($options);
    }

    /**
     * @param array<string, mixed> $field
     */
    private function numberField(array $field): TextInput
    {
        $input = TextInput::make($field['key'])->numeric();

        if (isset($field['min'])) {
            $input->minValue($field['min']);
        }

        if (isset($field['max'])) {
            $input->maxValue($field['max']);
        }

        if (isset($field['step'])) {
            $input->step($field['step']);
        }

        return $input;
    }

    /**
     * Whether this surface's write projection accepts the field.
     *
     * @param array<string, mixed> $field
     */
    private function isWritableHere(array $field): bool
    {
        return in_array(
            $field['key'],
            array_column(SettingsSchema::writableFor($this->settingsSurface()), 'key'),
            true
        );
    }

    private function settings(): SettingsService
    {
        return app(SettingsService::class);
    }

    /**
     * Record the save. Keys only, never values: settings include provider
     * credentials and the audit table is durable.
     *
     * @param list<string> $applied
     */
    private function recordAudit(array $applied): void
    {
        if ([] === $applied) {
            return;
        }

        app(AdminAuditLogger::class)->recordResource(
            'settings',
            SettingsService::SURFACE_OPS === $this->settingsSurface()
                ? 'platform'
                : TenantContext::current(),
            'updated',
            array(),
            array('keys' => $applied)
        );
    }
}
