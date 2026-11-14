<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-b — the Agent Skills catalogue (`GET /api/v1/skills`).
 *
 * The contract is the console's `SkillsClient`: `{success, skills, total}` with
 * `{name, description, license, compatibility}` per entry. Fixtures are written
 * to a temporary skills directory so the parser, the folder rule and the
 * malformed-file skip are exercised end to end.
 */
class SkillCatalogueTest extends TestCase
{
    use RefreshDatabase;

    private string $skillsPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->skillsPath = sys_get_temp_dir().'/nvoos-skills-'.bin2hex(random_bytes(4));
        mkdir($this->skillsPath, 0777, true);

        config(['oos.skills.path' => $this->skillsPath]);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        foreach (glob($this->skillsPath.'/*/*') ?: [] as $file) {
            @unlink($file);
        }

        foreach (glob($this->skillsPath.'/*', GLOB_ONLYDIR) ?: [] as $directory) {
            @rmdir($directory);
        }

        @rmdir($this->skillsPath);

        parent::tearDown();
    }

    private function writeSkill(string $folder, string $skillMd): void
    {
        mkdir($this->skillsPath.'/'.$folder, 0777, true);
        file_put_contents($this->skillsPath.'/'.$folder.'/SKILL.md', $skillMd);
    }

    public function test_an_empty_directory_serves_an_empty_catalogue(): void
    {
        $this->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJson([
                'success' => true,
                'skills' => [],
                'total' => 0,
            ]);
    }

    public function test_a_missing_directory_serves_an_empty_catalogue(): void
    {
        config(['oos.skills.path' => $this->skillsPath.'/does-not-exist']);

        $this->getJson('/api/v1/skills')
            ->assertOk()
            ->assertJsonPath('total', 0);
    }

    public function test_it_lists_the_catalogue_fields_name_sorted(): void
    {
        $this->writeSkill('zeta-skill', "---\nname: zeta-skill\ndescription: Zeta.\n---\n\nBody.\n");
        $this->writeSkill(
            'alpha-skill',
            "---\nname: alpha-skill\ndescription: Alpha.\nlicense: MIT\ncompatibility: nvoos>=2.5\n---\n\nBody.\n"
        );

        $body = $this->getJson('/api/v1/skills')->assertOk()->json();

        $this->assertTrue($body['success']);
        $this->assertSame(2, $body['total']);
        $this->assertSame(
            [
                [
                    'name' => 'alpha-skill',
                    'description' => 'Alpha.',
                    'license' => 'MIT',
                    'compatibility' => 'nvoos>=2.5',
                ],
                [
                    'name' => 'zeta-skill',
                    'description' => 'Zeta.',
                    'license' => '',
                    'compatibility' => '',
                ],
            ],
            $body['skills']
        );
    }

    public function test_folders_without_a_skill_file_and_unparseable_files_are_skipped(): void
    {
        mkdir($this->skillsPath.'/empty-folder', 0777, true);
        $this->writeSkill('no-frontmatter', "# Just markdown\n");
        $this->writeSkill('no-name', "---\ndescription: Missing a name.\n---\n\nBody.\n");
        $this->writeSkill('valid', "---\nname: valid\ndescription: Fine.\n---\n\nBody.\n");

        $body = $this->getJson('/api/v1/skills')->assertOk()->json();

        $this->assertSame(1, $body['total']);
        $this->assertSame('valid', $body['skills'][0]['name']);
    }

    public function test_an_editor_cannot_reach_the_catalogue(): void
    {
        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/skills')->assertForbidden();
    }
}
