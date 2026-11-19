<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\Datasets;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Datasets\DatasetCatalogue;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Nvoos\Core\Application\Tool\ToolReadiness;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Tool\HuggingFaceRecommendedDatasetsTool;
use Tests\TestCase;

/**
 * Proposal 149 §95 — `/ops/datasets`, the browser §85.1 left `planned`.
 *
 * The page is a catalogue, so what matters is that its rows are **true**: the
 * endpoint column is read from each tool's own constant rather than re-typed
 * here, the starting points come from the tool that answers them, and a token is
 * described without its value ever being read.
 *
 * The anti-drift test is the one worth keeping: a twelfth HuggingFace tool that
 * declared no `ENDPOINT` would silently appear as a "built-in list" row, which is
 * a fact about the page being wrong, not a styling problem.
 */
class AdminDatasetsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        config(['sanctum.stateful' => array('localhost', '127.0.0.1')]);
        $this->withHeader('Referer', 'http://localhost');
    }

    public function test_the_page_lists_every_dataset_tool_with_its_endpoint(): void
    {
        $this->actingAs($this->operator())
            ->get('/ops/datasets')
            ->assertOk()
            ->assertSee('Datasets')
            ->assertSee('HuggingFace Dataset Get Rows')
            ->assertSee('huggingface_dataset_get_rows')
            ->assertSee('/rows')
            ->assertSee('/first-rows')
            ->assertSee('built-in list');
    }

    /**
     * The whole point of the `ENDPOINT` constants: the catalogue cannot describe
     * a route the tool does not call.
     */
    public function test_every_api_tool_declares_the_endpoint_the_catalogue_shows(): void
    {
        $rows = DatasetCatalogue::rows(app(ToolRegistry::class), app(ToolReadiness::class));

        $this->assertNotEmpty($rows, 'The registry must serve the HuggingFace tools — §91 classified them.');

        $api = 0;
        $static = 0;

        foreach ($rows as $row) {
            $class = $this->classFor($row['slug']);

            if (null === $row['endpoint']) {
                ++$static;

                $this->assertFalse(
                    defined($class.'::ENDPOINT'),
                    "{$row['slug']} shows no endpoint but its class declares one."
                );

                continue;
            }

            ++$api;

            $this->assertTrue(
                defined($class.'::ENDPOINT'),
                "{$row['slug']} shows an endpoint its class does not declare."
            );
            $this->assertSame(constant($class.'::ENDPOINT'), $row['endpoint']);
        }

        // Exactly one tool answers from a list, and it is the one §91.2
        // reclassified as `pure`.
        $this->assertSame(1, $static);
        $this->assertSame(10, $api);
    }

    public function test_the_starting_points_are_the_tools_own_list(): void
    {
        $expected = HuggingFaceRecommendedDatasetsTool::datasets();

        $this->actingAs($this->operator())
            ->get('/ops/datasets')
            ->assertOk()
            ->assertSee('imdb')
            ->assertSee('cifar10')
            ->assertSee(number_format((int) $expected[0]['rows']));
    }

    public function test_the_category_filter_narrows_the_starting_points(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Datasets::class)
            ->set('category', 'vision')
            ->assertSee('cifar10')
            ->assertDontSee('imdb');
    }

    public function test_the_search_narrows_the_tools(): void
    {
        $this->actingAs($this->operator());

        Livewire::test(Datasets::class)
            ->set('search', 'first-rows')
            ->assertSee('HuggingFace Dataset Preview Rows')
            ->assertDontSee('HuggingFace Dataset Filter');
    }

    /**
     * A deployment token is reported as present, and its value is never in the
     * payload — the same rule the diagnostics and asset pages follow.
     */
    public function test_a_deployment_token_is_reported_without_its_value(): void
    {
        config(array('services.huggingface.key' => 'hf_secret_should_never_render'));

        $this->actingAs($this->operator());

        Livewire::test(Datasets::class)
            ->assertOk()
            ->assertDontSee('No deployment token')
            ->assertDontSee('hf_secret_should_never_render');
    }

    public function test_an_unconfigured_deployment_says_where_a_token_goes(): void
    {
        config(array('services.huggingface.key' => null, 'oos.api_keys.huggingface' => null));

        $this->actingAs($this->operator());

        Livewire::test(Datasets::class)
            ->assertOk()
            ->assertSee('No deployment token')
            ->assertSee('Settings → Providers');
    }

    public function test_the_page_requires_the_operator_panel(): void
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        $this->actingAs($user)
            ->get('/ops/datasets')
            ->assertForbidden();
    }

    /**
     * The slug → class map, resolved through the registry so a renamed class
     * fails here rather than in a stale literal.
     */
    private function classFor(string $slug): string
    {
        foreach (app(ToolRegistry::class)->all() as $tool) {
            if ($slug === $tool->getSlug()) {
                return $tool::class;
            }
        }

        $this->fail("No registered tool for slug {$slug}.");
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }
}
