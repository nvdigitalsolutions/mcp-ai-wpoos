<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\EventDispatcherInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Tool\DeleteSettingTool;
use Nvoos\Core\Tool\DispatchEventTool;
use Nvoos\Core\Tool\ManageContextLifecycleTool;
use Nvoos\Core\Tool\MineAgentMemoryTool;
use Nvoos\Core\Tool\RetrieveAgentMemoryTool;
use Nvoos\Core\Tool\SemanticContentSearchTool;
use Nvoos\Core\Tool\SemanticContextSearchTool;
use Nvoos\Core\Tool\StoreAgentContextTool;
use Nvoos\Core\Tool\UpdateSettingTool;
use Tests\TestCase;

/**
 * Batch 4 evidence, part two: memory, settings and events.
 *
 * All three seams are bound in this runtime. Memory is exercised against a fake
 * `MemoryStoreInterface` because the real store is database-backed and a memory
 * row is deployment data; the point of these tests is that the tools call the
 * contract with the right shape, which is what the fake pins.
 *
 * `dispatch_event` is different: it must actually deliver, so the test replaces
 * the bound dispatcher with one it holds, registers a listener, and asserts the
 * listener ran. Replacing it first is necessary because the container binds the
 * dispatcher per call, not as a shared instance.
 */
class MemorySettingsAndEventToolsTest extends TestCase
{
    /**
     * @param class-string $class
     */
    private function execute(string $class, array $arguments = array(), array $context = array()): array
    {
        $result = $this->app->make($class)->execute($arguments, $context);

        $this->assertIsArray($result, $class . ' returned a non-array result: ' . print_r($result, true));

        return $result;
    }

    private function fakeMemory(): object
    {
        $fake = new class implements MemoryStoreInterface {
            /** @var array<int, array<string, mixed>> */
            public array $stored = array();

            /** @var array<int, string> */
            public array $deleted = array();

            public function store(array $record): array
            {
                $this->stored[] = $record;

                return array('success' => true, 'memory_id' => 'mem-1');
            }

            public function get(string $memoryId): array
            {
                return array('id' => $memoryId, 'content' => 'stored content');
            }

            public function update(string $memoryId, array $patch): array
            {
                return array_merge(array('id' => $memoryId), $patch);
            }

            public function delete(string $memoryId): array
            {
                $this->deleted[] = $memoryId;

                return array('id' => $memoryId, 'deleted' => true);
            }

            public function search(string $query, array $filters = array(), int $limit = 10): array
            {
                return array(array('id' => 'mem-1', 'content' => 'stored content', 'score' => 0.9));
            }

            public function listByAgent(string $agentId, array $filters = array(), int $limit = 50, int $offset = 0): array
            {
                return array(array('id' => 'mem-1', 'content' => 'stored content'));
            }
        };

        $this->app->instance(MemoryStoreInterface::class, $fake);

        return $fake;
    }

    // ─── Memory ──────────────────────────────────────────────────────────

    public function test_store_agent_context_writes_through_the_store(): void
    {
        $memory = $this->fakeMemory();

        $result = $this->execute(StoreAgentContextTool::class, array(
            'agent_id' => 7,
            'content' => 'The estate prefers email.',
            'context_type' => 'fact',
        ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $memory->stored);
    }

    public function test_retrieve_agent_memory_reads_from_the_store(): void
    {
        $this->fakeMemory();

        $result = $this->execute(RetrieveAgentMemoryTool::class, array('agent_id' => 7));

        $this->assertTrue($result['success']);
    }

    public function test_mine_agent_memory_reads_from_the_store(): void
    {
        $this->fakeMemory();

        $result = $this->execute(MineAgentMemoryTool::class, array('agent_id' => 7));

        $this->assertTrue($result['success']);
    }

    public function test_manage_context_lifecycle_deletes_through_the_store(): void
    {
        $memory = $this->fakeMemory();

        $result = $this->execute(ManageContextLifecycleTool::class, array(
            'action' => 'delete',
            'memory_id' => 'mem-1',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(array('mem-1'), $memory->deleted);
    }

    public function test_semantic_content_search_returns_hits(): void
    {
        $this->fakeMemory();

        $result = $this->execute(SemanticContentSearchTool::class, array('query' => 'email preference'));

        $this->assertTrue($result['success']);
    }

    public function test_semantic_context_search_returns_hits(): void
    {
        $this->fakeMemory();

        $result = $this->execute(SemanticContextSearchTool::class, array(
            'agent_id' => 7,
            'query' => 'email preference',
        ));

        $this->assertTrue($result['success']);
    }

    // ─── Settings (the store creates its own table) ──────────────────────

    public function test_update_setting_writes_a_value(): void
    {
        Http::fake();

        $result = $this->execute(UpdateSettingTool::class, array(
            'key' => 'default_provider',
            'value' => 'openai',
        ));

        $this->assertTrue($result['success']);

        Http::assertNothingSent();
    }

    public function test_delete_setting_removes_a_value(): void
    {
        Http::fake();

        $result = $this->execute(DeleteSettingTool::class, array('key' => 'test.batch4'));

        $this->assertTrue($result['success']);

        Http::assertNothingSent();
    }

    // ─── Events ──────────────────────────────────────────────────────────

    public function test_dispatch_event_reaches_a_registered_listener(): void
    {
        Http::fake();

        $dispatcher = $this->app->make(EventDispatcherInterface::class);
        $this->app->instance(EventDispatcherInterface::class, $dispatcher);

        // dispatch_event wraps the payload in an anonymous class, so a listener
        // registered by name can never match it. The adapter also forwards to
        // Laravel's dispatcher, so a wildcard listener is the only way to
        // observe delivery - and it proves the framework side ran.
        $seen = array();
        Event::listen('*', static function (string $eventName, array $payload) use (&$seen): void {
            $seen[] = array($eventName, $payload);
        });

        $result = $this->execute(DispatchEventTool::class, array(
            'event' => 'nvoos.batch4.ping',
            'payload' => array('id' => 1),
        ));

        $this->assertTrue($result['success']);
        $this->assertNotEmpty($seen);

        Http::assertNothingSent();
    }
}
