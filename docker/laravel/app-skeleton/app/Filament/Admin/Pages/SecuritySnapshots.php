<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Security\SecuritySnapshotStore;
use App\Support\SettingsSchema;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Security snapshots — Proposal 149 §51, `/admin/security`.
 *
 * The contract row's note is the design: **"Security snapshots are a dedicated
 * store; the admin read-only view is a projection, never a scan at request
 * time."** So this page reads `nvoos_security_snapshots` and nothing else. It
 * does not compute the posture report, preview headers or run the self-test —
 * those are live checks, the console already owns them, and re-running them on
 * a page load would make a slow, surprising request out of a records view.
 *
 * WHAT A SNAPSHOT IS
 *
 * A snapshot is the estate's settings payload at a point in time, with
 * sensitive values replaced by `SettingsSchema::MASKED_PLACEHOLDER` at write
 * time. Restoring one skips those placeholders, so a snapshot can never bring a
 * credential back — the console's restore path documents the same rule.
 *
 * Belt and braces: the page masks any key in `SettingsSchema::sensitiveKeys()`
 * again on render. Every snapshot this runtime writes is already masked, but a
 * row that predates the write-path mask (or was inserted by hand) would
 * otherwise be the one place a stored secret could reach a screen.
 *
 * Read-only: create and restore are console writes (`writer: console`), and a
 * panel action would be a second settings writer — the §16 rule.
 */
class SecuritySnapshots extends Page
{
    protected static ?string $slug = 'security';

    protected static ?string $navigationLabel = 'Security';

    protected static ?string $title = 'Security snapshots';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedLockClosed;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Activity;

    // Beside transcripts: both record what happened, and neither writes to the
    // estate.
    protected static ?int $navigationSort = 20;

    protected string $view = 'filament.admin.security';

    /**
     * The opened snapshot, or null.
     *
     * @var array<string, mixed>|null
     */
    public ?array $detail = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function snapshots(): array
    {
        return app(SecuritySnapshotStore::class)->list();
    }

    public function total(): int
    {
        return app(SecuritySnapshotStore::class)->total();
    }

    /**
     * Open one snapshot, scoped to this estate.
     *
     * A foreign id resolves to null and renders the "not found" panel rather
     * than an error page — the same answer the API gives.
     */
    public function open(string $id): void
    {
        $snapshot = app(SecuritySnapshotStore::class)->detail($id);

        if (null === $snapshot) {
            $this->detail = array('error' => 'Snapshot not found.');

            return;
        }

        $sensitive = array_flip(SettingsSchema::sensitiveKeys());
        $rows = array();

        foreach ($snapshot['payload'] as $key => $value) {
            $key = (string) $key;

            $masked = isset($sensitive[$key]) || $value === SettingsSchema::MASKED_PLACEHOLDER;

            $rows[] = array(
                'key' => $key,
                'masked' => $masked,
                'value' => $masked
                    ? SettingsSchema::MASKED_PLACEHOLDER
                    : (is_scalar($value) || null === $value
                        ? (string) $value
                        : (string) json_encode($value)),
            );
        }

        usort($rows, static fn (array $a, array $b): int => strcmp($a['key'], $b['key']));

        $this->detail = array(
            'snapshot_id' => $snapshot['snapshot_id'],
            'label' => $snapshot['label'],
            'created_at' => $snapshot['created_at'],
            'rows' => $rows,
            'masked_count' => count(array_filter($rows, static fn (array $row): bool => $row['masked'])),
        );
    }

    public function close(): void
    {
        $this->detail = null;
    }
}
