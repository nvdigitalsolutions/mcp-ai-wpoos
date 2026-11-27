<?php

/**
 * Pack items into shipping boxes.
 *
 * Proposal 164 section 164.116. Ports `shipping_box_packer` from the WordPress
 * `ecommerce` toolkit; the algorithm and the reasoning behind its four
 * corrections live in {@see BoxPacker}, and this class is the MCP surface.
 *
 * ## What is not here
 *
 * The WordPress tool had three actions. **`pack_order` is dropped**: it called
 * `wc_get_order()` and read each line item's product weight and dimensions, so
 * it needs a store the framework-agnostic runtime does not have. It is deferred
 * with the order tools, not ported thinly — a `pack_order` that returned an
 * empty plan would be worse than its absence.
 *
 * The `requires-plugin` capability flag went with it: it existed because
 * `is_available()` demanded WooCommerce, and nothing here does.
 *
 * ## What it returns
 *
 * The packing plan is the tool's whole value, so it is returned in full rather
 * than summarised: one record per package with the box chosen, its outer
 * dimensions (which is what a carrier quotes on), the item weight and the box
 * weight separately, the packing list, and the cubic standing. `weight_oz`
 * includes the empty box, because that is the weight the carrier bills.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Service\Safety\CapabilityFlagVocabulary;
use Nvoos\Core\Domain\Service\Shipping\BoxPacker;
use Nvoos\Core\Tool\AbstractTool;

/**
 * @phpstan-import-type PackBox from BoxPacker
 */
final class ShippingBoxPackerTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The actions this tool serves.
     *
     * @var list<string>
     */
    private const ACTIONS = array('pack_items', 'list_boxes');

    public function getSlug(): string
    {
        return 'shipping_box_packer';
    }

    public function getName(): string
    {
        return 'Shipping Box Packer';
    }

    public function getDescription(): string
    {
        return 'Pack items into optimal shipping boxes using a 3D bin-packing heuristic. '
            . 'Returns the box chosen for each package, its outer dimensions, item and packaging '
            . 'weights, a packing list, and whether the package qualifies for USPS Priority Mail '
            . 'Cubic pricing. Accepts custom box definitions or uses the USPS-boxed defaults. '
            . 'Pure computation: no network, no credentials, no store.';
    }

    /**
     * @return array<string, mixed>
     */
    public function getParametersSchema(): array
    {
        return array(
            'type' => 'object',
            'properties' => array(
                'action' => array(
                    'type' => 'string',
                    'description' => 'Packing action to perform.',
                    'enum' => self::ACTIONS,
                    'default' => 'pack_items',
                ),
                'items' => array(
                    'type' => 'array',
                    'description' => 'Items to pack. Each needs name, dimensions in inches, and '
                        . 'weight in ounces; quantity expands to that many units. Required for '
                        . 'pack_items.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array(
                                'type' => 'string',
                                'description' => 'Item name or description.',
                            ),
                            'length' => array(
                                'type' => 'number',
                                'description' => 'Item length in inches.',
                                'minimum' => 0.1,
                            ),
                            'width' => array(
                                'type' => 'number',
                                'description' => 'Item width in inches.',
                                'minimum' => 0.1,
                            ),
                            'height' => array(
                                'type' => 'number',
                                'description' => 'Item height in inches.',
                                'minimum' => 0.1,
                            ),
                            'weight_oz' => array(
                                'type' => 'number',
                                'description' => 'Item weight in ounces.',
                                'minimum' => 0.1,
                            ),
                            'quantity' => array(
                                'type' => 'integer',
                                'description' => 'Number of units of this item.',
                                'minimum' => 1,
                                'default' => 1,
                            ),
                            'keep_flat' => array(
                                'type' => 'boolean',
                                'description' => 'Recorded on the item and returned in the plan. The '
                                    . 'packing heuristic does not currently enforce it.',
                                'default' => false,
                            ),
                        ),
                        'required' => array('name', 'length', 'width', 'height', 'weight_oz'),
                    ),
                ),
                'boxes' => array(
                    'type' => 'array',
                    'description' => 'Custom box definitions. Omit to use the built-in USPS box '
                        . 'catalogue. Each needs reference and outer dimensions in inches.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'reference' => array(
                                'type' => 'string',
                                'description' => 'Box name or label.',
                            ),
                            'package_code' => array(
                                'type' => 'string',
                                'description' => 'Carrier package code, e.g. "package" or '
                                    . '"small_flat_rate_box".',
                                'default' => 'package',
                            ),
                            'package_name' => array(
                                'type' => 'string',
                                'description' => 'Display name for the box.',
                            ),
                            'box_type' => array(
                                'type' => 'string',
                                'description' => 'Cubic rates are assessed only for "cubic" boxes; '
                                    . '"flat_rate" boxes never carry a cubic tier.',
                                'enum' => array('cubic', 'flat_rate'),
                                'default' => 'cubic',
                            ),
                            'outer_length' => array(
                                'type' => 'number',
                                'description' => 'Outer length in inches.',
                            ),
                            'outer_width' => array(
                                'type' => 'number',
                                'description' => 'Outer width in inches.',
                            ),
                            'outer_depth' => array(
                                'type' => 'number',
                                'description' => 'Outer depth (height) in inches.',
                            ),
                            'inner_length' => array(
                                'type' => 'number',
                                'description' => 'Usable inner length in inches. Defaults to the '
                                    . 'outer length.',
                            ),
                            'inner_width' => array(
                                'type' => 'number',
                                'description' => 'Usable inner width in inches. Defaults to the '
                                    . 'outer width.',
                            ),
                            'inner_depth' => array(
                                'type' => 'number',
                                'description' => 'Usable inner depth in inches. Defaults to the '
                                    . 'outer depth.',
                            ),
                            'empty_weight' => array(
                                'type' => 'number',
                                'description' => 'Empty box weight in ounces.',
                                'default' => 0,
                            ),
                            'max_weight' => array(
                                'type' => 'number',
                                'description' => 'Maximum payload weight in pounds.',
                                'default' => 70,
                            ),
                        ),
                        'required' => array('reference', 'outer_length', 'outer_width', 'outer_depth'),
                    ),
                ),
            ),
            'required' => array('action'),
        );
    }

    /**
     * What the WordPress tool enforced.
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * Pure, deterministic, in-process.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return CapabilityFlagVocabulary::PURE_LOCAL;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $action = $this->stringParam($arguments, 'action', 'pack_items');

        if (! in_array($action, self::ACTIONS, true)) {
            return $this->errors->validationFailed(
                sprintf('Unknown action "%s". Use pack_items or list_boxes.', $action),
                array('action' => array('Unknown action.'))
            );
        }

        $boxes = $this->resolveBoxes($arguments);

        if (null === $boxes) {
            return $this->errors->validationFailed(
                'None of the supplied boxes has usable outer dimensions. Each box needs '
                . 'reference, outer_length, outer_width and outer_depth, all greater than zero.',
                array('boxes' => array('No usable box definitions.'))
            );
        }

        return 'pack_items' === $action
            ? $this->packItems($arguments, $boxes)
            : $this->listBoxes($boxes);
    }

    /**
     * Resolve the box catalogue: the caller's, or the built-in one.
     *
     * Absent boxes and malformed boxes are different answers. Absent means "use
     * the defaults"; malformed means the caller tried to define a catalogue and
     * none of it parsed, which must not quietly become the default plan.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return list<PackBox>|null Null when the caller's boxes are unusable.
     */
    private function resolveBoxes(array $arguments): ?array
    {
        $raw = $this->arrayParam($arguments, 'boxes');

        if (array() === $raw) {
            return BoxPacker::defaultBoxes();
        }

        $boxes = BoxPacker::normaliseBoxes($raw);

        return array() === $boxes ? null : $boxes;
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param list<PackBox>        $boxes     Resolved box catalogue.
     */
    private function packItems(array $arguments, array $boxes): mixed
    {
        $items = BoxPacker::normaliseItems($this->arrayParam($arguments, 'items'));

        if (array() === $items) {
            return $this->errors->validationFailed(
                'No usable items. Each item needs name, length, width, height and weight_oz, '
                . 'all greater than zero.',
                array('items' => array('No usable items.'))
            );
        }

        $packages = BoxPacker::pack($items, $boxes);

        return $this->success(
            sprintf(
                'Packed %d item(s) into %d package(s).',
                count($items),
                count($packages)
            ),
            array(
                'total_items' => count($items),
                'total_packages' => count($packages),
                'total_weight_oz' => round((float) array_sum(array_column($packages, 'weight_oz')), 2),
                'boxes_used' => count($boxes),
                'packages' => $packages,
            )
        );
    }

    /**
     * @param list<PackBox> $boxes Resolved box catalogue.
     */
    private function listBoxes(array $boxes): mixed
    {
        $described = BoxPacker::describe($boxes);

        return $this->success(
            sprintf('%d box definition(s) available.', count($described)),
            array(
                'box_count' => count($described),
                'cubic_max_cubic_feet' => BoxPacker::CUBIC_MAX_CUBIC_FEET,
                'cubic_max_weight_oz' => BoxPacker::CUBIC_MAX_WEIGHT_OZ,
                'cubic_max_longest_side' => BoxPacker::CUBIC_MAX_LONGEST_SIDE,
                'boxes' => $described,
            )
        );
    }
}
