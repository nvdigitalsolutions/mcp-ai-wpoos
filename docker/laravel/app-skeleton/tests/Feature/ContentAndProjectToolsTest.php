<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Tests\TestCase;

/**
 * Batch 5 evidence: the ContentStore-backed tools.
 *
 * Each test calls the tool through the registry and then asserts the *state it
 * left behind* through the store, rather than that a particular store method was
 * called. That is the difference that matters here: these tools are thin, and a
 * tool that returns the right envelope while writing the wrong row would pass an
 * interaction assertion and fail this one.
 */
class ContentAndProjectToolsTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // The content gates resolve a real user row: `read_content` and friends
        // deny an id that does not exist, so a bare `user_id => 1` is refused.
        // They also short-circuit to false when nothing is authenticated, which
        // is why the suite signs in rather than passing context alone.
        $this->user = User::factory()->admin()->create();
        $this->actingAs($this->user);
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function callTool(string $slug, array $arguments = array(), array $context = array()): array
    {
        $context = array() === $context ? array('user_id' => $this->user->id) : $context;

        $result = $this->app->make(ToolRegistry::class)
            ->get($slug)
            ->execute($arguments, $context);

        $this->assertIsArray($result, $slug . ' returned a non-array: ' . print_r($result, true));
        $this->assertTrue($result['success'] ?? false, $slug . ': ' . print_r($result, true));

        return $result;
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    public function test_create_post_round_trips_through_the_store(): void
    {
        $result = $this->callTool('create_post', array(
            'title' => 'Batch five post',
            'content' => 'Body text.',
            'status' => 'publish',
        ));

        $id = (int) $result['data']['id'];
        $this->assertGreaterThan(0, $id);

        $stored = $this->store()->find($id);
        $this->assertNotNull($stored);
        $this->assertSame('Batch five post', $stored->title);
        $this->assertSame('publish', $stored->status);
        $this->assertSame($this->user->id, $stored->authorId);
    }

    public function test_get_post_reads_the_row_back(): void
    {
        $created = $this->callTool('create_post', array('title' => 'Readable post', 'status' => 'publish'));

        $result = $this->callTool('get_post', array('post_id' => $created['data']['id']));

        $this->assertSame($created['data']['id'], $result['data']['id']);
        $this->assertSame('Readable post', $result['data']['title']);
        $this->assertArrayHasKey('meta', $result['data']);
    }

    public function test_update_post_changes_the_stored_title(): void
    {
        $created = $this->callTool('create_post', array('title' => 'Before', 'status' => 'publish'));

        $result = $this->callTool('update_post', array(
            'post_id' => $created['data']['id'],
            'title' => 'After',
        ));

        $this->assertSame('After', $result['data']['title']);

        $stored = $this->store()->find((int) $created['data']['id']);
        $this->assertSame('After', $stored->title);
    }

    public function test_delete_post_removes_the_row(): void
    {
        $created = $this->callTool('create_post', array('title' => 'Doomed', 'status' => 'publish'));
        $id      = (int) $created['data']['id'];

        $result = $this->callTool('delete_post', array('post_id' => $id));

        $this->assertSame($id, $result['data']['id']);
        $this->assertNull($this->store()->find($id));
    }

    public function test_save_post_creates_then_updates(): void
    {
        $created = $this->callTool('save_post', array(
            'title' => 'Saved once',
            'content' => 'First body.',
        ));

        $this->assertSame('Post created successfully.', $created['message']);

        $updated = $this->callTool('save_post', array(
            'post_id' => $created['data']['id'],
            'content' => 'Second body.',
        ));

        $this->assertSame('Post updated successfully.', $updated['message']);
        $this->assertSame('Second body.', $this->store()->find((int) $created['data']['id'])->content);
    }

    public function test_count_posts_defaults_to_published_and_can_filter_status(): void
    {
        $this->callTool('create_post', array('title' => 'One', 'status' => 'publish'));
        $this->callTool('create_post', array('title' => 'Two', 'status' => 'draft'));

        // No status filter means "publish", not "everything".
        $default = $this->callTool('count_posts', array('type' => 'post'));
        $this->assertSame(1, $default['data']['total']);
        $this->assertSame('post', $default['data']['filters']['type']);

        $drafts = $this->callTool('count_posts', array('type' => 'post', 'status' => 'draft'));
        $this->assertSame(1, $drafts['data']['total']);
        $this->assertSame('draft', $drafts['data']['filters']['status']);
    }

    public function test_get_recent_posts_lists_published_posts(): void
    {
        $created = $this->callTool('create_post', array('title' => 'Recent one', 'status' => 'publish'));

        $result = $this->callTool('get_recent_posts', array('count' => 5));

        $ids = array_column($result['data']['items'], 'id');
        $this->assertContains($created['data']['id'], $ids);
        $this->assertSame(1, $result['data']['total']);
    }

    public function test_search_content_finds_a_unique_word(): void
    {
        $this->callTool('create_post', array(
            'title' => 'Quokka sighting',
            'content' => 'A quokkaterm appears only here.',
            'status' => 'publish',
        ));

        $result = $this->callTool('search_content', array('query' => 'quokkaterm'));

        $this->assertGreaterThanOrEqual(1, $result['data']['total']);
        $this->assertSame('Quokka sighting', $result['data']['items'][0]['title']);
    }

    public function test_get_post_meta_reads_a_key_written_by_save_post(): void
    {
        $created = $this->callTool('save_post', array(
            'title' => 'Carries meta',
            'content' => 'Body.',
            'meta_input' => array('fixture_key' => 'fixture-value'),
        ));

        $result = $this->callTool('get_post_meta', array(
            'post_id' => $created['data']['id'],
            'key' => 'fixture_key',
        ));

        $this->assertTrue($result['data']['found']);
        $this->assertSame('fixture-value', $result['data']['value']);
    }

    public function test_get_post_taxonomies_reports_an_empty_set_for_a_new_post(): void
    {
        $created = $this->callTool('create_post', array('title' => 'Unfiled', 'status' => 'publish'));

        $result = $this->callTool('get_post_taxonomies', array('post_id' => $created['data']['id']));

        $this->assertSame($created['data']['id'], $result['data']['post_id']);
        $this->assertSame(0, $result['data']['total_terms']);
        $this->assertSame(array(), $result['data']['taxonomies']);
    }

    public function test_create_project_writes_a_project_row(): void
    {
        $result = $this->callTool('create_project', array(
            'name' => 'Alpha project',
            'description' => 'A project.',
        ));

        $id = (int) $result['data']['project_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertFalse($result['data']['updated']);

        $stored = $this->store()->find($id);
        $this->assertSame('Alpha project', $stored->title);
        $this->assertSame('mcp_ai_project', $stored->type);
        $this->assertSame('planning', $stored->meta['_project_status']);
    }

    public function test_update_project_changes_the_status_meta(): void
    {
        $created = $this->callTool('create_project', array('name' => 'Beta project'));

        $result = $this->callTool('update_project', array(
            'project_id' => $created['data']['project_id'],
            'status' => 'active',
        ));

        $this->assertSame($created['data']['project_id'], $result['data']['project_id']);
        $this->assertSame('active', $this->store()->find((int) $created['data']['project_id'])->meta['_project_status']);
    }

    public function test_delete_project_removes_the_row(): void
    {
        $created = $this->callTool('create_project', array('name' => 'Doomed project'));
        $id      = (int) $created['data']['project_id'];

        $this->callTool('delete_project', array('project_id' => $id));

        $this->assertNull($this->store()->find($id));
    }

    public function test_list_projects_returns_a_created_project(): void
    {
        $created = $this->callTool('create_project', array('name' => 'Listed project'));

        $result = $this->callTool('list_projects', array('limit' => 10));

        $ids = array_column($result['data']['projects'], 'id');
        $this->assertContains($created['data']['project_id'], $ids);
    }

    public function test_create_task_writes_a_task_row(): void
    {
        $result = $this->callTool('create_task', array(
            'title' => 'Alpha task',
            'priority' => 'high',
        ));

        $id = (int) $result['data']['task_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertFalse($result['data']['updated']);

        $stored = $this->store()->find($id);
        $this->assertSame('Alpha task', $stored->title);
        $this->assertSame('mcp_ai_task', $stored->type);
        $this->assertSame('todo', $stored->meta['_task_status']);
    }

    public function test_update_task_sets_the_status_meta(): void
    {
        $created = $this->callTool('create_task', array('title' => 'Moving task'));

        $this->callTool('update_task', array(
            'task_id' => $created['data']['task_id'],
            'status' => 'completed',
        ));

        $this->assertSame('completed', $this->store()->find((int) $created['data']['task_id'])->meta['_task_status']);
    }

    public function test_delete_task_removes_the_row(): void
    {
        $created = $this->callTool('create_task', array('title' => 'Doomed task'));
        $id      = (int) $created['data']['task_id'];

        $this->callTool('delete_task', array('task_id' => $id));

        $this->assertNull($this->store()->find($id));
    }

    public function test_list_tasks_returns_a_created_task(): void
    {
        $created = $this->callTool('create_task', array('title' => 'Listed task'));

        $result = $this->callTool('list_tasks', array('limit' => 10));

        $ids = array_column($result['data']['tasks'], 'id');
        $this->assertContains($created['data']['task_id'], $ids);
    }

    public function test_get_task_dependencies_reports_an_empty_graph(): void
    {
        $created = $this->callTool('create_task', array('title' => 'Lonely task'));

        $result = $this->callTool('get_task_dependencies', array('task_id' => $created['data']['task_id']));

        $this->assertSame($created['data']['task_id'], $result['data']['task_id']);
        $this->assertSame(array(), $result['data']['depends_on']);
        $this->assertSame(array(), $result['data']['blocks']);
        $this->assertTrue($result['data']['can_start']);
    }

    public function test_create_student_writes_a_student_row(): void
    {
        $result = $this->callTool('create_student', array(
            'first_name' => 'Ada',
            'last_name' => 'Lovelace',
            'year_group' => '11',
        ));

        $id = (int) $result['data']['student_id'];
        $this->assertGreaterThan(0, $id);
        $this->assertFalse($result['data']['updated']);

        $stored = $this->store()->find($id);
        $this->assertSame('mcp_ai_student', $stored->type);
        $this->assertSame('Ada', $stored->meta['_student_first_name']);
        $this->assertSame('11', $stored->meta['_student_year_group']);
    }

    public function test_update_student_changes_the_year_group(): void
    {
        $created = $this->callTool('create_student', array(
            'first_name' => 'Grace',
            'last_name' => 'Hopper',
            'year_group' => '10',
        ));

        $this->callTool('update_student', array(
            'student_id' => $created['data']['student_id'],
            'year_group' => '12',
        ));

        $stored = $this->store()->find((int) $created['data']['student_id']);
        $this->assertSame('12', $stored->meta['_student_year_group']);
        $this->assertSame('Grace', $stored->meta['_student_first_name']);
    }

    public function test_bulk_import_previews_imports_and_skips_duplicates(): void
    {
        $rows = array(
            array('title' => 'Imported one'),
            array('title' => 'Imported two'),
        );

        $preview = $this->callTool('bulk_import', array(
            'entity' => 'tasks',
            'rows' => $rows,
            'preview' => true,
        ));

        $this->assertSame(2, $preview['data']['valid']);
        $this->assertSame(0, $preview['data']['created']);
        $this->assertSame(0, $this->callTool('count_posts', array('type' => 'mcp_ai_task'))['data']['total']);

        $import = $this->callTool('bulk_import', array(
            'entity' => 'tasks',
            'rows' => $rows,
        ));

        $this->assertSame(2, $import['data']['created']);
        $this->assertSame(2, $this->callTool('count_posts', array('type' => 'mcp_ai_task'))['data']['total']);

        $again = $this->callTool('bulk_import', array(
            'entity' => 'tasks',
            'rows' => $rows,
        ));

        $this->assertSame(0, $again['data']['created']);
        $this->assertSame(2, $again['data']['duplicates']);
    }
}
