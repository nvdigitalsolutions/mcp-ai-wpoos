<?php

/**
 * Async `db_export` — Proposal 167 phase P7, task 7.1.
 *
 * The synchronous export is covered by the Postgres suite. What is new here is
 * the hand-off, and the three properties that make it safe:
 *
 *  1. enqueue asserts the tier and reads **no row** — the port's `select()` is
 *     never called on the request thread;
 *  2. the payload carries arguments and a principal, never a resolved scope, so
 *     the worker re-applies the grant in force when it runs;
 *  3. the queue depth is capped per principal, and the cap surfaces as
 *     `db_export_limit` (retryable), not as a generic failure.
 *
 * The vocabulary follows MCP Tasks (SEP-1686): `queued` … `completed`/`failed`.
 * The transport extension itself is not implemented here.
 *
 * @package Nvoos\Core\Tests
 * @since   2.7.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool\Database;

use Nvoos\Core\Domain\Entity\JobStatus;
use Nvoos\Core\Tests\Support\ArrayErrorFactory;
use Nvoos\Core\Tests\Support\FakeDatabasePort;
use Nvoos\Core\Tests\Support\FakeFileStore;
use Nvoos\Core\Tests\Support\FakeQueueClient;
use Nvoos\Core\Tool\Database\DatabaseExportHandler;
use Nvoos\Core\Tool\Database\ExportTool;
use Nvoos\Database\Domain\Service\Database\GrantTier;
use Nvoos\Database\Domain\Service\Database\RefusalCode;
use Nvoos\Database\Domain\ValueObject\Database\ConnectionDescriptor;
use Nvoos\Database\Domain\ValueObject\Database\PrincipalRef;
use Nvoos\Database\Domain\ValueObject\Database\ResultSet;
use Nvoos\Database\Domain\ValueObject\Database\ScopeDescriptor;
use PHPUnit\Framework\Attributes\CoversClass;
use PHPUnit\Framework\TestCase;
use RuntimeException;

#[CoversClass(ExportTool::class)]
#[CoversClass(DatabaseExportHandler::class)]
#[CoversClass(RefusalCode::class)]
final class ExportToolAsyncTest extends TestCase
{
    private ArrayErrorFactory $errors;

    private FakeDatabasePort $port;

    private FakeFileStore $files;

    private FakeQueueClient $queue;

    protected function setUp(): void
    {
        $this->errors = new ArrayErrorFactory();
        $this->port   = new FakeDatabasePort();
        $this->files  = new FakeFileStore();
        $this->queue  = new FakeQueueClient();

        $this->port->scope  = $this->scope(array(GrantTier::READ, GrantTier::EXPORT));
        $this->port->connections = array(
            new ConnectionDescriptor(
                id: 'reporting',
                label: 'REPORTING',
                engine: 'pgsql',
                host: 'db.internal',
                port: 5432,
                database: 'warehouse',
                credential: 'sealed-handle',
            ),
        );
        $this->port->result = new ResultSet(
            columns: array('id', 'email'),
            rows: array(array('id' => 1, 'email' => 'masked')),
            rowCount: 1,
            masked: array('email'),
            elapsedMs: 2,
            auditId: 'aud_async',
        );
    }

    public function test_async_enqueues_the_handler_and_reads_no_row(): void
    {
        $result = $this->tool()->execute(
            array('relation' => 'reporting.orders', 'async' => true, 'format' => 'csv'),
            array('user_id' => 7),
        );

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertTrue($result['async']);
        $this->assertSame('job_test', $result['job_id']);
        $this->assertSame('queued', $result['status']);
        $this->assertSame('db_export', $result['poll_with']);
        $this->assertSame(array('job_id' => 'job_test'), $result['poll_arguments']);
        $this->assertSame('csv', $result['format']);

        $this->assertCount(1, $this->queue->enqueued);
        $this->assertSame(DatabaseExportHandler::class, $this->queue->enqueued[0]['handler']);
        $this->assertSame('database', $this->queue->enqueued[0]['options']['group']);
        $this->assertSame(7, $this->queue->enqueued[0]['payload']['context']['user_id']);

        // The worker must not be handed `async`, or it would enqueue itself again.
        $this->assertArrayNotHasKey('async', $this->queue->enqueued[0]['payload']['arguments']);

        // Nothing was read on the request thread.
        $this->assertNull($this->port->last('select'));
    }

    public function test_async_is_refused_when_the_runtime_binds_no_queue(): void
    {
        $tool = new ExportTool($this->errors, $this->port, $this->files, null);

        $result = $tool->execute(array('relation' => 'reporting.orders', 'async' => true), array('user_id' => 7));

        $this->assertIsArray($result);
        $this->assertSame('wp_mcp_ai_db_async_unavailable', $result['error']['code']);
        $this->assertSame(501, $result['error']['data']['status']);
        $this->assertNull($this->port->last('select'));
    }

    public function test_async_export_is_capped_per_principal(): void
    {
        $this->queue->jobs = array(
            new JobStatus(jobId: 'a', status: 'queued'),
            new JobStatus(jobId: 'b', status: 'running'),
            new JobStatus(jobId: 'c', status: 'queued'),
            // A finished job must not count toward the cap.
            new JobStatus(jobId: 'd', status: 'completed'),
        );

        $result = $this->tool()->execute(
            array('relation' => 'reporting.orders', 'async' => true),
            array('user_id' => 7),
        );

        // `guard()` turns a refusal into the host envelope, so this asserts what
        // the model actually receives: a stable code, a 429 and `db_export_limit`.
        $this->assertIsArray($result);
        $this->assertSame('wp_mcp_ai_db_export_limit', $result['error']['code']);
        $this->assertSame(429, $result['error']['data']['status']);
        $this->assertSame('db_export_limit', $result['error']['data']['refusal_code']);
        $this->assertTrue(RefusalCode::EXPORT_LIMIT->isRetryable());

        $this->assertCount(0, $this->queue->enqueued);
    }

    public function test_the_handler_runs_the_export_and_skips_the_async_branch(): void
    {
        $handler = new DatabaseExportHandler($this->tool(), $this->errors);

        $result = $handler->handle(
            array(
                'arguments' => array('relation' => 'reporting.orders', 'async' => true),
                'context'   => array('user_id' => 7),
            ),
        );

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('async', $result);
        $this->assertSame('sha256:' . hash('sha256', $this->files->payload), $result['checksum']);
        $this->assertSame(array('email'), $result['masked']);
        $this->assertNotNull($this->port->last('select'));
        $this->assertCount(0, $this->queue->enqueued);
    }

    public function test_the_handler_fails_when_the_grant_was_narrowed_between_enqueue_and_run(): void
    {
        // The queue ran the job after an operator dropped the export tier.
        $this->port->scope = $this->scope(array(GrantTier::READ));

        $handler = new DatabaseExportHandler($this->tool(), $this->errors);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('db_tier_required');

        $handler->handle(
            array(
                'arguments' => array('relation' => 'reporting.orders'),
                'context'   => array('user_id' => 7),
            ),
        );
    }

    public function test_a_job_id_reads_the_export_back_without_a_relation(): void
    {
        $this->queue->status = new JobStatus(
            jobId: 'job_test',
            status: 'completed',
            result: array('checksum' => 'sha256:abc', 'rows' => 2),
            attempts: 1,
        );

        $result = $this->tool()->execute(array('job_id' => 'job_test'), array('user_id' => 7));

        $this->assertIsArray($result);
        $this->assertTrue($result['success']);
        $this->assertSame('completed', $result['status']);
        $this->assertSame('sha256:abc', $result['result']['checksum']);
        $this->assertSame(1, $result['attempts']);

        // Reading a job is not a read of the database.
        $this->assertNull($this->port->last('select'));
    }

    public function test_neither_a_relation_nor_a_job_id_is_a_validation_error(): void
    {
        $result = $this->tool()->execute(array(), array('user_id' => 7));

        $this->assertIsArray($result);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertNull($this->port->last('select'));
    }

    /**
     * A tool wired with the recording doubles.
     */
    private function tool(): ExportTool
    {
        return new ExportTool($this->errors, $this->port, $this->files, $this->queue);
    }

    /**
     * A scope for `reporting.orders` holding the given tiers.
     *
     * @param list<GrantTier> $tiers Tiers to grant.
     */
    private function scope(array $tiers): ScopeDescriptor
    {
        return new ScopeDescriptor(
            id: 'analyst',
            label: 'Analyst',
            connection: 'reporting',
            schemas: array('reporting'),
            tables: array('reporting.orders'),
            tiers: $tiers,
            principals: array(new PrincipalRef(PrincipalRef::USER, '7')),
        );
    }
}
