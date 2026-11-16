<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\AuthProviderInterface;
use Tests\TestCase;

/**
 * Credential expiry is persisted, not just promised (Proposal 149, Wave 2).
 *
 * Found while scoping `/ops` key rotation: `AuthProvider::issueCredential()`
 * accepted `expires_in_seconds` and returned a `Credential` carrying an
 * `expiresAt`, but `Sanctum::createToken()` takes no expiry and the adapter
 * never wrote one onto the row. A token that *looks* time-limited and never
 * expires is a credential-hygiene defect, and rotation is only meaningful if
 * the replacement can be given a lifetime.
 */
class CredentialExpiryTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_expiry_is_persisted_on_the_token_row(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $before = time();

        $credential = app(AuthProviderInterface::class)->issueCredential(7, array(
            'expires_in_seconds' => 3600,
        ));

        $row = DB::table('personal_access_tokens')
            ->where('name', 'oos-assistant-7')
            ->first();

        $this->assertNotNull($row);
        $this->assertNotNull($row->expires_at, 'The token row must carry the expiry the caller asked for.');

        $expiresAt = strtotime((string) $row->expires_at);

        $this->assertGreaterThanOrEqual($before + 3590, $expiresAt);
        $this->assertLessThanOrEqual($before + 3610, $expiresAt);

        // The entity and the row must agree, or a caller reports a lifetime the
        // token does not have.
        $this->assertNotNull($credential->expiresAt);
        $this->assertSame($expiresAt, $credential->expiresAt->getTimestamp());
    }

    public function test_a_token_issued_without_an_expiry_stays_unexpiring(): void
    {
        // The default is still "no expiry" — that is a deliberate choice, not
        // an oversight, and it must not become an accidental one-minute token.
        $this->actingAs(User::factory()->admin()->create());

        app(AuthProviderInterface::class)->issueCredential(8);

        $row = DB::table('personal_access_tokens')
            ->where('name', 'oos-assistant-8')
            ->first();

        $this->assertNotNull($row);
        $this->assertNull($row->expires_at);
    }

    public function test_the_plaintext_token_is_returned_once_and_never_stored(): void
    {
        $this->actingAs(User::factory()->admin()->create());

        $credential = app(AuthProviderInterface::class)->issueCredential(9, array(
            'expires_in_seconds' => 60,
        ));

        $row = DB::table('personal_access_tokens')
            ->where('name', 'oos-assistant-9')
            ->first();

        // Sanctum stores a hash in `token`; the plaintext exists only in the
        // returned entity.
        $this->assertNotSame($credential->token, (string) $row->token);
        $this->assertStringNotContainsString($credential->token, (string) $row->token);
    }
}
