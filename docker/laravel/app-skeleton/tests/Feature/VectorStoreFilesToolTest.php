<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Nvoos\Core\Tool\ManageVectorStoreFilesTool;
use Tests\TestCase;

/**
 * Batch 4 follow-up: the polling fixture `manage_vector_store_files` needed.
 *
 * The row was left `unverified` because the tool drives a file batch and polls
 * it, and a single-response fake cannot express that. The batch-status URL is a
 * `Http::sequence()`, so the loop is exercised for real: one `in_progress` then
 * one `completed`. The cap test does the opposite on purpose — a plain response
 * that keeps reporting `in_progress` forces the loop to exit on time rather than
 * on state.
 */
class VectorStoreFilesToolTest extends TestCase
{
    private const STORE = 'vs_1';

    private const BASE = 'api.openai.com/v1/vector_stores/' . self::STORE;

    protected function setUp(): void
    {
        parent::setUp();

        config(array('services.openai.key' => 'sk-test'));
    }

    /**
     * @param array<string, mixed> $arguments
     * @return array<string, mixed>
     */
    private function execute(array $arguments): array
    {
        $result = $this->app->make(ManageVectorStoreFilesTool::class)->execute($arguments);

        $this->assertIsArray($result, 'manage_vector_store_files returned a non-array: ' . print_r($result, true));

        return $result;
    }

    public function test_add_files_polls_the_batch_to_completion(): void
    {
        Http::fake(array(
            self::BASE . '/file_batches/vsb_1/files' => Http::response(array(
                'data' => array(array('id' => 'file_a', 'status' => 'completed')),
            )),
            self::BASE . '/file_batches/vsb_1' => Http::sequence()
                ->push(array('id' => 'vsb_1', 'status' => 'in_progress'), 200)
                ->push(array('id' => 'vsb_1', 'status' => 'completed'), 200),
            self::BASE . '/file_batches' => Http::response(array('id' => 'vsb_1', 'status' => 'in_progress')),
        ));

        $result = $this->execute(array(
            'action' => 'add',
            'vector_store_id' => self::STORE,
            'file_ids' => array('file_a'),
            'poll_max_seconds' => 5,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('vsb_1', $result['data']['batch_id']);
        $this->assertSame('completed', $result['data']['batch_status']);
        $this->assertSame(array(array('file_id' => 'file_a', 'status' => 'completed')), $result['data']['added']);
        $this->assertSame(array(), $result['data']['errors']);

        // Creation, two polls (the sequence proves the loop re-polls), statuses.
        Http::assertSentCount(4);

        Http::assertSent(fn ($request) => 'POST' === $request->method()
            && str_ends_with($request->url(), '/file_batches')
            && array('file_a') === $request->data()['file_ids']);
    }

    public function test_add_files_reports_a_batch_still_processing_at_the_cap(): void
    {
        Http::fake(array(
            self::BASE . '/file_batches' => Http::response(array('id' => 'vsb_2', 'status' => 'in_progress')),
            // Always in_progress: the loop must give up at the cap, not hang.
            self::BASE . '/file_batches/vsb_2' => Http::response(array('id' => 'vsb_2', 'status' => 'in_progress')),
        ));

        $result = $this->execute(array(
            'action' => 'add',
            'vector_store_id' => self::STORE,
            'file_ids' => array('file_a', 'file_b'),
            'poll_max_seconds' => 1,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('vsb_2', $result['data']['batch_id']);
        $this->assertSame('in_progress', $result['data']['batch_status']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertStringContainsString('still processing', $result['message']);
        $this->assertSame('in_progress', $result['data']['added'][0]['status']);
    }

    public function test_add_files_falls_back_to_individual_adds_when_the_batch_endpoint_is_missing(): void
    {
        Http::fake(array(
            self::BASE . '/files' => Http::response(array('id' => 'vs_file', 'status' => 'completed')),
            self::BASE . '/file_batches' => Http::response(array('error' => array('message' => 'no batches')), 404),
        ));

        $result = $this->execute(array(
            'action' => 'add',
            'vector_store_id' => self::STORE,
            'file_ids' => array('file_a', 'file_b'),
        ));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('batch_id', $result['data']);
        $this->assertCount(2, $result['data']['added']);
        $this->assertSame('file_a', $result['data']['added'][0]['file_id']);
        $this->assertSame(array(), $result['data']['errors']);

        // One rejected batch call, then one add per file.
        Http::assertSentCount(3);
    }

    public function test_remove_files_reports_deletion(): void
    {
        Http::fake(array(
            self::BASE . '/files/file_a' => Http::response(array('id' => 'file_a', 'deleted' => true)),
        ));

        $result = $this->execute(array(
            'action' => 'remove',
            'vector_store_id' => self::STORE,
            'file_ids' => array('file_a'),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(array('file_id' => 'file_a', 'deleted' => true)), $result['data']['added']);
        $this->assertSame(1, $result['data']['total']);

        Http::assertSent(fn ($request) => 'DELETE' === $request->method()
            && str_ends_with($request->url(), '/files/file_a'));
    }

    public function test_list_files_returns_a_collection(): void
    {
        Http::fake(array(
            self::BASE . '/files?*' => Http::response(array(
                'data' => array(
                    array('id' => 'file_a', 'status' => 'completed'),
                    array('id' => 'file_b', 'status' => 'in_progress'),
                ),
                'has_more' => false,
            )),
        ));

        $result = $this->execute(array(
            'action' => 'list',
            'vector_store_id' => self::STORE,
            'limit' => 2,
            'order' => 'asc',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['total']);
        $this->assertCount(2, $result['data']['items']);
        $this->assertSame('file_b', $result['data']['items'][1]['id']);

        Http::assertSent(fn ($request) => 'GET' === $request->method()
            && str_contains($request->url(), 'limit=2')
            && str_contains($request->url(), 'order=asc'));
    }
}
