<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\CommandCentre\CommandCentreReport;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * The command centre — Proposal 149 §106.
 *
 * The estate's "at a glance": six KPIs, the agent status map and the activity
 * feed. It is the ported WordPress Pro surface of the same name, reduced to the
 * one tab this runtime can source.
 *
 * **Why a page and not a widget on the Filament dashboard.** The panel's own
 * dashboard is `Filament\Pages\Dashboard` carrying `AccountWidget` and nothing
 * else; it is framework furniture, not a ported surface, and
 * `SurfaceOwnershipTest` only recognises `Pages/*.php` and `*Resource.php` as
 * surfaces. A widget area would therefore be undeclarable — the exact shape
 * §14 exists to prevent. The page sits first in the Workspace group instead,
 * where the WordPress menu put it.
 *
 * **What is deliberately not here.** The WordPress surface has seven tabs —
 * overview, activity, tasks, approvals, analytics, uptime, strategy. Five of
 * them are their own rows on this panel already (`approvals`,
 * `automation-runs`, `token-analytics`, `site-health`, `diagnostics`), and
 * re-rendering them here would recreate the duplication the dashboards review
 * (149-dashboards-redundancy-review.md) recommended removing. `strategy` has no
 * producer in this runtime at all. So this page carries the overview and names
 * where the rest lives, rather than growing a second copy of each.
 *
 * **Read-only.** No action on this page writes; the KPIs are the estate's own
 * and are scoped by `CommandCentreReport`.
 */
class CommandCentre extends Page
{
    protected static ?string $slug = 'command-centre';

    protected static ?string $navigationLabel = 'Command centre';

    protected static ?string $title = 'Command centre';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedCommandLine;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Workspace;

    // Before Assistants (20): the WordPress menu put this at the top of the
    // toolkit, and it is the only item in the group that summarises the others.
    protected static ?int $navigationSort = 10;

    protected string $view = 'filament.admin.command-centre';

    /**
     * Memoised for the request: the blade reads it several times.
     *
     * @var array<string, mixed>|null
     */
    private ?array $report = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * @return array<string, mixed>
     */
    public function report(): array
    {
        return $this->report ??= app(CommandCentreReport::class)->report(TenantContext::current());
    }

    /**
     * The six tiles, in reading order, with the caveat each one needs.
     *
     * A tile's `value` is already formatted because the note beside it is
     * part of the figure: "36.4%" is meaningless without "share of healthy
     * samples", and a dash is meaningless without "no sample recorded yet".
     *
     * @return list<array{id: string, label: string, value: string, note: string}>
     */
    public function tiles(): array
    {
        $kpis = (array) $this->report()['kpis'];

        $uptime = $kpis['uptime'] ?? null;

        return array(
            array(
                'id' => 'total_agents',
                'label' => 'Agents',
                'value' => number_format((int) ($kpis['total_agents'] ?? 0)),
                'note' => 'in this estate',
            ),
            array(
                'id' => 'agents_online',
                'label' => 'Online',
                'value' => number_format((int) ($kpis['agents_online'] ?? 0)),
                'note' => 'enabled — this runtime has no presence channel',
            ),
            array(
                'id' => 'active_tasks',
                'label' => 'Queued',
                'value' => number_format((int) ($kpis['active_tasks'] ?? 0)),
                'note' => 'accepted, not yet run',
            ),
            array(
                'id' => 'pending_approvals',
                'label' => 'Approvals',
                'value' => number_format((int) ($kpis['pending_approvals'] ?? 0)),
                'note' => 'awaiting a decision',
            ),
            array(
                'id' => 'tokens_today',
                'label' => 'Tokens today',
                'value' => number_format((int) ($kpis['tokens_today'] ?? 0)),
                'note' => 'since local midnight',
            ),
            array(
                'id' => 'uptime',
                'label' => 'Uptime (24h)',
                'value' => null === $uptime ? '—' : number_format((float) $uptime, 1).'%',
                'note' => null === $uptime
                    ? 'no sample recorded yet — not zero'
                    : 'share of healthy samples; a database outage shows as a gap',
            ),
        );
    }

    /**
     * The agent status map, most recently active first.
     *
     * @return list<array{id: int, title: string, status: string, last_active: int}>
     */
    public function agents(): array
    {
        $statuses = (array) $this->report()['agent_statuses'];

        $rows = array();

        foreach ($statuses as $id => $entry) {
            $rows[] = array(
                'id' => (int) $id,
                'title' => (string) ($entry['title'] ?? ''),
                'status' => (string) ($entry['status'] ?? 'idle'),
                'last_active' => (int) ($entry['last_active'] ?? 0),
            );
        }

        usort($rows, static fn (array $a, array $b): int => $b['last_active'] <=> $a['last_active']);

        return $rows;
    }

    /**
     * The activity feed, newest first.
     *
     * @return list<array<string, mixed>>
     */
    public function events(): array
    {
        return (array) $this->report()['recent_events'];
    }

    /**
     * The badge colour for an agent state.
     */
    public function statusColour(string $status): string
    {
        return match ($status) {
            'online' => 'success',
            'idle' => 'warning',
            default => 'gray',
        };
    }

    /**
     * A feed timestamp in the runtime's timezone.
     *
     * Named `stamp()` rather than `when()` deliberately: `when()` is the name
     * `Conditionable` claims, and a page method with a different signature would
     * fatal the class if the trait ever reaches `Filament\Pages\Page`.
     */
    public function stamp(int $unix): string
    {
        return 0 === $unix ? '—' : date('Y-m-d H:i', $unix);
    }
}
