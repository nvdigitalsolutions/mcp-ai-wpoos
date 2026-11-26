<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\Assistant;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AdminAuditLogger;
use App\Support\CapabilityMap;
use App\Support\McpAppsStore;
use BackedEnum;
use UnitEnum;
use Filament\Facades\Filament;
use Filament\Forms\Components\Repeater;
use Filament\Forms\Components\Select;
use Filament\Forms\Components\TextInput;
use Filament\Forms\Components\Toggle;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Notifications\Notification;
use Filament\Pages\Page;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Schema;
use Filament\Support\Icons\Heroicon;
use InvalidArgumentException;
use Livewire\Attributes\Url;

/**
 * Assistant MCP apps — Proposal 149 §43, `/admin/{estate}/mcp-apps`.
 *
 * The console has owned this surface since 090-i (`mcpApps`): `McpAppsClient`
 * reads and **replaces the whole list** per assistant. The panel does the same,
 * which is why this is a `full` surface rather than a read-only projection — a
 * bulk editor is the natural shape for a list, and `McpAppsStore` is built for
 * it (whitelist, cap, token carry-over).
 *
 * WHAT THE PANEL ADDS OVER THE CONSOLE: the console's editor never sends a
 * token (`toWritableApp()` strips it), so a connection created in the console
 * cannot be given one there. This form has a write-only token field, so an
 * estate administrator can paste a bearer token without leaving the panel. It is
 * never hydrated: `read()` projects a uniform mask, and `loadForm()` removes the
 * `token` key before filling, so the field starts empty and a blank save keeps
 * the sealed value.
 *
 * THE TWO CREDENTIAL RULES:
 *
 *  1. **No token is rendered.** Not the plaintext, not the mask. The field is a
 *     password input that is always empty on load.
 *  2. **Identity is the URL.** `McpAppsStore` carries a sealed token across an
 *     update by `connection_ref` (or `server_url` when the ref is blank).
 *     Changing either one is a *different* connection, so the stored token is
 *     not carried — the form says so, and `save()` reports how many apps ended
 *     up with a credential so the loss is visible rather than silent.
 *
 * ESTATE SCOPE. Assistants have no global tenant scope (unlike `WorkflowRun`),
 * so every lookup is `where('tenant_id', …)`. The selector is built from the
 * estate's assistants, and `resolveAssistant()` re-checks ownership on every
 * request — a tampered `assistantId` (the property is URL-bound) resolves to
 * null and the page shows no apps rather than another estate's.
 *
 * ACCESS. The REST routes sit in the `can:manage_options` group, and
 * `McpAppsTest::test_a_subscriber_cannot_read_or_write` pins it. The page
 * requires the same capability in addition to panel membership, so the button
 * and the endpoint cannot disagree.
 */
class McpAppsPage extends Page implements HasForms
{
    use InteractsWithForms;

    protected static ?string $slug = 'mcp-apps';

    protected static ?string $navigationLabel = 'MCP apps';

    protected static ?string $title = 'MCP apps';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedPuzzlePiece;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Connections;

    // Per-assistant configuration, so it leads the group: everything after it
    // names an endpoint the estate shares.
    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.mcp-apps';

    /**
     * The assistant being edited; empty when none is selected.
     *
     * URL-bound so a support link can name the assistant, and re-validated on
     * every update — the id is a selector, never an authorization.
     */
    #[Url]
    public string $assistantId = '';

    /** The Livewire state bucket the schema binds to. */
    public ?array $data = [];

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(AdminAccessPolicy::class)->accessTenantPanel($user)) {
            return false;
        }

        return CapabilityMap::has($user, 'manage_options');
    }

    public function mount(): void
    {
        $requested = trim($this->assistantId);
        $selected = $this->resolveAssistant($requested);

        // Fall back to the estate's first assistant ONLY when nothing was
        // requested. An id that was requested but does not resolve (missing or
        // another estate's) stays unresolved, so the page shows "choose an
        // assistant" instead of silently swapping in a different one.
        if (null === $selected && '' === $requested) {
            $selected = $this->firstAssistant();
        }

        $this->assistantId = null === $selected ? '' : (string) $selected->id;

        $this->loadForm();
    }

    /**
     * Re-resolve when the selector changes, then rebuild the form.
     *
     * Assignment back to the property also normalises an unknown or foreign id
     * to empty, so the URL cannot leave the page pointing at another estate.
     */
    public function updatedAssistantId(): void
    {
        $selected = $this->resolveAssistant($this->assistantId);

        $this->assistantId = null === $selected ? '' : (string) $selected->id;

        $this->loadForm();
    }

    public function form(Schema $schema): Schema
    {
        return $schema
            ->components([
                Repeater::make('apps')
                    ->label('Assigned apps')
                    ->maxItems(McpAppsStore::MAX_APPS)
                    ->defaultItems(0)
                    ->reorderable(false)
                    ->addActionLabel('Add app')
                    ->itemLabel(static function (array $state): ?string {
                        $label = trim((string) ($state['label'] ?? ''));

                        if ('' !== $label) {
                            return $label;
                        }

                        $url = trim((string) ($state['server_url'] ?? ''));

                        return '' !== $url ? $url : null;
                    })
                    ->schema([
                        TextInput::make('label')
                            ->maxLength(120)
                            ->placeholder('Weather tools'),
                        TextInput::make('server_url')
                            ->required()
                            ->url()
                            ->maxLength(255)
                            ->placeholder('https://mcp.example.com/mcp'),
                        TextInput::make('connection_ref')
                            ->maxLength(120)
                            ->helperText(
                                'Optional. The identity a stored token is carried by. '
                                .'Blank means the server URL is the identity.'
                            ),
                        Select::make('auth_type')
                            ->options([
                                'none' => 'None',
                                'bearer' => 'Bearer token',
                                'basic' => 'Basic auth',
                                'header' => 'Custom header',
                                'oauth' => 'OAuth',
                            ])
                            ->default('none')
                            ->live(),
                        TextInput::make('header_name')
                            ->maxLength(120)
                            ->placeholder('X-API-Key')
                            ->visible(static fn (Get $get): bool => 'header' === $get('auth_type'))
                            ->required(static fn (Get $get): bool => 'header' === $get('auth_type')),
                        TextInput::make('token')
                            ->password()
                            ->revealable()
                            ->helperText(
                                'Write-only. Leave blank to keep the stored token for this '
                                .'connection. Changing the connection reference or the server '
                                .'URL makes this a different connection, so the stored token is '
                                .'not carried over unless you paste it again.'
                            )
                            // Never dehydrate an empty value: a present-but-blank key is
                            // the shape the store's merge path treats as "no input".
                            ->dehydrated(static fn (?string $state): bool => filled($state)),
                        Toggle::make('enabled')
                            ->default(true),
                        TextInput::make('timeout')
                            ->numeric()
                            ->minValue(1)
                            ->maxValue(300)
                            ->default(30)
                            ->helperText('Seconds. 1–300.'),
                        Toggle::make('verify_ssl')
                            ->default(true)
                            ->helperText('Turn off only for a local server with a self-signed certificate.'),
                    ])
                    ->columns(2),
            ])
            ->statePath('data');
    }

    /**
     * Persist the list through the one writer.
     *
     * `McpAppsStore::replace()` validates every entry before it writes, so a bad
     * row leaves the stored list untouched. A refusal is a notification, not an
     * exception: a 500 in the middle of a bulk edit tells the operator nothing.
     */
    public function save(): void
    {
        $assistant = $this->assistant();

        if (null === $assistant) {
            Notification::make()
                ->danger()
                ->title('No assistant selected')
                ->body('Choose an assistant in this estate before saving.')
                ->send();

            return;
        }

        $state = $this->form->getState();
        $apps = is_array($state['apps'] ?? null) ? array_values($state['apps']) : array();

        $before = $this->store()->read($assistant);

        try {
            $saved = $this->store()->replace($assistant, $apps);
        } catch (InvalidArgumentException $error) {
            Notification::make()
                ->danger()
                ->title('MCP apps not saved')
                ->body($error->getMessage())
                ->send();

            return;
        }

        // Re-read rather than trusting the submitted state: the writer applies
        // defaults and clamping, and the form should show what was stored.
        $this->loadForm();

        app(AdminAuditLogger::class)->recordResource(
            'mcp-apps',
            (string) $assistant->id,
            'updated',
            $this->summary($before),
            $this->summary($saved)
        );

        Notification::make()
            ->success()
            ->title('MCP apps saved')
            ->body(sprintf(
                '%d app(s) assigned; %d hold a stored token.',
                count($saved),
                $this->sealedCount($saved)
            ))
            ->send();
    }

    /**
     * The estate's assistants, for the selector.
     *
     * @return list<array{id: int, title: string}>
     */
    public function assistants(): array
    {
        $estate = $this->estateId();

        if ('' === $estate) {
            return array();
        }

        return Assistant::query()
            ->where('tenant_id', $estate)
            ->orderBy('title')
            ->get(array('id', 'title'))
            ->map(static fn (Assistant $assistant): array => array(
                'id' => (int) $assistant->id,
                'title' => (string) $assistant->title,
            ))
            ->all();
    }

    public function maxApps(): int
    {
        return McpAppsStore::MAX_APPS;
    }

    /**
     * Fill the form from the stored list.
     *
     * The `token` key is removed, not blanked: `read()` projects a mask, and a
     * mask in the form state would either be shown or (without the store guard)
     * saved as the new credential. The field starts genuinely empty.
     */
    private function loadForm(): void
    {
        $assistant = $this->assistant();
        $rows = array();

        if (null !== $assistant) {
            foreach ($this->store()->read($assistant) as $app) {
                unset($app['token']);
                $rows[] = $app;
            }
        }

        $this->form->fill(array('apps' => $rows));
    }

    private function assistant(): ?Assistant
    {
        return $this->resolveAssistant($this->assistantId);
    }

    /**
     * The estate's first assistant, or null when the estate has none.
     */
    private function firstAssistant(): ?Assistant
    {
        $estate = $this->estateId();

        if ('' === $estate) {
            return null;
        }

        return Assistant::query()
            ->where('tenant_id', $estate)
            ->orderBy('title')
            ->first();
    }

    /**
     * An estate-scoped assistant, or null.
     *
     * `Assistant` has no global tenant scope, so the estate filter is the whole
     * check — `find()` alone would resolve another estate's assistant from a
     * crafted URL.
     */
    private function resolveAssistant(string $id): ?Assistant
    {
        $id = trim($id);

        if ('' === $id || ! ctype_digit($id)) {
            return null;
        }

        $estate = $this->estateId();

        if ('' === $estate) {
            return null;
        }

        return Assistant::query()
            ->where('tenant_id', $estate)
            ->find((int) $id);
    }

    /**
     * The estate this page is scoped to; empty when the panel has no tenant.
     */
    private function estateId(): string
    {
        $tenant = Filament::getTenant();

        return null === $tenant ? '' : (string) $tenant->getKey();
    }

    private function store(): McpAppsStore
    {
        return app(McpAppsStore::class);
    }

    /**
     * The audit projection of a list: counts, hosts and connection refs.
     *
     * Never the full server URL — a provider can put a token in the path — and
     * never a token, which the store does not project anyway.
     *
     * @param array<int, array<string, mixed>> $apps
     *
     * @return array<string, mixed>
     */
    private function summary(array $apps): array
    {
        $hosts = array();
        $refs = array();

        foreach ($apps as $app) {
            $url = (string) ($app['server_url'] ?? '');
            $host = parse_url($url, PHP_URL_HOST);
            $hosts[] = is_string($host) ? strtolower($host) : '';
            $refs[] = (string) ($app['connection_ref'] ?? '');
        }

        return array(
            'app_count' => count($apps),
            'hosts' => $hosts,
            'connection_refs' => $refs,
            // Named `sealed_count`, not `with_token`: the audit logger redacts
            // any key containing "token" (over-redaction is deliberate), which
            // would have made this field useless in the record it exists for.
            'sealed_count' => $this->sealedCount($apps),
        );
    }

    /**
     * How many entries report a sealed token.
     *
     * @param array<int, array<string, mixed>> $apps
     */
    private function sealedCount(array $apps): int
    {
        $count = 0;

        foreach ($apps as $app) {
            if (isset($app['token_sealed']) || (isset($app['token']) && '' !== (string) $app['token'])) {
                ++$count;
            }
        }

        return $count;
    }
}
