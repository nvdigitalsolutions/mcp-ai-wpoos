<?php

// phpcs:disable PHPCompatibility.Variables.ForbiddenThisUseContexts.OutsideObjectContext
//
// The same false positive `AdminNavigationGroup` documents at length, in the same
// place and for the same reason: PHPCompatibilityWP 2.1.8 does not parse PHP 8.1
// enum bodies, so it treats every method here as a plain function and rejects
// `$this` on all three. The CI job only inspects CHANGED files, which is why this
// file fails the check the moment it is touched rather than from the day it was
// written.
//
// Remove this block when the standard understands enums.

namespace App\Filament\Operator\Navigation;

use BackedEnum;
use Filament\Support\Contracts\HasIcon;
use Filament\Support\Contracts\HasLabel;
use Filament\Support\Icons\Heroicon;
use Illuminate\Contracts\Support\Htmlable;

/**
 * The `/ops` navigation groups — Proposal 149 §115.
 *
 * `/ops` had eighteen items and no groups at all, ordered by a `navigationSort`
 * integer. That had drifted into a real defect rather than a preference: **four
 * sort ties** (three pages at 12, two at 13, two at 14) meant the displayed order
 * depended on registration order, so the sidebar could reorder itself when an
 * unrelated provider changed — and a reader had no signal about what any two
 * adjacent pages had in common.
 *
 * This mirrors `AdminNavigationGroup` deliberately, including its reasoning:
 *
 * - **Case order is group order.** `NavigationManager::get()` sorts each group by
 *   `array_search($case, $case::cases())`, so this file, not
 *   `OperatorPanelProvider`, decides the shape of the sidebar.
 * - **An enum rather than a `$navigationGroup` string per page**, so the next page
 *   cannot invent an eighth group by typo — `OperatorNavigationTest` reads this
 *   enum and fails when an item is filed nowhere or a case is empty.
 * - **`items()` is the ordered membership list**, so the ties that used to be
 *   implicit become explicit and testable. Moving a page is one edit here and one
 *   `$navigationGroup` on the page.
 *
 * **Every case carries an icon even though `/ops` is not collapsible today.** The
 * expanded sidebar renders a group as a label; the icon is what the collapsed rail
 * shows when `sidebarCollapsibleOnDesktop()` is switched on. Carrying them now
 * means turning that on later is one line in the panel provider rather than a
 * second pass over this file. `OperatorNavigationTest` asserts the icon for the
 * same reason `AdminNavigationTest` does: a group without one silently degrades the
 * rail into a wall of individual item icons.
 *
 * **`Models` and `Estates` are Filament resources, not pages**, and both still
 * carry a group here. A resource with `$navigationGroup` set to this enum joins the
 * same list as a page, which is why `items()` names their navigation labels exactly
 * as the pages' are named.
 */
enum OperatorNavigationGroup: string implements HasIcon, HasLabel
{
    case Operations = 'operations';
    case Health = 'health';
    case Insight = 'insight';
    case Spend = 'spend';
    case Access = 'access';
    case Platform = 'platform';
    case Settings = 'settings';

    public function getLabel(): string
    {
        return match ($this) {
            self::Operations => 'Operations',
            self::Health => 'Health',
            self::Insight => 'Insight',
            self::Spend => 'Spend',
            self::Access => 'Access & audit',
            self::Platform => 'Platform',
            self::Settings => 'Settings',
        };
    }

    /**
     * The group's icon — the collapsed rail's only affordance.
     *
     * Distinct from the icons of the items inside the group, because in the rail
     * the group icon is all a reader has to go on.
     */
    public function getIcon(): string|BackedEnum|Htmlable|null
    {
        return match ($this) {
            self::Operations => Heroicon::OutlinedBolt,
            self::Health => Heroicon::OutlinedHeart,
            self::Insight => Heroicon::OutlinedChartBar,
            self::Spend => Heroicon::OutlinedBanknotes,
            self::Access => Heroicon::OutlinedShieldCheck,
            self::Platform => Heroicon::OutlinedServerStack,
            self::Settings => Heroicon::OutlinedCog6Tooth,
        };
    }

    /**
     * The items in this group, by navigation label, in display order.
     *
     * Task order, not alphabetical — "Audit log" must follow the two credential
     * surfaces it explains, and "Settings" must not sit between "Scheduler" and
     * "Site health" because both begin with S.
     *
     * The six groups that carry work each hold at least two items. `Settings` is
     * the single-item exception, exactly as on `/admin`: it is a destination, not a
     * category, and splitting one settings form across two groups to satisfy a
     * count would be worse than the exception.
     *
     * @return list<string>
     */
    public function items(): array
    {
        return match ($this) {
            self::Operations => array('Queue', 'Scheduler'),
            self::Health => array('Site health', 'Diagnostics', 'Asset inventory'),
            self::Insight => array('Reports', 'Metrics', 'Observability', 'Datasets'),
            self::Spend => array('Budgets', 'Token usage', 'Token analytics'),
            self::Access => array('Credentials', 'Personal tokens', 'Audit log'),
            self::Platform => array('Estates', 'Models', 'Conversation import'),
            self::Settings => array('Settings'),
        };
    }
}
