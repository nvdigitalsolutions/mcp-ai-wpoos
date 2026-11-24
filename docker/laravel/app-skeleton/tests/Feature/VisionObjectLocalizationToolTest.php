<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Nvoos\Core\Application\Tool\ToolRegistry;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\ToolInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Tests\TestCase;

/**
 * `vision_object_localization` against the app's real file store — §30.
 *
 * The unit suite pins the response parsing and the failure shapes. This pins what only
 * the app can get wrong: that a real stored image reaches Cloud Vision as base64 in the
 * documented request shape, and that a missing key is refused before any request.
 *
 * No network: `Http::fake()` intercepts vision.googleapis.com.
 */
final class VisionObjectLocalizationToolTest extends TestCase
{
    use RefreshDatabase;

    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    public function test_the_container_builds_the_tool(): void
    {
        $this->assertTrue(
            $this->app->make(ToolRegistry::class)->has('vision_object_localization'),
            'The manifest row is servable but the app did not register it.'
        );
    }

    public function test_it_localises_objects_in_a_real_stored_image(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        config(array( 'services.gemini.key' => 'google-key' ));

        Http::fake(array(
            'vision.googleapis.com/*' => Http::response(array(
                'responses' => array(
                    array(
                        'localizedObjectAnnotations' => array(
                            array(
                                'name'         => 'Cat',
                                'score'        => 0.94,
                                'boundingPoly' => array(
                                    'normalizedVertices' => array(
                                        array( 'x' => 0.2, 'y' => 0.3 ),
                                        array( 'x' => 0.8, 'y' => 0.9 ),
                                    ),
                                ),
                            ),
                        ),
                    ),
                ),
            )),
        ));

        $file = $this->storeImage($user);

        $result = $this->tool()->execute(
            array( 'file_id' => $file->id ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['count']);
        $this->assertSame('Cat', $result['data']['objects'][0]['name']);
        $this->assertSame(
            array( 'x_min' => 0.2, 'y_min' => 0.3, 'x_max' => 0.8, 'y_max' => 0.9 ),
            $result['data']['objects'][0]['box']
        );

        Http::assertSent(static fn ($request): bool => 'POST' === $request->method()
            && \str_contains($request->url(), 'images:annotate')
            && \str_contains($request->url(), 'key=google-key')
            && 'OBJECT_LOCALIZATION' === $request['requests'][0]['features'][0]['type']);
    }

    public function test_a_missing_key_sends_no_request(): void
    {
        Storage::fake('public');

        $user = User::factory()->admin()->create();

        $this->actingAs($user);

        config(array( 'services.gemini.key' => null, 'oos.api_keys.gemini' => null ));

        Http::fake();

        $file = $this->storeImage($user);

        $result = $this->tool()->execute(
            array( 'file_id' => $file->id ),
            array( 'user_id' => (int) $user->getKey() )
        );

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('Vision API enabled', $result->getMessage());

        Http::assertNothingSent();
    }

    private function storeImage(User $owner): StoredFile
    {
        $source = \tempnam(\sys_get_temp_dir(), 'vis_src_');

        $this->assertNotFalse($source, 'Could not create a source file.');

        \file_put_contents($source, \base64_decode(self::ONE_PIXEL_PNG, true));

        $file = $this->app->make(FileStoreInterface::class)->store(
            $source,
            'shot.png',
            'image/png',
            (int) $owner->getKey()
        );

        @\unlink($source);

        return $file;
    }

    private function tool(): ToolInterface
    {
        $tool = $this->app->make(ToolRegistry::class)->get('vision_object_localization');

        $this->assertInstanceOf(ToolInterface::class, $tool);

        return $tool;
    }
}
