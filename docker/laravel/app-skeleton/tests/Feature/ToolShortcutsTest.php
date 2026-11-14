<?php

namespace Tests\Feature;

use App\Models\Assistant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * Assistant tool shortcuts (090-i).
 *
 * The shaping rules themselves are pinned in
 * `lib/core/tests/Unit/Domain/Tool/ToolShortcutNormaliserTest.php`; what is
 * asserted here is the source — which meta the runtime reads — and the
 * response envelope the console's `ToolShortcutsClient` parses.
 */
final class ToolShortcutsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Sanctum::actingAs(User::factory()->admin()->create());
    }

    /**
     * Create an assistant through the API, optionally seeding meta.
     *
     * @param array<string, mixed> $meta
     */
    private function assistant(array $meta = array(), bool $isDefault = false): int
    {
        $id = (int) $this->postJson('/api/v1/assistants', array(
            'title' => 'Ops',
            'is_default' => $isDefault,
        ))->json('id');

        if (array() !== $meta) {
            $assistant = Assistant::find($id);
            $assistant->meta = array_merge($assistant->meta ?? array(), $meta);
            $assistant->save();
        }

        return $id;
    }

    public function test_an_assistant_without_shortcuts_returns_an_empty_drawer(): void
    {
        $id = $this->assistant();

        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}")
            ->assertOk()
            ->assertJsonPath('assistant_id', $id)
            ->assertJsonPath('shortcuts', array())
            ->assertJsonPath('categories', array());
    }

    public function test_flat_shortcuts_are_normalised(): void
    {
        $id = $this->assistant(array(
            'tool_shortcuts' => array(
                array('tool' => 'get_weather'),
            ),
        ));

        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}")
            ->assertOk()
            ->assertJsonPath('shortcuts.0.id', 'get_weather')
            ->assertJsonPath('shortcuts.0.label', 'get_weather')
            ->assertJsonPath('shortcuts.0.category', 'General')
            ->assertJsonPath('categories.0', 'General');
    }

    public function test_prebuilt_shortcuts_inherit_their_tool_from_the_map_key(): void
    {
        $id = $this->assistant(array(
            'tool_prebuilt_shortcuts' => array(
                'get_weather' => array(
                    'mode' => 'custom',
                    'shortcuts' => array(
                        array('label' => 'Forecast', 'payload' => 'weather'),
                    ),
                ),
            ),
        ));

        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}")
            ->assertOk()
            ->assertJsonPath('shortcuts.0.id', 'get_weather')
            ->assertJsonPath('shortcuts.0.tool', 'get_weather')
            ->assertJsonPath('shortcuts.0.label', 'Forecast');
    }

    public function test_disable_tool_shortcuts_hides_everything(): void
    {
        $id = $this->assistant(array(
            'disable_tool_shortcuts' => true,
            'tool_shortcuts' => array(
                array('tool' => 'get_weather'),
            ),
        ));

        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}")
            ->assertOk()
            ->assertJsonPath('shortcuts', array())
            ->assertJsonPath('assistant_id', $id);
    }

    public function test_search_filters_by_tool(): void
    {
        $id = $this->assistant(array(
            'tool_shortcuts' => array(
                array('label' => 'Forecast', 'payload' => 'f', 'tool' => 'get_weather'),
                array('label' => 'Timezone', 'payload' => 't', 'tool' => 'get_timezone'),
            ),
        ));

        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}&search=weath")
            ->assertOk()
            ->assertJsonCount(1, 'shortcuts')
            ->assertJsonPath('shortcuts.0.tool', 'get_weather');
    }

    public function test_it_falls_back_to_the_default_assistant(): void
    {
        $id = $this->assistant(array(
            'tool_shortcuts' => array(array('tool' => 'get_weather')),
        ), true);

        $this->getJson('/api/v1/pro/tool-shortcuts')
            ->assertOk()
            ->assertJsonPath('assistant_id', $id)
            ->assertJsonCount(1, 'shortcuts');
    }

    public function test_it_reports_zero_when_no_assistant_resolves(): void
    {
        $this->getJson('/api/v1/pro/tool-shortcuts')
            ->assertOk()
            ->assertJsonPath('assistant_id', 0)
            ->assertJsonPath('shortcuts', array());
    }

    public function test_an_editor_may_read_and_a_subscriber_may_not(): void
    {
        $id = $this->assistant();

        Sanctum::actingAs(User::factory()->editor()->create());
        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}")->assertOk();

        Sanctum::actingAs(User::factory()->create());
        $this->getJson("/api/v1/pro/tool-shortcuts?assistant_id={$id}")->assertForbidden();
    }

    public function test_the_endpoint_is_reported_available(): void
    {
        $this->getJson('/api/v1/features')
            ->assertOk()
            ->assertJsonPath('data.endpoints.shortcuts', true)
            ->assertJsonPath('data.endpoint_reasons.shortcuts', 'route')
            ->assertJsonPath('data.endpoints.slashCommands', false)
            ->assertJsonPath('data.endpoint_reasons.slashCommands', 'toolkit-shaped');
    }
}
