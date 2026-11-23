<?php

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
//
// False positive, scoped to this file and to this one sniff.
// PHPCompatibilityWP 2.1.8 does not parse PHP 8.1 enum bodies, so it treats every
// method in this enum as a plain function and rejects `$this` on all three of
// them. The evidence is a two-line probe: the same `match ($this)` passes in a
// class and fails in an enum, and so does `switch ($this)`, and so does a bare
// `$this->value`. No rewrite of this file satisfies the sniff while the enum
// implements `HasLabel` and `HasIcon`, which are instance methods by definition.
//
// The CI job only checks CHANGED files, which is why this surfaced here (§106)
// and not before: the file fails the check whenever it is touched, so it was
// finally touched.
//
// Remove this block when the standard understands enums. The rest of the file
// is still judged — by WPCS, and by the Laravel standard.

namespace App\Filament\Admin\Navigation;

use BackedEnum;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The `/admin` navigation groups — Proposal 149 §55.
 *
 * **Case order is group order.** `NavigationManager::get()` sorts each group by
 * `array_search($case, $case::cases())`, so this file, not
 * `AdminPanelProvider`, decides the shape of the sidebar.
 *
 * Why an enum rather than 21 `$navigationGroup` strings:
 *
 * - the next page cannot invent a seventh group by typo — `AdminNavigationTest`
 *   reads this enum and fails when an item is filed nowhere or a case is empty;
 * - the labels are one edit away from `__()` when the panel is translated;
 * - re-grouping after real use (the split in §55.4 is reasoned from tasks, not
 *   measured from usage) is a one-file change.
 *
 * **Every case must carry an icon.** With the sidebar collapsible on desktop
 * (`AdminPanelProvider`), Filament renders a group as an icon that opens a
 * dropdown instead of listing its items in the rail — but only when the group
 * has an icon. A group without one falls back to rendering all of its items as
 * individual rail icons, which is how a six-group rail turns back into a
 * twenty-one-icon rail. `AdminNavigationTest` enforces the icon for that reason,
 * not for decoration.
 *
 * Note what the icon does to the *expanded* list: Filament nulls each item's own
 * icon when the group has one and the sidebar is collapsible
 * (`sidebar/group.blade.php`: `$hasDropdown` ⇒ `$itemIcon = null`). The icons are
 * not lost — the collapsed rail's dropdown prints them — but the expanded
 * sidebar becomes icon-plus-label per group and text-only per item. That is the
 * framework's intended minimal design, and it is why this file's icons must be
 * distinct enough to identify a group on their own.
 *
 * **Dashboard is deliberately not in a group.** It is Filament's own page
 * (`Filament\Pages\Dashboard`, registered by the panel provider), and grouping
 * it would mean shipping an `app/Filament/Admin/Pages/Dashboard.php` subclass —
 * which `SurfaceOwnershipTest` requires to be declared in
 * `contracts/surface-ownership.json`, and the dashboard is not a ported
 * WordPress surface. Filament sorts ungrouped items first
 * (`NavigationManager::get()` returns `-1` for a blank group label), so the
 * dashboard keeps its conventional position above the groups.
 */
enum AdminNavigationGroup: string implements HasIcon, HasLabel
{
    case Workspace = 'workspace';
    case Knowledge = 'knowledge';
    case Automation = 'automation';
    case Connections = 'connections';
    case Activity = 'activity';
    case Settings = 'settings';

    public function getLabel(): string
    {
        return match ($this) {
            self::Workspace => 'Workspace',
            self::Knowledge => 'Knowledge',
            self::Automation => 'Automation',
            self::Connections => 'Connections',
            self::Activity => 'Activity',
            self::Settings => 'Settings',
        };
    }

    /**
     * The group's icon — the collapsed rail's only affordance.
     *
     * Chosen to be distinct from the icons of the items inside the group, because
     * in the rail the group icon is all a reader has to go on.
     */
    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Workspace => Heroicon::OutlinedRectangleGroup,
            self::Knowledge => Heroicon::OutlinedBookOpen,
            self::Automation => Heroicon::OutlinedArrowPath,
            self::Connections => Heroicon::OutlinedLink,
            self::Activity => Heroicon::OutlinedClock,
            self::Settings => Heroicon::OutlinedCog6Tooth,
        };
    }

    /**
     * The items in this group, by navigation label, in display order.
     *
     * Task order, not alphabetical: a settings page must not sit next to a
     * roster because "S" does. `AdminNavigationTest` compares the rendered
     * sidebar to this map, so adding a page without deciding where it belongs —
     * or renaming one — fails the build.
     *
     * @return list<string>
     */
    public function items(): array
    {
        return match ($this) {
            self::Workspace => array(
                'Command centre', 'Assistants', 'Teams', 'Access requests', 'Blueprints', 'Portability',
            ),
            self::Knowledge => array(
                'Tools', 'Tool quotas', 'Tool flags', 'Toolkits', 'Knowledge (OKF)', 'Paper Store', 'Media', 'Memory',
                'Agent skills', 'Professions',
            ),
            self::Automation => array('Automations', 'Workflow runs', 'Approvals'),
            self::Connections => array('MCP apps', 'Remote sites', 'Credentials', 'Personal tokens'),
            self::Activity => array('Dashboards', 'Transcripts', 'Security', 'Spend'),
            self::Settings => array('Settings', 'Presets'),
        };
    }
}
