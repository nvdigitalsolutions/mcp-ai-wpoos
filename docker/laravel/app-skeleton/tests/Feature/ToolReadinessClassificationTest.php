<?php

namespace Tests\Feature;

use Nvoos\Core\Application\Tool\ToolRegistry;
use Tests\TestCase;

/**
 * 149 §85.1 → §91 — the HuggingFace dataset tools are served, on evidence.
 *
 * §85.1 marked them `unavailable` because nothing had probed them: they call
 * `datasets-server.huggingface.co` and a gated dataset needs a token. It also
 * left this test behind with the explicit instruction that "a future flip to
 * `ready` has to come with tests, not with a string edit".
 *
 * §91 is that flip. `HuggingFaceDatasetToolsTest` records the evidence — endpoint,
 * dataset argument, bearer header, and the upstream-failure path — and this test
 * now pins the consequence in the other direction.
 *
 * One of the eleven was misclassified rather than unproven:
 * `huggingface_recommended_datasets` returns a static list and makes no request,
 * so it is `pure`, not `ready`.
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

    public function test_the_huggingface_dataset_tools_are_served(): void
    {
        $registry = app(ToolRegistry::class);

        $this->assertGreaterThan(
            50,
            $registry->count(),
            'The registry must actually be populated, or "present" proves nothing.'
        );

        foreach (self::HUGGINGFACE_DATASET_TOOLS as $slug) {
            $this->assertTrue(
                $registry->has($slug),
                $slug.' must be served: §91 recorded its evidence in HuggingFaceDatasetToolsTest.'
            );
        }
    }
}
