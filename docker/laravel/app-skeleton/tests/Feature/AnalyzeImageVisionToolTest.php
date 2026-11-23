<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Support\TenantContext;
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
 * `analyze_image` end to end, with a fake provider on the real router — §20.
 *
 * The unit test pins argument handling with mocks. This pins the wiring that only
 * the app can get wrong: the manifest row registers, the registrar injects the
 * *singleton* router (so a provider registered at boot is the one that answers),
 * the tool resolves a client by slug, and the multimodal message it builds is the
 * one the provider receives.
 *
 * Nothing here reaches a network: the fake client records the request and returns
 * a canned completion.
 */
final class AnalyzeImageVisionToolTest extends TestCase
{
    use RefreshDatabase;

    /** A 1x1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    protected function tearDown(): void
    {
        TenantContext::forget();

        parent::tearDown();
    }

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('analyze_image'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_asks_the_resolved_provider_about_the_image(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $fake = $this->registerFake();

        $result = $this->tool()->execute(array(
            'image_data' => 'data:image/png;base64,' . self::PNG,
            'prompt'     => 'What colour is this?',
            'provider'   => 'fake-vision',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('A single dark pixel.', $result['data']['text']);
        $this->assertSame('fake-vision', $result['data']['provider']);
        $this->assertSame(7, $result['data']['usage']['total_tokens']);
        $this->assertSame('image/png', $result['data']['mime']);

        $this->assertCount(1, $fake->seen);

        [$messages, $options] = $fake->seen[0];

        $this->assertSame('What colour is this?', $messages[0]['content'][0]['text']);
        $this->assertStringStartsWith(
            'data:image/png;base64,',
            $messages[0]['content'][1]['image_url']['url']
        );
        $this->assertSame(1024, $options['max_tokens']);
    }

    public function test_a_missing_file_id_is_a_404(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $this->registerFake();

        $result = $this->tool()->execute(array( 'file_id' => 999999 ));

        // The adapter returns errors as values, with the HTTP status as the code.
        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(404, $result->getCode());
    }

    public function test_a_remote_url_is_refused_before_any_provider_call(): void
    {
        $this->actingAs(User::factory()->create());
        TenantContext::set(Tenant::DEFAULT_ID);

        $fake = $this->registerFake();

        $result = $this->tool()->execute(array( 'image_data' => 'https://example.test/cat.png' ));

        $this->assertInstanceOf(RuntimeException::class, $result);
        $this->assertSame(422, $result->getCode());
        $this->assertCount(0, $fake->seen, 'A refused source must not reach a provider.');
    }

    /**
     * Register a recording provider under a slug of its own.
     */
    private function registerFake(): AbstractProviderClient
    {
        $fake = new class(
            $this->app->make(SettingsStoreInterface::class),
            $this->app->make(HttpClientInterface::class),
            $this->app->make(ErrorFactoryInterface::class),
        ) extends AbstractProviderClient {
            /** @var list<array{0: array<int, mixed>, 1: array<string, mixed>}> */
            public array $seen = array();

            protected string $providerSlug = 'fake-vision';

            public function chat(array $messages, array $options = array()): mixed
            {
                $this->seen[] = array( $messages, $options );

                return array(
                    'model'   => 'fake-1',
                    'choices' => array( array( 'message' => array( 'content' => 'A single dark pixel.' ) ) ),
                    'usage'   => array( 'total_tokens' => 7 ),
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

        // The router is a container singleton, so registering here is what the
        // tool built at boot will resolve against.
        $this->app->make(ProviderRouter::class)->register($fake);

        return $fake;
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('analyze_image');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
