<?php

/**
 * `pro_batch_ocr`.
 *
 * The engine is not in question: `ImportAndOcrToolsTest` already covers
 * `recognize()` over `PdfOcrInterface`. What is left here is the loop around it —
 * that one unreadable file does not cost the batch, that
 * `continue_on_error: false` really stops, that a vendor name this host does not
 * run is refused instead of substituted, and that the options reach every file
 * rather than only the first.
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
use Nvoos\Core\Tool\ProBatchOcrTool;
use PHPUnit\Framework\TestCase;

final class ProBatchOcrToolTest extends TestCase
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

    public function test_it_declares_its_slug_and_flags(): void
    {
        $tool = $this->tool();

        $this->assertSame('pro_batch_ocr', $tool->getSlug());
        $this->assertSame('read', $tool->getRequiredCapability());
        $this->assertContains('read-only', $tool->getCapabilityFlags());
        $this->assertNotContains('requires-model', $tool->getCapabilityFlags());
    }

    // ─── The batch ─────────────────────────────────────────────────────────

    public function test_it_ocrs_every_file_and_sums_the_pages(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf', 2 => '/srv/b.pdf'));

        $result = $this->tool($this->ocr(), $files)->execute(
            array('file_ids' => array(1, 2))
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame(0, $result['data']['failed']);
        $this->assertSame(2, $result['data']['requested']);
        $this->assertSame(6, $result['data']['total_pages']);
        $this->assertFalse($result['data']['stopped_early']);

        $this->assertSame(1, $result['data']['results'][0]['file_id']);
        $this->assertTrue($result['data']['results'][0]['ok']);
        $this->assertSame('Text of /srv/a.pdf', $result['data']['results'][0]['text']);
        $this->assertSame(1, $result['data']['results'][0]['pages']);
        $this->assertSame(3, $result['data']['results'][0]['total_pages']);
        $this->assertTrue($result['data']['results'][0]['truncated']);
    }

    /**
     * The options are per-call, so a batch that applied them only to its first
     * file would OCR the rest at the wrong resolution or in the wrong language —
     * silently, because the text would still look plausible.
     */
    public function test_it_forwards_the_options_to_every_file(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf', 2 => '/srv/b.pdf'));
        $ocr = $this->ocr();

        $this->tool($ocr, $files)->execute(array(
            'file_ids' => array(1, 2),
            'dpi' => 400,
            'max_pages' => 5,
            'language' => 'deu',
        ));

        $this->assertCount(2, $ocr->calls);

        foreach ($ocr->calls as $call) {
            $this->assertSame(400, $call[1]['dpi']);
            $this->assertSame(5, $call[1]['max_pages']);
            $this->assertSame('deu', $call[1]['language']);
        }
    }

    public function test_it_uses_the_published_defaults_when_none_are_given(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf'));
        $ocr = $this->ocr();

        $this->tool($ocr, $files)->execute(array('file_ids' => array(1)));

        $this->assertSame(ProBatchOcrTool::DEFAULT_DPI, $ocr->calls[0][1]['dpi']);
        $this->assertSame(ProBatchOcrTool::DEFAULT_MAX_PAGES, $ocr->calls[0][1]['max_pages']);
        $this->assertSame('eng', $ocr->calls[0][1]['language']);
    }

    /**
     * Twenty documents that abort on the third have spent nineteen OCR runs and
     * returned nothing. The default is therefore to carry on.
     */
    public function test_a_failing_file_is_reported_and_the_batch_carries_on(): void
    {
        $files = $this->registry(array(
            1 => '/srv/a.pdf',
            2 => '/srv/broken.pdf',
            3 => '/srv/c.pdf',
        ));

        $result = $this->tool($this->ocr(true, array('/srv/broken.pdf')), $files)->execute(
            array('file_ids' => array(1, 2, 3))
        );

        $this->assertTrue($result['success']);
        $this->assertSame(2, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame(3, $result['data']['requested']);
        $this->assertFalse($result['data']['stopped_early']);

        $this->assertTrue($result['data']['results'][0]['ok']);
        $this->assertFalse($result['data']['results'][1]['ok']);
        $this->assertStringContainsString('/srv/broken.pdf', $result['data']['results'][1]['error']);
        $this->assertTrue($result['data']['results'][2]['ok']);
    }

    public function test_continue_on_error_false_stops_at_the_first_failure(): void
    {
        $files = $this->registry(array(
            1 => '/srv/a.pdf',
            2 => '/srv/broken.pdf',
            3 => '/srv/c.pdf',
        ));
        $ocr = $this->ocr(true, array('/srv/broken.pdf'));

        $result = $this->tool($ocr, $files)->execute(array(
            'file_ids' => array(1, 2, 3),
            'continue_on_error' => false,
        ));

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertSame(3, $result['data']['requested']);
        $this->assertTrue($result['data']['stopped_early']);

        // The third file was never attempted.
        $this->assertCount(2, $ocr->calls);
        $this->assertCount(2, $result['data']['results']);
    }

    public function test_an_unknown_file_id_is_a_per_file_error(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf'));

        $result = $this->tool($this->ocr(), $files)->execute(
            array('file_ids' => array(1, 99))
        );

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['data']['processed']);
        $this->assertSame(1, $result['data']['failed']);
        $this->assertFalse($result['data']['results'][1]['ok']);
        $this->assertStringContainsString('99', $result['data']['results'][1]['error']);
    }

    // ─── Refusals ──────────────────────────────────────────────────────────

    /**
     * Running tesseract under a vendor's name would let a caller believe it got a
     * service it did not, which is worse than not running the batch at all.
     */
    public function test_unlimited_ocr_is_refused_by_name(): void
    {
        $result = $this->tool()->execute(array(
            'file_ids' => array(1),
            'provider' => 'unlimited_ocr',
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('ocr_provider_unavailable', $result['error']['code']);
        $this->assertStringContainsString('unlimited_ocr', $result['error']['message']);
    }

    public function test_deepseek_ocr_is_refused_by_name(): void
    {
        $result = $this->tool()->execute(array(
            'file_ids' => array(1),
            'provider' => 'deepseek_ocr',
        ));

        $this->assertFalse($result['success']);
        $this->assertSame('ocr_provider_unavailable', $result['error']['code']);
        $this->assertStringContainsString('deepseek_ocr', $result['error']['message']);
    }

    public function test_a_refused_provider_never_reaches_the_engine(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf'));
        $ocr = $this->ocr();

        $this->tool($ocr, $files)->execute(array(
            'file_ids' => array(1),
            'provider' => 'deepseek_ocr',
        ));

        $this->assertSame(array(), $ocr->calls);
    }

    public function test_it_refuses_when_no_file_store_is_bound(): void
    {
        $result = $this->tool($this->ocr(), null)->execute(array('file_ids' => array(1)));

        $this->assertFalse($result['success']);
        $this->assertSame('no_file_store', $result['error']['code']);
    }

    public function test_it_refuses_when_no_ocr_engine_is_bound(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf'));

        $result = $this->tool($this->ocr(false), $files)->execute(array('file_ids' => array(1)));

        $this->assertFalse($result['success']);
        $this->assertSame('no_ocr_engine', $result['error']['code']);
    }

    // ─── Limits ────────────────────────────────────────────────────────────

    public function test_it_refuses_a_batch_over_the_cap(): void
    {
        $ids = range(1, ProBatchOcrTool::MAX_FILES + 1);

        $result = $this->tool()->execute(array('file_ids' => $ids));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    public function test_it_refuses_an_empty_batch(): void
    {
        $result = $this->tool()->execute(array('file_ids' => array()));

        $this->assertFalse($result['success']);
        $this->assertSame('validation_failed', $result['error']['code']);
    }

    /**
     * The same file twice is one OCR run, not two.
     */
    public function test_duplicate_ids_are_ocred_once(): void
    {
        $files = $this->registry(array(1 => '/srv/a.pdf'));
        $ocr = $this->ocr();

        $result = $this->tool($ocr, $files)->execute(array('file_ids' => array(1, 1)));

        $this->assertSame(1, $result['data']['requested']);
        $this->assertCount(1, $ocr->calls);
    }

    // ─── Helpers ───────────────────────────────────────────────────────────

    private function tool(
        ?PdfOcrInterface $ocr = null,
        ?FileStoreInterface $files = null
    ): ProBatchOcrTool {
        return new ProBatchOcrTool($this->errors, $ocr ?? $this->ocr(), $files);
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
     * A recording OCR engine.
     *
     * @param list<string> $failures Paths that should throw.
     */
    private function ocr(bool $available = true, array $failures = array()): PdfOcrInterface
    {
        return new class ($available, $failures) implements PdfOcrInterface {
            /** @var list<array{0: string, 1: array<string, mixed>}> */
            public array $calls = array();

            /**
             * @param list<string> $failures
             */
            public function __construct(
                private readonly bool $available,
                private readonly array $failures,
            ) {
            }

            public function recognize(string $localPath, array $options = array()): array
            {
                $this->calls[] = array($localPath, $options);

                if (in_array($localPath, $this->failures, true)) {
                    throw new \RuntimeException('The engine could not read ' . $localPath . '.');
                }

                return array(
                    'text' => 'Text of ' . $localPath,
                    'pages' => array(array('page' => 1, 'text' => 'Text of ' . $localPath, 'confidence' => 90.0)),
                    'count' => 1,
                    'total_pages' => 3,
                    'truncated' => true,
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
