<?php

namespace Tests\Feature;

use App\Mail\AccessDigest;
use App\Models\Tenant;
use App\Models\User;
use App\Support\Auth\JitProvisioner;
use App\Support\Settings\SettingsService;
use App\Support\SettingsSchema;
use App\Support\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * The access-request digest — Proposal 149 §111.
 *
 * §110 gave the refusal a page; this gives it a push. The properties under test
 * are the ones that make a recurring email worth reading rather than worth
 * filtering:
 *
 *  - **silence when there is nothing to say** — an empty digest trains the
 *    recipient to ignore the channel, so "0 pending ⇒ 0 mail" is a rule, not an
 *    optimisation;
 *  - **one estate per mail** — the content is one estate's refused addresses,
 *    and a combined mail would disclose them to somebody outside it;
 *  - **only people who can act** — a `viewer` is refused the page outright, so
 *    mailing one would describe a decision they cannot take part in;
 *  - **the recipient is not left to guess** — count, addresses, the gate in
 *    words, and a link to the page that can resolve it;
 *  - **a schedule is not a failure domain** — an uninstalled table, a deleted
 *    estate or one estate's broken transport must not stop the rest.
 */
final class AccessDigestTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
    }

    // ── Silence ──────────────────────────────────────────────────────────────

    public function test_nothing_pending_means_no_mail(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');

        $this->artisan('nvoos:access-digest')
            ->expectsOutputToContain('no digest sent')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_fresh_install_is_inert_rather_than_loud(): void
    {
        // The command is on a schedule, so a deployment that has not run §110's
        // migration must find it a no-op — not a failure every morning.
        Schema::drop('nvoos_jit_provisioning_requests');

        $this->artisan('nvoos:access-digest')
            ->expectsOutputToContain('not installed here')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_an_estate_that_turned_the_digest_off_is_skipped(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');
        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);
        $this->setDigest('default', false);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertNothingSent();
    }

    public function test_a_request_whose_estate_no_longer_exists_is_skipped_not_fatal(): void
    {
        // `nvoos_jit_provisioning_requests` has no foreign key on `tenant_id`, so
        // a request can outlive the estate. There is nobody to address and no
        // page to link: skip, and still exit clean.
        $this->pending('ghost-estate', 'alice@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertNothingSent();
    }

    // ── The recipient set ────────────────────────────────────────────────────

    public function test_the_digest_goes_to_the_decision_makers_and_not_to_a_viewer(): void
    {
        $estate = $this->estateWithOwner('default', 'owner@example.test');

        $this->member($estate, 'admin@example.test', 'admin');
        $this->member($estate, 'viewer@example.test', 'viewer');

        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, 2);
        $this->assertSentTo('owner@example.test');
        $this->assertSentTo('admin@example.test');
        $this->assertNotSentTo('viewer@example.test');
    }

    public function test_an_estate_of_managers_is_still_told(): void
    {
        // `editor` maps to `manager` in the membership backfill, so an estate with
        // no owner and no admin is a real configuration — and the one where
        // "nobody was told" would otherwise be silent.
        $estate = $this->estateWithOwner('default', 'owner@example.test');
        $estate->users()->detach();
        $this->member($estate, 'editor@example.test', 'manager');

        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, 1);
        $this->assertSentTo('editor@example.test');
    }

    public function test_an_estate_with_nobody_to_tell_does_not_fail_the_run(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');
        Tenant::query()->findOrFail('default')->users()->detach();

        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest')
            ->expectsOutputToContain('no administrator to tell')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    // ── One estate per mail ──────────────────────────────────────────────────

    public function test_each_estate_is_told_only_about_its_own_requests(): void
    {
        $first = $this->estateWithOwner('default', 'owner@default.test');
        $second = $this->estateWithOwner('acme', 'owner@acme.test');

        $this->assertNotSame($first->id, $second->id);

        $this->pending('default', 'alice@default.test', JitProvisioner::REASON_DISABLED);
        $this->pending('acme', 'bob@acme.test', JitProvisioner::REASON_DOMAIN);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, 2);

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            if (! $mail->hasTo('owner@default.test')) {
                return false;
            }

            $body = $mail->render();

            $this->assertStringContainsString('alice@default.test', $body);
            $this->assertStringNotContainsString(
                'bob@acme.test',
                $body,
                'One estate\'s addresses must never reach another estate.'
            );

            return true;
        });

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            if (! $mail->hasTo('owner@acme.test')) {
                return false;
            }

            $this->assertStringContainsString('bob@acme.test', $mail->render());

            return true;
        });
    }

    public function test_the_tenant_option_narrows_the_run(): void
    {
        $this->estateWithOwner('default', 'owner@default.test');
        $this->estateWithOwner('acme', 'owner@acme.test');

        $this->pending('default', 'alice@default.test', JitProvisioner::REASON_DISABLED);
        $this->pending('acme', 'bob@acme.test', JitProvisioner::REASON_DOMAIN);

        $this->artisan('nvoos:access-digest', array('--tenant' => 'acme'))->assertSuccessful();

        Mail::assertSent(AccessDigest::class, 1);
        $this->assertSentTo('owner@acme.test');
    }

    // ── What the mail says ───────────────────────────────────────────────────

    public function test_the_mail_says_how_many_who_why_and_where(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');
        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DOMAIN);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            $this->assertSame(1, $mail->total);

            // `Mail::fake()` records without rendering, so a broken template
            // would pass every other assertion. Render it: a mail that names the
            // count but omits the address or the link is worse than no mail.
            $body = $mail->render();

            $this->assertStringContainsString('alice@acme.test', $body);
            $this->assertStringContainsString('The address domain is not on the verified list', $body);
            $this->assertStringContainsString('/admin/default/access-requests', $body);
            $this->assertStringContainsString(
                'One person has tried to sign in to Default estate and was not let in.',
                $body
            );

            return true;
        });
    }

    public function test_the_subject_carries_the_count_and_pluralises(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');
        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);
        $this->pending('default', 'bob@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            $this->assertSame('2 access requests are waiting for Default estate', $mail->envelope()->subject);
            $this->assertStringContainsString(
                '2 people have tried to sign in to Default estate and were not let in.',
                $mail->render()
            );

            return true;
        });
    }

    public function test_the_preview_is_bounded_and_the_count_is_not(): void
    {
        config(array('oos.access_digest.preview_limit' => 3));

        $this->estateWithOwner('default', 'owner@example.test');

        for ($n = 1; $n <= 7; ++$n) {
            $this->pending('default', 'person'.$n.'@acme.test', JitProvisioner::REASON_DISABLED);
        }

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            // Ten rows in an inbox is a digest; seventy is a log.
            $this->assertCount(3, $mail->rows);
            $this->assertSame(7, $mail->total);

            $body = $mail->render();

            $this->assertStringContainsString('7 people have tried to sign in', $body);
            $this->assertStringContainsString('and 4 more', $body);

            return true;
        });
    }

    public function test_an_unasserted_address_is_named_rather_than_left_blank(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');
        $this->pending('default', '', JitProvisioner::REASON_UNVERIFIED);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            $this->assertSame('(no address asserted)', $mail->rows[0]['email']);
            $this->assertStringContainsString('(no address asserted)', $mail->render());

            return true;
        });
    }

    public function test_an_estate_name_is_not_html_escaped_in_a_plain_text_body(): void
    {
        // Blade's `{{ }}` escapes for HTML. This body is `text/plain`, where that
        // is a corruption rather than a protection: "Smith & Sons" would arrive
        // as "Smith &amp; Sons" and the mail would look broken.
        $tenant = $this->estateWithOwner('default', 'owner@example.test');
        $tenant->forceFill(array('name' => 'Smith & Sons <Ltd>'))->save();

        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest')->assertSuccessful();

        Mail::assertSent(AccessDigest::class, function (AccessDigest $mail): bool {
            $body = $mail->render();

            $this->assertStringContainsString('Smith & Sons <Ltd>', $body);
            $this->assertStringNotContainsString('&amp;', $body);
            $this->assertStringNotContainsString('&lt;', $body);

            return true;
        });
    }

    // ── Dry run ──────────────────────────────────────────────────────────────

    public function test_a_dry_run_reports_without_sending(): void
    {
        $this->estateWithOwner('default', 'owner@example.test');
        $this->pending('default', 'alice@acme.test', JitProvisioner::REASON_DISABLED);

        $this->artisan('nvoos:access-digest', array('--dry-run' => true))
            ->expectsOutputToContain('would be sent')
            ->assertSuccessful();

        Mail::assertNothingSent();
    }

    // ── Wiring ───────────────────────────────────────────────────────────────

    public function test_the_setting_is_tenant_scope_and_on_by_default(): void
    {
        $field = SettingsSchema::field('enable_access_digest');

        $this->assertNotNull($field, 'The digest switch must be in the catalogue, or it is not a switch.');
        $this->assertSame('tenant', $field['scope']);
        $this->assertTrue($field['default']);

        TenantContext::set('default');

        try {
            $this->assertTrue(
                (bool) app(SettingsService::class)->value('enable_access_digest'),
                'An estate that has never touched the setting gets the digest.'
            );
        } finally {
            TenantContext::forget();
        }
    }

    public function test_the_digest_is_scheduled_daily_at_a_fixed_hour(): void
    {
        $schedule = (string) config('oos.access_digest.schedule');

        $this->assertSame('0 8 * * *', $schedule, 'The default is a fixed, predictable hour, not an interval.');
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function assertSentTo(string $address): void
    {
        Mail::assertSent(
            AccessDigest::class,
            static fn (AccessDigest $mail): bool => $mail->hasTo($address)
        );
    }

    private function assertNotSentTo(string $address): void
    {
        Mail::assertNotSent(
            AccessDigest::class,
            static fn (AccessDigest $mail): bool => $mail->hasTo($address)
        );
    }

    private function estateWithOwner(string $tenantId, string $email): Tenant
    {
        $tenant = Tenant::query()->firstOrCreate(
            array('id' => $tenantId),
            array('name' => 'Estate '.$tenantId, 'slug' => $tenantId, 'is_operator' => false)
        );

        $this->member($tenant, $email, 'owner');

        return $tenant;
    }

    private function member(Tenant $tenant, string $email, string $role): User
    {
        $user = User::factory()->create(array('email' => $email));

        $tenant->users()->syncWithoutDetaching(array($user->id => array('role' => $role)));

        return $user;
    }

    /**
     * Record a pending request directly: these tests are about the digest, not
     * about the gates that produce a row.
     */
    private function pending(string $tenantId, string $email, string $reason): void
    {
        DB::table('nvoos_jit_provisioning_requests')->insert(array(
            'tenant_id' => $tenantId,
            'subject' => 'auth0|'.md5($tenantId.$email),
            'email' => $email,
            'reason' => $reason,
            'status' => 'pending',
            'attempted_at' => now()->subHours(2),
            'created_at' => now(),
            'updated_at' => now(),
        ));
    }

    private function setDigest(string $tenantId, bool $on): void
    {
        TenantContext::set($tenantId);

        try {
            app(SettingsService::class)->write(
                array('enable_access_digest' => $on),
                SettingsService::SURFACE_ADMIN
            );
        } finally {
            TenantContext::forget();
        }
    }
}
