<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

/**
 * The OKF write routes — Proposal 162 §162.10.
 *
 * Reads are open to any authenticated console user (the drawer is in the chat
 * toolbar); writes are not. A bundle is deployment-global knowledge shared by
 * every estate, so these routes live behind `manage_options` and this test pins
 * both halves of that: an operator can write, a mere reader cannot.
 */
final class OkfWriteTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/okf-write-'.bin2hex(random_bytes(6));

        mkdir($this->root.'/demo', 0775, true);

        config(['oos.okf.path' => $this->root]);
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);

        parent::tearDown();
    }

    public function test_an_operator_can_write_a_concept(): void
    {
        $this->asOperator();

        $response = $this->postJson('/api/v1/pro/okf/bundles/demo/concepts', [
            'concept_id'  => 'tables/orders',
            'frontmatter' => ['type' => 'BigQuery Table', 'title' => 'Orders'],
            'body'        => "# Schema\n\nOne row per order.",
        ]);

        $response->assertCreated();
        $response->assertJsonPath('success', true);

        $this->assertFileExists($this->root.'/demo/tables/orders.md');

        $written = (string) file_get_contents($this->root.'/demo/tables/orders.md');

        $this->assertStringContainsString("type: BigQuery Table", $written);
        $this->assertStringContainsString("title: Orders", $written);
        $this->assertStringContainsString('# Schema', $written);
    }

    public function test_a_reader_without_manage_options_cannot_write(): void
    {
        $admin = User::factory()->create();
        $reader = User::factory()->create();

        Gate::define('manage_options', static fn (User $user): bool => $user->is($admin));

        $this->actingAs($reader)
            ->postJson('/api/v1/pro/okf/bundles/demo/concepts', [
                'concept_id'  => 'tables/orders',
                'frontmatter' => ['type' => 'BigQuery Table'],
            ])
            ->assertForbidden();

        $this->assertFileDoesNotExist($this->root.'/demo/tables/orders.md');
    }

    public function test_a_concept_without_a_type_is_refused_with_the_writer_code(): void
    {
        $this->asOperator();

        $response = $this->postJson('/api/v1/pro/okf/bundles/demo/concepts', [
            'concept_id'  => 'notes/empty',
            'frontmatter' => ['title' => 'No type'],
        ]);

        // The caller can fix this, so it is a 422 carrying the writer's code
        // rather than a generic validation failure.
        $response->assertStatus(422);
        $response->assertJsonPath('code', 'okf_missing_type');
        $this->assertFileDoesNotExist($this->root.'/demo/notes/empty.md');
    }

    public function test_regenerating_the_index_writes_it(): void
    {
        $this->asOperator();

        $this->postJson('/api/v1/pro/okf/bundles/demo/concepts', [
            'concept_id'  => 'orders',
            'frontmatter' => ['type' => 'BigQuery Table', 'title' => 'Orders', 'description' => 'Orders rows.'],
        ])->assertCreated();

        $this->postJson('/api/v1/pro/okf/bundles/demo/index')
            ->assertOk()
            ->assertJsonPath('path', 'index.md');

        $index = (string) file_get_contents($this->root.'/demo/index.md');

        $this->assertStringContainsString('* [Orders](orders.md) - Orders rows.', $index);
    }

    public function test_an_index_path_cannot_escape_the_bundle(): void
    {
        $this->asOperator();

        // `regenerateIndex()` takes a subdirectory from the caller, so the
        // containment check cannot live only on concept ids.
        mkdir($this->root.'/outside', 0775, true);

        $response = $this->postJson('/api/v1/pro/okf/bundles/demo/index', array(
            'path' => '../outside',
        ));

        $response->assertStatus(422);
        $response->assertJsonPath('code', 'okf_path_traversal');
        $this->assertFileDoesNotExist($this->root.'/outside/index.md');
    }

    public function test_an_unknown_bundle_is_a_404(): void
    {
        $this->asOperator();

        $this->postJson('/api/v1/pro/okf/bundles/nope/concepts', array(
            'concept_id'  => 'orders',
            'frontmatter' => array('type' => 'BigQuery Table'),
        ))->assertNotFound();
    }

    /**
     * Act as a user for whom `manage_options` passes.
     *
     * Defined in the test rather than relying on the host's role model, so the
     * test proves the route's gate rather than the fixture's permissions.
     */
    private function asOperator(): User
    {
        $admin = User::factory()->create();

        Gate::define('manage_options', static fn (User $user): bool => $user->is($admin));

        $this->actingAs($admin);

        return $admin;
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach ((array) scandir($path) as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path.'/'.$entry;

            is_dir($child) ? $this->remove($child) : @unlink($child);
        }

        @rmdir($path);
    }
}
