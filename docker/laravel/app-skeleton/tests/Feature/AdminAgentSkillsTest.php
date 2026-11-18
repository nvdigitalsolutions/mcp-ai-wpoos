<?php

namespace Tests\Feature;

use App\Filament\Admin\Pages\AgentSkills;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §49 — `/admin/agent-skills`.
 *
 * The row has three console keys and no WordPress write path on this runtime,
 * so the page is read-only. These tests pin the properties that matter beyond
 * layout:
 *
 *  - the shortcut list is estate-scoped. `ToolShortcutsController` used a bare
 *    `find()` and a bare `is_default` lookup, so an id from another estate
 *    returned that estate's configuration and a foreign default could win the
 *    fallback. Both now resolve through `AssistantShortcutCatalogue`.
 *  - the console payload is unchanged by that extraction — it is a published
 *    contract read by the shared drawer.
 *  - slash commands are filtered by the executor's own capability check, not by
 *    a second rule invented in the page.
 *  - the page has no form: an install/execute control would move the contract
 *    row from `read-only` to something that declares a writer.
 */
final class AdminAgentSkillsTest extends TestCase
{
    use RefreshDatabase;

    /** @var list<string> */
    private array $tempDirs = [];

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');
    }

    protected function tearDown(): void
    {
        foreach ($this->tempDirs as $directory) {
            $this->removeDirectory($directory);
        }

        parent::tearDown();
    }

    public function test_the_page_lists_the_installed_skills_and_the_directory(): void
    {
        $path = $this->skillsDirectory([
            'invoice' => ['name' => 'Invoice Reconciliation', 'description' => 'Match invoices to purchase orders.', 'license' => 'MIT', 'compatibility' => 'nvoos >= 1.0'],
            'summarise' => ['name' => 'Summarise Thread', 'description' => 'Condense a long conversation.', 'license' => '', 'compatibility' => ''],
        ]);

        config(['oos.skills.path' => $path]);

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/agent-skills')
            ->assertOk()
            ->assertSee('Skills, shortcuts and slash commands')
            ->assertSee('Invoice Reconciliation')
            ->assertSee('Summarise Thread')
            ->assertSee($path)
            ->assertSee('Read-only');
    }

    public function test_a_missing_skills_directory_is_an_empty_catalogue(): void
    {
        config(['oos.skills.path' => sys_get_temp_dir().'/nvoos-missing-'.bin2hex(random_bytes(6))]);

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/agent-skills')
            ->assertOk()
            ->assertSee('No skill folders are present in that directory.');
    }

    public function test_the_skill_search_narrows_the_list(): void
    {
        $path = $this->skillsDirectory([
            'invoice' => ['name' => 'Invoice Reconciliation', 'description' => 'Match invoices.', 'license' => '', 'compatibility' => ''],
            'summarise' => ['name' => 'Summarise Thread', 'description' => 'Condense a conversation.', 'license' => '', 'compatibility' => ''],
        ]);

        config(['oos.skills.path' => $path]);

        $this->bootPanel();

        Livewire::test(AgentSkills::class)
            ->set('skillSearch', 'invoice')
            ->assertSee('Invoice Reconciliation')
            ->assertDontSee('Summarise Thread');
    }

    public function test_the_page_lists_the_slash_commands_this_account_may_run(): void
    {
        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/agent-skills')
            ->assertOk()
            ->assertSee('/help')
            ->assertSee('/status')
            ->assertSee('List the tools you can execute');
    }

    public function test_the_assistant_selector_lists_only_the_estate(): void
    {
        $this->assistant(Tenant::DEFAULT_ID, 'Own assistant', 'Own shortcut');
        $this->assistant('estate-b', 'Foreign assistant', 'Foreign shortcut');

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/agent-skills')
            ->assertOk()
            ->assertSee('Own assistant')
            ->assertDontSee('Foreign assistant');
    }

    public function test_the_shortcut_rows_show_the_selected_assistants_configuration(): void
    {
        $own = $this->assistant(Tenant::DEFAULT_ID, 'Own assistant', 'Own shortcut');

        $this->bootPanel();

        Livewire::test(AgentSkills::class)
            ->set('assistantId', (string) $own->id)
            ->assertSee('Own shortcut')
            ->assertSee('summarise');
    }

    public function test_a_foreign_assistant_id_yields_no_shortcuts(): void
    {
        // An own assistant keeps the selector and the shortcut section on the
        // page, so the assertion is about the resolved assistant, not about an
        // empty estate.
        $this->assistant(Tenant::DEFAULT_ID, 'Own assistant', 'Own shortcut');
        $foreign = $this->assistant('estate-b', 'Foreign assistant', 'Foreign shortcut');

        $this->bootPanel();

        Livewire::test(AgentSkills::class)
            ->set('assistantId', (string) $foreign->id)
            ->assertDontSee('Foreign shortcut')
            ->assertSee('This assistant exposes no shortcuts');
    }

    public function test_the_console_payload_keeps_its_shape_and_estate_scope(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $own = $this->assistant(Tenant::DEFAULT_ID, 'Own assistant', 'Own shortcut');
        $foreign = $this->assistant('estate-b', 'Foreign assistant', 'Foreign shortcut');

        $this->getJson('/api/v1/pro/tool-shortcuts?assistant_id='.$own->id)
            ->assertOk()
            ->assertJsonPath('assistant_id', $own->id)
            ->assertJsonPath('shortcuts.0.label', 'Own shortcut')
            ->assertJsonStructure(array('shortcuts', 'categories', 'assistant_id'));

        // A foreign id is absent, not borrowed.
        $this->getJson('/api/v1/pro/tool-shortcuts?assistant_id='.$foreign->id)
            ->assertOk()
            ->assertJsonPath('assistant_id', 0)
            ->assertJsonPath('shortcuts', array());
    }

    public function test_the_default_assistant_fallback_is_estate_scoped(): void
    {
        Sanctum::actingAs(User::factory()->admin()->create());

        $own = $this->assistant(Tenant::DEFAULT_ID, 'Own assistant', 'Own shortcut', true);

        // The trap: `value('id')` on an unscoped query can return this row.
        $this->assistant('estate-b', 'Foreign assistant', 'Foreign shortcut', true);

        $this->getJson('/api/v1/pro/tool-shortcuts')
            ->assertOk()
            ->assertJsonPath('assistant_id', $own->id)
            ->assertJsonPath('shortcuts.0.label', 'Own shortcut');
    }

    public function test_a_viewer_is_refused_the_page(): void
    {
        $viewer = User::factory()->create();
        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($viewer->id => array('role' => 'viewer')));

        $this->actingAs($viewer)
            ->get('/admin/default/agent-skills')
            ->assertForbidden();
    }

    /**
     * A page that cannot accept a payload cannot grow a second writer by
     * accident. Adding form state here means the contract row has to move from
     * `read-only` to something that declares a writer.
     */
    public function test_the_page_accepts_no_payload(): void
    {
        $traits = class_uses_recursive(AgentSkills::class);

        $this->assertArrayNotHasKey(\Filament\Forms\Concerns\InteractsWithForms::class, $traits);
        $this->assertFalse(
            is_subclass_of(AgentSkills::class, \Filament\Forms\Contracts\HasForms::class),
            'The agent-skills surface is read-only; a form would need the contract row to declare a writer.'
        );
    }

    private function bootPanel(): void
    {
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($this->estateAdmin());
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function assistant(string $tenantId, string $title, string $shortcut, bool $default = false): Assistant
    {
        $assistant = new Assistant([
            'title' => $title,
            'slug' => str($title)->slug()->value(),
            'status' => 'draft',
            'is_default' => $default,
            'meta' => [
                'tool_shortcuts' => [
                    ['label' => $shortcut, 'tool' => 'summarise', 'category' => 'Writing', 'payload' => $shortcut],
                ],
            ],
        ]);

        $assistant->tenant_id = $tenantId;
        $assistant->save();

        return $assistant;
    }

    /**
     * Write a throwaway skills directory and return its path.
     *
     * @param array<string, array{name: string, description: string, license: string, compatibility: string}> $skills
     */
    private function skillsDirectory(array $skills): string
    {
        $root = sys_get_temp_dir().'/nvoos-skills-'.bin2hex(random_bytes(6));
        $this->tempDirs[] = $root;

        foreach ($skills as $slug => $skill) {
            $directory = $root.'/'.$slug;
            mkdir($directory, 0777, true);

            file_put_contents(
                $directory.'/SKILL.md',
                "---\n"
                .'name: '.$skill['name']."\n"
                .'description: '.$skill['description']."\n"
                .'license: '.$skill['license']."\n"
                .'compatibility: '.$skill['compatibility']."\n"
                ."---\n\nBody.\n"
            );
        }

        return $root;
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }
}
