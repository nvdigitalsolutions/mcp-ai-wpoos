<?php

/**
 * `resize_image_smart` — the content-aware crop, and what it refuses to claim.
 *
 * The WordPress tool this ports advertises four `mode` values and a `focus_area`
 * including `face`. Three of the four modes collapse onto one engine call and
 * `focus_area` is never read, so the schema is the part of that tool most likely
 * to be copied by mistake. The first test here is therefore about **absence**:
 * the port must not offer a knob the engine does not honour.
 *
 * The rest pins the three ways a one-dimension resize can go wrong:
 *
 *  - a dimension is derived from the source aspect ratio, not guessed square;
 *  - a host with no geometry engine refuses by name instead of inventing a size;
 *  - an engine without `image_smart_crop` refuses by name instead of falling
 *    back to a centre crop, which would return success for a different edit.
 *
 * @package Nvoos\Core\Tests
 * @since   2.6.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\ImageProcessingInterface;
use Nvoos\Core\Domain\Contract\ImageProductionInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\ResizeImageSmartTool;
use PHPUnit\Framework\TestCase;

final class ResizeImageSmartToolTest extends TestCase
{
    /** A 1x1 PNG — the engine fake never decodes it, the store only checks it exists. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $workDir;

    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->workDir = \sys_get_temp_dir() . '/oos-smartcrop-' . \bin2hex(\random_bytes(5));

        \mkdir($this->workDir, 0775, true);

        $this->errors = new class () implements ErrorFactoryInterface {
            public function create(string $code, string $message, array $data = array()): mixed
            {
                return array('error' => array('code' => $code, 'message' => $message, 'data' => $data));
            }

            public function isError(mixed $value): bool
            {
                return \is_array($value) && isset($value['error']);
            }

            public function normalize(mixed $error): array
            {
                return (array) $error;
            }

            public function notFound(string $message = 'Resource not found.', array $data = array()): mixed
            {
                return $this->create('not_found', $message, $data);
            }

            public function forbidden(string $message = 'Access denied.', array $data = array()): mixed
            {
                return $this->create('forbidden', $message, $data);
            }

            public function validationFailed(string $message, array $errors = array()): mixed
            {
                return $this->create('validation_failed', $message, $errors);
            }

            public function rateLimited(string $message, int $retryAfterSeconds = 60): mixed
            {
                return $this->create('rate_limited', $message);
            }
        };
    }

    protected function tearDown(): void
    {
        foreach (\glob($this->workDir . '/*') ?: array() as $file) {
            @\unlink($file);
        }

        @\rmdir($this->workDir);

        parent::tearDown();
    }

    public function test_it_declares_the_slug_capability_and_flags(): void
    {
        $tool = new ResizeImageSmartTool($this->errors, $this->production());

        $this->assertSame('resize_image_smart', $tool->getSlug());
        $this->assertSame('Resize Image (Smart)', $tool->getName());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertSame(
            array('requires-capability', 'state-changing', 'write', 'idempotent'),
            $tool->getCapabilityFlags(),
        );
    }

    /**
     * The anti-regression for accepted-then-ignored.
     *
     * `mode` would collapse `smart` and `fill` onto the same call, `focus_area`
     * has no engine behind it here, and `resizeSmart()` takes no quality, so all
     * three are absent rather than accepted and dropped.
     */
    public function test_the_schema_offers_only_the_knobs_the_engine_honours(): void
    {
        $schema = (new ResizeImageSmartTool($this->errors, $this->production()))->getParametersSchema();
        $properties = $schema['properties'];

        $this->assertArrayNotHasKey('mode', $properties, '`mode` collapses onto one engine call; resize_image owns fit and crop.');
        $this->assertArrayNotHasKey('focus_area', $properties, 'No face detection is bound; the WordPress tool ignored this argument.');
        $this->assertArrayNotHasKey('quality', $properties, 'ImageProductionInterface::resizeSmart() takes no quality.');

        $this->assertSame(
            array('attention', 'entropy', 'centre', 'top', 'bottom', 'left', 'right'),
            $properties['position']['enum'],
            'Only the positions the contract documents are portable.',
        );
        $this->assertSame('attention', $properties['position']['default']);
    }

    public function test_a_missing_source_is_a_validation_failure(): void
    {
        $result = (new ResizeImageSmartTool($this->errors, $this->production()))->execute(array('width' => 100));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_missing_dimensions_are_a_validation_failure(): void
    {
        $result = (new ResizeImageSmartTool($this->errors, $this->production()))->execute(array(
            'image_url' => $this->source(),
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_an_unknown_position_is_refused_with_the_accepted_set(): void
    {
        $result = (new ResizeImageSmartTool($this->errors, $this->production()))->execute(array(
            'image_url' => $this->source(),
            'width' => 10,
            'height' => 10,
            'position' => 'face',
        ));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('attention', $result['error']['data']['position'][0]);
    }

    public function test_an_engine_without_smart_crop_refuses_by_name(): void
    {
        $result = (new ResizeImageSmartTool($this->errors, $this->production(false)))->execute(array(
            'image_url' => $this->source(),
            'width' => 10,
            'height' => 10,
        ));

        $this->assertSame('image_pipeline_unavailable', $result['error']['code']);
        $this->assertStringContainsString('image_smart_crop', $result['error']['message']);
    }

    public function test_the_missing_width_is_derived_from_the_source_aspect_ratio(): void
    {
        $captured = null;
        $tool = new ResizeImageSmartTool(
            $this->errors,
            $this->production(true, $captured),
            null,
            null,
            $this->geometry(800, 400),
        );

        $result = $tool->execute(array('image_url' => $this->source(), 'height' => 100));

        $this->assertTrue($result['success']);
        $this->assertSame(200, $captured['width'], '800x400 at height 100 is 200 wide.');
        $this->assertSame(100, $captured['height']);
        $this->assertSame('width', $result['data']['derived']);
    }

    public function test_the_missing_height_is_derived_from_the_source_aspect_ratio(): void
    {
        $captured = null;
        $tool = new ResizeImageSmartTool(
            $this->errors,
            $this->production(true, $captured),
            null,
            null,
            $this->geometry(800, 400),
        );

        $result = $tool->execute(array('image_url' => $this->source(), 'width' => 200));

        $this->assertTrue($result['success']);
        $this->assertSame(200, $captured['width']);
        $this->assertSame(100, $captured['height']);
        $this->assertSame('height', $result['data']['derived']);
    }

    public function test_without_a_geometry_engine_a_missing_dimension_refuses_rather_than_guessing(): void
    {
        $result = (new ResizeImageSmartTool($this->errors, $this->production()))->execute(array(
            'image_url' => $this->source(),
            'width' => 100,
        ));

        $this->assertSame('no_dimension_source', $result['error']['code']);
    }

    public function test_both_dimensions_are_passed_through_with_the_position(): void
    {
        $captured = null;
        $tool = new ResizeImageSmartTool($this->errors, $this->production(true, $captured));

        $result = $tool->execute(array(
            'image_url' => $this->source(),
            'width' => 64,
            'height' => 48,
            'position' => 'entropy',
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(64, $captured['width']);
        $this->assertSame(48, $captured['height']);
        $this->assertSame('entropy', $captured['position']);
        $this->assertSame('entropy', $result['data']['position'], 'The engine-resolved position is reported.');
        $this->assertArrayNotHasKey('derived', $result['data']);
    }

    public function test_a_stored_file_resolves_through_the_registry(): void
    {
        $captured = null;
        $stores = $this->stores();
        $tool = new ResizeImageSmartTool($this->errors, $this->production(true, $captured), $stores, null);

        $result = $tool->execute(array('file_id' => 7, 'width' => 10, 'height' => 10));

        $this->assertTrue($result['success']);
        $this->assertSame('/tmp/from-registry.png', $captured['source'], 'The path came from the registry, not the argument.');
    }

    public function test_the_output_is_stored_when_the_host_has_a_registry(): void
    {
        $stores = $this->stores();
        $tool = new ResizeImageSmartTool($this->errors, $this->production(), $stores, $stores);

        $result = $tool->execute(array('image_url' => $this->source(), 'width' => 10, 'height' => 10));

        $this->assertTrue($result['success']);
        $this->assertCount(1, $stores->writes);
        $this->assertSame(701, $result['data']['media'][0]['attachment_id']);
        $this->assertStringContainsString('smart-crop-', $stores->writes[0]['filename']);
    }

    public function test_save_to_media_false_leaves_the_registry_alone(): void
    {
        $stores = $this->stores();
        $tool = new ResizeImageSmartTool($this->errors, $this->production(), $stores, $stores);

        $result = $tool->execute(array(
            'image_url' => $this->source(),
            'width' => 10,
            'height' => 10,
            'save_to_media' => false,
        ));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('media', $result['data']);
        $this->assertCount(0, $stores->writes);
    }

    public function test_an_engine_failure_is_named_rather_than_thrown(): void
    {
        $captured = null;
        $tool = new ResizeImageSmartTool(
            $this->errors,
            $this->production(true, $captured, new \RuntimeException('worker said no')),
        );

        $result = $tool->execute(array('image_url' => $this->source(), 'width' => 10, 'height' => 10));

        $this->assertSame('resize_smart_failed', $result['error']['code']);
        $this->assertStringContainsString('worker said no', $result['error']['message']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function source(): string
    {
        $path = $this->workDir . '/source.png';

        if (! \is_file($path)) {
            \file_put_contents($path, (string) \base64_decode(self::PNG, true));
        }

        return $path;
    }

    /**
     * A production engine fake. Every interface method is implemented because an
     * anonymous class cannot inherit defaults.
     *
     * @param array<string, mixed>|null $captured Receives the `resizeSmart()` call.
     */
    private function production(
        bool $supports = true,
        ?array &$captured = null,
        ?\Throwable $throws = null,
    ): ImageProductionInterface {
        $captured = null;

        return new class ($supports, $captured, $throws, $this->source()) implements ImageProductionInterface {
            /**
             * Untyped on purpose: it is bound by reference to the caller's
             * variable, and a typed property cannot hold a reference to a null
             * that later becomes an array.
             *
             * @var array<string, mixed>|null
             */
            private $captured;

            /**
             * A promoted property cannot be by-reference, so the hook is
             * assigned in the body.
             *
             * @param array<string, mixed>|null $captured
             */
            public function __construct(
                private readonly bool $supported,
                ?array &$captured,
                private readonly ?\Throwable $throws,
                private readonly string $output,
            ) {
                $this->captured = &$captured;
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
                if (null !== $this->throws) {
                    throw $this->throws;
                }

                $this->captured = array(
                    'source' => $sourcePath,
                    'width' => $width,
                    'height' => $height,
                    'position' => $position,
                );

                return array(
                    'path' => $this->output,
                    'width' => $width,
                    'height' => $height,
                    'format' => 'png',
                    'mime_type' => 'image/png',
                    'bytes' => 70,
                    'original_bytes' => 140,
                    'position' => $position,
                );
            }

            public function batch(array $sourcePaths, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function edit(string $sourcePath, string $operation, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function supports(string $operation): bool
            {
                return 'resize_smart' === $operation && $this->supported;
            }

            public function isAvailable(): bool
            {
                return true;
            }
        };
    }

    private function geometry(int $width, int $height): ImageProcessingInterface
    {
        return new class ($width, $height) implements ImageProcessingInterface {
            public function __construct(private readonly int $width, private readonly int $height)
            {
            }

            public function resize(string $sourcePath, int $width, int $height, array $options = array()): array
            {
                throw new \LogicException('not used');
            }

            public function crop(string $sourcePath, int $x, int $y, int $width, int $height): array
            {
                throw new \LogicException('not used');
            }

            public function rotate(string $sourcePath, float $angle, string $background = '#ffffff'): array
            {
                throw new \LogicException('not used');
            }

            public function convert(string $sourcePath, string $targetFormat, int $quality = 90): array
            {
                throw new \LogicException('not used');
            }

            public function getInfo(string $sourcePath): array
            {
                return array(
                    'width' => $this->width,
                    'height' => $this->height,
                    'mime_type' => 'image/png',
                    'bytes' => 140,
                    'format' => 'png',
                );
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedFormats(): array
            {
                return array('png');
            }
        };
    }

    /**
     * Recording stores. `writes` is public so a test can assert on what landed.
     */
    private function stores(): object
    {
        return new class () implements FileStoreInterface, FileVisibilityStoreInterface {
            /** @var list<array<string, string>> */
            public array $writes = array();

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                throw new \LogicException('not used');
            }

            public function getPath(int $fileId): ?string
            {
                return 7 === $fileId ? '/tmp/from-registry.png' : null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return true;
            }

            public function delete(int $fileId): void
            {
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return array();
            }

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
                    'smart-crop',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return true;
            }
        };
    }
}
