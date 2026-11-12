<?php
/**
 * Pro entitlement contract for the oOS core.
 *
 * Abstracts the commercial entitlement check so Pro surfaces never depend on
 * WordPress options, a licence server, or a gateway client directly. The
 * WordPress adapter reads the plugin's licence record; the Laravel adapter is
 * configuration-driven until a commercial source is chosen (Proposal 090-B,
 * ADR-7).
 *
 * @package Nvoos\Core
 * @since   1.2.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Domain\Contract;

interface ProEntitlementInterface {

	/**
	 * Whether the given feature is entitled on this runtime.
	 *
	 * Implementations must fail closed for unknown drivers and unknown
	 * features in `config` mode. A missing commercial source is not an
	 * excuse to grant a paid surface.
	 *
	 * @param string $feature Feature key (e.g. "commerce", "media_ops").
	 */
	public function allows( string $feature ): bool;

	/**
	 * The active plan label (e.g. "compliance", "agency").
	 */
	public function plan(): string;

	/**
	 * The entitlement source identifier (e.g. "allow", "deny", "config",
	 * "wp-license", "gateway"). Surfaced in diagnostics so operators can see
	 * why a surface is available.
	 */
	public function source(): string;

	/**
	 * The full feature map, for diagnostics and the console feature payload.
	 *
	 * @return array<string, bool>
	 */
	public function features(): array;
}
