<?php
/**
 * Connection provider contract.
 *
 * Proposal 167 decision D11. The toolkit has to answer one question the plugin
 * already answers twice, differently: "which external thing does this assistant
 * have access to?" The native database records are new; the Remote Sites records
 * already exist. Rather than teach every tool about both stores, a provider
 * projects one store into a single vocabulary.
 *
 * A provider is deliberately read-only. Authoring a connection happens in the
 * store that owns it (the database settings page, or the Remote Sites admin), so
 * there is no way for a provider to invent a connection that has no operator
 * record behind it.
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

use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;

/**
 * Projects a connection store into descriptors.
 */
interface WP_MCP_AI_Connection_Provider_Interface {

	/**
	 * Provider id, matching the id namespace prefix.
	 *
	 * `native` owns `db:…` ids, `remote_site` owns `rs:…` ids.
	 */
	public function id(): string;

	/**
	 * The id prefix, without the colon.
	 */
	public function prefix(): string;

	/**
	 * Connections this principal may use, redacted.
	 *
	 * @param PrincipalRef $principal Principal.
	 * @return list<ConnectionDescriptor>
	 */
	public function list_connections( PrincipalRef $principal ): array;

	/**
	 * Whether this provider owns an id.
	 *
	 * @param string $id Namespaced or bare id.
	 */
	public function supports( string $id ): bool;

	/**
	 * Resolve an id to a descriptor.
	 *
	 * @param string       $id        Namespaced or bare id.
	 * @param PrincipalRef $principal Principal.
	 * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal When the id is unknown or not visible.
	 */
	public function resolve( string $id, PrincipalRef $principal ): ConnectionDescriptor;
}
