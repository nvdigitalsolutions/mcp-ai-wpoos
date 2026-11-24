<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Tool\BatchEmbedContentIndexTool;
use Tests\TestCase;

/**
 * `batch_embed_content` against the app's real content store — §28.
 *
 * The unit suite pins the request shape and the failure paths with mocks. This pins the
 * part only the app can get wrong: that a vector really lands in the item's meta and
 * reads back, and that a second run costs nothing because the first one is visible to
 * the skip check.
 *
 * No network: `Http::fake()` intercepts the embeddings endpoint.
 */
final class BatchEmbedContentToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('batch_embed_content'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_embeds_and_stores_the_vector_in_real_meta(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        config(array( 'services.openai.key' => 'sk-test' ));

        Http::fake(array(
            'api.openai.com/*' => Http::response(array(
                'data'  => array( array( 'index' => 0, 'embedding' => array( 0.1, 0.2, 0.3 ) ) ),
                'usage' => array( 'total_tokens' => 9 ),
            )),
        ));

        $post = $this->create($user, 'Widget notes', 'Body of notes.');

        $result = $this->tool()->execute(
            array( 'post_ids' => array( $post->id ) ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['embedded']);
        $this->assertSame(3, $result['data']['dimensions']);

        // The real store round-trip: the vector is in the item's meta and readable back.
        $meta   = $this->store()->getMeta($post->id);
        $record = $meta[ BatchEmbedContentIndexTool::META_KEY ] ?? null;

        $this->assertIsArray($record);
        $this->assertSame('openai', $record['provider']);
        $this->assertSame('text-embedding-3-small', $record['model']);
        $this->assertSame(array( 0.1, 0.2, 0.3 ), $record['vector']);
    }

    public function test_a_second_run_skips_the_already_embedded_item(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        config(array( 'services.openai.key' => 'sk-test' ));

        Http::fake(array(
            'api.openai.com/*' => Http::response(array(
                'data'  => array( array( 'index' => 0, 'embedding' => array( 0.4, 0.5 ) ) ),
                'usage' => array( 'total_tokens' => 4 ),
            )),
        ));

        $post = $this->create($user, 'Widget notes', 'Body of notes.');

        $this->tool()->execute(
            array( 'post_ids' => array( $post->id ) ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $second = $this->tool()->execute(
            array( 'post_ids' => array( $post->id ) ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($second['success']);
        $this->assertSame(array(), $second['data']['embedded']);
        $this->assertSame('already_embedded', $second['data']['skipped'][0]['reason']);

        // One request in total: the second run spent nothing.
        Http::assertSentCount(1);
    }

    public function test_a_host_without_an_api_key_says_so_and_writes_nothing(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        config(array( 'services.openai.key' => null, 'oos.api_keys.openai' => null ));

        Http::fake();

        $post = $this->create($user, 'Widget notes', 'Body of notes.');

        $result = $this->tool()->execute(
            array( 'post_ids' => array( $post->id ) ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('API key', $result->getMessage());

        Http::assertNothingSent();

        $meta = $this->store()->getMeta($post->id);

        $this->assertArrayNotHasKey(BatchEmbedContentIndexTool::META_KEY, $meta);
    }

    private function create(User $user, string $title, string $content): \Nvoos\Core\Domain\Entity\ContentItem
    {
        return $this->store()->create(new CreateContentCommand(
            title: $title,
            type: 'post',
            authorId: (int) $user->getKey(),
            content: $content,
            excerpt: $content,
        ));
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('batch_embed_content');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
