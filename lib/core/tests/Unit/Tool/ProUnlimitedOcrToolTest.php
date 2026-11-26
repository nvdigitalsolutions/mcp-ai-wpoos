<?php

/**
 * `pro_unlimited_ocr`.
 *
 * The interesting behaviour is the window protocol, not the engine: where the
 * next call starts, and what happens when the document ends, is asked for past
 * its end, or is smaller than one budget. The engine is a fake that answers from
 * a page count, so those cases are cheap to state.
 *
 * @package Nvoos\Core\Tests
 * @since   2.5.0
 * @license MIT
 */

declare(strict_types=1);

namespace Nvoos\Core\Tests\Unit\Tool;

use Nvoos\Core\Domain\Contract\ErrorFactoryInterface;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
use Nvoos\Core\Tool\ProUnlimitedOcrTool;
use PHPUnit\Framework\TestCase;

final class ProUnlimitedOcrToolTest extends TestCase
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

        $this->errors->method('notFound')->willReturnCallback(
            static fn (string $message = 'Resource not found.', array $data = array()): array => array(
                'success' => false,
                'error' => array('code' => 'not_found', 'message' => $message),
            )
        );
    }

    // ─── Identity ──────────────────────────────────────────────────────────

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('pro_unlimited_ocr', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
    }

    /**
     * The WordPress tool's `model_type`, `image_mode`, `output_format`,
     * `preserve_layout` and `extract_tables` describe vLLM output this host
     * cannot produce, so the schema must not carry them.
     */
    public function test_it_offers_no_provider_or_model_knobs(): void
    {
        $properties = $this->tool()->getParametersSchema()['properties'];

        foreach (array('model_type', 'image_mode', 'output_format', 'preserve_layout', 'extract_tables', 'provider', 'save_to_paper_store') as $absent) {
            $this->assertArrayNotHasKey($absent, $properties);
        }
    }

    // ─── The window protocol ───────────────────────────────────────────────

    public function test_it_reads_the_first_window_and_reports_where_to_continue(): void
    {
        $ocr = $this->ocr(120);
        $result = $this->tool($ocr, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['start_page']);
        $this->assertSame(50, $result['data']['pages_read']);
        $this->assertSame(120, $result['data']['total_pages']);
        $this->assertSame(51, $result['data']['next_page']);
        $this->assertFalse($result['data']['complete']);

        // From page one the budget alone says the same thing, so no page list is
        // built or sent.
        $this->assertArrayNotHasKey('pages', $ocr->calls[0][1]);
    }

    public function test_resuming_passes_an_explicit_window(): void
    {
        $ocr = $this->ocr(120);
        $result = $this->tool($ocr, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7, 'start_page' => 51));

        $this->assertSame(51, $result['data']['start_page']);
        $this->assertSame(50, $result['data']['pages_read']);
        $this->assertSame(101, $result['data']['next_page']);
        $this->assertSame(range(51, 100), $ocr->calls[0][1]['pages']);
    }

    public function test_the_last_window_completes_the_document(): void
    {
        $ocr = $this->ocr(120);
        $result = $this->tool($ocr, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7, 'start_page' => 101));

        $this->assertSame(20, $result['data']['pages_read']);
        $this->assertNull($result['data']['next_page']);
        $this->assertTrue($result['data']['complete']);
        $this->assertStringContainsString('complete', $result['message']);
    }

    public function test_a_document_that_fits_one_budget_is_complete_in_one_call(): void
    {
        $ocr = $this->ocr(30);
        $result = $this->tool($ocr, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertSame(30, $result['data']['pages_read']);
        $this->assertNull($result['data']['next_page']);
    }

    /**
     * Asking past the end is a caller mistake, not a crash: the answer says how
     * long the document actually is.
     */
    public function test_a_start_page_past_the_end_reads_nothing_and_says_so(): void
    {
        $result = $this->tool($this->ocr(30), $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7, 'start_page' => 100));

        $this->assertTrue($result['success']);
        $this->assertSame(0, $result['data']['pages_read']);
        $this->assertNull($result['data']['next_page']);
        $this->assertTrue($result['data']['complete']);
        $this->assertStringContainsString('past its end', $result['message']);
    }

    public function test_the_per_page_array_is_only_returned_on_request(): void
    {
        $files = $this->registry(array(7 => '/srv/scan.pdf'));

        $quiet = $this->tool($this->ocr(120), $files)->execute(array('file_id' => 7, 'max_pages' => 2));
        $this->assertArrayNotHasKey('pages', $quiet['data']);

        $loud = $this->tool($this->ocr(120), $files)->execute(
            array('file_id' => 7, 'max_pages' => 2, 'include_pages' => true)
        );
        $this->assertCount(2, $loud['data']['pages']);
        $this->assertSame(1, $loud['data']['pages'][0]['page']);
    }

    /**
     * The route clamps a budget to 50, so a larger request must not be passed on
     * as though it meant something.
     */
    public function test_a_budget_over_the_route_ceiling_is_clamped(): void
    {
        $ocr = $this->ocr(500);
        $this->tool($ocr, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7, 'max_pages' => 500));

        $this->assertSame(50, $ocr->calls[0][1]['max_pages']);
    }

    public function test_the_options_reach_the_engine(): void
    {
        $ocr = $this->ocr(120);
        $this->tool($ocr, $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7, 'dpi' => 400, 'language' => 'deu'));

        $this->assertSame(400, $ocr->calls[0][1]['dpi']);
        $this->assertSame('deu', $ocr->calls[0][1]['language']);
    }

    // ─── Gates ─────────────────────────────────────────────────────────────

    public function test_it_requires_a_file_id(): void
    {
        $result = $this->tool()->execute(array());

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_it_refuses_when_no_file_store_is_bound(): void
    {
        $result = $this->tool($this->ocr(10), null)->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('no_file_store', $result['error']['code']);
    }

    public function test_an_unknown_file_is_not_found(): void
    {
        $result = $this->tool($this->ocr(10), $this->registry(array()))->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('not_found', $result['error']['code']);
    }

    public function test_it_refuses_when_no_ocr_engine_is_bound(): void
    {
        $result = $this->tool($this->ocr(10, false), $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('no_ocr_engine', $result['error']['code']);
    }

    public function test_an_engine_failure_is_reported_as_an_error(): void
    {
        $result = $this->tool($this->ocr(10, true, 'the engine died'), $this->registry(array(7 => '/srv/scan.pdf')))
            ->execute(array('file_id' => 7));

        $this->assertFalse($result['success']);
        $this->assertSame('pdf_ocr_failed', $result['error']['code']);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function tool(
        ?PdfOcrInterface $ocr = null,
        ?FileStoreInterface $files = null
    ): ProUnlimitedOcrTool {
        return new ProUnlimitedOcrTool($this->errors, $ocr ?? $this->ocr(), $files);
    }

    /**
     * @param array<int, string> $paths
     */
    private function registry(array $paths): FileStoreInterface
    {
        $files = $this->createMock(FileStoreInterface::class);
        $files->method('getPath')->willReturnCallback(
            static fn (int $id): ?string => $paths[$id] ?? null
        );

        return $files;
    }

    /**
     * An OCR engine that answers from a page count, the way the route does.
     */
    private function ocr(int $totalPages = 120, bool $available = true, ?string $failure = null): PdfOcrInterface
    {
        return new class ($totalPages, $available, $failure) implements PdfOcrInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            public function __construct(
                private readonly int $totalPages,
                private readonly bool $available,
                private readonly ?string $failure,
            ) {
            }

            public function recognize(string $localPath, array $options = array()): array
            {
                $this->calls[] = array($localPath, $options);

                if (null !== $this->failure) {
                    throw new \RuntimeException($this->failure);
                }

                $budget = max(1, (int) ($options['max_pages'] ?? 20));
                $requested = (array) ($options['pages'] ?? array());

                if (array() === $requested) {
                    $requested = range(1, max(1, $this->totalPages));
                }

                $requested = array_slice($requested, 0, $budget);

                $pages = array();

                foreach ($requested as $page) {
                    $page = (int) $page;

                    if ($page < 1 || $page > $this->totalPages) {
                        continue;
                    }

                    $pages[] = array('page' => $page, 'text' => 'Page ' . $page, 'confidence' => 90.0);
                }

                return array(
                    'text' => implode("\n\n", array_column($pages, 'text')),
                    'pages' => $pages,
                    'count' => count($pages),
                    'total_pages' => $this->totalPages,
                    'truncated' => count($pages) < $this->totalPages,
                    'dpi' => (int) ($options['dpi'] ?? 300),
                    'language' => (string) ($options['language'] ?? 'eng'),
                );
            }

            public function isAvailable(): bool
            {
                return $this->available;
            }
        };
    }
}
