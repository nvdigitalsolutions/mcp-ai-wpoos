<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

/**
 * 090-b — the OKF knowledge drawer (`/api/v1/pro/okf`).
 *
 * Fixtures are written to a temporary knowledge root so the core reader, the
 * frontmatter allowlist and the traversal guard are exercised for real. The
 * payloads are the ones `OkfClient` reads: unwrapped, no `success` envelope.
 */
class OkfTest extends TestCase
{
    use RefreshDatabase;

    private string $root;

    protected function setUp(): void
    {
        parent::setUp();

        $this->root = sys_get_temp_dir().'/nvoos-okf-'.bin2hex(random_bytes(4));
        mkdir($this->root, 0777, true);

        config(['oos.okf.path' => $this->root]);

        Sanctum::actingAs(User::factory()->admin()->create());
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->root);

        parent::tearDown();
    }

    private function removeTree(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        foreach (scandir($path) ?: [] as $entry) {
            if ('.' === $entry || '..' === $entry) {
                continue;
            }

            $child = $path.'/'.$entry;

            if (is_dir($child)) {
                $this->removeTree($child);
            } else {
                @unlink($child);
            }
        }

        @rmdir($path);
    }

    private function writeConcept(string $bundle, string $id, string $markdown): void
    {
        $directory = $this->root.'/'.$bundle;
        $file = $directory.'/'.$id.'.md';

        if (! is_dir(dirname($file))) {
            mkdir(dirname($file), 0777, true);
        }

        file_put_contents($file, $markdown);
    }

    private function seedPolicies(): void
    {
        $this->writeConcept('policies', 'returns', <<<'MD'
---
title: Returns
description: How returns are handled.
type: policy
tags: [returns, refunds]
status: stable
stale_after: 2020-01-01
verified:
  - by: human:alice
    at: 2026-01-01
internal_notes: never exposed
---

Refunds are covered in [refunds](refunds.md).
MD);

        $this->writeConcept('policies', 'refunds', <<<'MD'
---
title: Refunds
description: Refund timeline.
type: policy
tags:
  - refunds
status: deprecated
---

See [returns](returns.md).
MD);
    }

    public function test_a_missing_root_serves_an_empty_bundle_list(): void
    {
        config(['oos.okf.path' => $this->root.'/nope']);

        $this->getJson('/api/v1/pro/okf/bundles')
            ->assertOk()
            ->assertExactJson(['bundles' => []]);
    }

    public function test_bundles_describe_their_concepts(): void
    {
        $this->seedPolicies();

        $bundles = $this->getJson('/api/v1/pro/okf/bundles')->assertOk()->json('bundles');

        $this->assertCount(1, $bundles);
        $bundle = $bundles[0];

        $this->assertSame('policies', $bundle['name']);
        $this->assertFalse($bundle['protected']);
        $this->assertSame(2, $bundle['concept_count']);
        $this->assertSame(1, $bundle['stale_count']);
        $this->assertSame(1, $bundle['deprecated_count']);
        $this->assertTrue($bundle['conformant']);
        $this->assertSame(0, $bundle['issue_count']);
        $this->assertSame(['policy'], $bundle['types']);
        $this->assertSame(
            ['unverified' => 1, 'machine-confirmed' => 0, 'human-reviewed' => 1],
            $bundle['trust_tiers']
        );
        $this->assertIsInt($bundle['modified']);
    }

    public function test_a_broken_internal_link_makes_a_bundle_non_conformant(): void
    {
        $this->writeConcept('policies', 'returns', <<<'MD'
---
title: Returns
type: policy
---

See [missing](missing.md).
MD);

        $bundle = $this->getJson('/api/v1/pro/okf/bundles')->json('bundles.0');

        $this->assertFalse($bundle['conformant']);
        $this->assertSame(1, $bundle['issue_count']);
    }

    public function test_concepts_are_filterable(): void
    {
        $this->seedPolicies();

        $all = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts')->assertOk()->json();
        $this->assertSame('policies', $all['bundle']);
        $this->assertSame(2, $all['total']);

        $byType = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts?type=policy')->json();
        $this->assertSame(2, $byType['total']);

        $deprecated = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts?status=deprecated')->json();
        $this->assertSame(1, $deprecated['total']);
        $this->assertSame('refunds', $deprecated['concepts'][0]['concept_id']);

        // `q` matches tags as well as id/title/description, so `refund` hits the
        // `refunds` tag on `returns` too. `timeline` is unique to one concept.
        $search = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts?q=timeline')->json();
        $this->assertSame(1, $search['total']);
        $this->assertSame('refunds', $search['concepts'][0]['concept_id']);

        $limited = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts?limit=1')->json();
        $this->assertSame(1, $limited['total']);

        // `returns` is stale; excluding stale concepts drops it.
        $fresh = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts?include_stale=0')->json();
        $this->assertSame(1, $fresh['total']);
        $this->assertSame('refunds', $fresh['concepts'][0]['concept_id']);
    }

    public function test_a_concept_returns_allowlisted_frontmatter_and_links(): void
    {
        $this->seedPolicies();

        $detail = $this->getJson('/api/v1/pro/okf/bundles/policies/concepts/returns')->assertOk()->json();

        $this->assertSame('policies', $detail['bundle']);
        $this->assertSame('returns', $detail['concept_id']);
        $this->assertSame('Returns', $detail['frontmatter']['title']);
        $this->assertArrayNotHasKey('internal_notes', $detail['frontmatter']);
        $this->assertStringContainsString('Refunds are covered', $detail['body']);
        // Links are resolved concept ids, not filenames.
        $this->assertSame(['refunds'], $detail['links']);
        $this->assertSame('human-reviewed', $detail['trust_tier']);
        $this->assertTrue($detail['stale']);
    }

    public function test_unknown_bundles_and_concepts_are_404(): void
    {
        $this->seedPolicies();

        $this->getJson('/api/v1/pro/okf/bundles/nope/concepts')->assertNotFound();
        $this->getJson('/api/v1/pro/okf/bundles/policies/concepts/nope')->assertNotFound();
    }

    public function test_a_traversing_concept_id_is_refused(): void
    {
        $this->seedPolicies();

        $this->getJson('/api/v1/pro/okf/bundles/policies/concepts/..%2F..%2Fetc%2Fpasswd')
            ->assertNotFound();
    }

    public function test_search_spans_bundles_and_reports_the_bundle(): void
    {
        $this->seedPolicies();
        $this->writeConcept('handbook', 'onboarding', <<<'MD'
---
title: Onboarding
description: First week.
type: guide
---

Welcome.
MD);

        $body = $this->getJson('/api/v1/pro/okf/search?q=timeline')->assertOk()->json();

        $this->assertSame('timeline', $body['query']);
        $this->assertSame(1, $body['total']);
        $this->assertSame('policies', $body['results'][0]['bundle']);
        $this->assertSame('refunds', $body['results'][0]['concept_id']);

        $this->getJson('/api/v1/pro/okf/search?q=')
            ->assertOk()
            ->assertExactJson(['query' => '', 'results' => [], 'total' => 0]);
    }

    public function test_skills_is_honest_empty_divorced_from_grants(): void
    {
        $this->seedPolicies();

        $this->getJson('/api/v1/pro/okf/skills?assistant_id=7')
            ->assertOk()
            ->assertExactJson(['assistant_id' => 7, 'skills' => []]);
    }

    public function test_any_authenticated_reader_can_use_the_drawer(): void
    {
        $this->seedPolicies();

        Sanctum::actingAs(User::factory()->editor()->create());

        $this->getJson('/api/v1/pro/okf/bundles')->assertOk();
    }

    public function test_guests_are_refused(): void
    {
        $this->app['auth']->forgetGuards();

        $this->getJson('/api/v1/pro/okf/bundles')->assertUnauthorized();
    }
}
