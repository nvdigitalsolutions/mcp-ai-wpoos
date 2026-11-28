<?php

/**
 * `db_test_connection` and `db_export` on the Laravel host.
 *
 * Proposal 167 phase P4. Both are operator-facing and both have a host-shaped
 * dependency — a privilege probe and a file store — so they are the two tools
 * where "port" could quietly become "different tool". The assertions here are
 * about the parts a caller can see: the probe payload and its four guidance
 * strings, the export tier gate, the stored bytes (including the spreadsheet
 * formula guard), the checksum, and the refusal when the store fails.
 *
 * The probe itself is tested against a live PostgreSQL in
 * `tests/database/postgres/LaravelAdapterTest.php`; here it is a double because
 * the question is whether the tool asks the right connection and renders the
 * answer the way WordPress does.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool\Database;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Tests\Support\FakeAuditReader;
use Nvoos\Core\Tests\Support\FakeDatabasePort;
use Nvoos\Core\Tests\Support\FakeFileStore;
use Nvoos\Core\Tests\Support\FakeProbe;
use Nvoos\Core\Tool\Database\ExportTool;
use Nvoos\Core\Tool\Database\TailAuditTool;
use Nvoos\Core\Tool\Database\TestConnectionTool;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\ProbeResult;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;

#[CoversClass(TestConnectionTool::class)]
#[CoversClass(ExportTool::class)]
#[CoversClass(TailAuditTool::class)]
final class DatabaseAdminToolsTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    private FakeDatabasePort $port;

    private FakeProbe $probe;

    private FakeFileStore $files;

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

        $this->port  = new FakeDatabasePort();
        $this->probe = new FakeProbe();
        $this->files = new FakeFileStore();

        $this->port->connections = array($this->connection());
    }

    // ─── db_test_connection ───────────────────────────────────────────

    public function test_test_connection_reports_a_safe_role(): void
    {
        $payload = $this->testConnection()->execute(
            array('connection' => 'reporting'),
            array('user_id' => 7),
        );

        $this->assertTrue($payload['success']);
        $this->assertSame('reporting', $payload['connection']);
        $this->assertSame('database', $payload['kind']);
        $this->assertFalse($payload['persisted']);
        $this->assertTrue($payload['probe']['reachable']);
        $this->assertSame(
            array('The connection is reachable and safe for read-only scopes.'),
            $payload['guidance'],
        );
        $this->assertCount(1, $this->probe->probed);
        $this->assertSame('reporting', $this->probe->probed[0]->id);
    }

    public function test_test_connection_guidance_names_both_privilege_problems(): void
    {
        $this->probe->result = new ProbeResult(
            reachable: true,
            role: 'postgres',
            canWrite: true,
            bypassesRls: true,
        );

        $payload = $this->testConnection()->execute(
            array('connection' => 'reporting'),
            array('user_id' => 7),
        );

        $this->assertCount(2, $payload['guidance']);
        $this->assertStringContainsString('can write', $payload['guidance'][0]);
        $this->assertStringContainsString('bypasses row-level security', $payload['guidance'][1]);
    }

    public function test_test_connection_guidance_explains_an_unreachable_host(): void
    {
        $this->probe->result = ProbeResult::unreachable('db_connection_unavailable');

        $payload = $this->testConnection()->execute(
            array('connection' => 'reporting'),
            array('user_id' => 7),
        );

        $this->assertFalse($payload['probe']['reachable']);
        $this->assertStringContainsString('could not be opened', $payload['guidance'][0]);
    }

    public function test_test_connection_refuses_an_id_the_principal_cannot_see(): void
    {
        $refused = $this->testConnection()->execute(
            array('connection' => 'warehouse'),
            array('user_id' => 7),
        );

        $this->assertSame('db_unknown_connection', $refused['error']['data']['refusal_code']);
        $this->assertSame(404, $refused['error']['data']['status']);
        $this->assertCount(0, $this->probe->probed);
    }

    public function test_test_connection_requires_an_administrator(): void
    {
        $this->assertSame('manage_options', $this->testConnection()->getRequiredCapability());
    }

    // ─── db_export ────────────────────────────────────────────────────

    public function test_export_refuses_without_the_export_tier(): void
    {
        $refused = $this->export()->execute(
            array('relation' => 'reporting.orders'),
            array('user_id' => 7),
        );

        $this->assertSame('db_tier_required', $refused['error']['data']['refusal_code']);
        $this->assertSame(403, $refused['error']['data']['status']);
        $this->assertNull($this->port->last('select'));
        $this->assertSame('', $this->files->payload);
    }

    public function test_export_stores_privately_and_reports_a_checksum(): void
    {
        $this->withExportTier();

        $this->port->result = new ResultSet(
            columns: array('id', 'total'),
            rows: array(
                array('id' => 1, 'total' => 10),
                array('id' => 2, 'total' => 20),
            ),
            rowCount: 2,
            masked: array('reporting.orders.email'),
            truncated: true,
            auditId: 'audit-9',
        );

        $payload = $this->export()->execute(
            array('relation' => 'reporting.orders', 'limit' => 2),
            array('user_id' => 7),
        );

        $this->assertTrue($payload['success']);
        $this->assertSame('csv', $payload['format']);
        $this->assertSame(2, $payload['rows']);
        $this->assertTrue($payload['truncated']);
        $this->assertSame(array('reporting.orders.email'), $payload['masked']);
        $this->assertSame('audit-9', $payload['audit_id']);
        $this->assertTrue($payload['stored']);
        $this->assertNull($payload['url'], 'a private artefact has no public URL');
        $this->assertSame(FakeFileStore::VISIBILITY_PRIVATE, $this->files->visibility);
        $this->assertStringStartsWith('database-export-reporting-', $this->files->filename);
        $this->assertSame(strlen($this->files->payload), $payload['bytes']);
        $this->assertSame('sha256:' . hash('sha256', $this->files->payload), $payload['checksum']);
    }

    public function test_export_neutralises_spreadsheet_formulas(): void
    {
        $this->withExportTier();

        $this->port->result = new ResultSet(
            columns: array('id', 'name'),
            rows: array(array('id' => 1, 'name' => '=HYPERLINK("http://evil.test")')),
            rowCount: 1,
        );

        $this->export()->execute(array('relation' => 'reporting.orders'), array('user_id' => 7));

        $this->assertStringContainsString("'=HYPERLINK", $this->files->payload);
        $this->assertStringNotContainsString(',"=HYPERLINK', $this->files->payload);
    }

    public function test_export_renders_json(): void
    {
        $this->withExportTier();

        $this->port->result = new ResultSet(
            columns: array('id'),
            rows: array(array('id' => 1)),
            rowCount: 1,
        );

        $payload = $this->export()->execute(
            array('relation' => 'reporting.orders', 'format' => 'json'),
            array('user_id' => 7),
        );

        $this->assertSame('json', $payload['format']);
        $this->assertStringEndsWith('.json', $this->files->filename);
        $this->assertSame('[{"id":1}]', preg_replace('/\s+/', '', $this->files->payload));
    }

    public function test_export_maps_a_store_failure_to_a_refusal(): void
    {
        $this->withExportTier();
        $this->files->fail = true;

        $this->port->result = new ResultSet(columns: array('id'), rows: array(), rowCount: 0);

        $refused = $this->export()->execute(
            array('relation' => 'reporting.orders'),
            array('user_id' => 7),
        );

        $this->assertSame('db_result_too_large', $refused['error']['data']['refusal_code']);
    }

    // ─── db_audit_tail ─────────────────────────────────────────────────

    public function test_audit_tail_renders_the_page_and_forwards_filters(): void
    {
        $reader = new FakeAuditReader(
            records: array(
                array('id' => 'aud_1', 'action' => 'select', 'refusal_code' => null),
                array('id' => 'aud_2', 'action' => 'select', 'refusal_code' => null),
            ),
            summary: array('total' => 2, 'db_scope_table_not_allowed' => 1),
            retainedRecords: 0,
        );

        $payload = (new TailAuditTool($this->errors, $this->port, $reader))->execute(
            array(
                'limit'        => 2,
                'connection'   => ' reporting ',
                'refusal_code' => 'db_scope_table_not_allowed',
            ),
        );

        $this->assertTrue($payload['success']);
        $this->assertSame(2, $payload['count']);
        $this->assertSame(array('total' => 2, 'db_scope_table_not_allowed' => 1), $payload['summary']);
        $this->assertSame(0, $payload['retained']);

        $this->assertNotNull($reader->lastTail);
        $this->assertSame(2, $reader->lastTail[0]);
        $this->assertSame(
            array(
                'connection'   => 'reporting',
                'refusal_code' => 'db_scope_table_not_allowed',
            ),
            $reader->lastTail[1],
        );
    }

    public function test_audit_tail_clamps_the_limit_and_defaults_to_fifty(): void
    {
        $reader = new FakeAuditReader();
        $tool   = new TailAuditTool($this->errors, $this->port, $reader);

        $tool->execute(array('limit' => 5000));
        $this->assertSame(200, $reader->lastTail[0]);

        $tool->execute(array());
        $this->assertSame(50, $reader->lastTail[0]);

        $tool->execute(array('limit' => 0));
        $this->assertSame(1, $reader->lastTail[0]);
    }

    public function test_audit_tail_requires_an_administrator(): void
    {
        $reader = new FakeAuditReader();

        $this->assertSame(
            'manage_options',
            (new TailAuditTool($this->errors, $this->port, $reader))->getRequiredCapability(),
        );
    }

    // ─── helpers ──────────────────────────────────────────────────────

    private function testConnection(): TestConnectionTool
    {
        return new TestConnectionTool($this->errors, $this->port, $this->probe);
    }

    private function export(): ExportTool
    {
        return new ExportTool($this->errors, $this->port, $this->files);
    }

    private function withExportTier(): void
    {
        $this->port->scope = new ScopeDescriptor(
            id: 'analyst',
            label: 'Analyst',
            connection: 'reporting',
            schemas: array('reporting'),
            tables: array('reporting.orders'),
            tiers: array(GrantTier::METADATA, GrantTier::READ, GrantTier::EXPORT),
        );
    }

    private function connection(): ConnectionDescriptor
    {
        return new ConnectionDescriptor(
            id: 'reporting',
            label: 'Reporting',
            engine: 'pgsql',
            host: 'db.internal',
            port: 5432,
            database: 'warehouse',
            role: 'nvoos_reader',
            credential: 'secret',
        );
    }
}
