<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Tests\TestCase;

/**
 * The project-management admin surface on the Laravel runtime (090-F Phase 2).
 *
 * The console's Records page talks to `wp/v2/{restBase}` and discovers entities
 * from `/pro/records/schema`. Both halves have to know about tasks and projects
 * before the `/pm` page can render, so both are asserted here.
 */
class ProjectManagementAdminTest extends TestCase
{
    use RefreshDatabase;

    private ContentStoreInterface $content;

    protected function setUp(): void
    {
        parent::setUp();

        $this->content = $this->app->make(ContentStoreInterface::class);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    private function seedRecord(string $type, string $title, array $meta = []): int
    {
        return $this->content->create(
            new CreateContentCommand(
                title: $title,
                type: $type,
                authorId: 1,
                status: 'publish',
                content: 'body',
                meta: $meta,
            )
        )->id;
    }

    public function test_the_records_schema_advertises_the_pm_family(): void
    {
        $response = $this->getJson('/api/v1/pro/records/schema')->assertOk();

        $entities = collect($response->json('entities'))->keyBy('key');

        $this->assertSame('mcp_ai_task', $entities['task']['postType']);
        $this->assertSame('tasks', $entities['task']['restBase']);
        $this->assertSame('pm', $entities['task']['group']);

        $this->assertSame('mcp_ai_project', $entities['project']['postType']);
        $this->assertSame('projects', $entities['project']['restBase']);
        $this->assertSame('pm', $entities['project']['group']);

        // The previous entities are untouched.
        $this->assertSame('content', $entities['post']['group']);
    }

    public function test_the_wp_shim_lists_tasks(): void
    {
        $this->seedRecord('mcp_ai_task', 'First task', ['_task_status' => 'todo']);
        $this->seedRecord('mcp_ai_project', 'A project');
        $this->seedRecord('post', 'Not a task');

        $response = $this->getJson('/api/v1/wp/tasks')->assertOk();

        $this->assertSame(1, count($response->json()));
        $this->assertSame('First task', $response->json('0.title.rendered'));
        $this->assertSame('todo', $response->json('0.meta._task_status'));
    }

    public function test_the_wp_shim_lists_projects(): void
    {
        $this->seedRecord('mcp_ai_project', 'Launch', ['_project_status' => 'active']);
        $this->seedRecord('mcp_ai_task', 'A task');

        $response = $this->getJson('/api/v1/wp/projects')->assertOk();

        $this->assertSame(1, count($response->json()));
        $this->assertSame('Launch', $response->json('0.title.rendered'));
        $this->assertSame('active', $response->json('0.meta._project_status'));
    }

    public function test_the_wp_shim_creates_a_task_with_meta(): void
    {
        $response = $this->postJson('/api/v1/wp/tasks', [
            'title' => 'From the console',
            'status' => 'publish',
            'meta' => ['_task_status' => 'in-progress', '_task_priority' => 'high'],
        ]);

        $response->assertCreated()
            ->assertJsonPath('type', 'mcp_ai_task')
            ->assertJsonPath('meta._task_status', 'in-progress');

        $this->assertSame(
            1,
            $this->getJson('/api/v1/wp/tasks')->json('0.id')
        );
    }

    public function test_the_wp_shim_reads_one_task(): void
    {
        $id = $this->seedRecord('mcp_ai_task', 'Fetch me');

        $this->getJson('/api/v1/wp/tasks/'.$id)
            ->assertOk()
            ->assertJsonPath('title.rendered', 'Fetch me')
            ->assertJsonPath('type', 'mcp_ai_task');
    }

    public function test_an_unknown_base_is_still_a_wp_style_404(): void
    {
        $this->getJson('/api/v1/wp/invoices')
            ->assertNotFound()
            ->assertJsonPath('code', 'rest_no_route');
    }

    public function test_the_pm_feature_is_enabled_for_the_console_nav(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.features.project_management', true);
    }
}
