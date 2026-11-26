<?php

/**
 * `generate_invoice_pdf`.
 *
 * The arithmetic is pinned in `InvoiceDocumentTest`; what is left here is the
 * tool's own contract — that a valid invoice reaches the renderer with the
 * figures printed, that a refusal from the builder becomes a validation failure
 * rather than a 500, that a host with no engine refuses by name, and that the
 * registry row is written only when asked.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\GenerateInvoicePdfTool;
use PHPUnit\Framework\TestCase;

final class GenerateInvoicePdfToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    /** @var list<object> Renderers created, so their temp files can be removed. */
    private array $renderers = array();

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

    protected function tearDown(): void
    {
        foreach ($this->renderers as $renderer) {
            foreach ($renderer->paths as $path) {
                if (is_file($path)) {
                    unlink($path);
                }
            }
        }

        $this->renderers = array();
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('generate_invoice_pdf', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
        $this->assertNotContains('requires-model', $tool->getCapabilityFlags());
        $this->assertNotContains('consumes-tokens', $tool->getCapabilityFlags());
    }

    // ─── Rendering ─────────────────────────────────────────────────────────

    public function test_it_renders_an_invoice_and_reports_the_totals(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(array(
            'invoice_number' => 'INV-7',
            'items' => array(
                array('description' => 'Consulting', 'quantity' => 2, 'rate' => 150),
            ),
            'tax_rate' => 10,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame('INV-7', $result['data']['invoice']['number']);
        $this->assertSame(300.0, $result['data']['invoice']['subtotal']);
        $this->assertSame(330.0, $result['data']['invoice']['total']);
        $this->assertSame('application/pdf', $result['data']['mime_type']);
    }

    public function test_the_rendered_html_carries_the_number_and_a_table(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(array(
            'invoice_number' => 'INV-9',
            'items' => array(array('description' => 'Design work', 'amount' => 42)),
            'bill_to' => array('name' => 'Acme Ltd'),
        ));

        $html = $renderer->calls[0][0];

        $this->assertStringContainsString('INV-9', $html);
        $this->assertStringContainsString('Design work', $html);
        $this->assertStringContainsString('Acme Ltd', $html);
        $this->assertStringContainsString('<table>', $html);
    }

    public function test_the_page_size_and_orientation_reach_the_renderer(): void
    {
        $renderer = $this->renderer();

        $this->tool($renderer)->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('description' => 'Work', 'amount' => 1)),
            'page_size' => 'Letter',
            'orientation' => 'landscape',
        ));

        $this->assertSame('Letter', $renderer->calls[0][1]['format']);
        $this->assertTrue($renderer->calls[0][1]['landscape']);
    }

    public function test_the_title_defaults_to_the_invoice_number(): void
    {
        $result = $this->tool()->execute(array(
            'invoice_number' => 'INV-42',
            'items' => array(array('description' => 'Work', 'amount' => 1)),
        ));

        $this->assertSame('Invoice INV-42', $result['data']['title']);
    }

    // ─── Refusals ──────────────────────────────────────────────────────────

    public function test_a_host_with_no_pdf_engine_refuses_by_name(): void
    {
        $result = $this->tool($this->renderer(false))->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('description' => 'Work', 'amount' => 1)),
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('no_pdf_engine', $result['error']['code']);
    }

    public function test_invalid_invoice_data_is_a_validation_failure(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('quantity' => 1, 'rate' => 5)),
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls, 'Nothing should be rendered.');
    }

    public function test_a_disagreeing_total_is_a_validation_failure(): void
    {
        $result = $this->tool()->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('description' => 'Work', 'amount' => 100)),
            'total' => 99,
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertStringContainsString('total', $result['error']['message']);
    }

    public function test_a_render_failure_is_reported_as_an_error(): void
    {
        $result = $this->tool($this->renderer(true, 'the renderer died'))->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('description' => 'Work', 'amount' => 1)),
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('pdf_failed', $result['error']['code']);
    }

    // ─── Persistence ───────────────────────────────────────────────────────

    public function test_save_to_media_writes_the_pdf_through_the_store(): void
    {
        $store = $this->createMock(FileVisibilityStoreInterface::class);
        $store->expects($this->once())
            ->method('storeWithVisibility')
            ->with(
                $this->anything(),
                'invoice-inv-1',
                'application/pdf',
                0,
                FileVisibilityStoreInterface::VISIBILITY_PUBLIC,
                null,
            )
            ->willReturn($this->storedFile());

        $result = $this->tool($this->renderer(), $store)->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('description' => 'Work', 'amount' => 1)),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['media'][0]['attachment_id']);
    }

    public function test_save_to_media_false_skips_the_store(): void
    {
        $store = $this->createMock(FileVisibilityStoreInterface::class);
        $store->expects($this->never())->method('storeWithVisibility');

        $result = $this->tool($this->renderer(), $store)->execute(array(
            'invoice_number' => 'INV-1',
            'items' => array(array('description' => 'Work', 'amount' => 1)),
            'save_to_media' => false,
        ));

        $this->assertTrue($result['success']);
        $this->assertArrayNotHasKey('media', $result['data']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function tool(
        ?HtmlToPdfInterface $renderer = null,
        ?FileVisibilityStoreInterface $store = null
    ): GenerateInvoicePdfTool {
        return new GenerateInvoicePdfTool($this->errors, $renderer ?? $this->renderer(), $store);
    }

    private function storedFile(): StoredFile
    {
        return new StoredFile(
            1,
            'invoice-inv-1.pdf',
            'application/pdf',
            8,
            '/tmp/invoice-inv-1.pdf',
            0,
            new \DateTimeImmutable('2026-11-25'),
            'https://example.test/invoice-inv-1.pdf',
            array(),
            'public',
            'invoice-inv-1',
        );
    }

    /**
     * A renderer that writes a tiny real file, so the persistence path has
     * something to hand the registry.
     */
    private function renderer(bool $available = true, ?string $failure = null): HtmlToPdfInterface
    {
        $renderer = new class ($available, $failure) implements HtmlToPdfInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            /** @var list<string> */
            public array $paths = array();

            public function __construct(
                private readonly bool $available,
                private readonly ?string $failure,
            ) {
            }

            public function render(string $html, array $options = array()): array
            {
                $this->calls[] = array($html, $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                $path = (string) tempnam(sys_get_temp_dir(), 'invoice-');
                file_put_contents($path, '%PDF-1.4');

                $this->paths[] = $path;

                return array(
                    'path' => $path,
                    'mime_type' => 'application/pdf',
                    'bytes' => 8,
                    'format' => 'pdf',
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }

            public function getSupportedPageFormats(): array
            {
                return array('A4', 'Letter');
            }
        };

        $this->renderers[] = $renderer;

        return $renderer;
    }
}
