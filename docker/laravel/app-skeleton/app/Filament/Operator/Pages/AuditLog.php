<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Admin\AuditTrail;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * The admin audit trail — Proposal 149 §25, `/ops/audit-log`.
 *
 * READ-ONLY, and the contract row says `writer: none`. The table is append-only
 * (`AdminAuditLogger` only inserts, `nvoos_admin_audit` has no `updated_at`), so
 * there is nothing here to edit, retry or delete — and offering an action would
 * be the one thing that turns evidence into a mutable table.
 *
 * Operator-only, not tenant-scoped: the trail is cross-estate on purpose. An
 * operator asking "what happened on customful last Tuesday" needs the rows from
 * that estate *and* the operator actions that touched it, which carry no estate
 * at all. A tenant-scoped version would answer neither question.
 *
 * Both row kinds are shown. Request rows (`resource` null) answer "who tried and
 * was refused"; record rows (`resource` set) answer "what changed". A page that
 * showed only the second would silently hide every 403/422 — which is exactly
 * the half of a trail that matters when something went wrong.
 */
class AuditLog extends Page
{
    protected static ?string $slug = 'audit-log';

    protected static ?string $navigationLabel = 'Audit log';

    protected static ?string $title = 'Admin audit trail';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedClipboardDocumentList;

    // After the operational surfaces (queue, scheduler, credentials), before
    // platform settings.
    protected static ?int $navigationSort = 12;

    protected string $view = 'filament.operator.audit-log';

    /** Panel id filter; empty means every panel. */
    public string $panel = '';

    /** Estate filter; 'operator' selects rows with no estate. Empty = all. */
    public string $estate = '';

    /** Row-kind filter: `record`, `request`, or empty for both. */
    public string $kind = '';

    /** Outcome filter: `ok`, `refused`, or empty for both. */
    public string $status = '';

    /** Free-text filter over path, resource, action and record id. */
    public string $search = '';

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
        return AuditTrail::rows($this->filters());
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return array{total: int, record: int, refused: int, estates: int}
     */
    public function totals(array $rows): array
    {
        return AuditTrail::totals($rows);
    }

    /**
     * @return array<string, string>
     */
    public function panelOptions(): array
    {
        return AuditTrail::panels();
    }

    /**
     * @return array<string, string>
     */
    public function estateOptions(): array
    {
        return AuditTrail::estates();
    }

    /**
     * @return array<string, string>
     */
    public function resourceOptions(): array
    {
        return AuditTrail::resources();
    }

    /**
     * The ceiling this page renders, so the header can say it is a window
     * rather than the whole trail.
     */
    public function limit(): int
    {
        return AuditTrail::LIMIT;
    }

    public function clearFilters(): void
    {
        $this->panel = '';
        $this->estate = '';
        $this->kind = '';
        $this->status = '';
        $this->search = '';
    }

    /**
     * @return array<string, string>
     */
    private function filters(): array
    {
        return array(
            'panel' => $this->panel,
            'estate' => $this->estate,
            'kind' => $this->kind,
            'status' => $this->status,
            'search' => $this->search,
        );
    }
}
