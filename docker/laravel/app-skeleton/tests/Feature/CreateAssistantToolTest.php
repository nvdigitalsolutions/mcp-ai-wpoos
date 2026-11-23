<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Tests\TestCase;

/**
 * `create_assistant` against the app's real writer — Proposal 090-M §16.
 *
 * The unit test pins the argument rules with a mocked host. This pins the wiring
 * the unit test cannot: that the manifest row is servable here, that the app
 * binding reaches `AssistantWriter`, and that the write lands as a **draft in the
 * current estate** with a slug derived per estate — the three rules the REST
 * controller relies on too.
 */
final class CreateAssistantToolTest extends TestCase
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
            $this->app->make(ToolRegistry::class)->has('create_assistant'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_creates_a_draft_in_the_current_estate(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $result = $this->tool()->execute(array(
            'title'         => 'Ops Helper',
            'system_prompt' => 'You help with operations.',
            'tools'         => array( 'web_search' ),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('draft', $result['data']['assistant']['status']);
        $this->assertSame('ops-helper', $result['data']['assistant']['slug']);

        $assistant = Assistant::query()->findOrFail($result['data']['assistant']['id']);

        $this->assertSame('Ops Helper', $assistant->title);
        $this->assertSame(Tenant::DEFAULT_ID, $assistant->tenant_id);
        $this->assertSame('draft', $assistant->status, 'A tool call never publishes.');
        $this->assertFalse((bool) $assistant->is_default, 'Defaulting an assistant is an operator decision.');
        $this->assertSame(array( 'web_search' ), $assistant->tools);
    }

    public function test_a_second_assistant_with_the_same_title_gets_a_suffixed_slug(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $first  = $this->tool()->execute(array( 'title' => 'Ops Helper' ));
        $second = $this->tool()->execute(array( 'title' => 'Ops Helper' ));

        $this->assertSame('ops-helper', $first['data']['assistant']['slug']);
        $this->assertSame('ops-helper-2', $second['data']['assistant']['slug']);
    }

    /**
     * The registered tool, or a failure that names the wiring gap.
     */
    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('create_assistant');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
