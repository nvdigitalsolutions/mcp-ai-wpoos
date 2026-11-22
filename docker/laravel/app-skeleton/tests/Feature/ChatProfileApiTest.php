<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Nvoos\Core\Domain\Contract\ChatProfileResolverInterface;
use Tests\TestCase;

/**
 * Chat-profile API — the preference half of the Proposal 015 port.
 *
 * The enforcement half is pinned by `ChatProfileEnforcementTest`; this file
 * pins the trust boundary around the setter, which is where a user could
 * otherwise widen their own grant:
 *
 *  - entering a restrictive profile needs no authority (a downgrade),
 *  - leaving one needs `manage_options` (the upgrade direction),
 *  - selecting the site default clears the override rather than pinning it.
 */
class ChatProfileApiTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_guest_is_refused(): void
    {
        $this->getJson('/api/v1/chat-profile')->assertUnauthorized();
        $this->putJson('/api/v1/chat-profile', array('profile' => 'read-only'))->assertUnauthorized();
    }

    public function test_the_catalogue_reports_both_profiles_and_the_resolved_one(): void
    {
        Sanctum::actingAs($this->administrator());

        $this->getJson('/api/v1/chat-profile')
            ->assertOk()
            ->assertJsonPath('success', true)
            ->assertJsonPath('enabled', true)
            ->assertJsonPath('current', 'write')
            ->assertJsonPath('profiles.0.slug', 'write')
            ->assertJsonPath('profiles.1.slug', 'read-only')
            ->assertJsonPath('profiles.1.selectable', true);
    }

    public function test_a_user_can_restrict_themselves(): void
    {
        $user = $this->administrator();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/chat-profile', array('profile' => 'read-only'))
            ->assertOk()
            ->assertJsonPath('current', 'read-only');

        $this->assertSame('read-only', $user->fresh()->chat_profile);
        $this->getJson('/api/v1/chat-profile')->assertJsonPath('current', 'read-only');
    }

    /**
     * The asymmetry the whole endpoint exists to protect.
     *
     * A subscriber who is read-only must not be able to call the same setter
     * that restricted them and come back out with write access.
     */
    public function test_a_downgraded_user_cannot_elevate_themselves(): void
    {
        $user = User::factory()->create();
        $user->forceFill(array('chat_profile' => 'read-only'))->save();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/chat-profile', array('profile' => 'write'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'chat_profile_forbidden');

        $this->assertSame('read-only', $user->fresh()->chat_profile);
    }

    public function test_a_manager_can_return_to_write_and_the_override_is_cleared(): void
    {
        $user = $this->administrator();
        $user->forceFill(array('chat_profile' => 'read-only'))->save();
        Sanctum::actingAs($user);

        $this->putJson('/api/v1/chat-profile', array('profile' => 'write'))->assertOk();

        $this->assertNull(
            $user->fresh()->chat_profile,
            'Selecting the site default clears the personal override instead of pinning it.'
        );
        $this->getJson('/api/v1/chat-profile')->assertJsonPath('current', 'write');
    }

    public function test_an_unknown_profile_is_refused_rather_than_widened_to_write(): void
    {
        Sanctum::actingAs($this->administrator());

        $this->putJson('/api/v1/chat-profile', array('profile' => 'superuser'))
            ->assertStatus(400)
            ->assertJsonPath('error.code', 'chat_profile_invalid');
    }

    public function test_a_missing_profile_is_a_validation_error(): void
    {
        Sanctum::actingAs($this->administrator());

        $this->putJson('/api/v1/chat-profile', array())->assertStatus(422);
    }

    public function test_the_writer_refuses_when_the_feature_is_disabled(): void
    {
        $user = $this->administrator();
        Sanctum::actingAs($user);

        config(array('oos.chat_profiles.enabled' => false));
        $this->app->forgetInstance(ChatProfileResolverInterface::class);

        $this->putJson('/api/v1/chat-profile', array('profile' => 'read-only'))
            ->assertForbidden()
            ->assertJsonPath('error.code', 'chat_profile_disabled');

        $this->getJson('/api/v1/chat-profile')
            ->assertOk()
            ->assertJsonPath('enabled', false)
            ->assertJsonPath('current', 'write');

        $this->assertNull($user->fresh()->chat_profile);
    }

    public function test_the_site_default_applies_when_the_user_has_no_selection(): void
    {
        $user = $this->administrator();
        Sanctum::actingAs($user);

        config(array('oos.chat_profiles.default' => 'read-only'));
        $this->app->forgetInstance(ChatProfileResolverInterface::class);

        $this->getJson('/api/v1/chat-profile')
            ->assertOk()
            ->assertJsonPath('current', 'read-only');

        $this->assertNull($user->fresh()->chat_profile, 'The default is inherited, not stored.');
    }

    private function administrator(): User
    {
        // The factory's default state attaches the user to the default estate,
        // which these routes need: they sit behind ResolveApiTenant.
        return User::factory()->admin()->create();
    }
}
