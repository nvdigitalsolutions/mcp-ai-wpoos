<?php

/**
 * Error-mapping tests.
 *
 * The mapping is the boundary between "the database said no" and the toolkit's
 * closed vocabulary. It is data, so every row is asserted; the message is never
 * read, so a driver message cannot reach a caller.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\ErrorMapper;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use PDOException;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ErrorMapperTest extends TestCase
{
    private ErrorMapper $mapper;

    protected function setUp(): void
    {
        $this->mapper = new ErrorMapper();
    }

    #[DataProvider('pgsqlStates')]
    public function test_it_maps_postgres_sqlstates(string $state, RefusalCode $expected): void
    {
        self::assertSame($expected, $this->mapper->fromPgsql($state));
    }

    public static function pgsqlStates(): array
    {
        return array(
            'read only' => array( '25006', RefusalCode::READ_ONLY_VIOLATION ),
            'privilege' => array( '42501', RefusalCode::PRIVILEGE_DENIED ),
            'timeout' => array( '57014', RefusalCode::TIMEOUT ),
            'lock' => array( '55P03', RefusalCode::LOCK_TIMEOUT ),
            'missing table' => array( '42P01', RefusalCode::RELATION_NOT_FOUND ),
            'missing column' => array( '42703', RefusalCode::COLUMN_NOT_FOUND ),
            'bad password' => array( '28P01', RefusalCode::AUTH_FAILED ),
            'unknown database' => array( '3D000', RefusalCode::UNKNOWN_DATABASE ),
            'too many connections' => array( '53300', RefusalCode::CONNECTION_LIMIT ),
            'connection failure' => array( '08006', RefusalCode::CONNECTION_UNAVAILABLE ),
            'multi command' => array( '42601', RefusalCode::STATEMENT_NOT_ADMITTED ),
            'lowercase is normalised' => array( '42501', RefusalCode::PRIVILEGE_DENIED ),
            'unknown state' => array( 'XX000', RefusalCode::CONNECTION_UNAVAILABLE ),
        );
    }

    #[DataProvider('mysqlCodes')]
    public function test_it_maps_mysql_driver_codes(int $code, RefusalCode $expected): void
    {
        self::assertSame($expected, $this->mapper->fromMysql($code));
    }

    public static function mysqlCodes(): array
    {
        return array(
            'table denied' => array( 1142, RefusalCode::PRIVILEGE_DENIED ),
            'column denied' => array( 1143, RefusalCode::PRIVILEGE_DENIED ),
            'db denied' => array( 1044, RefusalCode::PRIVILEGE_DENIED ),
            'bad credentials' => array( 1045, RefusalCode::AUTH_FAILED ),
            'unknown db' => array( 1049, RefusalCode::UNKNOWN_DATABASE ),
            'missing table' => array( 1146, RefusalCode::RELATION_NOT_FOUND ),
            'missing column' => array( 1054, RefusalCode::COLUMN_NOT_FOUND ),
            'query timeout' => array( 3024, RefusalCode::TIMEOUT ),
            'lock wait' => array( 1205, RefusalCode::LOCK_TIMEOUT ),
            'connection limit' => array( 1040, RefusalCode::CONNECTION_LIMIT ),
            'server gone' => array( 2006, RefusalCode::CONNECTION_UNAVAILABLE ),
            'unknown code' => array( 9999, RefusalCode::CONNECTION_UNAVAILABLE ),
        );
    }

    public function test_it_maps_a_pdo_exception_by_sqlstate_on_postgres(): void
    {
        $error = new PDOException('permission denied for table orders');
        $error->errorInfo = array( '42501', 7, 'permission denied for table orders' );

        self::assertSame(RefusalCode::PRIVILEGE_DENIED, $this->mapper->fromThrowable($error, 'pgsql'));
    }

    public function test_it_maps_a_pdo_exception_by_driver_code_on_mysql(): void
    {
        $error = new PDOException('denied');
        $error->errorInfo = array( '42000', 1142, 'SELECT command denied' );

        self::assertSame(RefusalCode::PRIVILEGE_DENIED, $this->mapper->fromThrowable($error, 'mysql'));
    }

    public function test_a_non_pdo_failure_is_a_connection_failure(): void
    {
        self::assertSame(
            RefusalCode::CONNECTION_UNAVAILABLE,
            $this->mapper->fromThrowable(new \RuntimeException('boom'), 'pgsql')
        );
    }

    public function test_the_audit_detail_carries_the_state_but_not_the_message(): void
    {
        $error = new PDOException('permission denied for table secret_orders');
        $error->errorInfo = array( '42501', 7, 'permission denied for table secret_orders' );

        $detail = $this->mapper->auditDetail($error);

        self::assertStringContainsString('sqlstate=42501', $detail);
        self::assertStringNotContainsString('secret_orders', $detail);
    }

    public function test_retryability_is_limited_to_transient_conditions(): void
    {
        self::assertTrue(RefusalCode::LOCK_TIMEOUT->isRetryable());
        self::assertTrue(RefusalCode::CONNECTION_UNAVAILABLE->isRetryable());
        self::assertFalse(RefusalCode::PRIVILEGE_DENIED->isRetryable());
        self::assertFalse(RefusalCode::STATEMENT_NOT_ADMITTED->isRetryable());
    }
}
