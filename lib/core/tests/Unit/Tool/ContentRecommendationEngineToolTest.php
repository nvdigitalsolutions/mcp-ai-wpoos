<?php

/**
 * `content_recommendation_engine`.
 *
 * The WordPress tool this replaces advertises semantic search and collaborative
 * filtering it never performs, and scores its category mode with the constant `0.8`.
 * These tests pin the replacement's actual behaviour: which rows are considered,
 * which are excluded, what the score is made of, and the exact refusal each
 * unsupported action produces.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Tool\ContentRecommendationEngineRankingTool;
use PHPUnit\Framework\TestCase;

final class ContentRecommendationEngineToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || ( \is_array($value) && isset($value['error']) )
        );

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => $code, 'message' => $message ),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'validation_failed', 'message' => $message ),
            )
        );
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = new ContentRecommendationEngineRankingTool($this->errors);

        $this->assertSame('content_recommendation_engine', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertNotContains('consumes-tokens', $tool->getCapabilityFlags());
        $this->assertNotContains('external-api', $tool->getCapabilityFlags());
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_a_host_without_a_content_store_says_so(): void
    {
        $result = (new ContentRecommendationEngineRankingTool($this->errors, null))
            ->execute(array( 'content' => 'Widget installation.' ));

        $this->assertSame('content_store_unavailable', $result['error']['code']);
    }

    public function test_it_requires_a_source(): void
    {
        $result = $this->tool($this->store(array()))
            ->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('post_id or some content', $result['error']['message']);
    }

    public function test_a_post_id_that_does_not_resolve_is_named(): void
    {
        $result = $this->tool($this->store(array()))->execute(array( 'post_id' => 4242 ));

        $this->assertSame('content_not_found', $result['error']['code']);
        $this->assertStringContainsString('4242', $result['error']['message']);
    }

    public function test_training_is_refused_because_there_is_no_model(): void
    {
        $result = $this->tool($this->store(array()))->execute(array( 'action' => 'train_model' ));

        $this->assertSame('unsupported_action', $result['error']['code']);
        $this->assertStringContainsString('nothing to fit', $result['error']['message']);
    }

    public function test_tracking_is_refused_because_there_is_no_interaction_store(): void
    {
        $result = $this->tool($this->store(array()))->execute(array( 'action' => 'track_interaction' ));

        $this->assertSame('unsupported_action', $result['error']['code']);
        $this->assertStringContainsString('records no interactions', $result['error']['message']);
    }

    public function test_performance_analysis_is_refused_for_the_same_reason(): void
    {
        $result = $this->tool($this->store(array()))->execute(array( 'action' => 'analyze_performance' ));

        $this->assertSame('unsupported_action', $result['error']['code']);
        $this->assertStringContainsString('no click-through', $result['error']['message']);
    }

    // ── Retrieval contract ───────────────────────────────────────────────────

    public function test_it_queries_published_content_only(): void
    {
        $seen = array();

        $this->tool($this->store(array( $this->item(2, 'Widget care') ), $seen))
            ->execute(array( 'content' => 'Widget installation guide.' ));

        $this->assertNotEmpty($seen);
        $this->assertSame(array( 'publish' ), $seen[0]->statuses);
    }

    public function test_the_source_is_excluded_from_its_own_recommendations(): void
    {
        $seen = array();
        $source = $this->item(1, 'Widget fitting', 'Fitting a widget.');

        $this->tool($this->store(array( $source ), $seen), $source)
            ->execute(array( 'post_id' => 1 ));

        $this->assertContains(1, $seen[0]->exclude);
    }

    public function test_caller_exclusions_reach_the_query(): void
    {
        $seen = array();

        $this->tool($this->store(array( $this->item(2, 'Widget care') ), $seen))
            ->execute(array(
                'content'       => 'Widget installation guide.',
                'exclude_posts' => array( 7, 8 ),
            ));

        $this->assertContains(7, $seen[0]->exclude);
        $this->assertContains(8, $seen[0]->exclude);
    }

    // ── Scoring ──────────────────────────────────────────────────────────────

    public function test_it_ranks_by_shared_terms_and_reports_the_components(): void
    {
        $source = $this->item(1, 'Widget fitting', 'Fitting a widget guide.', array( 'category' => array( 'Workshop' ) ));

        $sharing = $this->item(2, 'Widget care', 'Widget maintenance.', array( 'category' => array( 'Workshop' ) ));
        $unrelated = $this->item(3, 'Sourdough', 'Bread.', array( 'category' => array( 'Baking' ) ));

        $result = $this->tool($this->store(array( $sharing, $unrelated )), $source)
            ->execute(array( 'post_id' => 1 ));

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['recommendations']);
        $this->assertSame(2, $result['data']['recommendations'][0]['id']);

        // The score is not a mystery: the shared category is reported as 1.0 and the
        // unrelated item carries 0.
        $this->assertSame(1.0, $result['data']['recommendations'][0]['signals']['taxonomy']);
        $this->assertSame(0.0, $result['data']['recommendations'][1]['signals']['taxonomy']);
        $this->assertGreaterThan(
            $result['data']['recommendations'][1]['score'],
            $result['data']['recommendations'][0]['score']
        );
    }

    public function test_the_score_follows_the_published_weights(): void
    {
        $source = $this->item(
            1,
            'Widget fitting',
            'Fitting a widget guide.',
            array( 'category' => array( 'Workshop' ), 'post_tag' => array( 'widget' ) )
        );

        $candidate = $this->item(
            2,
            'Widget fitting',
            'Fitting a widget guide.',
            array( 'category' => array( 'Workshop' ), 'post_tag' => array( 'widget' ) )
        );

        $result = $this->tool($this->store(array( $candidate )), $source)
            ->execute(array( 'post_id' => 1 ));

        // 0.4 taxonomy + 0.3 tag + 0.3 title, all perfect.
        $this->assertSame(1.0, $result['data']['recommendations'][0]['score']);
    }

    public function test_unrelated_titles_do_not_score_as_similar(): void
    {
        // `similar_text()`, which WordPress uses, scores these highly because they
        // share most of their letters. Token overlap does not.
        $source = $this->item(1, 'Widget fitting', 'Fitting a widget.', array( 'category' => array( 'A' ) ));
        $candidate = $this->item(2, 'Sourdough starter', 'Bread.', array( 'category' => array( 'B' ) ));

        $result = $this->tool($this->store(array( $candidate )), $source)
            ->execute(array( 'post_id' => 1 ));

        $this->assertSame(0.0, $result['data']['recommendations'][0]['signals']['title']);
    }

    public function test_an_item_with_no_terms_falls_back_to_text_overlap(): void
    {
        $source = $this->item(1, 'Widget fitting', 'Widget fitting explained.');

        $close = $this->item(2, 'Widget fitting notes', 'Widget fitting in practice.');
        $far = $this->item(3, 'Sourdough notes', 'Bread and water.');

        $result = $this->tool($this->store(array( $far, $close )), $source)
            ->execute(array( 'post_id' => 1 ));

        $this->assertSame(2, $result['data']['recommendations'][0]['id']);
        $this->assertArrayHasKey('text', $result['data']['recommendations'][0]['signals']);
        $this->assertArrayNotHasKey('taxonomy', $result['data']['recommendations'][0]['signals']);
        $this->assertGreaterThan(
            $result['data']['recommendations'][1]['signals']['text'],
            $result['data']['recommendations'][0]['signals']['text']
        );
    }

    public function test_equal_scores_are_ordered_by_id_so_the_answer_is_reproducible(): void
    {
        $source = $this->item(1, 'Widget fitting', 'Widget.', array( 'category' => array( 'Workshop' ) ));

        $nine = $this->item(9, 'Bread', 'Bread.');
        $seven = $this->item(7, 'Bread', 'Bread.');

        $result = $this->tool($this->store(array( $nine, $seven )), $source)
            ->execute(array( 'post_id' => 1 ));

        $this->assertSame(7, $result['data']['recommendations'][0]['id']);
    }

    public function test_the_limit_is_honoured(): void
    {
        $items = array(
            $this->item(2, 'Widget one'),
            $this->item(3, 'Widget two'),
            $this->item(4, 'Widget three'),
        );

        $result = $this->tool($this->store($items))
            ->execute(array( 'content' => 'Widget guide.', 'limit' => 1 ));

        $this->assertCount(1, $result['data']['recommendations']);
        $this->assertSame(1, $result['data']['count']);
    }

    // ── Category mode ────────────────────────────────────────────────────────

    public function test_category_mode_keeps_only_sharers_and_scores_them_honestly(): void
    {
        $source = $this->item(1, 'Widget fitting', 'Widget.', array( 'category' => array( 'Workshop' ) ));

        $sharing = $this->item(2, 'Widget care', 'Widget.', array( 'category' => array( 'Workshop' ) ));
        $other = $this->item(3, 'Widget baking', 'Widget.', array( 'category' => array( 'Baking' ) ));

        $result = $this->tool($this->store(array( $sharing, $other )), $source)
            ->execute(array( 'post_id' => 1, 'recommendation_type' => 'category_based' ));

        $this->assertCount(1, $result['data']['recommendations']);
        $this->assertSame(2, $result['data']['recommendations'][0]['id']);
        $this->assertSame('category', $result['data']['recommendations'][0]['reason']);

        // WordPress returns a literal 0.8 here. A real score is not a constant.
        $this->assertNotSame(0.8, $result['data']['recommendations'][0]['score']);
    }

    public function test_category_mode_without_terms_is_a_finding_not_an_error(): void
    {
        $source = $this->item(1, 'Widget fitting', 'Widget.');

        $result = $this->tool($this->store(array( $this->item(2, 'Widget care') )), $source)
            ->execute(array( 'post_id' => 1, 'recommendation_type' => 'category_based' ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['recommendations']);
        $this->assertStringContainsString('no categories or tags', $result['message']);
    }

    // ── Trending and the personalized fallback ───────────────────────────────

    public function test_trending_ranks_the_last_seven_days_by_recency(): void
    {
        $fresh = $this->item(2, 'Fresh', 'Widget.', array(), 1);
        $older = $this->item(3, 'Older', 'Widget.', array(), 5);
        $stale = $this->item(4, 'Stale', 'Widget.', array(), 30);

        $result = $this->tool($this->store(array( $stale, $older, $fresh )))
            ->execute(array( 'recommendation_type' => 'trending' ));

        $this->assertSame('recency', $result['data']['strategy']);
        $this->assertCount(2, $result['data']['recommendations'], 'The 30-day-old row is outside the window.');
        $this->assertSame(2, $result['data']['recommendations'][0]['id']);
        $this->assertSame(3, $result['data']['recommendations'][1]['id']);
        $this->assertGreaterThan(
            $result['data']['recommendations'][1]['score'],
            $result['data']['recommendations'][0]['score']
        );
    }

    public function test_trending_with_nothing_in_the_window_is_a_finding(): void
    {
        $result = $this->tool($this->store(array( $this->item(2, 'Stale', 'Widget.', array(), 30) )))
            ->execute(array( 'recommendation_type' => 'trending' ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['recommendations']);
        $this->assertStringContainsString('last 7 days', $result['message']);
    }

    public function test_personalized_reports_the_recency_fallback(): void
    {
        $result = $this->tool($this->store(array( $this->item(2, 'Fresh', 'Widget.', array(), 1) )))
            ->execute(array( 'recommendation_type' => 'personalized' ));

        $this->assertSame('trending', $result['data']['fallback']);
        $this->assertStringContainsString('no reading history', $result['data']['fallback_reason']);
    }

    // ── Empty retrieval ──────────────────────────────────────────────────────

    public function test_nothing_matching_is_a_finding_that_names_the_terms(): void
    {
        $result = $this->tool($this->store(array()))
            ->execute(array( 'content' => 'Widget installation guide.' ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['recommendations']);
        $this->assertSame(0, $result['data']['candidates_considered']);
        $this->assertStringContainsString('widget', \mb_strtolower($result['message']));
    }

    public function test_a_source_with_no_words_is_a_finding(): void
    {
        $result = $this->tool($this->store(array()))
            ->execute(array( 'content' => 'a b c 1 2 3' ));

        $this->assertTrue($result['success']);
        $this->assertStringContainsString('No searchable topic', $result['message']);
    }

    public function test_an_unknown_recommendation_type_is_refused(): void
    {
        $result = $this->tool($this->store(array()))
            ->execute(array( 'content' => 'Widget.', 'recommendation_type' => 'collaborative' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<string, array<int, string>> $taxonomy
     */
    private function item(
        int $id,
        string $title,
        string $content = '',
        array $taxonomy = array(),
        int $daysAgo = 0,
    ): ContentItem {
        $created = new \DateTimeImmutable(\sprintf('-%d days', $daysAgo));

        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: 'publish',
            type: 'post',
            authorId: 1,
            createdAt: $created,
            updatedAt: $created,
            taxonomy: $taxonomy,
            excerpt: '',
            slug: 'item-' . $id,
        );
    }

    /**
     * A store that returns every item it was given and records the queries.
     *
     * Scoring and filtering are what these tests exercise; matching by search term
     * is the adapter's job and is pinned by the feature test against the real store.
     *
     * @param list<ContentItem>     $items
     * @param list<ContentQuery>    $seen
     */
    private function store(array $items, array &$seen = array()): ContentStoreInterface
    {
        $store = $this->createMock(ContentStoreInterface::class);

        $store->method('query')->willReturnCallback(
            static function (ContentQuery $query) use ($items, &$seen): ContentCollection {
                $seen[] = $query;

                return new ContentCollection(
                    items: $items,
                    total: \count($items),
                    page: 1,
                    perPage: $query->perPage,
                    totalPages: 1,
                );
            }
        );

        return $store;
    }

    private function tool(ContentStoreInterface $store, ?ContentItem $source = null): ContentRecommendationEngineRankingTool
    {
        if (null !== $source) {
            // Rebuild the store bound to this source so `find()` resolves it.
            $store = $this->storeReturning($store, $source);
        }

        return new ContentRecommendationEngineRankingTool($this->errors, $store);
    }

    /**
     * Wrap a store so `find()` resolves the given item and `query()` delegates.
     */
    private function storeReturning(ContentStoreInterface $store, ContentItem $source): ContentStoreInterface
    {
        $wrapper = $this->createMock(ContentStoreInterface::class);

        $wrapper->method('find')->willReturnCallback(
            static fn (int $id, ?int $userId = null): ?ContentItem => $source->id === $id ? $source : null
        );

        $wrapper->method('query')->willReturnCallback(
            static fn (ContentQuery $query): ContentCollection => $store->query($query)
        );

        return $wrapper;
    }
}
