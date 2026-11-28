<?php
/**
 * WordPress binding of the host-neutral privilege probe.
 *
 * Proposal 167 amendment A1 and phase P4. The introspection itself lives in the
 * kernel as {@see \Nvoos\Database\Domain\Service\Database\PdoProbe}, because the
 * Laravel runtime needs the same three answers — reachable, can-write,
 * bypasses-RLS — and a second copy would be a second set of privilege queries to
 * keep in step with four engines.
 *
 * What remains here is the class name and the `run()` alias: the settings screen,
 * `db_test_connection` and the Postgres suite all call `run()`, and renaming a
 * method that has worked since P1 buys nothing.
 *
 * @package WP_MCP_AI
 * @since   3.1.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

use Nvoos\Database\Domain\Service\Database\PdoProbe;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;

/**
 * Privilege introspection.
 */
final class WP_MCP_AI_Db_Probe extends PdoProbe {

	/**
	 * Probe a connection.
	 *
	 * @param ConnectionDescriptor $connection Descriptor.
	 */
	public function run( ConnectionDescriptor $connection ): ProbeResult {
		return $this->probe( $connection );
	}
}
