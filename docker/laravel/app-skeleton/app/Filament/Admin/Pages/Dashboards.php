<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\DashboardCatalogue;
use App\Support\Dashboards\DashboardGatherer;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Dashboards — Proposal 149 §113, closing the last buildable row on the board.
 *
 * The WordPress console serves seven dashboards from one SPA page. This runtime
 * owns exactly one of those data stores (the classroom/ECA family, 090-e), so
 * this page renders **only the dashboards it can actually feed** — today that is
 * ECA alone.
 *
 * ## Absent, not disabled
 *
 * The console renders an unavailable dashboard as a greyed card naming the
 * toolkit toggle that would enable it, and that is right *there*: on WordPress
 * those six dashboards are one settings switch away from working.
 *
 * Here they are not. There is no commerce store, no health-metrics store, no
 * annotation store, and nothing an estate administrator could switch on to make
 * one appear. Guidance says the two cases are different: a control is worth
 * disabling when the user can reach the enabled state, and should be hidden when
 * they never can ("something is available in principle but not right now" is a
 * promise a greyed control makes). So `connectors` and the rest are simply not
 * on this page, and asking for one directly is a 404 from the data endpoint.
 *
 * That is the same line §104 drew for inapplicable settings and S-3 drew for
 * unlicensed toolkits. It is also why this page does **not** reuse the console's
 * `disabled` map: the API keeps that shape for the shared client's parity, the
 * panel does not render absence as a control.
 *
 * ## The one dashboard that *is* here can still be off
 *
 * `enable_eca_management` is a real tenant setting on this runtime (Settings →
 * Tools & Features), default off. When it is off the page says so and names the
 * toggle — a genuine disabled state, because the reader can fix it in two
 * clicks. That is the distinction the whole page turns on: unfixable is absent,
 * fixable is disabled-with-a-reason.
 *
 * ## One source of truth for the numbers
 *
 * The metrics come from {@see DashboardGatherer}, the same class the console's
 * REST endpoint calls. A second aggregation here would drift from the API on the
 * first change — the bug this repo has removed from `CommandCentreReport`,
 * `RoutingExportStore` and `JitProvisioner` already.
 */
class Dashboards extends Page
{
    protected static ?string $slug = 'dashboards';

    protected static ?string $navigationLabel = 'Dashboards';

    protected static ?string $title = 'Dashboards';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedChartBar;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Activity;

    // Before Transcripts (10): the summary of the estate's activity, then the
    // record of it.
    protected static ?int $navigationSort = 5;

    protected string $view = 'filament.admin.dashboards';

    /** The selected dashboard id. Only ever a servable id. */
    public string $dashboard = '';

    /**
     * Published filter values, keyed by the catalogue's filter keys.
     *
     * Two named properties rather than an array: Livewire binds `wire:model.live`
     * to a property, and a dynamic `filters.day` binding would let a crafted
     * request create keys the page never declared. The catalogue is still the
     * authority — {@see self::filters()} builds the payload from it.
     */
    public string $day = '';

    public string $eca_type = '';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    public function mount(): void
    {
        $servable = DashboardCatalogue::servable();

        $this->dashboard = $servable[0] ?? '';
    }

    /**
     * The dashboards to render, in catalogue order.
     *
     * Deliberately `servable()` and not `all()`: see the class docblock. Each
     * entry carries the render spec from the catalogue, which is the contract
     * the API publishes — so the page and the console cannot disagree about
     * which metric keys exist.
     *
     * @return list<array<string, mixed>>
     */
    public function dashboards(): array
    {
        $dashboards = [];

        foreach (DashboardCatalogue::servable() as $id) {
            $config = DashboardCatalogue::all()[$id] ?? null;

            if (null === $config) {
                continue;
            }

            $dashboards[] = $config + [
                'id' => $id,
                'enabled' => $this->enabled($id),
                'flag' => DashboardCatalogue::flags($id)[0] ?? '',
            ];
        }

        return $dashboards;
    }

    /**
     * Whether a servable dashboard's toolkit is switched on for this estate.
     *
     * A dashboard whose store exists but whose toggle is off is a real disabled
     * state — the reader can fix it — so the page explains it instead of hiding
     * it.
     */
    public function enabled(string $id): bool
    {
        $flags = DashboardCatalogue::flags($id);

        if ([] === $flags) {
            // Ungated: nothing to switch on, so it is always available.
            return true;
        }

        $settings = app(SettingsService::class);

        foreach ($flags as $flag) {
            if ((bool) $settings->value($flag)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The selected dashboard's render spec, or null when it is not servable or
     * not enabled.
     *
     * @return array<string, mixed>|null
     */
    public function config(): ?array
    {
        foreach ($this->dashboards() as $dashboard) {
            if ($dashboard['id'] === $this->dashboard) {
                return $dashboard['enabled'] ? $dashboard : null;
            }
        }

        return null;
    }

    /**
     * The selected dashboard's payload, or null when there is nothing to gather.
     *
     * Memoised per request: the blade reads metrics, both charts and the list,
     * and gathering paginates the whole ECA collection — four calls would be
     * four scans of the same rows.
     *
     * @return array<string, mixed>|null
     */
    public function data(): ?array
    {
        if ($this->gathered) {
            return $this->cached;
        }

        $this->gathered = true;

        $config = $this->config();

        if (null === $config) {
            return $this->cached = null;
        }

        return $this->cached = app(DashboardGatherer::class)->gather(
            (string) $config['id'],
            $this->filters()
        );
    }

    /**
     * The published filter values for the selected dashboard.
     *
     * Whitelisted against the catalogue exactly as the REST endpoint does, so a
     * page and an API call with the same filters return the same numbers.
     *
     * @return array<string, string>
     */
    public function filters(): array
    {
        $config = null;

        foreach (DashboardCatalogue::all() as $id => $candidate) {
            if ($id === $this->dashboard) {
                $config = $candidate;

                break;
            }
        }

        if (null === $config) {
            return [];
        }

        $filters = [];

        foreach ($config['filters'] ?? [] as $filter) {
            $key = (string) $filter['key'];
            $value = match ($key) {
                'day' => $this->day,
                'eca_type' => $this->eca_type,
                default => '',
            };

            if ('' !== trim($value)) {
                $filters[$key] = trim($value);
            }
        }

        return $filters;
    }

    /**
     * Whether the page has a public property to bind a filter key to.
     *
     * `wire:model.live` on a property that does not exist is a Livewire error at
     * render time, so a filter the page has not declared gets a sentence instead
     * of a control that throws. Adding a dashboard with a new filter is	therefore
     * two edits — a property and its entry in {@see self::filters()} — and this
     * guard is what makes forgetting the first one visible rather than fatal.
     */
    public function bound(string $key): bool
    {
        return in_array($key, array('day', 'eca_type'), true);
    }

    /**
     * The estate's settings page.
     *
     * Built here rather than in the blade: a URL assembled in a template has no
     * test and no single place to change when the panel's path moves.
     */
    public function settingsUrl(): string
    {
        return url('/admin/'.TenantContext::current().'/settings');
    }

    /**
     * A metric formatted for display.
     *
     * The number formats mirror the console's `formatMetric()` exactly — a
     * thousand separator, `$` with no decimals, percent to two places — so one
     * figure does not read differently on the two surfaces.
     *
     * It diverges on **one** thing: a missing value renders an em dash, where the
     * console coerces `undefined` to `0`. That coercion is the failure §106
     * already named for the command centre — "a KPI this runtime cannot measure
     * renders a dash and says why, never a zero" — and a dash is the only honest
     * answer for a key that was not computed.
     */
    public function format(mixed $value, string $format = 'number'): string
    {
        if (null === $value || '' === $value) {
            return '—';
        }

        return match ($format) {
            'money' => '$'.number_format((float) $value, 0),
            'percent' => number_format((float) $value, 2).'%',
            default => number_format((float) $value),
        };
    }

    /**
     * A distribution, ranked and scaled for a bar list.
     *
     * Both ECA charts go through this. The catalogue calls one of them a `donut`
     * and the panel draws bars, deliberately: the panel has no chart library, and
     * a donut built out of `conic-gradient` would be a shape nobody can read
     * precisely. Hill's 2025 comparison found pie and donut charts as accurate as
     * bars for part-to-whole but **slower, and less precise for ranking** — and
     * "which activity types do we run?" is a ranking question.
     *
     * Every bar carries its label and its value as text, so the encoding is never
     * colour alone (WCAG 1.4.1).
     *
     * @param array<string, int|float> $distribution
     * @return list<array{label: string, value: float, display: string, width: float}>
     */
    public function bars(array $distribution): array
    {
        if ([] === $distribution) {
            return [];
        }

        $max = max(array_map(static fn ($value): float => (float) $value, $distribution));

        $bars = [];

        foreach ($distribution as $label => $value) {
            $float = (float) $value;

            $bars[] = [
                'label' => (string) $label,
                'value' => $float,
                'display' => $this->format($float),
                'width' => $max > 0 ? round(($float / $max) * 100, 2) : 0.0,
            ];
        }

        usort($bars, static fn (array $a, array $b): int => $b['value'] <=> $a['value']);

        return $bars;
    }

    /**
     * The selected dashboard's value for the one chart, as text.
     *
     * Kept next to the data so the blade does no arithmetic.
     *
     * @return array<string, int|float>
     */
    public function chartData(string $key): array
    {
        $data = $this->data();

        if (null === $data || ! isset($data[$key]) || ! is_array($data[$key])) {
            return [];
        }

        return $data[$key];
    }

    /**
     * The selected dashboard's list rows, or [].
     *
     * @return list<array<string, mixed>>
     */
    public function rows(string $key): array
    {
        $data = $this->data();

        if (null === $data || ! isset($data[$key]) || ! is_array($data[$key])) {
            return [];
        }

        return array_values($data[$key]);
    }

    /** @var array<string, mixed>|null */
    private ?array $cached = null;

    /** Whether {@see self::data()} has already run in this request. */
    private bool $gathered = false;
}
