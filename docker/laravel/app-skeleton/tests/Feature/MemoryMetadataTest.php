<?php

namespace Tests\Feature;

use App\Models\Memory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Tests\TestCase;

/**
 * Memory provenance — Proposal 164 §164.42.
 *
 * The columns on `nvoos_memories` say what a memory *is*: content, tier, wing,
 * room, importance. They cannot say where it came from, when it was true, or
 * what policy the tool that captured it applied — and `MemoryStore` used to drop
 * any key it did not have a column for, silently.
 *
 * This file pins that a `metadata` map survives a round trip, that patching it
 * merges rather than replaces (RFC 7386), and that the contract's byte ceiling is
 * enforced on both paths.
 */
class MemoryMetadataTest extends TestCase
{
    use RefreshDatabase;

    private MemoryStoreInterface $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->store = $this->app->make(MemoryStoreInterface::class);
    }

    // ─── Round trip ────────────────────────────────────────────────────────

    public function test_a_metadata_map_survives_a_round_trip(): void
    {
        $id = $this->remember([
            'subject_refs' => ['https://example.test/brief', 'doc-42'],
            'recorded_at' => '2026-11-25T10:00:00Z',
            'valid_from' => '2026-11-25',
            'valid_until' => null,
            'verbatim' => true,
            'sensitivity' => 'pii',
        ]);

        $record = $this->store->get($id)['record'];

        $this->assertSame(['https://example.test/brief', 'doc-42'], $record['metadata']['subject_refs']);
        $this->assertSame('2026-11-25T10:00:00Z', $record['metadata']['recorded_at']);
        $this->assertSame('2026-11-25', $record['metadata']['valid_from']);
        $this->assertTrue($record['metadata']['verbatim']);
        $this->assertSame('pii', $record['metadata']['sensitivity']);
        $this->assertArrayHasKey('valid_until', $record['metadata']);
        $this->assertNull($record['metadata']['valid_until']);
    }

    /**
     * A caller should never have to tell "absent" from "empty" to read a
     * provenance field.
     */
    public function test_a_record_without_metadata_reads_back_as_an_empty_map(): void
    {
        $id = $this->remember([]);

        $this->assertSame([], $this->store->get($id)['record']['metadata']);
    }

    /**
     * The column is nullable rather than defaulting to `{}`, so "nothing was
     * recorded" is visible in the database.
     */
    public function test_an_empty_map_is_stored_as_null(): void
    {
        $id = $this->remember([]);

        $this->assertNull(
            DB::table('nvoos_memories')->where('id', $id)->value('metadata'),
        );
    }

    public function test_search_results_carry_the_metadata(): void
    {
        $this->remember(['recorded_at' => '2026-11-25'], 'The refund window is thirty days.');

        $results = $this->store->search('refund');

        $this->assertCount(1, $results);
        $this->assertSame('2026-11-25', $results[0]['metadata']['recorded_at']);
    }

    public function test_list_by_agent_carries_the_metadata(): void
    {
        $this->remember(['verbatim' => false]);

        $listed = $this->store->listByAgent('agent-1');

        $this->assertSame(1, $listed['total']);
        $this->assertFalse($listed['memories'][0]['metadata']['verbatim']);
    }

    /**
     * The read-only `/admin` browser goes through the Eloquent model, which must
     * decode the column rather than hand a surface a JSON string.
     */
    public function test_the_read_only_model_decodes_the_map(): void
    {
        $id = $this->remember(['verbatim' => true, 'valid_from' => '2026-11-25']);

        $memory = Memory::withoutGlobalScope('tenant')->findOrFail($id);

        $this->assertSame(['verbatim' => true, 'valid_from' => '2026-11-25'], $memory->metadata);
    }

    // ─── Merge patch semantics ─────────────────────────────────────────────

    /**
     * The reason a patch merges: setting `valid_until` when a record is
     * superseded must not require sending back every other key.
     */
    public function test_updating_metadata_adds_a_key_and_keeps_the_others(): void
    {
        $id = $this->remember([
            'subject_refs' => ['doc-42'],
            'recorded_at' => '2026-11-25',
        ]);

        $this->assertTrue($this->store->update($id, [
            'metadata' => ['valid_until' => '2027-11-25'],
        ])['success']);

        $metadata = $this->store->get($id)['record']['metadata'];

        $this->assertSame(['doc-42'], $metadata['subject_refs']);
        $this->assertSame('2026-11-25', $metadata['recorded_at']);
        $this->assertSame('2027-11-25', $metadata['valid_until']);
    }

    public function test_a_null_value_removes_a_key(): void
    {
        $id = $this->remember([
            'valid_until' => '2027-01-01',
            'sensitivity' => 'pii',
        ]);

        $this->store->update($id, ['metadata' => ['valid_until' => null]]);

        $metadata = $this->store->get($id)['record']['metadata'];

        $this->assertArrayNotHasKey('valid_until', $metadata);
        $this->assertSame('pii', $metadata['sensitivity'], 'Only the named key is removed.');
    }

    public function test_a_nested_object_is_merged_recursively(): void
    {
        $id = $this->remember([
            'capture' => ['tool' => 'docgen_capture_style_memory', 'version' => 1],
        ]);

        $this->store->update($id, ['metadata' => ['capture' => ['version' => 2]]]);

        $metadata = $this->store->get($id)['record']['metadata'];

        $this->assertSame('docgen_capture_style_memory', $metadata['capture']['tool']);
        $this->assertSame(2, $metadata['capture']['version']);
    }

    /**
     * RFC 7386 replaces an array wholesale; only objects merge. A caller
     * re-sending `subject_refs` is replacing the list, not appending to it.
     */
    public function test_an_array_is_replaced_wholesale(): void
    {
        $id = $this->remember(['subject_refs' => ['a', 'b', 'c']]);

        $this->store->update($id, ['metadata' => ['subject_refs' => ['z']]]);

        $this->assertSame(['z'], $this->store->get($id)['record']['metadata']['subject_refs']);
    }

    public function test_other_fields_are_still_replaced_not_merged(): void
    {
        $id = $this->remember(['verbatim' => true], 'Original content');

        $this->store->update($id, ['content' => 'Replaced content', 'tags' => ['new']]);

        $record = $this->store->get($id)['record'];

        $this->assertSame('Replaced content', $record['content']);
        $this->assertSame(['new'], $record['tags']);
        $this->assertTrue($record['metadata']['verbatim'], 'Metadata is untouched by a non-metadata patch.');
    }

    /**
     * "Nothing to do" is not "no such record". The old implementation derived
     * success from the affected-row count, which a database reports as zero when
     * a patch writes the values already present.
     */
    public function test_a_patch_that_changes_nothing_still_succeeds(): void
    {
        $id = $this->remember(['verbatim' => true], 'Same content');

        $result = $this->store->update($id, ['content' => 'Same content']);

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('error', $result);
    }

    public function test_updating_an_unknown_id_still_fails(): void
    {
        $result = $this->store->update('no-such-id', ['metadata' => ['a' => 1]]);

        $this->assertFalse($result['success']);
        $this->assertSame('Memory not found.', $result['error']);
    }

    // ─── The ceiling ───────────────────────────────────────────────────────

    public function test_oversized_metadata_is_refused_on_store(): void
    {
        $result = $this->store->store([
            'agent_id' => 'agent-1',
            'content' => 'x',
            'metadata' => ['blob' => str_repeat('x', MemoryStoreInterface::MAX_METADATA_BYTES + 1)],
        ]);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('file registry', (string) $result['error']);
        $this->assertSame(0, DB::table('nvoos_memories')->count(), 'Nothing is written.');
    }

    public function test_oversized_metadata_is_refused_on_update(): void
    {
        $id = $this->remember(['verbatim' => true]);

        $result = $this->store->update($id, [
            'metadata' => ['blob' => str_repeat('x', MemoryStoreInterface::MAX_METADATA_BYTES + 1)],
        ]);

        $this->assertFalse($result['success']);
        $this->assertSame(
            ['verbatim' => true],
            $this->store->get($id)['record']['metadata'],
            'The stored map is left as it was.',
        );
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    /**
     * @param array<string, mixed> $metadata
     *
     * @return string The stored memory id.
     */
    private function remember(array $metadata, string $content = 'A style sample.'): string
    {
        $result = $this->store->store([
            'agent_id' => 'agent-1',
            'content' => $content,
            'title' => 'Sample',
            'tags' => ['docgen', 'style'],
            'tier' => MemoryStoreInterface::TIER_RECALL,
            'importance' => 0.55,
            'wing' => 'user/agent-1',
            'room' => 'style',
            'metadata' => $metadata,
        ]);

        $this->assertTrue($result['success'], 'Setup failed: ' . ($result['error'] ?? ''));

        return (string) $result['memory_id'];
    }
}
