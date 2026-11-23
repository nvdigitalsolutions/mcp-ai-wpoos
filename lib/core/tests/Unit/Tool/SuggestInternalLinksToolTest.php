<?php

/**
 * `suggest_internal_links`.
 *
 * The important assertion is `test_an_invented_id_is_dropped`: the model is handed
 * a list of real pages and asked to choose, and a model that returns an id it was
 * not given must not become a link to a page that does not exist. Everything else
 * here guards the retrieval that makes the list real.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\SuggestInternalLinksCompletionTool;
use PHPUnit\Framework\TestCase;

final class SuggestInternalLinksToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || (\is_array($value) && isset($value['error']))
        );
    }

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = new SuggestInternalLinksCompletionTool($this->errors);

        $this->assertSame('suggest_internal_links', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertContains('external-api', $tool->getCapabilityFlags());
    }

    public function test_content_is_required(): void
    {
        $this->errors->expects($this->once())
            ->method('validationFailed')
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'validation_failed' ) ));

        $result = $this->tool()->execute(array( 'content' => '   ' ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_host_without_a_content_store_says_so(): void
    {
        $this->errors->expects($this->once())
            ->method('create')
            ->with('content_store_unavailable', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'content_store_unavailable' ) ));

        $result = (new SuggestInternalLinksCompletionTool($this->errors, $this->router($this->client()), null))
            ->execute(array( 'content' => 'Widget installation guide.' ));

        $this->assertSame('content_store_unavailable', $result['error']['code']);
    }

    public function test_a_page_with_no_topic_returns_no_suggestions(): void
    {
        $result = $this->tool($this->store(array()))->execute(array( 'content' => 'a b c d e f' ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['suggestions']);
        $this->assertSame(0, $result['data']['candidates_considered']);
    }

    public function test_nothing_matching_in_the_store_is_a_result_not_a_failure(): void
    {
        $result = $this->tool($this->store(array()))->execute(array(
            'content' => 'Widget installation guide for the workshop.',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['suggestions']);
    }

    public function test_it_searches_the_store_and_returns_valid_suggestions(): void
    {
        $store = $this->store(array(
            $this->item(11, 'Fitting a widget'),
            $this->item(12, 'Widget maintenance'),
        ));

        $answer = \json_encode(array(
            'suggestions' => array(
                array(
                    'content_id' => 11,
                    'anchor_text' => 'fitting a widget',
                    'reason' => 'Direct follow-on.',
                    'relevance' => 0.9,
                ),
                array(
                    'content_id' => 12,
                    'anchor_text' => 'widget maintenance',
                    'reason' => 'Next step.',
                    'relevance' => 0.75,
                ),
            ),
        ));

        $result = $this->tool($store, (string) $answer)->execute(array(
            'content' => 'Widget installation guide for the workshop.',
        ));

        $this->assertTrue($result['success']);
        $this->assertCount(2, $result['data']['suggestions']);
        $this->assertSame(11, $result['data']['suggestions'][0]['content_id']);
        $this->assertSame('Fitting a widget', $result['data']['suggestions'][0]['title']);
        $this->assertSame(0, $result['data']['dropped']);

        // Terms were derived from the content, and "widget" is among them.
        $this->assertContains('widget', $result['data']['terms']);
    }

    public function test_an_invented_id_is_dropped_and_counted(): void
    {
        $store = $this->store(array( $this->item(11, 'Fitting a widget') ));

        $answer = \json_encode(array(
            'suggestions' => array(
                array( 'content_id' => 11, 'anchor_text' => 'fitting', 'reason' => 'ok', 'relevance' => 0.9 ),
                array(
                    'content_id' => 999,
                    'anchor_text' => 'made up',
                    'reason' => 'not a real page',
                    'relevance' => 0.99,
                ),
            ),
        ));

        $result = $this->tool($store, (string) $answer)->execute(array(
            'content' => 'Widget installation guide.',
        ));

        // One real suggestion, and the invented id is not in the output at all.
        $this->assertCount(1, $result['data']['suggestions']);
        $this->assertSame(11, $result['data']['suggestions'][0]['content_id']);
        $this->assertSame(1, $result['data']['dropped']);
        $this->assertStringContainsString('invented id', $result['message']);
    }

    public function test_a_suggestion_below_the_relevance_floor_is_discarded(): void
    {
        $store = $this->store(array( $this->item(11, 'Fitting a widget') ));

        $answer = \json_encode(array(
            'suggestions' => array(
                array( 'content_id' => 11, 'anchor_text' => 'x', 'reason' => 'weak', 'relevance' => 0.2 ),
            ),
        ));

        $result = $this->tool($store, (string) $answer)->execute(array(
            'content' => 'Widget installation guide.',
            'min_relevance' => 0.6,
        ));

        $this->assertSame(array(), $result['data']['suggestions']);
    }

    public function test_max_suggestions_is_honoured(): void
    {
        $store = $this->store(array(
            $this->item(11, 'Fitting a widget'),
            $this->item(12, 'Widget maintenance'),
            $this->item(13, 'Widget cleaning'),
        ));

        $answer = \json_encode(array(
            'suggestions' => array(
                array( 'content_id' => 11, 'anchor_text' => 'a', 'reason' => 'r', 'relevance' => 0.9 ),
                array( 'content_id' => 12, 'anchor_text' => 'b', 'reason' => 'r', 'relevance' => 0.9 ),
                array( 'content_id' => 13, 'anchor_text' => 'c', 'reason' => 'r', 'relevance' => 0.9 ),
            ),
        ));

        $result = $this->tool($store, (string) $answer)->execute(array(
            'content' => 'Widget installation guide.',
            'max_suggestions' => 2,
        ));

        $this->assertCount(2, $result['data']['suggestions']);
    }

    public function test_the_query_override_replaces_derived_terms(): void
    {
        $store = $this->store(array( $this->item(11, 'Fitting a widget') ));

        $captured = array();

        $tool = new SuggestInternalLinksCompletionTool(
            $this->errors,
            $this->router($this->client('{"suggestions":[]}', $captured)),
            $store
        );

        $result = $tool->execute(array(
            'content' => 'A page about something unrelated entirely.',
            'query'   => 'widget fitting',
        ));

        $this->assertSame(array( 'widget fitting' ), $result['data']['terms']);
    }

    public function test_an_unreadable_answer_is_an_error(): void
    {
        $store = $this->store(array( $this->item(11, 'Fitting a widget') ));

        $this->errors->expects($this->once())
            ->method('create')
            ->with('unparseable_response', $this->anything())
            ->willReturn(array( 'success' => false, 'error' => array( 'code' => 'unparseable_response' ) ));

        $result = $this->tool($store, 'I think you should link to the fitting page.')
            ->execute(array( 'content' => 'Widget installation guide.' ));

        $this->assertSame('unparseable_response', $result['error']['code']);
    }

    /**
     * @param list<ContentItem> $items
     */
    private function store(array $items): ContentStoreInterface
    {
        $store = $this->createMock(ContentStoreInterface::class);
        $store->method('query')->willReturnCallback(
            static fn (ContentQuery $query): ContentCollection => new ContentCollection(
                items: $items,
                total: \count($items),
                page: 1,
                perPage: 10,
                totalPages: 1,
            )
        );

        return $store;
    }

    private function item(int $id, string $title): ContentItem
    {
        return new ContentItem(
            id: $id,
            title: $title,
            content: 'Body of ' . $title,
            status: 'publish',
            type: 'post',
            authorId: 5,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            excerpt: 'About ' . $title,
            slug: 'post-' . $id,
        );
    }

    private function tool(
        ?ContentStoreInterface $store = null,
        mixed $answer = '{"suggestions":[]}'
    ): SuggestInternalLinksCompletionTool {
        return new SuggestInternalLinksCompletionTool(
            $this->errors,
            $this->router($this->client($answer)),
            $store
        );
    }

    private function router(?AbstractProviderClient $client): ProviderRouter
    {
        $router = $this->createMock(ProviderRouter::class);
        $router->method('resolveForChat')->willReturn($client);
        $router->method('getRegisteredSlugs')->willReturn(array( 'openai' ));

        return $router;
    }

    /**
     * @param array<int, mixed> $captured
     */
    private function client(mixed $answer = '{"suggestions":[]}', array &$captured = array()): AbstractProviderClient
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturnCallback(
            static function (array $messages) use ($answer, &$captured): mixed {
                $captured[] = $messages;

                return array(
                    'model'   => 'gpt-4o',
                    'choices' => array( array( 'message' => array( 'content' => $answer ) ) ),
                    'usage'   => array( 'total_tokens' => 21 ),
                );
            }
        );

        return $client;
    }
}
