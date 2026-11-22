<?php

namespace App\Filament\Operator\Pages;

use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Security\SecuritySnapshotOverview;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Security snapshots across every estate — Proposal 149 §171, `/ops/security`.
 *
 * The last `planned` surface in the ownership contract. It is the operator half
 * of a surface whose admin half shipped in §51: the same records, the same
 * masking rule, a different audience and therefore a different scope.
 *
 * OPERATOR-WIDE, ON PURPOSE
 *
 * `/ops` has no estate selected (§17), so this page cannot be estate-scoped and
 * should not pretend to be. An operator asking "which estates have saved a
 * snapshot" needs the cross-estate answer, exactly as `/ops/audit-log` does.
 * The estate is a column, not a filter, and the reduction the contract declares
 * is what keeps that honest: labels, timestamps, key counts, and a masked
 * payload on demand.
 *
 * WHAT IS DELIBERATELY ABSENT
 *
 * No posture scan, no header preview, no self-test, no chart. The first three
 * are live checks the console runs on demand; the chart is a Wave 6 canvas
 * bundle (§7.10). Running them on page load would turn a records view into a
 * slow, surprising request — the same reasoning §51 recorded for the admin half.
 *
 * READ-ONLY
 *
 * Creating and restoring a snapshot remain console writes (`writer: console`).
 * A button here would be a second settings writer, which is §16's rule.
 */
class SecurityOverview extends Page
{
    protected static ?string $slug = 'security';

    protected static ?string $navigationLabel = 'Security';

    protected static ?string $title = 'Security snapshots';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedShieldCheck;

    // Last of the Access group: it reads records rather than credentials or
    // tools, so it belongs after the things an operator rotates.
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Access;

    protected static ?int $navigationSort = 28;

    protected string $view = 'filament.operator.security';

    /**
     * The opened snapshot, or an error row, or null.
     *
     * @var array<string, mixed>|null
     */
    public ?array $detail = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function rows(): array
    {
        return app(SecuritySnapshotOverview::class)->rows();
    }

    public function total(): int
    {
        return app(SecuritySnapshotOverview::class)->total();
    }

    /**
     * @return array<string, int> Estate => count.
     */
    public function estates(): array
    {
        return app(SecuritySnapshotOverview::class)->estates();
    }

    /**
     * The ceiling this page renders, so the header can say it is a window.
     */
    public function limit(): int
    {
        return SecuritySnapshotOverview::LIMIT;
    }

    /**
     * Open one snapshot, masked.
     *
     * An id that no longer exists resolves to null and renders "not found"
     * rather than an error page — the same answer the admin page gives.
     */
    public function open(string $id): void
    {
        $snapshot = app(SecuritySnapshotOverview::class)->detail($id);

        $this->detail = null === $snapshot
            ? array('error' => 'Snapshot not found.')
            : $snapshot;
    }

    public function close(): void
    {
        $this->detail = null;
    }
}
