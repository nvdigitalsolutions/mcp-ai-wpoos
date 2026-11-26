<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * `import_assistant` against the app's real engine — Proposal 090-M §19.
 *
 * The unit test pins the argument rules with a mocked host. This pins the restore
 * itself: the four conflict outcomes must actually differ, a dry run must write
 * nothing, and the `manage_options` gate must be the thing that separates an
 * administrator from an editor — because `overwrite` replaces an assistant
 * rather than adding one.
 */
final class ImportAssistantToolTest extends TestCase
{
    use RefreshDatabase;

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('import_assistant'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_a_dry_run_reports_without_writing(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $result = $this->tool()->execute(array(
            'json'    => $this->bundleJson('A prompt.'),
            'dry_run' => true,
        ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(1, $result['data']['imported']);

        $this->assertSame(0, Assistant::query()->count(), 'A dry run wrote to the database.');
    }

    public function test_skip_leaves_an_existing_assistant_alone(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $existing = $this->writer()->create(array(
            'title'         => 'Imported',
            'system_prompt' => 'Original prompt.',
        ));

        $result = $this->tool()->execute(array( 'json' => $this->bundleJson('Replacement prompt.') ));

        $this->assertSame(1, $result['data']['skipped']);
        $this->assertSame(0, $result['data']['imported']);

        $this->assertSame(1, Assistant::query()->count());
        $this->assertSame('Original prompt.', $existing->fresh()->system_prompt);
    }

    public function test_duplicate_adds_a_second_copy(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->writer()->create(array( 'title' => 'Imported', 'system_prompt' => 'Original.' ));

        $result = $this->tool()->execute(array(
            'json' => $this->bundleJson('Replacement.'),
            'mode' => 'duplicate',
        ));

        $this->assertSame(1, $result['data']['imported']);
        $this->assertSame(2, Assistant::query()->count());
    }

    public function test_overwrite_replaces_the_prompt_and_keeps_one_row(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $existing = $this->writer()->create(array(
            'title'         => 'Imported',
            'system_prompt' => 'Original prompt.',
        ));

        $result = $this->tool()->execute(array(
            'json' => $this->bundleJson('Replacement prompt.'),
            'mode' => 'overwrite',
        ));

        $this->assertSame(1, $result['data']['updated']);
        $this->assertSame(1, Assistant::query()->count());

        $this->assertSame('Replacement prompt.', $existing->fresh()->system_prompt);
    }

    public function test_a_payload_that_is_not_a_bundle_is_a_422_naming_the_host_reason(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $result = $this->tool()->execute(array( 'json' => 'not a bundle' ));

        // The adapter returns these as values, with the HTTP status as the code.
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertStringContainsString('not an nvoos-assistant bundle', $result->getMessage());
    }

    public function test_an_editor_cannot_import_because_overwrite_replaces_an_assistant(): void
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $editor = User::factory()->editor()->create();

        Sanctum::actingAs($editor);

        $result = $this->app->make(ToolRegistry::class)->execute(
            'import_assistant',
            array( 'json' => $this->bundleJson('A prompt.') ),
            array(
                'user_id'       => $editor->getKey(),
                'auth_provider' => $this->app->make(AuthProviderInterface::class),
                'agentic_loop'  => true,
            )
        );

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(403, $result->getCode());
        $this->assertSame(0, Assistant::query()->count());
    }

    /**
     * A one-assistant `nvoos-assistant` v1 bundle.
     */
    private function bundleJson(string $prompt): string
    {
        $bundle = array(
            'format'         => 'nvoos-assistant',
            'format_version' => 1,
            'plugin_version' => '1.2.0',
            'exported_at'    => '2026-11-23T00:00:00+00:00',
            'assistants'     => array(
                array(
                    'title'  => 'Imported',
                    'slug'   => 'imported',
                    'status' => 'draft',
                    'content' => $prompt,
                    'meta'   => array( 'provider' => 'openai' ),
                ),
            ),
        );

        return (string) \json_encode($bundle);
    }

    private function writer(): AssistantWriter
    {
        return $this->app->make(AssistantWriter::class);
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('import_assistant');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
