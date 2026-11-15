<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Domain\Contract\ToolInvokerInterface;
use Nvoos\Core\Domain\Service\Harness\RetrievalHarness;
use Tests\TestCase;

/**
 * Retrieval harness wiring — the runtime seam, not the pipeline.
 *
 * The pipeline itself is covered by the core unit tests. What matters here is
 * that the container resolves the harness, that the invoker is wired to the real
 * tool registry, and that a primitive which errors (no embedding provider, no
 * data) is skipped instead of failing the call.
 */
class RetrievalHarnessWiringTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_harness_resolves_from_the_container(): void
    {
        $this->assertInstanceOf(RetrievalHarness::class, app(RetrievalHarness::class));
    }

    public function test_retrieval_tries_every_source_and_stays_well_formed(): void
    {
        $result = app(RetrievalHarness::class)->retrieve('quarterly revenue', ['assistant_id' => 0], 3);

        $this->assertSame(
            ['recall_memory', 'semantic_context_search', 'retrieve_agent_memory'],
            $result['sources_tried']
        );

        $this->assertArrayHasKey('passages', $result);
        $this->assertArrayHasKey('citations', $result);
        $this->assertArrayHasKey('freshness', $result);
        $this->assertArrayHasKey('recall_confidence', $result);

        // Whatever the empty app returns, every passage must carry provenance.
        foreach ($result['passages'] as $passage) {
            $this->assertArrayHasKey('citation', $passage);
            $this->assertNotEmpty($passage['citation']['source']);
            $this->assertNotEmpty($passage['citation']['content_hash']);
        }

        $this->assertCount(count($result['passages']), $result['citations']);
    }

    public function test_an_unregistered_tool_is_skipped_rather_than_thrown(): void
    {
        $this->assertNull(app(ToolInvokerInterface::class)->invoke('definitely_not_a_tool', [], []));
    }

    public function test_citation_support_is_computed_over_real_passages(): void
    {
        $result = app(RetrievalHarness::class)->verifyCitations(
            'Revenue rose twelve percent in the third quarter of the year.',
            [['text' => 'Revenue rose twelve percent in the third quarter of the year.']]
        );

        $this->assertTrue($result['covered']);
        $this->assertSame(1.0, $result['coverage_ratio']);
    }
}
