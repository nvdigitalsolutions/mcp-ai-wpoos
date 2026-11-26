<?php

namespace App\Wifi;

use NvoosWifi\Interfaces\Interface_WiFi_Id_Generator;

/**
 * The Laravel host's opaque identifier generator (Proposal 150, T-0.10).
 *
 * Same contract as the WordPress implementation: random, prefixed, never derived
 * from a MAC address (802.11bh-2024) and never sequential in a way that leaks
 * venue volume.
 */
final class IdGenerator implements Interface_WiFi_Id_Generator
{
    public function new_device_id(): string
    {
        return 'dev_'.bin2hex(random_bytes(16));
    }

    public function new_session_id(): string
    {
        return 'ses_'.bin2hex(random_bytes(16));
    }
}
