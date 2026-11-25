<?php

/**
 * `generate_invoice_batch`.
 *
 * The re-scope is the point: invoices are given, not looked up. What this file
 * pins is the batch contract around that — one bad invoice does not lose the
 * good ones, `continue_on_error: false` really stops, and the cap is enforced
 * before anything renders.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\HtmlToPdfInterface;
use Nvoos\Core\Tool\GenerateInvoiceBatchTool;
use PHPUnit\Framework\TestCase;

final class GenerateInvoiceBatchToolTest extends TestCase
{
    private ErrorFactoryInterface $errors;

    /** @var list<object> */
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

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('generate_invoice_batch', $tool->getSlug());
        $this->assertSame('upload_files', $tool->getRequiredCapability());
        $this->assertContains('write', $tool->getCapabilityFlags());
    }

    /**
     * The WordPress tool required `order_ids`. This one must not: there is no
     * order store on this host, so the schema offers the invoices themselves.
     */
    public function test_the_schema_takes_invoices_and_not_order_ids(): void
    {
        $properties = $this->tool()->getParametersSchema()['properties'];

        $this->assertArrayHasKey('invoices', $properties);
        $this->assertArrayNotHasKey('order_ids', $properties);
        $this->assertArrayNotHasKey('template_id', $properties);
        $this->assertArrayNotHasKey('send_email', $properties);
    }

    // ─── The batch ─────────────────────────────────────────────────────────

    public function test_it_renders_every_invoice(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(array(
            'invoices' => array(
                $this->invoice('INV-1', 10),
                $this->invoice('INV-2', 20),
            ),
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame(0, $result['data']['failed']);
        $this->assertSame(2, $result['data']['requested']);
        $this->assertFalse($result['data']['stopped_early']);
        $this->assertCount(2, $renderer->calls);
        $this->assertSame('INV-1', $result['data']['results'][0]['invoice']['number']);
        $this->assertSame(10.0, $result['data']['results'][0]['invoice']['total']);
        $this->assertSame('INV-2', $result['data']['results'][1]['invoice']['number']);
    }

    public function test_a_failing_invoice_is_reported_and_the_batch_carries_on(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(array(
            'invoices' => array(
                $this->invoice('INV-1', 10),
                array('invoice_number' => 'INV-BAD', 'items' => array(array('quantity' => 1))),
                $this->invoice('INV-3', 30),
            ),
        ));

        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertTrue($result['data']['results'][0]['ok']);
        $this->assertFalse($result['data']['results'][1]['ok']);
        $this->assertSame('INV-BAD', $result['data']['results'][1]['invoice_number']);
        $this->assertTrue($result['data']['results'][2]['ok']);
        $this->assertCount(2, $renderer->calls);
    }

    public function test_continue_on_error_false_stops_before_rendering_the_rest(): void
    {
        $renderer = $this->renderer();

        $result = $this->tool($renderer)->execute(array(
            'invoices' => array(
                array('invoice_number' => 'INV-BAD', 'items' => array()),
                $this->invoice('INV-2', 20),
                $this->invoice('INV-3', 30),
            ),
            'continue_on_error' => false,
        ));

        $this->assertSame(0, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertTrue($result['data']['stopped_early']);
        $this->assertCount(1, $result['data']['results']);
        $this->assertSame(array(), $renderer->calls);
    }

    public function test_an_entry_that_is_not_an_object_is_a_per_item_error(): void
    {
        $result = $this->tool()->execute(array(
            'invoices' => array('not-an-object'),
        ));

        $this->assertSame(0, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertFalse($result['data']['results'][0]['ok']);
    }

    public function test_each_invoice_names_its_own_document(): void
    {
        $result = $this->tool()->execute(array(
            'invoices' => array($this->invoice('INV-5', 5)),
        ));

        $this->assertSame('Invoice INV-5', $result['data']['results'][0]['title']);
    }

    // ─── Limits and gates ──────────────────────────────────────────────────

    public function test_an_empty_batch_is_refused(): void
    {
        $result = $this->tool()->execute(array('invoices' => array()));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_a_batch_over_the_cap_is_refused_before_anything_renders(): void
    {
        $renderer = $this->renderer();
        $invoices = array();

        for ($i = 0; $i <= GenerateInvoiceBatchTool::MAX_INVOICES; $i++) {
            $invoices[] = $this->invoice('INV-' . $i, 1);
        }

        $result = $this->tool($renderer)->execute(array('invoices' => $invoices));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
        $this->assertSame(array(), $renderer->calls);
    }

    public function test_a_host_with_no_pdf_engine_refuses_by_name(): void
    {
        $result = $this->tool($this->renderer(false))->execute(array(
            'invoices' => array($this->invoice('INV-1', 10)),
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('no_pdf_engine', $result['error']['code']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function tool(?HtmlToPdfInterface $renderer = null): GenerateInvoiceBatchTool
    {
        return new GenerateInvoiceBatchTool($this->errors, $renderer ?? $this->renderer());
    }

    /**
     * @return array<string, mixed>
     */
    private function invoice(string $number, float $amount): array
    {
        return array(
            'invoice_number' => $number,
            'items' => array(array('description' => 'Work', 'amount' => $amount)),
        );
    }

    private function renderer(bool $available = true): HtmlToPdfInterface
    {
        $renderer = new class ($available) implements HtmlToPdfInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            /** @var list<string> */
            public array $paths = array();

            public function __construct(private readonly bool $available)
            {
            }

            public function render(string $html, array $options = array()): array
            {
                $this->calls[] = array($html, $options);

                $path = (string) tempnam(sys_get_temp_dir(), 'invoice-batch-');
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
