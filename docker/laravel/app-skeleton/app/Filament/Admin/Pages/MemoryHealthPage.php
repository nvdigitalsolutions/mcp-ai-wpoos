<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Memory\MemoryHealth;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Agent-memory health — Proposal 149 §182, `/admin/memory-health`.
 *
 * **Read-only, and not `/admin/memory`.** That page browses what an assistant
 * stored; this one grades the store: how much of it is live, dormant or expired,
 * which way it is moving, and which agents it belongs to. The contract row's
 * `writer` stays `none` because there is nothing here to write.
 *
 * **This is not a port of the WordPress view's headline number.** That view
 * reports PHP process memory (`memory_get_usage()` against a percentage of
 * `memory_limit`), which an FPM request cannot observe across requests — the
 * figure would measure the page rendering itself. §182 records the swap to the
 * portable half, Pro's `get_memory_health_stats()`.
 *
 * **The bands are fixed; the window is a setting.** `memory_dormancy_days` —
 * ported with the §62 settings catalogue, shown under Settings → Orchestration —
 * decides what counts as dormant, because that is a policy question. The
 * percentages that turn the count into a verdict are constants in
 * {@see MemoryHealth}, because a band an operator can widen until it is green is
 * not a band.
 */
class MemoryHealthPage extends Page
{
    protected static ?string $slug = 'memory-health';

    protected static ?string $navigationLabel = 'Memory health';

    protected static ?string $title = 'Memory health';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedHeart;

    // The Knowledge group already holds what an assistant can read and write;
    // this grades one of those stores, so it sits beside Memory (40).
    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Knowledge;

    protected static ?int $navigationSort = 41;

    protected string $view = 'filament.admin.memory-health';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessTenantPanel($user);
    }

    /**
     * The configured dormancy window, falling back to the WordPress default.
     */
    public function dormancyDays(): int
    {
        $days = app(SettingsService::class)->value('memory_dormancy_days');

        return max(1, (int) ($days ?? MemoryHealth::DEFAULT_DORMANCY_DAYS));
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        return app(MemoryHealth::class)->summary(TenantContext::current(), $this->dormancyDays());
    }

    public function verdict(): string
    {
        return app(MemoryHealth::class)->verdict($this->summary());
    }

    public function growthDays(): int
    {
        return MemoryHealth::GROWTH_DAYS;
    }

    public function expiringSoonDays(): int
    {
        return MemoryHealth::EXPIRING_SOON_DAYS;
    }

    public function verdictLabel(): string
    {
        return match ($this->verdict()) {
            'critical' => 'Critical',
            'warn' => 'Warning',
            'ok' => 'Healthy',
            default => 'No data',
        };
    }

    public function humanBytes(int $bytes): string
    {
        if ($bytes < 1024) {
            return $bytes.' B';
        }

        if ($bytes < 1024 * 1024) {
            return number_format($bytes / 1024, 1).' KB';
        }

        if ($bytes < 1024 * 1024 * 1024) {
            return number_format($bytes / (1024 * 1024), 1).' MB';
        }

        return number_format($bytes / (1024 * 1024 * 1024), 2).' GB';
    }

    /**
     * Growth as an arrow-free phrase: the sign is the information.
     *
     * @param array<string, mixed> $summary
     */
    public function growthLabel(array $summary): string
    {
        $recent = (int) ($summary['created_recent'] ?? 0);
        $previous = (int) ($summary['created_previous'] ?? 0);
        $pct = $summary['growth_pct'] ?? null;

        if (null === $pct) {
            return $recent > 0
                ? sprintf('%d written, none in the window before', $recent)
                : 'nothing written in either window';
        }

        if (0 === (int) $pct) {
            return sprintf('%d written, flat on the window before', $recent);
        }

        return sprintf(
            '%d written, %s%d%% on the window before',
            $recent,
            (int) $pct > 0 ? '+' : '',
            (int) $pct
        );
    }

    public function humanDate(string $value): string
    {
        return '' === $value ? '—' : substr($value, 0, 10);
    }
}
