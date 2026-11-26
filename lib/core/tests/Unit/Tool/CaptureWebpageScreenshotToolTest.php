<?php

/**
 * `capture_webpage_screenshot`.
 *
 * Three things are being proven here, in this order:
 *
 *  - the arguments WordPress accepts-and-drops are refused instead (§164.53):
 *    `width`/`height` outside `viewport: custom`, and `quality` on PNG;
 *  - the bytes reach the file registry, because a screenshot with no stored file
 *    is not a deliverable on this runtime;
 *  - every refusal names itself, so a host without a worker, a store, or a
 *    reachable page reads as an absence rather than a failure.
 *
 * The capture engine and the store are recording doubles: the assertions are
 * about the request the tool built and the bytes it handed over.
 *
 * @package Nvoos\Core\Tests
 * @since   2.7.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\BrowserCaptureInterface;
use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\CaptureWebpageScreenshotTool;
use PHPUnit\Framework\TestCase;

final class CaptureWebpageScreenshotToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

        $this->errors->method('create')->willReturnCallback(
            static fn (string $code, string $message, array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => $code, 'message' => $message),
            )
        );

        $this->errors->method('validationFailed')->willReturnCallback(
            static fn (string $message, array $fields = array()): array => array(
                'success' => false,
                'error' => array('code' => 'validation_failed', 'message' => $message),
            )
        );
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->tool($this->browser());

        $this->assertSame('capture_webpage_screenshot', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('external-api', $tool->getCapabilityFlags());
        $this->assertContains('may-timeout', $tool->getCapabilityFlags());
    }

    /**
     * The schema must name the two arguments that are refused outside their
     * mode; a caller reads the refusal in the description before it fires.
     */
    public function test_the_schema_says_where_width_height_and_quality_are_read(): void
    {
        $properties = $this->tool($this->browser())->getParametersSchema()['properties'];

        $this->assertStringContainsString('only when viewport is "custom"', $properties['width']['description']);
        $this->assertStringContainsString('only for JPEG output', $properties['quality']['description']);
    }

    // ─── Arguments refused rather than ignored ─────────────────────────────

    public function test_a_missing_url_is_refused(): void
    {
        $result = $this->tool($this->browser())->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_non_http_scheme_is_refused_before_the_engine_is_asked(): void
    {
        $browser = $this->browser();
        $result  = $this->tool($browser)->execute(array('url' => 'ftp://example.com/page'));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $browser->calls);
    }

    public function test_an_unknown_viewport_is_refused(): void
    {
        $result = $this->tool($this->browser())->execute(
            array('url' => 'https://example.com', 'viewport' => 'watch')
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_custom_viewport_needs_both_dimensions(): void
    {
        $result = $this->tool($this->browser())->execute(
            array('url' => 'https://example.com', 'viewport' => 'custom', 'width' => 800)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('both width and height', $result['error']['message']);
    }

    /**
     * The WordPress tool accepts `width` beside a preset and drops it. Dropping
     * it silently is the defect this programme keeps removing, so the pair is
     * refused with the rule in the message.
     */
    public function test_dimensions_beside_a_preset_are_refused(): void
    {
        $browser = $this->browser();
        $result  = $this->tool($browser)->execute(
            array('url' => 'https://example.com', 'viewport' => 'desktop', 'width' => 800)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('only read when viewport is "custom"', $result['error']['message']);
        $this->assertSame(array(), $browser->calls);
    }

    public function test_a_custom_viewport_outside_the_bounds_is_refused(): void
    {
        $result = $this->tool($this->browser())->execute(
            array('url' => 'https://example.com', 'viewport' => 'custom', 'width' => 8000, 'height' => 600)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_quality_on_png_is_refused(): void
    {
        $result = $this->tool($this->browser())->execute(
            array('url' => 'https://example.com', 'format' => 'png', 'quality' => 80)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('only read for JPEG', $result['error']['message']);
    }

    public function test_a_timeout_outside_the_window_is_refused(): void
    {
        $result = $this->tool($this->browser())->execute(
            array('url' => 'https://example.com', 'timeout' => 90)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_save_to_media_false_is_refused_rather_than_ignored(): void
    {
        $result = $this->tool($this->browser())->execute(
            array('url' => 'https://example.com', 'save_to_media' => false)
        );

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('always stored', $result['error']['message']);
    }

    // ─── The host, the engine and the store ────────────────────────────────

    public function test_a_host_without_a_file_registry_says_so(): void
    {
        $result = $this->tool($this->browser(), null)->execute(
            array('url' => 'https://example.com')
        );

        $this->assertSame('no_file_store', $result['error']['code']);
    }

    public function test_a_host_without_a_browser_engine_says_so(): void
    {
        $browser = $this->browser(false);

        $result = $this->tool($browser, $this->store())->execute(array('url' => 'https://example.com'));

        $this->assertSame('no_browser_engine', $result['error']['code']);
        $this->assertSame(array(), $browser->calls);
    }

    public function test_an_engine_failure_keeps_its_reason(): void
    {
        $browser = $this->browser(true, new \RuntimeException('The URL resolved to a private address.'));

        $result = $this->tool($browser, $this->store())->execute(array('url' => 'https://example.com'));

        $this->assertSame('screenshot_failed', $result['error']['code']);
        $this->assertStringContainsString('private address', $result['error']['message']);
    }

    // ─── The happy path ────────────────────────────────────────────────────

    public function test_a_preset_capture_asks_for_the_preset_and_stores_the_bytes(): void
    {
        $browser = $this->browser();
        $store   = $this->store();

        $result = $this->tool($browser, $store)->execute(
            array('url' => 'https://example.com/page'),
            array('user_id' => 7)
        );

        $this->assertTrue($result['success']);

        $this->assertCount(1, $browser->calls);
        $this->assertSame('https://example.com/page', $browser->calls[0]['url']);
        $this->assertSame(1920, $browser->calls[0]['options']['width']);
        $this->assertSame(1080, $browser->calls[0]['options']['height']);
        $this->assertTrue($browser->calls[0]['options']['full_page']);
        $this->assertSame('png', $browser->calls[0]['options']['format']);
        $this->assertSame(30, $browser->calls[0]['options']['timeout']);
        $this->assertArrayNotHasKey('quality', $browser->calls[0]['options']);

        // The bytes reached the registry, with the caller's owner and the
        // engine's type rather than the caller's hint.
        $this->assertCount(1, $store->calls);
        $this->assertSame('image/png', $store->calls[0]['mimeType']);
        $this->assertSame(7, $store->calls[0]['userId']);
        $this->assertSame('public', $store->calls[0]['visibility']);

        $this->assertSame(42, $result['data']['media'][0]['attachment_id']);
        $this->assertSame('https://example.test/files/screenshot.png', $result['data']['media'][0]['source_url']);
        $this->assertSame('desktop', $result['data']['viewport']);
        $this->assertSame(1920, $result['data']['width']);
    }

    public function test_a_custom_jpeg_capture_forwards_quality_and_dimensions(): void
    {
        $browser = $this->browser();

        $result = $this->tool($browser, $this->store())->execute(
            array(
                'url'      => 'https://example.com/wide',
                'viewport' => 'custom',
                'width'    => 1440,
                'height'   => 900,
                'format'   => 'jpeg',
                'quality'  => 55,
                'full_page' => false,
                'timeout'  => 45,
            )
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1440, $browser->calls[0]['options']['width']);
        $this->assertSame(900, $browser->calls[0]['options']['height']);
        $this->assertSame('jpeg', $browser->calls[0]['options']['format']);
        $this->assertSame(55, $browser->calls[0]['options']['quality']);
        $this->assertFalse($browser->calls[0]['options']['full_page']);
        $this->assertSame(45, $browser->calls[0]['options']['timeout']);
    }

    public function test_a_store_that_refuses_the_bytes_is_reported_not_hidden(): void
    {
        $result = $this->tool($this->browser(), $this->store(true))->execute(
            array('url' => 'https://example.com')
        );

        $this->assertSame('screenshot_not_stored', $result['error']['code']);
        $this->assertStringContainsString('disk is full', $result['error']['message']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────────

    private function tool(
        BrowserCaptureInterface $browser,
        ?FileVisibilityStoreInterface $store = null,
    ): CaptureWebpageScreenshotTool {
        return new CaptureWebpageScreenshotTool($this->errors, $browser, $store);
    }

    private function browser(bool $available = true, ?\Throwable $fails = null): BrowserCaptureInterface
    {
        return new class ($available, $fails) implements BrowserCaptureInterface {
            /** @var list<array{url: string, options: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?\Throwable $fails,
            ) {
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function capture(string $url, array $options = array()): array
            {
                $this->calls[] = array('url' => $url, 'options' => $options);

                if (null !== $this->fails) {
                    throw $this->fails;
                }

                return array(
                    'b64'        => 'cGl4ZWxz',
                    'mime_type'  => 'image/png',
                    'size_bytes' => 6,
                    'format'     => 'png',
                );
            }
        };
    }

    /**
     * @param bool $refuse Throw instead of storing, as a full disk would.
     */
    private function store(bool $refuse = false): FileVisibilityStoreInterface
    {
        return new class ($refuse) implements FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = array();

            public function __construct(private readonly bool $refuse)
            {
            }

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
                if ($this->refuse) {
                    throw new \RuntimeException('The disk is full.');
                }

                $this->calls[] = array(
                    'localPath'  => $localPath,
                    'filename'   => $filename,
                    'mimeType'   => $mimeType,
                    'userId'     => $userId,
                    'visibility' => $visibility,
                    'tenantId'   => $tenantId,
                );

                return new StoredFile(
                    id: 42,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: 6,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'private' === $visibility ? null : 'https://example.test/files/screenshot.png',
                    visibility: $visibility,
                    slug: 'screenshot',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array(
                    $visibility,
                    array(self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE),
                    true
                );
            }
        };
    }
}
