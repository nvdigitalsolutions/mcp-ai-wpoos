<?php

namespace Tests\Feature;

use App\Filament\Operator\Resources\Models\Pages\ManageModels;
use App\Models\ModelConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Models\ModelConfigCatalogue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 149 §68 — the text-model catalogue behind `/ops/models`.
 *
 * The tests pin the three boundaries that make the table safe to edit:
 *
 *  - **text only** — the media catalogue is a different shape and must not leak
 *    into this store or these selects;
 *  - **operator only** — an estate administrator cannot reach `/ops`, and the
 *    settings selects only ever ADD catalogue models to their curated list;
 *  - **sync is additive** — an operator's edit is never overwritten by a later
 *    catalogue sync.
 */
class ModelConfigManagerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
    }

    public function test_the_text_catalogue_is_complete_and_contains_no_media_models(): void
    {
        $entries = ModelConfigCatalogue::entries();
        $providers = array_values(array_unique(array_column($entries, 'provider')));

        $this->assertGreaterThanOrEqual(200, count($entries));
        $this->assertContains('openai', $providers);
        $this->assertContains('anthropic', $providers);
        $this->assertContains('gemini', $providers);

        // Media providers live in media-model-catalog.json.
        $this->assertNotContains('higgsfield', $providers);

        $first = $entries[0];

        $this->assertArrayHasKey('input_cost_per_million', $first);
        $this->assertArrayHasKey('output_cost_per_million', $first);
        $this->assertArrayHasKey('fallback_model_id', $first);
        $this->assertArrayNotHasKey('kind', $first, 'The text catalogue must not carry the media schema.');

        // A known media id must not be reachable through the text catalogue.
        $ids = array_column($entries, 'model_id');
        $this->assertNotContains('soul-2', $ids);
        $this->assertNotContains('veo-3.1-generate-preview', $ids);

        // Identity is the (provider, model_id) pair: the canonical catalogue
        // publishes `gpt-4o` under Azure and OpenAI with different lifecycle, so
        // there are fewer distinct ids than entries.
        $this->assertLessThan(count($entries), count(array_unique($ids)));
    }

    public function test_sync_adds_missing_models_and_never_overwrites_an_edit(): void
    {
        $first = ModelConfigCatalogue::entries()[0];
        $distinct = $this->distinctCatalogueSize();

        ModelConfig::query()->create([
            'model_id' => $first['model_id'],
            'name' => 'Edited locally',
            'provider' => $first['provider'],
            'status' => 'active',
        ]);

        $result = ModelConfigCatalogue::sync();

        $this->assertSame($distinct - 1, $result['added']);
        $this->assertSame(1, $result['existing']);
        $this->assertSame(
            'Edited locally',
            ModelConfig::query()->where('model_id', $first['model_id'])->value('name')
        );

        $second = ModelConfigCatalogue::sync();

        $this->assertSame(0, $second['added']);
        $this->assertSame($distinct, $second['existing']);
        $this->assertSame($distinct, ModelConfig::query()->count());
    }

    public function test_one_model_id_may_be_published_by_two_providers(): void
    {
        ModelConfigCatalogue::sync();

        $rows = ModelConfig::query()->where('model_id', 'gpt-4o')->get();

        $this->assertCount(2, $rows, 'Both the Azure deployment and the OpenAI model must survive a sync.');
        $this->assertEqualsCanonicalizing(['azure', 'openai'], $rows->pluck('provider')->all());

        // A provider-scoped select never crosses providers. The OpenAI entry is
        // deprecated upstream, so it only appears when deprecated rows are allowed.
        $this->assertArrayHasKey('gpt-4o', ModelConfigCatalogue::optionsForProvider('azure'));
        $this->assertArrayNotHasKey('gpt-4o', ModelConfigCatalogue::optionsForProvider('openai'));
        $this->assertArrayHasKey('gpt-4o', ModelConfigCatalogue::optionsForProvider('openai', includeDeprecated: true));

        // One option per value is unavoidable, so the label names the row a new
        // selection would get: the servable one, disambiguated by provider.
        $options = ModelConfigCatalogue::options(includeDeprecated: true);

        $this->assertStringContainsString('Azure OpenAI', $options['gpt-4o']);

        // A bare fallback id resolves to the naming row's own provider.
        $this->assertSame('azure', ModelConfigCatalogue::resolveFallback('azure', 'gpt-4o')?->provider);
        $this->assertSame('openai', ModelConfigCatalogue::resolveFallback('openai', 'gpt-4o')?->provider);
        $this->assertNull(ModelConfigCatalogue::resolveFallback('openai', null));
        $this->assertNull(ModelConfigCatalogue::resolveFallback('openai', ''));
    }

    public function test_options_are_servable_models_only(): void
    {
        foreach (array(
            array('alpha-model', 'Alpha', 'active'),
            array('beta-model', 'Beta', 'preview'),
            array('gamma-model', 'Gamma', 'deprecated'),
            array('delta-model', 'Delta', 'retired'),
        ) as [$id, $name, $status]) {
            ModelConfig::query()->create(array(
                'model_id' => $id,
                'name' => $name,
                'provider' => 'openai',
                'status' => $status,
            ));
        }

        $options = ModelConfigCatalogue::options();

        $this->assertSame(array('alpha-model', 'beta-model'), array_keys($options));
        $this->assertSame(array('Alpha', 'Beta'), array_values($options));
    }

    public function test_an_operator_reaches_the_model_manager_and_an_estate_admin_does_not(): void
    {
        $this->actingAs($this->operatorMember())
            ->get('/ops/models')
            ->assertOk();

        $this->actingAs($this->estateAdmin())
            ->get('/ops/models')
            ->assertForbidden();
    }

    public function test_the_operator_page_renders_a_catalogue_model(): void
    {
        $model = ModelConfig::query()->create(array(
            'model_id' => 'test-model',
            'name' => 'Test Model',
            'provider' => 'openai',
            'status' => 'active',
            'context_window' => 128000,
        ));

        $this->actingAs($this->operatorMember());

        Livewire::test(ManageModels::class)
            ->assertCanSeeTableRecords(array($model));
    }

    public function test_creating_a_model_records_an_audit_row(): void
    {
        $this->actingAs($this->operatorMember());

        ModelConfig::query()->create(array(
            'model_id' => 'audited-model',
            'name' => 'Audited Model',
            'provider' => 'openai',
            'status' => 'active',
        ));

        $this->assertDatabaseHas('nvoos_admin_audit', array(
            'resource' => 'models',
            'record_id' => 'audited-model',
            'action' => 'created',
        ));
    }

    public function test_the_sync_command_adds_the_catalogue(): void
    {
        $this->artisan('nvoos:sync-models')->assertSuccessful();

        $this->assertSame(
            $this->distinctCatalogueSize(),
            ModelConfig::query()->count()
        );
    }

    public function test_text_model_selects_are_provider_scoped_and_media_selects_are_not_marked(): void
    {
        $path = resource_path('settings/catalogue.json');
        $document = json_decode((string) file_get_contents($path), true);
        $fields = array_column($document['fields'], null, 'key');

        $this->assertSame('model_catalog:gemini', $fields['default_gemini_model']['options_source'] ?? null);
        $this->assertSame('model_catalog:anthropic', $fields['anthropic_model']['options_source'] ?? null);
        $this->assertSame('model_catalog:nvidia', $fields['nvidia_model']['options_source'] ?? null);

        foreach (array(
            'openai_image_model',
            'openai_embedding_model',
            'openai_transcribe_model',
            'openai_speech_model',
            'gemini_image_model',
            'gemini_video_model',
            'cloudflare_image_model',
            'cloudflare_audio_model',
            'ollama_model',
            'lm_studio_model',
            'embedded_model',
            'embedded_server_model',
            'deep_research_model',
        ) as $mediaKey) {
            $this->assertArrayNotHasKey(
                'options_source',
                $fields[$mediaKey],
                $mediaKey.' is a media/local select and must not read the text catalogue.'
            );
        }
    }

    public function test_a_catalogue_model_appears_in_its_own_provider_select(): void
    {
        ModelConfig::query()->create(array(
            'model_id' => 'gemini-test-new',
            'name' => 'Gemini Test New',
            'provider' => 'gemini',
            'status' => 'active',
        ));

        ModelConfig::query()->create(array(
            'model_id' => 'openai-test-other',
            'name' => 'OpenAI Only ZZZ',
            'provider' => 'openai',
            'status' => 'active',
        ));

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->actingAs($this->estateAdmin())
            ->get('/admin/default/settings')
            ->assertOk()
            ->assertSee('Gemini Test New')
            ->assertDontSee('OpenAI Only ZZZ');
    }

    private function estateAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::DEFAULT_ID)
            ->users()->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }

    /**
     * Distinct (provider, model_id) pairs in the bundled document.
     */
    private function distinctCatalogueSize(): int
    {
        $pairs = array_map(
            static fn (array $entry): string => ((string) $entry['provider']).'|'.((string) $entry['model_id']),
            ModelConfigCatalogue::entries()
        );

        return count(array_unique($pairs));
    }

    private function operatorMember(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()->findOrFail(Tenant::OPERATOR_ID)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }
}
