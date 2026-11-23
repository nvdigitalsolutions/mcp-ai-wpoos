<?php

namespace Tests\Feature;

use App\Support\Settings\SettingsCatalogue;
use App\Support\Settings\SettingsService;
use App\Support\SettingsSchema;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * 149 §62 — the ported WordPress settings catalogue.
 *
 * The catalogue is generated from the plugin's section classes, so the risk is
 * not a typo in one field; it is a structural lie that renders as a form. These
 * tests pin the invariants a generator can silently break: duplicate keys,
 * duplicate storage, a tab that has no entry in the strip, a select whose
 * default is not one of its options, and — the one this port actually hit — a
 * generated field that writes the same stored key as a core field.
 *
 * Counts are floors, not equalities: adding fields to the WordPress surface
 * should not fail this file, only regenerating away half of them should.
 * The exception is the security tab's owner classification (§66): a new security
 * field must be reviewed and listed, because the generator's default is `tenant`
 * and that is how an estate gains a control the deployment should own.
 */
class SettingsCatalogueTest extends TestCase
{
    use RefreshDatabase;

    /**
     * 149 §66 — the security tab's reviewed operator ownership.
     *
     * Deliberately an equality, not a floor. A security field nobody classified
     * would keep the generator's default `tenant`, which is how an estate
     * administrator gains a control the deployment should own. Adding a field to
     * the tab is expected to fail this file until it is reviewed and listed.
     *
     * @var list<string>
     */
    private const SECURITY_OPERATOR = array(
        // Infrastructure, root key, SSRF boundary, resource limits.
        'enable_root_security_key',
        'root_security_key',
        'enable_loopback_ssl_bypass',
        'enable_loopback_private_network_requests',
        'mcp_app_allowed_hosts',
        'max_sse_connections_per_user',
        'max_request_body_size_kb',
        'max_json_depth',
        // Transport, response headers, CORS, error/output hardening.
        'require_https',
        'enable_security_headers',
        'enable_hsts',
        'hsts_max_age',
        'csp_frame_ancestors',
        'cors_allow_origin',
        'cors_allowed_origins',
        'api_error_verbosity',
        'strip_asset_versions',
        // Throttling: the platform's middleware, not an estate preference.
        'enable_rate_limiting',
        'rate_limit_requests',
        'rate_limit_window',
        'rate_limit_by',
        'enable_auth_rate_limiting',
        'auth_rate_limit_threshold',
        'auth_rate_limit_window',
        'enable_oauth_token_rate_limit',
        // Audit/compliance: platform-wide writer and retention.
        'enable_security_audit_log',
        'log_successful_auth',
        'log_file_access',
        'audit_log_retention_days',
    );

    /**
     * Security fields that describe the estate's own policy or product
     * behaviour, and stay writable by its administrator.
     *
     * @var list<string>
     */
    /**
     * 149 §183.9 — tenant-scope security fields that are declared inert.
     *
     * Still owned by the estate (they are that administrator's policy to see) but
     * not writable: nothing here opens a guest surface or verifies a second factor,
     * so a saved value would enforce nothing. They stay in the reviewed-owner map
     * below because "every security field has an owner" must remain true.
     */
    private const SECURITY_INERT = array(
        'allow_guest_access',
        'enable_2fa_requirement',
    );

    private const SECURITY_TENANT = array(
        'require_authentication_all',
        // `allow_guest_access` and `enable_2fa_requirement` were here until §183.9
        // declared them inert: nothing in this runtime opens a guest surface or
        // verifies a second factor, and a writable control that enforces nothing is
        // the false assurance UnimplementedSettings exists to prevent. Both stay
        // tenant scope and both still render — disabled, with a reason.
        'bypass_auth_for_logged_in',
        'require_auth_chat_endpoints',
        'require_auth_tool_execution',
        'mcp_require_assistant_scope',
        'require_auth_assistant_management',
        'require_auth_transcripts',
        'require_auth_file_operations',
        'protect_media_urls',
        'protect_attachment_pages',
        'allow_public_thumbnails',
        'protected_file_extensions',
        'minimum_capability',
        'guest_token_ttl_hours',
        'enable_a2a_jwt_validation',
        'require_capability_on_delegate',
        'enable_ip_whitelist',
        'ip_whitelist',
        'enable_ip_blacklist',
        'ip_blacklist',
        'tool_rate_limit_max',
        'tool_rate_limit_window',
        'tool_rate_limit_exempt_tokens',
        'enable_prompt_injection_detector',
        'prompt_injection_sensitivity',
        'prompt_injection_mode',
        'enable_pii_filter',
        'pii_filter_side',
        'pii_filter_mode',
        'enable_hitl_for_write_tools',
        'hitl_write_tool_threshold',
        'enable_sandbox_mode',
        'enable_ai_cost_tracking',
        'ai_daily_budget_usd',
        'ai_monthly_budget_usd',
        'enable_ai_disclosure',
        'ai_disclosure_message',
        'ai_disclosure_position',
        'enable_consent_modal',
        'consent_message',
        'enable_transparency_headers',
        'enable_generation_logging',
        'generation_log_retention_days',
    );

    public function test_the_catalogue_loads(): void
    {
        $this->assertFileExists(SettingsCatalogue::path());

        $this->assertGreaterThanOrEqual(500, count(SettingsCatalogue::fields()));
        $this->assertGreaterThanOrEqual(10, count(SettingsCatalogue::tabs()));
    }

    public function test_every_field_declares_the_keys_the_renderer_needs(): void
    {
        $required = array(
            'key', 'storage', 'scope', 'console', 'type',
            'section_id', 'section', 'tab', 'label', 'sensitive',
        );

        foreach (self::catalogue() as $field) {
            foreach ($required as $property) {
                $this->assertArrayHasKey(
                    $property,
                    $field,
                    sprintf('Field "%s" is missing "%s".', (string) ($field['key'] ?? '?'), $property)
                );
            }

            $this->assertContains(
                $field['type'],
                array('text', 'textarea', 'number', 'checkbox', 'select', 'password', 'url', 'email'),
                sprintf('Field "%s" has an unsupported type "%s".', $field['key'], $field['type'])
            );

            $this->assertContains($field['scope'], array('tenant', 'operator', 'user'));
            $this->assertContains($field['console'], array('visible', 'readonly', 'hidden'));
        }
    }

    public function test_surface_keys_are_unique(): void
    {
        $keys = array_column(SettingsSchema::fields(), 'key');

        $duplicates = array_keys(array_filter(array_count_values($keys), static fn (int $n): bool => $n > 1));

        $this->assertSame([], $duplicates, 'Two controls would bind the same form state: '.implode(', ', $duplicates));
    }

    public function test_storage_keys_are_unique(): void
    {
        $storages = array_map(
            static fn (array $field): string => (string) ($field['storage'] ?? $field['key']),
            SettingsSchema::fields()
        );

        $duplicates = array_keys(array_filter(array_count_values($storages), static fn (int $n): bool => $n > 1));

        // `google_api_key` stores under `gemini_api_key`; porting the plugin's
        // `gemini_api_key` field as well would render two controls for one value.
        $this->assertSame([], $duplicates, 'Two fields write the same stored key: '.implode(', ', $duplicates));
    }

    public function test_every_field_belongs_to_a_declared_tab(): void
    {
        $tabs = SettingsSchema::tabs();

        foreach (SettingsSchema::fields() as $field) {
            $this->assertArrayHasKey(
                $field['tab'],
                $tabs,
                sprintf('Field "%s" is in tab "%s", which the strip does not declare.', $field['key'], $field['tab'])
            );
        }
    }

    public function test_selects_offer_their_default(): void
    {
        foreach (SettingsSchema::fields() as $field) {
            if ('select' !== $field['type']) {
                continue;
            }

            $this->assertNotEmpty($field['options'] ?? [], sprintf('Select "%s" has no options.', $field['key']));

            $this->assertArrayHasKey(
                (string) ($field['default'] ?? ''),
                $field['options'],
                sprintf(
                    'Select "%s" defaults to "%s", which is not one of its options.',
                    $field['key'],
                    (string) ($field['default'] ?? '')
                )
            );
        }
    }

    public function test_number_bounds_are_coherent(): void
    {
        foreach (SettingsSchema::fields() as $field) {
            if ('number' !== $field['type']) {
                continue;
            }

            $this->assertTrue(
                is_numeric($field['default'] ?? 0),
                sprintf('Number "%s" has a non-numeric default.', $field['key'])
            );

            if (isset($field['min'], $field['max'])) {
                $this->assertLessThanOrEqual(
                    $field['max'],
                    $field['min'],
                    sprintf('Number "%s" has min greater than max.', $field['key'])
                );
            }
        }
    }

    public function test_a_limit_is_not_mistaken_for_a_secret(): void
    {
        $sensitive = SettingsSchema::sensitiveKeys();

        // The generator matches credential suffixes, not the substring "token":
        // `max_tokens` and `token_limit` are limits, and masking them would hide
        // ordinary settings from the administrator.
        $this->assertNotContains('max_tokens', $sensitive);
        $this->assertNotContains('token_limit', $sensitive);

        $this->assertContains('openai_api_key', $sensitive);
        // The surface key, not the storage key: `google_api_key` stores under
        // `gemini_api_key`, which is why the generated set skips the latter.
        $this->assertContains('google_api_key', $sensitive);
    }

    public function test_every_sensitive_field_is_a_password_input_or_a_named_credential(): void
    {
        $suffixes = array(
            '_api_key', '_secret', '_password', '_token',
            '_client_secret', '_access_token', '_refresh_token', '_private_key',
        );
        $exact    = array('api_key', 'password', 'secret');

        foreach (SettingsSchema::fields() as $field) {
            if (! $field['sensitive']) {
                continue;
            }

            $named = in_array($field['key'], $exact, true);

            foreach ($suffixes as $suffix) {
                $named = $named || str_ends_with($field['key'], $suffix);
            }

            $this->assertTrue(
                $named || 'password' === $field['type'],
                sprintf(
                    'Field "%s" is sensitive but is neither a password input nor a named credential.',
                    $field['key']
                )
            );
        }
    }

    public function test_the_ported_fields_reach_the_admin_surface_only(): void
    {
        $admin   = array_column(SettingsSchema::forSurface('admin'), 'key');
        $console = array_column(SettingsSchema::forSurface('console'), 'key');

        // A ported field is a tenant setting on the admin panel...
        $this->assertContains('chat_assistant_bubble_color', $admin);

        // ...and the console projection stays exactly as it shipped.
        $this->assertNotContains('chat_assistant_bubble_color', $console);
        $this->assertLessThan(count($admin), count($console));
    }

    public function test_a_ported_field_writes_through_the_one_writer(): void
    {
        $applied = app(SettingsService::class)->write(
            array(
                'chat_assistant_bubble_color' => '#123456',
                'default_model' => 'gpt-4o-mini',
            ),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertContains('chat_assistant_bubble_color', $applied);
        $this->assertContains('default_model', $applied);
    }

    public function test_a_ported_secret_is_masked_and_never_echoed(): void
    {
        app(SettingsService::class)->write(
            array('mailjet_api_key' => 'mj-live-secret'),
            SettingsService::SURFACE_ADMIN
        );

        $values = app(SettingsService::class)->values(SettingsService::SURFACE_ADMIN);

        $this->assertArrayHasKey('mailjet_api_key', $values);
        $this->assertSame(SettingsSchema::MASKED_PLACEHOLDER, $values['mailjet_api_key']);
        $this->assertNotContains('mj-live-secret', $values);
    }

    public function test_a_masked_secret_is_not_rewritten(): void
    {
        app(SettingsService::class)->write(
            array('mailjet_api_key' => 'mj-live-secret'),
            SettingsService::SURFACE_ADMIN
        );

        // Re-submitting the mask is the "leave it alone" contract.
        $applied = app(SettingsService::class)->write(
            array('mailjet_api_key' => SettingsSchema::MASKED_PLACEHOLDER),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame(array(), $applied);
        $this->assertSame(
            'mj-live-secret',
            app(\Nvoos\Core\Domain\Contract\SettingsStoreInterface::class)->get('mailjet_api_key')
        );
    }

    public function test_an_operator_field_is_still_not_writable_on_the_admin_surface(): void
    {
        $applied = app(SettingsService::class)->write(
            array('ollama_endpoint' => 'http://elsewhere:11434'),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertSame(array(), $applied);
    }

    public function test_infrastructure_endpoints_are_operator_scope(): void
    {
        $ops   = array_column(SettingsSchema::forSurface('ops'), 'key');
        $admin = array_column(SettingsSchema::forSurface('admin'), 'key');

        // Local / self-hosted infrastructure: NV manages the deployment's
        // endpoints and the keys that authenticate to them. `ollama_endpoint`
        // set this precedent before the port.
        foreach (array(
            'ollama_endpoint',
            'ollama_network_interface',
            'lm_studio_endpoint_url',
            'lm_studio_network_interface',
            'lm_studio_api_key',
            'embedded_server_model',
        ) as $key) {
            $this->assertContains($key, $ops, $key.' should be operator scope.');
            $this->assertContains($key, $admin, $key.' must still render on /admin (disabled).');
        }

        // Hosted-provider base URLs are a bring-your-own-gateway choice made with
        // a credential the estate already owns, so they stay tenant scope.
        $this->assertContains('openai_base_url', $admin);
        $this->assertContains('typesafe_base_url', $admin);
        $this->assertNotContains('openai_base_url', $ops);

        $writable = array_column(SettingsSchema::writableFor('admin'), 'key');
        $this->assertNotContains('lm_studio_endpoint_url', $writable);
    }

    public function test_large_provider_sections_split_into_connection_and_behaviour(): void
    {
        $sections = array();

        foreach (SettingsCatalogue::fields() as $field) {
            $sections[ $field['section_id'] ][] = $field['key'];
        }

        $split = array(
            'providers__openai',
            'providers__anthropic',
            'providers__gemini',
            'providers__cloudflare',
            'providers__typesafe',
        );

        foreach ($split as $sid) {
            $token = substr($sid, (int) strrpos($sid, '__') + 2);

            $this->assertArrayHasKey($sid.'__connection', $sections, $sid.' has no connection section.');
            $this->assertArrayHasKey($sid.'__models', $sections, $sid.' has no behaviour section.');

            // The provider's master switch belongs with the credential it gates.
            $this->assertContains('enable_'.$token, $sections[ $sid.'__connection' ]);
        }

        // No provider section is a wall of controls any more: the largest is
        // OpenAI's behaviour block at 22, down from 27 in one card.
        foreach ($sections as $sid => $keys) {
            if (! str_starts_with((string) $sid, 'providers__')) {
                continue;
            }

            $this->assertLessThanOrEqual(22, count($keys), $sid.' is still one undifferentiated block.');
        }
    }

    /**
     * 149 §66 — every security field has a reviewed owner.
     */
    public function test_every_security_field_has_a_reviewed_owner(): void
    {
        $this->assertSame(
            array(),
            array_intersect(self::SECURITY_OPERATOR, self::SECURITY_TENANT),
            'A field cannot be both operator and tenant.'
        );

        $this->assertSame(
            array(),
            array_intersect(self::SECURITY_INERT, self::SECURITY_OPERATOR),
            'An inert field cannot also be an operator field.'
        );

        $this->assertSame(
            array(),
            array_intersect(self::SECURITY_INERT, self::SECURITY_TENANT),
            'An inert field must be listed once: in SECURITY_INERT, not SECURITY_TENANT.'
        );

        $actual = array();

        foreach (SettingsCatalogue::fields() as $field) {
            if ('security' === $field['tab']) {
                $actual[$field['key']] = $field['scope'];
            }
        }

        $expected = array_fill_keys(self::SECURITY_OPERATOR, 'operator')
            + array_fill_keys(self::SECURITY_TENANT, 'tenant')
            + array_fill_keys(self::SECURITY_INERT, 'tenant');

        ksort($actual);
        ksort($expected);

        $this->assertSame(
            $expected,
            $actual,
            'The security tab has an unreviewed field; classify it in plan §66.'
        );
    }

    /**
     * 149 §66 — infrastructure is rendered for transparency but not writable by
     * an estate administrator.
     */
    public function test_security_infrastructure_is_operator_and_not_estate_writable(): void
    {
        $ops      = array_column(SettingsSchema::forSurface('ops'), 'key');
        $admin    = array_column(SettingsSchema::forSurface('admin'), 'key');
        $writable = array_column(SettingsSchema::writableFor('admin'), 'key');

        foreach (self::SECURITY_OPERATOR as $key) {
            $this->assertContains($key, $ops, $key.' should be operator scope.');
            $this->assertContains($key, $admin, $key.' must still render on /admin (disabled).');
            $this->assertNotContains($key, $writable, $key.' must not be writable by an estate.');
        }

        // The two fields that would be most damaging to hand over.
        $this->assertContains('root_security_key', self::SECURITY_OPERATOR);
        $this->assertContains('mcp_app_allowed_hosts', self::SECURITY_OPERATOR);
    }

    /**
     * 149 §66 — estate policy stays tenant scope and writable on /admin.
     */
    public function test_security_estate_policy_is_tenant_writable(): void
    {
        $writable = array_column(SettingsSchema::writableFor('admin'), 'key');
        $ops      = array_column(SettingsSchema::forSurface('ops'), 'key');

        foreach (self::SECURITY_TENANT as $key) {
            $field = SettingsSchema::field($key);

            $this->assertNotNull($field, $key.' is missing from the catalogue.');
            $this->assertSame('tenant', $field['scope'], $key.' should stay tenant scope.');
            $this->assertContains($key, $writable, $key.' should be writable on /admin.');
            $this->assertNotContains($key, $ops, $key.' is not a platform setting.');
        }
    }

    /**
     * 149 §183.9 — a declared-inert security control is not writable.
     *
     * Kept separate from `test_security_estate_policy_is_tenant_writable` so the
     * policy assertions above keep describing the estate policy, and this one
     * describes the exception and why it exists.
     */
    public function test_declared_inert_security_controls_are_not_writable(): void
    {
        $writable = array_column(SettingsSchema::writableFor('admin'), 'key');

        foreach (self::SECURITY_INERT as $key) {
            $this->assertContains(
                $key,
                \App\Support\Settings\UnimplementedSettings::keys(),
                $key.' must stay declared inert; otherwise it is a writable control that enforces nothing.'
            );

            $this->assertNotContains($key, $writable, $key.' must not be writable while it is inert.');
        }
    }

    /**
     * 149 §66 — the root key is refused on the estate surface, and the rest of
     * the payload still applies.
     */
    public function test_a_root_security_key_is_refused_on_the_admin_surface(): void
    {
        $applied = app(SettingsService::class)->write(
            array(
                'root_security_key' => 'deployment-root-key',
                'default_model'     => 'gpt-4o-mini',
            ),
            SettingsService::SURFACE_ADMIN
        );

        $this->assertNotContains('root_security_key', $applied);
        $this->assertContains('default_model', $applied, 'Ignoring the operator key must not reject the rest.');
        $this->assertNull(app(\Nvoos\Core\Domain\Contract\SettingsStoreInterface::class)->get('root_security_key'));
    }

    /**
     * 149 §66 — the root key is writable on /ops and never returned in plaintext.
     */
    public function test_the_root_security_key_is_masked_on_the_ops_surface(): void
    {
        $applied = app(SettingsService::class)->write(
            array('root_security_key' => 'deployment-root-key'),
            SettingsService::SURFACE_OPS
        );

        $this->assertContains('root_security_key', $applied);

        $values = app(SettingsService::class)->values(SettingsService::SURFACE_OPS);

        $this->assertSame(SettingsSchema::MASKED_PLACEHOLDER, $values['root_security_key']);
        $this->assertNotContains('deployment-root-key', $values);
    }

    /**
     * 149 §66 — the two dense sections split by concern.
     */
    public function test_security_sections_split_by_concern(): void
    {
        $sections = array();

        foreach (SettingsCatalogue::fields() as $field) {
            if ('security' === $field['tab']) {
                $sections[$field['section_id']][] = $field['key'];
            }
        }

        $expected = array(
            'security__access'         => 8,
            'security__endpoints'      => 6,
            'security__media'          => 4,
            'security__network'        => 7,
            'security__throttling'     => 8,
            'security__transport'      => 9,
            'security__infrastructure' => 8,
            'security__ai_safety'      => 7,
            'security__ai_governance'  => 5,
            'security__transparency'   => 8,
            'security__audit'          => 4,
        );

        $this->assertSame(
            array(),
            array_diff(array_keys($expected), array_keys($sections)),
            'A reviewed security section disappeared.'
        );
        $this->assertSame(
            array(),
            array_diff(array_keys($sections), array_keys($expected)),
            'A new security section needs a review in plan §66.'
        );

        $operator_only = array(
            'security__throttling',
            'security__transport',
            'security__infrastructure',
            'security__audit',
        );

        foreach ($expected as $sid => $count) {
            $this->assertCount($count, $sections[$sid], $sid.' has the wrong field count.');
            $this->assertLessThanOrEqual(9, count($sections[$sid]), $sid.' is still one undifferentiated block.');

            $scopes = array_values(array_unique(array_map(
                static fn (string $key): string => (string) SettingsSchema::field($key)['scope'],
                $sections[$sid]
            )));

            $this->assertSame(
                in_array($sid, $operator_only, true) ? array('operator') : array('tenant'),
                $scopes,
                $sid.' mixes deployment infrastructure with estate policy.'
            );
        }
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function catalogue(): array
    {
        return SettingsCatalogue::fields();
    }
}
