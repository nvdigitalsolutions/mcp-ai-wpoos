<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Pins the 090-M readiness payload on GET /api/v1/tools.
 *
 * The console uses `readiness` to badge a tool and `servable` to state how
 * many tools this runtime can actually honour, so both have to be truthful:
 * every returned tool is servable, and the counts agree with the list.
 */
class ToolsReadinessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    public function test_tools_payload_carries_readiness(): void
    {
        $response = $this->getJson('/api/v1/tools');

        $response->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonStructure(array(
                'data' => array(
                    'tools' => array(array('slug', 'name', 'description', 'required_capability', 'readiness', 'annotations')),
                    'total',
                    'servable',
                    'endpoint_contract_version',
                ),
            ));

        $data = $response->json('data');

        $this->assertSame(count($data['tools']), $data['total']);
        $this->assertSame(1, $data['endpoint_contract_version']);

        $servable = 0;
        foreach ($data['tools'] as $tool) {
            $this->assertContains(
                $tool['readiness'],
                array('ready', 'pure', 'delegated', 'unverified', 'quarantined', 'unavailable')
            );

            if (in_array($tool['readiness'], array('ready', 'pure', 'delegated'), true)) {
                $servable++;
            }
        }

        $this->assertSame($servable, $data['servable'], 'The servable count must match the list.');
        $this->assertSame(count($data['tools']), $servable, 'The registry must only serve servable tools.');
    }

    public function test_quarantined_tools_are_absent(): void
    {
        $slugs = array_column(
            $this->getJson('/api/v1/tools')->json('data.tools'),
            'slug'
        );

        foreach (array('analyze_image', 'analyze_image_prompt', 'batch_embed_content', 'validate_reasoning_chain') as $slug) {
            $this->assertNotContains($slug, $slugs, "Quarantined tool '{$slug}' leaked into the served set.");
        }
    }

    /**
     * Probes for the two reads that need the host `users` table.
     *
     * They cannot run in the adapter suite (no users table there), so their
     * evidence lives here rather than being silently dropped.
     */
    public function test_user_scoped_reads_do_their_own_work(): void
    {
        $registry = app(\Nvoos\Core\Application\Tool\ToolRegistry::class);
        $userId = (int) auth()->id();

        $caps = $registry->get('check_capability')->execute(array('capability' => 'read'));
        $this->assertIsArray($caps, 'check_capability should answer with the canonical envelope: ' . (string) json_encode($caps));
        $this->assertTrue($caps['success'] ?? false);
        $this->assertNotEmpty($caps['data'] ?? array(), 'check_capability returned an empty payload.');

        $user = $registry->get('get_user_info')->execute(array('user_id' => $userId));
        $this->assertIsArray($user, 'get_user_info should answer with the canonical envelope: ' . (string) json_encode($user));
        $this->assertTrue($user['success'] ?? false);
        $this->assertArrayHasKey('id', $user['data'] ?? array());
    }
}
