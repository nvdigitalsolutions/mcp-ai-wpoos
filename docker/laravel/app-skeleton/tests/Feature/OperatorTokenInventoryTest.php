<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\TokenInventory;
use App\Models\Tenant;
use App\Models\User;
use App\Support\PersonalTokens;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * The operator token inventory — Proposal 149 §114.
 *
 * Three properties carry the weight:
 *
 *  - **The secret is unreachable.** No code path may display a token after
 *    minting, for a user or an administrator; this page never selects the hash
 *    column, and the test proves it by inspecting the query's own SQL rather than
 *    the rendered output (a page can hide a column it still selected).
 *  - **"Reaches" is the holder's memberships, not the token's scope.** A personal
 *    token belongs to a person and carries their authority everywhere they belong;
 *    a test that let this drift would let the page describe a restriction the
 *    runtime does not enforce.
 *  - **The read is audited.** OWASP's multi-tenant guidance asks for a
 *    cross-tenant administrative path to be explicit, separately authorized and
 *    auditable; the third is a row, and the row names which estate was looked at.
 */
final class OperatorTokenInventoryTest extends TestCase
{
    use RefreshDatabase;

    // ── Access ───────────────────────────────────────────────────────────────

    public function test_a_tenant_administrator_cannot_open_the_operator_inventory(): void
    {
        // A customer's estate owner is NOT an operator: operator status belongs to
        // the estate (`is_operator`), never to a person's role. This is the
        // predicate Q-1 exists for.
        $user = User::factory()->admin()->create();
        $user->tenants()->syncWithoutDetaching(array(Tenant::DEFAULT_ID => array('role' => 'owner')));

        $this->actingAs($user);

        $this->assertFalse(TokenInventory::canAccess());
    }

    public function test_an_operator_can_open_it(): void
    {
        $this->actingAsOperator();

        $this->assertTrue(TokenInventory::canAccess());
    }

    // ── The secret ───────────────────────────────────────────────────────────

    public function test_the_hash_column_is_never_selected(): void
    {
        $this->operator()->createToken(PersonalTokens::NAME_PREFIX.'laptop', array('*'), now()->addDays(30));

        $columns = PersonalTokens::query()->getQuery()->columns;

        // Asserting the select list itself. Asserting the rendered rows would be
        // the weaker claim (a page can hide a column it still selected), and
        // asserting a substring of the SQL does not work here at all: the grammar
        // quotes each segment, so even a selected hash appears as
        // `"personal_access_tokens"."token"` and no `personal_access_tokens.token`
        // literal is ever present. A null list is the implicit `select *`.
        $this->assertIsArray($columns, 'The select list must name its columns.');

        $names = array_map(
            static function (string $column): string {
                $dot = strrpos($column, '.');

                return false === $dot ? $column : substr($column, $dot + 1);
            },
            $columns
        );

        $this->assertNotContains('*', $names, 'A wildcard select would carry the hash.');
        $this->assertNotContains('token', $names, 'The hash must not be selected, even for an operator.');

        foreach (PersonalTokens::acrossEstates() as $row) {
            $this->assertArrayNotHasKey('token', $row);
            $this->assertArrayNotHasKey('hash', $row);
        }
    }

    // ── Scope and reach ──────────────────────────────────────────────────────

    public function test_the_unscoped_view_lists_tokens_from_every_estate(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');
        $this->tokenHolder('acme', 'owner@acme.test', 'ci-runner');

        $this->operator();

        $labels = array_column(PersonalTokens::acrossEstates(), 'label');

        sort($labels);

        $this->assertSame(array('ci-runner', 'laptop'), $labels);
    }

    public function test_scoping_narrows_to_holders_of_one_estate(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');
        $this->tokenHolder('acme', 'owner@acme.test', 'ci-runner');

        $this->operator();

        $scoped = PersonalTokens::acrossEstates('acme');

        $this->assertCount(1, $scoped);
        $this->assertSame('ci-runner', $scoped[0]['label']);
        $this->assertSame('owner@acme.test', $scoped[0]['holder_email']);
    }

    public function test_reaches_is_the_holders_memberships_not_the_tokens_scope(): void
    {
        // One holder in two estates, one token. The token is not split in two and
        // is not attributed to either estate alone — it presents the person.
        $user = $this->tokenHolder('default', 'both@example.test', 'shared');

        // `tenant_user` has a real foreign key, so the second estate must exist
        // before the membership can point at it.
        Tenant::query()->firstOrCreate(
            array('id' => 'acme'),
            array('name' => 'Acme estate', 'slug' => 'acme', 'is_operator' => false)
        );

        $user->tenants()->syncWithoutDetaching(array('acme' => array('role' => 'manager')));

        $this->operator();

        $rows = PersonalTokens::acrossEstates();

        $this->assertCount(1, $rows);
        $this->assertSame(
            array('acme', 'default'),
            array_column($rows[0]['estates'], 'slug'),
            'Both memberships are listed, in estate-name order.'
        );

        // And the estate filter still finds it — because the holder can reach it.
        $this->assertCount(1, PersonalTokens::acrossEstates('acme'));
        $this->assertCount(1, PersonalTokens::acrossEstates('default'));
    }

    public function test_a_holder_without_a_membership_reads_as_no_estate(): void
    {
        // A token outlives a membership; the row must not invent one. The factory
        // provisions a default-estate membership, so it has to be removed for
        // this to be the case under test.
        $user = User::factory()->create(array('email' => 'orphan@example.test'));
        $user->tenants()->detach();
        $user->createToken(PersonalTokens::NAME_PREFIX.'left', array('*'), now()->addDays(30));

        $this->operator();

        $rows = PersonalTokens::acrossEstates();

        $this->assertCount(1, $rows);
        $this->assertSame(array(), $rows[0]['estates']);
    }

    public function test_estates_with_holders_omits_estates_that_hold_none(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');

        Tenant::query()->firstOrCreate(
            array('id' => 'empty-estate'),
            array('name' => 'Empty estate', 'slug' => 'empty-estate', 'is_operator' => false)
        );

        $slugs = array_column(PersonalTokens::estatesWithHolders(), 'slug');

        $this->assertSame(array('default'), $slugs);
    }

    // ── The page ─────────────────────────────────────────────────────────────

    public function test_an_unknown_scope_is_ignored_rather_than_honoured(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');

        $this->actingAsOperator();

        // A hand-edited URL must not produce an empty page that reads as "this
        // estate has no tokens".
        Livewire::withQueryParams(array('tenant' => 'does-not-exist'))
            ->test(TokenInventory::class)
            ->assertSet('estate', '')
            ->assertSee('laptop');
    }

    public function test_a_known_scope_is_honoured_and_named(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');
        $this->tokenHolder('acme', 'owner@acme.test', 'ci-runner');

        $this->actingAsOperator();

        Livewire::withQueryParams(array('tenant' => 'acme'))
            ->test(TokenInventory::class)
            ->assertSet('estate', 'acme')
            ->assertSee('ci-runner')
            ->assertDontSee('laptop');
    }

    public function test_never_used_is_spelled_out(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'forgotten');

        $this->actingAsOperator();

        $rows = PersonalTokens::acrossEstates();
        $this->assertSame('', $rows[0]['last_used_at']);

        $this->actingAsOperator();

        Livewire::test(TokenInventory::class)->assertSee('Never used');
    }

    // ── Search and sort, which are SQL-level ──────────────────────────────

    public function test_search_runs_in_sql_across_the_join(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');
        $this->tokenHolder('acme', 'owner@acme.test', 'ci-runner');

        $this->actingAsOperator();

        // The holder's address and the token's own name live in two different
        // joined tables. A records-backed table would render both rows and
        // silently ignore this search, which is why the assertion is on what
        // disappears as well as what remains.
        Livewire::test(TokenInventory::class)
            ->searchTable('acme')
            ->assertSee('ci-runner')
            ->assertDontSee('laptop');
    }

    public function test_sorting_uses_qualified_columns_and_keeps_the_join_valid(): void
    {
        // Distinct names, deliberately: `holder_name` sorts on `users.name`, so
        // equal names would be a tie and the rendered order would depend on the
        // fallback sort rather than on the sort under test.
        $this->tokenHolder('default', 'owner@default.test', 'laptop', 'Zoe Default');
        $this->tokenHolder('acme', 'owner@acme.test', 'ci-runner', 'Adam Acme');

        $this->actingAsOperator();

        // `holder_name` has to sort on `users.name` and `created_at` on
        // `personal_access_tokens.created_at`; either one left unqualified matches
        // both tables and the database rejects it as ambiguous.
        //
        // Asserted on the names, not the addresses: `holder_email` is hidden by
        // default to keep the table inside the viewport, so the address is not in
        // the rendered HTML to compare.
        Livewire::test(TokenInventory::class)
            ->sortTable('holder_name', 'asc')
            ->assertSeeInOrder(array('Adam Acme', 'Zoe Default'));

        Livewire::test(TokenInventory::class)
            ->sortTable('created_at', 'desc')
            ->assertOk();
    }

    // ── The audit trail ──────────────────────────────────────────────────────

    public function test_reading_the_inventory_is_audited_with_the_scope(): void
    {
        $this->tokenHolder('default', 'owner@default.test', 'laptop');
        $this->tokenHolder('acme', 'owner@acme.test', 'ci-runner');

        // The acting principal itself, not a separately created one: `operator()`
        // makes a fresh user each call, so capturing one and authenticating
        // another leaves the audit row pointing at a different id.
        $operator = $this->actingAsOperator();

        Livewire::test(TokenInventory::class);

        $row = DB::table('nvoos_admin_audit')
            ->where('resource', 'personal-tokens-inventory')
            ->orderByDesc('id')
            ->first();

        $this->assertNotNull($row, 'Opening a cross-estate credential inventory must leave a trace.');
        $this->assertSame('view', $row->action);
        $this->assertSame('all-estates', $row->record_id);
        $this->assertSame($operator->id, (int) $row->user_id);
        // The payload key is `count`, not `tokens`: the audit redactor
        // substring-matches "token", so a `tokens` key is stored as
        // `[redacted]` — correctly, under its own over-redaction rule.
        $this->assertStringContainsString('count', (string) $row->after);
        $this->assertSame(2, $row->after === null ? -1 : (json_decode((string) $row->after, true)['count'] ?? -1));

        // Narrowing records WHICH estate, which is the question the trail exists
        // to answer about this page.
        Livewire::withQueryParams(array('tenant' => 'acme'))->test(TokenInventory::class);

        $scoped = DB::table('nvoos_admin_audit')
            ->where('resource', 'personal-tokens-inventory')
            ->orderByDesc('id')
            ->first();

        $this->assertSame('acme', $scoped->record_id);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * Create an estate if needed, a holder in it, and one token.
     */
    private function tokenHolder(string $tenantId, string $email, string $label, ?string $name = null): User
    {
        $tenant = Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array('name' => ucfirst($tenantId).' estate', 'slug' => $tenantId, 'is_operator' => false)
        );

        $attributes = array('email' => $email);

        if (null !== $name) {
            $attributes['name'] = $name;
        }

        $user = User::factory()->create($attributes);
        $user->tenants()->syncWithoutDetaching(array($tenant->id => array('role' => 'owner')));
        $user->createToken(PersonalTokens::NAME_PREFIX.$label, array('*'), now()->addDays(30));

        return $user;
    }

    /**
     * An operator principal: operator-estate membership plus `manage_options`.
     */
    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching(array($user->id => array('role' => 'owner')));

        return $user;
    }

    /**
     * Authenticate as a fresh operator and point the operator panel at them.
     */
    private function actingAsOperator(): User
    {
        $user = $this->operator();

        $this->actingAs($user);

        Filament::setCurrentPanel(Filament::getPanel('operator'));

        return $user;
    }
}
