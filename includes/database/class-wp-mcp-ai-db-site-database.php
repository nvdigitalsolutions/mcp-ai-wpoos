<?php
/**
 * Refuses the application's own database as a scoped connection.
 *
 * Proposal 167 section 167.6.5. A scoped connection that pointed at the
 * WordPress database would let a scope minted for a reporting schema read
 * `wp_users`, `wp_options` (and therefore every stored provider key) and the
 * session tokens. The refusal is structural: it is evaluated from the stored
 * record's `(host, port, database)` tuple and compares it with the site's own
 * constants, and there is no scope-level override — only the connection record's
 * `allow_site_db` flag plus an explicit administrator confirmation.
 *
 * The check runs twice: when a record is saved, so an operator sees the problem
 * while editing it, and again immediately before a connection is opened, so a
 * `wp-config.php` change after the fact cannot turn a benign record into a
 * dangerous one.
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

use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;

/**
 * Compares a descriptor with the WordPress database endpoint.
 */
final class WP_MCP_AI_Db_Site_Database {

	/**
	 * Host aliases that all mean "this server".
	 *
	 * @var list<string>
	 */
	private const LOCAL_HOSTS = array( 'localhost', '127.0.0.1', '::1', '[::1]' );

	/**
	 * Whether a descriptor resolves to the WordPress database.
	 *
	 * The database-name comparison is case-insensitive because MySQL lowercases
	 * schema names on some filesystems and PostgreSQL folds unquoted identifiers.
	 *
	 * @param ConnectionDescriptor $connection Descriptor to test.
	 */
	public static function matches( ConnectionDescriptor $connection ): bool {
		if ( ConnectionDescriptor::SQLITE === $connection->engine ) {
			return false;
		}

		$site_host = defined( 'DB_HOST' ) ? (string) DB_HOST : '';
		$site_name = defined( 'DB_NAME' ) ? (string) DB_NAME : '';

		if ( '' === $site_host || '' === $site_name ) {
			return false;
		}

		if ( 0 !== strcasecmp( trim( $connection->database ), trim( $site_name ) ) ) {
			return false;
		}

		list( $host, $port ) = self::split_host( $site_host );

		return self::same_host( $connection->host, $host )
			&& self::same_port( $connection->port, $port );
	}

	/**
	 * Refuse a descriptor that resolves to the site database.
	 *
	 * @param ConnectionDescriptor $connection Descriptor to test.
	 * @throws DatabaseRefusal When the descriptor is the site database and the override is absent.
	 */
	public static function assert_not_site_database( ConnectionDescriptor $connection ): void {
		if ( ! self::matches( $connection ) ) {
			return;
		}

		if ( $connection->allowSiteDb ) {
			return;
		}

		throw DatabaseRefusal::of(
			RefusalCode::SITE_DATABASE_REFUSED,
			'Connection ' . $connection->id . ' resolves to the WordPress database.'
		);
	}

	/**
	 * Split a `host:port` constant.
	 *
	 * `DB_HOST` may be `localhost`, `localhost:3306`, `host:/path/to.sock` or an
	 * IPv6 literal. Only the first two forms carry a comparable port; a socket
	 * path compares as "no port".
	 *
	 * @param string $value Raw `DB_HOST`.
	 * @return array{0: string, 1: int} Host and port (0 when absent).
	 */
	private static function split_host( string $value ): array {
		$value = trim( $value );

		if ( '' === $value ) {
			return array( '', 0 );
		}

		if ( preg_match( '/^(.+):(\d+)$/', $value, $matches ) ) {
			return array( $matches[1], (int) $matches[2] );
		}

		return array( $value, 0 );
	}

	/**
	 * Whether two host strings name the same endpoint.
	 *
	 * @param string $left  Record host.
	 * @param string $right Site host.
	 */
	private static function same_host( string $left, string $right ): bool {
		$left  = strtolower( trim( $left ) );
		$right = strtolower( trim( $right ) );

		if ( $left === $right ) {
			return true;
		}

		return in_array( $left, self::LOCAL_HOSTS, true ) && in_array( $right, self::LOCAL_HOSTS, true );
	}

	/**
	 * Whether two ports are compatible.
	 *
	 * A missing port on either side means "the engine default", which matches any
	 * explicit port for the same host and database: the tuple is already specific
	 * enough that a false positive here only forces an operator to tick the
	 * override box.
	 *
	 * @param int $left  Record port.
	 * @param int $right Site port.
	 */
	private static function same_port( int $left, int $right ): bool {
		if ( 0 === $left || 0 === $right ) {
			return true;
		}

		return $left === $right;
	}
}
