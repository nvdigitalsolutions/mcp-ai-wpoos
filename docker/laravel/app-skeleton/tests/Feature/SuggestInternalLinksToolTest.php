<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ContentStoreInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\ContentQuery;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Tests\TestCase;

/**
 * `suggest_internal_links` against the app's real content store — §23.
 *
 * The unit suite pins the retrieval contract with a mocked store and the id
 * validation with a canned answer. This pins the part only the app can get wrong:
 * that the store really is searched, that the ids handed to the model are real
 * rows, and that an id the model invents is dropped rather than returned.
 *
 * No network: a recording provider answers with a canned completion.
 */
final class SuggestInternalLinksToolTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('suggest_internal_links'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_suggests_a_real_page_and_drops_an_invented_one(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $first = $this->store()->create(new \Nvoos\Core\Domain\Entity\CreateContentCommand(
            title: 'Fitting a widget',
            type: 'post',
            authorId: (int) $user->getKey(),
            excerpt: 'How to fit a widget.',
            content: 'Fitting a widget is straightforward.',
        ));

        $this->store()->create(new \Nvoos\Core\Domain\Entity\CreateContentCommand(
            title: 'Widget maintenance',
            type: 'post',
            authorId: (int) $user->getKey(),
            excerpt: 'Keeping a widget working.',
            content: 'Maintenance of a widget.',
        ));

        // One real id and one the model made up.
        $this->registerFake((string) \json_encode(array(
            'suggestions' => array(
                array(
                    'content_id' => $first->id,
                    'anchor_text' => 'fitting a widget',
                    'reason' => 'Direct follow-on.',
                    'relevance' => 0.92,
                ),
                array(
                    'content_id' => 999999,
                    'anchor_text' => 'nowhere',
                    'reason' => 'invented',
                    'relevance' => 0.99,
                ),
            ),
        )));

        $result = $this->tool()->execute(
            array(
                'content'  => 'Widget installation guide for the workshop.',
                'provider' => 'fake-links',
            ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['suggestions']);
        $this->assertSame($first->id, $result['data']['suggestions'][0]['content_id']);
        $this->assertSame('Fitting a widget', $result['data']['suggestions'][0]['title']);
        $this->assertSame(1, $result['data']['dropped']);

        // The store was really searched: two published pages matched "widget".
        $this->assertGreaterThanOrEqual(2, $result['data']['candidates_considered']);
    }

    public function test_a_topic_nothing_covers_returns_no_suggestions(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $this->store()->create(new \Nvoos\Core\Domain\Entity\CreateContentCommand(
            title: 'Fitting a widget',
            type: 'post',
            authorId: (int) $user->getKey(),
            content: 'Fitting a widget is straightforward.',
        ));

        $this->registerFake('{"suggestions":[]}');

        $result = $this->tool()->execute(
            array(
                'content'  => 'A page about sourdough hydration ratios.',
                'provider' => 'fake-links',
            ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['suggestions']);
        $this->assertSame(0, $result['data']['candidates_considered']);
    }

    public function test_the_store_only_returns_published_content(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $this->store()->create(new \Nvoos\Core\Domain\Entity\CreateContentCommand(
            title: 'Draft widget notes',
            type: 'post',
            authorId: (int) $user->getKey(),
            status: 'draft',
            content: 'Unpublished widget notes.',
        ));

        $matches = $this->store()->query(new ContentQuery(search: 'widget', statuses: array( 'publish' )));

        // The tool searches published content only, so an unpublished page cannot
        // be suggested as a link target.
        $this->assertSame(0, $matches->total);
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    /**
     * Register a recording provider under a slug of its own.
     */
    private function registerFake(string $answer): void
    {
        $fake = new class(
            $this->app->make(SettingsStoreInterface::class),
            $this->app->make(HttpClientInterface::class),
            $this->app->make(ErrorFactoryInterface::class),
            $answer,
        ) extends AbstractProviderClient {
            protected string $providerSlug = 'fake-links';

            public function __construct(
                SettingsStoreInterface $settings,
                HttpClientInterface $http,
                ErrorFactoryInterface $errors,
                private readonly string $answer,
            ) {
                parent::__construct($settings, $http, $errors);
            }

            public function chat(array $messages, array $options = array()): mixed
            {
                return array(
                    'model'   => 'fake-links-1',
                    'choices' => array( array( 'message' => array( 'content' => $this->answer ) ) ),
                    'usage'   => array( 'total_tokens' => 17 ),
                );
            }

            public function stream(array $messages, array $options = array(), ?callable $onChunk = null): mixed
            {
                return $this->chat($messages, $options);
            }

            public function listModels(): mixed
            {
                return array( 'models' => array() );
            }

            protected function getDefaultBaseUrl(): string
            {
                return 'https://fake.test/v1';
            }
        };

        $this->app->make(ProviderRouter::class)->register($fake);
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('suggest_internal_links');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
