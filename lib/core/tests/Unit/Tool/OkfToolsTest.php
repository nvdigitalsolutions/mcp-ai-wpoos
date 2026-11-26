<?php

/**
 * The OKF pair — enrichment writes concepts, routing decides where to look.
 *
 * These run against the real `OkfWriter` and `OkfReader` in a temporary bundle
 * base rather than a double, because the questions are about what lands on disk:
 * a concept id that makes a re-run overwrite, a frontmatter shape the reader can
 * parse back, and a `# Related` section built from real links. A fake writer
 * would answer all three by agreeing with the test.
 *
 * The two design points the suite pins:
 *
 *  - **`resource` is optional and honest.** The runtime has no permalink, so the
 *    key is absent unless the caller supplies a template — and the summary says
 *    which happened rather than leaving the caller to guess.
 *  - **A missing bundle does not invalidate a routing plan.** The plan is the
 *    answer; the empty result set is explained in a note.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Okf\HybridKnowledgeRouter;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\SchemaStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\PostTypeSchema;
use Nvoos\Core\Domain\Entity\TaxonomySchema;
use Nvoos\Core\Domain\Okf\OkfReader;
use Nvoos\Core\Infrastructure\Okf\FilesystemBundleLocator;
use Nvoos\Core\Tool\OkfEnrichSiteContentTool;
use Nvoos\Core\Tool\RouteKnowledgeQueryTool;
use PHPUnit\Framework\TestCase;

final class OkfToolsTest extends TestCase
{
    private string $base;

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/oos-okf-' . bin2hex(random_bytes(5));

        mkdir($this->base, 0775, true);

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
                return (array) $error;
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
                return $this->create('validation_failed', $message, $errors);
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message);
            }
        };
    }

    protected function tearDown(): void
    {
        $this->removeTree($this->base);
    }

    // ── Enrichment ───────────────────────────────────────────────────────────

    public function test_enrichment_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->enrich($this->content(array()));

        $this->assertSame('okf_enrich_site_content', $tool->getSlug());
        $this->assertSame('OKF — Enrich from Site Content', $tool->getName());
        $this->assertSame('manage_options', $tool->getRequiredCapability());
        $this->assertSame(array('local-only', 'state-changing', 'write'), $tool->getCapabilityFlags());
    }

    public function test_a_concept_per_record_lands_on_disk_with_the_documented_shape(): void
    {
        $content = $this->content(array($this->item(1, 'Refund policy', 'refund-policy')));

        $result = $this->enrich($content)->execute(array('bundle' => 'site-content'));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['concepts']);
        $this->assertSame(array('post/refund-policy'), $result['data']['concept_ids']);
        $this->assertFalse($result['data']['resource_links'], 'No template means no resource link, and the summary says so.');

        $reader = new OkfReader($this->bundle('site-content'));
        $concept = $reader->getConcept('post/refund-policy');

        $this->assertSame('Post', $concept['frontmatter']['type']);
        $this->assertSame('Refund policy', $concept['frontmatter']['title']);
        $this->assertSame('stable', $concept['frontmatter']['status']);
        $this->assertSame('process:okf-enrichment', $concept['frontmatter']['generated']['by']);
        $this->assertArrayNotHasKey('resource', $concept['frontmatter']);
        $this->assertStringStartsWith("# Summary\n\n", $concept['body']);
        $this->assertStringContainsString('# Content', $concept['body']);
    }

    public function test_a_re_run_overwrites_the_same_concept_rather_than_duplicating_it(): void
    {
        $content = $this->content(array($this->item(1, 'Refund policy', 'refund-policy')));
        $tool = $this->enrich($content);

        $tool->execute(array('bundle' => 'site-content'));
        $second = $tool->execute(array('bundle' => 'site-content'));

        $this->assertSame(1, $second['data']['concepts']);

        $concepts = array_values(array_filter(
            glob($this->base . '/site-content/post/*.md') ?: array(),
            static fn (string $path): bool => 'index.md' !== basename($path),
        ));

        $this->assertCount(1, $concepts, 'The concept id is the identity, so a re-run refreshes one file.');
        $this->assertSame('refund-policy.md', basename($concepts[0]));
    }

    public function test_a_resource_template_fills_the_links_and_related_section(): void
    {
        $content = $this->content(array(
            $this->item(1, 'Refund policy', 'refund-policy'),
            $this->item(2, 'Shipping policy', 'shipping-policy', 'See <a href="https://example.test/post/refund-policy">the refund rules</a>.'),
        ));

        $result = $this->enrich($content)->execute(array(
            'bundle' => 'site-content',
            'resource_template' => 'https://example.test/{type}/{slug}',
        ));

        $this->assertTrue($result['data']['resource_links']);

        $reader = new OkfReader($this->bundle('site-content'));
        $concept = $reader->getConcept('post/shipping-policy');

        $this->assertSame('https://example.test/post/shipping-policy', $concept['frontmatter']['resource']);
        $this->assertSame('https://example.test/post/shipping-policy', $concept['frontmatter']['sources'][0]['resource']);
        $this->assertStringContainsString('# Related', $concept['body']);
        $this->assertStringContainsString('(/post/refund-policy.md)', $concept['body'], 'Related links are bundle-relative.');
    }

    public function test_include_content_false_leaves_the_body_to_the_summary(): void
    {
        $content = $this->content(array($this->item(1, 'Refund policy', 'refund-policy')));

        $this->enrich($content)->execute(array('bundle' => 'site-content', 'include_content' => false));

        $concept = (new OkfReader($this->bundle('site-content')))->getConcept('post/refund-policy');

        $this->assertStringNotContainsString('# Content', $concept['body']);
    }

    public function test_a_dry_run_writes_nothing(): void
    {
        $content = $this->content(array($this->item(1, 'Refund policy', 'refund-policy')));

        $result = $this->enrich($content)->execute(array('bundle' => 'site-content', 'dry_run' => true));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(1, $result['data']['concepts']);
        $this->assertSame(array(), glob($this->base . '/site-content/*', GLOB_ONLYDIR) ?: array(), 'A rehearsal creates no bundle.');
    }

    public function test_no_published_content_is_a_named_refusal(): void
    {
        $result = $this->enrich($this->content(array()))->execute(array('bundle' => 'site-content'));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('okf_no_content', $result['error']['code']);
    }

    public function test_terms_are_written_under_the_terms_prefix(): void
    {
        $content = $this->content(array($this->item(1, 'Refund policy', 'refund-policy')));

        $result = $this->enrich($content)->execute(array('bundle' => 'site-content', 'include_terms' => true));

        $this->assertSame(array('terms/category/help'), $result['data']['terms']);

        $concept = (new OkfReader($this->bundle('site-content')))->getConcept('terms/category/help');

        $this->assertSame('Term', $concept['frontmatter']['type']);
        $this->assertSame('Help', $concept['frontmatter']['title']);
        $this->assertStringContainsString('3 published item(s)', $concept['body']);
    }

    public function test_an_unusable_bundle_name_is_refused(): void
    {
        $result = $this->enrich($this->content(array()))->execute(array('bundle' => '../escape'));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ── Routing ──────────────────────────────────────────────────────────────

    public function test_the_router_orders_the_matched_sources_then_falls_back(): void
    {
        $router = new HybridKnowledgeRouter();

        $policy = $router->classify('what is the refund policy');
        $this->assertSame('okf', $policy['primary']);
        $this->assertSame(array('okf'), $policy['signals']);
        $this->assertSame(
            array('okf', 'vector', 'paper'),
            array_column($policy['sources'], 'source'),
            'The fallback order is curated, semantic, structured.',
        );

        $incident = $router->classify('list all incidents in the log');
        $this->assertSame('paper', $incident['primary']);

        $similar = $router->classify('find documents similar to this one');
        $this->assertSame('vector', $similar['primary']);
    }

    public function test_an_unmatched_query_still_produces_a_plan(): void
    {
        $plan = (new HybridKnowledgeRouter())->classify('banana');

        $this->assertSame('okf', $plan['primary']);
        $this->assertSame(array(), $plan['signals'], 'No pattern fired, and the plan says so.');
    }

    public function test_the_route_tool_returns_the_plan_and_the_okf_matches(): void
    {
        $this->enrich($this->content(array($this->item(1, 'Refund policy', 'refund-policy'))))
            ->execute(array('bundle' => 'site-knowledge'));

        $tool = $this->route($this->locator());

        $result = $tool->execute(array('query' => 'what is the refund policy'));

        $this->assertTrue($result['success']);
        $this->assertSame('okf', $result['data']['primary']);
        $this->assertSame(array('okf'), $result['data']['signals']);
        $this->assertCount(1, $result['data']['results']);
        $this->assertSame('post/refund-policy', $result['data']['results'][0]['concept_id']);
        $this->assertSame('', $result['data']['note']);
    }

    public function test_a_missing_bundle_explains_the_empty_result_without_losing_the_plan(): void
    {
        $result = $this->route($this->locator())->execute(array('query' => 'what is the refund policy'));

        $this->assertTrue($result['success'], 'The plan is the answer; the empty result is a note.');
        $this->assertSame('okf', $result['data']['primary']);
        $this->assertSame(array(), $result['data']['results']);
        $this->assertStringContainsString('does not exist', $result['data']['note']);
    }

    public function test_the_route_tool_without_a_bundle_store_says_so(): void
    {
        $result = $this->route(null)->execute(array('query' => 'what is the refund policy'));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('No OKF bundle store', $result['data']['note']);
    }

    public function test_the_route_tool_requires_a_query(): void
    {
        $result = $this->route($this->locator())->execute(array('query' => '  '));

        $this->assertArrayNotHasKey('success', $result);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function enrich(ContentStoreInterface $content): OkfEnrichSiteContentTool
    {
        return new OkfEnrichSiteContentTool($this->errors, $content, $this->schemaFake(), $this->locator());
    }

    private function route($locator): RouteKnowledgeQueryTool
    {
        return new RouteKnowledgeQueryTool($this->errors, new HybridKnowledgeRouter(), $locator);
    }

    private function locator(): FilesystemBundleLocator
    {
        return new FilesystemBundleLocator($this->base);
    }

    private function bundle(string $name)
    {
        return $this->locator()->rootFor($name);
    }

    private function item(int $id, string $title, string $slug, string $content = 'Body text'): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: 'publish',
            type: 'post',
            authorId: 1,
            createdAt: new \DateTimeImmutable('2026-01-01T09:00:00+00:00'),
            updatedAt: new \DateTimeImmutable('2026-02-01T09:00:00+00:00'),
            meta: array(),
            taxonomy: array('category' => array('Help')),
            excerpt: null,
            slug: $slug,
        );
    }

    private function schemaFake(): SchemaStoreInterface
    {
        return new class () implements SchemaStoreInterface {
            public function getPostType(string $type): ?PostTypeSchema
            {
                return 'post' === $type ? new PostTypeSchema('post', 'Post') : null;
            }

            public function listPostTypes(): array
            {
                return array($this->getPostType('post'));
            }

            public function getTaxonomy(string $taxonomy): ?TaxonomySchema
            {
                return 'category' === $taxonomy ? new TaxonomySchema('category', 'Category') : null;
            }

            public function listTaxonomies(string $postType): array
            {
                return 'post' === $postType ? array(new TaxonomySchema('category', 'Category')) : array();
            }

            public function getPostStatuses(): array
            {
                return array('publish', 'draft');
            }

            public function postTypeSupports(string $postType, string $feature): bool
            {
                return true;
            }
        };
    }

    /**
     * @param list<ContentItem> $items
     */
    private function content(array $items): ContentStoreInterface
    {
        return new class ($items) implements ContentStoreInterface {
            /** @var list<ContentItem> */
            private array $items;

            public ?ContentQuery $lastQuery = null;

            /**
             * @param list<ContentItem> $items
             */
            public function __construct(array $items)
            {
                $this->items = $items;
            }

            public function find(int $id, ?int $userId = null): ?ContentItem
            {
                foreach ($this->items as $item) {
                    if ($item->id === $id) {
                        return $item;
                    }
                }

                return null;
            }

            public function query(ContentQuery $query): ContentCollection
            {
                $this->lastQuery = $query;

                return new ContentCollection($this->items, count($this->items), 1, $query->perPage, 1);
            }

            public function create(\Nvoos\Core\Domain\Entity\CreateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used');
            }

            public function update(int $id, \Nvoos\Core\Domain\Entity\UpdateContentCommand $command): ContentItem
            {
                throw new \LogicException('not used');
            }

            public function delete(int $id, int $userId): void
            {
            }

            public function getMeta(int $id): array
            {
                return array();
            }

            public function getTaxonomyTerms(int $id): array
            {
                return array();
            }

            public function listTaxonomyTerms(string $taxonomy, int $limit = 200): array
            {
                return 'category' === $taxonomy
                    ? array(array('id' => 7, 'name' => 'Help', 'slug' => 'help', 'count' => 3))
                    : array();
            }

            public function userCanAccess(int $id, int $userId, string $operation = 'read'): bool
            {
                return true;
            }
        };
    }

    private function removeTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        foreach ((array) scandir($dir) as $entry) {
            if ('.' === $entry || '..' === $entry || ! is_string($entry)) {
                continue;
            }

            $path = $dir . DIRECTORY_SEPARATOR . $entry;

            if (is_dir($path)) {
                $this->removeTree($path);
                continue;
            }

            @unlink($path);
        }

        @rmdir($dir);
    }
}
