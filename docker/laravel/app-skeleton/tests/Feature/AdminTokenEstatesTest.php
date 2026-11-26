<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\TokenUsage;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * 149 §87 — the `per_site` view, as an estate scope on `/ops/token-usage`.
 *
 * The WordPress view is "site-wide statistics": the whole scope's requests,
 * users and spend. In a multi-estate runtime the estate **is** the site, so the
 * port is one control — the estate select — and the `per_user` table underneath
 * it becomes that estate's user list.
 *
 * The numbers are pinned against a fixture small enough to check by hand, so a
 * broken `SUM` or a double-counted anonymous turn fails here rather than on a
 * dashboard.
 */
class AdminTokenEstatesTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'nvoos_token_usage';

    private const ESTATE_A = 'estate-a';

    private const ESTATE_B = 'estate-b';

    /** Estate A's totals at the fixture below: 4 events, 2 users, 480 tokens. */
    private const A_TOKENS = 480;

    private const B_TOKENS = 75;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        Carbon::setTestNow('2026-11-19 12:00:00');

        config(['sanctum.stateful' => ['localhost', '127.0.0.1']]);
        $this->withHeader('Referer', 'http://localhost');

        foreach ([self::ESTATE_A => 'Estate A', self::ESTATE_B => 'Estate B'] as $id => $name) {
            Tenant::query()->create(array(
                'id' => $id,
                'name' => $name,
                'slug' => $id,
            ));
        }
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_the_estate_table_groups_usage_by_estate(): void
    {
        $this->fixture();

        $estates = Livewire::actingAs($this->operator())
            ->test(TokenUsage::class)
            ->instance()
            ->estates();

        $this->assertCount(2, $estates, 'Only estates with usage are listed.');

        $a = $estates[0];
        $b = $estates[1];

        $this->assertSame(self::ESTATE_A, $a['tenant_id'], 'Largest token volume sorts first.');
        $this->assertSame('Estate A', $a['name']);
        $this->assertSame(4, $a['events']);
        $this->assertSame(2, $a['users'], 'Two authenticated users; the anonymous turn is not a third.');
        $this->assertSame(320, $a['prompt_tokens']);
        $this->assertSame(160, $a['completion_tokens']);
        $this->assertSame(self::A_TOKENS, $a['total_tokens']);
        $this->assertEqualsWithDelta(0.071, $a['cost'], 1e-6);
        $this->assertEqualsWithDelta(86.5, $a['share'], 0.05);

        $this->assertSame(self::ESTATE_B, $b['tenant_id']);
        $this->assertSame(1, $b['users']);
        $this->assertSame(self::B_TOKENS, $b['total_tokens']);
        $this->assertEqualsWithDelta(13.5, $b['share'], 0.05);

        $this->assertEqualsWithDelta(
            100.0,
            (float) array_sum(array_column($estates, 'share')),
            0.2,
            'Shares are of tokens, so they sum to the whole.'
        );
    }

    public function test_selecting_an_estate_scopes_the_user_table(): void
    {
        $this->fixture();

        $component = Livewire::actingAs($this->operator())->test(TokenUsage::class);

        $all = $component->instance()->totals();
        $this->assertSame(self::A_TOKENS + self::B_TOKENS, $all['total_tokens'], 'Unscoped is every estate.');

        $component->set('estate', self::ESTATE_A);

        $rows = $component->instance()->rows();
        $this->assertSame(4, $component->instance()->totals()['events']);
        $this->assertSame(self::A_TOKENS, $component->instance()->totals()['total_tokens']);
        $this->assertSame(
            ['Alice Operator', 'Anonymous', 'Bob Builder'],
            $this->sortedNames($rows),
            'Only estate A\'s users, and the anonymous turn stays anonymous.'
        );

        $component->set('estate', self::ESTATE_B);

        $this->assertSame(
            ['Carol Estate B'],
            $this->sortedNames($component->instance()->rows())
        );
        $this->assertSame(self::B_TOKENS, $component->instance()->totals()['total_tokens']);

        $component->set('estate', '');

        $this->assertFalse($component->instance()->isScoped(), 'An empty selection means every estate.');
        $this->assertSame(self::A_TOKENS + self::B_TOKENS, $component->instance()->totals()['total_tokens']);
    }

    public function test_the_selector_lists_only_estates_with_usage(): void
    {
        $this->fixture();

        // A real estate with no turns in the window: not a row, not an option.
        Tenant::query()->create(array(
            'id' => 'estate-c',
            'name' => 'Estate C',
            'slug' => 'estate-c',
        ));

        $options = Livewire::actingAs($this->operator())
            ->test(TokenUsage::class)
            ->instance()
            ->estateOptions();

        $this->assertSame('All estates', $options['']);
        $this->assertArrayHasKey(self::ESTATE_A, $options);
        $this->assertArrayHasKey(self::ESTATE_B, $options);
        $this->assertArrayNotHasKey('estate-c', $options, 'An estate that spent nothing is not navigable.');
    }

    public function test_an_estate_from_the_url_that_has_no_usage_stays_selected(): void
    {
        $this->fixture();

        $page = Livewire::withQueryParams(array('estate' => 'ghost-estate'))
            ->actingAs($this->operator())
            ->test(TokenUsage::class)
            ->instance();

        $this->assertTrue($page->isScoped());
        $this->assertSame('ghost-estate', $page->estateScope());
        $this->assertSame(
            'ghost-estate',
            $page->estateOptions()['ghost-estate'],
            'An unknown estate falls back to its id, like `User #id`.'
        );
        $this->assertSame(array(), $page->rows());
        $this->assertSame(0, $page->totals()['total_tokens'], 'An unknown estate has no rows, not every row.');
    }

    public function test_an_empty_query_parameter_means_every_estate(): void
    {
        $this->fixture();

        $page = Livewire::withQueryParams(array('estate' => ''))
            ->actingAs($this->operator())
            ->test(TokenUsage::class)
            ->instance();

        $this->assertFalse($page->isScoped());
        $this->assertSame(self::A_TOKENS + self::B_TOKENS, $page->totals()['total_tokens']);
    }

    public function test_the_page_renders_both_sections(): void
    {
        $this->fixture();

        $this->actingAs($this->operator())
            ->get('/ops/token-usage')
            ->assertOk()
            ->assertSee('By estate')
            ->assertSee('Estate A')
            ->assertSee('By user — All estates');
    }

    public function test_the_estate_table_is_hidden_when_scoped(): void
    {
        $this->fixture();

        $this->actingAs($this->operator())
            ->get('/ops/token-usage?estate='.self::ESTATE_A)
            ->assertOk()
            ->assertDontSee('By estate')
            ->assertSee('By user — Estate A')
            ->assertSee('Alice Operator')
            ->assertDontSee('Carol Estate B');
    }

    public function test_an_estate_with_no_usage_says_so_rather_than_showing_zeros(): void
    {
        $this->fixture();

        $this->actingAs($this->operator())
            ->get('/ops/token-usage?estate=ghost-estate')
            ->assertOk()
            ->assertSee('ghost-estate recorded no token usage in this window');
    }

    public function test_the_scoped_view_breaks_usage_down_by_model(): void
    {
        $this->fixture();

        // A second model in estate A only, so a breakdown that ignored the scope
        // would still find it by accident; the count and the order are what make
        // this a scope test rather than a grouping test.
        $this->insert(self::ESTATE_A, 0, 40, 20, 0.004, 'gemini-2');

        $component = Livewire::actingAs($this->operator())->test(TokenUsage::class);
        $component->set('estate', self::ESTATE_A);

        $models = $component->instance()->models();

        $this->assertCount(2, $models, 'Only estate A\'s models appear.');
        $this->assertSame('gpt-4o-mini', $models[0]['model_id'], 'Largest token volume sorts first.');
        $this->assertSame(self::A_TOKENS, $models[0]['total_tokens']);
        $this->assertSame('gemini-2', $models[1]['model_id']);
        $this->assertSame(60, $models[1]['total_tokens']);
    }

    public function test_the_model_breakdown_is_absent_while_unscoped(): void
    {
        $this->fixture();

        $this->assertSame(
            array(),
            Livewire::actingAs($this->operator())->test(TokenUsage::class)->instance()->models(),
            'The estate table is the overview; a platform-wide model table would be a third one.'
        );
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<string>
     */
    private function sortedNames(array $rows): array
    {
        $names = array_column($rows, 'name');
        sort($names);

        return $names;
    }

    /**
     * Estate A: two users, one anonymous turn. Estate B: one user.
     */
    private function fixture(): void
    {
        $alice = User::factory()->admin()->inEstate(self::ESTATE_A, 'owner')
            ->create(array('name' => 'Alice Operator'));
        $bob = User::factory()->admin()->inEstate(self::ESTATE_A, 'owner')
            ->create(array('name' => 'Bob Builder'));
        $carol = User::factory()->admin()->inEstate(self::ESTATE_B, 'owner')
            ->create(array('name' => 'Carol Estate B'));

        $this->insert(self::ESTATE_A, $alice->id, 100, 50, 0.02);
        $this->insert(self::ESTATE_A, $alice->id, 10, 5, 0.001);
        $this->insert(self::ESTATE_A, $bob->id, 200, 100, 0.05);
        $this->insert(self::ESTATE_A, 0, 10, 5, 0.0);
        $this->insert(self::ESTATE_B, $carol->id, 50, 25, 0.01);
    }

    private function insert(string $tenantId, int $userId, int $prompt, int $completion, float $cost, string $model = 'gpt-4o-mini'): void
    {
        DB::table(self::TABLE)->insert(array(
            'tenant_id' => $tenantId,
            'user_id' => $userId,
            'model_id' => $model,
            'prompt_tokens' => $prompt,
            'completion_tokens' => $completion,
            'total_tokens' => $prompt + $completion,
            'cost' => $cost,
            'metadata' => json_encode(array('provider' => 'openai')),
            'created_at' => now(),
        ));
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => ['role' => 'owner']]);

        return $user;
    }
}
