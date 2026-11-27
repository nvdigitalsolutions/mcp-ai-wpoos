<?php

/**
 * The box packer and its MCP surface.
 *
 * The interesting tests here are the ones the WordPress original would fail.
 * Each of the four corrections in `BoxPacker`'s file docblock has a case that
 * only passes because the correction is there, so a future refactor that
 * reintroduces the old behaviour cannot do so quietly:
 *
 * 1. The 22-inch longest-side limit (USPS raised it on 2026-07-12).
 * 2. A long thin item cannot join a box it does not dimensionally fit.
 * 3. A box is not chosen for an item that already overweights it once the
 *    packaging is counted.
 * 4. A malformed box list is refused rather than replaced by the defaults.
 *
 * Fixtures use whole inches and round weights so every volume and tier below
 * can be checked by hand: 8 x 8 x 6 in is 384 in^3 = 0.222 cu ft, which is the
 * 0.3 tier.
 *
 * @package Nvoos\Core\Tests
 * @since   3.0.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Error\AccessDeniedException;
use Nvoos\Core\Domain\Service\Shipping\BoxPacker;
use Nvoos\Core\Tool\Ecommerce\ShippingBoxPackerTool;
use PHPUnit\Framework\TestCase;

final class EcommerceShippingBoxPackerTest extends TestCase
{
    private const CONTEXT = array('user_id' => 7);

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message, 'data' => $fields),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = 'Access denied.', array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => 'forbidden', 'message' => $message, 'data' => $data),
            )
        );
    }

    private function tool(): ShippingBoxPackerTool
    {
        return new ShippingBoxPackerTool($this->errors);
    }

    /**
     * An item row in the shape `normaliseItems()` accepts.
     *
     * @return array<string, mixed>
     */
    private function item(
        string $name,
        float $length,
        float $width,
        float $height,
        float $weightOz,
        int $quantity = 1
    ): array {
        return array(
            'name' => $name,
            'length' => $length,
            'width' => $width,
            'height' => $height,
            'weight_oz' => $weightOz,
            'quantity' => $quantity,
        );
    }

    /**
     * The `data` payload of a successful envelope.
     *
     * @return array<string, mixed>
     */
    private function data(mixed $result): array
    {
        $this->assertIsArray($result);
        $this->assertTrue($result['success'] ?? false, 'Expected a success envelope.');
        $this->assertArrayHasKey('data', $result);
        $this->assertIsArray($result['data']);

        return $result['data'];
    }

    /**
     * The packages of a successful `pack_items` envelope.
     *
     * @return list<array<string, mixed>>
     */
    private function packages(mixed $result): array
    {
        $data = $this->data($result);

        $this->assertArrayHasKey('packages', $data);
        $this->assertIsArray($data['packages']);

        /** @var list<array<string, mixed>> $packages */
        $packages = array_values($data['packages']);

        return $packages;
    }

    /**
     * The error code of a refusal.
     */
    private function errorCode(mixed $result): string
    {
        $this->assertIsArray($result);
        $this->assertFalse($result['success'] ?? true, 'Expected a refusal envelope.');
        $this->assertArrayHasKey('error', $result);
        $this->assertIsArray($result['error']);
        $this->assertArrayHasKey('code', $result['error']);

        return (string) $result['error']['code'];
    }

    // ─── Cubic rules ─────────────────────────────────────────────────

    public function test_cubic_eligibility_accepts_a_side_between_18_and_22_inches(): void
    {
        // 20 x 6 x 4 in = 480 in^3 = 0.278 cu ft, longest side 20 in.
        // The WordPress rule (18 in) rejects this; USPS has allowed it since
        // 2026-07-12.
        $this->assertTrue(
            BoxPacker::isCubicEligible(
                array('length' => 20.0, 'width' => 6.0, 'height' => 4.0),
                32.0
            )
        );
    }

    public function test_cubic_eligibility_rejects_each_gate(): void
    {
        $side = array('length' => 23.0, 'width' => 4.0, 'height' => 4.0);
        $this->assertFalse(BoxPacker::isCubicEligible($side, 32.0), 'Longest side over 22 in.');

        $volume = array('length' => 15.0, 'width' => 15.0, 'height' => 15.0);
        $this->assertFalse(BoxPacker::isCubicEligible($volume, 32.0), 'Volume over 0.5 cu ft.');

        $weight = array('length' => 12.0, 'width' => 6.0, 'height' => 6.0);
        $this->assertTrue(BoxPacker::isCubicEligible($weight, 320.0), 'Exactly 20 lb is allowed.');
        $this->assertFalse(BoxPacker::isCubicEligible($weight, 321.0), 'Over 20 lb.');

        $degenerate = array('length' => 0.0, 'width' => 6.0, 'height' => 6.0);
        $this->assertFalse(BoxPacker::isCubicEligible($degenerate, 32.0), 'A zero side is not a box.');
    }

    public function test_cubic_tiers_are_bucketed_by_volume(): void
    {
        $this->assertSame(
            '0.1',
            BoxPacker::cubicTier(array('length' => 6.0, 'width' => 5.0, 'height' => 5.0)),
            '150 in^3 = 0.087 cu ft.'
        );

        $this->assertSame(
            '0.3',
            BoxPacker::cubicTier(array('length' => 8.0, 'width' => 8.0, 'height' => 6.0)),
            '384 in^3 = 0.222 cu ft.'
        );

        $this->assertSame(
            '',
            BoxPacker::cubicTier(array('length' => 12.0, 'width' => 10.0, 'height' => 8.0)),
            '960 in^3 = 0.556 cu ft exceeds every tier.'
        );
    }

    // ─── Packing ─────────────────────────────────────────────────────

    public function test_a_long_thin_item_does_not_join_a_box_it_cannot_fit(): void
    {
        $boxes = BoxPacker::normaliseBoxes(
            array(
                array(
                    'reference' => 'Only Box',
                    'outer_length' => 8,
                    'outer_width' => 8,
                    'outer_depth' => 6,
                ),
            )
        );

        $items = BoxPacker::normaliseItems(
            array(
                // 3 x 3 x 3 = 27 in^3, fits comfortably.
                $this->item('Cube', 3.0, 3.0, 3.0, 10.0),
                // 20 x 1 x 1 = 20 in^3: smaller by volume, 20 inches long.
                $this->item('Rod', 20.0, 1.0, 1.0, 10.0),
            )
        );

        $packages = BoxPacker::pack($items, $boxes);

        // Volume alone (27 + 20 = 47 <= 384) would have put the rod in the box.
        $this->assertCount(2, $packages, 'The rod cannot share an 8-inch box.');
        $this->assertSame('Only Box', $packages[0]['box_reference']);
        $this->assertSame('Fallback Package', $packages[1]['box_reference']);
        $this->assertSame(20.0, $packages[1]['dimensions']['length']);
    }

    public function test_a_box_is_not_chosen_for_an_item_it_would_already_overweight(): void
    {
        $boxes = BoxPacker::normaliseBoxes(
            array(
                array(
                    'reference' => 'Twenty Pound Box',
                    'outer_length' => 12,
                    'outer_width' => 12,
                    'outer_depth' => 12,
                    'empty_weight' => 4,
                    'max_weight' => 20,
                ),
            )
        );

        // 318 oz of contents plus the 4 oz box is 322 oz: over the 20 lb (320 oz)
        // limit. The original compared the item weight alone and chose the box.
        $items = BoxPacker::normaliseItems(
            array($this->item('Anvil', 12.0, 12.0, 12.0, 318.0))
        );

        $packages = BoxPacker::pack($items, $boxes);

        $this->assertCount(1, $packages);
        $this->assertSame('Fallback Package', $packages[0]['box_reference']);
    }

    public function test_the_package_weight_includes_the_empty_box(): void
    {
        $packages = BoxPacker::pack(
            BoxPacker::normaliseItems(array($this->item('Widget', 4.0, 4.0, 4.0, 10.0))),
            BoxPacker::defaultBoxes()
        );

        $this->assertCount(1, $packages);

        $package = $packages[0];

        // 4 x 4 x 4 does not fit the 2-inch-tall small flat-rate box, so the
        // next smallest — Cubic Small, 8 x 8 x 6 — is chosen.
        $this->assertSame('Cubic Small', $package['box_reference']);
        $this->assertSame(10.0, $package['item_weight_oz']);
        $this->assertSame(3.0, $package['box_weight_oz']);
        $this->assertSame(13.0, $package['weight_oz'], 'Charged weight is contents plus packaging.');
        $this->assertTrue($package['cubic_eligible']);
        $this->assertSame('0.3', $package['cubic_tier']);
        $this->assertSame(array('1x Widget'), $package['packing_list']);
    }

    public function test_quantity_expands_into_units_and_packs_together(): void
    {
        $packages = BoxPacker::pack(
            BoxPacker::normaliseItems(array($this->item('Widget', 4.0, 4.0, 4.0, 10.0, 3))),
            BoxPacker::defaultBoxes()
        );

        // 3 x 64 in^3 = 192 in^3 of the 384 in^3 box, 30 oz of a 320 oz limit.
        $this->assertCount(1, $packages);
        $this->assertSame(3, $packages[0]['item_count']);
        $this->assertSame(array('3x Widget'), $packages[0]['packing_list']);
        $this->assertSame(33.0, $packages[0]['weight_oz']);
    }

    public function test_a_flat_rate_box_carries_no_cubic_tier(): void
    {
        // 9 x 6 x 2 is the small flat-rate box; a 8 x 4 x 1 item fits it and
        // nothing smaller does.
        $packages = BoxPacker::pack(
            BoxPacker::normaliseItems(array($this->item('Flat', 8.0, 4.0, 1.0, 5.0))),
            BoxPacker::defaultBoxes()
        );

        $this->assertCount(1, $packages);
        $this->assertSame('USPS Small Flat Rate', $packages[0]['box_reference']);
        $this->assertSame('', $packages[0]['cubic_tier']);
        $this->assertFalse($packages[0]['cubic_eligible'], 'A flat-rate box is priced flat, not cubic.');
    }

    public function test_items_without_usable_dimensions_are_dropped(): void
    {
        $items = BoxPacker::normaliseItems(
            array(
                $this->item('Good', 4.0, 4.0, 4.0, 10.0),
                $this->item('No Height', 4.0, 4.0, 0.0, 10.0),
                $this->item('No Weight', 4.0, 4.0, 4.0, 0.0),
                'not a row',
            )
        );

        $this->assertCount(1, $items);
        $this->assertSame('Good', $items[0]['name']);
    }

    public function test_keep_flat_is_carried_but_not_enforced(): void
    {
        $raw = $this->item('Poster', 24.0, 18.0, 0.5, 8.0);
        $raw['keep_flat'] = true;

        $items = BoxPacker::normaliseItems(array($raw));

        $this->assertCount(1, $items);
        $this->assertTrue($items[0]['keep_flat'], 'The flag round-trips in the record.');
    }

    public function test_an_empty_item_list_packs_nothing(): void
    {
        $this->assertSame(array(), BoxPacker::pack(array(), BoxPacker::defaultBoxes()));
    }

    // ─── Tool surface ────────────────────────────────────────────────

    public function test_pack_items_returns_the_plan(): void
    {
        $result = $this->tool()->execute(
            array(
                'action' => 'pack_items',
                'items' => array($this->item('Widget', 4.0, 4.0, 4.0, 10.0, 2)),
            ),
            self::CONTEXT
        );

        $data = $this->data($result);

        $this->assertSame(2, $data['total_items']);
        $this->assertSame(1, $data['total_packages']);
        $this->assertSame(23.0, $data['total_weight_oz'], '2 x 10 oz of goods plus a 3 oz box.');
        $this->assertSame(6, $data['boxes_used']);
    }

    public function test_list_boxes_reports_the_cubic_standing_of_each_default(): void
    {
        $result = $this->tool()->execute(array('action' => 'list_boxes'), self::CONTEXT);

        $data = $this->data($result);

        $this->assertSame(6, $data['box_count']);
        $this->assertSame(22.0, $data['cubic_max_longest_side']);

        $boxes = array();

        $this->assertIsArray($data['boxes']);
        foreach ($data['boxes'] as $box) {
            $this->assertIsArray($box);
            $boxes[(string) $box['reference']] = $box;
        }

        $this->assertTrue($boxes['Cubic Small']['cubic_eligible']);
        $this->assertSame('0.3', $boxes['Cubic Small']['cubic_tier']);

        // 12 x 10 x 8 = 0.556 cu ft and 14 x 12 x 10 = 0.972 cu ft: both are
        // named "Cubic" and neither can qualify. The listing says so.
        $this->assertFalse($boxes['Cubic Medium']['cubic_eligible']);
        $this->assertSame('', $boxes['Cubic Medium']['cubic_tier']);
        $this->assertFalse($boxes['Cubic Large']['cubic_eligible']);
    }

    public function test_malformed_boxes_are_refused_rather_than_replaced(): void
    {
        $result = $this->tool()->execute(
            array(
                'action' => 'pack_items',
                'items' => array($this->item('Widget', 4.0, 4.0, 4.0, 10.0)),
                'boxes' => array(array('reference' => 'No Dimensions')),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $this->errorCode($result));
    }

    public function test_no_usable_items_is_a_validation_failure(): void
    {
        $result = $this->tool()->execute(
            array(
                'action' => 'pack_items',
                'items' => array($this->item('No Height', 4.0, 4.0, 0.0, 10.0)),
            ),
            self::CONTEXT
        );

        $this->assertSame('validation_failed', $this->errorCode($result));
    }

    public function test_an_unknown_action_is_refused(): void
    {
        $result = $this->tool()->execute(array('action' => 'pack_order'), self::CONTEXT);

        $this->assertSame('validation_failed', $this->errorCode($result));
    }

    public function test_a_missing_user_cannot_pack(): void
    {
        $this->expectException(AccessDeniedException::class);

        $this->tool()->execute(array('action' => 'list_boxes'), array());
    }

    public function test_the_tool_advertises_only_pure_local_flags(): void
    {
        $flags = $this->tool()->getCapabilityFlags();

        sort($flags);

        $this->assertSame(array('cacheable', 'idempotent', 'local-only', 'read-only'), $flags);
        $this->assertSame('shipping_box_packer', $this->tool()->getSlug());
    }
}
