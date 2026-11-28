<?php

/**
 * The Laravel-side database tools.
 *
 * Proposal 167 phase P4, task 4.4. These tests run without a database and
 * without Laravel: the port is a recording double, because the questions are
 * about the tool layer only — did it build the right query object, did it shape
 * the answer the way the WordPress host does, and did it turn a kernel refusal
 * into the same machine-readable error.
 *
 * What is *not* covered here, and is covered elsewhere:
 *
 *  - the guarantee that a write cannot happen — `tests/database/postgres/`,
 *    against a live PostgreSQL with a SELECT-only role;
 *  - contract parity with the WordPress tools — `tests/database/parity/`,
 *    which compares slug, schema and refusal mapping class-by-class.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool\Database;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tests\Support\FakeDatabasePort;
use Nvoos\Core\Tool\Database\AggregateTool;
use Nvoos\Core\Tool\Database\DescribeTool;
use Nvoos\Core\Tool\Database\ExplainTool;
use Nvoos\Core\Tool\Database\ListConnectionsTool;
use Nvoos\Core\Tool\Database\ProfileTableTool;
use Nvoos\Core\Tool\Database\QueryTool;
use Nvoos\Core\Tool\Database\SearchTool;
use Nvoos\Core\Tool\Database\SelectTool;
use Nvoos\Database\Domain\Service\Database\DatabaseRefusal;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\CatalogueDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ColumnDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\TableDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(ListConnectionsTool::class)]
#[CoversClass(DescribeTool::class)]
#[CoversClass(SelectTool::class)]
#[CoversClass(AggregateTool::class)]
#[CoversClass(SearchTool::class)]
#[CoversClass(ExplainTool::class)]
#[CoversClass(QueryTool::class)]
#[CoversClass(ProfileTableTool::class)]
final class DatabaseToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private FakeDatabasePort $port;

    protected function setUp(): void
    {
        $this->errors = new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array('error' => array('code' => $code, 'message' => $message, 'data' => $data));
            }

            public function isError(mixed $value): bool
            {
                return is_array($value) && isset($value['error']);
            }

            public function normalize(mixed $error): array
            {
                return is_array($error) ? (array) ($error['error'] ?? $error) : array();
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, array('errors' => $errors));
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message, array('retry_after' => $retryAfterSeconds));
            }
        };

        $this->port = new FakeDatabasePort();
    }

    public function test_list_connections_is_redacted(): void
    {
        $this->port->connections = array($this->connection());

        $payload = (new ListConnectionsTool($this->errors, $this->port))->execute(array(), array('user_id' => 7));

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame(1, $payload['count']);
        $this->assertSame('reporting', $payload['connections'][0]['id']);
        $this->assertSame('pgsql', $payload['connections'][0]['engine']);

        foreach (array('host', 'port', 'database', 'credential', 'read_replica', 'sslrootcert') as $forbidden) {
            $this->assertArrayNotHasKey($forbidden, $payload['connections'][0]);
        }
    }

    public function test_select_builds_a_query_object_and_shapes_the_result(): void
    {
        $this->port->connections = array($this->connection());
        $this->port->result      = new ResultSet(
            columns: array('id'),
            rows: array(array('id' => 1)),
            rowCount: 1,
            elapsedMs: 3,
            auditId: 'a1',
        );

        $payload = (new SelectTool($this->errors, $this->port))->execute(
            array(
                'relation' => 'reporting.orders',
                'columns'  => array('ID'),
                'filters'  => array(
                    array('column' => 'status', 'op' => 'eq', 'value' => 'paid'),
                ),
                'order'    => array(
                    array('column' => 'id', 'direction' => 'DESC'),
                ),
                'limit'    => 10,
            ),
            array('user_id' => 7),
        );

        $call = $this->port->last('select');

        $this->assertNotNull($call);
        $this->assertSame('reporting', $call[1]);
        $this->assertSame('', $call[2]);
        $this->assertSame('reporting.orders', $call[3]->relation);
        $this->assertSame(array('id'), $call[3]->columns);
        $this->assertSame('desc', $call[3]->order[0]->direction);
        $this->assertSame(10, $call[3]->limit);

        $this->assertIsArray($payload);
        $this->assertTrue($payload['success']);
        $this->assertSame(array('id'), $payload['columns']);
        $this->assertSame(1, $payload['row_count']);
        $this->assertSame('reporting.orders', $payload['relation']);
        $this->assertSame('reporting', $payload['connection']);
    }

    public function test_several_connections_require_an_explicit_id(): void
    {
        $this->port->connections = array($this->connection(), $this->connection('crm'));

        $refused = (new SelectTool($this->errors, $this->port))->execute(
            array('relation' => 'reporting.orders'),
            array('user_id' => 7),
        );

        $this->assertSame('db_unknown_connection', $refused['error']['data']['refusal_code']);
        $this->assertSame(404, $refused['error']['data']['status']);

        $this->port->result = new ResultSet(columns: array('id'), rows: array(), rowCount: 0);

        $accepted = (new SelectTool($this->errors, $this->port))->execute(
            array('relation' => 'reporting.orders', 'connection' => 'CRM'),
            array('user_id' => 7),
        );

        $this->assertSame('crm', $this->port->last('select')[1]);
        $this->assertTrue($accepted['success']);
    }

    public function test_unknown_metric_is_refused_with_the_kernel_code(): void
    {
        $this->port->connections = array($this->connection());

        $refused = (new AggregateTool($this->errors, $this->port))->execute(
            array(
                'relation' => 'reporting.orders',
                'metrics'  => array(array('metric' => 'p99', 'column' => 'total')),
            ),
            array('user_id' => 7),
        );

        $this->assertSame('db_unsupported_metric', $refused['error']['data']['refusal_code']);
        $this->assertSame(400, $refused['error']['data']['status']);
        $this->assertNull($this->port->last('aggregate'));
    }

    public function test_aggregate_accepts_the_closed_metric_set(): void
    {
        $this->port->connections = array($this->connection());

        (new AggregateTool($this->errors, $this->port))->execute(
            array(
                'relation' => 'reporting.orders',
                'group_by' => array('status'),
                'metrics'  => array(
                    array('metric' => 'count'),
                    array('metric' => 'sum', 'column' => 'total', 'alias' => 'revenue'),
                ),
            ),
            array('user_id' => 7),
        );

        $query = $this->port->last('aggregate')[3];

        $this->assertSame(array('status'), $query->groupBy);
        $this->assertCount(2, $query->metrics);
        $this->assertSame('revenue', $query->metrics[1]->alias);
    }

    public function test_search_echoes_the_sanitised_term(): void
    {
        $this->port->connections = array($this->connection());

        $payload = (new SearchTool($this->errors, $this->port))->execute(
            array('relation' => 'reporting.customers', 'term' => '  Acme  '),
            array('user_id' => 7),
        );

        $this->assertSame('Acme', $this->port->last('search')[3]->term);
        $this->assertSame('Acme', $payload['term']);
    }

    public function test_explain_passes_the_analyze_flag(): void
    {
        $this->port->connections = array($this->connection());

        $payload = (new ExplainTool($this->errors, $this->port))->execute(
            array('relation' => 'reporting.orders', 'analyze' => true),
            array('user_id' => 7),
        );

        $this->assertTrue($this->port->last('explain')[4]);
        $this->assertTrue($payload['analyzed']);
        $this->assertSame('reporting.orders', $payload['relation']);
    }

    public function test_query_prefixes_placeholders(): void
    {
        $this->port->connections = array($this->connection());

        (new QueryTool($this->errors, $this->port))->execute(
            array(
                'sql'    => 'SELECT id FROM reporting.orders WHERE id = :id',
                'params' => array('ID' => '42'),
            ),
            array('user_id' => 7),
        );

        $query = $this->port->last('query')[3];

        $this->assertSame(array(':id' => '42'), $query->params);
    }

    public function test_describe_caps_the_catalogue(): void
    {
        $this->port->connections = array($this->connection());

        $tables = array();

        for ($index = 0; $index < 65; $index++) {
            $key = sprintf('reporting.t%02d', $index);

            $tables[$key] = new TableDescriptor(
                schema: 'reporting',
                name: sprintf('t%02d', $index),
                columns: array('id' => new ColumnDescriptor('id', 'bigint', false)),
                primaryKey: array('id'),
            );
        }

        $this->port->catalogue = new CatalogueDescriptor($tables);

        $payload = (new DescribeTool($this->errors, $this->port))->execute(
            array(),
            array('user_id' => 7),
        );

        $this->assertSame(60, $payload['count']);
        $this->assertTrue($payload['truncated']);
        $this->assertNull($this->port->last('describe')[2]);
    }

    public function test_profile_skips_sensitive_columns(): void
    {
        $table = new TableDescriptor(
            schema: 'reporting',
            name: 'customers',
            columns: array(
                'name'  => new ColumnDescriptor('name', 'text', true),
                'email' => new ColumnDescriptor('email', 'text', true),
            ),
            primaryKey: array('name'),
        );

        $this->port->connections = array($this->connection());
        $this->port->catalogue   = new CatalogueDescriptor(array('reporting.customers' => $table));
        $this->port->scope       = new ScopeDescriptor(
            id: 'analyst',
            label: 'Analyst',
            connection: 'reporting',
            schemas: array('reporting'),
            tables: array('reporting.customers'),
            columns: array(
                'reporting.customers' => array('sensitive' => array('email')),
            ),
            principals: array(new PrincipalRef(PrincipalRef::USER, '7')),
        );
        $this->port->result = new ResultSet(
            columns: array('n'),
            rows: array(array('n' => 4)),
            rowCount: 1,
        );

        $payload = (new ProfileTableTool($this->errors, $this->port))->execute(
            array('relation' => 'reporting.customers', 'columns' => array('name', 'email')),
            array('user_id' => 7),
        );

        $this->assertSame(array('email'), $payload['skipped']);
        $this->assertSame('name', $payload['columns'][0]['name']);
        $this->assertSame(4, $payload['row_count']);
    }

    public function test_a_kernel_refusal_becomes_a_host_agnostic_error(): void
    {
        $this->port->connections = array($this->connection());
        $this->port->refusal     = DatabaseRefusal::of(
            RefusalCode::TABLE_NOT_ALLOWED,
            'Relation is not visible to this principal.',
        );

        $refused = (new SelectTool($this->errors, $this->port))->execute(
            array('relation' => 'secret.ledger'),
            array('user_id' => 7),
        );

        $this->assertSame('wp_mcp_ai_db_scope_table_not_allowed', $refused['error']['code']);
        $this->assertSame('db_scope_table_not_allowed', $refused['error']['data']['refusal_code']);
        $this->assertSame(403, $refused['error']['data']['status']);
    }

    public function test_no_principal_is_a_refusal_not_a_crash(): void
    {
        $refused = (new ListConnectionsTool($this->errors, $this->port))->execute(array(), array());

        $this->assertSame('db_scope_not_allowed', $refused['error']['data']['refusal_code']);
    }

    private function connection(string $id = 'reporting'): ConnectionDescriptor
    {
        return new ConnectionDescriptor(
            id: $id,
            label: strtoupper($id),
            engine: 'pgsql',
            host: 'db.internal',
            port: 5432,
            database: 'warehouse',
            credential: 'sealed-handle',
        );
    }
}
