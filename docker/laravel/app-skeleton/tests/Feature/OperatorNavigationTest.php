<?php

namespace Tests\Feature;

use App\Filament\Operator\Navigation\OperatorNavigationGroup;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The `/ops` sidebar — Proposal 149 §115.
 *
 * `/ops` carried eighteen items in one flat list, ordered by a `navigationSort`
 * integer, with **four sort ties**. Ties mean the rendered order depends on
 * registration order, so an unrelated provider change could reorder the vendor
 * panel's sidebar — and nothing caught it, because no test read the sidebar.
 *
 * This is the `AdminNavigationTest` shape applied to the other panel, and it
 * asserts the same four invariants for the same reasons:
 *
 *  - the sidebar **is** `OperatorNavigationGroup`, so a page cannot be filed
 *    nowhere and a group cannot be empty;
 *  - every group carries an icon, because a group without one degrades the
 *    collapsed rail into individual item icons;
 *  - only `Settings` is allowed to hold a single item;
 *  - an item's label appears once and only once.
 *
 * What it deliberately does not assert is the *order within* a group beyond
 * `items()` itself — that list is the contract, so comparing the rendered list to
 * it is the whole check.
 */
final class OperatorNavigationTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_sidebar_is_the_documented_groups_in_order(): void
    {
        [$ungrouped, $groups] = $this->sidebar();

        $expected = array();

        foreach (OperatorNavigationGroup::cases() as $group) {
            $expected[$group->getLabel()] = $group->items();
        }

        $this->assertSame(
            $expected,
            $groups,
            'The `/ops` sidebar does not match OperatorNavigationGroup (labels, contents or group order).'
        );
    }

    public function test_the_only_ungrouped_item_is_the_dashboard(): void
    {
        // The same exception `/admin` carries, for the same reason: `Dashboard` is
        // Filament's own page registered by the panel provider, not a ported
        // surface, so grouping it would mean shipping a subclass that
        // `SurfaceOwnershipTest` would require a contract row for. Filament sorts
        // ungrouped items first, which is where a dashboard belongs.
        [$ungrouped] = $this->sidebar();

        $this->assertSame(
            array('Dashboard'),
            $ungrouped,
            'Every /ops item except the dashboard belongs to a group.'
        );
    }

    public function test_every_group_holds_at_least_two_items_except_settings(): void
    {
        foreach (OperatorNavigationGroup::cases() as $group) {
            $count = count($group->items());

            if (OperatorNavigationGroup::Settings === $group) {
                $this->assertSame(1, $count, 'Settings is the single-item exception.');

                continue;
            }

            $this->assertGreaterThanOrEqual(
                2,
                $count,
                sprintf('Group "%s" holds one item, so it describes nothing.', $group->getLabel())
            );
        }
    }

    public function test_every_group_carries_an_icon(): void
    {
        foreach (OperatorNavigationGroup::cases() as $group) {
            $this->assertNotNull(
                $group->getIcon(),
                sprintf('Group "%s" has no icon; the collapsed rail would list its items individually.', $group->getLabel())
            );
        }
    }

    public function test_no_item_is_filed_in_two_groups(): void
    {
        $seen = array();

        foreach (OperatorNavigationGroup::cases() as $group) {
            foreach ($group->items() as $item) {
                $this->assertArrayNotHasKey(
                    $item,
                    $seen,
                    sprintf('"%s" is listed by both "%s" and "%s".', $item, $seen[$item] ?? '', $group->getLabel())
                );

                $seen[$item] = $group->getLabel();
            }
        }
    }

    public function test_the_group_enum_covers_every_operator_page(): void
    {
        // The complement of the ungrouped check: that one proves the sidebar has no
        // stray groups, this one proves the enum has no stale entries — an item
        // listed here with no page behind it, or a page that renders under a label
        // the enum does not know.
        [$ungrouped, $groups] = $this->sidebar();

        $rendered = array_merge($ungrouped, ...array_values($groups));

        // Filament's own dashboard is not a ported surface and is not in the enum.
        $rendered = array_values(array_diff($rendered, array('Dashboard')));

        $declared = array_merge(...array_map(
            static fn (OperatorNavigationGroup $group): array => $group->items(),
            OperatorNavigationGroup::cases()
        ));

        sort($rendered);
        sort($declared);

        $this->assertSame($declared, $rendered, 'OperatorNavigationGroup and the rendered sidebar disagree.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * The grouped sidebar actually renders.
     *
     * Regression, §115. Every assertion above reads `getNavigation()`, which
     * builds the group data and renders nothing — so the suite stayed green while
     * **every `/ops` page returned 500**: `sidebar/group.blade.php` throws
     * "Navigation group […] has an icon but one or more of its items also have
     * icons" unless the panel's sidebar is collapsible, and only a real HTTP
     * render goes through that template. This is the cheap guard for the whole
     * class of "the data is right, the layout explodes" defects.
     */
    public function test_the_grouped_sidebar_renders(): void
    {
        $this->actingAsOperator();

        $this->get('/ops')->assertOk();
    }

    /**
     * The panel's navigation, as `[ungrouped labels, group label => labels]`.
     */
    private function sidebar(): array
    {
        $ungrouped = array();
        $groups = array();

        foreach ($this->navigation() as $group) {
            $label = $group->getLabel();
            $label = $label instanceof Htmlable ? $label->toHtml() : (string) $label;

            $items = array();

            foreach ($group->getItems() as $item) {
                $itemLabel = $item->getLabel();
                $items[] = $itemLabel instanceof Htmlable ? $itemLabel->toHtml() : (string) $itemLabel;
            }

            if ('' === $label) {
                $ungrouped = $items;

                continue;
            }

            $groups[$label] = $items;
        }

        return array(array_values($ungrouped), $groups);
    }

    /**
     * @return array<\Filament\Navigation\NavigationGroup>
     */
    private function navigation(): array
    {
        $this->actingAsOperator();

        return Filament::getPanel('operator')->getNavigation();
    }

    /**
     * An operator principal: operator-estate membership plus `manage_options`.
     *
     * `/ops` is not tenant-scoped, but every page it mounts checks
     * `canAccess()` -> `AdminAccessPolicy::accessOperatorPanel()`, which needs
     * BOTH an operator-estate membership and `manage_options`. A plain
     * `admin()` user has neither, so `getNavigation()` would filter every item
     * out and the assertions would compare empty to empty.
     */
    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    /**
     * Authenticate as a fresh operator and point the operator panel at them.
     */
    private function actingAsOperator(): User
    {
        $user = $this->operator();

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        return $user;
    }
}
