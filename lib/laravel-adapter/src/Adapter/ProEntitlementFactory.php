<?php
/**
 * Laravel adapter: Pro entitlement factory.
 *
 * The one place a `ProEntitlementInterface` is constructed from configuration.
 * Extracted in Proposal 149 T-0.8 so a host that resolves entitlement
 * per-estate (the Laravel admin reads each tenant's licence) can build the same
 * driver families without re-implementing the mapping — a second copy would
 * drift the moment a driver or an option is added.
 *
 * Driver families:
 *
 *   allow  — every feature entitled.
 *   deny   — nothing entitled.
 *   config — only features explicitly listed in `features`.
 *   license— resolve against the commercial licence server.
 *
 * Unknown drivers fail closed.
 *
 * @package Nvoos\Laravel
 * @since   1.4.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\ProEntitlementInterface;

final class ProEntitlementFactory {

	/**
	 * Build the entitlement for one installation (or one estate).
	 *
	 * @param array<string, mixed> $config            `oos.pro_entitlement`.
	 * @param string               $defaultInstanceId Fallback when `instance_id` is unset.
	 * @param string               $licenseKey        Overrides `license_key` (per estate).
	 */
	public static function make(
		array $config,
		string $defaultInstanceId = '',
		string $licenseKey = ''
	): ProEntitlementInterface {
		$driver = (string) ( $config['driver'] ?? 'allow' );
		$key    = '' !== trim( $licenseKey ) ? trim( $licenseKey ) : (string) ( $config['license_key'] ?? '' );

		if ( 'license' === $driver ) {
			return new LicensedProEntitlement(
				licenseKey: $key,
				serverUrl: (string) ( $config['server_url'] ?? '' ),
				instanceId: '' !== (string) ( $config['instance_id'] ?? '' )
					? (string) $config['instance_id']
					: $defaultInstanceId,
				product: (string) ( $config['product'] ?? 'mcp-ai-wpoos-pro' ),
				featureMap: (array) ( $config['features'] ?? array() ),
				ttl: (int) ( $config['ttl'] ?? 43200 ),
				deniedTtl: (int) ( $config['denied_ttl'] ?? 300 ),
				graceTtl: (int) ( $config['grace_ttl'] ?? 604800 ),
				timeout: (int) ( $config['timeout'] ?? 10 ),
			);
		}

		return new ProEntitlement(
			driver: $driver,
			plan: (string) ( $config['plan'] ?? 'compliance' ),
			featureMap: (array) ( $config['features'] ?? array() ),
		);
	}
}
