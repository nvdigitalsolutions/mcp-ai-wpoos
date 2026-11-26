<?php

namespace App\Filament\Operator\Pages;

use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Support\Diagnostics\RoutingExportStore;
use App\Support\Observability\TraceRuns;
use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use BackedEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;
use UnitEnum;

/**
 * Observability — Proposal 149 §84, `/ops/observability`.
 *
 * The WordPress orchestration view called `observability` is a hub: a Run
 * Timeline, a Measurement Dashboard and the OTLP exporter's status, all
 * read-only. This page is the part of that which the runtime can evidence
 * today:
 *
 *  - **recent runs**, from the harness trace store, which had a writer and no
 *    reader — the closest thing the runtime has to WordPress's Run Timeline;
 *  - **the export gauge**, because a run that never leaves the runtime is an
 *    observability fact, not just an export fact;
 *  - **links to the pages that already answer the rest** (diagnostics, token
 *    analytics) rather than a second copy of their tables.
 *
 * The Measurement Dashboard equivalent is not built: it needs the metric and
 * eval stores §45 recorded as its prerequisite, and a page over an empty model
 * would be a screenshot of a decision nobody has made.
 */
class Observability extends Page
{
    protected static ?string $slug = 'observability';

    protected static ?string $navigationLabel = 'Observability';

    protected static ?string $title = 'Observability';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedSignal;

    // After token analytics (14).
    protected static string|UnitEnum|null $navigationGroup = OperatorNavigationGroup::Insight;
    protected static ?int $navigationSort = 17;

    protected string $view = 'filament.operator.observability';

    public static function canAccess(): bool
    {
        $user = auth()->user();

        return $user instanceof User
            && app(AdminAccessPolicy::class)->accessOperatorPanel($user);
    }

    /**
     * Whether any run can be recorded at all. Capture is a master switch on top
     * of the per-assistant profile: off here means nothing is written whatever
     * an assistant asks for.
     */
    public function captureEnabled(): bool
    {
        return (bool) config('oos.security.trace_capture', true);
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function runs(): array
    {
        if (! $this->captureEnabled()) {
            return array();
        }

        return app(TraceRuns::class)->recent();
    }

    /**
     * The export gauge, in the same terms the diagnostics API uses.
     *
     * @return array<string, mixed>
     */
    public function exportState(): array
    {
        $configured = '' !== trim((string) config('oos.routing.export.endpoint', ''));
        $store = app(RoutingExportStore::class);

        if (! $configured) {
            return array(
                'configured' => false,
                'status' => 'not configured',
                'backing_off' => false,
                'retry_after' => null,
                'watermark' => 0,
            );
        }

        $last = $store->last();
        $state = $store->backoffState();

        return array(
            'configured' => true,
            'status' => null === $last ? 'configured, never run' : (string) ($last->status ?? 'unknown'),
            'backing_off' => $state['backing_off'],
            'retry_after' => $state['retry_after'],
            'watermark' => $store->watermark(),
        );
    }
}
