<?php

/**
 * `vision_object_localization`.
 *
 * The assertions that matter are about honesty rather than arithmetic:
 *
 * - a `200 OK` with a per-image error is a **failure**, not "no objects found";
 * - the provider's own message survives, because it is the sentence that tells an
 *   operator which API to enable;
 * - a malformed polygon yields no box instead of a `0,0,0,0` box that would put every
 *   object in the top-left corner.
 *
 * @package Nvoos\Core\Tests
 * @since   2.1.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\HttpClientInterface;
use Nvoos\Core\Domain\Contract\SettingsStoreInterface;
use Nvoos\Core\Domain\Entity\HttpResponse;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\VisionObjectLocalizationDetectTool;
use PHPUnit\Framework\TestCase;

final class VisionObjectLocalizationToolTest extends TestCase
{
    private const ONE_PIXEL_PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $temporary = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('isError')->willReturnCallback(
            static fn (mixed $value): bool => $value instanceof \Throwable
                || ( \is_array($value) && isset($value['error']) )
        );

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => $code, 'message' => $message ),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'validation_failed', 'message' => $message ),
            )
        );

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = '', array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'not_found', 'message' => $message ),
            )
        );

        $this->errors->method('forbidden')->willReturnCallback(
            static fn (string $message = '', array $data = array()): array => array(
                'success' => false,
                'error'   => array( 'code' => 'forbidden', 'message' => $message ),
            )
        );
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }

        $this->temporary = array();
    }

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = new VisionObjectLocalizationDetectTool($this->errors);

        $this->assertSame('vision_object_localization', $tool->getSlug());
        $this->assertSame('edit_posts', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertContains('requires-credentials', $tool->getCapabilityFlags());
        $this->assertNotContains('consumes-tokens', $tool->getCapabilityFlags());
    }

    public function test_a_host_without_http_or_settings_says_so(): void
    {
        $result = (new VisionObjectLocalizationDetectTool($this->errors, $this->store()))
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('vision_unavailable', $result['error']['code']);
    }

    public function test_a_missing_api_key_is_named_with_the_requirement(): void
    {
        $result = $this->tool($this->http(), '')
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('missing_api_key', $result['error']['code']);
        $this->assertStringContainsString('Vision API enabled', $result['error']['message']);
    }

    public function test_a_provider_refusal_is_reported_with_its_status_and_body(): void
    {
        $result = $this->tool(
            new HttpResponse(403, '{"error":{"message":"Cloud Vision API has not been used in project 1"}}')
        )->execute(array( 'file_id' => 1 ));

        $this->assertSame('provider_error', $result['error']['code']);
        $this->assertStringContainsString('403', $result['error']['message']);
        $this->assertStringContainsString('has not been used', $result['error']['message']);
    }

    public function test_a_two_hundred_with_a_per_image_error_is_a_failure(): void
    {
        // Vision answers 200 and puts the failure inside the batch payload. Reporting
        // that as "no objects found" would be a different, wrong answer.
        $result = $this->tool(new HttpResponse(
            200,
            '{"responses":[{"error":{"code":3,"message":"Bad image data."}}]}'
        ))->execute(array( 'file_id' => 1 ));

        $this->assertSame('provider_error', $result['error']['code']);
        $this->assertStringContainsString('Bad image data', $result['error']['message']);
    }

    public function test_malformed_json_is_invalid(): void
    {
        $result = $this->tool(new HttpResponse(200, '<html>nope</html>'))
            ->execute(array( 'file_id' => 1 ));

        $this->assertSame('invalid_response', $result['error']['code']);
    }

    public function test_it_returns_objects_with_scores_and_boxes(): void
    {
        $captured = array();

        $result = $this->tool(
            new HttpResponse(200, (string) \json_encode(array(
                'responses' => array(
                    array(
                        'localizedObjectAnnotations' => array(
                            array(
                                'name'           => 'Cat',
                                'score'          => 0.9123,
                                'mid'            => '/m/01xqw',
                                'boundingPoly'   => array(
                                    'normalizedVertices' => array(
                                        array( 'x' => 0.25, 'y' => 0.1 ),
                                        array( 'x' => 0.75, 'y' => 0.9 ),
                                    ),
                                ),
                            ),
                            array(
                                'name'  => 'Chair',
                                'score' => 0.5,
                            ),
                        ),
                    ),
                ),
            ))),
            'google-key',
            $captured
        )->execute(array( 'file_id' => 1, 'max_results' => 5 ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['count']);
        $this->assertSame('Cat', $result['data']['objects'][0]['name']);
        $this->assertSame(0.912, $result['data']['objects'][0]['score']);
        $this->assertSame(
            array( 'x_min' => 0.25, 'y_min' => 0.1, 'x_max' => 0.75, 'y_max' => 0.9 ),
            $result['data']['objects'][0]['box']
        );

        // No polygon at all yields no box, rather than a box in the corner.
        $this->assertNull($result['data']['objects'][1]['box']);

        $this->assertStringContainsString('key=google-key', $captured['url']);
        $this->assertSame('POST', $captured['method']);

        $payload = \json_decode((string) $captured['body'], true);

        $this->assertSame('OBJECT_LOCALIZATION', $payload['requests'][0]['features'][0]['type']);
        $this->assertSame(5, $payload['requests'][0]['features'][0]['maxResults']);
        $this->assertNotEmpty($payload['requests'][0]['image']['content']);
    }

    public function test_max_results_is_clamped_to_the_supported_range(): void
    {
        $captured = array();

        $this->tool($this->http(), 'google-key', $captured)
            ->execute(array( 'file_id' => 1, 'max_results' => 500 ));

        $payload = \json_decode((string) $captured['body'], true);

        $this->assertSame(100, $payload['requests'][0]['features'][0]['maxResults']);
    }

    // ── Helpers ──────────────────────────────────────────────────────────────

    private function tool(
        ?HttpResponse $response = null,
        string $key = 'google-key',
        array &$captured = array(),
    ): VisionObjectLocalizationDetectTool {
        $http = $this->createMock(HttpClientInterface::class);

        $http->method('send')->willReturnCallback(
            static function (string $method, string $url, array $headers, ?string $body) use ($response, &$captured): HttpResponse {
                $captured = array( 'method' => $method, 'url' => $url, 'headers' => $headers, 'body' => $body );

                return $response ?? new HttpResponse(200, '{"responses":[{"localizedObjectAnnotations":[]}]}');
            }
        );

        $settings = $this->createMock(SettingsStoreInterface::class);
        $settings->method('getApiKey')->willReturn('' === $key ? null : $key);

        return new VisionObjectLocalizationDetectTool(
            $this->errors,
            $this->store(),
            $http,
            $settings
        );
    }

    private function http(?HttpResponse $response = null): HttpResponse
    {
        return $response ?? new HttpResponse(200, '{"responses":[{"localizedObjectAnnotations":[]}]}');
    }

    private function store(): FileStoreInterface
    {
        $path = \tempnam(\sys_get_temp_dir(), 'vis_');

        $this->assertNotFalse($path, 'Could not create a temporary file.');

        \file_put_contents($path, \base64_decode(self::ONE_PIXEL_PNG, true));

        $this->temporary[] = $path;

        $file = new StoredFile(
            id: 1,
            filename: 'shot.png',
            mimeType: 'image/png',
            sizeBytes: (int) \filesize($path),
            localPath: $path,
            ownerId: 3,
            createdAt: new \DateTimeImmutable(),
        );

        $store = $this->createMock(FileStoreInterface::class);
        $store->method('getMetadata')->willReturn($file);
        $store->method('userCanAccess')->willReturn(true);

        return $store;
    }
}
