<?php

namespace Tests\Feature;

use App\Filament\Admin\Navigation\AdminNavigationGroup;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Proposal 149 §55 — the `/admin` navigation structure.
 *
 * The sidebar is information architecture, so it needs a contract like any other
 * surface: before §55 it was 21 links in one unnamed group, ordered by the date
 * each page shipped. This test pins the grouping, the order **inside** each
 * group, and the one deliberate exception, by reading `AdminNavigationGroup`
 * rather than a second copy of the mapping.
 *
 * What it catches that a rendered-HTML assertion would not:
 *
 * - a new page that declares no `$navigationGroup` (it would land in the
 *   ungrouped bucket, which must contain only the dashboard);
 * - a page filed into a group the enum does not define;
 * - a page renamed without updating the group it belongs to;
 * - a group emptied by a page being hidden, which Filament would silently drop
 *   from the sidebar.
 *
 * It asserts on `Panel::getNavigation()` — the same array the sidebar renders —
 * not on the Blade markup, so a Filament class rename cannot turn this red.
 */
final class AdminNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_sidebar_is_the_documented_groups_in_order(): void
    {
        [$ungrouped, $groups] = $this->sidebar();

        $expected = array();

        foreach (AdminNavigationGroup::cases() as $group) {
            $expected[$group->getLabel()] = $group->items();
        }

        $this->assertSame($expected, $groups, 'The sidebar does not match AdminNavigationGroup (labels, contents or group order).');
    }

    /**
     * Filament sorts ungrouped items first, and that is where the dashboard
     * belongs. It is the only exception: grouping it would mean shipping a
     * subclass of a vendor page that is not a contract surface.
     */
    public function test_the_only_ungrouped_item_is_the_dashboard(): void
    {
        [$ungrouped] = $this->sidebar();

        $this->assertSame(
            array('Dashboard'),
            $ungrouped,
            'Every nav item except the dashboard must declare an AdminNavigationGroup.'
        );
    }

    /**
     * A group of one is a label over a single link — noise. Settings is the
     * documented exception, because filing it under Activity or Connections
     * would mis-describe it.
     */
    public function test_every_group_holds_at_least_two_items_except_settings(): void
    {
        foreach (AdminNavigationGroup::cases() as $group) {
            $count = count($group->items());

            if (AdminNavigationGroup::Settings === $group) {
                $this->assertSame(1, $count, 'Settings is the single-item exception; anything else needs a second item.');

                continue;
            }

            $this->assertGreaterThanOrEqual(
                2,
                $count,
                sprintf('Group "%s" has one item; fold it into a neighbour.', $group->getLabel())
            );
        }
    }

    /**
     * Filament renders a group header as a `<div>` with an Alpine click handler:
     * mouse-only, and a screen reader is not told the group is collapsible. The
     * panel registers a render hook that restores the disclosure semantics; this
     * asserts the hook actually reaches the page, because a provider edit that
     * drops it would otherwise be invisible — the sidebar still looks and clicks
     * fine with a mouse.
     */
    public function test_the_sidebar_renders_the_disclosure_enhancement(): void
    {
        $response = $this->actingAs(User::factory()->admin()->create())
            ->get('/admin/default/blueprints')
            ->assertOk();

        $response->assertSee('sidebarDisclosure', false);
        $response->assertSee('aria-expanded', false);
    }

    /**
     * A group with no icon degrades the collapsed rail: Filament only replaces a
     * group's items with a dropdown **when the group has an icon**, so a missing
     * one turns six rail icons back into twenty-one. The enum therefore carries
     * an icon per case, and this test proves it reaches the rendered group.
     */
    public function test_every_rendered_group_carries_an_icon(): void
    {
        foreach ($this->navigation() as $group) {
            $label = $group->getLabel();
            $label = $label instanceof Htmlable ? $label->toHtml() : (string) $label;

            if ('' === $label) {
                continue;
            }

            $this->assertNotNull(
                $group->getIcon(),
                sprintf('Group "%s" has no icon, so the collapsed rail would list its items individually.', $label)
            );
        }
    }

    /**
     * The panel's navigation, as `[ungrouped labels, group label => labels]`.
     *
     * Filament mounts navigation lazily on `Panel::getNavigation()`, and the
     * pages it mounts check `canAccess()` and build tenant-prefixed URLs, so the
     * panel needs an authenticated estate member and a resolved tenant first.
     *
     * @return array{0: list<string>, 1: array<string, list<string>>}
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
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs(User::factory()->admin()->create());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        return Filament::getPanel('admin')->getNavigation();
    }
}
