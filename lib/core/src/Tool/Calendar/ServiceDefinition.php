<?php

/**
 * Shared rules for bookable services.
 *
 * Proposal 164 section 164.86. `create_service` and `import_services` write the
 * same record — an `mcp_service` content item with the WordPress `_service_*`
 * meta family — and both read it back through {@see row()} so a create and a
 * bulk import cannot disagree about what a service looks like.
 *
 * ## Bounds
 *
 * Duration and buffer reuse the appointment bounds, because a service is
 * bookable only if an appointment can represent it: 15–480 minutes and a buffer
 * of 0–240. WordPress accepted `absint` with no ceiling, so a 10,000-minute
 * service was storable and unbookable. A service outside the bounds is refused
 * rather than clamped: the value is durable and reused, so silently changing it
 * would hide the caller's mistake.
 *
 * Price is stored as a float with two decimals, as WordPress did. The runtime
 * keeps no currency, so a price is a bare number in the host's currency.
 *
 * ## Identity
 *
 * A service stores two identity keys:
 *
 * - `_service_external_id`, when the caller supplies one, is the stable source
 *   identity an import matches on first.
 * - `_service_key` is `slug(name)` plus the place id when one is set. It is the
 *   fallback identity, and it is why an import can be re-run without creating
 *   duplicates even when the source has no id column.
 *
 * ## What is not ported
 *
 * WordPress sideloaded `image_urls` with `media_sideload_image()`, which fetches
 * an arbitrary URL server-side. This runtime has no SSRF-guarded fetch contract
 * for media, so `image_urls` is refused with a clear error and `media_ids`
 * (files already in the registry) replaces it. The places toolkit is not ported
 * either, so `place_id` is stored as an opaque reference and is not verified to
 * exist.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Calendar;

use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Support\Text;

final class ServiceDefinition
{
    /** The content type WordPress stored services under. */
    public const TYPE = 'mcp_service';

    /** The taxonomy WordPress stored service categories under. */
    public const TAXONOMY = 'mcp_service_category';

    /** The service meta family. */
    public const META_DURATION = '_service_duration';

    public const META_PRICE = '_service_price';

    public const META_BUFFER = '_service_buffer_time';

    public const META_PLACE = '_service_place_id';

    public const META_PARTICIPANTS = '_service_max_participants';

    public const META_SOURCE_URL = '_service_source_url';

    public const META_GALLERY = '_service_gallery';

    public const META_TAGS = '_service_tags';

    public const META_KEY = '_service_key';

    public const META_EXTERNAL_ID = '_service_external_id';

    /** Duration bounds, shared with the booking tools. */
    public const MIN_DURATION = AppointmentDefinition::MIN_DURATION;

    public const MAX_DURATION = AppointmentDefinition::MAX_DURATION;

    public const MAX_BUFFER = 240;

    public const MIN_PARTICIPANTS = 1;

    public const MAX_PARTICIPANTS = 1000;

    /** The ceiling on a single import call; a visible `next_offset` follows. */
    public const MAX_ROWS = 5000;

    /** The dedupe prefetch window. */
    public const DEDUPE_SCAN = 1000;

    /**
     * A service duration in minutes, or null when it is out of bounds.
     */
    public static function duration(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            return null;
        }

        $minutes = (int) round($number);

        return $minutes >= self::MIN_DURATION && $minutes <= self::MAX_DURATION ? $minutes : null;
    }

    /**
     * A price rounded to two decimals, or null when it is not a valid price.
     */
    public static function price(mixed $value): ?float
    {
        if (! is_numeric($value)) {
            return null;
        }

        $price = (float) $value;

        if (! is_finite($price) || $price < 0) {
            return null;
        }

        return round($price, 2);
    }

    /**
     * A buffer in minutes, or null when it is out of bounds.
     */
    public static function buffer(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            return null;
        }

        $minutes = (int) round($number);

        return $minutes >= 0 && $minutes <= self::MAX_BUFFER ? $minutes : null;
    }

    /**
     * A participant ceiling, or null when it is out of bounds.
     */
    public static function participants(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $number = (float) $value;

        if (! is_finite($number)) {
            return null;
        }

        $count = (int) round($number);

        return $count >= self::MIN_PARTICIPANTS && $count <= self::MAX_PARTICIPANTS ? $count : null;
    }

    /**
     * A place reference, or null when it is negative or not numeric.
     */
    public static function place(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $place = (int) $value;

        return $place >= 0 ? $place : null;
    }

    /**
     * A source URL, '' when empty, or null when it is not an http(s) URL.
     */
    public static function url(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $url = trim($value);

        if ('' === $url) {
            return '';
        }

        if (false === filter_var($url, FILTER_VALIDATE_URL)) {
            return null;
        }

        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));

        return in_array($scheme, array('http', 'https'), true) ? $url : null;
    }

    /**
     * A single-line external identity, capped at 190 characters.
     */
    public static function externalId(mixed $value): string
    {
        if (! is_scalar($value)) {
            return '';
        }

        return substr(Text::singleLine((string) $value), 0, 190);
    }

    /**
     * Tags from a list or a delimited string.
     *
     * @return list<string>
     */
    public static function tags(mixed $value): array
    {
        return self::stringList($value, 50);
    }

    /**
     * Media IDs from a list or a delimited string.
     *
     * @return list<int>
     */
    public static function mediaIds(mixed $value): array
    {
        $items = self::items($value);
        $ids   = array();

        foreach ($items as $item) {
            $id = is_numeric($item) ? (int) $item : 0;

            if ($id > 0 && ! in_array($id, $ids, true)) {
                $ids[] = $id;
            }

            if (count($ids) >= 20) {
                break;
            }
        }

        return $ids;
    }

    /**
     * The stable fallback identity: slug(name), plus the place when one is set.
     */
    public static function key(string $name, int $placeId): string
    {
        $slug = Text::slug($name);

        if ('' === $slug) {
            $slug = 's-' . substr(sha1(trim($name)), 0, 12);
        }

        return $placeId > 0 ? $slug . ':' . $placeId : $slug;
    }

    /**
     * The API projection of a service.
     *
     * @return array<string, mixed>
     */
    public static function row(ContentItem $item): array
    {
        $meta      = $item->meta;
        $duration  = $meta[ self::META_DURATION ] ?? null;
        $price     = $meta[ self::META_PRICE ] ?? null;
        $buffer    = $meta[ self::META_BUFFER ] ?? null;
        $place     = (int) ( $meta[ self::META_PLACE ] ?? 0 );
        $max       = $meta[ self::META_PARTICIPANTS ] ?? null;
        $gallery   = $meta[ self::META_GALLERY ] ?? array();
        $tags      = $meta[ self::META_TAGS ] ?? array();
        $category  = $item->getTerms(self::TAXONOMY);

        return array(
            'id'                  => $item->id,
            'name'                => $item->title,
            'description'         => $item->content,
            'duration_minutes'    => is_numeric($duration) ? (int) $duration : null,
            'price'               => is_numeric($price) ? (float) $price : null,
            'buffer_time_minutes' => is_numeric($buffer) ? (int) $buffer : 0,
            'category'            => isset($category[0]) ? (string) $category[0] : '',
            'place_id'            => $place > 0 ? $place : null,
            'max_participants'    => is_numeric($max) ? (int) $max : null,
            'source_url'          => self::string($meta[ self::META_SOURCE_URL ] ?? ''),
            'media_ids'           => self::intList($gallery),
            'tags'                => self::stringList($tags, 50),
            'key'                 => self::string($meta[ self::META_KEY ] ?? ''),
            'external_id'         => self::string($meta[ self::META_EXTERNAL_ID ] ?? ''),
            'created_at'          => $item->createdAt->format('c'),
            'updated_at'          => $item->updatedAt->format('c'),
        );
    }

    /**
     * A scalar meta value as a string; arrays become ''.
     */
    public static function string(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    /**
     * A stored list of integers.
     *
     * @return list<int>
     */
    private static function intList(mixed $value): array
    {
        if (! is_array($value)) {
            return array();
        }

        $ints = array();

        foreach ($value as $item) {
            if (is_numeric($item)) {
                $ints[] = (int) $item;
            }
        }

        return $ints;
    }

    /**
     * A stored list of strings, accepting a delimited string.
     *
     * @return list<string>
     */
    private static function stringList(mixed $value, int $max): array
    {
        $items = self::items($value);
        $list  = array();

        foreach ($items as $item) {
            if (! is_scalar($item)) {
                continue;
            }

            $item = Text::singleLine((string) $item);

            if ('' !== $item && ! in_array($item, $list, true)) {
                $list[] = $item;
            }

            if (count($list) >= $max) {
                break;
            }
        }

        return $list;
    }

    /**
     * Normalise a list-or-delimited-string into scalar items.
     *
     * @return list<mixed>
     */
    private static function items(mixed $value): array
    {
        if (is_array($value)) {
            return array_values($value);
        }

        if (is_scalar($value)) {
            $parts = preg_split('/[|,;]+/', (string) $value);

            return false === $parts ? array() : $parts;
        }

        return array();
    }
}
