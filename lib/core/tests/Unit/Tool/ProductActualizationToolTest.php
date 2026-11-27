<?php

/**
 * Product actualization: the two-turn orchestration and its named refusals.
 *
 * The tool owns no pixels. Its whole job is to call `generate()` and then
 * `compose()` with the right arguments, in the right order, and to delete the
 * intermediate scene. So the fake production below records calls and answers
 * fixed shapes; the assertions are about the orchestration, the prompt, and
 * what the payload reports — not about an image.
 *
 * The three refusals (video, composite, remove) are tested because each has a
 * next step in its message, and a refusal that drifts into a silent fallback
 * is the failure mode the port is trying to avoid.
 *
 * @package Nvoos\Core
 * @since   3.0.0
 * @license MIT
 *
 * SPDX-License-Identifier: MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\Ecommerce\ProductActualizationTool;
use PHPUnit\Framework\TestCase;

final class ProductActualizationToolTest extends TestCase
{
    /** A 1×1 PNG — the fakes never decode it; the store only checks it exists. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $dir;

    private string $productPath;

    private string $scenePath;

    private string $composedPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->dir = \sys_get_temp_dir() . '/oos-actualization-' . \bin2hex(\random_bytes(5));
        \mkdir($this->dir, 0775, true);

        $bytes = (string) \base64_decode(self::PNG, true);

        $this->productPath = $this->dir . '/product.png';
        $this->scenePath = $this->dir . '/scene.png';
        $this->composedPath = $this->dir . '/composed.png';

        foreach (array($this->productPath, $this->scenePath, $this->composedPath) as $path) {
            \file_put_contents($path, $bytes);
        }
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->dir . '/*') ?: array() as $file) {
            if (\is_file($file)) {
                \unlink($file);
            }
        }

        if (\is_dir($this->dir)) {
            \rmdir($this->dir);
        }

        parent::tearDown();
    }

    public function test_it_generates_a_scene_then_composes_the_product_into_it(): void
    {
        $calls = array();
        $store = $this->store();
        $tool = new ProductActualizationTool(
            $this->errors(),
            $this->production($calls),
            $this->registry(),
            $store,
        );

        $result = $tool->execute(
            array(
                'file_id' => 7,
                'scene_prompt' => 'bright kitchen counter, morning light',
                'placement_hint' => 'centre on the table',
            ),
            array('user_id' => 1)
        );

        $this->assertTrue($result['success'] ?? false, \print_r($result, true));

        // The two calls, in order, with the scene first and the product second.
        $this->assertSame('generate', $calls[0][0]);
        $this->assertSame('bright kitchen counter, morning light', $calls[0][1]);
        $this->assertSame('3:2', $calls[0][2]['aspect_ratio']);
        $this->assertSame('auto', $calls[0][2]['provider']);

        $this->assertSame('compose', $calls[1][0]);
        $this->assertSame($this->scenePath, $calls[1][1], 'The generated scene is the first image.');
        $this->assertSame($this->productPath, $calls[1][2], 'The product is the second image.');
        $this->assertStringContainsString('bright kitchen counter, morning light', $calls[1][3]);
        $this->assertStringContainsString('Position the product as follows: centre on the table.', $calls[1][3]);

        $data = $result['data'];

        $this->assertSame('image', $data['mode']);
        $this->assertSame('ai', $data['integration_mode']);
        $this->assertSame('auto', $data['background_mode']);
        $this->assertFalse($data['background_removed'], 'No removal capability exists, and the payload says so.');
        $this->assertSame('gemini', $data['provider']);
        $this->assertSame(96, $data['width']);
        $this->assertSame(72, $data['height']);
        $this->assertSame('gemini-2.5-flash-image', $data['scene_model']);
        $this->assertSame(array('width' => 1792, 'height' => 1024), $data['scene_dimensions']);

        // The final image is stored; the intermediate scene is not kept.
        $this->assertCount(1, $store->writes);
        $this->assertSame($this->composedPath, $store->writes[0]['path']);
        $this->assertSame(701, $data['media'][0]['attachment_id']);
        $this->assertFileDoesNotExist($this->scenePath, 'The intermediate scene is cleaned up.');
        $this->assertFileExists($this->composedPath, 'The composition is the artifact.');
    }

    public function test_video_mode_is_refused_with_a_next_step(): void
    {
        $calls = array();
        $tool = new ProductActualizationTool($this->errors(), $this->production($calls), $this->registry());

        $result = $tool->execute(
            array(
                'file_id' => 7,
                'scene_prompt' => 'a kitchen',
                'mode' => 'video',
            ),
            array('user_id' => 1)
        );

        $this->assertSame('video_generation_unavailable', $result['error']['code']);
        $this->assertStringContainsString('mode "image"', $result['error']['message']);
        $this->assertSame(array(), $calls, 'A refused mode spends no model call.');
    }

    public function test_composite_mode_is_refused_with_a_next_step(): void
    {
        $calls = array();
        $tool = new ProductActualizationTool($this->errors(), $this->production($calls), $this->registry());

        $result = $tool->execute(
            array(
                'file_id' => 7,
                'scene_prompt' => 'a kitchen',
                'integration_mode' => 'composite',
            ),
            array('user_id' => 1)
        );

        $this->assertSame('composite_not_available', $result['error']['code']);
        $this->assertStringContainsString('integration_mode "ai"', $result['error']['message']);
        $this->assertSame(array(), $calls);
    }

    public function test_background_removal_is_refused_with_a_next_step(): void
    {
        $calls = array();
        $tool = new ProductActualizationTool($this->errors(), $this->production($calls), $this->registry());

        $result = $tool->execute(
            array(
                'file_id' => 7,
                'scene_prompt' => 'a kitchen',
                'background_mode' => 'remove',
            ),
            array('user_id' => 1)
        );

        $this->assertSame('background_removal_unavailable', $result['error']['code']);
        $this->assertStringContainsString('"auto" or "preserve"', $result['error']['message']);
        $this->assertSame(array(), $calls);
    }

    public function test_a_worker_that_cannot_compose_refuses_before_generating(): void
    {
        $calls = array();
        $tool = new ProductActualizationTool(
            $this->errors(),
            $this->production($calls, array('generate' => true, 'compose' => false)),
            $this->registry()
        );

        $result = $tool->execute(
            array('file_id' => 7, 'scene_prompt' => 'a kitchen'),
            array('user_id' => 1)
        );

        $this->assertSame('no_image_composition_provider', $result['error']['code']);
        $this->assertSame(array(), $calls, 'A paid scene must not be generated for a composition that cannot happen.');
    }

    public function test_a_missing_file_id_is_a_validation_failure(): void
    {
        $calls = array();
        $tool = new ProductActualizationTool($this->errors(), $this->production($calls), $this->registry());

        $result = $tool->execute(array('scene_prompt' => 'a kitchen'), array('user_id' => 1));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_file_the_caller_cannot_see_answers_not_found(): void
    {
        $calls = array();
        $tool = new ProductActualizationTool(
            $this->errors(),
            $this->production($calls),
            $this->registry(false)
        );

        $result = $tool->execute(
            array('file_id' => 7, 'scene_prompt' => 'a kitchen'),
            array('user_id' => 1)
        );

        $this->assertSame('not_found', $result['error']['code']);
        $this->assertSame(array(), $calls);
    }

    /**
     * @param array<int, array<int, mixed>> $calls
     * @param array<string, bool>           $supports
     */
    private function production(array &$calls, array $supports = array()): ImageProductionInterface
    {
        return new class ($calls, $supports, $this->scenePath, $this->composedPath) implements ImageProductionInterface {
            /** @var array<int, array<int, mixed>> */
            private $calls;

            /** @var array<string, bool> */
            private array $supports;

            public function __construct(
                array &$calls,
                array $supports,
                private readonly string $scenePath,
                private readonly string $composedPath,
            ) {
                $this->calls = &$calls;
                $this->supports = $supports;
            }

            public function generate(string $prompt, array $options = array()): array
            {
                $this->calls[] = array('generate', $prompt, $options);

                return array(
                    'path' => $this->scenePath,
                    'width' => 1792,
                    'height' => 1024,
                    'format' => 'png',
                    'mime_type' => 'image/png',
                    'bytes' => 70,
                    'operation' => 'generate',
                    'provider' => 'gemini',
                    'model' => 'gemini-2.5-flash-image',
                    'prompt' => $prompt,
                );
            }

            public function compose(string $scenePath, string $productPath, array $options = array()): array
            {
                $this->calls[] = array('compose', $scenePath, $productPath, (string) ($options['prompt'] ?? ''));

                return array(
                    'path' => $this->composedPath,
                    'width' => 96,
                    'height' => 72,
                    'format' => 'png',
                    'mime_type' => 'image/png',
                    'bytes' => 96,
                    'scene_bytes' => 70,
                    'product_bytes' => 70,
                    'operation' => 'compose',
                    'provider' => 'gemini',
                    'model' => 'gemini-3.1-flash-image',
                    'prompt' => (string) ($options['prompt'] ?? ''),
                );
            }

            public function supports(string $operation): bool
            {
                return $this->supports[$operation] ?? true;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function optimize(string $sourcePath, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function enhance(string $sourcePath, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function upscale(string $sourcePath, int $factor = 2, string $kernel = 'lanczos3'): array
            {
                throw new \LogicException('not used');
            }

            public function resizeSmart(string $sourcePath, int $width, int $height, string $position = 'attention'): array
            {
                throw new \LogicException('not used');
            }

            public function batch(array $sourcePaths, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function edit(string $sourcePath, string $operation, array $options = array()): array
            {
                throw new \LogicException('not used');
            }
        };
    }

    private function registry(bool $accessible = true): FileStoreInterface
    {
        $src = $this->productPath;

        return new class ($accessible, $src) implements FileStoreInterface {
            public function __construct(private readonly bool $accessible, private readonly string $path)
            {
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \LogicException('not used');
            }

            public function getPath(int $fileId): ?string
            {
                return $this->path;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return 7 === $fileId
                    ? new StoredFile(
                        7,
                        'product.png',
                        'image/png',
                        (int) (\filesize($this->path) ?: 0),
                        $this->path,
                        1,
                        new \DateTimeImmutable(),
                        'https://example.test/media/product.png',
                        array(),
                        'public',
                        'product',
                    )
                    : null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return $this->accessible;
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return array();
            }
        };
    }

    private function store(): FileVisibilityStoreInterface
    {
        return new class () implements FileVisibilityStoreInterface {
            /** @var list<array<string, string>> */
            public array $writes = array();

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                $this->writes[] = array(
                    'path' => $localPath,
                    'filename' => $filename,
                    'mime_type' => $mimeType,
                    'visibility' => $visibility,
                );

                return new StoredFile(
                    701,
                    $filename . '.png',
                    $mimeType,
                    (int) (\filesize($localPath) ?: 0),
                    $localPath,
                    $userId,
                    new \DateTimeImmutable(),
                    'https://example.test/media/' . $filename . '.png',
                    array(),
                    $visibility,
                    'actualization',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return true;
            }
        };
    }

    private function errors(): ErrorFactoryInterface
    {
        $errors = $this->createMock(ErrorFactoryInterface::class);

        $errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => \is_array($value) && isset($value['error'])
        );

        $errors->method('create')->willReturnCallback(
            static fn (string $code, string $message): array => array(
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $errors->method('validationFailed')->willReturnCallback(
            static fn (string $message): array => array(
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );

        $errors->method('notFound')->willReturnCallback(
            static fn (string $message): array => array(
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );

        return $errors;
    }
}
