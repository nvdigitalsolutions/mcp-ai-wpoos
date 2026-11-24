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
use Nvoos\Core\Domain\Entity\CreateContentCommand;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Tests\TestCase;

/**
 * `auto_categorize_content` against the app's real content store — §26.
 *
 * The unit suite pins the id validation and the assignment arithmetic with mocks.
 * This pins what only the app can get wrong: that the vocabulary is read from the
 * `nvoos_terms` rows the site actually holds, that a chosen term is really attached,
 * and that an existing term survives the write.
 *
 * No network: a recording provider answers with a canned completion.
 */
final class AutoCategorizeContentToolTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Public so the recording provider below can increment it. The test asserts on it
     * to prove an empty vocabulary spends no completion.
     */
    public int $promptsSent = 0;

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('auto_categorize_content'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_the_vocabulary_comes_from_the_real_terms(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $this->create($user, 'Widget fitting', 'Fitting a widget.', array( 'category' => array( 'Workshop' ) ));
        $this->create($user, 'Bread', 'Baking bread.', array( 'category' => array( 'Baking' ) ));

        $terms = $this->store()->listTaxonomyTerms('category');

        $names = \array_column($terms, 'name');

        $this->assertContains('Workshop', $names);
        $this->assertContains('Baking', $names);
    }

    public function test_it_suggests_a_real_term_and_drops_an_invented_one(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget.', array( 'category' => array( 'Workshop' ) ));
        $this->create($user, 'Bread', 'Baking bread.', array( 'category' => array( 'Baking' ) ));

        $workshop = $this->termId('Workshop');

        $this->registerFake((string) \json_encode(array(
            'categories' => array(
                array( 'id' => $workshop, 'confidence' => 0.93 ),
                array( 'id' => 999999, 'confidence' => 0.99 ),
            ),
        )));

        $result = $this->tool()->execute(
            array( 'post_id' => $source->id, 'provider' => 'fake-categories' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $result['data']['suggestions']);
        $this->assertSame($workshop, $result['data']['suggestions'][0]['id']);
        $this->assertSame('Workshop', $result['data']['suggestions'][0]['name']);
        $this->assertSame(1, $result['data']['dropped']);
    }

    public function test_auto_assign_attaches_the_term_and_keeps_the_existing_one(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget.', array( 'category' => array( 'Curated' ) ));
        $this->create($user, 'Widget care', 'Caring for a widget.', array( 'category' => array( 'Technology' ) ));

        $technology = $this->termId('Technology');

        $this->registerFake((string) \json_encode(array(
            'categories' => array( array( 'id' => $technology, 'confidence' => 0.9 ) ),
        )));

        $result = $this->tool()->execute(
            array(
                'post_id'     => $source->id,
                'auto_assign' => true,
                'provider'    => 'fake-categories',
            ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['auto_assigned']);
        $this->assertSame(array( 'Technology' ), $result['data']['assigned']);

        // The real store round-trip: both terms are on the post afterwards.
        $after = $this->store()->getTaxonomyTerms($source->id);

        $this->assertContains('Curated', $after['category'] ?? array());
        $this->assertContains('Technology', $after['category'] ?? array());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget.', array( 'category' => array( 'Curated' ) ));
        $this->create($user, 'Widget care', 'Caring for a widget.', array( 'category' => array( 'Technology' ) ));

        $technology = $this->termId('Technology');

        $this->registerFake((string) \json_encode(array(
            'categories' => array( array( 'id' => $technology, 'confidence' => 0.9 ) ),
        )));

        $result = $this->tool()->execute(
            array(
                'post_id'     => $source->id,
                'auto_assign' => true,
                'dry_run'     => true,
                'provider'    => 'fake-categories',
            ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['data']['dry_run']);
        $this->assertSame(array( 'Technology' ), $result['data']['assigned']);

        $after = $this->store()->getTaxonomyTerms($source->id);

        $this->assertSame(array( 'Curated' ), $after['category'] ?? array());
    }

    public function test_a_taxonomy_with_no_terms_is_a_finding_and_calls_no_model(): void
    {
        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $source = $this->create($user, 'Widget fitting', 'Fitting a widget.');

        $this->registerFake('{"categories":[]}');

        $result = $this->tool()->execute(
            array( 'post_id' => $source->id, 'taxonomy' => 'genre', 'provider' => 'fake-categories' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(array(), $result['data']['suggestions']);
        $this->assertSame(0, $result['data']['vocabulary']);
        $this->assertSame(0, $this->promptsSent, 'An empty vocabulary must not spend a completion.');
    }

    private function create(
        User $user,
        string $title,
        string $content,
        array $taxonomy = array(),
    ): \Nvoos\Core\Domain\Entity\ContentItem {
        return $this->store()->create(new CreateContentCommand(
            title: $title,
            type: 'post',
            authorId: (int) $user->getKey(),
            content: $content,
            excerpt: $content,
            taxonomyInput: $taxonomy,
        ));
    }

    private function termId(string $name): int
    {
        foreach ($this->store()->listTaxonomyTerms('category', 500) as $term) {
            if ($term['name'] === $name) {
                return (int) $term['id'];
            }
        }

        $this->fail('Term not found: ' . $name);

        return 0;
    }

    private function store(): ContentStoreInterface
    {
        return $this->app->make(ContentStoreInterface::class);
    }

    /**
     * Register a recording provider that counts the prompts it receives.
     */
    private function registerFake(string $answer): void
    {
        $self = $this;

        $fake = new class(
            $this->app->make(SettingsStoreInterface::class),
            $this->app->make(HttpClientInterface::class),
            $this->app->make(ErrorFactoryInterface::class),
            $answer,
            $self,
        ) extends AbstractProviderClient {
            protected string $providerSlug = 'fake-categories';

            public function __construct(
                SettingsStoreInterface $settings,
                HttpClientInterface $http,
                ErrorFactoryInterface $errors,
                private readonly string $answer,
                private readonly object $counter,
            ) {
                parent::__construct($settings, $http, $errors);
            }

            public function chat(array $messages, array $options = array()): mixed
            {
                $counter = $this->counter;

                if (\property_exists($counter, 'promptsSent')) {
                    $counter->promptsSent++;
                }

                return array(
                    'model'   => 'fake-categories-1',
                    'choices' => array( array( 'message' => array( 'content' => $this->answer ) ) ),
                    'usage'   => array( 'total_tokens' => 42 ),
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
        $tool = $this->app->make(ToolRegistry::class)->get('auto_categorize_content');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
