<?php

namespace Tests\Support;

use NvoosWifi\Interfaces\Interface_WiFi_Clock;

/**
 * A clock frozen at an instant (Proposal 150, T-0.11).
 *
 * The dual-runtime parity fixture pins `generated_at` to 1763424100, so both
 * hosts must run the service against the same instant. Rebinding the container's
 * `Interface_WiFi_Clock` before the request is what the lazy service binding in
 * `WifiServiceProvider` exists to allow.
 */
final class FrozenClock implements Interface_WiFi_Clock
{
    public function __construct(private readonly int $at)
    {
    }

    public function now(): int
    {
        return $this->at;
    }
}
