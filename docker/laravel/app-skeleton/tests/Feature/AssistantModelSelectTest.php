<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Assistants\Pages\ManageAssistants;
use App\Models\Assistant;
use App\Models\ModelConfig;
use App\Models\Tenant;
use App\Models\User;
use App\Services\AssistantWriter;
use App\Support\TenantContext;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149 §120 — the assistant form's dependent provider/model selects.
 *
 * The field shapes match WordPress: a plain provider select (a dozen-odd
 * options, so no search per NN/g's 15–20 threshold) and a searchable model
 * select fed by the catalogue for the chosen provider. Two rules matter more
 * than the widgets themselves:
 *
 *  - **Hydration is preserved.** A stored value the catalogue no longer serves
 *    (a deprecated model) or has never heard of (a deployment or fine-tune)
 *    must appear as `<value> (current value)` and survive an unrelated edit.
 *  - **The select is an advisor, not an allowlist.** §90.2 lets an unknown id
 *    through at the chat boundary, so the form must not be stricter than the
 *    writer; `getOptionLabelUsing()` returns a label for any non-empty value.
 */
class AssistantModelSelectTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));

        $this->seedCatalogue();
    }

    /**
     * §120.5 — provider change and provider scoping.
     */
    public function test_changing_the_provider_rebuilds_the_model_options_and_clears_the_selection(): void
    {
        $alice = $this->estateAdmin();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $assistant = $this->assistantIn(array(
            'title' => 'OpenAI bot',
            'provider' => 'openai',
            'model' => 'gpt-5.5',
        ));

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        $page = Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->mountTableAction(EditAction::class, $assistant)
            ->assertTableActionDataSet(array('provider' => 'openai', 'model' => 'gpt-5.5'))
            ->assertSchemaComponentExists(
                'model',
                checkComponentUsing: static function ($component): bool {
                    $options = $component->getOptions();

                    return array_key_exists('gpt-5.5', $options)
                        && ! array_key_exists('gemini-3.8-flash', $options);
                }
            );

        $page->setTableActionData(array('provider' => 'gemini'))
            ->assertTableActionDataSet(array('provider' => 'gemini', 'model' => null))
            ->assertSchemaComponentExists(
                'model',
                checkComponentUsing: static function ($component): bool {
                    $options = $component->getOptions();

                    return array_key_exists('gemini-3.8-flash', $options)
                        && ! array_key_exists('gpt-5.5', $options);
                }
            );
    }

    /**
     * §120.5 — a legacy value on edit, and the REST wire shape after the save.
     */
    public function test_a_deprecated_model_is_offered_as_the_current_value_and_survives_an_unrelated_edit(): void
    {
        $alice = $this->estateAdmin();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $assistant = $this->assistantIn(array(
            'title' => 'Legacy Gemini bot',
            'provider' => 'gemini',
            'model' => 'gemini-2.5-flash',
        ));

        $before = $assistant->toRecord();

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        $page = Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->mountTableAction(EditAction::class, $assistant)
            ->assertTableActionDataSet(array('provider' => 'gemini', 'model' => 'gemini-2.5-flash'))
            ->assertSchemaComponentExists(
                'model',
                checkComponentUsing: static function ($component): bool {
                    $options = $component->getOptions();

                    return 'gemini-2.5-flash (current value)' === ($options['gemini-2.5-flash'] ?? null)
                        && array_key_exists('gemini-3.8-flash', $options);
                }
            );

        $page->setTableActionData(array('title' => 'Renamed Gemini bot'))
            ->callMountedTableAction();

        $fresh = $assistant->fresh();
        $after = $fresh->toRecord();

        $this->assertSame('Renamed Gemini bot', $fresh->title);
        $this->assertSame('gemini', $fresh->provider);
        $this->assertSame('gemini-2.5-flash', $fresh->model);
        $this->assertSame($before['provider'], $after['provider']);
        $this->assertSame($before['model'], $after['model']);
    }

    /**
     * §120.5 — a stored provider the catalogue has no rows for.
     */
    public function test_a_provider_with_no_catalogue_rows_is_preserved_and_the_form_saves(): void
    {
        $alice = $this->estateAdmin();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $assistant = $this->assistantIn(array(
            'title' => 'Private provider bot',
            'provider' => 'private-cloud',
            'model' => 'private-model-1',
        ));

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        $page = Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->mountTableAction(EditAction::class, $assistant)
            ->assertTableActionDataSet(array(
                'provider' => 'private-cloud',
                'model' => 'private-model-1',
            ))
            ->assertSchemaComponentExists(
                'provider',
                checkComponentUsing: static function ($component): bool {
                    $options = $component->getOptions();

                    return 'private-cloud (current value)' === ($options['private-cloud'] ?? null);
                }
            )
            ->assertSchemaComponentExists(
                'model',
                checkComponentUsing: static function ($component): bool {
                    $options = $component->getOptions();

                    return array_key_exists('private-model-1', $options);
                }
            );

        $page->setTableActionData(array('title' => 'Renamed private bot'))
            ->callMountedTableAction();

        $fresh = $assistant->fresh();

        $this->assertSame('Renamed private bot', $fresh->title);
        $this->assertSame('private-cloud', $fresh->provider);
        $this->assertSame('private-model-1', $fresh->model);
    }

    /**
     * §120.5 — both a catalogue model and a custom id save through the form.
     */
    public function test_a_catalogue_model_and_a_custom_model_can_both_be_created(): void
    {
        $alice = $this->estateAdmin();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        $page = Livewire::test(ManageAssistants::class, array('tenant' => $tenant));

        $page->callAction('create', data: array(
            'title' => 'Catalogue bot',
            'status' => 'draft',
            'provider' => 'gemini',
            'model' => 'gemini-3.8-flash',
        ));

        $page->callAction('create', data: array(
            'title' => 'Fine-tune bot',
            'status' => 'draft',
            'provider' => 'openai',
            'model' => 'ft:gpt-5.5:acme:private',
        ));

        $this->assertDatabaseHas('assistants', array(
            'title' => 'Catalogue bot',
            'provider' => 'gemini',
            'model' => 'gemini-3.8-flash',
        ));
        $this->assertDatabaseHas('assistants', array(
            'title' => 'Fine-tune bot',
            'provider' => 'openai',
            'model' => 'ft:gpt-5.5:acme:private',
        ));
    }

    /**
     * The child is unusable until a provider is chosen, and says why.
     */
    public function test_the_model_select_is_disabled_until_a_provider_is_chosen(): void
    {
        $alice = $this->estateAdmin();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $assistant = $this->assistantIn(array('title' => 'Estate default bot'));

        $this->actingAs($alice);
        Filament::setTenant($tenant);
        TenantContext::set(Tenant::DEFAULT_ID);

        $page = Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->mountTableAction(EditAction::class, $assistant)
            ->assertSchemaComponentExists(
                'model',
                checkComponentUsing: static function ($component): bool {
                    return $component->isDisabled()
                        && 'Pick a provider first' === $component->getPlaceholder();
                }
            );

        $page->setTableActionData(array('provider' => 'gemini'))
            ->assertSchemaComponentExists(
                'model',
                checkComponentUsing: static function ($component): bool {
                    return (! $component->isDisabled())
                        && 'Select a model' === $component->getPlaceholder();
                }
            );
    }

    /**
     * Only the rows the catalogue serves are offered; the rest live in the test
     * fixtures so a status change is explicit rather than incidental.
     */
    private function seedCatalogue(): void
    {
        foreach (array(
            array(
                'provider' => 'gemini',
                'model_id' => 'gemini-3.8-flash',
                'name' => 'Gemini 3.8 Flash',
                'status' => 'active',
            ),
            array(
                'provider' => 'gemini',
                'model_id' => 'gemini-2.5-flash',
                'name' => 'Gemini 2.5 Flash',
                'status' => 'deprecated',
            ),
            array(
                'provider' => 'openai',
                'model_id' => 'gpt-5.5',
                'name' => 'GPT-5.5',
                'status' => 'active',
            ),
        ) as $row) {
            ModelConfig::query()->create($row);
        }
    }

    private function estateAdmin(string $tenantId = Tenant::DEFAULT_ID): User
    {
        $user = User::factory()->admin()->withoutEstate()->create();

        Tenant::query()->findOrFail($tenantId)
            ->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    /**
     * @param array<string, mixed> $data
     */
    private function assistantIn(array $data): Assistant
    {
        TenantContext::set(Tenant::DEFAULT_ID);

        $assistant = app(AssistantWriter::class)->create($data);

        TenantContext::forget();

        return $assistant;
    }
}
