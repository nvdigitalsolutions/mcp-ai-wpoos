<?php

namespace App\Wifi;

use NvoosWifi\Interfaces\Interface_WiFi_Clock;

/**
 * The Laravel host's clock (Proposal 150, T-0.10).
 *
 * Deliberately `time()`: the WiFi domain works exclusively in UTC epoch seconds
 * and resolves every venue-local boundary against `Venue::timezone()`. Reading
 * `config('app.timezone')` here would reintroduce the timezone-drift failure
 * class the domain was designed to avoid.
 */
final class Clock implements Interface_WiFi_Clock
{
    public function now(): int
    {
        return time();
    }
}
