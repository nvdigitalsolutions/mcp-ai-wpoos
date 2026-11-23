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
 * The three image-reading tools, end to end — §22.
 *
 * The unit suite pins argument handling and the empty/decorative distinctions with
 * mocks. This pins the wiring: the manifest rows register, the registrar injects
 * the singleton router, and a vision answer survives the round trip from provider
 * to tool payload.
 *
 * No network: a recording provider answers with a canned completion.
 */
final class VisionImageToolsTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private const SLUGS = array( 'extract_image_text', 'generate_image_alt_text', 'generate_image_caption' );

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

    public function test_an_ocr_read_returns_the_text_and_its_counts(): void
    {
        $this->actingAs(User::factory()->create());

        $fake = $this->registerFake("Invoice 4471\nTotal: 12.00");

        $result = $this->tool('extract_image_text')->execute(array(
            'image_data'      => 'data:image/png;base64,' . self::PNG,
            'preserve_layout' => true,
            'provider'        => 'fake-vision-image',
        ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['has_text']);
        $this->assertTrue($result['data']['preserve_layout']);
        $this->assertStringContainsString("\n", $result['data']['text']);
        $this->assertSame(\mb_strlen($result['data']['text']), $result['data']['character_count']);
        $this->assertSame('image/png', $result['data']['mime']);

        // The prompt asked for the transcription and the image reached the provider.
        $content = $fake->seen[0][0][0]['content'];

        $this->assertStringContainsString('Transcribe', $content[0]['text']);
        $this->assertStringStartsWith('data:image/png;base64,', $content[1]['image_url']['url']);
    }

    public function test_alt_text_that_is_too_long_is_reported_and_left_intact(): void
    {
        $this->actingAs(User::factory()->create());

        $long = \str_repeat('word ', 30);

        $this->registerFake($long);

        $result = $this->tool('generate_image_alt_text')->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
            'context'    => 'A product page for a handbag.',
            'provider'   => 'fake-vision-image',
        ));

        $this->assertTrue($result['success']);
        $this->assertFalse($result['data']['within_recommended_length']);
        $this->assertSame(149, $result['data']['characters']);
        $this->assertStringStartsWith('word word', $result['data']['alt_text']);
    }

    public function test_a_decorative_image_returns_empty_alt_text(): void
    {
        $this->actingAs(User::factory()->create());

        $this->registerFake('[DECORATIVE]');

        $result = $this->tool('generate_image_alt_text')->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
            'provider'   => 'fake-vision-image',
        ));

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['decorative']);
        $this->assertSame('', $result['data']['alt_text']);
    }

    public function test_an_unknown_provider_is_refused_with_the_registered_list(): void
    {
        $this->actingAs(User::factory()->create());

        $result = $this->tool('generate_image_caption')->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
            'provider'   => 'no-such-provider',
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

            protected string $providerSlug = 'fake-vision-image';

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
                    'model'   => 'fake-vision-1',
                    'choices' => array( array( 'message' => array( 'content' => $this->answer ) ) ),
                    'usage'   => array( 'total_tokens' => 13 ),
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
