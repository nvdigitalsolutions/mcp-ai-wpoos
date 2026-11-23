<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\SkillManager;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Skills\SkillInstallInvalid;
use App\Support\Skills\SkillInstaller;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §190 — the skill manager and the security pass behind it.
 *
 * `SkillCatalogue` was read-only from §49 with a reason attached: install,
 * replace and delete are "file writes driven by HTTP input and deserve their own
 * security pass". These tests are that pass, stated as rules:
 *
 *  - the folder name comes from the parsed frontmatter, never from input;
 *  - a name that would escape the root cannot escape the root;
 *  - input that is not a SKILL.md is refused rather than stored;
 *  - a duplicate is refused unless the caller said "replace";
 *  - delete never removes a folder holding files this panel did not write;
 *  - every write records an audit row, because Livewire traffic is not audited
 *    by the request middleware.
 */
final class SkillManagerTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        $this->root = storage_path('framework/testing/skills-'.bin2hex(random_bytes(5)));

        config(array(
            'cache.default' => 'array',
            'oos.skills.path' => $this->root,
        ));
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    // ── The installer ───────────────────────────────────────────────────

    public function test_it_installs_a_skill_under_a_slug_derived_from_the_name(): void
    {
        $result = $this->installer()->install($this->skillMd('Design: Chart Recipes!'));

        $this->assertSame('design-chart-recipes', $result['slug']);
        $this->assertFalse($result['replaced']);
        $this->assertFileExists($this->root.'/design-chart-recipes/SKILL.md');
        $this->assertSame(
            1,
            DB::table('nvoos_admin_audit')->where('resource', 'skill')->where('action', 'installed')->count()
        );
    }

    public function test_it_refuses_input_without_frontmatter(): void
    {
        $this->expectException(SkillInstallInvalid::class);

        $this->installer()->install("# Just markdown\n\nNo frontmatter here.");
    }

    public function test_it_refuses_frontmatter_without_a_name(): void
    {
        $this->expectException(SkillInstallInvalid::class);

        $this->installer()->install("---\ndescription: Something\n---\n\nBody.");
    }

    public function test_it_refuses_a_name_that_slugifies_to_nothing(): void
    {
        $this->expectException(SkillInstallInvalid::class);

        $this->installer()->install($this->skillMd('!!! ???'));
    }

    public function test_it_refuses_an_oversized_file(): void
    {
        $padding = str_repeat('x', SkillInstaller::MAX_BYTES + 1);

        $this->expectException(SkillInstallInvalid::class);

        $this->installer()->install($this->skillMd('Huge', $padding));
    }

    public function test_a_duplicate_is_refused_and_a_replace_is_not(): void
    {
        $installer = $this->installer();

        $installer->install($this->skillMd('Design Guide', 'First body.'));

        try {
            $installer->install($this->skillMd('Design Guide', 'Second body.'));
            $this->fail('A duplicate install must be refused.');
        } catch (SkillInstallInvalid $error) {
            $this->assertStringContainsString('already installed', $error->getMessage());
        }

        $result = $installer->install($this->skillMd('Design Guide', 'Second body.'), true);

        $this->assertTrue($result['replaced']);
        $this->assertStringContainsString(
            'Second body.',
            (string) file_get_contents($this->root.'/design-guide/SKILL.md')
        );
        $this->assertSame(
            1,
            DB::table('nvoos_admin_audit')->where('resource', 'skill')->where('action', 'replaced')->count()
        );
    }

    public function test_a_traversal_attempt_lands_inside_the_root(): void
    {
        // The slug is built from the *parsed* name, so separators cannot survive.
        $result = $this->installer()->install($this->skillMd('../../etc/passwd'));

        $this->assertSame('etc-passwd', $result['slug']);
        $this->assertFileExists($this->root.'/etc-passwd/SKILL.md');
        $this->assertFileDoesNotExist(dirname($this->root).'/etc/passwd');
    }

    public function test_a_crlf_paste_is_accepted(): void
    {
        // The parser anchors on "\n"; a Windows paste must not read as "no frontmatter".
        $result = $this->installer()->install(str_replace("\n", "\r\n", $this->skillMd('Windows Paste')));

        $this->assertSame('windows-paste', $result['slug']);
    }

    public function test_delete_removes_the_folder(): void
    {
        $installer = $this->installer();
        $installer->install($this->skillMd('Throwaway'));

        $installer->delete('throwaway');

        $this->assertDirectoryDoesNotExist($this->root.'/throwaway');
        $this->assertSame(
            1,
            DB::table('nvoos_admin_audit')->where('resource', 'skill')->where('action', 'deleted')->count()
        );
    }

    public function test_delete_refuses_a_folder_holding_other_files(): void
    {
        $installer = $this->installer();
        $installer->install($this->skillMd('With Assets'));

        file_put_contents($this->root.'/with-assets/notes.txt', 'mine');

        try {
            $installer->delete('with-assets');
            $this->fail('A folder with extra files must not be deleted.');
        } catch (SkillInstallInvalid $error) {
            $this->assertStringContainsString('on the server', $error->getMessage());
        }

        $this->assertFileExists($this->root.'/with-assets/SKILL.md', 'The refusal must not half-delete.');
        $this->assertFileExists($this->root.'/with-assets/notes.txt');
    }

    public function test_delete_refuses_a_path_shaped_slug(): void
    {
        $this->expectException(SkillInstallInvalid::class);

        $this->installer()->delete('../etc');
    }

    public function test_delete_refuses_an_unknown_skill(): void
    {
        $this->expectException(SkillInstallInvalid::class);

        $this->installer()->delete('never-installed');
    }

    // ── The page ────────────────────────────────────────────────────────

    public function test_an_operator_can_install_from_the_page(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(SkillManager::class)
            ->callAction('install', data: array('content' => $this->skillMd('Page Install')));

        $this->assertFileExists($this->root.'/page-install/SKILL.md');
    }

    public function test_a_tenant_admin_cannot_reach_the_page(): void
    {
        $this->actingAs($this->customerAdmin())
            ->get('/ops/skills')
            ->assertForbidden();
    }

    public function test_the_page_lists_what_is_installed(): void
    {
        $this->installer()->install($this->skillMd('Visible Skill'));

        $this->actingAs($this->operator())
            ->get('/ops/skills')
            ->assertOk()
            ->assertSee('Visible Skill')
            ->assertSee('visible-skill');
    }

    // ── Helpers ─────────────────────────────────────────────────────────

    private function installer(): SkillInstaller
    {
        return SkillInstaller::fromConfig();
    }

    private function skillMd(string $name, string $body = 'Do the thing carefully.'): string
    {
        return "---\nname: {$name}\ndescription: A skill used by the tests.\nlicense: MIT\n---\n\n{$body}\n";
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

    private function customerAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    private function removeTree(string $path): void
    {
        if ('' === $path || ! is_dir($path)) {
            return;
        }

        foreach (array_diff(scandir($path) ?: array(), array('.', '..')) as $entry) {
            $full = $path.'/'.$entry;

            is_dir($full) ? $this->removeTree($full) : @unlink($full);
        }

        @rmdir($path);
    }
}
