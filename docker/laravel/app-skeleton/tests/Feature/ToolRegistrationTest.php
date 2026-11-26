<?php

namespace Tests\Feature;

use Tests\TestCase;

/**
 * This host builds every tool the manifest advertises — Proposal 149 §165.
 *
 * `ToolReadinessRegistrationTest` proves the same thing for the *package* host,
 * using only the package's own bindings. This asks it of the app, where
 * `AppServiceProvider` replaces several of them. `ManifestToolRegistrar` skips
 * a servable row it cannot construct and the provider records the list instead
 * of failing boot, so without this assertion the only symptom would be a tool
 * that quietly is not there.
 */
final class ToolRegistrationTest extends TestCase
{
    public function test_the_app_builds_every_servable_manifest_row(): void
    {
        $skipped = $this->app->bound('nvoos.tools.skipped')
            ? $this->app->make('nvoos.tools.skipped')
            : [];

        $this->assertSame(
            [],
            $skipped,
            'Manifest rows marked servable that this host could not construct: '.json_encode($skipped)
        );
    }
}
