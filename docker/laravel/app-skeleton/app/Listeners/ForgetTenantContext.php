<?php

namespace App\Listeners;

use App\Support\TenantContext;

/**
 * Clears the pinned tenant at the end of an Octane operation (Proposal 149 T-0.2).
 *
 * Registered in config/octane.php against RequestTerminated, TaskTerminated and
 * TickTerminated. Octane instantiates the listener and calls handle() with the
 * concrete event, so `object` is the contravariant supertype that lets one class
 * serve all three instead of three near-identical ones.
 *
 * Why the event and not $app->terminating(): Octane's task and tick workers do
 * not run HTTP terminating callbacks but reuse the same process, so they leak a
 * pinned tenant exactly the same way a request does. Queue workers are covered
 * separately in AppServiceProvider, because they fire no Octane events.
 */
final class ForgetTenantContext
{
    public function handle(object $event): void
    {
        TenantContext::forget();
    }
}
