<?php
/**
 * Credential resolution contract for database connections.
 *
 * Proposal 167 decision D5/A2. A {@see \Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor}
 * carries a **handle**, never a secret. The host resolves the handle at connect
 * time and drops the plaintext as soon as PDO has it.
 *
 * Keeping this behind an interface means the portable factory never links to
 * `WP_MCP_AI_Encryption`, and a Laravel host can bind a config- or Vault-backed
 * implementation without changing a line of the executor.
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

/**
 * Resolves a stored credential handle to a username and password.
 */
interface WP_MCP_AI_Db_Credential_Interface {

	/**
	 * Resolve a connection's credential.
	 *
	 * Implementations must throw {@see \Nvoos\Database\Domain\Service\Database\DatabaseRefusal}
	 * rather than returning empty strings: an unresolvable credential is a
	 * configuration failure the operator must see, not an anonymous connection.
	 *
	 * @param string $handle Stored credential handle.
	 * @return array{username: string, password: string} Plaintext, for PDO only.
	 * @throws \Nvoos\Database\Domain\Service\Database\DatabaseRefusal When the handle cannot be resolved.
	 */
	public function resolve( string $handle ): array;
}
