<?php

namespace Tests\Feature;

use App\Filament\Operator\Pages\Credentials;
use App\Models\Assistant;
use App\Models\Tenant;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Proposal 149, Wave 2 — `/ops/credentials` (assistant key rotation).
 *
 * The surface exists to answer one question safely: can an operator replace a
 * credential without the plaintext ever becoming recoverable?
 *
 * The assertions that matter:
 *
 *  - **Rotation replaces, it does not accumulate.** Issue-first-then-revoke, so a
 *    failed issuance leaves the old credential working; a successful one leaves
 *    exactly one token behind.
 *  - **The shown value is the live credential.** It resolves through Sanctum's
 *    own lookup, carries the requested expiry, and is not stored in clear.
 *  - **It is never persisted anywhere durable.** Not in the token row (only the
 *    hash), not in the audit log, not in a notification store. The page property
 *    is the only carrier, and `dismissIssued()` clears it.
 */
class OperatorCredentialsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Filament::setCurrentPanel(Filament::getPanel('operator'));
    }

    public function test_an_operator_reaches_the_page(): void
    {
        $assistant = $this->assistant('Concierge');

        $this->actingAs($this->operator())
            ->get('/ops/credentials')
            ->assertOk()
            ->assertSee('Concierge');
    }

    /**
     * The page is the documented cross-estate opt-out (Proposal 153): `/ops` is
     * the vendor's view, so a scope that quietly reduced this table to the
     * default estate would be a reporting bug, not a safety win.
     */
    public function test_the_page_lists_assistants_from_every_estate(): void
    {
        Tenant::query()->create(array('id' => 'estate-b', 'name' => 'Estate B', 'slug' => 'estate-b'));

        $this->assistant('Home agent');

        $foreign = new Assistant(array(
            'title' => 'Foreign agent',
            'slug' => 'foreign-agent',
            'status' => 'publish',
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ));
        $foreign->tenant_id = 'estate-b';
        $foreign->save();

        $this->actingAs($this->operator())
            ->get('/ops/credentials')
            ->assertOk()
            ->assertSee('Home agent')
            ->assertSee('Foreign agent')
            ->assertSee('Estate B');
    }

    public function test_a_customer_administrator_is_refused(): void
    {
        $this->actingAs($this->customerAdmin())
            ->get('/ops/credentials')
            ->assertForbidden();
    }

    public function test_a_guest_is_sent_to_the_operator_login(): void
    {
        $this->get('/ops/credentials')->assertRedirect('/ops/login');
    }

    public function test_rotation_replaces_the_credential_and_shows_the_new_one(): void
    {
        $operator = $this->operator();
        $assistant = $this->assistant('Concierge');

        $this->seedToken($assistant, 'OLD-CREDENTIAL');

        $this->actingAs($operator);

        $component = Livewire::test(Credentials::class)
            ->callTableAction('rotate', $assistant, data: array('days' => 90));

        $plain = $component->get('issuedToken');

        $this->assertIsString($plain);
        $this->assertNotSame('', $plain);
        $this->assertSame('Concierge', $component->get('issuedFor'));

        $rows = $this->tokensFor($assistant);

        $this->assertCount(1, $rows, 'Rotation must leave exactly one credential.');

        // The old one is gone: its hash is not the stored hash any more.
        $this->assertNotSame(hash('sha256', 'OLD-CREDENTIAL'), (string) $rows->first()->token);

        // The shown value is the live credential, not a display artefact.
        $found = PersonalAccessToken::findToken($plain);

        $this->assertNotNull($found);
        $this->assertSame('oos-assistant-'.$assistant->id, $found->name);
        $this->assertNotNull($found->expires_at, 'The requested expiry must be persisted.');
        $this->assertTrue(
            $found->expires_at->between(now()->addDays(89), now()->addDays(91)),
            'The expiry should be roughly 90 days out.'
        );
    }

    public function test_the_plaintext_is_never_stored_in_clear_or_audited(): void
    {
        $this->actingAs($this->operator());
        $assistant = $this->assistant();

        $component = Livewire::test(Credentials::class)
            ->callTableAction('rotate', $assistant, data: array('days' => 30));

        $plain = (string) $component->get('issuedToken');

        // Only Sanctum's hash is in the row.
        $this->assertStringNotContainsString(
            $plain,
            (string) $this->tokensFor($assistant)->first()->token
        );

        // And the audit row records the rotation without the secret.
        $row = DB::table('nvoos_admin_audit')->where('resource', 'credential')->first();

        $this->assertNotNull($row);
        $this->assertSame('rotated', $row->action);
        $this->assertSame((string) $assistant->id, $row->record_id);
        $this->assertSame(0, json_decode((string) $row->after, true)['revoked']);

        $serialised = (string) $row->before.(string) $row->after.(string) $row->payload;

        $this->assertStringNotContainsString($plain, $serialised);
    }

    public function test_rotating_twice_leaves_exactly_one_credential(): void
    {
        $this->actingAs($this->operator());
        $assistant = $this->assistant();

        $first = Livewire::test(Credentials::class)
            ->callTableAction('rotate', $assistant, data: array('days' => 30));

        $second = Livewire::test(Credentials::class)
            ->callTableAction('rotate', $assistant, data: array('days' => 30));

        $this->assertCount(1, $this->tokensFor($assistant));
        $this->assertNotSame($first->get('issuedToken'), $second->get('issuedToken'));

        // The first shown value stops authenticating.
        $this->assertNull(PersonalAccessToken::findToken((string) $first->get('issuedToken')));
        $this->assertNotNull(PersonalAccessToken::findToken((string) $second->get('issuedToken')));
    }

    public function test_revoking_deletes_without_issuing(): void
    {
        $this->actingAs($this->operator());
        $assistant = $this->assistant();

        $this->seedToken($assistant, 'OLD-CREDENTIAL');

        $component = Livewire::test(Credentials::class)
            ->callTableAction('revoke', $assistant);

        $this->assertCount(0, $this->tokensFor($assistant));
        $this->assertNull($component->get('issuedToken'), 'Revoking must not mint a replacement.');

        $this->assertSame(
            'revoked',
            DB::table('nvoos_admin_audit')->where('resource', 'credential')->value('action')
        );
    }

    public function test_the_revoke_action_is_hidden_when_there_is_nothing_to_revoke(): void
    {
        $this->actingAs($this->operator());
        $assistant = $this->assistant();

        Livewire::test(Credentials::class)
            ->assertTableActionHidden('revoke', $assistant);

        $this->seedToken($assistant, 'OLD-CREDENTIAL');

        Livewire::test(Credentials::class)
            ->assertTableActionVisible('revoke', $assistant);
    }

    public function test_dismissing_hides_the_shown_credential_without_revoking_it(): void
    {
        $this->actingAs($this->operator());
        $assistant = $this->assistant();

        $component = Livewire::test(Credentials::class)
            ->callTableAction('rotate', $assistant, data: array('days' => 30));

        $plain = (string) $component->get('issuedToken');

        $component->call('dismissIssued');

        $this->assertNull($component->get('issuedToken'));
        $this->assertNull($component->get('issuedFor'));
        $this->assertNull($component->get('issuedExpiresAt'));

        // Hiding is not revoking: the credential still works.
        $this->assertNotNull(PersonalAccessToken::findToken($plain));
    }

    public function test_the_page_predicate_refuses_a_non_operator(): void
    {
        $this->actingAs($this->customerAdmin());

        $this->assertFalse(Credentials::canAccess());
    }

    public function test_an_unknown_token_name_is_not_counted_against_an_assistant(): void
    {
        $this->actingAs($this->operator());
        $assistant = $this->assistant();

        DB::table('personal_access_tokens')->insert(array(
            'tokenable_type' => User::class,
            'tokenable_id' => 1,
            'name' => 'unrelated-token',
            'token' => hash('sha256', 'someone-elses'),
            'abilities' => (string) json_encode(array('*')),
            'created_at' => now(),
            'updated_at' => now(),
        ));

        Livewire::test(Credentials::class)
            ->assertTableActionHidden('revoke', $assistant);
    }

    private function operator(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::OPERATOR_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function customerAdmin(): User
    {
        $user = User::factory()->admin()->create();

        Tenant::query()
            ->findOrFail(Tenant::DEFAULT_ID)
            ->users()
            ->syncWithoutDetaching([$user->id => array('role' => 'owner')]);

        return $user;
    }

    private function assistant(string $title = 'Assistant'): Assistant
    {
        return Assistant::query()->create(array(
            'title' => $title,
            'slug' => Str::slug($title).'-'.Str::lower(Str::random(6)),
            'status' => 'publish',
            'provider' => 'openai',
            'model' => 'gpt-4o',
        ));
    }

    /**
     * A pre-existing credential for the assistant, stored the way Sanctum does.
     */
    private function seedToken(Assistant $assistant, string $plain): void
    {
        DB::table('personal_access_tokens')->insert(array(
            'tokenable_type' => User::class,
            'tokenable_id' => 1,
            'name' => 'oos-assistant-'.$assistant->id,
            'token' => hash('sha256', $plain),
            'abilities' => (string) json_encode(array('edit_posts')),
            'created_at' => now(),
            'updated_at' => now(),
        ));
    }

    /**
     * @return \Illuminate\Support\Collection<int, object>
     */
    private function tokensFor(Assistant $assistant)
    {
        return DB::table('personal_access_tokens')
            ->where('name', 'oos-assistant-'.$assistant->id)
            ->get();
    }
}
