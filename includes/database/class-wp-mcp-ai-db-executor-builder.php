<?php
/**
 * Assembles the WordPress executor from the adapter layer.
 *
 * Proposal 167. `PdoExecutor` needs seven collaborators; before this class two
 * call sites built them inline (the Pro tool base for every `db_*` tool, and the
 * console controller for a preview). Three copies of "which cache, which secret,
 * which tenant" is exactly how a host half-drifts, so the assembly lives here
 * once and both call it.
 *
 * The three value helpers are public because the write tools still construct
 * their own writer with the same roots, secret and tenant — they keep their
 * behaviour, not a second copy of it.
 *
 * @package WP_MCP_AI
 * @since   3.2.0
 * @author    NV Digital Solutions
 * @copyright Copyright (c) 2025-2026 NV Digital Solutions
 * @license   GPL-3.0-or-later
 *
 * SPDX-License-Identifier: GPL-3.0-or-later
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( ! class_exists( 'WP_MCP_AI_Db_Executor_Builder' ) ) {
	/**
	 * One place that knows how the executor is wired on WordPress.
	 */
	final class WP_MCP_AI_Db_Executor_Builder {

		/**
		 * Build the executor with the site's collaborators.
		 *
		 * No principal is bound: callers use `for_principal()` (which clones) so
		 * a shared instance can never answer for the wrong assistant. The audit
		 * log is the bounded WordPress ring, not the Laravel ledger.
		 */
		public static function build(): WP_MCP_AI_Db_Executor {
			$scopes      = new WP_MCP_AI_Db_Scope_Registry();
			$connections = new WP_MCP_AI_Db_Connection_Registry( null, $scopes );
			$factory     = new WP_MCP_AI_Db_Connection_Factory(
				new WP_MCP_AI_Db_Encrypted_Credential_Store(),
				self::sqlite_roots()
			);
			$catalogue   = new WP_MCP_AI_Db_Catalogue( $factory, new WP_MCP_AI_Db_Transient_Cache() );

			return new WP_MCP_AI_Db_Executor(
				$connections,
				$scopes,
				$catalogue,
				$factory,
				new WP_MCP_AI_Db_Audit_Log(),
				new WP_MCP_AI_Db_Redactor(),
				self::cursor_secret(),
				new WP_MCP_AI_Db_Proposal_Store(),
				self::tenant_resolver()
			);
		}

		/**
		 * Directories a sqlite connection may point into.
		 *
		 * Empty by default, which makes the factory refuse every sqlite path —
		 * the safe default for a file-backed engine that can read the site.
		 *
		 * @return list<string>
		 */
		public static function sqlite_roots(): array {
			if ( defined( 'WP_MCP_AI_DB_SQLITE_ROOTS' ) && is_array( WP_MCP_AI_DB_SQLITE_ROOTS ) ) {
				return array_values( array_map( 'strval', WP_MCP_AI_DB_SQLITE_ROOTS ) );
			}

			return array();
		}

		/**
		 * Cursor signing secret.
		 *
		 * Derived from the master key where available, so a cursor signed on one
		 * node verifies on another behind a shared database, and rotating the key
		 * invalidates outstanding cursors (which is the desired behaviour).
		 */
		public static function cursor_secret(): string {
			if ( class_exists( 'WP_MCP_AI_Encryption' ) ) {
				$key = WP_MCP_AI_Encryption::get_master_key();

				if ( is_string( $key ) && '' !== $key ) {
					return hash( 'sha256', $key . '|database-cursor' );
				}
			}

			return hash( 'sha256', wp_salt( 'auth' ) . '|database-cursor' );
		}

		/**
		 * The tenant resolver.
		 *
		 * Decision D4: reuse the estate vocabulary and never accept a tenant id as
		 * a tool argument. A host integration supplies the value through a filter;
		 * with no resolver the tenant-sourced row filter refuses, which is safe.
		 */
		public static function tenant_resolver(): Closure {
			return static function (): ?string {
				/**
				 * The current tenant for a database call.
				 *
				 * @since 3.1.0
				 *
				 * @param string|null $tenant Tenant id.
				 */
				$tenant = apply_filters( 'wp_mcp_ai_database_tenant_id', null );

				return is_string( $tenant ) && '' !== trim( $tenant ) ? $tenant : null;
			};
		}
	}
}
