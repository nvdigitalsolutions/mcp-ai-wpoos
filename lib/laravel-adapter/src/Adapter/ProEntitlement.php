<?php
/**
 * Laravel adapter: Pro entitlement.
 *
 * Configuration-driven implementation of ProEntitlementInterface. The
 * WordPress licence record is not available outside WordPress, so the
 * Laravel runtime selects a driver:
 *
 *   - allow  : every feature entitled (development, CI, an already-licensed
 *              private deployment that is enforced elsewhere).
 *   - deny   : no feature entitled (base-only and fail-closed deployments).
 *   - config : only features explicitly enabled in
 *              `oos.pro_entitlement.features`.
 *
 * Unknown drivers fail closed. The commercial source (the existing licence
 * server with a Laravel instance ID, or the gateway/commerce entitlement) is
 * wired in a later phase behind this same contract — no caller changes
 * (Proposal 090-B, ADR-7).
 *
 * @package Nvoos\Laravel
 * @since   1.2.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Laravel\Adapter;

use Nvoos\Core\Domain\Contract\ProEntitlementInterface;

class ProEntitlement implements ProEntitlementInterface {

	/**
	 * @param string               $driver     Entitlement driver: allow|deny|config.
	 * @param string               $plan       Plan label for diagnostics.
	 * @param array<string, bool>  $featureMap Feature flags for the config driver.
	 */
	public function __construct(
		private readonly string $driver = 'allow',
		private readonly string $plan = 'compliance',
		private readonly array $featureMap = array(),
	) {}

	/**
	 * {@inheritDoc}
	 */
	public function allows( string $feature ): bool {
		return match ( $this->driver ) {
			'allow' => true,
			'deny' => false,
			'config' => (bool) ( $this->featureMap[ $feature ] ?? false ),
			default => false,
		};
	}

	/**
	 * {@inheritDoc}
	 */
	public function plan(): string {
		return $this->plan;
	}

	/**
	 * {@inheritDoc}
	 */
	public function source(): string {
		return $this->driver;
	}

	/**
	 * {@inheritDoc}
	 */
	public function features(): array {
		return $this->featureMap;
	}
}
