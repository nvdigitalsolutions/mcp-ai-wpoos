<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `nvoos:doctor` as a post-deploy gate (090-b Phase 5.5).
 *
 * The command is only useful if its exit code is meaningful, so these tests
 * pin the failure paths as well as the healthy one.
 */
class DoctorTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_migrated_install_is_healthy(): void
    {
        $this->artisan('nvoos:doctor')
            ->expectsOutputToContain('Schema version: 1 (expected 1)')
            ->expectsOutputToContain('Pending migrations: none')
            ->assertExitCode(0);
    }

    public function test_a_schema_version_mismatch_fails_the_gate(): void
    {
        config(['oos.schema_version' => 2]);

        $this->artisan('nvoos:doctor')
            ->expectsOutputToContain('FAIL: schema version mismatch')
            ->assertExitCode(1);
    }

    public function test_a_contract_version_mismatch_fails_the_gate(): void
    {
        config(['oos.contract_version' => 2]);

        $this->artisan('nvoos:doctor')
            ->expectsOutputToContain('FAIL: the console only speaks contract v1')
            ->assertExitCode(1);
    }
}
