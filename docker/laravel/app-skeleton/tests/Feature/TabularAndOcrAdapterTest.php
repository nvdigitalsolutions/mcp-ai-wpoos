<?php

namespace Tests\Feature;

use Illuminate\Http\Client\Request as ClientRequest;
use Illuminate\Support\Facades\Http;
use Nvoos\Core\Domain\Contract\FileStoreInterface;
use Nvoos\Core\Domain\Contract\FileVisibilityStoreInterface;
use Nvoos\Core\Domain\Contract\PdfMutatorInterface;
use Nvoos\Core\Domain\Contract\PdfOcrInterface;
use Nvoos\Core\Domain\Contract\TabularReaderInterface;
use Nvoos\Core\Domain\Entity\StoredFile;
use Nvoos\Core\Tool\AddWatermarkToPdfTool;
use Nvoos\Core\Tool\ExcelDataImportTool;
use Nvoos\Core\Tool\OcrPdfTextTool;
use Nvoos\Laravel\Adapter\MediaWorkerPdfOcr;
use Nvoos\Laravel\Adapter\MediaWorkerTabularReader;
use Tests\TestCase;

/**
 * Spreadsheet import, PDF OCR and watermark styling — Proposal 164 §164.4.3/§164.7.
 *
 * All three are newer than the worker routes they use, so every test here is
 * really about the same rule: **a capability the worker does not advertise is
 * refused by name, not discovered by failing.** The capability matrix in
 * `/api/health/full` is faked explicitly in each case, because a fake that
 * answers every URL the same way would make a missing capability look present.
 */
final class TabularAndOcrAdapterTest extends TestCase
{
    /** A minimal PDF header — the adapters never parse it. */
    private const PDF_B64 = 'JVBERi0xLjQKJcTl8uXrp/Og0MTGCg==';

    private string $workDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir().'/oos-import-'.bin2hex(random_bytes(5));

        mkdir($this->workDir, 0775, true);

        file_put_contents($this->workDir.'/book.xlsx', 'PK-fixture');
        file_put_contents($this->workDir.'/scan.pdf', '%PDF-1.4 scan');

        config([
            'oos.media_worker.url' => 'http://worker.test',
            'oos.media_worker.output_dir' => $this->workDir.'/out',
            'oos.media_worker.ingest_timeout' => 5,
            'oos.media_worker.ocr_timeout' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        foreach (glob($this->workDir.'/out/*') ?: [] as $file) {
            @unlink($file);
        }

        @unlink($this->workDir.'/book.xlsx');
        @unlink($this->workDir.'/scan.pdf');
        @rmdir($this->workDir.'/out');
        @rmdir($this->workDir);

        parent::tearDown();
    }

    // ─── Wiring ────────────────────────────────────────────────────────

    public function test_the_new_contracts_resolve_to_worker_adapters(): void
    {
        $this->assertInstanceOf(MediaWorkerTabularReader::class, $this->app->make(TabularReaderInterface::class));
        $this->assertInstanceOf(MediaWorkerPdfOcr::class, $this->app->make(PdfOcrInterface::class));
    }

    // ─── excel_data_import ─────────────────────────────────────────────

    public function test_the_reader_posts_the_workers_argument_spellings(): void
    {
        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            $captured = ['url' => $request->url(), 'parts' => $request->data()];

            return Http::response([
                'success' => true,
                'format' => 'xlsx',
                'columns' => ['Date', 'Item'],
                'rows' => [['2026-10-01', 'A'], ['2026-10-02', 'B']],
                'meta' => ['engine' => 'exceljs', 'sheet' => 'Daily Sales', 'source_total' => 2],
            ]);
        });

        $matrix = $this->app->make(TabularReaderInterface::class)->read(
            $this->workDir.'/book.xlsx',
            ['sheet' => 'Daily Sales', 'max_rows' => 5]
        );

        $this->assertSame('http://worker.test/api/ingest/parse', $captured['url']);
        $this->assertSame('xlsx', $this->part($captured, 'format'));
        $this->assertSame('Daily Sales', $this->part($captured, 'sheet'));
        $this->assertSame('5', $this->part($captured, 'maxRows'));

        $this->assertSame(['Date', 'Item'], $matrix['columns']);
        $this->assertCount(2, $matrix['rows']);
        $this->assertSame('exceljs', $matrix['meta']['engine']);
    }

    public function test_the_reader_bounds_the_rows_even_when_the_worker_ignores_the_cap(): void
    {
        // An older worker ignores `maxRows`; the adapter must still bound.
        Http::fake([
            'worker.test/*' => Http::response([
                'success' => true,
                'columns' => ['A'],
                'rows' => [['1'], ['2'], ['3'], ['4']],
                'meta' => ['source_total' => 4],
            ]),
        ]);

        $matrix = $this->app->make(TabularReaderInterface::class)
            ->read($this->workDir.'/book.xlsx', ['max_rows' => 2]);

        $this->assertCount(2, $matrix['rows']);
        $this->assertTrue($matrix['meta']['truncated']);
        $this->assertSame(4, $matrix['meta']['source_total']);
    }

    public function test_the_reader_refuses_an_unsupported_format_before_the_call(): void
    {
        Http::fake();
        file_put_contents($this->workDir.'/legacy.xls', 'not-really');

        try {
            $this->app->make(TabularReaderInterface::class)->read($this->workDir.'/legacy.xls');
            $this->fail('A legacy .xls must be refused.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('Unsupported tabular format', $e->getMessage());
        } finally {
            @unlink($this->workDir.'/legacy.xls');
        }

        Http::assertNothingSent();
    }

    public function test_excel_data_import_runs_end_to_end(): void
    {
        $this->bindRegistry([4 => $this->workDir.'/book.xlsx']);

        Http::fake([
            'worker.test/*' => Http::response([
                'success' => true,
                'columns' => ['Region', 'Total'],
                'rows' => [['EMEA', '10']],
                'meta' => ['engine' => 'exceljs', 'sheet' => 'Q1', 'source_total' => 1],
            ]),
        ]);

        $result = $this->app->make(ExcelDataImportTool::class)->execute(['file_id' => 4]);

        $this->assertTrue($result['success']);
        $this->assertSame(['Region', 'Total'], $result['data']['columns']);
        $this->assertSame([['EMEA', '10']], $result['data']['rows']);
        $this->assertSame('Q1', $result['data']['sheet']);
        $this->assertSame(1, $result['data']['row_count']);
    }

    // ─── ocr_pdf_text ──────────────────────────────────────────────────

    public function test_ocr_is_unavailable_when_the_worker_does_not_advertise_it(): void
    {
        Http::fake([
            'worker.test/api/health' => Http::response(['status' => 'ok', 'version' => '3.4.0']),
            'worker.test/api/health/full' => Http::response([
                'status' => 'ok',
                'capabilities' => ['pdf_generation' => true, 'pdf_extraction' => true],
            ]),
        ]);

        $this->assertFalse($this->app->make(PdfOcrInterface::class)->isAvailable());

        $this->bindRegistry([1 => $this->workDir.'/scan.pdf']);

        $result = $this->app->make(OcrPdfTextTool::class)->execute(['file_id' => 1]);

        // This adapter's error factory returns a Throwable, which is the signal
        // the registry expects; the message carries the named reason.
        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('OCR is not available', $result->getMessage());
    }

    public function test_ocr_is_available_when_the_capability_is_advertised(): void
    {
        $this->fakeCapabilities(['pdf_ocr' => true]);

        $this->assertTrue($this->app->make(PdfOcrInterface::class)->isAvailable());
    }

    public function test_ocr_posts_the_page_budget_and_reads_per_page_results(): void
    {
        $this->fakeCapabilities(['pdf_ocr' => true]);

        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            if (str_contains($request->url(), '/api/health')) {
                return Http::response([
                    'status' => 'ok',
                    'version' => '3.5.0',
                    'capabilities' => ['pdf_ocr' => true],
                ]);
            }

            $captured = ['url' => $request->url(), 'parts' => $request->data()];

            return Http::response([
                'success' => true,
                'text' => "Page one\n\nPage two",
                'pages' => [
                    ['page' => 1, 'text' => 'Page one', 'confidence' => 91.5],
                    ['page' => 2, 'text' => 'Page two', 'confidence' => 88.0],
                ],
                'count' => 2,
                'total_pages' => 9,
                'truncated' => true,
                'dpi' => 300,
                'language' => 'eng',
            ]);
        });

        $result = $this->app->make(PdfOcrInterface::class)->recognize(
            $this->workDir.'/scan.pdf',
            ['dpi' => 300, 'max_pages' => 2, 'language' => 'eng']
        );

        $this->assertSame('http://worker.test/api/pdf/ocr', $captured['url']);
        $this->assertSame('300', $this->part($captured, 'dpi'));
        $this->assertSame('2', $this->part($captured, 'maxPages'));
        $this->assertSame('eng', $this->part($captured, 'language'));

        $this->assertSame(2, $result['count']);
        $this->assertSame(9, $result['total_pages']);
        $this->assertTrue($result['truncated']);
        $this->assertSame(91.5, $result['pages'][0]['confidence']);
    }

    /**
     * The worker reads `pages` from a multipart text field, so it arrives as a
     * comma-joined string. Before the route learned to parse one, this field was
     * dropped and the request read the first N pages instead of the window asked
     * for: a parameter that was accepted and did nothing.
     */
    public function test_ocr_forwards_an_explicit_page_window_as_a_comma_list(): void
    {
        $this->fakeCapabilities(['pdf_ocr' => true]);

        $captured = null;

        Http::fake(function (ClientRequest $request) use (&$captured) {
            if (str_contains($request->url(), '/api/health')) {
                return Http::response([
                    'status' => 'ok',
                    'version' => '3.5.0',
                    'capabilities' => ['pdf_ocr' => true],
                ]);
            }

            $captured = ['parts' => $request->data()];

            return Http::response([
                'success' => true,
                'text' => 'Page 51',
                'pages' => [['page' => 51, 'text' => 'Page 51', 'confidence' => 90.0]],
                'count' => 1,
                'total_pages' => 120,
                'truncated' => true,
                'dpi' => 300,
                'language' => 'eng',
            ]);
        });

        $this->app->make(PdfOcrInterface::class)->recognize(
            $this->workDir.'/scan.pdf',
            ['pages' => [51, 52, 53]]
        );

        $this->assertSame('51,52,53', $this->part($captured, 'pages'));
    }

    public function test_ocr_pdf_text_runs_end_to_end(): void
    {
        $this->bindRegistry([1 => $this->workDir.'/scan.pdf']);
        $this->fakeCapabilities(['pdf_ocr' => true]);

        Http::fake(function (ClientRequest $request) {
            if (str_contains($request->url(), '/api/health')) {
                return Http::response(['status' => 'ok', 'capabilities' => ['pdf_ocr' => true]]);
            }

            return Http::response([
                'success' => true,
                'text' => 'Invoice 42',
                'pages' => [['page' => 1, 'text' => 'Invoice 42', 'confidence' => 90]],
                'count' => 1,
                'total_pages' => 1,
                'truncated' => false,
                'dpi' => 300,
                'language' => 'eng',
            ]);
        });

        $result = $this->app->make(OcrPdfTextTool::class)->execute(['file_id' => 1]);

        $this->assertTrue($result['success']);
        $this->assertSame('Invoice 42', $result['data']['text']);
        $this->assertSame(10, $result['data']['characters']);
        $this->assertSame(false, $result['data']['truncated']);
    }

    // ─── watermark styling ─────────────────────────────────────────────

    public function test_watermark_style_is_supported_only_when_advertised(): void
    {
        $this->fakeCapabilities(['pdf_watermark_style' => true]);
        $this->assertTrue($this->app->make(PdfMutatorInterface::class)->watermarkStyleSupported());
    }

    public function test_watermark_style_is_refused_when_the_worker_is_older(): void
    {
        $this->fakeCapabilities(['pdf_generation' => true]);
        $this->bindRegistry([1 => $this->workDir.'/scan.pdf']);

        $this->assertFalse($this->app->make(PdfMutatorInterface::class)->watermarkStyleSupported());

        $result = $this->app->make(AddWatermarkToPdfTool::class)->execute([
            'file_id' => 1,
            'text' => 'DRAFT',
            'position' => 'top',
        ]);

        $this->assertInstanceOf(\RuntimeException::class, $result);
        $this->assertStringContainsString('does not support watermark opacity or position', $result->getMessage());
    }

    public function test_a_supported_style_is_sent_and_the_default_is_not(): void
    {
        $this->fakeCapabilities(['pdf_watermark_style' => true]);

        $bodies = [];

        Http::fake(function (ClientRequest $request) use (&$bodies) {
            if (str_contains($request->url(), '/api/health')) {
                return Http::response([
                    'status' => 'ok',
                    'capabilities' => ['pdf_watermark_style' => true],
                ]);
            }

            $bodies[] = $request->data();

            return Http::response([
                'success' => true,
                'data_base64' => self::PDF_B64,
                'size' => 20,
                'pages' => 1,
            ]);
        });

        $mutator = $this->app->make(PdfMutatorInterface::class);

        $mutator->watermark($this->workDir.'/scan.pdf', 'DRAFT');
        $mutator->watermark($this->workDir.'/scan.pdf', 'DRAFT', ['opacity' => 0.8, 'position' => 'bottom']);

        $this->assertNotContains('opacity', $this->partNames($bodies[0]), 'The default sends no style.');
        $this->assertNotContains('position', $this->partNames($bodies[0]));
        $this->assertContains('opacity', $this->partNames($bodies[1]));
        $this->assertContains('position', $this->partNames($bodies[1]));
    }

    // ─── Helpers ───────────────────────────────────────────────────────

    /**
     * Fake the health probe so a capability question has a definite answer.
     *
     * @param array<string, bool> $capabilities
     */
    private function fakeCapabilities(array $capabilities): void
    {
        Http::fake([
            'worker.test/api/health' => Http::response(['status' => 'ok', 'version' => '3.5.0']),
            'worker.test/api/health/full' => Http::response([
                'status' => 'ok',
                'capabilities' => $capabilities,
            ]),
        ]);
    }

    /**
     * Read one multipart field from a captured request.
     *
     * @param array<string, mixed>|null $captured
     */
    private function part(?array $captured, string $name): ?string
    {
        foreach ((array) ($captured['parts'] ?? []) as $part) {
            if (is_array($part) && $name === ($part['name'] ?? null)) {
                return is_resource($part['contents'] ?? null)
                    ? null
                    : (string) ($part['contents'] ?? '');
            }
        }

        return null;
    }

    /**
     * The part names in a captured multipart body.
     *
     * @param array<int, mixed> $parts
     *
     * @return list<string>
     */
    private function partNames(array $parts): array
    {
        $names = [];

        foreach ($parts as $part) {
            if (is_array($part) && isset($part['name'])) {
                $names[] = (string) $part['name'];
            }
        }

        return $names;
    }

    /**
     * Bind a registry that answers ids and records writes.
     *
     * @param array<int, string> $paths
     */
    private function bindRegistry(array $paths): void
    {
        $registry = new class ($paths) implements FileStoreInterface, FileVisibilityStoreInterface {
            /** @var list<array<string, mixed>> */
            public array $writes = [];

            /**
             * @param array<int, string> $paths
             */
            public function __construct(private readonly array $paths)
            {
            }

            public function getPath(int $fileId): ?string
            {
                return $this->paths[$fileId] ?? null;
            }

            public function getMetadata(int $fileId): ?StoredFile
            {
                return null;
            }

            public function userCanAccess(int $fileId, int $userId): bool
            {
                return true;
            }

            public function store(string $localPath, string $filename, string $mimeType, int $userId): StoredFile
            {
                return $this->storeWithVisibility($localPath, $filename, $mimeType, $userId, self::VISIBILITY_PUBLIC);
            }

            public function findByMetadata(array $criteria, int $limit = 50): array
            {
                return [];
            }

            public function delete(int $fileId): void
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
                $this->writes[] = ['filename' => $filename, 'visibility' => $visibility];

                return new StoredFile(
                    id: 901,
                    filename: $filename,
                    mimeType: $mimeType,
                    sizeBytes: (int) @filesize($localPath),
                    localPath: $localPath,
                    ownerId: $userId,
                    createdAt: new \DateTimeImmutable(),
                    publicUrl: 'https://example.test/files/'.$filename,
                    visibility: $visibility,
                    slug: 'stored',
                );
            }

            public function supportsVisibility(string $visibility): bool
            {
                return \in_array($visibility, [self::VISIBILITY_PUBLIC, self::VISIBILITY_PRIVATE], true);
            }
        };

        $this->app->instance(FileStoreInterface::class, $registry);
        $this->app->instance(FileVisibilityStoreInterface::class, $registry);
    }
}
