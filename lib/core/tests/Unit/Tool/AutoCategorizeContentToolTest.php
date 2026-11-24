<?php

/**
 * `auto_categorize_content`.
 *
 * Two assertions carry the weight here:
 *
 * - an id the model returns that was not in the vocabulary is **dropped and counted**,
 *   because assigning it would fail or invent a near-duplicate term;
 * - `auto_assign` **adds**, it never removes a term the model did not name — so a
 *   suggestion cannot silently delete curation a human made.
 *
 * Everything else guards the retrieval and the filtering that make those two true.
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
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Nvoos\Core\Tool\AutoCategorizeContentCompletionTool;
use PHPUnit\Framework\TestCase;

final class AutoCategorizeContentToolTest extends TestCase
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

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = '', array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'forbidden', 'message' => $message ),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = '', array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'not_found', 'message' => $message ),
            )
        );
    }

    // ── Shape ────────────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = new AutoCategorizeContentCompletionTool($this->errors);

        $this->assertSame('auto_categorize_content', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
        $this->assertContains('state-changing', $tool->getCapabilityFlags());
        $this->assertContains('consumes-tokens', $tool->getCapabilityFlags());
        $this->assertNotContains('read-only', $tool->getCapabilityFlags());
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_a_host_without_a_content_store_says_so(): void
    {
        $result = (new AutoCategorizeContentCompletionTool($this->errors))
            ->execute(array( 'content' => 'Anything' ));

        $this->assertSame('content_store_unavailable', $result['error']['code']);
    }

    public function test_a_source_is_required(): void
    {
        $result = $this->tool($this->store())->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_empty_taxonomy_is_refused(): void
    {
        $result = $this->tool($this->store())->execute(array(
            'content'  => 'Anything',
            'taxonomy' => '   ',
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_post_id_that_does_not_resolve_is_named(): void
    {
        $result = $this->tool($this->store())->execute(array( 'post_id' => 77 ));

        $this->assertSame('content_not_found', $result['error']['code']);
    }

    public function test_an_unreadable_answer_is_an_error(): void
    {
        $result = $this->tool($this->store(array( 1 => 'Technology' )), 'I think it is about technology.')
            ->execute(array( 'content' => 'A post about widgets.' ));

        $this->assertSame('unparseable_response', $result['error']['code']);
    }

    // ── The vocabulary ───────────────────────────────────────────────────────

    public function test_an_empty_vocabulary_is_a_finding_and_costs_no_model_call(): void
    {
        $captured = array();

        $result = $this->tool($this->store(), '{"categories":[]}', $captured)
            ->execute(array( 'content' => 'A post about widgets.' ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['suggestions']);
        $this->assertSame(0, $result['data']['vocabulary']);
        $this->assertSame(array(), $captured, 'An empty vocabulary should not spend a completion.');
        $this->assertStringContainsString('no terms', $result['message']);
    }

    public function test_an_invented_id_is_dropped_and_counted(): void
    {
        $result = $this->tool(
            $this->store(array( 1 => 'Technology', 2 => 'Design' )),
            '{"categories":[{"id":1,"confidence":0.9},{"id":999,"confidence":0.99}]}'
        )->execute(array( 'content' => 'A post about widgets.' ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['suggestions']);
        $this->assertSame(1, $result['data']['suggestions'][0]['id']);
        $this->assertSame('Technology', $result['data']['suggestions'][0]['name']);
        $this->assertSame(1, $result['data']['dropped']);
        $this->assertStringContainsString('invented id', $result['message']);
    }

    public function test_a_suggestion_below_the_confidence_floor_is_discarded(): void
    {
        $result = $this->tool(
            $this->store(array( 1 => 'Technology', 2 => 'Design' )),
            '{"categories":[{"id":1,"confidence":0.2},{"id":2,"confidence":0.8}]}'
        )->execute(array( 'content' => 'A post.', 'min_confidence' => 0.5 ));

        $this->assertSame(array( 2 ), \array_column($result['data']['suggestions'], 'id'));
    }

    public function test_max_categories_is_honoured(): void
    {
        $result = $this->tool(
            $this->store(array( 1 => 'A', 2 => 'B', 3 => 'C' )),
            '{"categories":[{"id":1,"confidence":0.9},{"id":2,"confidence":0.8},{"id":3,"confidence":0.7}]}'
        )->execute(array( 'content' => 'A post.', 'max_categories' => 2 ));

        $this->assertCount(2, $result['data']['suggestions']);
    }

    public function test_equal_confidence_is_ordered_by_name_so_the_answer_is_reproducible(): void
    {
        $result = $this->tool(
            $this->store(array( 1 => 'Zebra', 2 => 'Apple' )),
            '{"categories":[{"id":1,"confidence":0.9},{"id":2,"confidence":0.9}]}'
        )->execute(array( 'content' => 'A post.' ));

        $this->assertSame(array( 'Apple', 'Zebra' ), \array_column($result['data']['suggestions'], 'name'));
    }

    // ── Assignment ───────────────────────────────────────────────────────────

    public function test_it_does_not_write_without_auto_assign(): void
    {
        $store = $this->store(array( 1 => 'Technology' ), null, 'post');

        $store->expects($this->never())->method('update');

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}',
            $captured
        )->execute(
            array( 'post_id' => 1 ),
            array( 'user_id' => 5 )
        );

        $this->assertFalse($result['data']['auto_assigned']);
    }

    public function test_auto_assign_adds_the_chosen_term(): void
    {
        $store = $this->store(array( 1 => 'Technology' ), null, 'post');

        $written = null;

        $store->expects($this->once())->method('update')->willReturnCallback(
            static function (int $id, UpdateContentCommand $command) use (&$written, $store): ContentItem {
                $written = $command;

                return $store->find($id) ?? new ContentItem(
                    id: $id,
                    title: 't',
                    content: 'c',
                    status: 'publish',
                    type: 'post',
                    authorId: 5,
                    createdAt: new \DateTimeImmutable(),
                    updatedAt: new \DateTimeImmutable(),
                );
            }
        );

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}',
            $captured
        )->execute(
            array( 'post_id' => 1, 'auto_assign' => true ),
            array( 'user_id' => 5 )
        );

        $this->assertTrue($result['data']['auto_assigned']);
        $this->assertSame(array( 'Technology' ), $result['data']['assigned']);
        $this->assertInstanceOf(UpdateContentCommand::class, $written);
        $this->assertSame(array( 'category' => array( 'Technology' ) ), $written->taxonomyInput);
        $this->assertSame(5, $written->userId);
    }

    public function test_auto_assign_keeps_terms_the_model_did_not_name(): void
    {
        $store = $this->store(
            array( 1 => 'Technology', 2 => 'Design' ),
            array( 'category' => array( 'Curated' ) ),
            'post'
        );

        $written = null;

        $store->expects($this->once())->method('update')->willReturnCallback(
            static function (int $id, UpdateContentCommand $command) use (&$written, $store): ContentItem {
                $written = $command;

                return new ContentItem(
                    id: $id,
                    title: 't',
                    content: 'c',
                    status: 'publish',
                    type: 'post',
                    authorId: 5,
                    createdAt: new \DateTimeImmutable(),
                    updatedAt: new \DateTimeImmutable(),
                );
            }
        );

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}',
            $captured
        )->execute(
            array( 'post_id' => 1, 'auto_assign' => true ),
            array( 'user_id' => 5 )
        );

        $this->assertSame(array( 'Technology' ), $result['data']['assigned']);

        // The pre-existing term survives: the store's replace semantics would have
        // deleted it, and that is the behaviour this tool deliberately does not copy.
        $this->assertSame(
            array( 'category' => array( 'Curated', 'Technology' ) ),
            $written->taxonomyInput
        );
    }

    public function test_auto_assign_does_not_write_when_everything_is_already_present(): void
    {
        $store = $this->store(
            array( 1 => 'Technology' ),
            array( 'category' => array( 'Technology' ) ),
            'post'
        );

        $store->expects($this->never())->method('update');

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}',
            $captured
        )->execute(
            array( 'post_id' => 1, 'auto_assign' => true ),
            array( 'user_id' => 5 )
        );

        $this->assertSame(array(), $result['data']['assigned']);
        $this->assertSame(array( 'Technology' ), $result['data']['already_present']);
    }

    public function test_dry_run_reports_what_would_be_added_and_writes_nothing(): void
    {
        $store = $this->store(array( 1 => 'Technology' ), null, 'post');

        $store->expects($this->never())->method('update');

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}',
            $captured
        )->execute(
            array( 'post_id' => 1, 'auto_assign' => true, 'dry_run' => true ),
            array( 'user_id' => 5 )
        );

        $this->assertTrue($result['data']['dry_run']);
        $this->assertFalse($result['data']['auto_assigned']);
        $this->assertSame(array( 'Technology' ), $result['data']['assigned']);
    }

    public function test_auto_assign_without_a_calling_user_is_refused(): void
    {
        $store = $this->store(array( 1 => 'Technology' ), null, 'post');

        $store->expects($this->never())->method('update');

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}',
            $captured
        )->execute(array( 'post_id' => 1, 'auto_assign' => true ));

        $this->assertSame('user_required', $result['error']['code']);
    }

    public function test_auto_assign_without_a_stored_post_only_suggests(): void
    {
        $store = $this->store(array( 1 => 'Technology' ));

        $store->expects($this->never())->method('update');

        $result = $this->tool(
            $store,
            '{"categories":[{"id":1,"confidence":0.9}]}'
        )->execute(array( 'content' => 'An unsaved draft.', 'auto_assign' => true ));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['auto_assigned']);
        $this->assertCount(1, $result['data']['suggestions']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    /**
     * @param array<int, string>   $vocabulary
     * @param array<string, array<int, string>>|null $currentTerms
     * @param array<int, mixed>    $captured
     */
    private function store(array $vocabulary = array(), ?array $currentTerms = null, string $type = 'post'): ContentStoreInterface
    {
        $store = $this->createMock(ContentStoreInterface::class);

        $terms = array();

        foreach ($vocabulary as $id => $name) {
            $terms[] = array( 'id' => $id, 'name' => $name, 'slug' => \strtolower($name), 'count' => 1 );
        }

        $store->method('listTaxonomyTerms')->willReturn($terms);

        $store->method('getTaxonomyTerms')->willReturn($currentTerms ?? array());

        if ('post' === $type) {
            $store->method('find')->willReturnCallback(
                static fn (int $id): ?ContentItem => 1 === $id
                    ? new ContentItem(
                        id: 1,
                        title: 'A post about widgets',
                        content: 'Widgets are useful.',
                        status: 'publish',
                        type: 'post',
                        authorId: 5,
                        createdAt: new \DateTimeImmutable(),
                        updatedAt: new \DateTimeImmutable(),
                    )
                    : null
            );
        }

        return $store;
    }

    /**
     * @param array<int, mixed>|null $captured Records prompts when given.
     */
    private function tool(
        ContentStoreInterface $store,
        mixed $answer = '{"categories":[]}',
        ?array &$captured = null,
    ): AutoCategorizeContentCompletionTool {
        return new AutoCategorizeContentCompletionTool(
            $this->errors,
            $this->router($this->client($answer, $captured)),
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
     * @param array<int, mixed>|null $captured Records prompts when given.
     */
    private function client(mixed $answer, ?array &$captured = null): AbstractProviderClient
    {
        $client = $this->createMock(AbstractProviderClient::class);
        $client->method('getProviderSlug')->willReturn('openai');
        $client->method('chat')->willReturnCallback(
            static function (array $messages) use ($answer, &$captured): mixed {
                if (null !== $captured) {
                    $captured[] = $messages;
                }

                return array(
                    'model'   => 'gpt-4o-mini',
                    'choices' => array( array( 'message' => array( 'content' => $answer ) ) ),
                    'usage'   => array( 'total_tokens' => 30 ),
                );
            }
        );

        return $client;
    }
}
