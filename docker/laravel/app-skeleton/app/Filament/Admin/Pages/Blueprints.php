<?php

namespace App\Filament\Admin\Pages;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\User;
use App\Policies\AdminAccessPolicy;
use App\Services\BlueprintCatalogue;
use App\Services\BlueprintInstaller;
use App\Support\Admin\AdminAuditLogger;
use App\Support\BlueprintInstallException;
use App\Support\CapabilityMap;
use BackedEnum;
use UnitEnum;
use Filament\Pages\Page;
use Filament\Support\Icons\Heroicon;

/**
 * Assistant blueprint catalogue — Proposal 149 §53, `/admin/blueprints`.
 *
 * The row is one of the few that is genuinely **full** on this panel, because
 * the write is already shared: `BlueprintInstaller` is the single writer for
 * both the console's `POST /blueprints/{toolkit}/{slug}/install` and this page.
 * The panel calls the service, never the route (§46's rule), so there is no
 * loopback HTTP hop and no second install path.
 *
 * Why this is safe to expose:
 *
 * - **The installer is already estate-scoped.** It resolves the existing
 *   assistant with `where('tenant_id', …)` and sets the tenant on create, so an
 *   install lands in the caller's estate and a title collision is an estate
 *   collision, not a global one. That is the opposite finding from §47/§49/§51 —
 *   nothing to fix, and worth recording so the next reader does not go looking.
 * - **Overwrite is explicit.** Reinstalling is offered as a separate,
 *   confirmed action and updates the assistant in place rather than creating a
 *   duplicate.
 * - **Every install writes an audit row.** Livewire traffic is skipped by
 *   `AuditAdminRequests`, so without this an operator installing (or
 *   overwriting) an assistant from the panel would leave no trace.
 *
 * The catalogue itself is code-shipped reference data (`blueprints/`, mirrored
 * from the Pro toolkits and guarded by `bin/sync-blueprints.php --check`). It is
 * the same set on every estate; only the *installed* flags are estate-scoped.
 *
 * Access needs `manage_options` in addition to non-viewer membership, because
 * `POST /blueprints/{toolkit}/{slug}/install` sits inside the console's
 * `can:manage_options` group. A page reachable by a member the console refuses
 * would be the panel promising a write the API declines.
 */
class Blueprints extends Page
{
    protected static ?string $slug = 'blueprints';

    protected static ?string $navigationLabel = 'Blueprints';

    protected static ?string $title = 'Assistant blueprints';

    protected static string|BackedEnum|null $navigationIcon = Heroicon::OutlinedRectangleStack;

    protected static string|UnitEnum|null $navigationGroup = AdminNavigationGroup::Workspace;

    // A records surface next to the assistant resource.
    protected static ?int $navigationSort = 40;

    protected string $view = 'filament.admin.blueprints';

    /** Free-text filter over name, description and toolkit. */
    public string $search = '';

    /** Toolkit-slug filter; empty means every toolkit. */
    public string $toolkit = '';

    /**
     * The opened blueprint, as `['toolkit','slug','installed_id','detail']`, or
     * null. `installed_id` is 0 when this estate has no assistant from it.
     */
    public ?array $detail = null;

    /** Last install outcome: `['tone' => 'ok'|'error', 'message' => string]`. */
    public ?array $notice = null;

    /**
     * One decoded catalogue per request.
     *
     * `catalogue()`, `toolkits()` and `installedCount()` each need the listing,
     * and `open()` needs it again to know whether the opened blueprint is
     * installed. Without the memo a single render would re-read and re-decode
     * every blueprint JSON file four times. Private, so Livewire never
     * serialises it: each request starts clean and cannot serve a stale
     * catalogue after an install.
     *
     * @var array{total:int, toolkits:list<array<string,mixed>>}|null
     */
    private ?array $listing = null;

    public static function canAccess(): bool
    {
        $user = auth()->user();

        if (! $user instanceof User || ! app(AdminAccessPolicy::class)->accessTenantPanel($user)) {
            return false;
        }

        return CapabilityMap::has($user, 'manage_options');
    }

    /**
     * The filtered catalogue, grouped by toolkit.
     *
     * @return array{total: int, toolkits: list<array<string, mixed>>}
     */
    public function catalogue(): array
    {
        $listing = $this->listing();
        $needle = mb_strtolower(trim($this->search));
        $toolkits = [];

        foreach ($listing['toolkits'] as $group) {
            if ('' !== $this->toolkit && $group['slug'] !== $this->toolkit) {
                continue;
            }

            $blueprints = array_values(array_filter(
                $group['blueprints'],
                static function (array $blueprint) use ($needle, $group): bool {
                    if ('' === $needle) {
                        return true;
                    }

                    return str_contains(
                        mb_strtolower($blueprint['name'].' '.$blueprint['description'].' '.$group['label'].' '.$group['slug']),
                        $needle
                    );
                }
            ));

            if ([] === $blueprints) {
                continue;
            }

            $toolkits[] = array(
                'slug' => $group['slug'],
                'label' => $group['label'],
                'blueprints' => $blueprints,
            );
        }

        return array(
            'total' => array_sum(array_map(static fn (array $g): int => count($g['blueprints']), $toolkits)),
            'toolkits' => $toolkits,
        );
    }

    /**
     * Toolkit filters, from the unfiltered listing so the select never shrinks
     * to the option already chosen.
     *
     * @return array<string, string>
     */
    public function toolkits(): array
    {
        $options = [];

        foreach ($this->listing()['toolkits'] as $group) {
            $options[(string) $group['slug']] = (string) $group['label'];
        }

        return $options;
    }

    /**
     * The installed-assistant count for the header.
     */
    public function installedCount(): int
    {
        $count = 0;

        foreach ($this->listing()['toolkits'] as $group) {
            foreach ($group['blueprints'] as $blueprint) {
                if ($blueprint['installed']) {
                    ++$count;
                }
            }
        }

        return $count;
    }

    public function open(string $toolkit, string $slug): void
    {
        $detail = app(BlueprintCatalogue::class)->detail($toolkit, $slug);
        $row = $this->row($toolkit, $slug);

        if (null === $detail || null === $row) {
            $this->detail = null;
            $this->notice = array('tone' => 'error', 'message' => 'That blueprint does not exist.');

            return;
        }

        $this->detail = array(
            'toolkit' => $toolkit,
            'slug' => $slug,
            // The detail panel offers one control, and which one depends on the
            // estate: Install must not silently overwrite, Reinstall must be the
            // confirmed action. Resolved from the same listing row the table
            // renders, so the two cannot disagree.
            'installed_id' => $row['installed'] ? (int) $row['assistant_id'] : 0,
            'detail' => $detail,
        );
    }

    public function close(): void
    {
        $this->detail = null;
    }

    /**
     * Install, or reinstall, a blueprint as an assistant in this estate.
     *
     * `$overwrite` is only passed as true from the confirmed "Reinstall"
     * control; the plain "Install" control never overwrites.
     */
    public function install(string $toolkit, string $slug, bool $overwrite = false): void
    {
        try {
            $result = app(BlueprintInstaller::class)->install($toolkit, $slug, $overwrite);
        } catch (BlueprintInstallException $exception) {
            $this->notice = array(
                'tone' => 'error',
                'message' => 409 === $exception->status()
                    ? $this->conflictMessage($toolkit, $slug)
                    : $exception->getMessage(),
            );

            return;
        }

        $this->audit($toolkit, $slug, $result['assistant_id'], $overwrite);

        // An install from the open detail panel must flip that panel's control
        // from Install to Reinstall, without a second catalogue read.
        if (null !== $this->detail
            && $this->detail['toolkit'] === $toolkit
            && $this->detail['slug'] === $slug) {
            $this->detail['installed_id'] = (int) $result['assistant_id'];
        }

        $this->notice = array(
            'tone' => 'ok',
            'message' => sprintf(
                '%s "%s" as assistant #%d in this estate.',
                $overwrite ? 'Reinstalled' : 'Installed',
                $result['name'],
                $result['assistant_id']
            ),
        );
    }

    /**
     * Turn the installer's 409 into a message that names an available control.
     *
     * The title is the installer's identity (as it is in WordPress), while the
     * catalogue's `installed` flag is provenance (`_blueprint_source`). The two
     * disagree in exactly one case: an assistant an operator created by hand
     * with the same title. It is not shown as installed, so the row offers no
     * Reinstall control — saying "Use Reinstall" would point at a button that
     * is not there. The API's own wording ("Set overwrite=true") has the same
     * problem, so neither message is reused verbatim.
     */
    private function conflictMessage(string $toolkit, string $slug): string
    {
        $row = $this->row($toolkit, $slug);
        $name = null !== $row ? (string) $row['name'] : $slug;

        if (null !== $row && $row['installed']) {
            return sprintf('%s is already installed in this estate. Use Reinstall to update it in place.', $name);
        }

        return sprintf(
            'An assistant named "%s" already exists in this estate but was not installed from a blueprint. Rename or delete it in Assistants, then install again.',
            $name
        );
    }

    public function clearFilters(): void
    {
        $this->search = '';
        $this->toolkit = '';
    }

    /**
     * The listing row for one blueprint, or null when it is not in the
     * catalogue.
     *
     * @return array<string,mixed>|null
     */
    private function row(string $toolkit, string $slug): ?array
    {
        foreach ($this->listing()['toolkits'] as $group) {
            if ($group['slug'] !== $toolkit) {
                continue;
            }

            foreach ($group['blueprints'] as $blueprint) {
                if ($blueprint['slug'] === $slug) {
                    return $blueprint;
                }
            }
        }

        return null;
    }

    /**
     * @return array{total:int, toolkits:list<array<string,mixed>>}
     */
    private function listing(): array
    {
        return $this->listing ??= app(BlueprintCatalogue::class)->listing();
    }

    /**
     * Record the install. The request-level audit skips Livewire, so without
     * this the one mutating action on the page would be invisible.
     */
    private function audit(string $toolkit, string $slug, int $assistantId, bool $overwrite): void
    {
        app(AdminAuditLogger::class)->recordResource(
            'blueprint',
            $toolkit.'/'.$slug,
            $overwrite ? 'reinstall' : 'install',
            array(),
            array('assistant_id' => $assistantId, 'overwrite' => $overwrite)
        );
    }
}
