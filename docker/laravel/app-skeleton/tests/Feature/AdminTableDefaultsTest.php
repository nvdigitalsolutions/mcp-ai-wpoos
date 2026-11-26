<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Assistants\Pages\ManageAssistants;
use App\Filament\Admin\Resources\Teams\Pages\ManageTeams;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Filament\Tables\Enums\FiltersLayout;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §55.5, step 5 — one place for the resource table defaults.
 *
 * `Table::configureUsing()` runs at construction, so a resource's `table()` stays
 * about *its* columns. The defaults are asserted on the **real** pages rather
 * than on a synthetic table, because the point is that a resource inherits them
 * without opting in; a hand-built table would prove the callback runs but not
 * that both resources get it.
 *
 * What is and is not covered here:
 *
 *  - Pagination, the page-size list and the filters layout all have getters, so
 *    they are asserted directly. The framework defaults are `[5, 10, 25, 50]`
 *    and a page size of **10** (resolved by `getDefaultPaginationPageOption()`
 *    when 10 is one of the options), so `25` is a real change, not a no-op.
 *  - `persistInSession()` and `loadingSkeleton()` are booleans with **no
 *    getters** on `Table`. Asserting them would mean reflecting on protected
 *    properties, which tests the framework's shape rather than our behaviour;
 *    they are configured in `AppServiceProvider` and their effect — a persistent
 *    sort, a skeleton under deferred loading — is visible only across a
 *    navigation or with `deferLoading()`, which no table enables yet.
 */
final class AdminTableDefaultsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_both_resource_tables_share_the_panel_defaults(): void
    {
        $tenant = $this->bootPanel();

        foreach (array(ManageAssistants::class, ManageTeams::class) as $page) {
            $table = Livewire::test($page, array('tenant' => $tenant))->instance()->getTable();

            $this->assertTrue($table->isPaginated(), $page.' should paginate.');
            $this->assertSame(array(10, 25, 50), $table->getPaginationPageOptions(), $page);
            $this->assertSame(25, $table->getDefaultPaginationPageOption(), $page);
            $this->assertSame(
                FiltersLayout::AboveContentCollapsible,
                $table->getFiltersLayout(),
                $page
            );
        }
    }

    private function bootPanel(): Tenant
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $user = User::factory()->admin()->create();
        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        return $tenant;
    }
}
