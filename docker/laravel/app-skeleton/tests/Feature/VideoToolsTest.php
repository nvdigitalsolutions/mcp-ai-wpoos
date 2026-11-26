<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Application\Provider\ProviderRouter;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Infrastructure\Provider\AbstractProviderClient;
use Tests\TestCase;

/**
 * `analyze_video` and `generate_video_caption` against the app's real file store — §29.
 *
 * The unit suite pins the prompts and the size guard. This pins what only the app can
 * get wrong: that the store's sniffed `video/mp4` reaches the provider as a video data
 * URI, and that a stored document is refused rather than described to the model as a
 * video.
 *
 * No network: a recording provider answers with a canned completion.
 */
final class VideoToolsTest extends TestCase
{
    use RefreshDatabase;

    /**
     * Public so the recording provider can append to it.
     *
     * @var list<array<int, mixed>>
     */
    public array $prompts = array();

    public function test_both_tools_are_registered(): void
    {
        $registry = $this->app->make(ToolRegistry::class);

        $this->assertTrue($registry->has('analyze_video'), 'analyze_video is servable but not registered.');
        $this->assertTrue($registry->has('generate_video_caption'), 'generate_video_caption is servable but not registered.');
    }

    public function test_it_analyses_a_real_stored_video(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $file = $this->storeVideo($user, 'clip.mp4', $this->mp4Header());

        $this->registerFake('A cat crosses the kitchen.');

        $result = $this->tool('analyze_video')->execute(
            array( 'file_id' => $file->id, 'analysis_type' => 'timeline', 'provider' => 'fake-video' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertSame('A cat crosses the kitchen.', $result['data']['analysis']);
        $this->assertSame('timeline', $result['data']['analysis_type']);

        // The store's sniffed type is what the provider is told the bytes are.
        $segment = $this->prompts[0][0]['content'][1];

        $this->assertSame('image_url', $segment['type']);
        $this->assertStringStartsWith('data:video/mp4;base64,', $segment['image_url']['url']);
    }

    public function test_it_captions_a_real_stored_video_within_the_limit(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $file = $this->storeVideo($user, 'clip.mp4', $this->mp4Header());

        $this->registerFake(\str_repeat('word ', 80));

        $result = $this->tool('generate_video_caption')->execute(
            array( 'file_id' => $file->id, 'max_length' => 50, 'provider' => 'fake-video' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertTrue($result['data']['truncated']);
        $this->assertSame(50, \mb_strlen($result['data']['caption']));
    }

    public function test_a_stored_document_is_refused_as_not_a_video(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        $file = $this->storeFile($user, 'notes.txt', "A short document about widgets.\n", 'text/plain');

        $this->registerFake('Should never be asked.');

        $result = $this->tool('analyze_video')->execute(
            array( 'file_id' => $file->id, 'provider' => 'fake-video' ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('not a video', $result->getMessage());
        $this->assertSame(array(), $this->prompts, 'A refusal must not spend a completion.');
    }

    /**
     * A minimal `ftyp` box. The store sniffs the type rather than trusting the
     * extension, and this is enough for it to answer `video/mp4` — which is exactly the
     * behaviour this test needs to see flow through to the provider.
     */
    private function mp4Header(): string
    {
        return "\x00\x00\x00\x18" . 'ftyp' . 'mp42' . "\x00\x00\x00\x00" . 'mp42isom';
    }

    private function storeVideo(User $owner, string $filename, string $bytes): StoredFile
    {
        return $this->storeFile($owner, $filename, $bytes, 'video/mp4');
    }

    private function storeFile(User $owner, string $filename, string $bytes, string $mime): StoredFile
    {
        $source = \tempnam(\sys_get_temp_dir(), 'vid_src_');

        $this->assertNotFalse($source, 'Could not create a source file.');

        \file_put_contents($source, $bytes);

        $file = $this->app->make(FileStoreInterface::class)->store(
            $source,
            $filename,
            $mime,
            (int) $owner->getKey()
        );

        @\unlink($source);

        return $file;
    }

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
            protected string $providerSlug = 'fake-video';

            public function __construct(
                SettingsStoreInterface $settings,
                HttpClientInterface $http,
                ErrorFactoryInterface $errors,
                private readonly string $answer,
                private readonly object $recorder,
            ) {
                parent::__construct($settings, $http, $errors);
            }

            public function chat(array $messages, array $options = array()): mixed
            {
                $recorder = $this->recorder;

                if (\property_exists($recorder, 'prompts')) {
                    $recorder->prompts[] = $messages;
                }

                return array(
                    'model'   => 'fake-video-1',
                    'choices' => array( array( 'message' => array( 'content' => $this->answer ) ) ),
                    'usage'   => array( 'total_tokens' => 25 ),
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

    private function tool(string $slug): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get($slug);

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
