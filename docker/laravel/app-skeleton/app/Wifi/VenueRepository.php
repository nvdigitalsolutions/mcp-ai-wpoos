<?php

namespace App\Wifi;

use NvoosWifi\Domain\Result;
use NvoosWifi\Domain\Venue;

/**
 * Venue lookup for the Laravel host (Proposal 150, T-0.10).
 *
 * Reads `config/wifi.php`. The WordPress host reads the `nvoos_wifi_venues`
 * option. Both hydrate the same portable `Venue` value object, so the validation
 * rules (non-empty id, IANA timezone, known residency label) cannot drift
 * between runtimes.
 */
final class VenueRepository
{
    /**
     * @return Result ok(Venue) | fail(venue_not_found|invalid_venue|invalid_timezone|invalid_residency)
     */
    public function find(string $venueId): Result
    {
        $venues = (array) config('wifi.venues', []);
        $key = trim($venueId);

        if (! isset($venues[$key]) || ! is_array($venues[$key])) {
            return Result::fail(
                'venue_not_found',
                sprintf('Venue "%s" is not registered in config/wifi.php.', $key),
            );
        }

        $data = $venues[$key];
        $data['id'] = $key;

        return Venue::from_array($data);
    }
}
