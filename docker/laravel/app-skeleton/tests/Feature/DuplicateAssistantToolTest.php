<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use RuntimeException;
use Tests\TestCase;

/**
 * `duplicate_assistant` against the app's real writer — Proposal 090-M §17.
 *
 * The unit test pins the argument rules with a mocked host. This pins the copy
 * itself: a draft in the current estate, never the default, never the source's
 * slug, and a source in another estate reported as **not found** rather than as
 * forbidden.
 */
final class DuplicateAssistantToolTest extends TestCase
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
            $this->app->make(ToolRegistry::class)->has('duplicate_assistant'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_copies_an_assistant_into_a_draft(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $sourceId = $this->createSource();

        $result = $this->tool()->execute(array( 'assistant_id' => $sourceId ));

        $this->assertTrue($result['success']);
        $this->assertSame('draft', $result['data']['assistant']['status']);
        $this->assertSame($sourceId, $result['data']['source_id']);

        $source = Assistant::query()->findOrFail($sourceId);
        $copy   = Assistant::query()->findOrFail($result['data']['assistant']['id']);

        $this->assertNotSame((int) $source->id, (int) $copy->id);
        $this->assertSame(Tenant::DEFAULT_ID, $copy->tenant_id);
        $this->assertSame('Ops Helper (Copy)', $copy->title);
        $this->assertSame('ops-helper-copy', $copy->slug);
        $this->assertSame('draft', $copy->status);
        $this->assertFalse((bool) $copy->is_default);
        $this->assertSame($source->system_prompt, $copy->system_prompt);
        $this->assertSame($source->tools, $copy->tools);

        // The source is untouched by the copy.
        $this->assertSame('Ops Helper', $source->title);
        $this->assertSame('ops-helper', $source->slug);
    }

    public function test_a_custom_title_is_used(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $sourceId = $this->createSource();

        $result = $this->tool()->execute(array(
            'assistant_id' => $sourceId,
            'title'        => 'Service Desk Bot',
        ));

        $copy = Assistant::query()->findOrFail($result['data']['assistant']['id']);

        $this->assertSame('Service Desk Bot', $copy->title);
        $this->assertSame('service-desk-bot', $copy->slug);
    }

    public function test_duplicating_the_estate_default_does_not_move_the_flag(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $sourceId = $this->createSource();

        DB::table('assistants')->where('id', $sourceId)->update(array( 'is_default' => true ));

        $result = $this->tool()->execute(array( 'assistant_id' => $sourceId ));

        $copy = Assistant::query()->findOrFail($result['data']['assistant']['id']);

        $this->assertFalse((bool) $copy->is_default, 'A copy is never the estate default.');
        $this->assertTrue(
            (bool) Assistant::query()->findOrFail($sourceId)->is_default,
            'The source stays the default.'
        );
    }

    public function test_a_source_in_another_estate_is_not_found(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $foreignId = TenantContext::runAs(
            'other-estate',
            fn (): int => (int) $this->app->make(AssistantWriter::class)
                ->create(array( 'title' => 'Foreign Assistant' ))
                ->getKey()
        );

        $result = $this->tool()->execute(array( 'assistant_id' => $foreignId ));

        // The adapter's ErrorFactory returns the error as a value; code is the
        // HTTP status. 404 rather than 403: a 403 would confirm the row exists.
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(404, $result->getCode());

        // And nothing was created in the current estate.
        $this->assertSame(0, Assistant::query()->count());
    }

    /**
     * A source assistant shaped like the create tool would make one.
     */
    private function createSource(): int
    {
        return (int) $this->app->make(AssistantWriter::class)->create(array(
            'title'         => 'Ops Helper',
            'description'   => 'Helps with operations.',
            'system_prompt' => 'You help with operations.',
            'tools'         => array( 'web_search' ),
        ))->getKey();
    }

    /**
     * The registered tool, or a failure that names the wiring gap.
     */
    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('duplicate_assistant');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
