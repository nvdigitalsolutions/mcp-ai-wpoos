<?php

namespace Tests\Feature;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Tests\TestCase;

/**
 * 149 §85 — the HuggingFace dataset tools are deliberately unavailable.
 *
 * They were `unverified` ("auto-added by the auditor"), which already meant
 * "not served", but left the reason ambiguous: nobody had decided. They call an
 * external service (`datasets-server.huggingface.co`) and gated datasets need a
 * token, so serving them without evidence is the §35 objection. The manifest now
 * says `unavailable`, and this test pins the consequence — a future flip to
 * `ready` has to come with tests, not with a string edit.
 */
class ToolReadinessClassificationTest extends TestCase
{
    /** @var list<string> */
    private const HUGGINGFACE_DATASET_TOOLS = [
        'huggingface_dataset_filter',
        'huggingface_dataset_get_info',
        'huggingface_dataset_get_parquet',
        'huggingface_dataset_get_rows',
        'huggingface_dataset_get_size',
        'huggingface_dataset_get_statistics',
        'huggingface_dataset_is_valid',
        'huggingface_dataset_list_splits',
        'huggingface_dataset_preview_rows',
        'huggingface_dataset_search',
        'huggingface_recommended_datasets',
    ];

    public function test_the_huggingface_dataset_tools_are_not_served(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertGreaterThan(
            50,
            $registry->count(),
            'The registry must actually be populated, or "not present" proves nothing.'
        );

        foreach (self::HUGGINGFACE_DATASET_TOOLS as $slug) {
            $this->assertFalse(
                $registry->has($slug),
                $slug.' must not be served: the datasets-server path has no recorded evidence (Proposal 149 §85).'
            );
        }
    }
}
