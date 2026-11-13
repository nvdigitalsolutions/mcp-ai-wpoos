<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\PaperStoreInterface;
use Tests\TestCase;

/**
 * Paper Store API on the flat-file store (090-f Phase 3).
 *
 * The routes previously pointed at `nvoos_paper_records`, a table no migration
 * creates, so every call failed. These tests pin the API shape *and* the fact
 * that a record lands on disk in the layout WordPress reads.
 */
class PaperStoreTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/paper-api-'.bin2hex(random_bytes(6));
        mkdir($this->root, 0775, true);

        config(['oos.paper_store_root' => $this->root]);
        $this->app->forgetInstance(PaperStoreInterface::class);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        $this->rmtree($this->root);

        parent::tearDown();
    }

    public function test_creating_a_record_writes_a_file(): void
    {
        $response = $this->postJson('/api/v1/paper-store/knowledge', [
            'title' => 'Dior Sauvage',
            'description' => 'Fragrance note',
            'tags' => ['fragrance'],
            'status' => 'published',
        ]);

        $response->assertCreated()
            ->assertJsonPath('id', 'dior-sauvage')
            ->assertJsonPath('collection', 'knowledge')
            ->assertJsonPath('status', 'published');

        $path = $this->root.'/knowledge/dior-sauvage.json';
        $this->assertFileExists($path);

        $stored = json_decode((string) file_get_contents($path), true);
        $this->assertSame(1, $stored['schemaVersion']);
        $this->assertSame('Dior Sauvage', $stored['title']);
        $this->assertNotEmpty($stored['created_at']);
    }

    public function test_a_duplicate_title_gets_a_suffix(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Same'])->assertCreated();
        $second = $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Same']);

        $second->assertCreated()->assertJsonPath('id', 'same-2');
    }

    public function test_listing_returns_records_and_a_total(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Alpha', 'tags' => ['x']]);
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Beta', 'tags' => ['y']]);

        $this->getJson('/api/v1/paper-store/knowledge')
            ->assertOk()
            ->assertJsonPath('total', 2)
            ->assertJsonCount(2, 'records');

        $this->getJson('/api/v1/paper-store/knowledge?tag=x')
            ->assertOk()
            ->assertJsonPath('total', 1)
            ->assertJsonPath('records.0.title', 'Alpha');
    }

    public function test_collections_lists_populated_directories(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'A']);
        $this->postJson('/api/v1/paper-store/prompts', ['title' => 'B']);

        $this->getJson('/api/v1/paper-store')
            ->assertOk()
            ->assertJsonPath('collections', ['knowledge', 'prompts']);
    }

    public function test_show_returns_a_record_or_a_typed_404(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Alpha']);

        $this->getJson('/api/v1/paper-store/knowledge/alpha')
            ->assertOk()
            ->assertJsonPath('title', 'Alpha');

        $this->getJson('/api/v1/paper-store/knowledge/missing')
            ->assertNotFound()
            ->assertJsonPath('error.code', 'not_found');
    }

    public function test_update_merges_and_keeps_created_at(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Alpha', 'description' => 'first']);

        $before = json_decode((string) file_get_contents($this->root.'/knowledge/alpha.json'), true);

        $this->putJson('/api/v1/paper-store/knowledge/alpha', ['tags' => ['updated']])
            ->assertOk()
            ->assertJsonPath('title', 'Alpha')
            ->assertJsonPath('tags', ['updated']);

        $after = json_decode((string) file_get_contents($this->root.'/knowledge/alpha.json'), true);

        $this->assertSame('first', $after['description']);
        $this->assertSame($before['created_at'], $after['created_at']);
    }

    public function test_destroy_reports_the_second_delete_as_missing(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Alpha']);

        $this->deleteJson('/api/v1/paper-store/knowledge/alpha')->assertOk()->assertJsonPath('ok', true);
        $this->deleteJson('/api/v1/paper-store/knowledge/alpha')->assertNotFound();
        $this->assertFileDoesNotExist($this->root.'/knowledge/alpha.json');
    }

    public function test_search_spans_collections(): void
    {
        $this->postJson('/api/v1/paper-store/knowledge', ['title' => 'Dior Sauvage']);
        $this->postJson('/api/v1/paper-store/prompts', ['title' => 'Sauvage copy']);

        $this->getJson('/api/v1/paper-store/search?q=sauvage')
            ->assertOk()
            ->assertJsonCount(2, 'records');

        $this->getJson('/api/v1/paper-store/search?q=sauvage&collection=prompts')
            ->assertOk()
            ->assertJsonCount(1, 'records');
    }

    public function test_an_empty_search_returns_nothing(): void
    {
        $this->getJson('/api/v1/paper-store/search?q=')
            ->assertOk()
            ->assertJsonCount(0, 'records');
    }

    public function test_an_unsafe_collection_segment_is_a_404(): void
    {
        $this->getJson('/api/v1/paper-store/bad.name')->assertNotFound();
    }

    /**
     * Recursively delete a directory.
     */
    private function rmtree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }

            $child = $path.'/'.$entry;
            is_dir($child) ? $this->rmtree($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
