<?php

/**
 * Admission classifier tests.
 *
 * This is the layer-2 suite. It pins the bypass families the 2026 research
 * documents — stacked statements, transaction escape, `SET`, CTE chaining, file
 * and OS functions — and, just as importantly, pins the cases that must **not** be
 * refused, because a classifier that trips on string literals would be turned off
 * in production within a week.
 *
 * Gate 6 of the implementation plan (removing layer 2 entirely and proving the
 * role still refuses) lives in the WordPress/Postgres integration suite, not here:
 * this class cannot prove a database denied anything.
 *
 * @package Nvoos\Database\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Database\Tests\Unit\Domain\Service\Database;

use Nvoos\Database\Domain\Service\Database\AdmissionClassifier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AdmissionClassifierTest extends TestCase
{
    use RefusalAssertions;

    private AdmissionClassifier $classifier;

    protected function setUp(): void
    {
        $this->classifier = new AdmissionClassifier();
    }

    #[DataProvider('refusedStatements')]
    public function test_it_refuses(string $sql, string $engine = 'pgsql'): void
    {
        $this->assertRefusal(
            RefusalCode::STATEMENT_NOT_ADMITTED,
            fn (): mixed => $this->classifier->admit($sql, $engine)
        );
    }

    public static function refusedStatements(): array
    {
        return array(
            'empty' => array( "   \n\t " ),
            'comment only' => array( '-- nothing here' ),
            'stacked drop' => array( 'SELECT 1; DROP TABLE x' ),
            'stacked after comment' => array( "SELECT 1; -- ok\nDELETE FROM t" ),
            'bare commit' => array( 'COMMIT' ),
            'select then commit' => array( 'SELECT 1; COMMIT' ),
            'rollback' => array( 'ROLLBACK' ),
            'cte delete' => array( 'WITH d AS (DELETE FROM t RETURNING *) SELECT * FROM d' ),
            'cte update' => array( 'WITH u AS (UPDATE t SET a = 1 RETURNING *) SELECT * FROM u' ),
            'set role' => array( 'SET ROLE app_admin' ),
            'session authorization' => array( 'SET SESSION AUTHORIZATION admin' ),
            'reset role' => array( 'RESET ROLE' ),
            'copy from program' => array( "COPY t FROM PROGRAM 'id'" ),
            'pg_read_file' => array( "SELECT pg_read_file('/etc/passwd')" ),
            'lo_import' => array( "SELECT lo_import('/tmp/x')" ),
            'dblink' => array( "SELECT * FROM dblink('x','y') AS t(a int)" ),
            'pg_authid' => array( 'SELECT * FROM pg_authid' ),
            'pg_shadow' => array( 'SELECT * FROM pg_shadow' ),
            'pg_catalog' => array( 'SELECT * FROM pg_catalog.pg_class' ),
            'information_schema' => array( 'SELECT * FROM information_schema.tables' ),
            'pg_sleep' => array( 'SELECT pg_sleep(10)' ),
            'update' => array( 'UPDATE t SET a = 1' ),
            'insert' => array( 'INSERT INTO t VALUES (1)' ),
            'truncate' => array( 'TRUNCATE t' ),
            'ddl' => array( 'CREATE TABLE x (a int)' ),
            'grant' => array( 'GRANT SELECT ON t TO PUBLIC' ),
            'explain preview' => array( 'EXPLAIN SELECT 1' ),
            'mysql load_file' => array( "SELECT LOAD_FILE('/etc/passwd')", 'mysql' ),
            'mysql outfile' => array( "SELECT * FROM t INTO OUTFILE '/tmp/x'", 'mysql' ),
            'mysql sleep' => array( 'SELECT SLEEP(5)', 'mariadb' ),
        );
    }

    #[DataProvider('admittedStatements')]
    public function test_it_admits(string $sql): void
    {
        $this->classifier->admit($sql, 'pgsql');

        // admit() returns void and throws on refusal; reaching here is the assertion.
        self::assertTrue(true);
    }

    public static function admittedStatements(): array
    {
        return array(
            'plain select' => array( 'SELECT 1' ),
            'trailing semicolon' => array( 'SELECT 1;' ),
            'trailing semicolon and space' => array( 'SELECT 1 ; ' ),
            'keyword in literal' => array( "SELECT 'commit' AS word" ),
            'semicolon in literal' => array( "SELECT * FROM t WHERE label = 'a;b'" ),
            'keyword in quoted identifier' => array( 'SELECT "delete" FROM t' ),
            'keyword in dollar-quoted body' => array( 'SELECT $tag$ delete $tag$ AS body' ),
            'line comment' => array( "SELECT 1 -- trailing comment" ),
            'block comment' => array( 'SELECT /* note */ 1' ),
            'nested block comment' => array( 'SELECT /* outer /* inner */ still */ 1' ),
            'cte select' => array( 'WITH x AS (SELECT 1 AS a) SELECT a FROM x' ),
            'aggregate' => array( 'SELECT count(*) FROM reporting.orders' ),
            'window function' => array( 'SELECT row_number() OVER (ORDER BY a) FROM t' ),
            'qualified schema' => array( 'SELECT a FROM reporting.orders' ),
        );
    }

    public function test_normalisation_blanks_literals_and_comments(): void
    {
        $normalised = $this->classifier->normalise("SELECT 'a;b', \"c;d\", \$\$e;f\$\$ /* g;h */ -- i;j");

        self::assertStringNotContainsString(';', $normalised);
        self::assertStringNotContainsString('e;f', $normalised);
    }

    public function test_normalisation_is_case_insensitive_for_matching(): void
    {
        self::assertStringStartsWith('select', $this->classifier->normalise('  SELECT 1'));
    }

    public function test_an_unterminated_literal_does_not_hang(): void
    {
        // A truncated payload must terminate, not spin. It is admitted as a single
        // SELECT; the driver will reject the malformed statement.
        $this->classifier->admit("SELECT 'unterminated", 'pgsql');

        self::assertTrue(true);
    }
}
