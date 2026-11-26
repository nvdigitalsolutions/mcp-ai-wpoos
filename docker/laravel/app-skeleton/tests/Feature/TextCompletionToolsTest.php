<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use RuntimeException;
use Tests\TestCase;

/**
 * The five text-completion tools, end to end — §21.
 *
 * The unit suite pins argument handling and normalisation with mocks. This pins
 * the wiring only the app can get wrong: the manifest rows register, the registrar
 * injects the singleton router, and a structured tool's verdict survives the round
 * trip from a provider answer to the tool payload.
 *
 * No network: a recording provider answers with a canned completion.
 */
final class TextCompletionToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The slugs this batch added to the servable set.
     */
    private const SLUGS = array(
        'generate_post_excerpt',
        'analyze_code_sequence',
        'analyze_comment_content',
        'validate_reasoning_chain',
        'seo_meta_optimizer',
    );

    public function test_the_container_builds_every_tool(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        foreach (self::SLUGS as $slug) {
            $this->assertTrue(
                $registry->has($slug),
                "The manifest row is servable but the app did not register '{$slug}'."
            );
        }
    }

    public function test_an_excerpt_comes_back_from_the_resolved_provider(): void
    {
        $this->actingAs(User::factory()->create());

        $fake = $this->registerFake('A tidy summary of the page.');

        $result = $this->tool('generate_post_excerpt')->execute(array(
            'content'  => '<p>Some longer body text that needs summarising.</p>',
            'provider' => 'fake-completion',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('A tidy summary of the page.', $result['data']['excerpt']);
        $this->assertSame('fake-completion', $result['data']['provider']);
        $this->assertSame(6, $result['data']['words']);

        // HTML was stripped on the way in, which is right for prose.
        $prompt = $fake->seen[0][0][0]['content'][0]['text'];
        $this->assertStringNotContainsString('<p>', $prompt);
        $this->assertStringContainsString('Some longer body text', $prompt);
    }

    public function test_a_code_review_receives_the_code_verbatim(): void
    {
        $this->actingAs(User::factory()->create());

        $fake = $this->registerFake('One problem: the strict comparison.');

        $code = "<?php\nif (\$a < \$b) {\n    echo '<b>'; }\n";

        $result = $this->tool('analyze_code_sequence')->execute(array(
            'code'     => $code,
            'language' => 'php',
            'provider' => 'fake-completion',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('php', $result['data']['language']);

        $prompt = $fake->seen[0][0][0]['content'][0]['text'];

        // `strip_tags` would have eaten `<?php` and everything from `<` onward.
        $this->assertStringContainsString('<?php', $prompt);
        $this->assertStringContainsString('if ($a < $b)', $prompt);
        $this->assertStringContainsString("echo '<b>'", $prompt);
    }

    public function test_a_comment_verdict_keeps_the_wordpress_shape(): void
    {
        $this->actingAs(User::factory()->create());

        $this->registerFake((string) \json_encode(array(
            'is_spam'            => true,
            'is_toxic'           => false,
            'toxicity_level'     => 'low',
            'spam_indicators'    => array( 'link in first line' ),
            'recommended_action' => 'spam',
            'confidence'         => 0.87,
            'reason'             => 'Promotional link.',
        )));

        $result = $this->tool('analyze_comment_content')->execute(array(
            'comment_content' => 'Buy cheap widgets at example.test!',
            'sensitivity'     => 'high',
            'provider'        => 'fake-completion',
        ));

        $this->assertTrue($result['success']);

        $this->assertTrue($result['data']['is_spam']);
        $this->assertSame('spam', $result['data']['recommended_action']);
        $this->assertSame('low', $result['data']['toxicity_level']);
        $this->assertSame(0.87, $result['data']['confidence']);
        $this->assertSame('high', $result['data']['sensitivity_applied']);
    }

    public function test_an_unknown_provider_is_refused_with_the_registered_list(): void
    {
        $this->actingAs(User::factory()->create());

        $result = $this->tool('validate_reasoning_chain')->execute(array(
            'reasoning_steps' => array( 'A implies B.', 'B implies C.' ),
            'provider'        => 'no-such-provider',
        ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
    }

    /**
     * Register a recording provider under a slug of its own.
     */
    private function registerFake(string $answer): AbstractProviderClient
    {
        $fake = new class(
            $this->app->make(SettingsStoreInterface::class),
            $this->app->make(HttpClientInterface::class),
            $this->app->make(ErrorFactoryInterface::class),
            $answer,
        ) extends AbstractProviderClient {
            /** @var list<array{0: array<int, mixed>, 1: array<string, mixed>}> */
            public array $seen = array();

            protected string $providerSlug = 'fake-completion';

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
                $this->seen[] = array( $messages, $options );

                return array(
                    'model'   => 'fake-1',
                    'choices' => array( array( 'message' => array( 'content' => $this->answer ) ) ),
                    'usage'   => array( 'total_tokens' => 11 ),
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

        return $fake;
    }

    private function tool(string $slug): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get($slug);

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
