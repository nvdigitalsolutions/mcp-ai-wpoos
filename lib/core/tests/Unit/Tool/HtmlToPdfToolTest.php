<?php

/**
 * `html_to_pdf`.
 *
 * The tool's job is to hand a document to whatever renderer the host bound and
 * then hand the result to the file registry. Both halves have a failure mode
 * worth pinning:
 *
 *  - **The markup must arrive intact.** `AbstractTool::stringParam()` applies
 *    `strip_tags()`, so a tool that reads its HTML with the usual helper renders
 *    an empty page and reports success. That is a lie a unit test can catch, and
 *    the first test here does.
 *  - **A missing engine must be named, not degraded.** 164.4.8 says a tool whose
 *    engine is unbound is not servable; this one at least fails with
 *    `no_pdf_engine` rather than producing a blank PDF.
 *
 * The renderer is a recording double rather than a mock, because most assertions
 * are about the request the tool built — the page size, the orientation, the
 * bytes — and a mock would make those assertions about the mock instead.
 *
 * @package Nvoos\Core\Tests
 * @since   2.3.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\HtmlToPdfTool;
use PHPUnit\Framework\TestCase;

final class HtmlToPdfToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    /** @var list<string> */
    private array $temporary = array();

    protected function setUp(): void
    {
        $this->errors = $this->createMock(ErrorFactoryInterface::class);

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
    }

    protected function tearDown(): void
    {
        foreach ($this->temporary as $path) {
            if (\is_file($path)) {
                \unlink($path);
            }
        }
    }

    // ─── Identity ──────────────────────────────────────────────────────

    public function test_it_declares_its_slug_capability_and_flags(): void
    {
        $tool = $this->tool($this->renderer());

        $this->assertSame('html_to_pdf', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
        $this->assertContains('state-changing', $tool->getCapabilityFlags());

        // The WordPress tool declares `local-only` because both of its engines
        // run in-process. The worker tier here sends the HTML off the host, so
        // the flag would be false here and is deliberately absent.
        $this->assertNotContains('local-only', $tool->getCapabilityFlags());
    }

    // ─── Refusals ──────────────────────────────────────────────────────

    public function test_empty_html_is_a_validation_error(): void
    {
        $renderer = $this->renderer();
        $result   = $this->tool($renderer)->execute(array( 'html' => "  \n\t " ));

        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls, 'Nothing should reach the renderer.');
    }

    public function test_missing_html_is_a_validation_error(): void
    {
        $result = $this->tool($this->renderer())->execute(array());

        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_host_with_no_renderer_gets_a_named_capability_error(): void
    {
        $renderer = $this->renderer(available: false);
        $result   = $this->tool($renderer)->execute(array( 'html' => '<p>hi</p>' ));

        $this->assertSame('no_pdf_engine', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls);
    }

    public function test_a_throwing_renderer_becomes_pdf_failed(): void
    {
        $renderer = new class implements HtmlToPdfInterface {
            public function render(string $html, array $options = array()): array
            {
                throw new \RuntimeException('The media worker returned no PDF payload.');
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getSupportedPageFormats(): array
            {
                return array( 'A4' );
            }
        };

        $result = $this->tool($renderer)->execute(array( 'html' => '<p>hi</p>' ));

        $this->assertSame('pdf_failed', $result['error']['code']);
        $this->assertSame('The media worker returned no PDF payload.', $result['error']['message']);
    }

    // ─── The request the renderer receives ─────────────────────────────

    public function test_the_markup_reaches_the_renderer_intact(): void
    {
        $html = '<!doctype html><html><head><style>h1{color:red}</style></head>'
            . '<body><h1>Invoice</h1><p>Total: <strong>€10</strong></p></body></html>';

        $renderer = $this->renderer();
        $this->tool($renderer)->execute(array( 'html' => $html ));

        $this->assertCount(1, $renderer->calls);
        $this->assertSame($html, $renderer->calls[0]['html']);
    }

    public function test_it_renders_a4_portrait_by_default(): void
    {
        $renderer = $this->renderer(formats: array( 'A4', 'Letter', 'Legal' ));
        $this->tool($renderer)->execute(array( 'html' => '<p>hi</p>' ));

        $this->assertSame(
            array( 'format' => 'A4', 'landscape' => false, 'print_background' => true ),
            $renderer->calls[0]['options']
        );
    }

    public function test_a_landscape_orientation_is_passed_through(): void
    {
        $renderer = $this->renderer();
        $this->tool($renderer)->execute(array( 'html' => '<p>hi</p>', 'orientation' => 'landscape' ));

        $this->assertTrue($renderer->calls[0]['options']['landscape']);
    }

    public function test_the_wordpress_lowercase_page_size_resolves(): void
    {
        $renderer = $this->renderer(formats: array( 'A4', 'Letter', 'Legal' ));
        $this->tool($renderer)->execute(array( 'html' => '<p>hi</p>', 'page_size' => 'legal' ));

        $this->assertSame('Legal', $renderer->calls[0]['options']['format']);
    }

    public function test_an_unknown_page_size_falls_back_to_the_first_supported(): void
    {
        $renderer = $this->renderer(formats: array( 'Letter', 'A4' ));
        $this->tool($renderer)->execute(array( 'html' => '<p>hi</p>', 'page_size' => 'Tabloid' ));

        $this->assertSame('Letter', $renderer->calls[0]['options']['format']);
    }

    public function test_a_renderer_with_no_formats_still_renders(): void
    {
        $renderer = $this->renderer(formats: array());
        $tool     = $this->tool($renderer);

        $result = $tool->execute(array( 'html' => '<p>hi</p>' ));

        $this->assertTrue($result['success']);
        $this->assertSame('A4', $renderer->calls[0]['options']['format']);
        $this->assertSame(array( 'A4' ), $tool->getParametersSchema()['properties']['page_size']['enum']);
    }

    // ─── The payload ───────────────────────────────────────────────────

    public function test_the_payload_describes_what_was_rendered(): void
    {
        $result = $this->tool($this->renderer())->execute(array( 'html' => '<p>hi</p>' ));

        $this->assertTrue($result['success']);
        $this->assertSame('pdf', $result['data']['format']);
        $this->assertSame('A4', $result['data']['page_size']);
        $this->assertSame('portrait', $result['data']['orientation']);
        $this->assertSame('application/pdf', $result['data']['mime_type']);
        $this->assertSame(1024, $result['data']['bytes']);
    }

    public function test_the_schema_enum_lists_what_the_renderer_accepts(): void
    {
        $schema = $this->tool($this->renderer(formats: array( 'A5', 'A4' )))->getParametersSchema();

        $this->assertSame(array( 'A5', 'A4' ), $schema['properties']['page_size']['enum']);
        $this->assertSame('A5', $schema['properties']['page_size']['default']);
        $this->assertSame(array( 'html' ), $schema['required']);
    }

    // ─── File registry ─────────────────────────────────────────────────

    public function test_the_rendered_file_is_stored_in_the_registry(): void
    {
        $store = $this->recordingStore();
        $tool  = $this->tool($this->renderer(), $store);

        $result = $tool->execute(
            array( 'html' => '<p>hi</p>', 'title' => 'Quarterly Report' ),
            array( 'user_id' => 7, 'tenant_id' => 'acme' )
        );

        $this->assertTrue($result['success']);
        $this->assertCount(1, $store->calls);

        $call = $store->calls[0];
        $this->assertSame('quarterly-report.pdf', $call['filename']);
        $this->assertSame('application/pdf', $call['mimeType']);
        $this->assertSame(7, $call['userId']);
        $this->assertSame('public', $call['visibility']);
        $this->assertSame('acme', $call['tenantId']);
        $this->assertSame(42, $result['data']['media'][0]['attachment_id']);
    }

    public function test_private_visibility_is_honoured_when_storing(): void
    {
        $store = $this->recordingStore();
        $tool  = $this->tool($this->renderer(), $store);

        $tool->execute(array( 'html' => '<p>hi</p>', 'visibility' => 'private' ));

        $this->assertSame('private', $store->calls[0]['visibility']);
    }

    public function test_a_filename_wins_over_the_title_and_keeps_one_suffix(): void
    {
        $store = $this->recordingStore();
        $tool  = $this->tool($this->renderer(), $store);

        $tool->execute(array( 'html' => '<p>hi</p>', 'title' => 'Ignored', 'filename' => 'invoice.pdf' ));

        $this->assertSame('invoice.pdf', $store->calls[0]['filename']);
    }

    public function test_without_a_registry_the_tool_does_not_claim_a_stored_file(): void
    {
        $result = $this->tool($this->renderer())->execute(array( 'html' => '<p>hi</p>' ));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('media', $result['data']);
    }

    // ─── Doubles ───────────────────────────────────────────────────────

    /**
     * A renderer that records the request and writes a real file.
     *
     * The file is real because the registry bridge checks `is_file()` before it
     * will store anything; faking that would test the mock.
     *
     * @param list<string> $formats
     */
    private function renderer(array $formats = array( 'A4', 'Letter', 'Legal' ), bool $available = true): HtmlToPdfInterface
    {
        $path = \tempnam(\sys_get_temp_dir(), 'nvoos-html-to-pdf-');
        $this->assertNotFalse($path);
        $this->temporary[] = $path;

        return new class ($formats, $available, $path) implements HtmlToPdfInterface {
            /** @var list<array{html: string, options: array<string, mixed>}> */
            public array $calls = array();

            /**
             * @param list<string> $formats
             */
            public function __construct(
                private readonly array $formats,
                private readonly bool $available,
                private readonly string $path,
            ) {
            }

            public function render(string $html, array $options = array()): array
            {
                $this->calls[] = array( 'html' => $html, 'options' => $options );
                \file_put_contents($this->path, '%PDF-1.4 test');

                return array(
                    'path'      => $this->path,
                    'mime_type' => 'application/pdf',
                    'bytes'     => 1024,
                    'format'    => 'a4 portrait',
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getSupportedPageFormats(): array
            {
                return $this->formats;
            }
        };
    }

    /**
     * A file store that records the arguments of each store call.
     */
    private function recordingStore(): FileVisibilityStoreInterface
    {
        return new class implements FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $calls = array();

            public function storeWithVisibility(
                string $localPath,
                string $filename,
                string $mimeType,
                int $userId,
                string $visibility,
                ?string $tenantId = null,
            ): StoredFile {
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
                    sizeBytes: 1024,
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'private' === $visibility ? null : 'https://example.test/files/' . $filename,
                    visibility: $visibility,
                    slug: 'stored-slug',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array(
                    $visibility,
                    array( self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE ),
                    true
                );
            }
        };
    }

    private function tool(
        HtmlToPdfInterface $pdf,
        ?FileVisibilityStoreInterface $store = null
    ): HtmlToPdfTool {
        return new HtmlToPdfTool($this->errors, $pdf, $store);
    }
}
