<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Diagnostics\ProbeRunner;
use App\Support\Diagnostics\RoutingMetricStore;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ProEntitlementInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Infrastructure\Token\TokenBudgetManager;
use Nvoos\Laravel\Adapter\JobStatusStore;
use Nvoos\Laravel\Adapter\TokenUsage;

/**
 * Operations, diagnostics and cost — Proposal 149 §48, `/ops/diagnostics`.
 *
 * The contract row `platform-operations` was the only row planned on both
 * panels (only `/ops` here) and the last place WordPress had a screen that this
 * runtime did not: Tools → Provider Test, MCP Server Diagnostic and REST
 * Context Diagnostic. Those three are probe surfaces — "send a request, show
 * the envelope" — and §7.8 asked for one renderer rather than three pages.
 *
 * The probe itself is {@see ProbeRunner}; this class owns presentation only.
 * The page is READ-ONLY and stateless: every probe re-runs when asked and
 * nothing is stored, so the row declares `writer: none` (the §31 rule, applied
 * again). A "last checked" timestamp would be a lie the moment the page closes.
 *
 * THREE SECTIONS, because the row names three console keys:
 *
 *  - **Diagnostics** (`diagnostics`) — presence facts about providers, tools,
 *    the queue and the plan. Presence only: no mask, no key, no `••••`.
 *  - **Probes** (`operations`) — the renderer, parameterised by probe key.
 *  - **Cost** (`cost`) — the 30-day aggregate from `TokenUsage`.
 *
 * The diagnostics section is a deliberate re-projection of
 * `GET /api/v1/diagnostics/overview` rather than the same payload: the API
 * carries WordPress-shaped fields (`wp_version`, `settings_dashboard`,
 * `mcp_server`) that mean nothing here, and a panel that rendered them would
 * be imitating another runtime instead of describing this one.
 */
class DiagnosticsPage extends Page
{
    protected static ?string $slug = 'diagnostics';

    protected static ?string $navigationLabel = 'Diagnostics';

    protected static ?string $title = 'Operations & diagnostics';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedWrenchScrewdriver;

    // Before site health: this page answers "can the runtime reach its
    // dependencies", health answers "is the runtime itself sound".
    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.operator.diagnostics';

    /** Selected probe key. Constrained to ProbeRunner::PROBES on submit. */
    public string $probe = 'provider';

    /** Selected provider slug, for the `provider` probe. */
    public string $provider = '';

    /** Selected assistant id as a string, for the `assistant` probe. */
    public string $assistant = '';

    /**
     * The last result, as an array rather than a ProbeResult.
     *
     * Livewire must serialise every public property between requests, and a
     * readonly value object round-trips through its synthesizer with less
     * certainty than a plain array. The projection is already safe by the time
     * it lands here.
     *
     * @var array<string, mixed>|null
     */
    public ?array $result = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    public function mount(): void
    {
        $runner = $this->runner();

        $this->provider = (string) (array_key_first($runner->providerOptions()) ?? '');
        $assistant = $runner->assistantOptions();
        $this->assistant = (string) (array_key_first($assistant) ?? '');
    }

    /**
     * Run the selected probe and keep the rendered summary.
     */
    public function runProbe(): void
    {
        $validated = $this->validate([
            'probe' => ['required', 'string', Rule::in(array_keys(ProbeRunner::PROBES))],
            'provider' => ['nullable', 'string', 'max:64'],
            'assistant' => ['nullable', 'integer', 'min:0'],
        ]);

        $result = $this->runner()->run($validated['probe'], [
            'provider' => (string) ($validated['provider'] ?? ''),
            'assistant' => (int) ($validated['assistant'] ?? 0),
        ]);

        $this->result = [
            'probe' => $result->probe,
            'label' => $result->label,
            'status' => $result->status,
            'summary' => $result->summary,
            'rows' => $result->rows,
            'duration_ms' => $result->durationMs,
        ];
    }

    /**
     * @return array<string, string>
     */
    public function probeOptions(): array
    {
        return $this->runner()->labels();
    }

    /**
     * @return array<string, string>
     */
    public function providerOptions(): array
    {
        return $this->runner()->providerOptions();
    }

    /**
     * @return array<int, string>
     */
    public function assistantOptions(): array
    {
        return $this->runner()->assistantOptions();
    }

    /**
     * Presence facts, grouped for the view.
     *
     * No credential value ever enters this array — the provider rows say
     * `configured` or `missing`, never a mask. A mask invites "where is the
     * reveal button?"; a boolean does not.
     *
     *  - **Cost** (`cost`) — the 30-day aggregate from `TokenUsage`.
     *  - **Routing** — fallback counts for the same window, and which tokenizer
     *    the pre-flight check is using.
     *
     * @return array<string, array<int, array{label: string, value: string}>>
     */
    public function overview(): array
    {
        $providers = app(ProviderRouter::class);
        $tools = app(ToolRegistry::class);
        $settings = app(SettingsStoreInterface::class);
        $entitlement = app(ProEntitlementInterface::class);
        $jobs = app(JobStatusStore::class);
        $routing = app(RoutingMetricStore::class)->summary(30);

        $slugs = array_values(array_map('strval', $providers->getRegisteredSlugs()));

        $providerRows = [];

        foreach ($slugs as $slug) {
            // Ask the same question the client asks. `getApiKey()` checks the
            // services config, the oos key map and the settings table in that
            // order, so reading `all()[$key]` here would miss an env key and
            // report "no credentials" for a provider that can chat.
            $needsKey = ! in_array($slug, ['ollama', 'lm_studio'], true);

            if (! $needsKey) {
                $providerRows[] = [
                    'label' => ucfirst(str_replace('_', ' ', $slug)),
                    'value' => 'no key required',
                ];

                continue;
            }

            $key = $settings->getApiKey($slug);

            $providerRows[] = [
                'label' => ucfirst(str_replace('_', ' ', $slug)),
                'value' => is_string($key) && '' !== $key ? 'credentials configured' : 'no credentials',
            ];
        }

        $queueCounts = $jobs->counts();
        $queueRows = [
            ['label' => 'Available', 'value' => $jobs->isAvailable() ? 'yes' : 'no'],
        ];

        // Statuses are whatever the store has recorded; do not invent a list.
        foreach ($queueCounts as $status => $total) {
            $queueRows[] = ['label' => ucfirst((string) $status), 'value' => (string) $total];
        }

        if (1 === count($queueRows)) {
            $queueRows[] = ['label' => 'Jobs', 'value' => 'none recorded'];
        }

        return [
            'Runtime' => [
                ['label' => 'PHP', 'value' => PHP_VERSION],
                ['label' => 'Laravel', 'value' => app()->version()],
                ['label' => 'App version', 'value' => (string) config('app.version', '1.2.0')],
                ['label' => 'Memory limit', 'value' => (string) ini_get('memory_limit')],
                ['label' => 'Database', 'value' => $this->databaseLabel()],
                ['label' => 'Tokenizer', 'value' => $this->tokenizerLabel()],
            ],
            'Catalogue' => [
                ['label' => 'Providers registered', 'value' => (string) count($slugs)],
                ['label' => 'Tools registered', 'value' => (string) $tools->count()],
                ['label' => 'Plan', 'value' => (string) $entitlement->plan()],
                ['label' => 'Plan source', 'value' => (string) $entitlement->source()],
            ],
            'Queue' => $queueRows,
            'Provider credentials' => $providerRows,
            'Routing (30d)' => $this->routingRows($routing),
        ];
    }

    /**
     * The tokenizer the pre-flight check is using.
     *
     * The number matters because a rejection is only as good as the estimate:
     * chars/4 is a rough average, tiktoken is accurate.
     */
    private function tokenizerLabel(): string
    {
        $tokenizer = app(TokenBudgetManager::class)->getTokenizer();

        if (null === $tokenizer) {
            return 'not wired';
        }

        return $tokenizer->isAvailable()
            ? 'tiktoken (accurate)'
            : 'heuristic (chars/4)';
    }

    /**
     * Fallback rows for the overview.
     *
     * "none recorded" is a real answer: it says the catalogue has never had
     * to rescue a request in this window, not that the page failed to load.
     *
     * @param array<string, mixed> $summary
     * @return list<array{label: string, value: string}>
     */
    private function routingRows(array $summary): array
    {
        $total = (int) ($summary['total'] ?? 0);

        if (0 === $total) {
            return [['label' => 'Model fallbacks', 'value' => 'none recorded']];
        }

        $rows = [
            ['label' => 'Model fallbacks', 'value' => (string) $total],
            ['label' => 'Succeeded', 'value' => (string) (int) ($summary['succeeded'] ?? 0)],
            ['label' => 'Failed', 'value' => (string) (int) ($summary['failed'] ?? 0)],
        ];

        $last = $summary['last_at'] ?? null;
        if (is_string($last) && '' !== $last) {
            $rows[] = ['label' => 'Last fallback', 'value' => $last];
        }

        foreach ((array) ($summary['pairs'] ?? []) as $pair) {
            $label = sprintf('%s → %s', (string) ($pair['from'] ?? ''), (string) ($pair['to'] ?? ''));
            $value = sprintf('%d on %s', (int) ($pair['total'] ?? 0), (string) ($pair['provider'] ?? ''));

            if ((int) ($pair['failed'] ?? 0) > 0) {
                $value .= sprintf(', %d failed', (int) $pair['failed']);
            }

            $rows[] = ['label' => $label, 'value' => $value];
        }

        return $rows;
    }

    /**
     * The 30-day cost aggregate the `cost` console key serves.
     *
     * @return array<string, mixed>
     */
    public function cost(): array
    {
        $summary = app(TokenUsage::class)->summary(30);

        $byProvider = [];
        foreach ((array) ($summary['by_provider'] ?? []) as $provider => $cost) {
            $byProvider[] = [
                'label' => (string) $provider,
                'value' => '$'.number_format((float) $cost, 4),
            ];
        }

        return [
            'period' => sprintf('%s → %s', (string) ($summary['period_start'] ?? ''), (string) ($summary['period_end'] ?? '')),
            'total_cost' => '$'.number_format((float) ($summary['total_cost'] ?? 0), 4),
            'total_tokens' => number_format((int) ($summary['total_tokens'] ?? 0)),
            'by_provider' => $byProvider,
        ];
    }

    private function databaseLabel(): string
    {
        try {
            DB::select('select 1');

            return 'connected';
        } catch (\Throwable $exception) {
            return 'unreachable';
        }
    }

    private function runner(): ProbeRunner
    {
        return app(ProbeRunner::class);
    }
}
