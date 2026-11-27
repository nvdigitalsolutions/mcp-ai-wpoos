<?php

/**
 * Quote shipping rates for packed items.
 *
 * Proposal 164 section 164.116. Ports `shipping_rate_estimator` from the
 * WordPress `ecommerce` toolkit: it packs items with {@see BoxPacker} and asks a
 * carrier aggregator what each package would cost.
 *
 * ## Two providers, and three products called "ShipStation"
 *
 * ShipEngine was rebranded as the **ShipStation API**, which means the provider
 * names the WordPress tool used are now ambiguous. All three products were
 * checked against the vendor's own API overview on 2026-11-27:
 *
 * | Product | Base URL | This tool |
 * |---|---|---|
 * | ShipStation API (formerly ShipEngine) | `https://api.shipengine.com/v1` | `shipstation_standalone` |
 * | ShipStation API V1 | `https://ssapi.shipstation.com` | `shipstation_v1` |
 * | ShipStation API V2 | `https://api.shipstation.com/v2` | not supported |
 *
 * V2 is an early release limited to Standard and Premium plans, so it is not a
 * substitute for either. The legacy spellings `shipengine` and `shipstation` are
 * **refused with a message naming the replacement** rather than accepted: a
 * caller that still says "shipengine" is describing a product that no longer
 * exists under that name, and silently honouring it hides the migration.
 *
 * ## Five corrections
 *
 * Each fixes something the WordPress tool got wrong in a way a caller could
 * observe:
 *
 * 1. **A run that cannot quote anything is a refusal, not a success.** The
 *    original set `success: true` before rating and returned
 *    `total_rate_amount: 0.0` with warnings attached when it had no credentials
 *    or every package failed. A zero is an answer a caller acts on.
 * 2. **Totals are kept per currency.** The original summed every package into
 *    one number and overwrote `currency` with whichever package came last.
 * 3. **The reported amount is the amount used to choose.** On V1 the original
 *    sorted by `shipmentCost + otherCost` and then returned `shipmentCost`
 *    alone, so the price it reported could be lower than the price it compared.
 * 4. **No hardcoded USPS rows.** The original emitted a `pirateship_rows` export
 *    with `carrier: USPS` and `service: Priority Mail` written into the code,
 *    whatever rate had come back. Those rows are gone; each package reports the
 *    carrier and service the provider actually returned.
 * 5. **No hidden stores.** The original read its carrier and its API keys from
 *    another plugin's option (`fk_usps_optimizer_settings`), fell back to the
 *    WooCommerce store address, and took the *first* enabled connection of a
 *    type when no explicit id was given — so a second account was silently
 *    ignored. Here `credentials` and `ship_from` are arguments. Nothing is
 *    looked up, so nothing can be looked up wrongly.
 *
 * The hardcoded `service_code: usps_priority_mail` is gone for the same reason
 * as (4): with `carrier_ids` alone the standalone API rate-shops every service
 * those carriers offer, which is what "rate-shopping" means. A caller that wants
 * one service passes `service_code`.
 *
 * ## What is deliberately different from the original
 *
 * - **No sleeping.** The WordPress client retried a `429` twice with
 *   `sleep()`, blocking a tool call for up to 20 seconds. A rate-limited quote is
 *   returned as a refusal carrying `Retry-After`; the caller decides whether to
 *   wait.
 * - **`estimate_order_rates` is not here.** It read a WooCommerce order through
 *   `wc_get_order()`; it belongs with the deferred order tools.
 * - **One provider call per package.** A ten-package plan is ten requests. The
 *   limit that matters is the provider's, and `test_connection` reports what is
 *   left of it.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tool\Ecommerce;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\ToolCapabilityFlagsInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Service\Shipping\BoxPacker;
use Nvoos\Core\Domain\ValueObject\Money;
use Nvoos\Core\Tool\AbstractTool;

/**
 * @phpstan-import-type PackBox from BoxPacker
 * @phpstan-type RateAddress array{name: string, company: string, phone: string, address_line1: string, address_line2: string, city: string, state: string, postal_code: string, country_code: string, residential: bool}
 * @phpstan-type RateOutcome array{ok: bool, rate: Money|null, other_cost: Money|null, carrier: string, service_code: string, message: string}
 */
final class ShippingRateEstimatorTool extends AbstractTool implements ToolCapabilityFlagsInterface
{
    /**
     * The standalone ShipStation API, formerly ShipEngine.
     */
    public const STANDALONE_BASE = 'https://api.shipengine.com/v1';

    /**
     * The legacy ShipStation API V1, still supported by the vendor.
     */
    public const V1_BASE = 'https://ssapi.shipstation.com';

    /**
     * Providers this tool speaks.
     *
     * @var list<string>
     */
    private const PROVIDERS = array('shipstation_standalone', 'shipstation_v1');

    /**
     * Names the WordPress tool used, and what they mean now.
     *
     * @var array<string, string>
     */
    private const LEGACY_PROVIDER_NAMES = array(
        'shipengine' => 'shipstation_standalone',
        'shipstation' => 'shipstation_v1',
    );

    /**
     * V1's default carrier code, as the WordPress tool defaulted it.
     */
    private const DEFAULT_V1_CARRIER = 'stamps_com';

    public function __construct(
        ErrorFactoryInterface $errors,
        private readonly HttpClientInterface $http,
    ) {
        parent::__construct($errors);
    }

    public function getSlug(): string
    {
        return 'shipping_rate_estimator';
    }

    public function getName(): string
    {
        return 'Shipping Rate Estimator';
    }

    public function getDescription(): string
    {
        return 'Packs items into boxes and quotes what each package would cost through the '
            . 'ShipStation API (formerly ShipEngine, api.shipengine.com) or the legacy '
            . 'ShipStation API V1 (ssapi.shipstation.com). Returns one rate per package with '
            . 'the carrier and service the provider chose, totals kept separate per currency, '
            . 'and a refusal when nothing could be quoted. Credentials and the ship-from '
            . 'address are arguments; nothing is read from a store.';
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
                    'description' => 'estimate_rates quotes packed items; test_connection checks '
                        . 'credentials and reports the remaining rate limit.',
                    'enum' => array('estimate_rates', 'test_connection'),
                    'default' => 'estimate_rates',
                ),
                'provider' => array(
                    'type' => 'string',
                    'description' => 'shipstation_standalone is the ShipStation API, formerly '
                        . 'ShipEngine. shipstation_v1 is the legacy ShipStation API V1. The old '
                        . 'names "shipengine" and "shipstation" are refused because the rebrand '
                        . 'made them ambiguous.',
                    'enum' => self::PROVIDERS,
                    'default' => 'shipstation_standalone',
                ),
                'items' => array(
                    'type' => 'array',
                    'description' => 'Items to pack. Same shape as shipping_box_packer: name, '
                        . 'dimensions in inches, weight in ounces, optional quantity.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'name' => array(
                                'type' => 'string',
                                'description' => 'Item name.',
                            ),
                            'length' => array(
                                'type' => 'number',
                                'description' => 'Length in inches.',
                                'minimum' => 0.1,
                            ),
                            'width' => array(
                                'type' => 'number',
                                'description' => 'Width in inches.',
                                'minimum' => 0.1,
                            ),
                            'height' => array(
                                'type' => 'number',
                                'description' => 'Height in inches.',
                                'minimum' => 0.1,
                            ),
                            'weight_oz' => array(
                                'type' => 'number',
                                'description' => 'Weight in ounces.',
                                'minimum' => 0.1,
                            ),
                            'quantity' => array(
                                'type' => 'integer',
                                'description' => 'Number of units.',
                                'minimum' => 1,
                                'default' => 1,
                            ),
                        ),
                        'required' => array('name', 'length', 'width', 'height', 'weight_oz'),
                    ),
                ),
                'boxes' => array(
                    'type' => 'array',
                    'description' => 'Custom box definitions. Omit for the built-in USPS '
                        . 'catalogue. Same shape as shipping_box_packer.',
                    'items' => array(
                        'type' => 'object',
                        'properties' => array(
                            'reference' => array(
                                'type' => 'string',
                                'description' => 'Box name.',
                            ),
                            'package_code' => array(
                                'type' => 'string',
                                'description' => 'Carrier package code.',
                                'default' => 'package',
                            ),
                            'package_name' => array(
                                'type' => 'string',
                                'description' => 'Display name for the box.',
                            ),
                            'box_type' => array(
                                'type' => 'string',
                                'description' => 'cubic or flat_rate.',
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
                                'description' => 'Outer depth in inches.',
                            ),
                            'inner_length' => array(
                                'type' => 'number',
                                'description' => 'Usable inner length. Defaults to outer.',
                            ),
                            'inner_width' => array(
                                'type' => 'number',
                                'description' => 'Usable inner width. Defaults to outer.',
                            ),
                            'inner_depth' => array(
                                'type' => 'number',
                                'description' => 'Usable inner depth. Defaults to outer.',
                            ),
                            'empty_weight' => array(
                                'type' => 'number',
                                'description' => 'Empty box weight in ounces.',
                                'default' => 0,
                            ),
                            'max_weight' => array(
                                'type' => 'number',
                                'description' => 'Maximum payload in pounds.',
                                'default' => 70,
                            ),
                        ),
                        'required' => array('reference', 'outer_length', 'outer_width', 'outer_depth'),
                    ),
                ),
                'ship_to' => array(
                    'type' => 'object',
                    'description' => 'Destination address. postal_code is required.',
                    'properties' => array(
                        'name' => array('type' => 'string', 'description' => 'Recipient name.'),
                        'company' => array('type' => 'string', 'description' => 'Company name.'),
                        'phone' => array('type' => 'string', 'description' => 'Phone number.'),
                        'address_line1' => array('type' => 'string', 'description' => 'Street address.'),
                        'address_line2' => array('type' => 'string', 'description' => 'Suite or unit.'),
                        'city' => array('type' => 'string', 'description' => 'City.'),
                        'state' => array('type' => 'string', 'description' => 'State or province code.'),
                        'postal_code' => array('type' => 'string', 'description' => 'ZIP or postal code.'),
                        'country_code' => array(
                            'type' => 'string',
                            'description' => 'Two-letter country code.',
                            'default' => 'US',
                        ),
                        'residential' => array(
                            'type' => 'boolean',
                            'description' => 'Whether the destination is residential.',
                            'default' => false,
                        ),
                    ),
                    'required' => array('postal_code'),
                ),
                'ship_from' => array(
                    'type' => 'object',
                    'description' => 'Origin address. Required: rates cannot be quoted without an '
                        . 'origin, and this runtime has no store to read one from.',
                    'properties' => array(
                        'name' => array('type' => 'string', 'description' => 'Sender name.'),
                        'company' => array('type' => 'string', 'description' => 'Company name.'),
                        'phone' => array('type' => 'string', 'description' => 'Phone number.'),
                        'address_line1' => array('type' => 'string', 'description' => 'Street address.'),
                        'address_line2' => array('type' => 'string', 'description' => 'Suite or unit.'),
                        'city' => array('type' => 'string', 'description' => 'City.'),
                        'state' => array('type' => 'string', 'description' => 'State or province code.'),
                        'postal_code' => array('type' => 'string', 'description' => 'ZIP or postal code.'),
                        'country_code' => array(
                            'type' => 'string',
                            'description' => 'Two-letter country code.',
                            'default' => 'US',
                        ),
                    ),
                    'required' => array('postal_code'),
                ),
                'credentials' => array(
                    'type' => 'object',
                    'description' => 'Provider credentials. shipstation_standalone needs '
                        . 'api_key and carrier_id (optionally service_code). shipstation_v1 '
                        . 'needs api_key and api_secret (optionally carrier_code, default '
                        . 'stamps_com). The WordPress argument name api_credentials is accepted '
                        . 'as an alias.',
                    'properties' => array(
                        'api_key' => array('type' => 'string', 'description' => 'API key.'),
                        'api_secret' => array(
                            'type' => 'string',
                            'description' => 'API secret. V1 only.',
                        ),
                        'carrier_id' => array(
                            'type' => 'string',
                            'description' => 'Standalone carrier id, e.g. "se-123456".',
                        ),
                        'service_code' => array(
                            'type' => 'string',
                            'description' => 'Standalone service to restrict to, e.g. '
                                . '"usps_priority_mail". Omit to rate-shop every service.',
                        ),
                        'carrier_code' => array(
                            'type' => 'string',
                            'description' => 'V1 carrier code.',
                            'default' => self::DEFAULT_V1_CARRIER,
                        ),
                    ),
                ),
            ),
            'required' => array('action'),
        );
    }

    /**
     * The WordPress tool gated on `manage_woocommerce`, which this runtime does
     * not have. The toolkit's convention is `edit_posts`.
     */
    public function getRequiredCapability(): string
    {
        return 'edit_posts';
    }

    /**
     * A rate quote is an outbound read: no state changes anywhere.
     *
     * @return string[]
     */
    public function getCapabilityFlags(): array
    {
        return array('external-api', 'network-dependent', 'read-only', 'requires-credentials');
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     * @param array<string, mixed> $context   Execution context.
     */
    public function execute(array $arguments = array(), array $context = array()): mixed
    {
        $this->authorize($context, $this->getRequiredCapability());

        $action = $this->stringParam($arguments, 'action', 'estimate_rates');

        if (! in_array($action, array('estimate_rates', 'test_connection'), true)) {
            return $this->errors->validationFailed(
                sprintf('Unknown action "%s". Use estimate_rates or test_connection.', $action),
                array('action' => array('Unknown action.'))
            );
        }

        $provider = $this->provider($arguments);

        if (! in_array($provider, self::PROVIDERS, true)) {
            return $this->providerRefusal($provider);
        }

        $credentials = $this->credentials($arguments);

        return 'test_connection' === $action
            ? $this->testConnection($provider, $credentials)
            : $this->estimateRates($provider, $arguments, $credentials);
    }

    // ─── Rate estimation ─────────────────────────────────────────────

    /**
     * @param array<string, mixed>  $arguments   Tool arguments.
     * @param array<string, mixed>  $credentials Provider credentials.
     */
    private function estimateRates(string $provider, array $arguments, array $credentials): mixed
    {
        $items = BoxPacker::normaliseItems($this->arrayParam($arguments, 'items'));

        if (array() === $items) {
            return $this->errors->validationFailed(
                'No usable items. Each item needs name, length, width, height and weight_oz, '
                . 'all greater than zero.',
                array('items' => array('No usable items.'))
            );
        }

        $shipTo = $this->address($this->objectParam($arguments, 'ship_to'));

        if (null === $shipTo) {
            return $this->errors->validationFailed(
                'ship_to needs a postal_code.',
                array('ship_to' => array('A postal code is required.'))
            );
        }

        $shipFrom = $this->address($this->objectParam($arguments, 'ship_from'));

        if (null === $shipFrom) {
            return $this->errors->validationFailed(
                'ship_from needs a postal_code. Rates cannot be quoted without an origin, and '
                . 'this runtime has no store address to fall back to.',
                array('ship_from' => array('A postal code is required.'))
            );
        }

        // Refuse before spending a request. The WordPress tool reported success
        // with a zero total when credentials were missing.
        $missing = $this->missingCredentials($provider, $credentials);

        if (array() !== $missing) {
            return $this->errors->validationFailed(
                sprintf(
                    '%s needs %s. Pass them in the credentials argument.',
                    $provider,
                    implode(' and ', $missing)
                ),
                array('credentials' => $missing)
            );
        }

        $boxes = $this->resolveBoxes($arguments);

        if (null === $boxes) {
            return $this->errors->validationFailed(
                'None of the supplied boxes has usable outer dimensions.',
                array('boxes' => array('No usable box definitions.'))
            );
        }

        $packages = BoxPacker::pack($items, $boxes);

        if (array() === $packages) {
            return $this->errors->create(
                'shipping_rate_packing_failed',
                'The items could not be packed into any box.',
                array('total_items' => count($items))
            );
        }

        $rated = array();
        $unrated = array();

        /** @var array<string, Money> $totals */
        $totals = array();

        foreach ($packages as $package) {
            $outcome = $this->ratePackage($provider, $package, $shipTo, $shipFrom, $credentials);

            if (! $outcome['ok'] || ! $outcome['rate'] instanceof Money) {
                $unrated[] = array(
                    'package_number' => $package['package_number'],
                    'box_reference' => $package['box_reference'],
                    'reason' => $outcome['message'],
                );

                continue;
            }

            $rate = $outcome['rate'];

            $totals[$rate->currency] = isset($totals[$rate->currency])
                ? $totals[$rate->currency]->add($rate)
                : $rate;

            $rated[] = array_merge(
                $package,
                array(
                    'carrier' => $outcome['carrier'],
                    'service_code' => $outcome['service_code'],
                    'rate' => $rate,
                    'other_cost' => $outcome['other_cost'],
                )
            );
        }

        // Everything failed: that is a refusal, not a zero total.
        if (array() === $rated) {
            return $this->errors->create(
                'shipping_rate_failed',
                sprintf('No package could be rated by %s.', $provider),
                array(
                    'provider' => $provider,
                    'attempts' => $unrated,
                )
            );
        }

        return $this->success(
            sprintf(
                'Rated %d of %d package(s) via %s.',
                count($rated),
                count($packages),
                $provider
            ),
            array(
                'provider' => $provider,
                'total_items' => count($items),
                'total_packages' => count($packages),
                'rated_packages' => count($rated),
                'unrated_packages' => count($unrated),
                'currency_totals' => $totals,
                'packages' => $rated,
                'unrated' => $unrated,
            )
        );
    }

    /**
     * Quote one packed package.
     *
     * @param array<string, mixed> $package     A {@see BoxPacker} package.
     * @param RateAddress          $shipTo      Destination.
     * @param RateAddress          $shipFrom    Origin.
     * @param array<string, mixed> $credentials Provider credentials.
     *
     * @return RateOutcome
     */
    private function ratePackage(
        string $provider,
        array $package,
        array $shipTo,
        array $shipFrom,
        array $credentials
    ): array {
        return 'shipstation_standalone' === $provider
            ? $this->standaloneRate($package, $shipTo, $shipFrom, $credentials)
            : $this->v1Rate($package, $shipTo, $shipFrom, $credentials);
    }

    /**
     * Quote through the standalone API: POST /v1/rates.
     *
     * `carrier_ids` alone lets the provider rate-shop every service that carrier
     * offers. `service_code` is sent only when the caller restricts to one —
     * the WordPress tool hardcoded `usps_priority_mail`, which is not what
     * "rate-shopping" means.
     *
     * @param array<string, mixed> $package     Packed package.
     * @param RateAddress          $shipTo      Destination.
     * @param RateAddress          $shipFrom    Origin.
     * @param array<string, mixed> $credentials Credentials.
     *
     * @return RateOutcome
     */
    private function standaloneRate(
        array $package,
        array $shipTo,
        array $shipFrom,
        array $credentials
    ): array {
        $shipment = array(
            'validate_address' => 'no_validation',
            'ship_to' => $this->standaloneAddress($shipTo),
            'ship_from' => $this->standaloneAddress($shipFrom),
            'packages' => array(
                array(
                    'package_code' => (string) $package['package_code'],
                    'weight' => array(
                        'value' => (float) $package['weight_oz'],
                        'unit' => 'ounce',
                    ),
                    'dimensions' => array(
                        'unit' => 'inch',
                        'length' => $package['dimensions']['length'],
                        'width' => $package['dimensions']['width'],
                        'height' => $package['dimensions']['height'],
                    ),
                ),
            ),
        );

        $serviceCode = $this->text($credentials, 'service_code');

        if ('' !== $serviceCode) {
            $shipment['service_code'] = $serviceCode;
        }

        $payload = array(
            'rate_options' => array(
                'carrier_ids' => array($this->text($credentials, 'carrier_id')),
            ),
            'shipment' => $shipment,
        );

        try {
            $response = $this->http->send(
                'POST',
                self::STANDALONE_BASE . '/rates',
                $this->standaloneHeaders($credentials),
                $this->jsonBody($payload)
            );
        } catch (\RuntimeException $error) {
            return $this->failed('The rate request could not be sent: ' . $error->getMessage());
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            return $this->failed(
                $this->providerError($response, 'shipstation_standalone')
            );
        }

        $body = $this->decode($response);

        $rates = $body['rate_response']['rates'] ?? null;

        if (! is_array($rates) || array() === $rates) {
            return $this->failed('The provider returned no rates for this package.');
        }

        $best = $this->cheapest(
            $rates,
            static function (array $rate): float {
                $amount = $rate['shipping_amount']['amount'] ?? null;

                return is_numeric($amount) ? (float) $amount : INF;
            }
        );

        if (null === $best) {
            return $this->failed('Every returned rate was missing its amount.');
        }

        try {
            $money = Money::fromDecimal(
                $this->scalar($best['shipping_amount']['amount'] ?? null),
                $this->currency($best['shipping_amount']['currency'] ?? null)
            );
        } catch (\InvalidArgumentException $error) {
            return $this->failed('The provider returned an unusable amount: ' . $error->getMessage());
        }

        return array(
            'ok' => true,
            'rate' => $money,
            'other_cost' => null,
            'carrier' => $this->scalar($best['carrier_friendly_name'] ?? $best['carrier_code'] ?? null),
            'service_code' => $this->scalar($best['service_code'] ?? null),
            'message' => '',
        );
    }

    /**
     * Quote through the legacy V1 API: POST /shipments/getrates.
     *
     * The returned amount is `shipmentCost + otherCost`, which is the figure the
     * original sorted by — and then failed to report.
     *
     * @param array<string, mixed> $package     Packed package.
     * @param RateAddress          $shipTo      Destination.
     * @param RateAddress          $shipFrom    Origin.
     * @param array<string, mixed> $credentials Credentials.
     *
     * @return RateOutcome
     */
    private function v1Rate(
        array $package,
        array $shipTo,
        array $shipFrom,
        array $credentials
    ): array {
        $carrierCode = $this->text($credentials, 'carrier_code', self::DEFAULT_V1_CARRIER);

        $payload = array(
            'carrierCode' => $carrierCode,
            'serviceCode' => null,
            'packageCode' => null,
            'fromPostalCode' => $shipFrom['postal_code'],
            'toState' => $shipTo['state'],
            'toCountry' => $shipTo['country_code'],
            'toPostalCode' => $shipTo['postal_code'],
            'toCity' => $shipTo['city'],
            'weight' => array(
                'value' => (float) $package['weight_oz'],
                'units' => 'ounces',
            ),
            'dimensions' => array(
                'units' => 'inches',
                'length' => $package['dimensions']['length'],
                'width' => $package['dimensions']['width'],
                'height' => $package['dimensions']['height'],
            ),
            'confirmation' => 'none',
            'residential' => $shipTo['residential'],
        );

        try {
            $response = $this->http->send(
                'POST',
                self::V1_BASE . '/shipments/getrates',
                $this->v1Headers($credentials),
                $this->jsonBody($payload)
            );
        } catch (\RuntimeException $error) {
            return $this->failed('The rate request could not be sent: ' . $error->getMessage());
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            return $this->failed($this->providerError($response, 'shipstation_v1'));
        }

        $body = json_decode($response->body, true);
        $rates = is_array($body) ? $body : array();

        if (array() === $rates) {
            return $this->failed('The provider returned no rates for this package.');
        }

        $best = $this->cheapest(
            $rates,
            static function (array $rate): float {
                $cost = $rate['shipmentCost'] ?? null;
                $other = $rate['otherCost'] ?? 0;

                if (! is_numeric($cost)) {
                    return INF;
                }

                return (float) $cost + (is_numeric($other) ? (float) $other : 0.0);
            }
        );

        if (null === $best) {
            return $this->failed('Every returned rate was missing its cost.');
        }

        try {
            $base = Money::fromDecimal($this->scalar($best['shipmentCost'] ?? null), 'USD');
            $otherTotal = is_numeric($best['otherCost'] ?? null)
                ? Money::fromDecimal($this->scalar($best['otherCost']), 'USD')
                : null;
        } catch (\InvalidArgumentException $error) {
            return $this->failed('The provider returned an unusable cost: ' . $error->getMessage());
        }

        return array(
            'ok' => true,
            'rate' => null !== $otherTotal ? $base->add($otherTotal) : $base,
            'other_cost' => $otherTotal,
            'carrier' => $carrierCode,
            'service_code' => $this->scalar($best['serviceCode'] ?? null),
            'message' => '',
        );
    }

    // ─── Connection test ─────────────────────────────────────────────

    /**
     * @param array<string, mixed> $credentials Provider credentials.
     */
    private function testConnection(string $provider, array $credentials): mixed
    {
        $missing = $this->missingCredentials($provider, $credentials);

        if (array() !== $missing) {
            return $this->errors->validationFailed(
                sprintf('%s needs %s.', $provider, implode(' and ', $missing)),
                array('credentials' => $missing)
            );
        }

        $standalone = 'shipstation_standalone' === $provider;

        try {
            $response = $this->http->send(
                'GET',
                $standalone ? self::STANDALONE_BASE . '/carriers' : self::V1_BASE . '/carriers',
                $standalone ? $this->standaloneHeaders($credentials) : $this->v1Headers($credentials)
            );
        } catch (\RuntimeException $error) {
            return $this->errors->create(
                'shipping_rate_unreachable',
                'The provider could not be reached: ' . $error->getMessage(),
                array('provider' => $provider)
            );
        }

        if (401 === $response->statusCode || 403 === $response->statusCode) {
            return $this->errors->create(
                'shipping_rate_credentials_rejected',
                sprintf('%s rejected the credentials (HTTP %d).', $provider, $response->statusCode),
                array('provider' => $provider)
            );
        }

        if ($response->statusCode < 200 || $response->statusCode >= 300) {
            return $this->errors->create(
                'shipping_rate_provider_error',
                $this->providerError($response, $provider),
                array('provider' => $provider, 'status' => $response->statusCode)
            );
        }

        $body = $this->decode($response);
        $carriers = isset($body['carriers']) && is_array($body['carriers']) ? $body['carriers'] : array();

        $data = array(
            'provider' => $provider,
            'sandbox' => $this->isSandbox($provider, $credentials),
        );

        if ($standalone) {
            $data['carriers_found'] = count($carriers);

            $carrierId = $this->text($credentials, 'carrier_id');

            if ('' !== $carrierId) {
                $data['carrier_id_present'] = $this->carrierExists($carriers, $carrierId);
            }
        }

        $limits = $this->rateLimits($response);

        if (array() !== $limits) {
            $data['rate_limits'] = $limits;
        }

        return $this->success(sprintf('%s connection successful.', $provider), $data);
    }

    /**
     * @param array<mixed> $carriers
     */
    private function carrierExists(array $carriers, string $carrierId): bool
    {
        foreach ($carriers as $carrier) {
            if (is_array($carrier) && isset($carrier['carrier_id']) && (string) $carrier['carrier_id'] === $carrierId) {
                return true;
            }
        }

        return false;
    }

    // ─── Provider plumbing ───────────────────────────────────────────

    /**
     * @param array<string, mixed> $credentials Credentials.
     *
     * @return array<string, string>
     */
    private function standaloneHeaders(array $credentials): array
    {
        return array(
            'API-Key' => $this->text($credentials, 'api_key'),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'NVoOS-Core/1.0',
        );
    }

    /**
     * @param array<string, mixed> $credentials Credentials.
     *
     * @return array<string, string>
     */
    private function v1Headers(array $credentials): array
    {
        $pair = $this->text($credentials, 'api_key') . ':' . $this->text($credentials, 'api_secret');

        return array(
            'Authorization' => 'Basic ' . base64_encode($pair),
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'User-Agent' => 'NVoOS-Core/1.0',
        );
    }

    /**
     * The address shape the standalone API expects.
     *
     * @param RateAddress $address Canonical address.
     *
     * @return array<string, string>
     */
    private function standaloneAddress(array $address): array
    {
        return array(
            'name' => $address['name'],
            'company_name' => $address['company'],
            'phone' => $address['phone'],
            'address_line1' => $address['address_line1'],
            'address_line2' => $address['address_line2'],
            'city_locality' => $address['city'],
            'state_province' => $address['state'],
            'postal_code' => $address['postal_code'],
            'country_code' => $address['country_code'],
            'address_residential_indicator' => $address['residential'] ? 'yes' : 'no',
        );
    }

    /**
     * The provider's own error message, or a labelled status line.
     */
    private function providerError(HttpResponse $response, string $provider): string
    {
        $body = $this->decode($response);

        // The standalone API nests `errors[].message`; V1 uses a `Message` key.
        if (isset($body['errors']) && is_array($body['errors'])) {
            $messages = array();

            foreach ($body['errors'] as $error) {
                if (is_array($error) && isset($error['message']) && is_scalar($error['message'])) {
                    $messages[] = (string) $error['message'];
                }
            }

            if (array() !== $messages) {
                return implode('; ', $messages);
            }
        }

        foreach (array('Message', 'message') as $key) {
            if (isset($body[$key]) && is_scalar($body[$key]) && '' !== (string) $body[$key]) {
                return (string) $body[$key];
            }
        }

        return sprintf('%s returned HTTP %d.', $provider, $response->statusCode);
    }

    /**
     * A refusal outcome for one package.
     *
     * @return RateOutcome
     */
    private function failed(string $message): array
    {
        return array(
            'ok' => false,
            'rate' => null,
            'other_cost' => null,
            'carrier' => '',
            'service_code' => '',
            'message' => $message,
        );
    }

    /**
     * The entry with the lowest cost, or null when none has a usable one.
     *
     * @param array<mixed>            $rows  Provider rows.
     * @param callable(array<mixed>): float $cost Cost extractor.
     *
     * @return array<mixed>|null
     */
    private function cheapest(array $rows, callable $cost): ?array
    {
        $best = null;
        $bestCost = INF;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $value = $cost($row);

            if (! is_finite($value)) {
                continue;
            }

            if ($value < $bestCost) {
                $best = $row;
                $bestCost = $value;
            }
        }

        return $best;
    }

    /**
     * @param array<string, mixed> $credentials Provider credentials.
     *
     * @return list<string> Missing argument names.
     */
    private function missingCredentials(string $provider, array $credentials): array
    {
        $missing = array();

        if ('' === $this->text($credentials, 'api_key')) {
            $missing[] = 'api_key';
        }

        if ('shipstation_standalone' === $provider) {
            if ('' === $this->text($credentials, 'carrier_id')) {
                $missing[] = 'carrier_id';
            }

            return $missing;
        }

        if ('' === $this->text($credentials, 'api_secret')) {
            $missing[] = 'api_secret';
        }

        return $missing;
    }

    /**
     * @param array<string, mixed> $credentials Provider credentials.
     */
    private function isSandbox(string $provider, array $credentials): bool
    {
        if ('shipstation_standalone' !== $provider) {
            return false;
        }

        // The standalone API marks sandbox keys with a TEST_ prefix.
        return str_starts_with($this->text($credentials, 'api_key'), 'TEST_');
    }

    /**
     * The provider's rate-limit headers, when it sends them.
     *
     * @return array<string, int>
     */
    private function rateLimits(HttpResponse $response): array
    {
        $limits = array();
        $remaining = $this->header($response, 'x-ratelimit-remaining', 'x-rate-limit-remaining');
        $limit = $this->header($response, 'x-ratelimit-limit', 'x-rate-limit-limit');

        if ('' !== $remaining) {
            $limits['requests_remaining'] = (int) $remaining;
        }

        if ('' !== $limit) {
            $limits['requests_limit'] = (int) $limit;
        }

        return $limits;
    }

    /**
     * A response header, trying each name case-insensitively.
     */
    private function header(HttpResponse $response, string ...$names): string
    {
        foreach ($response->headers as $name => $value) {
            if (in_array(strtolower($name), $names, true)) {
                return trim($value);
            }
        }

        return '';
    }

    /**
     * The decoded body, or an empty array when it is not a JSON object.
     *
     * @return array<string, mixed>
     */
    private function decode(HttpResponse $response): array
    {
        $decoded = json_decode($response->body, true);

        if (! is_array($decoded)) {
            return array();
        }

        $map = array();

        foreach ($decoded as $key => $value) {
            if (is_string($key)) {
                $map[$key] = $value;
            }
        }

        return $map;
    }

    // ─── Argument handling ───────────────────────────────────────────

    /**
     * The requested provider, or the raw value so the refusal can name it.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     */
    private function provider(array $arguments): string
    {
        $raw = $this->stringParam($arguments, 'provider', '');

        if ('' === $raw) {
            $raw = $this->stringParam($arguments, 'carrier', 'shipstation_standalone');
        }

        return $raw;
    }

    private function providerRefusal(string $requested): mixed
    {
        $replacement = self::LEGACY_PROVIDER_NAMES[$requested] ?? null;

        if (null !== $replacement) {
            return $this->errors->validationFailed(
                sprintf(
                    '"%s" is no longer a provider name: ShipEngine was rebranded as the '
                    . 'ShipStation API. Use "%s".',
                    $requested,
                    $replacement
                ),
                array('provider' => array(sprintf('Use "%s".', $replacement)))
            );
        }

        return $this->errors->validationFailed(
            sprintf(
                'Unknown provider "%s". Use %s.',
                $requested,
                implode(' or ', self::PROVIDERS)
            ),
            array('provider' => array('Unsupported provider.'))
        );
    }

    /**
     * Provider credentials: `credentials`, or the WordPress name as an alias.
     *
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return array<string, mixed>
     */
    private function credentials(array $arguments): array
    {
        $credentials = $this->objectParam($arguments, 'credentials');

        return array() === $credentials
            ? $this->objectParam($arguments, 'api_credentials')
            : $credentials;
    }

    /**
     * A canonical address, or null when it has no postal code.
     *
     * @param array<string, mixed> $raw Raw address argument.
     *
     * @return RateAddress|null
     */
    private function address(array $raw): ?array
    {
        $postalCode = $this->text($raw, 'postal_code');

        if ('' === $postalCode) {
            return null;
        }

        $country = strtoupper($this->text($raw, 'country_code', 'US'));

        return array(
            'name' => $this->text($raw, 'name'),
            'company' => $this->text($raw, 'company'),
            'phone' => $this->text($raw, 'phone'),
            'address_line1' => $this->text($raw, 'address_line1'),
            'address_line2' => $this->text($raw, 'address_line2'),
            'city' => $this->text($raw, 'city'),
            'state' => $this->text($raw, 'state'),
            'postal_code' => $postalCode,
            'country_code' => '' !== $country ? $country : 'US',
            'residential' => $this->boolParam($raw, 'residential'),
        );
    }

    /**
     * @param array<string, mixed> $arguments Tool arguments.
     *
     * @return list<PackBox>|null
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
     * A trimmed string field from a raw argument row.
     *
     * @param array<string, mixed> $row     Raw row.
     * @param string               $key     Field name.
     * @param string               $default Fallback when absent or not scalar.
     */
    private function text(array $row, string $key, string $default = ''): string
    {
        $value = isset($row[$key]) && is_scalar($row[$key]) ? trim((string) $row[$key]) : '';

        return '' !== $value ? $value : $default;
    }

    /**
     * A scalar as a string, or the empty string.
     */
    private function scalar(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * A currency code from the provider, upper-cased, defaulting to USD.
     */
    private function currency(mixed $value): string
    {
        $currency = strtoupper($this->scalar($value));

        return 1 === preg_match('/^[A-Z]{3}$/', $currency) ? $currency : 'USD';
    }
}
