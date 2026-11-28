<?php

namespace Tests\Feature;

use App\Filament\Admin\Resources\Assistants\Pages\ManageAssistants;
use App\Models\Assistant;
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
 * Proposal 149 §12.3 — the fifteen `advanced` keys on the assistant form.
 *
 * The panel now owns the vocabulary the console and REST surface already
 * carried: simple values (fallback chain, IDs, enum, booleans) plus four
 * structured editors (`tool_shortcuts`, `tool_prebuilt_shortcuts`,
 * `tool_role_rules`, `preferred_datasets`). The stored shapes are unchanged:
 * `tool_prebuilt_shortcuts` is a tool-slug map on the wire and a Repeater row
 * list in the form, converted by the create/edit actions.
 *
 * Two failure modes are pinned here:
 *
 *  - a structured editor that drops a value the server would keep (unknown meta
 *    keys, shortcut `hidden` flags, group IDs), and
 *  - a form that treats the vocabulary as an allowlist and refuses a value the
 *    writer accepts. The structured fields validate their own shape, not the
 *    catalogue.
 */
class AssistantAdvancedFormTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('admin'));
    }

    public function test_the_edit_form_hydrates_every_advanced_key(): void
    {
        [$alice, $tenant] = $this->actor();
        $assistant = $this->assistantIn(array(
            'title' => 'Advanced bot',
            'advanced' => $this->advancedFixture(),
        ));

        $this->mountEdit($alice, $tenant, $assistant)
            ->assertTableActionDataSet(array(
                'advanced.fallback_chain' => 'openai, anthropic',
                'advanced.adaptive_tool_cap' => 'on',
                'advanced.vector_store_id' => 'vs_123',
                'advanced.corpus_name' => 'corpus-a',
                'advanced.prompt_caching' => true,
                'advanced.disable_tool_shortcuts' => true,
                'advanced.external_action_id' => 'action-9',
                'advanced.external_action_type' => 'workflow',
                'advanced.required_capability' => 'manage_options',
            ))
            ->assertSchemaComponentExists(
                'advanced.memory_files',
                checkComponentUsing: static fn ($component): bool => array_map(
                    'intval',
                    $component->getState() ?? array()
                ) === array(12, 34)
            )
            ->assertSchemaComponentExists(
                'advanced.tool_shortcuts',
                checkComponentUsing: static function ($component): bool {
                    $rows = array_values($component->getState() ?? array());

                    return 1 === count($rows)
                        && 'Summarise' === ($rows[0]['label'] ?? null)
                        && 'summarise_text' === ($rows[0]['tool'] ?? null)
                        && true === ($rows[0]['hidden'] ?? null);
                }
            )
            ->assertSchemaComponentExists(
                'advanced.tool_prebuilt_shortcuts',
                checkComponentUsing: static function ($component): bool {
                    $byTool = array();

                    foreach (array_values($component->getState() ?? array()) as $row) {
                        $byTool[(string) ($row['tool'] ?? '')] = $row;
                    }

                    if (! isset($byTool['alpha_tool'], $byTool['beta_tool'])) {
                        return false;
                    }

                    $alpha = array_values($byTool['alpha_tool']['shortcuts'] ?? array());
                    $beta = array_values($byTool['beta_tool']['shortcuts'] ?? array());

                    return 1 === count($alpha)
                        && 'Alpha one' === ($alpha[0]['label'] ?? null)
                        && false === ($alpha[0]['hidden'] ?? null)
                        && 1 === count($beta)
                        && true === ($beta[0]['hidden'] ?? null);
                }
            )
            ->assertSchemaComponentExists(
                'advanced.tool_role_rules',
                checkComponentUsing: static function ($component): bool {
                    $rows = array_values($component->getState() ?? array());

                    return 1 === count($rows)
                        && 'alpha_tool' === ($rows[0]['tool'] ?? null)
                        && array('editor') === array_values($rows[0]['roles'] ?? array())
                        && array(7) === array_map('intval', $rows[0]['groups'] ?? array())
                        && array('allow_authenticated') === array_values($rows[0]['flags'] ?? array());
                }
            )
            ->assertSchemaComponentExists(
                'advanced.preferred_datasets',
                checkComponentUsing: static function ($component): bool {
                    $rows = array_values($component->getState() ?? array());

                    return 1 === count($rows)
                        && 'owner/dataset' === ($rows[0]['dataset'] ?? null)
                        && 'nlp' === ($rows[0]['category'] ?? null)
                        && 'high' === ($rows[0]['priority'] ?? null);
                }
            );
    }

    public function test_saving_the_form_round_trips_every_advanced_key(): void
    {
        [$alice, $tenant] = $this->actor();
        $assistant = $this->assistantIn(array(
            'title' => 'Advanced bot',
            'advanced' => $this->advancedFixture(),
        ));

        $this->mountEdit($alice, $tenant, $assistant)
            ->setTableActionData(array('title' => 'Renamed advanced bot'))
            ->callMountedTableAction();

        $fresh = $assistant->fresh();
        $meta = $fresh->meta;

        $this->assertSame('Renamed advanced bot', $fresh->title);
        $this->assertSame(array('openai', 'anthropic'), $meta['fallback_chain']);
        $this->assertSame('on', $meta['adaptive_tool_cap']);
        $this->assertSame(array(12, 34), $meta['memory_files']);
        $this->assertSame('vs_123', $meta['vector_store_id']);
        $this->assertSame('corpus-a', $meta['corpus_name']);
        $this->assertTrue($meta['prompt_caching']);
        $this->assertTrue($meta['disable_tool_shortcuts']);
        $this->assertSame('action-9', $meta['external_action_id']);
        $this->assertSame('workflow', $meta['external_action_type']);
        $this->assertSame('manage_options', $meta['required_capability']);

        $this->assertSame('Summarise', $meta['tool_shortcuts'][0]['label']);
        $this->assertSame('summarise_text', $meta['tool_shortcuts'][0]['tool']);
        $this->assertTrue($meta['tool_shortcuts'][0]['hidden']);

        $this->assertSame('alpha_tool', array_key_first($meta['tool_prebuilt_shortcuts']));
        $this->assertSame('custom', $meta['tool_prebuilt_shortcuts']['alpha_tool']['mode']);
        $this->assertSame('Alpha one', $meta['tool_prebuilt_shortcuts']['alpha_tool']['shortcuts'][0]['label']);
        $this->assertFalse($meta['tool_prebuilt_shortcuts']['alpha_tool']['shortcuts'][0]['hidden']);
        $this->assertTrue($meta['tool_prebuilt_shortcuts']['beta_tool']['shortcuts'][0]['hidden']);

        $this->assertSame('alpha_tool', $meta['tool_role_rules'][0]['tool']);
        $this->assertSame(array('editor'), $meta['tool_role_rules'][0]['roles']);
        $this->assertSame(array(7), $meta['tool_role_rules'][0]['groups']);
        $this->assertSame(array('allow_authenticated'), $meta['tool_role_rules'][0]['flags']);

        $this->assertSame('owner/dataset', $meta['preferred_datasets'][0]['dataset']);
        $this->assertSame('nlp', $meta['preferred_datasets'][0]['category']);
        $this->assertSame('high', $meta['preferred_datasets'][0]['priority']);
    }

    public function test_the_remote_connections_selection_hydrates_and_survives_a_panel_save(): void
    {
        [$alice, $tenant] = $this->actor();
        $assistant = $this->assistantIn(array(
            'title' => 'Mailjet bot',
            'advanced' => array('remote_connections' => array('conn-a', 'settings')),
        ));

        $this->mountEdit($alice, $tenant, $assistant)
            ->assertSchemaComponentExists(
                'advanced.remote_connections',
                checkComponentUsing: static fn ($component): bool => array('conn-a', 'settings') === array_values(
                    $component->getState() ?? array()
                )
            )
            ->setTableActionData(array('title' => 'Mailjet bot renamed'))
            ->callMountedTableAction();

        $fresh = $assistant->fresh();

        $this->assertSame('Mailjet bot renamed', $fresh->title);
        $this->assertSame(array('conn-a', 'settings'), $fresh->meta['remote_connections']);
    }

    /**
     * A connection id that no longer resolves must survive a panel save.
     *
     * Filament validates a multi-select against its option labels, so a stale
     * id that the option closure omitted would fail validation or be dropped —
     * the same silent-loss failure the provider select is written to avoid.
     * This is the remote-site half of that rule.
     */
    public function test_a_remote_connection_that_no_longer_exists_is_preserved_on_save(): void
    {
        [$alice, $tenant] = $this->actor();
        $assistant = $this->assistantIn(array(
            'title' => 'Stale bot',
            'advanced' => array('remote_connections' => array('deleted-connection-id')),
        ));

        $this->mountEdit($alice, $tenant, $assistant)
            ->callMountedTableAction();

        $this->assertSame(
            array('deleted-connection-id'),
            $assistant->fresh()->meta['remote_connections']
        );
    }

    public function test_the_create_form_saves_the_advanced_object(): void
    {
        [$alice, $tenant] = $this->actor();

        $this->bootPage($alice, $tenant)
            ->mountAction('create')
            ->setActionData(array(
                'title' => 'Form-created advanced bot',
                'status' => 'draft',
                'advanced' => $this->advancedFormFixture(),
            ))
            ->callMountedAction();

        $assistant = Assistant::query()
            ->where('title', 'Form-created advanced bot')
            ->firstOrFail();

        $meta = $assistant->meta;

        $this->assertSame(array('openai', 'anthropic'), $meta['fallback_chain']);
        $this->assertSame('Summarise', $meta['tool_shortcuts'][0]['label']);
        $this->assertSame('alpha_tool', array_key_first($meta['tool_prebuilt_shortcuts']));
        $this->assertSame('custom', $meta['tool_prebuilt_shortcuts']['alpha_tool']['mode']);
        $this->assertSame('owner/dataset', $meta['preferred_datasets'][0]['dataset']);
    }

    public function test_clearing_an_advanced_value_removes_the_meta_key(): void
    {
        [$alice, $tenant] = $this->actor();
        $assistant = $this->assistantIn(array(
            'title' => 'Advanced bot',
            'advanced' => $this->advancedFixture(),
        ));

        $this->mountEdit($alice, $tenant, $assistant)
            ->setTableActionData(array(
                'advanced' => array(
                    'fallback_chain' => '',
                    'vector_store_id' => '',
                    'prompt_caching' => false,
                    'tool_shortcuts' => array(),
                    'tool_prebuilt_shortcuts' => array(),
                ),
            ))
            ->callMountedTableAction();

        $meta = $assistant->fresh()->meta;

        $this->assertArrayNotHasKey('fallback_chain', $meta);
        $this->assertArrayNotHasKey('vector_store_id', $meta);
        $this->assertArrayNotHasKey('prompt_caching', $meta);
        $this->assertArrayNotHasKey('tool_shortcuts', $meta);
        $this->assertArrayNotHasKey('tool_prebuilt_shortcuts', $meta);
    }

    public function test_a_panel_edit_preserves_unknown_meta_keys(): void
    {
        [$alice, $tenant] = $this->actor();
        $assistant = $this->assistantIn(array(
            'title' => 'Custom meta bot',
            'meta' => array('custom_key' => 'kept'),
            'advanced' => array('vector_store_id' => 'vs_keep'),
        ));

        $this->mountEdit($alice, $tenant, $assistant)
            ->setTableActionData(array('title' => 'Renamed custom bot'))
            ->callMountedTableAction();

        $this->assertSame('kept', $assistant->fresh()->meta['custom_key']);
        $this->assertSame('vs_keep', $assistant->fresh()->meta['vector_store_id']);
    }

    public function test_invalid_structured_values_are_rejected(): void
    {
        [$alice, $tenant] = $this->actor();

        $this->bootPage($alice, $tenant)
            ->mountAction('create')
            ->setActionData(array(
                'title' => 'Bad advanced bot',
                'status' => 'draft',
                'advanced' => array(
                    'memory_files' => array('not-an-id'),
                    'tool_prebuilt_shortcuts' => array(
                        array(
                            'tool' => 'not a slug',
                            'shortcuts' => array(array('label' => 'One', 'payload' => 'x')),
                        ),
                    ),
                    'preferred_datasets' => array(
                        array('dataset' => ''),
                    ),
                ),
            ))
            ->callMountedAction()
            ->assertHasFormErrors(array(
                'advanced.memory_files.0',
                'advanced.tool_prebuilt_shortcuts.0.tool',
                'advanced.preferred_datasets.0.dataset',
            ));

        $this->assertDatabaseMissing('assistants', array('title' => 'Bad advanced bot'));
    }

    /**
     * The stored shape: the `advanced` object exactly as the REST contract
     * defines it — a tool-slug map, a provider-slug list, and server-side
     * string/`int` lists. This is what `AssistantWriter` accepts under the
     * nested `advanced` key and flattens into `meta`.
     *
     * @return array<string, mixed>
     */
    private function advancedFixture(): array
    {
        return array(
            'fallback_chain' => array('openai', 'anthropic'),
            'adaptive_tool_cap' => 'on',
            'memory_files' => array(12, 34),
            'vector_store_id' => 'vs_123',
            'corpus_name' => 'corpus-a',
            'prompt_caching' => true,
            'disable_tool_shortcuts' => true,
            'tool_shortcuts' => array(
                array(
                    'label' => 'Summarise',
                    'payload' => 'Summarise this.',
                    'tool' => 'summarise_text',
                    'description' => 'Short summary',
                    'hidden' => true,
                ),
            ),
            'tool_prebuilt_shortcuts' => array(
                'alpha_tool' => array(
                    'mode' => 'custom',
                    'shortcuts' => array(
                        array(
                            'label' => 'Alpha one',
                            'payload' => 'Do alpha.',
                            'description' => 'First',
                            'hidden' => false,
                        ),
                    ),
                ),
                'beta_tool' => array(
                    'mode' => 'custom',
                    'shortcuts' => array(
                        array(
                            'label' => 'Beta one',
                            'payload' => 'Do beta.',
                            'hidden' => true,
                        ),
                    ),
                ),
            ),
            'tool_role_rules' => array(
                array(
                    'tool' => 'alpha_tool',
                    'roles' => array('editor'),
                    'groups' => array(7),
                    'flags' => array('allow_authenticated'),
                ),
            ),
            'external_action_id' => 'action-9',
            'external_action_type' => 'workflow',
            'required_capability' => 'manage_options',
            'preferred_datasets' => array(
                array(
                    'dataset' => 'owner/dataset',
                    'name' => 'Dataset',
                    'category' => 'nlp',
                    'priority' => 'high',
                ),
            ),
        );
    }

    /**
     * The form shape: the fallback chain is a comma-joined string and the
     * prebuilt map is a list of `tool` rows.
     *
     * @return array<string, mixed>
     */
    private function advancedFormFixture(): array
    {
        $data = $this->advancedFixture();

        $data['fallback_chain'] = implode(', ', $data['fallback_chain']);

        $rows = array();

        foreach ($data['tool_prebuilt_shortcuts'] as $tool => $settings) {
            $rows[] = array(
                'tool' => $tool,
                'shortcuts' => $settings['shortcuts'],
            );
        }

        $data['tool_prebuilt_shortcuts'] = $rows;

        return $data;
    }

    /**
     * @return array{0: User, 1: Tenant}
     */
    private function actor(): array
    {
        $user = User::factory()->admin()->withoutEstate()->create();
        $tenant = Tenant::query()->findOrFail(Tenant::DEFAULT_ID);

        $tenant->users()->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return array($user, $tenant);
    }

    private function mountEdit(User $user, Tenant $tenant, Assistant $assistant)
    {
        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set($tenant->id);

        return Livewire::test(ManageAssistants::class, array('tenant' => $tenant))
            ->mountTableAction(EditAction::class, $assistant);
    }

    /**
     * The page needs the panel tenant and an authenticated actor before an
     * action can mount; a bare `Livewire::test()` leaves `mountedActions` null.
     */
    private function bootPage(User $user, Tenant $tenant)
    {
        $this->actingAs($user);
        Filament::setTenant($tenant);
        TenantContext::set($tenant->id);

        return Livewire::test(ManageAssistants::class, array('tenant' => $tenant));
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
