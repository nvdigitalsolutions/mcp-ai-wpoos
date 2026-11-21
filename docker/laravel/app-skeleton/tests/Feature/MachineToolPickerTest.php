<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\MachineTools;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Settings\SettingsService;
use App\Support\Tools\MachineToolAllowlist;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * `/ops/machine-tools` — Proposal 149 §128.
 *
 * §127's field works but asks an operator to type slugs. This page is the
 * picker over the same setting, and the properties pinned here are the ones
 * that keep it honest:
 *
 *  - it lists only **servable** tools, so a toggle always does something;
 *  - it writes the panel list, never the deploy's config — a remove cannot
 *    narrow what config/oos.php allows;
 *  - a slug that is not servable is refused even if someone posts it;
 *  - every change leaves an audit row, because Livewire traffic is excluded
 *    from the request-level audit.
 */
final class MachineToolPickerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(array(
            'oos.machine_tools' => array(),
            'sanctum.stateful' => array('localhost', '127.0.0.1'),
        ));

        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_renders_for_an_operator(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/machine-tools')
            ->assertOk()
            ->assertSee('What the allowlist decides');
    }

    public function test_rows_carry_the_annotation_and_the_source(): void
    {
        config(array('oos.machine_tools' => array('base64')));

        $rows = array();

        foreach ((new MachineTools())->rows() as $row) {
            $rows[$row['slug']] = $row;
        }

        $this->assertArrayHasKey('base64', $rows, 'A servable tool must be listed.');
        $this->assertTrue($rows['base64']['allowlisted']);
        $this->assertSame('config', $rows['base64']['source']);

        $this->assertArrayHasKey('create_post', $rows);
        $this->assertFalse($rows['create_post']['allowlisted']);
        $this->assertSame('', $rows['create_post']['source']);
    }

    public function test_allow_writes_the_setting_and_deny_removes_it(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(MachineTools::class)->call('allowTool', 'base64');

        $allowlist = app(MachineToolAllowlist::class);
        $this->assertTrue($allowlist->allows('base64'));

        Livewire::test(MachineTools::class)->call('denyTool', 'base64');

        $this->assertFalse($allowlist->allows('base64'));
    }

    public function test_a_config_entry_cannot_be_removed_from_the_page(): void
    {
        config(array('oos.machine_tools' => array('base64')));

        $this->actingAs($this->operator());

        Livewire::test(MachineTools::class)->call('denyTool', 'base64');

        $this->assertTrue(
            app(MachineToolAllowlist::class)->allows('base64'),
            'A panel click must not narrow a decision made in config.'
        );
    }

    public function test_a_slug_that_is_not_servable_is_refused(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(MachineTools::class)->call('allowTool', 'not_a_tool');

        $this->assertFalse(app(MachineToolAllowlist::class)->allows('not_a_tool'));
        $stored = app(SettingsService::class)->value('machine_tools');

        $this->assertSame(array(), MachineToolAllowlist::parse($stored));
    }

    public function test_every_change_leaves_an_audit_row(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(MachineTools::class)->call('allowTool', 'base64');

        $row = DB::table('nvoos_admin_audit')
            ->where('resource', 'machine_tools')
            ->where('record_id', 'base64')
            ->latest('id')
            ->first();

        $this->assertNotNull($row, 'A change to the gate must leave a record.');
        $this->assertSame('allowed', (string) $row->action);
    }

    public function test_an_estate_administrator_cannot_reach_the_page(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/ops/machine-tools')
            ->assertForbidden();
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}
