<?php

/**
 * 3D bin packing for shipping boxes, and the USPS cubic rules that qualify one.
 *
 * Proposal 164 section 164.116. Ported from
 * `WP_MCP_AI_Tool_Shipping_Box_Packer`, which is the one tool in the
 * `ecommerce` toolkit that needs no store, no credential and no network — and
 * therefore the first thing the rate estimator can be built on.
 *
 * The algorithm is the original's, because it is the behaviour being preserved:
 *
 * 1. Sort boxes by inner volume, smallest first.
 * 2. Sort items by volume, largest first (best-fit decreasing).
 * 3. For each item, join the first open package it fits, else open a new one in
 *    the smallest box that holds it.
 *
 * ## Five corrections, each of which changes an outcome
 *
 * - **The longest-side ceiling is 22 inches, not 18.** USPS raised it on
 *   **2026-07-12**; the WordPress tool still tests 18, so it rejects packages
 *   that qualify today and reports an empty cubic tier for them.
 * - **An item must fit the box's dimensions to join an open package.** The
 *   original checked only the summed volume, so a 20 x 1 x 1 item could be
 *   placed in an 8 x 8 x 6 box: the volume test passes and the item cannot
 *   physically go in.
 * - **The box's own weight counts towards the weight limit when the box is
 *   chosen.** `find_best_box()` compared the item against `max_weight` and the
 *   open-package test compared item + contents + the empty box, so a single
 *   heavy item could be assigned a box it already overweights.
 * - **A malformed box list is refused, not silently replaced.** The original
 *   fell back to the default boxes when none of the caller's boxes parsed, which
 *   turns a typo into a plausible but wrong packing plan.
 * - **`cubic_eligible` and `cubic_tier` agree.** The original computed
 *   eligibility for every box regardless of its type, so a USPS flat-rate box
 *   reported `cubic_eligible: true` next to a blank tier — an invitation to
 *   quote a cubic rate on a box USPS prices flat.
 *
 * Two things are deliberately *not* corrected, and are documented rather than
 * quietly changed:
 *
 * - **Contents are fitted by summed volume, not by geometry.** Ten 2 x 1 x 1
 *   items "fit" a 20 x 1 x 1 box. Real bin packing needs a placement solver;
 *   this is a fast heuristic and the original's docblock says so.
 * - **`keep_flat` is carried but not enforced.** It round-trips in the item
 *   record so a caller's data is not lost, and the heuristic does not consult
 *   it. Claiming otherwise would be the one thing a port must not do.
 *
 * ## The cubic rules are data, not magic numbers
 *
 * Priority Mail Cubic has three gates: a volume ceiling of 0.5 cu ft, a weight
 * ceiling of 20 lb, and a longest-side ceiling (22 in since 2026-07-12), all
 * measured on the **outside** of the box. Volume is bucketed into five tiers
 * from 0.1 to 0.5 cu ft. Ground Advantage Cubic allows 1.0 cu ft instead, which
 * this class does not model — the caller picks the product, this answers for
 * Priority Mail.
 *
 * A consequence worth knowing about the default boxes: "Cubic Medium"
 * (12 x 10 x 8 in) is 0.556 cu ft and "Cubic Large" (14 x 12 x 10 in) is 0.972,
 * so **neither can ever qualify for cubic pricing**, despite the name. The
 * dimensions are kept because changing them would change every packing plan;
 * {@see describe()} reports `cubic_eligible: false` for both, so the
 * contradiction is visible instead of implied.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Service\Shipping;

/**
 * @phpstan-type PackItem array{name: string, length: float, width: float, height: float, weight_oz: float, keep_flat: bool}
 * @phpstan-type PackBox array{reference: string, package_code: string, package_name: string, box_type: string, outer_length: float, outer_width: float, outer_depth: float, inner_length: float, inner_width: float, inner_depth: float, empty_weight: float, max_weight: float}
 * @phpstan-type PackDimensions array{length: float, width: float, height: float}
 * @phpstan-type OpenPackage array{box: PackBox, items: list<PackItem>, weight_oz: float}
 * @phpstan-type PackPackage array{package_number: int, box_reference: string, box_type: string, package_code: string, package_name: string, dimensions: PackDimensions, weight_oz: float, item_weight_oz: float, box_weight_oz: float, item_count: int, packing_list: list<string>, cubic_eligible: bool, cubic_tier: string, items: list<PackItem>}
 */
final class BoxPacker
{
    /**
     * Priority Mail Cubic: the volume ceiling, in cubic feet.
     */
    public const CUBIC_MAX_CUBIC_FEET = 0.5;

    /**
     * Priority Mail Cubic: the weight ceiling, in ounces (20 lb).
     */
    public const CUBIC_MAX_WEIGHT_OZ = 320.0;

    /**
     * Priority Mail Cubic: the longest-side ceiling, in inches.
     *
     * USPS raised this from 18 to 22 on **2026-07-12**. The WordPress original
     * still tests 18 and therefore under-reports eligibility.
     */
    public const CUBIC_MAX_LONGEST_SIDE = 22.0;

    /**
     * Cubic inches in a cubic foot.
     */
    private const CUBIC_INCHES_PER_CUBIC_FOOT = 1728.0;

    /**
     * Ounces in a pound, for the `max_weight` comparison.
     */
    private const OUNCES_PER_POUND = 16.0;

    /**
     * The five Priority Mail Cubic tiers, ascending, in cubic feet.
     *
     * @var list<float>
     */
    private const CUBIC_TIERS = array(0.1, 0.2, 0.3, 0.4, 0.5);

    /**
     * The box catalogue the WordPress tool shipped, unchanged.
     *
     * Dimensions in inches; `empty_weight` in ounces; `max_weight` in pounds.
     * The three cubic boxes allow 20 lb because that is the cubic ceiling; the
     * flat-rate boxes allow 70 lb, USPS's domestic parcel ceiling.
     *
     * @return list<PackBox>
     */
    public static function defaultBoxes(): array
    {
        return array(
            array(
                'reference' => 'Cubic Small',
                'package_code' => 'package',
                'package_name' => 'Custom Cubic Small',
                'box_type' => 'cubic',
                'outer_length' => 8.0,
                'outer_width' => 8.0,
                'outer_depth' => 6.0,
                'inner_length' => 8.0,
                'inner_width' => 8.0,
                'inner_depth' => 6.0,
                'empty_weight' => 3.0,
                'max_weight' => 20.0,
            ),
            array(
                'reference' => 'Cubic Medium',
                'package_code' => 'package',
                'package_name' => 'Custom Cubic Medium',
                'box_type' => 'cubic',
                'outer_length' => 12.0,
                'outer_width' => 10.0,
                'outer_depth' => 8.0,
                'inner_length' => 12.0,
                'inner_width' => 10.0,
                'inner_depth' => 8.0,
                'empty_weight' => 5.0,
                'max_weight' => 20.0,
            ),
            array(
                'reference' => 'Cubic Large',
                'package_code' => 'package',
                'package_name' => 'Custom Cubic Large',
                'box_type' => 'cubic',
                'outer_length' => 14.0,
                'outer_width' => 12.0,
                'outer_depth' => 10.0,
                'inner_length' => 14.0,
                'inner_width' => 12.0,
                'inner_depth' => 10.0,
                'empty_weight' => 7.0,
                'max_weight' => 20.0,
            ),
            array(
                'reference' => 'USPS Small Flat Rate',
                'package_code' => 'small_flat_rate_box',
                'package_name' => 'USPS Small Flat Rate Box',
                'box_type' => 'flat_rate',
                'outer_length' => 9.0,
                'outer_width' => 6.0,
                'outer_depth' => 2.0,
                'inner_length' => 9.0,
                'inner_width' => 6.0,
                'inner_depth' => 2.0,
                'empty_weight' => 4.0,
                'max_weight' => 70.0,
            ),
            array(
                'reference' => 'USPS Medium Flat Rate',
                'package_code' => 'medium_flat_rate_box',
                'package_name' => 'USPS Medium Flat Rate Box',
                'box_type' => 'flat_rate',
                'outer_length' => 14.0,
                'outer_width' => 12.0,
                'outer_depth' => 3.0,
                'inner_length' => 14.0,
                'inner_width' => 12.0,
                'inner_depth' => 3.0,
                'empty_weight' => 6.0,
                'max_weight' => 70.0,
            ),
            array(
                'reference' => 'USPS Large Flat Rate',
                'package_code' => 'large_flat_rate_box',
                'package_name' => 'USPS Large Flat Rate Box',
                'box_type' => 'flat_rate',
                'outer_length' => 12.0,
                'outer_width' => 12.0,
                'outer_depth' => 6.0,
                'inner_length' => 12.0,
                'inner_width' => 12.0,
                'inner_depth' => 6.0,
                'empty_weight' => 8.0,
                'max_weight' => 70.0,
            ),
        );
    }

    /**
     * Expand raw item input into one record per physical unit.
     *
     * A row is dropped when any dimension or the weight is missing or not
     * positive: a box cannot be chosen for an item with no size, and inventing
     * one would silently produce a plausible plan for an impossible item. A
     * blank name is replaced with its 1-based row number rather than dropped, so
     * the packing list still accounts for the unit.
     *
     * @param array<mixed> $raw The caller's `items` argument.
     *
     * @return list<PackItem>
     */
    public static function normaliseItems(array $raw): array
    {
        $items = array();

        foreach (array_values($raw) as $index => $row) {
            if (! is_array($row)) {
                continue;
            }

            $length = self::number($row, 'length');
            $width = self::number($row, 'width');
            $height = self::number($row, 'height');
            $weight = self::number($row, 'weight_oz');

            if ($length <= 0.0 || $width <= 0.0 || $height <= 0.0 || $weight <= 0.0) {
                continue;
            }

            $name = isset($row['name']) && is_scalar($row['name']) ? trim((string) $row['name']) : '';

            if ('' === $name) {
                $name = sprintf('Item %d', $index + 1);
            }

            $quantity = isset($row['quantity']) && is_numeric($row['quantity'])
                ? max(1, (int) $row['quantity'])
                : 1;

            $item = array(
                'name' => $name,
                'length' => $length,
                'width' => $width,
                'height' => $height,
                'weight_oz' => $weight,
                'keep_flat' => isset($row['keep_flat']) && (bool) $row['keep_flat'],
            );

            for ($copy = 0; $copy < $quantity; ++$copy) {
                $items[] = $item;
            }
        }

        return $items;
    }

    /**
     * Sanitise caller-supplied box definitions.
     *
     * Returns an empty list when the caller supplied boxes and none of them is
     * usable — the caller decides whether that is a refusal. Absent boxes are
     * not this method's business: {@see defaultBoxes()} is the answer there.
     *
     * `inner_*` defaults to the outer dimension when omitted, which is the
     * original's rule and the only safe assumption: claiming more usable space
     * than the box has would over-fill it by arithmetic.
     *
     * @param array<mixed> $raw The caller's `boxes` argument.
     *
     * @return list<PackBox>
     */
    public static function normaliseBoxes(array $raw): array
    {
        $boxes = array();

        foreach (array_values($raw) as $row) {
            if (! is_array($row)) {
                continue;
            }

            $outerLength = self::number($row, 'outer_length');
            $outerWidth = self::number($row, 'outer_width');
            $outerDepth = self::number($row, 'outer_depth');

            if ($outerLength <= 0.0 || $outerWidth <= 0.0 || $outerDepth <= 0.0) {
                continue;
            }

            $reference = isset($row['reference']) && is_scalar($row['reference'])
                ? trim((string) $row['reference'])
                : 'Custom Box';

            if ('' === $reference) {
                $reference = 'Custom Box';
            }

            $boxes[] = array(
                'reference' => $reference,
                'package_code' => self::text($row, 'package_code', 'package'),
                'package_name' => self::text($row, 'package_name', $reference),
                'box_type' => 'flat_rate' === self::text($row, 'box_type', 'cubic') ? 'flat_rate' : 'cubic',
                'outer_length' => $outerLength,
                'outer_width' => $outerWidth,
                'outer_depth' => $outerDepth,
                'inner_length' => self::positiveOr(self::number($row, 'inner_length'), $outerLength),
                'inner_width' => self::positiveOr(self::number($row, 'inner_width'), $outerWidth),
                'inner_depth' => self::positiveOr(self::number($row, 'inner_depth'), $outerDepth),
                'empty_weight' => max(0.0, self::number($row, 'empty_weight')),
                'max_weight' => self::positiveOr(self::number($row, 'max_weight'), 70.0),
            );
        }

        return $boxes;
    }

    /**
     * Pack items into the fewest, smallest boxes this heuristic can find.
     *
     * @param list<PackItem> $items One record per physical unit.
     * @param list<PackBox>  $boxes Available boxes; order does not matter.
     *
     * @return list<PackPackage>
     */
    public static function pack(array $items, array $boxes): array
    {
        if (array() === $items || array() === $boxes) {
            return array();
        }

        $catalogue = self::sortedBoxes($boxes);
        $queue = self::sortedItems($items);

        /**
         * Packages still accepting items.
         *
         * @var list<OpenPackage> $open
         */
        $open = array();

        foreach ($queue as $item) {
            $placed = false;

            foreach ($open as $index => $package) {
                if (! self::itemFitsPackage($item, $package)) {
                    continue;
                }

                $open[$index]['items'][] = $item;
                $open[$index]['weight_oz'] += $item['weight_oz'];
                $placed = true;

                break;
            }

            if ($placed) {
                continue;
            }

            $open[] = array(
                'box' => self::bestBox($item, $catalogue),
                'items' => array($item),
                'weight_oz' => $item['weight_oz'],
            );
        }

        $packages = array();

        foreach ($open as $index => $package) {
            $box = $package['box'];
            $totalWeight = $package['weight_oz'] + $box['empty_weight'];

            // Cubic rates are quoted on the outside of the box.
            $dimensions = array(
                'length' => $box['outer_length'],
                'width' => $box['outer_width'],
                'height' => $box['outer_depth'],
            );

            $packages[] = array(
                'package_number' => $index + 1,
                'box_reference' => $box['reference'],
                'box_type' => $box['box_type'],
                'package_code' => $box['package_code'],
                'package_name' => $box['package_name'],
                'dimensions' => $dimensions,
                'weight_oz' => round($totalWeight, 2),
                'item_weight_oz' => round($package['weight_oz'], 2),
                'box_weight_oz' => $box['empty_weight'],
                'item_count' => count($package['items']),
                'packing_list' => self::packingList($package['items']),
                'cubic_eligible' => 'cubic' === $box['box_type']
                    && self::isCubicEligible($dimensions, $totalWeight),
                'cubic_tier' => 'cubic' === $box['box_type'] ? self::cubicTier($dimensions) : '',
                'items' => $package['items'],
            );
        }

        return $packages;
    }

    /**
     * A box catalogue with each box's volume and cubic standing resolved.
     *
     * `cubic_eligible` here asks whether the box can be quoted at a cubic rate
     * at all: it must be a `cubic` box *and* its dimensions must pass the three
     * gates, at any weight. A packed package's eligibility additionally depends
     * on its weight; see {@see pack()}.
     *
     * The `cubic` gate matters because a flat-rate box is priced flat no matter
     * how small it is. Reporting `cubic_eligible: true` for one alongside a
     * blank tier invited exactly the wrong conclusion.
     *
     * @param list<PackBox> $boxes Box definitions.
     *
     * @return list<array<string, mixed>>
     */
    public static function describe(array $boxes): array
    {
        $described = array();

        foreach ($boxes as $box) {
            $inner = array(
                'length' => $box['inner_length'],
                'width' => $box['inner_width'],
                'height' => $box['inner_depth'],
            );

            $outer = array(
                'length' => $box['outer_length'],
                'width' => $box['outer_width'],
                'height' => $box['outer_depth'],
            );

            $cubicInches = self::volume($inner);

            $described[] = array_merge(
                $box,
                array(
                    'volume_cubic_inches' => round($cubicInches, 2),
                    'volume_cubic_feet' => round($cubicInches / self::CUBIC_INCHES_PER_CUBIC_FOOT, 4),
                    'cubic_eligible' => 'cubic' === $box['box_type']
                        && self::isCubicEligible($outer, 0.0),
                    'cubic_tier' => 'cubic' === $box['box_type'] ? self::cubicTier($outer) : '',
                )
            );
        }

        return $described;
    }

    /**
     * Whether a package of these outer dimensions and weight qualifies for
     * Priority Mail Cubic.
     *
     * All three gates: volume <= 0.5 cu ft, weight <= 20 lb, longest side <=
     * 22 in. A dimension of zero is not eligible — a degenerate box is not a
     * box.
     *
     * @param PackDimensions $dimensions Outer dimensions in inches.
     * @param float          $weightOz   Total package weight in ounces.
     */
    public static function isCubicEligible(array $dimensions, float $weightOz): bool
    {
        $sides = array_values($dimensions);

        foreach ($sides as $side) {
            if ($side <= 0.0) {
                return false;
            }
        }

        rsort($sides);

        return self::volume($dimensions) / self::CUBIC_INCHES_PER_CUBIC_FOOT <= self::CUBIC_MAX_CUBIC_FEET
            && $weightOz <= self::CUBIC_MAX_WEIGHT_OZ
            && $sides[0] <= self::CUBIC_MAX_LONGEST_SIDE;
    }

    /**
     * The cubic tier a package of these dimensions falls in, e.g. `'0.3'`.
     *
     * Empty when the volume exceeds every tier; the caller decides whether the
     * package is still eligible by weight and side length.
     *
     * @param PackDimensions $dimensions Outer dimensions in inches.
     */
    public static function cubicTier(array $dimensions): string
    {
        $cubicFeet = self::volume($dimensions) / self::CUBIC_INCHES_PER_CUBIC_FOOT;

        foreach (self::CUBIC_TIERS as $tier) {
            if ($cubicFeet <= $tier) {
                return number_format($tier, 1, '.', '');
            }
        }

        return '';
    }

    /**
     * Whether one more item can join a package already being filled.
     *
     * @param PackItem    $item    Candidate.
     * @param OpenPackage $package Open package.
     */
    private static function itemFitsPackage(array $item, array $package): bool
    {
        $box = $package['box'];

        // The box's own weight counts: a package is overweight on the scale,
        // not on the contents.
        $newTotalWeight = $package['weight_oz'] + $item['weight_oz'] + $box['empty_weight'];

        if ($newTotalWeight > $box['max_weight'] * self::OUNCES_PER_POUND) {
            return false;
        }

        // Volume alone would let a long thin item into a small box.
        if (! self::itemFitsBox($item, $box)) {
            return false;
        }

        $usedVolume = self::volume(
            array(
                'length' => $item['length'],
                'width' => $item['width'],
                'height' => $item['height'],
            )
        );

        foreach ($package['items'] as $packed) {
            $usedVolume += self::volume(
                array(
                    'length' => $packed['length'],
                    'width' => $packed['width'],
                    'height' => $packed['height'],
                )
            );
        }

        return $usedVolume <= self::volume(
            array(
                'length' => $box['inner_length'],
                'width' => $box['inner_width'],
                'height' => $box['inner_depth'],
            )
        );
    }

    /**
     * Whether an item's dimensions and weight allow a box at all.
     *
     * Orientation-independent: both sets are sorted, so a 10 x 2 x 2 item fits
     * a 2 x 2 x 10 box. The box's empty weight is included in the limit, which
     * is the correction described in the file docblock.
     *
     * @param PackItem $item Candidate.
     * @param PackBox  $box  Box definition.
     */
    private static function itemFitsBox(array $item, array $box): bool
    {
        $itemSides = array($item['length'], $item['width'], $item['height']);
        $boxSides = array($box['inner_length'], $box['inner_width'], $box['inner_depth']);

        rsort($itemSides);
        rsort($boxSides);

        return $itemSides[0] <= $boxSides[0]
            && $itemSides[1] <= $boxSides[1]
            && $itemSides[2] <= $boxSides[2]
            && $item['weight_oz'] + $box['empty_weight'] <= $box['max_weight'] * self::OUNCES_PER_POUND;
    }

    /**
     * The smallest box that holds one item, or a box built for it.
     *
     * @param PackItem      $item  Item needing a box.
     * @param list<PackBox> $boxes Catalogue sorted by inner volume, ascending.
     *
     * @return PackBox
     */
    private static function bestBox(array $item, array $boxes): array
    {
        foreach ($boxes as $box) {
            if (self::itemFitsBox($item, $box)) {
                return $box;
            }
        }

        return self::fallbackBox($item);
    }

    /**
     * A minimal box for an item no catalogue box holds.
     *
     * Rounding up to the whole inch and taking the outer dimension from the
     * item means the result always holds the item; the box weighs nothing
     * because there is no real packaging to weigh.
     *
     * @param PackItem $item The item no box fitted.
     *
     * @return PackBox
     */
    private static function fallbackBox(array $item): array
    {
        $length = max(1.0, ceil($item['length']));
        $width = max(1.0, ceil($item['width']));
        $height = max(1.0, ceil($item['height']));

        return array(
            'reference' => 'Fallback Package',
            'package_code' => 'package',
            'package_name' => 'Fallback Package',
            'box_type' => 'cubic',
            'outer_length' => $length,
            'outer_width' => $width,
            'outer_depth' => $height,
            'inner_length' => $length,
            'inner_width' => $width,
            'inner_depth' => $height,
            'empty_weight' => 0.0,
            'max_weight' => 70.0,
        );
    }

    /**
     * Human-readable lines, e.g. `2x Widget`.
     *
     * Grouped by name and ordered by first appearance, so the list reads the way
     * the plan was built.
     *
     * @param list<PackItem> $items Packed items.
     *
     * @return list<string>
     */
    private static function packingList(array $items): array
    {
        /** @var array<string, int> $counts */
        $counts = array();

        foreach ($items as $item) {
            if (! isset($counts[$item['name']])) {
                $counts[$item['name']] = 0;
            }

            ++$counts[$item['name']];
        }

        $lines = array();

        foreach ($counts as $name => $quantity) {
            $lines[] = $quantity . 'x ' . $name;
        }

        return $lines;
    }

    /**
     * Boxes ordered by usable volume, smallest first.
     *
     * @param list<PackBox> $boxes Boxes in any order.
     *
     * @return list<PackBox>
     */
    private static function sortedBoxes(array $boxes): array
    {
        usort(
            $boxes,
            static function (array $left, array $right): int {
                return self::innerVolume($left) <=> self::innerVolume($right);
            }
        );

        return $boxes;
    }

    /**
     * Items ordered by volume, largest first.
     *
     * @param list<PackItem> $items Items in any order.
     *
     * @return list<PackItem>
     */
    private static function sortedItems(array $items): array
    {
        usort(
            $items,
            static function (array $left, array $right): int {
                return self::itemVolume($right) <=> self::itemVolume($left);
            }
        );

        return $items;
    }

    /**
     * @param PackBox $box Box definition.
     */
    private static function innerVolume(array $box): float
    {
        return $box['inner_length'] * $box['inner_width'] * $box['inner_depth'];
    }

    /**
     * @param PackItem $item Item record.
     */
    private static function itemVolume(array $item): float
    {
        return $item['length'] * $item['width'] * $item['height'];
    }

    /**
     * @param PackDimensions $dimensions Dimensions in inches.
     */
    private static function volume(array $dimensions): float
    {
        return $dimensions['length'] * $dimensions['width'] * $dimensions['height'];
    }

    /**
     * A numeric field, or 0.0 when absent or not numeric.
     *
     * @param array<mixed> $row Raw row.
     * @param string       $key Field name.
     */
    private static function number(array $row, string $key): float
    {
        if (! isset($row[$key]) || ! is_numeric($row[$key])) {
            return 0.0;
        }

        return (float) $row[$key];
    }

    /**
     * A textual field with a default.
     *
     * @param array<mixed> $row     Raw row.
     * @param string       $key     Field name.
     * @param string       $default Fallback when absent or blank.
     */
    private static function text(array $row, string $key, string $default): string
    {
        $value = isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';

        return '' !== $value ? $value : $default;
    }

    /**
     * A positive value, or the fallback when the value is zero or negative.
     *
     * An inner dimension cannot be larger than the outer one it was copied
     * from, and a zero weight limit would make every box reject every item.
     */
    private static function positiveOr(float $value, float $fallback): float
    {
        return $value > 0.0 ? $value : $fallback;
    }
}
