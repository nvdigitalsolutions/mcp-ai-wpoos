<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\SettingsSchema;
use App\Support\Settings\SettingsService;
use App\Support\TenantContext;
use App\Support\Tools\MachineToolAllowlist;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The machine allowlist has two sources — Proposal 149 §127.
 *
 * `oos.machine_tools` is a deploy decision; the operator-scope `machine_tools`
 * setting is the panel's. These tests pin the union, the closed failure
 * direction, and the surface that may write it: a gate whose source is
 * editable is only as safe as the edit path.
 */
final class MachineToolAllowlistTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_empty_allowlist_permits_nothing(): void
    {
        config(array('oos.machine_tools' => array()));

        $this->assertSame(array(), app(MachineToolAllowlist::class)->slugs());
    }

    public function test_the_operator_setting_adds_a_servable_tool(): void
    {
        config(array('oos.machine_tools' => array()));

        $applied = app(SettingsService::class)->write(
            array('machine_tools' => 'base64'),
            SettingsService::SURFACE_OPS,
        );

        $this->assertSame(array('machine_tools'), $applied);
        $this->assertContains('base64', app(MachineToolAllowlist::class)->slugs());
    }

    public function test_a_name_that_does_not_resolve_is_ignored(): void
    {
        config(array('oos.machine_tools' => array()));

        app(SettingsService::class)->write(
            array('machine_tools' => 'not_a_tool, base64'),
            SettingsService::SURFACE_OPS,
        );

        $slugs = app(MachineToolAllowlist::class)->slugs();

        $this->assertContains('base64', $slugs);
        $this->assertNotContains('not_a_tool', $slugs);
    }

    public function test_the_setting_cannot_remove_a_config_entry(): void
    {
        config(array('oos.machine_tools' => array('base64')));

        app(SettingsService::class)->write(
            array('machine_tools' => ''),
            SettingsService::SURFACE_OPS,
        );

        $this->assertContains('base64', app(MachineToolAllowlist::class)->slugs());
    }

    public function test_only_the_operator_surface_may_write_the_key(): void
    {
        $ops = array_column(SettingsSchema::writableFor('ops'), 'key');
        $admin = array_column(SettingsSchema::writableFor('admin'), 'key');

        $this->assertContains('machine_tools', $ops);
        $this->assertNotContains('machine_tools', $admin);
    }

    public function test_a_tool_allowlisted_by_the_setting_executes(): void
    {
        config(array('oos.machine_tools' => array()));

        app(SettingsService::class)->write(
            array('machine_tools' => 'base64'),
            SettingsService::SURFACE_OPS,
        );

        [$user, $assistant] = $this->fixture(array('base64'));

        $this->withHeader('Authorization', 'Bearer '.$this->token($user, $assistant))
            ->postJson('/api/v1/tools/base64', array('arguments' => array('text' => 'hi')))
            ->assertOk()
            ->assertJsonPath('data.output', 'aGk=');
    }

    public function test_the_setting_cannot_make_a_state_changing_tool_unattended(): void
    {
        config(array('oos.machine_tools' => array()));

        app(SettingsService::class)->write(
            array('machine_tools' => 'create_post'),
            SettingsService::SURFACE_OPS,
        );

        [$user, $assistant] = $this->fixture(array('create_post'));

        $this->withHeader('Authorization', 'Bearer '.$this->token($user, $assistant))
            ->postJson('/api/v1/tools/create_post', array('arguments' => array('title' => 'Hello')))
            ->assertStatus(202)
            ->assertJsonPath('status', 'pending_approval');
    }

    /**
     * @param list<string> $tools
     * @return array{0: User, 1: Assistant}
     */
    private function fixture(array $tools): array
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        TenantContext::set(Tenant::DEFAULT_ID);

        $assistant = app(AssistantWriter::class)->create(array(
            'title' => 'Machine allowlist fixture',
            'tools' => $tools,
        ));

        TenantContext::forget();

        return array($user, $assistant);
    }

    private function token(User $user, Assistant $assistant): string
    {
        return $user->createToken('machine', array('assistant:'.$assistant->getKey()))->plainTextToken;
    }
}
