<?php

/**
 * `batch_embed_content`.
 *
 * The assertions that matter:
 *
 * - a vector is only stored when the provider actually returned one for that input;
 * - an item that already carries an embedding is **not paid for twice**;
 * - `dry_run` calls no provider at all;
 * - a provider refusal is reported with its status, not silently turned into an empty
 *   success.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\ContentCollection;
use Nvoos\Core\Domain\Entity\ContentItem;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Entity\UpdateContentCommand;
use Nvoos\Core\Tool\BatchEmbedContentIndexTool;
use PHPUnit\Framework\TestCase;

final class BatchEmbedContentToolTest extends TestCase
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
        $tool = new BatchEmbedContentIndexTool($this->errors);

        $this->assertSame('batch_embed_content', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
        $this->assertContains('consumes-tokens', $tool->getCapabilityFlags());
        $this->assertNotContains('read-only', $tool->getCapabilityFlags());
    }

    // ── Refusals ─────────────────────────────────────────────────────────────

    public function test_a_host_without_a_content_store_says_so(): void
    {
        $result = (new BatchEmbedContentIndexTool($this->errors))
            ->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 1 ));

        $this->assertSame('content_store_unavailable', $result['error']['code']);
    }

    public function test_a_host_without_http_or_settings_says_so(): void
    {
        $result = (new BatchEmbedContentIndexTool($this->errors, $this->store(array())))
            ->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 1 ));

        $this->assertSame('embeddings_unavailable', $result['error']['code']);
    }

    public function test_an_unimplemented_provider_is_refused_by_name(): void
    {
        $result = $this->tool($this->store(array( $this->item(1) )), $this->http())
            ->execute(
                array( 'post_ids' => array( 1 ), 'provider' => 'gemini' ),
                array( 'user_id' => 7 )
            );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('gemini', $result['error']['message']);
    }

    public function test_a_missing_calling_user_is_refused(): void
    {
        $result = $this->tool($this->store(array( $this->item(1) )), $this->http())
            ->execute(array( 'post_ids' => array( 1 ) ));

        $this->assertSame('user_required', $result['error']['code']);
    }

    public function test_a_missing_api_key_is_named(): void
    {
        $result = $this->tool(
            $this->store(array( $this->item(1) )),
            $this->http(),
            ''
        )->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertSame('missing_api_key', $result['error']['code']);
    }

    public function test_a_provider_refusal_is_reported_with_its_status(): void
    {
        $result = $this->tool(
            $this->store(array( $this->item(1) )),
            $this->http(new HttpResponse(429, '{"error":{"message":"slow down"}}'))
        )->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertSame('provider_error', $result['error']['code']);
        $this->assertStringContainsString('429', $result['error']['message']);
        $this->assertStringContainsString('slow down', $result['error']['message']);
    }

    public function test_a_response_without_embeddings_is_invalid(): void
    {
        $result = $this->tool(
            $this->store(array( $this->item(1) )),
            $this->http(new HttpResponse(200, '{"data":[]}'))
        )->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertSame('invalid_response', $result['error']['code']);
    }

    // ── Dry run ──────────────────────────────────────────────────────────────

    public function test_dry_run_calls_no_provider_and_writes_nothing(): void
    {
        $store = $this->store(array( $this->item(1), $this->item(2) ));

        $store->expects($this->never())->method('update');

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('send');

        $result = $this->tool($store, $http)
            ->execute(
                array( 'post_types' => array( 'post' ), 'dry_run' => true ),
                array( 'user_id' => 7 )
            );

        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(array( 1, 2 ), $result['data']['would_embed']);
    }

    // ── Embedding ────────────────────────────────────────────────────────────

    public function test_it_embeds_and_stores_the_vector_in_meta(): void
    {
        $store = $this->store(array( $this->item(1) ));

        $written = null;

        $store->expects($this->once())->method('update')->willReturnCallback(
            static function (int $id, UpdateContentCommand $command) use (&$written): ContentItem {
                $written = $command;

                return new ContentItem(
                    id: $id,
                    title: 't',
                    content: 'c',
                    status: 'publish',
                    type: 'post',
                    authorId: 7,
                    createdAt: new \DateTimeImmutable(),
                    updatedAt: new \DateTimeImmutable(),
                );
            }
        );

        $result = $this->tool($store, $this->http())
            ->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['embedded']);
        $this->assertSame(3, $result['data']['dimensions']);
        $this->assertSame(11, $result['data']['tokens']);

        $this->assertInstanceOf(UpdateContentCommand::class, $written);

        $record = $written->meta[ BatchEmbedContentIndexTool::META_KEY ] ?? null;

        $this->assertIsArray($record);
        $this->assertSame('openai', $record['provider']);
        $this->assertSame('text-embedding-3-small', $record['model']);
        $this->assertSame(3, $record['dimensions']);
        $this->assertSame(array( 0.1, 0.2, 0.3 ), $record['vector']);
        $this->assertSame(\sha1('Widget notes' . "\n\n" . 'Body of notes.'), $record['text_hash']);
    }

    public function test_it_posts_to_the_embeddings_endpoint_with_bearer_auth(): void
    {
        $captured = array();

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->once())->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers, ?string $body) use (&$captured): HttpResponse {
                $captured = array( 'method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body );

                return new HttpResponse(200, '{"data":[{"index":0,"embedding":[0.5,0.6]}],"usage":{"total_tokens":3}}');
            }
        );

        $this->tool($this->store(array( $this->item(1) )), $http)
            ->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertSame('POST', $captured['method']);
        $this->assertSame('https://api.test/v1/embeddings', $captured['url']);
        $this->assertContains('Authorization: Bearer sk-test', $captured['headers']);

        $payload = \json_decode((string) $captured['body'], true);

        $this->assertSame('text-embedding-3-small', $payload['model']);
        $this->assertSame(array( 'Widget notes' . "\n\n" . 'Body of notes.' ), $payload['input']);
    }

    public function test_an_item_that_already_has_an_embedding_is_skipped(): void
    {
        $store = $this->store(array( $this->item(1, array(
            BatchEmbedContentIndexTool::META_KEY => array( 'vector' => array( 0.9, 0.8 ) ),
        )) ));

        $store->expects($this->never())->method('update');

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('send');

        $result = $this->tool($store, $http)
            ->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['embedded']);
        $this->assertSame('already_embedded', $result['data']['skipped'][0]['reason']);
        $this->assertStringContainsString('update_existing', $result['message']);
    }

    public function test_update_existing_re_embeds(): void
    {
        $store = $this->store(array( $this->item(1, array(
            BatchEmbedContentIndexTool::META_KEY => array( 'vector' => array( 0.9, 0.8 ) ),
        )) ));

        $store->expects($this->once())->method('update')->willReturn($this->item(1));

        $result = $this->tool($store, $this->http())
            ->execute(
                array( 'post_ids' => array( 1 ), 'update_existing' => true ),
                array( 'user_id' => 7 )
            );

        $this->assertCount(1, $result['data']['embedded']);
    }

    public function test_an_item_with_no_text_is_skipped(): void
    {
        $store = $this->store(array( $this->item(1, array(), '', '') ));

        $store->expects($this->never())->method('update');

        $http = $this->createMock(HttpClientInterface::class);
        $http->expects($this->never())->method('send');

        $result = $this->tool($store, $http)
            ->execute(array( 'post_ids' => array( 1 ) ), array( 'user_id' => 7 ));

        $this->assertSame('empty_text', $result['data']['skipped'][0]['reason']);
    }

    public function test_the_limit_reaches_the_query(): void
    {
        $store = $this->store(array( $this->item(1) ));

        $store->expects($this->once())->method('query')->willReturnCallback(
            static function (ContentQuery $query) use ($store): ContentCollection {
                return new ContentCollection(
                    items: array( new ContentItem(
                        id: 1,
                        title: 't',
                        content: 'c',
                        status: 'publish',
                        type: 'post',
                        authorId: 7,
                        createdAt: new \DateTimeImmutable(),
                        updatedAt: new \DateTimeImmutable(),
                    ) ),
                    total: 1,
                    page: 1,
                    perPage: $query->perPage,
                    totalPages: 1,
                );
            }
        );

        $this->tool($store, $this->http())
            ->execute(
                array( 'post_types' => array( 'post' ), 'limit' => 7, 'dry_run' => true ),
                array( 'user_id' => 7 )
            );
    }

    public function test_a_long_batch_is_split_across_requests(): void
    {
        // 65 items is one past the per-request input cap, so exactly two requests.
        $items = array();

        for ($id = 1; $id <= 65; ++$id) {
            $items[] = $this->item($id);
        }

        $requests = 0;

        $http = $this->createMock(HttpClientInterface::class);
        $http->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers, ?string $body) use (&$requests): HttpResponse {
                ++$requests;

                $payload = \json_decode((string) $body, true);
                $data    = array();

                foreach (\array_keys((array) $payload['input']) as $index) {
                    $data[] = array( 'index' => $index, 'embedding' => array( 0.1, 0.2 ) );
                }

                return new HttpResponse(200, (string) \json_encode(array( 'data' => $data, 'usage' => array( 'total_tokens' => 5 ) )));
            }
        );

        $result = $this->tool($this->store($items), $http)
            ->execute(
                array( 'post_types' => array( 'post' ), 'limit' => 100 ),
                array( 'user_id' => 7 )
            );

        $this->assertSame(2, $requests);
        $this->assertSame(2, $result['data']['batches']);
        $this->assertCount(65, $result['data']['embedded']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function item(
        int $id,
        array $meta = array(),
        string $title = 'Widget notes',
        string $content = 'Body of notes.',
    ): ContentItem {
        return new ContentItem(
            id: $id,
            title: $title,
            content: $content,
            status: 'publish',
            type: 'post',
            authorId: 7,
            createdAt: new \DateTimeImmutable(),
            updatedAt: new \DateTimeImmutable(),
            meta: $meta,
        );
    }

    /**
     * @param list<ContentItem> $items
     */
    private function store(array $items): ContentStoreInterface
    {
        $store = $this->createMock(ContentStoreInterface::class);

        $byId = array();

        foreach ($items as $item) {
            $byId[ $item->id ] = $item;
        }

        $store->method('find')->willReturnCallback(
            static fn (int $id, ?int $userId = null): ?ContentItem => $byId[ $id ] ?? null
        );

        $store->method('getMeta')->willReturnCallback(
            static fn (int $id): array => $byId[ $id ]->meta ?? array()
        );

        $store->method('query')->willReturnCallback(
            static fn (ContentQuery $query): ContentCollection => new ContentCollection(
                items: $items,
                total: \count($items),
                page: 1,
                perPage: $query->perPage,
                totalPages: 1,
            )
        );

        $store->method('update')->willReturnCallback(
            static fn (int $id, UpdateContentCommand $command): ContentItem => $byId[ $id ]
        );

        return $store;
    }

    private function http(?HttpResponse $response = null): HttpClientInterface
    {
        $http = $this->createMock(HttpClientInterface::class);

        $http->method('send')->willReturn(
            $response ?? new HttpResponse(
                200,
                '{"data":[{"index":0,"embedding":[0.1,0.2,0.3]}],"usage":{"total_tokens":11}}'
            )
        );

        return $http;
    }

    private function tool(
        ContentStoreInterface $store,
        HttpClientInterface $http,
        string $key = 'sk-test',
    ): BatchEmbedContentIndexTool {
        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn('' === $key ? null : $key);
        $settings->method('getApiBaseUrl')->willReturn('https://api.test/v1');

        return new BatchEmbedContentIndexTool($this->errors, $store, $http, $settings);
    }
}
