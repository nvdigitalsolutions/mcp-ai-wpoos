<?php

/**
 * Engine-profile parity across hosts (Proposal 167 gate 9, session-SQL half).
 *
 * The Laravel host carries its own copy of the four engine profiles. That
 * duplication is only acceptable because this test asserts the two produce
 * byte-identical statements — same SQL text, same bound values, same order —
 * for every engine, with and without a tenant, at several timeouts.
 *
 * If a future change touches one copy, this fails. That is the entire reason the
 * copy is allowed to exist.
 *
 * @package Nvoos\Tests
 * @since   3.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Tests\Database\Parity;

use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\ValueObject\Database\CompiledStatement;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\DatabaseLimits;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Laravel\Database\Engine\AbstractProfile;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EngineParityTest extends TestCase
{
    /**
     * @return array<string, array{0: class-string, 1: string}>
     */
    public static function engines(): array
    {
        return array(
            'pgsql'   => array('WP_MCP_AI_Db_Engine_Pgsql', 'pgsql'),
            'mysql'   => array('WP_MCP_AI_Db_Engine_Mysql', 'mysql'),
            'mariadb' => array('WP_MCP_AI_Db_Engine_Mariadb', 'mariadb'),
            'sqlite'  => array('WP_MCP_AI_Db_Engine_Sqlite', 'sqlite'),
        );
    }

    #[DataProvider('engines')]
    public function test_the_profile_name_matches(string $wordpress, string $engine): void
    {
        $this->assertSame($engine, (new $wordpress())->name());
        $this->assertSame($engine, AbstractProfile::for($this->connection($engine))->name());
    }

    #[DataProvider('engines')]
    public function test_the_begin_statement_matches(string $wordpress, string $engine): void
    {
        $this->assertSame(
            (new $wordpress())->begin_statement(),
            AbstractProfile::for($this->connection($engine))->begin_statement(),
            'BEGIN drift on ' . $engine,
        );
    }

    /**
     * @return array<string, array{0: string, 1: int, 2: string|null}>
     */
    public static function preambleCases(): array
    {
        $cases = array();

        foreach (array('pgsql', 'mysql', 'mariadb', 'sqlite') as $engine) {
            foreach (array(1500, 5000, 30000) as $timeout) {
                foreach (array(null, 'tenant-a') as $tenant) {
                    $label = $engine . ':' . $timeout . ':' . ($tenant ?? 'none');

                    $cases[$label] = array($engine, $timeout, $tenant);
                }
            }
        }

        return $cases;
    }

    #[DataProvider('preambleCases')]
    public function test_the_session_preamble_matches(string $engine, int $timeout, ?string $tenant): void
    {
        $wordpress = $this->wordpressEngine($engine);
        $laravel   = AbstractProfile::for($this->connection($engine));

        $this->assertSame(
            $this->normalise($wordpress->session_statements(
                $this->connection($engine),
                $this->scope($engine),
                new DatabaseLimits(timeoutMs: $timeout),
                'nvoos:analyst:42',
                $tenant,
            )),
            $this->normalise($laravel->session_statements(
                $this->connection($engine),
                $this->scope($engine),
                new DatabaseLimits(timeoutMs: $timeout),
                'nvoos:analyst:42',
                $tenant,
            )),
            'Session preamble drift on ' . $engine . ' at ' . $timeout . 'ms',
        );
    }

    #[DataProvider('engines')]
    public function test_the_explain_wrapping_matches(string $wordpress, string $engine): void
    {
        $statement = new CompiledStatement(
            'SELECT "id" FROM "reporting"."orders" WHERE "status" = :p0',
            array(':p0' => 'paid'),
        );

        foreach (array(false, true) as $analyze) {
            $expected = (new $wordpress())->explain_statement($statement, $analyze);
            $actual   = AbstractProfile::for($this->connection($engine))->explain_statement($statement, $analyze);

            $this->assertSame($expected->sql, $actual->sql, 'EXPLAIN drift on ' . $engine);
            $this->assertSame($expected->bindings, $actual->bindings, 'EXPLAIN bindings drift on ' . $engine);

            $this->assertSame(
                (new $wordpress())->supports_explain_analyze($this->connection($engine)),
                AbstractProfile::for($this->connection($engine))->supports_explain_analyze($this->connection($engine)),
                'ANALYZE support drift on ' . $engine,
            );
        }
    }

    /**
     * Reduce a statement list to comparable scalars.
     *
     * Bindings are compared as strings because the WordPress MySQL profile binds
     * an integer timeout while the Laravel copy does the same — a strict `===`
     * on `array(':p0' => 5000)` would pass, but normalising keeps the failure
     * message readable when it does not.
     *
     * @param list<CompiledStatement> $statements Statements.
     * @return list<array{sql: string, bindings: array<string, mixed>}>
     */
    private function normalise(array $statements): array
    {
        return array_map(
            static fn (CompiledStatement $statement): array => array(
                'sql'      => $statement->sql,
                'bindings' => $statement->bindings,
            ),
            $statements,
        );
    }

    private function wordpressEngine(string $engine): object
    {
        $classes = array(
            'pgsql'   => 'WP_MCP_AI_Db_Engine_Pgsql',
            'mysql'   => 'WP_MCP_AI_Db_Engine_Mysql',
            'mariadb' => 'WP_MCP_AI_Db_Engine_Mariadb',
            'sqlite'  => 'WP_MCP_AI_Db_Engine_Sqlite',
        );

        $class = $classes[$engine];

        return new $class();
    }

    private function connection(string $engine): ConnectionDescriptor
    {
        return new ConnectionDescriptor(
            id: 'reporting',
            label: 'Reporting',
            engine: $engine,
            host: '127.0.0.1',
            port: 5432,
            database: $engine === 'sqlite' ? '/tmp/reporting.sqlite' : 'warehouse',
            sslmode: 'require',
            credential: 'secret',
        );
    }

    private function scope(string $engine): ScopeDescriptor
    {
        return new ScopeDescriptor(
            id: 'analyst',
            label: 'Analyst',
            connection: $engine === 'sqlite' ? 'reporting' : 'reporting',
            schemas: array('reporting'),
            tables: array('*'),
            tiers: array(GrantTier::METADATA, GrantTier::READ),
        );
    }
}
