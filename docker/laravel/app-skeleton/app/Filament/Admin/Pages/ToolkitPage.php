<?php

namespace App\Filament\Admin\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\CapabilityMap;
use App\Support\Pro\ToolkitEngine;
use App\Support\Pro\ToolkitException;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\Textarea;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Pages\Page;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Schema;
use Nvoos\Core\Application\Toolkit\ToolkitResearchService;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\ValueObject\ToolkitManifest;

/**
 * One toolkit, rendered from its manifest — Proposal 149 Wave 3 (149 §46 P4).
 *
 * Route: `/admin/{estate}/toolkits/{toolkit}/{screen}`. The screen is a path
 * segment so every surface is deep-linkable; the entity is a query parameter
 * (`?entity=product`) because the entity set is manifest data, not a fixed
 * route shape.
 *
 * FIVE SCREENS, ALL FROM DATA:
 *
 * - **settings** — fields and tabs come from `ToolkitManifest::settingTabs()`.
 *   A manifest without `tab_labels` renders one "General" tab; one with them
 *   renders exactly what it declares. There is no hand-written form per family,
 *   which is the failure mode this engine exists to remove.
 * - **entities** — a table from the manifest's columns, with the same search
 *   and delete rules the console applies. Deletion is armed two-step and
 *   audited; cross-estate ids answer "not in this estate" because the engine's
 *   one `query()` helper scopes every read (149 §47).
 * - **research** — draft one record from a natural-language query through
 *   `ToolkitResearchService`, review the fields, then save. The save is a
 *   one-shot `createRecord`, not an editor: the engine's column validation
 *   stays the single gate into the table, which is why the free-form editor
 *   can remain console-side (§56.4).
 * - **consolidate** — group an entity by one text/list column through
 *   `ToolkitEngine::duplicates()` and delete the collisions. The scan runs
 *   server-side as one `GROUP BY`, so the panel never loads the whole entity
 *   to find duplicates; deletion re-scans so the list is never stale.
 * - **dashboard** — the manifest's cards, computed by `ToolkitEngine::dashboard()`.
 *
 * `research` and `consolidate` are entity-scoped: a manifest with no `entities`
 * cannot fill either screen, so both 404 rather than render an empty shell.
 * Wave 4 shipped them 2026-11-18; before that the panel advertised only three
 * screens and a link to either was a blank page.
 *
 * SUBSET, not full, and the contract row says so: the panel lists and deletes
 * records, while create/edit stay in the console's workbench. Rebuilding that
 * editor in Livewire would be the same form twice with a second place to keep
 * the column validation correct — the `RemoteSitesPage` precedent.
 *
 * Unlicensed and unknown toolkits both answer **404** (R4): an estate must not
 * learn which other families exist-but-are-unlicensed any more than it can read
 * another estate's records.
 */
class ToolkitPage extends Page implements HasForms
{
    use InteractsWithForms;

    /** Screens this renderer routes. Everything else stays console-only. */
    public const PANEL_SCREENS = array( 'settings', 'entities', 'research', 'consolidate', 'dashboard' );

    protected static ?string $slug = 'toolkits/{toolkit}/{screen}';

    protected static ?string $navigationLabel = 'Toolkits';

    protected static ?string $title = 'Toolkit';

    /**
     * The index page owns the nav entry; this page is reached from it.
     * Registering a parameterised slug in the sidebar would ask Filament for a
     * URL without any parameters.
     */
    protected static bool $shouldRegisterNavigation = false;

    protected string $view = 'filament.admin.toolkit';

    /** The settings form state bucket. */
    public ?array $data = [];

    public string $toolkit = '';

    public string $screen = 'settings';

    public string $entity = '';

    /** Entity search term, read from `?search=` on mount. */
    public string $search = '';

    /** Entity page, read from `?page=` on mount. */
    public int $recordPage = 1;

    /** Research query text (Wave 4). */
    public string $query = '';

    /**
     * Draft returned by the research service, keyed by manifest column.
     *
     * @var array<string, mixed>
     */
    public array $draft = array();

    /** Provider attribution for the current draft; empty when none. */
    public string $provider = '';

    /** Consolidate: the column duplicates are grouped by. */
    public string $duplicateColumn = '';

    /**
     * Consolidate: groups from the last scan.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $duplicateGroups = array();

    /** Consolidate: rows the last scan counted. */
    public int $duplicateScanned = 0;

    /** Consolidate: whether the last scan capped its group list. */
    public bool $duplicateTruncated = false;

    /** Consolidate: whether a scan has run since the page loaded. */
    public bool $duplicateScannedOnce = false;

    /** Record id armed for deletion; empty when nothing is armed. */
    public string $pendingDelete = '';

    /** Post-action status line; empty when nothing has happened this request. */
    public string $status = '';

    /** Tone for the status line: `info`, `success` or `danger`. */
    public string $statusTone = 'info';

    /**
     * Per-request caches. Private so Livewire never serialises them; both are
     * re-resolved lazily after a hydration.
     */
    private ?ToolkitManifest $manifestCache = null;

    /**
     * @var array{records: array<int, array<string, mixed>>, total: int, page: int, per_page: int}|null
     */
    private ?array $recordsCache = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(AdminAccessPolicy::class)->accessTenantPanel($user)) {
            return false;
        }

        return CapabilityMap::has($user, 'manage_options');
    }

    public function mount(string $toolkit, string $screen = 'settings'): void
    {
        $manifest = $this->manifest($toolkit);

        $this->toolkit = $manifest->slug();
        $this->screen = $this->resolveScreen($manifest, $screen);

        if ($this->screen === 'entities') {
            $this->entity = $this->resolveEntity(
                $manifest,
                (string) request()->query('entity', '')
            );
            $this->search = trim((string) request()->query('search', ''));
            $this->recordPage = max(1, (int) request()->query('page', 1));
        }

        // Both Wave 4 screens address one entity, so they share the resolver
        // (and its 404 when the manifest declares none).
        if ($this->screen === 'research' || $this->screen === 'consolidate') {
            $this->entity = $this->resolveEntity(
                $manifest,
                (string) request()->query('entity', '')
            );
        }

        if ($this->screen === 'consolidate') {
            $this->duplicateColumn = $this->defaultDuplicateColumn();
        }

        if ($this->screen === 'settings') {
            $this->form->fill(app(ToolkitEngine::class)->settingsValues($manifest));
        }
    }

    public function form(Schema $schema): Schema
    {
        $tabs = array();

        foreach ($this->manifest()->settingTabs() as $tab) {
            $tabs[] = Tab::make($tab['label'])->schema(array_map(
                fn (array $field): Field => $this->component($field),
                $tab['settings']
            ));
        }

        return $schema
            ->components(array(Tabs::make('Settings')->tabs($tabs)))
            ->statePath('data');
    }

    /**
     * Save through the engine — never a direct store write.
     *
     * The audit row carries **keys only**. The engine masks secret values on
     * both reads, so a changed secret is not even reported as a value change;
     * the action row is the record that a save happened. That is the same
     * "presence, never value" rule the credential pages follow.
     */
    public function save(): void
    {
        $manifest = $this->manifest();
        $engine = app(ToolkitEngine::class);

        $before = $engine->settingsValues($manifest);

        try {
            $engine->writeSettings($manifest, (array) $this->form->getState());
        } catch (ToolkitException $exception) {
            $this->report('danger', $exception->getMessage());

            return;
        }

        $after = $engine->settingsValues($manifest);
        $changed = array();

        foreach ($after as $key => $value) {
            if (($before[$key] ?? null) !== $value) {
                $changed[] = (string) $key;
            }
        }

        $this->form->fill($after);

        app(AdminAuditLogger::class)->recordResource(
            'toolkits',
            $manifest->slug().':settings',
            'updated',
            array(),
            array('fields' => $changed)
        );

        $this->report(
            'success',
            array() === $changed ? 'No changes.' : count($changed).' setting(s) saved.'
        );
    }

    // ── Wave 4: research ─────────────────────────────────────────────────────

    /**
     * Draft one record from the query, for review. Nothing is written here.
     *
     * The service filters the model's answer down to the manifest's own
     * columns, so a field the model invents never reaches the form.
     */
    public function draftWithAi(): void
    {
        $query = trim($this->query);

        if ('' === $query) {
            $this->report('danger', 'Describe what the record should contain.');

            return;
        }

        $manifest = $this->manifest();
        $config = $this->entityConfig();

        if (array() === $config) {
            abort(404);
        }

        $result = app(ToolkitResearchService::class)->draft($manifest, $config, $query);

        if (null !== $result['error']) {
            $this->report('danger', (string) $result['message']);

            return;
        }

        $this->draft = (array) $result['draft'];
        $this->provider = (string) $result['provider'];

        $this->report('success', count($this->draft).' field(s) drafted. Review before saving.');
    }

    /**
     * Save the reviewed draft through the engine.
     *
     * Not an editor: `createRecord` applies the manifest's column validation,
     * so the panel cannot become a second, laxer gate into the record table.
     * That distinction is what keeps the free-form editor console-side (§56.4).
     */
    public function saveDraft(): void
    {
        if (array() === $this->draft) {
            return;
        }

        $manifest = $this->manifest();

        try {
            $created = app(ToolkitEngine::class)->createRecord($manifest, $this->entity, $this->draft);
        } catch (ToolkitException $exception) {
            $this->report('danger', $exception->getMessage());

            return;
        }

        app(AdminAuditLogger::class)->recordResource(
            'toolkits',
            $manifest->slug().':'.$this->entity.':'.(string) ( $created['id'] ?? '' ),
            'created',
            array(),
            array('fields' => array_keys($this->draft))
        );

        $fields = count($this->draft);

        $this->draft = array();
        $this->provider = '';
        $this->query = '';

        $this->report('success', 'Record created from '.$fields.' drafted field(s).');
    }

    /** Throw the draft away without writing anything. */
    public function discardDraft(): void
    {
        $this->draft = array();
        $this->provider = '';

        $this->report('info', 'Draft discarded.');
    }

    /**
     * Manifest columns the draft actually filled, in declared order.
     *
     * @return list<array<string, mixed>>
     */
    public function draftColumns(): array
    {
        $config = $this->entityConfig();
        $filled = array();

        foreach ((array) ( $config['columns'] ?? array() ) as $column) {
            $key = (string) ( $column['key'] ?? '' );

            if ('' !== $key && array_key_exists($key, $this->draft)) {
                $filled[] = $column;
            }
        }

        return $filled;
    }

    // ── Wave 4: consolidate ──────────────────────────────────────────────────

    /**
     * Columns the duplicate scan can group by.
     *
     * Text and enum only, because the engine groups with `GROUP BY
     * json_extract(...)` and a numeric or boolean group would be a different
     * question (ranges vs exact values) than "the same value twice".
     *
     * @return list<array<string, mixed>>
     */
    public function duplicateColumns(): array
    {
        $config = $this->entityConfig();
        $groupable = array();

        foreach ((array) ( $config['columns'] ?? array() ) as $column) {
            if (in_array((string) ( $column['type'] ?? '' ), array('string', 'enum'), true)) {
                $groupable[] = $column;
            }
        }

        return $groupable;
    }

    /** Group the whole entity by the chosen column. */
    public function scanDuplicates(): void
    {
        if (null === $this->scan(trim($this->duplicateColumn))) {
            return;
        }

        $this->report(
            'info',
            array() === $this->duplicateGroups
                ? 'No duplicates found in '.number_format($this->duplicateScanned).' record(s).'
                : count($this->duplicateGroups).' group(s) collide across '.number_format($this->duplicateScanned).' record(s).'
        );
    }

    /**
     * Run the scan and store the result, or report why it could not run.
     *
     * Split from `scanDuplicates()` so a post-delete refresh can reuse the
     * scan without overwriting the success message with an info one.
     *
     * @return array<string, mixed>|null
     */
    private function scan(string $column): ?array
    {
        if ('' === $column) {
            $this->report('danger', 'Choose a column to group by.');

            return null;
        }

        $manifest = $this->manifest();

        try {
            $result = app(ToolkitEngine::class)->duplicates($manifest, $this->entity, $column);
        } catch (ToolkitException $exception) {
            $this->report('danger', $exception->getMessage());

            return null;
        }

        $this->duplicateGroups = (array) $result['groups'];
        $this->duplicateScanned = (int) $result['scanned'];
        $this->duplicateTruncated = (bool) $result['truncated'];
        $this->duplicateScannedOnce = true;

        return $result;
    }

    /** First groupable column, so the screen has a usable default. */
    private function defaultDuplicateColumn(): string
    {
        $columns = $this->duplicateColumns();

        return array() === $columns ? '' : (string) $columns[0]['key'];
    }

    /**
     * One entity's manifest config.
     *
     * @return array<string, mixed>
     */
    public function entityConfig(): array
    {
        return $this->manifest()->entity($this->entity) ?? array();
    }

    /**
     * The catalogue projection of this page's manifest.
     *
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return app(ToolkitEngine::class)->summary($this->manifest());
    }

    /**
     * Panel-routed screens this manifest declares, in canonical order.
     *
     * A manifest may declare `research`/`consolidate` (the console serves
     * them); the panel's nav must only offer the screens it actually routes.
     *
     * @return list<string>
     */
    public function screens(): array
    {
        $declared = $this->manifest()->screens();

        return array_values(array_filter(
            self::PANEL_SCREENS,
            static fn (string $screen): bool => in_array($screen, $declared, true)
        ));
    }

    /**
     * The URL for one screen of this toolkit.
     *
     * @param array<string, mixed> $extra Query parameters (`entity`, `search`, `page`).
     */
    public function screenUrl(string $screen, array $extra = array()): string
    {
        return static::getUrl(array_merge(
            array('toolkit' => $this->toolkit, 'screen' => $screen),
            $extra
        ));
    }

    /**
     * The URL for one entity, with optional search/page state.
     *
     * @param array<string, mixed> $extra
     */
    public function entityUrl(string $entity, array $extra = array()): string
    {
        return $this->screenUrl('entities', array_merge(array('entity' => $entity), $extra));
    }

    /**
     * The current page of records, cached for the request.
     *
     * @return array{records: array<int, array<string, mixed>>, total: int, page: int, per_page: int}
     */
    public function records(): array
    {
        if ($this->recordsCache !== null) {
            return $this->recordsCache;
        }

        if ($this->screen !== 'entities' || '' === $this->entity) {
            return $this->recordsCache = array(
                'records' => array(),
                'total' => 0,
                'page' => 1,
                'per_page' => 25,
            );
        }

        return $this->recordsCache = app(ToolkitEngine::class)->listRecords(
            $this->manifest(),
            $this->entity,
            25,
            $this->recordPage,
            $this->search
        );
    }

    /**
     * The manifest's dashboard cards for the resolved estate.
     *
     * @return list<array<string, mixed>>
     */
    public function cards(): array
    {
        if ($this->screen !== 'dashboard') {
            return array();
        }

        return app(ToolkitEngine::class)->dashboard($this->manifest());
    }

    /**
     * Arm one record for deletion. The second click removes it.
     */
    public function armDelete(string $id): void
    {
        $this->pendingDelete = $id;
    }

    public function cancelDelete(): void
    {
        $this->pendingDelete = '';
    }

    /**
     * Delete the armed record through the engine.
     *
     * A foreign or already-deleted id answers "not in this estate" rather than
     * an error: the engine's scoped `findRow()` returns null, which is the same
     * answer as the REST surface's 404.
     */
    public function deleteRecord(): void
    {
        $id = trim($this->pendingDelete);
        $this->pendingDelete = '';

        if ('' === $id) {
            return;
        }

        $manifest = $this->manifest();

        $deleted = app(ToolkitEngine::class)->deleteRecord($manifest, $this->entity, $id);

        if (! $deleted) {
            $this->report('danger', 'That record is not in this estate; nothing was deleted.');

            return;
        }

        app(AdminAuditLogger::class)->recordResource(
            'toolkits',
            $manifest->slug().':'.$this->entity.':'.$id,
            'deleted',
            array(),
            array()
        );

        $this->report('success', 'Record deleted.');

        // The consolidate list is a snapshot of a scan; deleting a record
        // invalidates it, so re-scan rather than leave a stale group on screen.
        if ('consolidate' === $this->screen && '' !== trim($this->duplicateColumn)) {
            $this->scan(trim($this->duplicateColumn));
        }
    }

    /**
     * Resolve the manifest for the route, or 404.
     *
     * Cached per request: `form()`, `records()` and `cards()` all need it, and
     * re-reading the loader for each would be three filesystem passes.
     */
    private function manifest(?string $slug = null): ToolkitManifest
    {
        if ($this->manifestCache !== null) {
            return $this->manifestCache;
        }

        $manifest = app(ToolkitEngine::class)->find($slug ?? $this->toolkit);

        // Unknown and unlicensed are the same answer (R4). A 403 would confirm
        // that a family exists for some other estate or plan.
        abort_if(
            $manifest === null || ! app(ProEntitlementInterface::class)->allows($manifest->feature()),
            404
        );

        return $this->manifestCache = $manifest;
    }

    /**
     * Validate the screen segment against the closed panel set and the manifest.
     */
    private function resolveScreen(ToolkitManifest $manifest, string $screen): string
    {
        $screen = strtolower(trim($screen));

        abort_unless(in_array($screen, self::PANEL_SCREENS, true), 404);
        abort_unless(in_array($screen, $manifest->screens(), true), 404);

        // A settings screen with no fields would render an empty form; the
        // manifest declared a screen it cannot fill, so treat it as absent.
        if ('settings' === $screen) {
            abort_if(array() === $manifest->settings(), 404);
        }

        // research/consolidate address an entity, so a settings-only manifest
        // cannot fill them. 404 rather than an empty wizard.
        if ('research' === $screen || 'consolidate' === $screen) {
            abort_if(array() === $manifest->entities(), 404);
        }

        return $screen;
    }

    /**
     * Resolve the entity query parameter, defaulting to the first declared one.
     */
    private function resolveEntity(ToolkitManifest $manifest, string $requested): string
    {
        $entities = $manifest->entities();

        abort_if(array() === $entities, 404);

        if ('' !== $requested) {
            abort_if($manifest->entity($requested) === null, 404);

            return $requested;
        }

        return (string) $entities[0]['key'];
    }

    /**
     * Build one form component from a manifest setting field.
     *
     * @param array<string, mixed> $field
     */
    private function component(array $field): Field
    {
        $key = (string) $field['key'];
        $type = (string) ($field['type'] ?? 'text');

        $component = match ($type) {
            'select' => Select::make($key)->options((array) ($field['options'] ?? array())),
            'boolean' => Toggle::make($key),
            'number' => TextInput::make($key)->numeric(),
            'textarea' => Textarea::make($key)->rows((int) ($field['rows'] ?? 4)),
            'secret' => TextInput::make($key)
                ->password()
                ->revealable()
                // A password input hides characters; it does not remove the
                // value from the Livewire snapshot. Hydrate empty and write
                // only a typed value, so the stored secret never travels to
                // the browser — the same rule `BuildsSettingsForm` documents.
                ->afterStateHydrated(static function (TextInput $component): void {
                    $component->state(null);
                })
                ->dehydrated(static fn (?string $state): bool => filled($state)),
            default => TextInput::make($key),
        };

        $component->label((string) $field['label']);

        if ('secret' === $type) {
            $component->helperText('Leave blank to keep the stored value.');

            return $component;
        }

        if (isset($field['description'])) {
            $component->helperText((string) $field['description']);
        }

        return $component;
    }

    private function report(string $tone, string $message): void
    {
        $this->statusTone = $tone;
        $this->status = $message;
    }
}
