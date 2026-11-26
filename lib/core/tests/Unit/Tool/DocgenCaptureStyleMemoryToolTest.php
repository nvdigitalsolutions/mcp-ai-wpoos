<?php

/**
 * `docgen_capture_style_memory`.
 *
 * The behaviour worth pinning is the verbatim discipline: the exact text is
 * always recoverable, a summary is a second record rather than a replacement,
 * and a failure to keep the original stops before anything shorter is written.
 * Then the provenance: what the caller supplied reaches `metadata`, and what the
 * tool knows better than the caller — `verbatim`, `tier` — is derived rather than
 * accepted as a parameter.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\MemoryStoreInterface;
use Nvoos\Core\Tool\DocgenCaptureStyleMemoryTool;
use PHPUnit\Framework\TestCase;

final class DocgenCaptureStyleMemoryToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('docgen_capture_style_memory', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
    }

    /**
     * Since §164.42 the store holds a `metadata` map, so the provenance the
     * WordPress tool offers can be carried rather than refused.
     */
    public function test_the_provenance_fields_are_offered(): void
    {
        $properties = $this->tool()->getParametersSchema()['properties'];

        foreach (
            array(
                'subject_refs',
                'attachments',
                'recorded_at',
                'valid_from',
                'valid_until',
                'sensitivity',
                'consent_basis',
            ) as $offered
        ) {
            $this->assertArrayHasKey($offered, $properties);
        }
    }

    /**
     * `verbatim` and `tier` describe what the record holds, which the tool decides
     * — and `allow_summarisation` describes a model this host does not have.
     */
    public function test_flags_the_tool_decides_are_not_parameters(): void
    {
        $properties = $this->tool()->getParametersSchema()['properties'];

        foreach (array('verbatim', 'tier', 'allow_summarisation') as $absent) {
            $this->assertArrayNotHasKey($absent, $properties);
        }
    }

    // ─── The verbatim discipline ───────────────────────────────────────────

    public function test_without_a_summary_one_verbatim_record_is_written(): void
    {
        $memory = $this->memory();

        $result = $this->tool($memory)->execute(array(
            'agent_id' => '42',
            'content' => 'A voice that prefers short sentences.',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['records']);
        $this->assertNull($result['data']['archival_id']);
        $this->assertCount(1, $memory->records);
        $this->assertSame(MemoryStoreInterface::TIER_RECALL, $memory->records[0]['tier']);
        $this->assertSame('A voice that prefers short sentences.', $memory->records[0]['content']);
    }

    public function test_a_summary_becomes_the_recall_representative_and_the_original_stays_archival(): void
    {
        $memory = $this->memory();

        $result = $this->tool($memory)->execute(array(
            'agent_id' => '42',
            'content' => 'Long verbatim sample. ' . str_repeat('detail ', 50),
            'summary' => 'Terse, declarative, numbers over adjectives.',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['records']);
        $this->assertCount(2, $memory->records);

        $archival = $memory->records[0];
        $recall = $memory->records[1];

        $this->assertSame(MemoryStoreInterface::TIER_ARCHIVAL, $archival['tier']);
        $this->assertStringStartsWith('Long verbatim sample.', $archival['content']);
        $this->assertSame(MemoryStoreInterface::TIER_RECALL, $recall['tier']);
        $this->assertSame('Terse, declarative, numbers over adjectives.', $recall['content']);
    }

    public function test_a_summary_identical_to_the_content_writes_one_record(): void
    {
        $memory = $this->memory();

        $result = $this->tool($memory)->execute(array(
            'agent_id' => '42',
            'content' => 'Same text.',
            'summary' => 'Same text.',
        ));

        $this->assertSame(1, $result['data']['records']);
        $this->assertCount(1, $memory->records);
    }

    /**
     * A summary without its original breaks the discipline this tool exists for,
     * so a failure to keep the original must stop before anything shorter lands.
     */
    public function test_a_failed_archival_write_stores_no_summary(): void
    {
        $memory = $this->memory(MemoryStoreInterface::TIER_ARCHIVAL);

        $result = $this->tool($memory)->execute(array(
            'agent_id' => '42',
            'content' => 'The original.',
            'summary' => 'A summary.',
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('store_failed', $result['error']['code']);
        $this->assertCount(1, $memory->records, 'Only the failed archival attempt was made.');
        $this->assertSame(MemoryStoreInterface::TIER_ARCHIVAL, $memory->records[0]['tier']);
    }

    public function test_a_failed_recall_write_is_reported(): void
    {
        $memory = $this->memory(MemoryStoreInterface::TIER_RECALL);

        $result = $this->tool($memory)->execute(array(
            'agent_id' => '42',
            'content' => 'The original.',
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('store_failed', $result['error']['code']);
    }

    // ─── Placement ─────────────────────────────────────────────────────────

    public function test_the_wing_is_derived_from_the_agent_and_the_room_from_the_kind(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array(
            'agent_id' => 42,
            'kind' => 'drafts',
            'content' => 'A draft.',
        ));

        $this->assertSame('user/42', $memory->records[0]['wing']);
        $this->assertSame('drafts', $memory->records[0]['room']);
        $this->assertSame('42', $memory->records[0]['agent_id']);
    }

    public function test_the_default_kind_is_style(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => 'x'));

        $this->assertSame('style', $memory->records[0]['room']);
        $this->assertSame('Style sample', $memory->records[0]['title']);
    }

    public function test_tags_always_carry_docgen_and_the_kind(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array(
            'agent_id' => 'u1',
            'kind' => 'drafts',
            'content' => 'x',
            'tags' => array('legal', 'docgen', ''),
        ));

        $this->assertSame(array('docgen', 'drafts', 'legal'), $memory->records[0]['tags']);
    }

    public function test_the_ttl_defaults_to_two_years(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => 'x'));

        $this->assertSame(DocgenCaptureStyleMemoryTool::DEFAULT_TTL, $memory->records[0]['ttl']);
    }

    public function test_importance_is_clamped_to_zero_and_one(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => 'x', 'importance' => 5));

        $this->assertSame(1.0, $memory->records[0]['importance']);
    }

    /**
     * `stringParam()` strips tags, which would silently mangle a style sample
     * containing "a < b" or a draft the author wrote in markdown.
     */
    public function test_the_content_is_stored_exactly_as_given(): void
    {
        $memory = $this->memory();
        $content = 'When a < b, prefer the shorter form. **Bold** stays.';

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => $content));

        $this->assertSame($content, $memory->records[0]['content']);
    }

    // ─── Provenance ────────────────────────────────────────────────────────

    public function test_every_record_carries_its_source(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => 'x'));

        $this->assertSame('docgen_capture_style_memory', $memory->records[0]['metadata']['source']);
    }

    /**
     * The archival record holds the original, so it says `true`; a recall record
     * holding a summary is not the original and says `false`.
     */
    public function test_verbatim_is_derived_from_which_record_holds_what(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array(
            'agent_id' => 'u1',
            'content' => 'The original words, at length.',
            'summary' => 'Short.',
        ));

        $this->assertTrue($memory->records[0]['metadata']['verbatim'], 'The archival record is the original.');
        $this->assertFalse($memory->records[1]['metadata']['verbatim'], 'The recall record is a summary.');
    }

    public function test_a_lone_record_holding_the_original_is_verbatim(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => 'The original.'));

        $this->assertTrue($memory->records[0]['metadata']['verbatim']);
    }

    public function test_the_callers_provenance_reaches_the_metadata(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array(
            'agent_id' => 'u1',
            'content' => 'x',
            'subject_refs' => array('https://example.test/brief', 'doc-42'),
            'attachments' => array(array('sha256' => 'abc', 'mime' => 'application/pdf')),
            'recorded_at' => '2026-11-25T10:00:00Z',
            'valid_from' => '2026-11-25',
            'valid_until' => '2027-11-25',
            'sensitivity' => 'pii',
            'consent_basis' => 'consent',
        ));

        $metadata = $memory->records[0]['metadata'];

        $this->assertSame(array('https://example.test/brief', 'doc-42'), $metadata['subject_refs']);
        $this->assertSame(array(array('sha256' => 'abc', 'mime' => 'application/pdf')), $metadata['attachments']);
        $this->assertSame('2026-11-25T10:00:00Z', $metadata['recorded_at']);
        $this->assertSame('2026-11-25', $metadata['valid_from']);
        $this->assertSame('2027-11-25', $metadata['valid_until']);
        $this->assertSame('pii', $metadata['sensitivity']);
        $this->assertSame('consent', $metadata['consent_basis']);
    }

    /**
     * Both records describe the same capture, so they cite the same sources.
     */
    public function test_every_record_shares_the_same_provenance(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array(
            'agent_id' => 'u1',
            'content' => 'The original words, at length.',
            'summary' => 'Short.',
            'subject_refs' => array('doc-42'),
        ));

        $this->assertSame(
            $memory->records[0]['metadata']['subject_refs'],
            $memory->records[1]['metadata']['subject_refs'],
        );
    }

    /**
     * A record without provenance gets the derived keys only, not a map full of
     * empty values — so a reader can tell "nothing recorded" from "recorded as
     * empty".
     */
    public function test_unsupplied_provenance_is_absent_rather_than_empty(): void
    {
        $memory = $this->memory();

        $this->tool($memory)->execute(array('agent_id' => 'u1', 'content' => 'x'));

        $this->assertSame(
            array('source' => 'docgen_capture_style_memory', 'verbatim' => true),
            $memory->records[0]['metadata'],
        );
    }

    public function test_a_bad_date_is_refused_before_anything_is_stored(): void
    {
        $memory = $this->memory();

        $result = $this->tool($memory)->execute(array(
            'agent_id' => 'u1',
            'content' => 'x',
            'valid_until' => 'next Tuesday',
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertCount(0, $memory->records);
    }

    public function test_too_many_references_are_refused(): void
    {
        $refs = array();

        for ($i = 0; $i <= DocgenCaptureStyleMemoryTool::MAX_REFS; $i++) {
            $refs[] = 'ref-' . $i;
        }

        $result = $this->tool()->execute(array(
            'agent_id' => 'u1',
            'content' => 'x',
            'subject_refs' => $refs,
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_attachment_that_is_not_an_object_is_refused(): void
    {
        $result = $this->tool()->execute(array(
            'agent_id' => 'u1',
            'content' => 'x',
            'attachments' => array('just-a-string'),
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_over_long_label_is_refused(): void
    {
        $result = $this->tool()->execute(array(
            'agent_id' => 'u1',
            'content' => 'x',
            'sensitivity' => str_repeat('x', DocgenCaptureStyleMemoryTool::MAX_LABEL + 1),
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Refusals ──────────────────────────────────────────────────────────

    public function test_an_agent_id_is_required(): void
    {
        $result = $this->tool()->execute(array('content' => 'x'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_empty_content_is_refused(): void
    {
        $result = $this->tool()->execute(array('agent_id' => 'u1', 'content' => '   '));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_kind_is_refused(): void
    {
        $result = $this->tool()->execute(array('agent_id' => 'u1', 'content' => 'x', 'kind' => 'poetry'));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function tool(?MemoryStoreInterface $memory = null): DocgenCaptureStyleMemoryTool
    {
        return new DocgenCaptureStyleMemoryTool($this->errors, $memory ?? $this->memory());
    }

    /**
     * A recording memory store.
     *
     * @param string $failTier Tier whose write should fail; empty for none.
     */
    private function memory(string $failTier = ''): MemoryStoreInterface
    {
        return new class ($failTier) implements MemoryStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $records = array();

            public function __construct(private readonly string $failTier)
            {
            }

            public function store(array $record): array
            {
                $this->records[] = $record;

                if ('' !== $this->failTier && ($record['tier'] ?? '') === $this->failTier) {
                    return array('success' => false, 'error' => 'The store refused this tier.');
                }

                return array('success' => true, 'memory_id' => 'mem-' . count($this->records));
            }

            public function get(string $memoryId): array
            {
                return array('found' => false);
            }

            public function update(string $memoryId, array $patch): array
            {
                return array('success' => true);
            }

            public function delete(string $memoryId): array
            {
                return array('success' => true);
            }

            public function search(string $query, array $filters = array(), int $limit = 10): array
            {
                return array();
            }

            public function listByAgent(string $agentId, array $filters = array(), int $limit = 50, int $offset = 0): array
            {
                return array('memories' => array(), 'total' => 0);
            }
        };
    }
}
